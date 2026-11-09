#!/bin/bash
# Post-migration audit for manage:migrate-orchestrations-to-flow.
#
# Usage:
#   MANAGE_TOKEN=<token> docs/flow-migration-audit.sh <stackUrl> <migrationDate> <projectsFile> [concurrency]
#
#   stackUrl       e.g. https://connection.north-europe.azure.keboola.com
#   migrationDate  YYYY-MM-DD of the batch in the stack's local time (configs report `created` as
#                  e.g. 2026-10-09T13:47:54+0200); flows created on that day count as "migrated"
#   projectsFile   one project id per line (the `success` rows of the batch CSV report)
#
# Checks per project:
#   - every orchestration has a keboola.flow twin and the source orchestrations are disabled
#   - schedules:     flow schedules reuse the tokens of the enabled source schedules
#   - triggers:      every source trigger has a flow clone (same token, cool-down, tables);
#                    a trigger whose tables were all deleted cannot be cloned and never fires
#   - notifications: every source subscription has a flow clone (same event, recipient, filters);
#                    job-succeeded-with-warning is never cloned (flow does not emit it) and
#                    subscriptions filtered on a deleted branch are skipped by the tool on purpose
#   - nested refs:   no task in any orchestrator/flow config still calls keboola.orchestrator
#                    for an id that has a keboola.flow twin
#
# The manage token is read from the environment so it does not end up in shell history or `ps`.
set -o pipefail

CURL="curl -sS --retry 4 --retry-all-errors --retry-delay 1 -m 30"

# shellcheck disable=SC2016
AUDIT_JQ='
def filterValue(f): first(.filters[] | select(.field == f) | .value | tostring) // null;
def triggerKey: "token=\(.runWithTokenId) cooldown=\(.coolDownPeriodMinutes) tables=\([.tables[].tableId] | sort | join(","))";
# job.component.id flips on clone and the notification service adds branch.id to new subscriptions,
# so neither may take part in the comparison. Values are compared as strings (0.2 vs "0.2").
def subscriptionKey: "\(.event) \(.recipient.channel):\(.recipient.address // .recipient.url) "
  + ([.filters[] | select(.field != "job.component.id" and .field != "branch.id")
      | "\(.field)\(.operator)\(.value | tostring)"] | sort | join(" "));
