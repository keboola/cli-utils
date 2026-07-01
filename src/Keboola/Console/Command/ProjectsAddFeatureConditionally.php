<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectsAddFeatureConditionally extends ProjectsAddFeature
{
    const string ARG_CONDITION_FEATURE = 'condition-feature';
    const string ARG_TARGET_FEATURE = 'target-feature';
    const string OPT_MAINTAINER_ID = 'maintainer-id';
    const string OPT_ORGANIZATION_ID = 'organization-id';
    const string OPT_PROJECT_ID = 'project-id';
    const string OPT_CONDITION_MODE = 'condition-mode';
    const string CONDITION_MODE_PRESENT = 'present';
    const string CONDITION_MODE_ABSENT = 'absent';

    protected int $projectsSkippedByCondition = 0;

    protected function configure(): void
    {
        $this
            ->setName('manage:projects-add-feature-conditionally')
            ->setDescription('Add a target feature to projects based on whether they have a given condition feature')
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'manage token')
            ->addArgument(self::ARG_URL, InputArgument::REQUIRED, 'Stack URL')
            ->addArgument(
                self::ARG_CONDITION_FEATURE,
                InputArgument::REQUIRED,
                'condition feature(s), comma-separated for multiple; evaluated together (see --condition-mode)'
            )
            ->addArgument(self::ARG_TARGET_FEATURE, InputArgument::REQUIRED, 'feature to add')
            ->addOption(
                self::OPT_MAINTAINER_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'Limit scope to projects of all organizations of this maintainer'
            )
            ->addOption(
                self::OPT_ORGANIZATION_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'Limit scope to projects of this organization'
            )
            ->addOption(
                self::OPT_PROJECT_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'Limit scope to this single project'
            )
            ->addOption(
                self::OPT_CONDITION_MODE,
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Whether the condition feature must be "%s" or "%s" on the project',
                    self::CONDITION_MODE_PRESENT,
                    self::CONDITION_MODE_ABSENT
                ),
                self::CONDITION_MODE_PRESENT
            )
            ->addOption(self::OPT_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption(self::OPT_FORCE);
        $conditionFeaturesArg = $input->getArgument(self::ARG_CONDITION_FEATURE);
        assert(is_string($conditionFeaturesArg));
        $conditionFeatures = array_values(array_filter(
            array_map('trim', explode(',', $conditionFeaturesArg)),
            fn($feature) => $feature !== ''
        ));
        if (count($conditionFeatures) === 0) {
            $output->writeln('ERROR: At least one condition feature must be provided.');
            return 1;
        }
        $targetFeature = $input->getArgument(self::ARG_TARGET_FEATURE);
        assert(is_string($targetFeature));
        $url = $input->getArgument(self::ARG_URL);
        assert(is_string($url));
        $token = $input->getArgument(self::ARG_TOKEN);
        assert(is_string($token));

        $maintainerId = $input->getOption(self::OPT_MAINTAINER_ID);
        $organizationId = $input->getOption(self::OPT_ORGANIZATION_ID);
        $projectId = $input->getOption(self::OPT_PROJECT_ID);

        $scopeOptions = array_filter(
            [$maintainerId, $organizationId, $projectId],
            fn($value) => $value !== null
        );
        if (count($scopeOptions) > 1) {
            $output->writeln('ERROR: Options --maintainer-id, --organization-id and --project-id are mutually exclusive.');
            return 1;
        }

        $conditionMode = $input->getOption(self::OPT_CONDITION_MODE);
        assert(is_string($conditionMode));
        if (!in_array($conditionMode, [self::CONDITION_MODE_PRESENT, self::CONDITION_MODE_ABSENT], true)) {
            $output->writeln(sprintf(
                'ERROR: Option --%s must be either "%s" or "%s".',
                self::OPT_CONDITION_MODE,
                self::CONDITION_MODE_PRESENT,
                self::CONDITION_MODE_ABSENT
            ));
            return 1;
        }

        $client = $this->createClient($url, $token);

        foreach ($conditionFeatures as $conditionFeature) {
            if (!$this->checkIfFeatureExists($client, $conditionFeature)) {
                $output->writeln(sprintf('Condition feature %s does NOT exist', $conditionFeature));
                return 1;
            }
        }
        if (!$this->checkIfFeatureExists($client, $targetFeature)) {
            $output->writeln(sprintf('Target feature %s does NOT exist', $targetFeature));
            return 1;
        }

        if ($projectId !== null) {
            assert(is_string($projectId));
            $this->processProject($client, $output, $projectId, $conditionFeatures, $targetFeature, $conditionMode, $force);
        } elseif ($organizationId !== null) {
            assert(is_string($organizationId));
            $this->processOrganization($client, $output, $organizationId, $conditionFeatures, $targetFeature, $conditionMode, $force);
        } elseif ($maintainerId !== null) {
            assert(is_string($maintainerId));
            $this->processMaintainer($client, $output, $maintainerId, $conditionFeatures, $targetFeature, $conditionMode, $force);
        } else {
            $this->processAllProjects($client, $output, $conditionFeatures, $targetFeature, $conditionMode, $force);
        }

        $output->writeln("\nDONE with following results:\n");
        $this->printResult($output, $force);

        return 0;
    }

    /**
     * @param array{
     *     id: int,
     *     isDisabled?: bool,
     *     disabled: array{reason: string},
     *     features: string[]
     * } $projectInfo
     * @param list<string> $conditionFeatures
     */
    protected function addFeatureToProjectConditionally(
        Client $client,
        OutputInterface $output,
        array $projectInfo,
        array $conditionFeatures,
        string $targetFeature,
        string $conditionMode,
        bool $force
    ): void {
        $projectId = (string) $projectInfo['id'];
        $output->writeln('Checking project ' . $projectId);

        if (isset($projectInfo['isDisabled']) && $projectInfo['isDisabled']) {
            $output->writeln(' - project disabled: ' . $projectInfo['disabled']['reason']);
            $this->projectsDisabled++;
            $output->write("\n");
            return;
        }

        $features = $projectInfo['features'];

        $presentConditions = array_values(array_intersect($conditionFeatures, $features));
        $missingConditions = array_values(array_diff($conditionFeatures, $features));

        // present: project must have ALL condition features; absent: project must have NONE of them
        $conditionMet = $conditionMode === self::CONDITION_MODE_PRESENT
            ? count($missingConditions) === 0
            : count($presentConditions) === 0;

        if (!$conditionMet) {
            if ($conditionMode === self::CONDITION_MODE_PRESENT) {
                $output->writeln(sprintf(
                    ' - condition not met, project is missing feature(s): %s, skipping.',
                    implode(', ', $missingConditions)
                ));
            } else {
                $output->writeln(sprintf(
                    ' - condition not met, project has feature(s): %s, skipping.',
                    implode(', ', $presentConditions)
                ));
            }
            $this->projectsSkippedByCondition++;
            $output->write("\n");
            return;
        }

        if (in_array($targetFeature, $features, true)) {
            $output->writeln(sprintf(' - target feature "%s" is already set.', $targetFeature));
            $this->projectsWithFeature++;
            $output->write("\n");
            return;
        }

        if ($force) {
            $client->addProjectFeature($projectInfo['id'], $targetFeature);
            $output->writeln(sprintf(' - target feature "%s" successfully added.', $targetFeature));
        } else {
            $output->writeln(sprintf(
                ' - target feature "%s" CAN be added (condition met). Enable force mode with -f option.',
                $targetFeature
            ));
        }
        $this->projectsUpdated++;
        $output->write("\n");
    }

    /**
     * @param list<string> $conditionFeatures
     */
    protected function processProject(
        Client $client,
        OutputInterface $output,
        string $projectId,
        array $conditionFeatures,
        string $targetFeature,
        string $conditionMode,
        bool $force
    ): void {
        try {
            $project = $client->getProject($projectId);
            /**
             * @var array{
             *     id: int,
             *     isDisabled?: bool,
             *     disabled: array{reason: string},
             *     features: string[]
             * } $project
             */
            $this->addFeatureToProjectConditionally($client, $output, $project, $conditionFeatures, $targetFeature, $conditionMode, $force);
        } catch (ClientException $e) {
            $output->writeln("Error while handling project {$projectId} : " . $e->getMessage());
        }
    }

    /**
     * @param list<string> $conditionFeatures
     */
    protected function processOrganization(
        Client $client,
        OutputInterface $output,
        string $organizationId,
        array $conditionFeatures,
        string $targetFeature,
        string $conditionMode,
        bool $force
    ): void {
        $this->orgsChecked++;
        $projects = $client->listOrganizationProjects($organizationId);
        foreach ($projects as $project) {
            /**
             * @var array{
             *     id: int,
             *     isDisabled?: bool,
             *     disabled: array{reason: string},
             *     features: string[]
             * } $project
             */
            try {
                $this->addFeatureToProjectConditionally($client, $output, $project, $conditionFeatures, $targetFeature, $conditionMode, $force);
            } catch (ClientException $e) {
                $output->writeln("Error while handling project {$project['id']} : " . $e->getMessage());
            }
        }
    }

    /**
     * @param list<string> $conditionFeatures
     */
    protected function processMaintainer(
        Client $client,
        OutputInterface $output,
        string $maintainerId,
        array $conditionFeatures,
        string $targetFeature,
        string $conditionMode,
        bool $force
    ): void {
        $this->maintainersChecked++;
        $organizations = $client->listMaintainerOrganizations($maintainerId);
        foreach ($organizations as $organization) {
            $this->processOrganization(
                $client,
                $output,
                (string) $organization['id'],
                $conditionFeatures,
                $targetFeature,
                $conditionMode,
                $force
            );
        }
    }

    /**
     * @param list<string> $conditionFeatures
     */
    protected function processAllProjects(
        Client $client,
        OutputInterface $output,
        array $conditionFeatures,
        string $targetFeature,
        string $conditionMode,
        bool $force
    ): void {
        $maintainers = $client->listMaintainers();
        foreach ($maintainers as $maintainer) {
            $this->processMaintainer(
                $client,
                $output,
                (string) $maintainer['id'],
                $conditionFeatures,
                $targetFeature,
                $conditionMode,
                $force
            );
        }
    }

    private function printResult(OutputInterface $output, bool $force): void
    {
        $output->writeln(sprintf(
            "Checked %d maintainers\n"
            . "Checked %d organizations\n"
            . "%d projects were disabled\n"
            . "%d projects skipped (condition not met)\n"
            . "%d projects have the target feature already\n"
            . '%d ' . ($force ? 'projects updated' : 'projects can be updated in force mode') . "\n",
            $this->maintainersChecked,
            $this->orgsChecked,
            $this->projectsDisabled,
            $this->projectsSkippedByCondition,
            $this->projectsWithFeature,
            $this->projectsUpdated
        ));
    }
}
