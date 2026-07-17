<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException as ManageClientException;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException as StorageClientException;
use Keboola\StorageApi\Components;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class MigrateDataAppsOrchestratorTasks extends Command
{
    const ARG_TOKEN = 'token';
    const ARG_URL = 'url';
    const ARG_PROJECTS = 'projects';
    const OPT_FORCE = 'force';

    protected int $maintainersChecked = 0;
    protected int $orgsChecked = 0;
    protected int $projectsChecked = 0;
    protected int $projectsDisabled = 0;
    protected int $projectsError = 0;
    protected int $configsScanned = 0;
    protected int $configsTouched = 0;
    protected int $tasksMigrated = 0;
    protected int $tasksSkippedUnsupported = 0;
    protected int $tasksSkippedUnresolvable = 0;

    private DataAppOrchestratorTaskMigrator $migrator;

    public function __construct()
    {
        parent::__construct();
        $this->migrator = new DataAppOrchestratorTaskMigrator();
    }

    protected function configure(): void
    {
        $this
            ->setName('manage:migrate-data-apps-orchestrator-tasks')
            ->setDescription('Migrate orchestration/flow tasks from keboola.data-apps to keboola.data-app-control')
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'manage token')
            ->addArgument(self::ARG_URL, InputArgument::REQUIRED, 'Stack URL')
            ->addArgument(self::ARG_PROJECTS, InputArgument::REQUIRED, 'list of project IDs separated by comma or "all"')
            ->addOption(self::OPT_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run');
    }

    protected function createManageClient(string $host, string $token): Client
    {
        return new Client([
            'url' => $host,
            'token' => $token,
        ]);
    }

    // Cheap safety net against an accidental stack-wide mutation: "all" + "--force" is the highest
    // blast-radius combination this command supports, so it gets an explicit confirmation on top of
    // the dry-run default (mirrors the ConfirmationQuestion pattern used by
    // MassProjectEnableDynamicBackends for a less impactful change).
    private function confirmStackWideForceRun(InputInterface $input, OutputInterface $output, string $url): bool
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            sprintf(
                'You are about to migrate keboola.data-apps orchestrator/flow tasks on ALL projects on "%s" with'
                . ' --force. Continue? (y/n) ',
                $url
            ),
            false,
            '/^(y|j)/i'
        );

        return (bool) $helper->ask($input, $output, $question);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption(self::OPT_FORCE);
        $token = $input->getArgument(self::ARG_TOKEN);
        assert(is_string($token));
        $url = $input->getArgument(self::ARG_URL);
        assert(is_string($url));
        $projectsOption = $input->getArgument(self::ARG_PROJECTS);
        assert(is_string($projectsOption));

        $output->writeln($force
            ? 'Running in force mode. Configurations will be updated.'
            : 'Running in dry-run mode. No configurations will be updated. Use -f to enable force mode.');

        $checkAllProjects = strtolower($projectsOption) === 'all';

        if ($checkAllProjects && $force && !$this->confirmStackWideForceRun($input, $output, $url)) {
            $output->writeln('Aborted.');
            return 0;
        }

        $manageClient = $this->createManageClient($url, $token);

        if ($checkAllProjects) {
            $this->migrateAllProjects($manageClient, $output, $url, $force);
        } else {
            $projectIds = $this->parseProjectIds($projectsOption);
            if ($projectIds === null) {
                $output->writeln(sprintf(
                    'Invalid "%s" argument: expected a comma-separated list of numeric project IDs or "all", got "%s"',
                    self::ARG_PROJECTS,
                    $projectsOption
                ));
                return 1;
            }
            $this->migrateSelectedProjects($manageClient, $output, $url, $projectIds, $force);
        }

        $output->writeln("\nDONE with following results:\n");
        $this->printResult($output, $checkAllProjects, $force);

        return 0;
    }

    protected function migrateAllProjects(Client $manageClient, OutputInterface $output, string $url, bool $force): void
    {
        $maintainers = $manageClient->listMaintainers();
        foreach ($maintainers as $maintainer) {
            $this->maintainersChecked++;
            $organizations = $manageClient->listMaintainerOrganizations($maintainer['id']);
            foreach ($organizations as $organization) {
                $this->orgsChecked++;
                try {
                    $projects = $manageClient->listOrganizationProjects($organization['id']);
                } catch (ManageClientException $e) {
                    // The Manage token may not have access to every organization on the stack (e.g. it
                    // isn't a member/admin there) - skip it rather than aborting the whole stack-wide run.
                    $output->writeln(sprintf(
                        ' - error while listing projects for organization "%s": %s',
                        $organization['id'],
                        $e->getMessage()
                    ));
                    continue;
                }
                foreach ($projects as $project) {
                    $this->migrateProject($manageClient, $output, $url, (string) $project['id'], $force);
                }
            }
        }
    }

    /**
     * Splits the comma-separated projects argument into a list of numeric project IDs, or returns
     * null if any entry is not a plain non-negative integer (e.g. "foo", "1.2", "1e3", "").
     *
     * @return array<int, string>|null
     */
    private function parseProjectIds(string $projectsOption): ?array
    {
        $projectIds = array_map('trim', explode(',', $projectsOption));
        foreach ($projectIds as $projectId) {
            if (!ctype_digit($projectId)) {
                return null;
            }
        }

        return $projectIds;
    }

    /**
     * @param array<int, string> $projectIds
     */
    protected function migrateSelectedProjects(Client $manageClient, OutputInterface $output, string $url, array $projectIds, bool $force): void
    {
        foreach ($projectIds as $projectId) {
            $this->migrateProject($manageClient, $output, $url, $projectId, $force);
        }
    }

    protected function migrateProject(Client $manageClient, OutputInterface $output, string $url, string $projectId, bool $force): void
    {
        $output->writeln(sprintf('Processing project "%s"', $projectId));
        try {
            $project = $manageClient->getProject($projectId);
            if (isset($project['isDisabled']) && $project['isDisabled']) {
                $output->writeln(' - project disabled, skipping.');
                $this->projectsDisabled++;
                return;
            }

            $tokenInfo = $manageClient->createProjectStorageToken($projectId, [
                'description' => 'AJDA-2445 data-apps orchestrator task migration',
                'canManageBuckets' => false,
                'canManageTokens' => false,
                'expiresIn' => 300,
                // Without an explicit grant, projects that restrict component access by default
                // return 403 "You don't have access to the resource." when listing configurations
                // for these components - verified live against a North Europe project (AJDA-3010).
                'componentAccess' => ['keboola.orchestrator', 'keboola.flow', 'keboola.data-apps'],
            ]);

            $components = new Components(new StorageClient([
                'url' => $url,
                'token' => $tokenInfo['token'],
            ]));

            $result = $this->migrator->migrateProject($components, $output, $projectId, $force);

            $this->configsScanned += $result['configsScanned'];
            $this->configsTouched += $result['configsTouched'];
            $this->tasksMigrated += $result['tasksMigrated'];
            $this->tasksSkippedUnsupported += $result['tasksSkippedUnsupported'];
            $this->tasksSkippedUnresolvable += $result['tasksSkippedUnresolvable'];
            $this->projectsChecked++;
        } catch (ManageClientException | StorageClientException $e) {
            $output->writeln(sprintf(' - error while processing project "%s": %s', $projectId, $e->getMessage()));
            $this->projectsError++;
        }
        $output->write("\n");
    }

    private function printResult(OutputInterface $output, bool $checkAll, bool $force): void
    {
        // "Checked" means "attempted" here - it must include disabled/errored projects too,
        // otherwise it undercounts and conflicts with the disabled/error breakdown lines below it.
        $totalProjectsChecked = $this->projectsChecked + $this->projectsDisabled + $this->projectsError;

        $output->writeln(
            ($checkAll ? sprintf(
                "Checked %d maintainers\n"
                . "Checked %d organizations\n",
                $this->maintainersChecked,
                $this->orgsChecked
            ) : '')
            . sprintf(
                "Checked %d projects\n"
                . "%d projects were disabled\n"
                . "%d projects had errors\n"
                . "Scanned %d orchestrator/flow configurations\n"
                . '%d configurations ' . ($force ? 'updated' : 'would be updated in force mode') . "\n"
                . '%d tasks ' . ($force ? 'migrated' : 'would be migrated in force mode') . "\n"
                . "%d tasks skipped (unsupported shape - expected, no action needed)\n"
                . "%d tasks skipped as UNRESOLVABLE (needs manual follow-up)\n",
                $totalProjectsChecked,
                $this->projectsDisabled,
                $this->projectsError,
                $this->configsScanned,
                $this->configsTouched,
                $this->tasksMigrated,
                $this->tasksSkippedUnsupported,
                $this->tasksSkippedUnresolvable
            )
        );
    }
}
