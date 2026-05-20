<?php

namespace Keboola\Console\Command;

use InvalidArgumentException;
use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Keboola\ServiceClient\ServiceClient;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\ClientException as StorageClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Tokens;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteSandboxWorkspaces extends Command
{
    private const string COMPONENT_ID = 'keboola.sandboxes';

    protected function configure(): void
    {
        $this
            ->setName('manage:delete-sandbox-workspaces')
            ->setDescription(sprintf(
                'Delete %s workspaces in a project or organization that have no active editor session, '
                . 'filtered by workspace creation date.',
                self::COMPONENT_ID,
            ))
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addOption(
                'project-id',
                'p',
                InputOption::VALUE_REQUIRED,
                'Single project ID to clean. Mutually exclusive with --organization-id.',
            )
            ->addOption(
                'organization-id',
                'o',
                InputOption::VALUE_REQUIRED,
                'Organization ID — iterates all projects in the organization. '
                . 'Mutually exclusive with --project-id.',
            )
            ->addOption(
                'created-after',
                null,
                InputOption::VALUE_REQUIRED,
                'Only consider workspaces created at or after this date '
                . '(strtotime expression, e.g. "-30 days", "2026-01-01"). Default: "-30 days".',
                '-30 days',
            )
            ->addOption(
                'created-before',
                null,
                InputOption::VALUE_REQUIRED,
                'Only consider workspaces created before this date '
                . '(strtotime expression, e.g. "-1 day", "now"). Default: "now".',
                'now',
            )
            ->addArgument(
                'manageToken',
                InputArgument::REQUIRED,
                'Keboola Manage API token to use',
            )
            ->addArgument(
                'hostnameSuffix',
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manageToken = $input->getArgument('manageToken');
        assert(is_string($manageToken));
        assert($manageToken !== '');

        $hostnameSuffix = $input->getArgument('hostnameSuffix');
        assert(is_string($hostnameSuffix));
        assert($hostnameSuffix !== '');

        $projectIdOpt = $input->getOption('project-id');
        $organizationIdOpt = $input->getOption('organization-id');
        assert($projectIdOpt === null || is_string($projectIdOpt));
        assert($organizationIdOpt === null || is_string($organizationIdOpt));

        if ($projectIdOpt !== null && $organizationIdOpt !== null) {
            throw new InvalidArgumentException(
                'Cannot use both --project-id and --organization-id options at the same time.',
            );
        }
        if ($projectIdOpt === null && $organizationIdOpt === null) {
            throw new InvalidArgumentException(
                'Either --project-id or --organization-id option must be provided.',
            );
        }

        $createdAfterStr = $input->getOption('created-after');
        $createdBeforeStr = $input->getOption('created-before');
        assert(is_string($createdAfterStr));
        assert(is_string($createdBeforeStr));

        $createdAfter = strtotime($createdAfterStr);
        if ($createdAfter === false) {
            throw new InvalidArgumentException(sprintf('Invalid --created-after value: %s', $createdAfterStr));
        }
        $createdBefore = strtotime($createdBeforeStr);
        if ($createdBefore === false) {
            throw new InvalidArgumentException(sprintf('Invalid --created-before value: %s', $createdBeforeStr));
        }
        if ($createdBefore <= $createdAfter) {
            throw new InvalidArgumentException(sprintf(
                '--created-before (%s) must be later than --created-after (%s).',
                date('Y-m-d H:i:s', $createdBefore),
                date('Y-m-d H:i:s', $createdAfter),
            ));
        }

        $force = (bool) $input->getOption('force');

        $serviceClient = new ServiceClient($hostnameSuffix);
        $connectionUrl = $serviceClient->getConnectionServiceUrl();
        $editorUrl = $serviceClient->getEditorServiceUrl();

        $manageClient = new Client(['token' => $manageToken, 'url' => $connectionUrl]);

        // Resolve target projects
        if ($organizationIdOpt !== null) {
            if (!ctype_digit($organizationIdOpt)) {
                throw new InvalidArgumentException('--organization-id must be a numeric string.');
            }
            $organizationId = (int) $organizationIdOpt;
            try {
                $organization = $manageClient->getOrganization($organizationId);
            } catch (ClientException $e) {
                throw new \RuntimeException(sprintf(
                    'Failed to load organization %d: %s',
                    $organizationId,
                    $e->getMessage(),
                ), 0, $e);
            }
            $projects = $organization['projects'];
            $targetDesc = sprintf(
                'organization %d ("%s") — %d project(s)',
                $organizationId,
                $organization['name'] ?? '?',
                count($projects),
            );
        } else {
            if (!ctype_digit($projectIdOpt)) {
                throw new InvalidArgumentException('--project-id must be a numeric string.');
            }
            try {
                $project = $manageClient->getProject($projectIdOpt);
            } catch (ClientException $e) {
                throw new \RuntimeException(sprintf(
                    'Failed to load project %s: %s',
                    $projectIdOpt,
                    $e->getMessage(),
                ), 0, $e);
            }
            $projects = [$project];
            $targetDesc = sprintf('project %s ("%s")', $project['id'], $project['name'] ?? '?');
        }

        $this->writeBlock($output, 'Configuration', [
            sprintf('Target:           %s', $targetDesc),
            sprintf('Component:        %s', self::COMPONENT_ID),
            sprintf(
                'Created window:   from %s to %s',
                date('Y-m-d H:i:s', $createdAfter),
                date('Y-m-d H:i:s', $createdBefore),
            ),
            sprintf('Connection URL:   %s', $connectionUrl),
            sprintf('Editor URL:       %s', $editorUrl),
            sprintf('Mode:             %s', $force ? 'FORCE (deletions will happen)' : 'DRY-RUN (no changes)'),
        ]);

        $totalProjectsProcessed = 0;
        $totalProjectsSkipped = 0;
        $totalWorkspaces = 0;
        $totalCandidates = 0;
        $totalSkippedSession = 0;
        $totalSkippedComponent = 0;
        $totalSkippedDate = 0;
        $totalDeleted = 0;
        $totalDeleteErrors = 0;

        /** @var array<string, list<array{workspaceId: int|string, configurationId: string, branchId: int|string, schema: string, created: string}>> $summary */
        $summary = [];

        foreach ($projects as $project) {
            $projectKey = sprintf('%s (%s)', $project['name'] ?? '?', $project['id']);
            $this->writeBlock($output, sprintf('Project %s : %s', $project['id'], $project['name'] ?? '?'));

            try {
                $storageToken = $manageClient->createProjectStorageToken(
                    $project['id'],
                    [
                        'description' => 'Maintenance Sandbox Workspace Cleaner',
                        'expiresIn' => 1800,
                        'canManageBuckets' => true,
                        'canPurgeTrash' => true,
                    ],
                );
            } catch (\Throwable $e) {
                if ($e->getCode() === 403) {
                    $output->writeln(sprintf('  WARN: Access denied to project %s, skipping.', $project['id']));
                    $output->writeln('');
                    $totalProjectsSkipped++;
                    continue;
                }
                throw $e;
            }

            $storageClient = new StorageApiClient([
                'token' => $storageToken['token'],
                'url' => $connectionUrl,
                'backoffMaxTries' => 1,
                'logger' => new ConsoleLogger($output),
            ]);
            $editorClient = new EditorServiceClient($editorUrl, $storageToken['token']);

            // Index editor sessions by workspaceSchema for O(1) lookup against workspace credentials.
            $sessionsBySchema = [];
            foreach ($editorClient->listSessions() as $session) {
                $sessionsBySchema[$session['workspaceSchema']] = $session;
            }
            $output->writeln(sprintf(
                '  Editor sessions in project: %d (across all branches)',
                count($sessionsBySchema),
            ));

            $devBranches = new DevBranches($storageClient);
            $branchesList = $devBranches->listBranches();

            $projectWorkspaces = 0;
            $projectCandidates = 0;
            $projectSkippedSession = 0;
            $projectSkippedComponent = 0;
            $projectSkippedDate = 0;
            $projectDeleted = 0;
            $projectDeleteErrors = 0;

            $summary[$projectKey] = [];

            foreach ($branchesList as $branch) {
                $branchId = $branch['id'];
                $branchStorageClient = new BranchAwareClient($branchId, [
                    'token' => $storageToken['token'],
                    'url' => $connectionUrl,
                    'backoffMaxTries' => 1,
                ]);
                $workspacesClient = new Workspaces($branchStorageClient);
                $workspaceList = $workspacesClient->listWorkspaces();

                $output->writeln(sprintf(
                    '  Branch "%s" (#%s): %d workspace(s)',
                    $branch['name'],
                    $branchId,
                    count($workspaceList),
                ));
                $projectWorkspaces += count($workspaceList);

                foreach ($workspaceList as $workspace) {
                    $workspaceComponent = (string) ($workspace['component'] ?? '');
                    $workspaceId = $workspace['id'];
                    $createdStr = $workspace['created'];
                    $createdTs = strtotime($createdStr);
                    $schema = (string) ($workspace['connection']['schema'] ?? '');
                    $configurationId = (string) ($workspace['configurationId'] ?? '');

                    if ($workspaceComponent !== self::COMPONENT_ID) {
                        $output->writeln(sprintf(
                            '    - SKIP   workspace %s (component "%s" != "%s")',
                            (string) $workspaceId,
                            $workspaceComponent,
                            self::COMPONENT_ID,
                        ));
                        $projectSkippedComponent++;
                        continue;
                    }

                    if ($createdTs === false || $createdTs < $createdAfter || $createdTs >= $createdBefore) {
                        $output->writeln(sprintf(
                            '    - SKIP   workspace %s (created %s outside window)',
                            (string) $workspaceId,
                            $createdStr,
                        ));
                        $projectSkippedDate++;
                        continue;
                    }

                    if ($configurationId === '') {
                        $output->writeln(sprintf(
                            '    - SKIP   workspace %s (created %s) — no configurationId, cannot resolve config',
                            (string) $workspaceId,
                            $createdStr,
                        ));
                        $projectSkippedComponent++;
                        continue;
                    }

                    if ($schema !== '' && isset($sessionsBySchema[$schema])) {
                        $session = $sessionsBySchema[$schema];
                        $output->writeln(sprintf(
                            '    - SKIP   workspace %s (created %s, schema %s) — active editor session %s',
                            (string) $workspaceId,
                            $createdStr,
                            $schema,
                            $session['id'],
                        ));
                        $projectSkippedSession++;
                        continue;
                    }

                    $projectCandidates++;
                    $output->writeln(sprintf(
                        '    - DELETE workspace %s (created %s, schema "%s", config %s/%s, branch %s)',
                        (string) $workspaceId,
                        $createdStr,
                        $schema,
                        self::COMPONENT_ID,
                        $configurationId,
                        (string) $branchId,
                    ));

                    $summary[$projectKey][] = [
                        'workspaceId' => $workspaceId,
                        'configurationId' => $configurationId,
                        'branchId' => $branchId,
                        'schema' => $schema,
                        'created' => $createdStr,
                    ];

                    if (!$force) {
                        continue;
                    }

                    // 1) Delete configuration (trash then purge) — matches existing cleanup commands.
                    $configDeleted = false;
                    $components = new Components($branchStorageClient);
                    try {
                        // delete
                        $components->deleteConfiguration(self::COMPONENT_ID, $configurationId);
                        // purge (from trash)
                        $components->deleteConfiguration(self::COMPONENT_ID, $configurationId);
                        $configDeleted = true;
                        $output->writeln(sprintf(
                            '      Deleted configuration %s/%s',
                            self::COMPONENT_ID,
                            $configurationId,
                        ));
                    } catch (StorageClientException $e) {
                        if (str_contains($e->getMessage(), 'not found')) {
                            $output->writeln(sprintf(
                                '      Configuration %s/%s already gone',
                                self::COMPONENT_ID,
                                $configurationId,
                            ));
                            $configDeleted = true;
                        } else {
                            $output->writeln(sprintf(
                                '      ERROR deleting configuration %s/%s: %s',
                                self::COMPONENT_ID,
                                $configurationId,
                                $e->getMessage(),
                            ));
                        }
                    }

                    // 2) Delete the workspace itself.
                    try {
                        $workspacesClient->deleteWorkspace($workspaceId);
                        $output->writeln(sprintf('      Deleted workspace %s', (string) $workspaceId));
                        if ($configDeleted) {
                            $projectDeleted++;
                        } else {
                            $projectDeleteErrors++;
                        }
                    } catch (\Throwable $e) {
                        $output->writeln(sprintf(
                            '      ERROR deleting workspace %s: %s',
                            (string) $workspaceId,
                            $e->getMessage(),
                        ));
                        $projectDeleteErrors++;
                    }
                }
            }

            $output->writeln('');
            $output->writeln(sprintf(
                '  Project summary: %d workspace(s) seen, %d candidate(s), %d deleted, %d error(s); '
                . 'skipped: %d session, %d component, %d date',
                $projectWorkspaces,
                $projectCandidates,
                $projectDeleted,
                $projectDeleteErrors,
                $projectSkippedSession,
                $projectSkippedComponent,
                $projectSkippedDate,
            ));
            $output->writeln('');

            try {
                $tokensClient = new Tokens($storageClient);
                $tokensClient->dropToken($storageToken['id']);
            } catch (\Throwable $e) {
                $output->writeln(sprintf(
                    '  WARN: Could not drop temporary token %s: %s',
                    $storageToken['id'],
                    $e->getMessage(),
                ));
            }

            $totalProjectsProcessed++;
            $totalWorkspaces += $projectWorkspaces;
            $totalCandidates += $projectCandidates;
            $totalSkippedSession += $projectSkippedSession;
            $totalSkippedComponent += $projectSkippedComponent;
            $totalSkippedDate += $projectSkippedDate;
            $totalDeleted += $projectDeleted;
            $totalDeleteErrors += $projectDeleteErrors;
        }

        // Per-project candidate listing
        $output->writeln('');
        $output->writeln('=== Per-project candidates ===');
        foreach ($summary as $projectKey => $rows) {
            if (count($rows) === 0) {
                continue;
            }
            $output->writeln(sprintf('  Project: %s', $projectKey));
            foreach ($rows as $row) {
                $output->writeln(sprintf(
                    '    - workspaceId=%s configurationId=%s branchId=%s schema=%s created=%s',
                    (string) $row['workspaceId'],
                    $row['configurationId'],
                    (string) $row['branchId'],
                    $row['schema'] !== '' ? $row['schema'] : '(none)',
                    $row['created'],
                ));
            }
        }

        $summaryLines = [
            sprintf('Projects processed:        %d', $totalProjectsProcessed),
            sprintf('Projects skipped:          %d', $totalProjectsSkipped),
            sprintf('Workspaces seen:           %d', $totalWorkspaces),
            sprintf('Candidates (to delete):    %d', $totalCandidates),
            sprintf('Skipped (active session):  %d', $totalSkippedSession),
            sprintf('Skipped (other component): %d', $totalSkippedComponent),
            sprintf('Skipped (out of window):   %d', $totalSkippedDate),
        ];
        if ($force) {
            $summaryLines[] = sprintf('Deleted:                   %d', $totalDeleted);
            $summaryLines[] = sprintf('Delete errors:             %d', $totalDeleteErrors);
        } else {
            $summaryLines[] = 'Mode:                      DRY-RUN (re-run with --force to delete)';
        }
        $this->writeBlock($output, 'Final summary', $summaryLines);

        return 0;
    }

    /**
     * @param list<string> $lines
     */
    private function writeBlock(OutputInterface $output, string $title, array $lines = []): void
    {
        $width = max(70, strlen($title) + 4);
        $separator = str_repeat('=', $width);
        $output->writeln($separator);
        $output->writeln('  ' . $title);
        $output->writeln($separator);
        foreach ($lines as $line) {
            $output->writeln('  ' . $line);
        }
        if ($lines !== []) {
            $output->writeln('');
        }
    }
}