def tasksCalling(component; ids): [.[] as $cfg | ($cfg.configuration.tasks // [])[]
  | select(.task.componentId == "keboola.orchestrator" and ((.task.configId | tostring) as $t | ids | index($t)))
  | {in: "\(component)/\($cfg.id)", target: (.task.configId | tostring)}];

$orch[0] as $orch | $flow[0] as $flow | $sch[0] as $sch | $trig[0] as $trig
| $subs[0] as $subs | [$branches[0][].id | tostring] as $branchIds
| ($flow | map(select(.created | startswith($today))) | map(.id)) as $migrated
| ($flow | map(.id)) as $allFlow
| ($orch | map({key: .id, value: .isDisabled}) | from_entries) as $orchDisabled
# A disabled flow whose orchestrator twin is enabled is a hand-reverted migration: the tool
# deliberately keeps calling the orchestrator there, so such ids are not stale targets.
| ($flow | map(select((.isDisabled and ($orchDisabled[.id] == false)) | not)) | map(.id)) as $flowTargets
| { p: $p,
    orch: ($orch | length),
    orchWithFlow: ($orch | map(select(.id as $i | $allFlow | index($i))) | length),
    migratedToday: ($migrated | length),
    orchStillEnabled: ($orch | map(select(.isDisabled == false)) | length),

    schedules: [$migrated[] as $c
      | ($sch | map(select(.comp == "keboola.orchestrator" and .cfg == $c and .state == "enabled")) | map(.tokenId) | unique) as $st
      | ($sch | map(select(.comp == "keboola.flow" and .cfg == $c)) | map(.tokenId) | unique) as $ft
      | select(($st | length) > 0 or ($ft | length) > 0)
      | {cfg: $c, src: $st, flow: $ft, ok: (($st | sort) == ($ft | sort))}],

    # A trigger whose tables were all deleted can never fire, and the Storage API refuses to
    # create one with no tables, so it cannot be cloned and nothing is lost by not cloning it.
    triggers: [$migrated[] as $c
      | ($trig | map(select(.component == "keboola.orchestrator" and .configurationId == $c))) as $src
      | ($src | map(select(.tables | length == 0))) as $noTables
      | ($src - $noTables | map(triggerKey)) as $st
      | ($trig | map(select(.component == "keboola.flow" and .configurationId == $c)) | map(triggerKey)) as $ft
      | select(($src | length) > 0 or ($ft | length) > 0)
      | ($st - $ft) as $missing
      | {cfg: $c, src: ($src | length), flow: ($ft | length), noTablesNotCloned: ($noTables | length),
         missing: $missing, ok: ($missing | length == 0)}],

    notifications: [$migrated[] as $c
      | ($subs | map(select(filterValue("job.component.id") == "keboola.orchestrator"
                            and filterValue("job.configuration.id") == $c))) as $src
      | ($subs | map(select(filterValue("job.component.id") == "keboola.flow"
                            and filterValue("job.configuration.id") == $c)) | map(subscriptionKey)) as $ft
      | ($src | map(select(.event == "job-succeeded-with-warning"))) as $warning
      | ($src | map(select(.event != "job-succeeded-with-warning"
                           and filterValue("branch.id") != null
                           and (filterValue("branch.id") as $b | $branchIds | index($b) | not)))) as $deadBranch
      | ($src - $warning - $deadBranch | map(subscriptionKey)) as $expected
      | select(($src | length) > 0 or ($ft | length) > 0)
      | ($expected - $ft) as $missing
      | {cfg: $c, src: ($src | length), flow: ($ft | length), warningNotCloned: ($warning | length),
         deadBranchSkipped: ($deadBranch | length), missing: $missing, ok: ($missing | length == 0)}],

    # A disabled legacy orchestrator never runs, so a stale call inside it has no effect; it is
    # reported separately so it does not mask a stale call in a config that can actually run.
    nestedStale: (($orch | map(select(.isDisabled | not)) | tasksCalling("keboola.orchestrator"; $flowTargets))
                  + ($flow | tasksCalling("keboola.flow"; $flowTargets))),
    nestedStaleInDisabledLegacy: ($orch | map(select(.isDisabled)) | tasksCalling("keboola.orchestrator"; $flowTargets) | length),
    nestedToFlow: ([$flow[] | (.configuration.tasks // [])[] | select(.task.componentId == "keboola.flow")] | length)
  }'

fail() {
  echo "{\"p\":\"$1\",\"err\":\"$2\"}"
}

audit_project() {
  local P=$1 S=$2 TODAY=$3
  local SCH=${S/connection./scheduler.}
  local NOTIF=${S/connection./notification.}

  local ET
  ET=$($CURL -X POST -H "X-KBC-ManageApiToken: $MANAGE_TOKEN" -H "Content-Type: application/json" \
    -d '{"description":"flow-migration audit","expiresIn":1800,"canManageBuckets":true,"canReadAllFileUploads":true}' \
    "$S/manage/projects/$P/tokens" | jq -r '.token // empty')
  [ -z "$ET" ] && { fail "$P" "no token"; return; }

  # Listings can be large (configs carry full payloads), so they go through files, not argv.
  local DIR
  DIR=$(mktemp -d)
  local GET="$CURL -f -H X-StorageApi-Token:$ET"
  # The schedule list already carries tokenId, target and state. Do not fetch schedules one by
  # one: GET /schedules/{id} hangs for 30 s+ on some schedules and made the audit incomplete.
  $GET "$S/v2/storage/components/keboola.orchestrator/configs" > "$DIR/orch.json" || { fail "$P" "orch read failed"; rm -rf "$DIR"; return; }
  $GET "$S/v2/storage/components/keboola.flow/configs" > "$DIR/flow.json"         || { fail "$P" "flow read failed"; rm -rf "$DIR"; return; }
  $GET "$S/v2/storage/triggers" > "$DIR/trig.json"                                 || { fail "$P" "trigger read failed"; rm -rf "$DIR"; return; }
  $GET "$S/v2/storage/dev-branches" > "$DIR/branches.json"                         || { fail "$P" "branch read failed"; rm -rf "$DIR"; return; }
  $GET "$NOTIF/project-subscriptions" > "$DIR/subs.json"                           || { fail "$P" "subscription read failed"; rm -rf "$DIR"; return; }
  $GET "$SCH/schedules" \
    | jq -c '[.[] | {tokenId, comp:.target.componentId, cfg:.target.configurationId, state:.schedule.state}]' \
    > "$DIR/sch.json" || { fail "$P" "schedule read failed"; rm -rf "$DIR"; return; }

  jq -n -c --arg p "$P" --arg today "$TODAY" \
    --slurpfile orch "$DIR/orch.json" --slurpfile flow "$DIR/flow.json" --slurpfile sch "$DIR/sch.json" \
    --slurpfile trig "$DIR/trig.json" --slurpfile subs "$DIR/subs.json" --slurpfile branches "$DIR/branches.json" \
    "$AUDIT_JQ" || fail "$P" "audit evaluation failed"
  rm -rf "$DIR"
}

if [ "$1" = "--one" ]; then
  audit_project "$2" "$3" "$4"
  exit 0
fi

STACK=$1; TODAY=$2; PROJECTS=$3; CONCURRENCY=${4:-4}
[ -z "$MANAGE_TOKEN" ] && { echo "MANAGE_TOKEN env var is required" >&2; exit 1; }
[ -z "$PROJECTS" ] && { echo "usage: MANAGE_TOKEN=... $0 <stackUrl> <YYYY-MM-DD> <projectsFile> [concurrency]" >&2; exit 1; }

export MANAGE_TOKEN
OUT="flow-audit-$(date +%Y%m%d-%H%M%S).jsonl"
grep -v -e '^\s*$' -e '^\s*#' "$PROJECTS" \
  | xargs -P "$CONCURRENCY" -I{} "$0" --one {} "$STACK" "$TODAY" > "$OUT"

echo "results: $OUT ($(wc -l < "$OUT" | tr -d ' ') lines, expected $(grep -cv -e '^\s*$' -e '^\s*#' "$PROJECTS"))"
echo "=== read errors (must be none) ==="
jq -c 'select(.err)' "$OUT"
echo "=== totals ==="
jq -s -r 'map(select(.err|not)) | {
  projects: length,
  orchestrations: (map(.orch)|add),
  orchestrationsWithFlow: (map(.orchWithFlow)|add),
  flowsCreatedOnDate: (map(.migratedToday)|add),
  orchestrationsStillEnabled: (map(.orchStillEnabled)|add),
  scheduleConfigs: (map(.schedules|length)|add),
  scheduleTokenSetsDiffer: (map([.schedules[]|select(.ok|not)]|length)|add),
  triggerConfigs: (map(.triggers|length)|add),
  sourceTriggers: (map([.triggers[].src]|add // 0)|add),
  flowTriggers: (map([.triggers[].flow]|add // 0)|add),
  triggersWithoutTablesNotCloned: (map([.triggers[].noTablesNotCloned]|add // 0)|add),
  triggersMissing: (map([.triggers[].missing|length]|add // 0)|add),
  notificationConfigs: (map(.notifications|length)|add),
  sourceSubscriptions: (map([.notifications[].src]|add // 0)|add),
  flowSubscriptions: (map([.notifications[].flow]|add // 0)|add),
  warningSubscriptionsNotCloned: (map([.notifications[].warningNotCloned]|add // 0)|add),
  deadBranchSubscriptionsSkipped: (map([.notifications[].deadBranchSkipped]|add // 0)|add),
  subscriptionsMissing: (map([.notifications[].missing|length]|add // 0)|add),
  nestedTasksCallingFlow: (map(.nestedToFlow)|add),
  nestedStaleReferences: (map(.nestedStale|length)|add),
  nestedStaleInDisabledLegacy: (map(.nestedStaleInDisabledLegacy // 0)|add)
} | to_entries[] | "  \(.key): \(.value)"' "$OUT"
echo "=== problems (must be none) ==="
jq -r '.p as $p
  | ((.schedules // [])[] | select(.ok|not)
      | "  schedule  p=\($p) cfg=\(.cfg) src=[\(.src|join(","))] flow=[\(.flow|join(","))]"),
    ((.triggers // [])[] | .cfg as $c | .missing[] | "  trigger   p=\($p) cfg=\($c) missing: \(.)"),
    ((.notifications // [])[] | .cfg as $c | .missing[] | "  notif     p=\($p) cfg=\($c) missing: \(.)"),
    ((.nestedStale // [])[] | "  nested    p=\($p) \(.in) still calls keboola.orchestrator/\(.target)")' "$OUT"
