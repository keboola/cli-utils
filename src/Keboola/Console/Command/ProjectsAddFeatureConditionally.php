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

    protected int $projectsWithoutCondition = 0;

    protected function configure(): void
    {
        $this
            ->setName('manage:projects-add-feature-conditionally')
            ->setDescription('Add a target feature to projects that already have a given condition feature')
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'manage token')
            ->addArgument(self::ARG_URL, InputArgument::REQUIRED, 'Stack URL')
            ->addArgument(self::ARG_CONDITION_FEATURE, InputArgument::REQUIRED, 'feature a project must already have')
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
            ->addOption(self::OPT_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption(self::OPT_FORCE);
        $conditionFeature = $input->getArgument(self::ARG_CONDITION_FEATURE);
        assert(is_string($conditionFeature));
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

        $client = $this->createClient($url, $token);

        if (!$this->checkIfFeatureExists($client, $conditionFeature)) {
            $output->writeln(sprintf('Condition feature %s does NOT exist', $conditionFeature));
            return 1;
        }
        if (!$this->checkIfFeatureExists($client, $targetFeature)) {
            $output->writeln(sprintf('Target feature %s does NOT exist', $targetFeature));
            return 1;
        }

        if ($projectId !== null) {
            assert(is_string($projectId));
            $this->processProject($client, $output, $projectId, $conditionFeature, $targetFeature, $force);
        } elseif ($organizationId !== null) {
            assert(is_string($organizationId));
            $this->processOrganization($client, $output, $organizationId, $conditionFeature, $targetFeature, $force);
        } elseif ($maintainerId !== null) {
            assert(is_string($maintainerId));
            $this->processMaintainer($client, $output, $maintainerId, $conditionFeature, $targetFeature, $force);
        } else {
            $this->processAllProjects($client, $output, $conditionFeature, $targetFeature, $force);
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
     */
    protected function addFeatureToProjectConditionally(
        Client $client,
        OutputInterface $output,
        array $projectInfo,
        string $conditionFeature,
        string $targetFeature,
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

        if (!in_array($conditionFeature, $features, true)) {
            $output->writeln(sprintf(' - condition feature "%s" is NOT set, skipping.', $conditionFeature));
            $this->projectsWithoutCondition++;
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
                ' - target feature "%s" CAN be added (project has "%s"). Enable force mode with -f option.',
                $targetFeature,
                $conditionFeature
            ));
        }
        $this->projectsUpdated++;
        $output->write("\n");
    }

    protected function processProject(
        Client $client,
        OutputInterface $output,
        string $projectId,
        string $conditionFeature,
        string $targetFeature,
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
            $this->addFeatureToProjectConditionally($client, $output, $project, $conditionFeature, $targetFeature, $force);
        } catch (ClientException $e) {
            $output->writeln("Error while handling project {$projectId} : " . $e->getMessage());
        }
    }

    protected function processOrganization(
        Client $client,
        OutputInterface $output,
        string $organizationId,
        string $conditionFeature,
        string $targetFeature,
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
            $this->addFeatureToProjectConditionally($client, $output, $project, $conditionFeature, $targetFeature, $force);
        }
    }

    protected function processMaintainer(
        Client $client,
        OutputInterface $output,
        string $maintainerId,
        string $conditionFeature,
        string $targetFeature,
        bool $force
    ): void {
        $this->maintainersChecked++;
        $organizations = $client->listMaintainerOrganizations($maintainerId);
        foreach ($organizations as $organization) {
            $this->processOrganization(
                $client,
                $output,
                (string) $organization['id'],
                $conditionFeature,
                $targetFeature,
                $force
            );
        }
    }

    protected function processAllProjects(
        Client $client,
        OutputInterface $output,
        string $conditionFeature,
        string $targetFeature,
        bool $force
    ): void {
        $maintainers = $client->listMaintainers();
        foreach ($maintainers as $maintainer) {
            $this->processMaintainer(
                $client,
                $output,
                (string) $maintainer['id'],
                $conditionFeature,
                $targetFeature,
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
            . "%d projects do not have the condition feature\n"
            . "%d projects have the target feature already\n"
            . '%d ' . ($force ? 'projects updated' : 'projects can be updated in force mode') . "\n",
            $this->maintainersChecked,
            $this->orgsChecked,
            $this->projectsDisabled,
            $this->projectsWithoutCondition,
            $this->projectsWithFeature,
            $this->projectsUpdated
        ));
    }
}
