---
name: auditing-snowflake-password-users
description: Use when a customer needs their Snowflake workspace users audited before the password-authentication deprecation - "not ready service users", LEGACY_SERVICE users, Trust Center report, key-pair migration stalled, or a SUPPORT ticket about SNFLK username/password auth.
---

# Auditing Snowflake password users

## Overview

A customer has Snowflake users still on password auth and a deprecation deadline. The job is to
work out, per user, **what it is, whether it is still needed, and whose call it is** - then clean
up what is ours and hand the rest to the customer with a recommendation.

**Core principle: the audit is a join of two sources.** Snowflake knows which users exist and
how they authenticate. Keboola knows what each one is *for*. Neither alone can produce a verdict.

Done for Carvago (SUPPORT-16608), SLSP (DMD-1565), Shoptet (DMD-1929), FL Service (DMD-1992).

## Step 0: KBDB or BYODB?

**Settle this first - it decides whether you can see Snowflake at all.** Check the PS space in
Confluence, or ask in the ticket.

- **KBDB** (Keboola-managed account): we hold `ACCOUNTADMIN`. We can dump the users ourselves,
  and the customer often *cannot* - so do not send them off to the Trust Center, run the audit
  for them.
- **BYODB** (customer's own account): they must send the export. We only ever see what they send.

Getting this wrong sends the customer chasing a Trust Center role they will never get, or has us
waiting on an export we could have produced in a minute.

## The two sources

**Keboola side - always available, this is the backbone:**
```
php cli.php manage:describe-organization-workspaces <manage-token> <orgId> out.csv <hostname-suffix>
```
Gives `workspaceId`, `componentId`, `configurationId`, `creatorEmail`, `activeUser`,
`snowflakeSchema`, and **`loginType`** (`snowflake-legacy-service` = password,
`*-keypair`/`*-sso` = already migrated).

**Snowflake side - only with access:**
```sql
SELECT * FROM SNOWFLAKE.ACCOUNT_USAGE.USERS
WHERE DELETED_ON IS NULL AND TYPE = 'LEGACY_SERVICE';
```
Adds `LAST_SUCCESS_LOGIN` (activity), `HAS_RSA_PUBLIC_KEY`, and users that have **no** Keboola
workspace (true orphans).

**Join key:** the workspace schema. `NAME` is `KEBOOLA_<schema>` or `KBC_<STACK>_<schema>`
(the stack prefix varies - derive it from the data, never assume). `DEFAULT_NAMESPACE` is
`KEBOOLA_<projectId>.<schema>`, so the project falls out for free. Verify the mapping is
100% consistent before trusting it.

### Without Snowflake access

`loginType` alone answers "who is still on a password" - that is the whole deprecation scope, so
the audit still works. What you lose and how to replace it:

| Lost | Replacement |
|---|---|
| `LAST_SUCCESS_LOGIN` (activity) | `manage:check-project-workspaces-state` - reports each config's last job run |
| Users with no workspace (orphans) | Nothing. Say so; do not imply the list is complete. |
| Proof a user was dropped | Nothing. Absence from a later export is not proof. |

## Classification: lifecycle decides, not the name

**This is the part that has been got wrong twice. Read the table before writing any verdict.**

| Component | Lifecycle | A surviving workspace means | Whose call |
|---|---|---|---|
| `keboola.sandboxes` | permanent, personal | owner is not using it | **customer** |
| `*-transformation` (snowflake, dbt, python-snowpark, no-code-dbt) | **per-run** - workspace per job, dropped after | **leftover of a terminated/failed job** | **ours**, workspace only |
| `keboola.wr-db-snowflake` | **permanent staging**, 1:1 with its config | normal state, not a leftover | **customer** |
| anything unrecognised | unknown | unknown | **investigate, never drop** |

Two failure modes, both real:

- **"not a sandbox" → "our leftover"** dropped writer staging workspaces into the delete pile.
  A writer's staging workspace is permanent infrastructure; deleting it breaks the writer.
- **"sole workspace of its config" → "permanent"** swept the transformation leftovers out of
  the delete pile, which they genuinely belong in.

**Empirical check that separates them:** count workspaces per configuration. Writer staging is
uniformly 1:1. Transformations accumulate - one dbt config held 8 surviving workspaces spanning
six months. Accumulation is the fingerprint of a per-run component.

**Permanent does not mean untouchable - it means not ours.** Recommend, let the customer decide:
no traffic → suggest deleting, recent login → suggest migrating.

## Hard rules

- **Never pass `--with-configuration`** on a transformation or writer workspace. Purging the
  config takes the live transformation with it - that is how SUPPORT-16812 broke Carvago's
  production transformations.
- **Never drop a permanent workspace on our own authority.** Sandboxes and writer staging are
  the customer's setup.
- **Never let an empty or short inventory reach the classifier.** Every user then joins to
  nothing and reads as "orphan, safe to delete" - the most dangerous wrong verdict this audit can
  produce. Put a row-count floor in the script and fail loudly.
- **Keep the record of what was deleted in a file nothing regenerates** (`deleted_<date>.csv`).
  A delete input doubling as the audit trail gets truncated to zero rows the next time you
  regenerate it.

## Traps in the data

- **A filtered dump makes absence meaningless.** `TYPE = 'LEGACY_SERVICE'` excludes `TYPE IS NULL`
  (users predating the column - often the riskiest), `PERSON`, and `SERVICE`. A workspace missing
  from the dump is on another auth type, **not** an orphan. A user vanishing between two exports
  may have been dropped *or* converted - you cannot tell which.
- **Check when the users were created.** A tight cluster (e.g. 126 users inside five minutes)
  is a backend migration re-provisioning them. Login history only reaches back to that moment,
  so "never logged in" means "unused since then". Say it that way to the customer.
- **`LAST_SUCCESS_LOGIN` is not a usage signal for staging workspaces.** The unload into a
  staging schema runs as the storage role, not as the workspace user, so an actively used staging
  workspace can show zero logins. It is reliable for sandboxes, where a human really does connect.

## The customer report

Internal verdicts are not sendable. Generate a separate CSV:

- plain language (`Sandbox (interactive workspace)`, not `keboola.sandboxes`)
- **keep `COMPONENT_ID` and `CONFIGURATION_ID`** - that is how they find it in Keboola
- `WHO_ACTS` so nobody guesses whose move it is
- `OUR_RECOMMENDATION` - a specific suggestion per row, not a generic prompt
- an empty **`YOUR_DECISION`** column to fill in and send back
- their rows first, ours last
- spell out `LAST_USED` as "not since the <date> backend migration", never "never used"
- **fail the build on an unmapped verdict** rather than silently dropping the row

Keep our own cleanup out of it unless the customer benefits from knowing. Snapshot the sent file
as `*.sent-<date>.csv` - generators overwrite.

## Order of work

1. KBDB or BYODB → do we have Snowflake access?
2. Inventory (`describe-organization-workspaces`) + dump if available
3. Join, classify by lifecycle table, sanity-check the join is total
4. Clean up **our** leftovers: dry-run → confirm → workspace-only delete → keep `deleted_<date>.csv`
5. Re-export and verify the count moved by exactly what you deleted; investigate any difference
6. Send the customer report, wait for `YOUR_DECISION`
7. Act on their answers - deletes and migrations they signed off
