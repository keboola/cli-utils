<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectsAddFeature extends Command
{
    const ARG_FEATURE = 'feature';
    const ARG_PROJECTS = 'projects';
    const ARG_URL = 'url';
    const ARG_TOKEN = 'token';
    const OPT_FORCE = 'force';

    protected $maintainersChecked = 0;

    protected $orgsChecked = 0;

    protected $projectsDisabled = 0;

    protected $projectsWithFeature = 0;

    protected $projectsUpdated = 0;

    protected function configure(): void
    {
        $this
            ->setName('manage:projects-add-feature')
            ->setDescription('Add feature to multiple projects')
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'manage token')
            ->addArgument(self::ARG_URL, InputArgument::REQUIRED, 'Stack URL')
            ->addArgument(self::ARG_FEATURE, InputArgument::REQUIRED, 'feature')
            ->addArgument(self::ARG_PROJECTS, InputArgument::REQUIRED, 'list of IDs separated by comma or "ALL"')
            ->addOption(self::OPT_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run');
    }

    protected function createClient(string $host, string $token): Client
    {
        return new Client([
            'url' => $host,
            'token' => $token,
        ]);
    }

    protected function addFeatureToProject(Client $client, OutputInterface $output, array $projectInfo, string $featureName, bool $force): void
    {
        $output->writeln("Adding feature to project " . $projectInfo['id']);

        // Disabled projects
        if (isset($projectInfo["isDisabled"]) && $projectInfo["isDisabled"]) {
            $output->writeln(" - project disabled: " . $projectInfo["disabled"]["reason"]);
            $this->projectsDisabled++;
        } else {
            if (in_array($featureName, $projectInfo["features"], true)) {
                $output->writeln(" - feature '{$featureName}' is already set.");
                $this->projectsWithFeature++;
            } else {
                if ($force) {
                    $client->addProjectFeature($projectInfo['id'], $featureName);
                    $output->writeln(" - feature '{$featureName}' successfully added.");
                } else {
                    $output->writeln(sprintf(' - feature "%s" DOES NOT exist in the project %s yet. Enable force mode with -f option', $featureName, $projectInfo['id']));
                }
                $this->projectsUpdated++;
            }
        }
        $output->write("\n");
    }

    protected function addFeatureToAllProjects(Client $client, OutputInterface $output, string $feature, bool $force): void
    {
        $maintainers = $client->listMaintainers();
        foreach ($maintainers as $maintainer) {
            $this->maintainersChecked++;
            $organizations = $client->listMaintainerOrganizations($maintainer['id']);
            foreach ($organizations as $organization) {
                $this->orgsChecked++;

                $projects = $client->listOrganizationProjects($organization['id']);
                foreach ($projects as $project) {
                    $this->addFeatureToProject($client, $output, $project, $feature, $force);
                }
            }
        }
    }

    protected function addFeatureToSelectedProjects(Client $client, OutputInterface $output, string $featureName, array $projectIds, bool $force): void
    {
        foreach ($projectIds as $projectId) {
            try {
                $project = $client->getProject($projectId);
                $this->addFeatureToProject($client, $output, $project, $featureName, $force);
            } catch (ClientException $e) {
                $output->writeln("Error while handling project {$projectId} : " . $e->getMessage());
            }
        }
    }

    protected function checkIfFeatureExists(Client $client, string $featureName): bool
    {
        $projects = $client->listFeatures(['type' => 'project']);
        foreach ($projects as $project) {
            if ($project['name'] === $featureName) {
                return true;
            }
        }
        return false;
    }

    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $args = $input->getArguments();
        $force = (bool) $input->getOption(self::OPT_FORCE);
        $featureName = $args[self::ARG_FEATURE];
        $projectsOption = $args[self::ARG_PROJECTS];
        $checkAllProjects = strtolower($projectsOption) === 'all';
        $client = $this->createClient($args[self::ARG_URL], $args[self::ARG_TOKEN]);

        if (!$this->checkIfFeatureExists($client, $featureName)) {
            $output->writeln(sprintf('Feature %s does NOT exist', $featureName));
            return 1;
        }
        if ($checkAllProjects) {
            $this->addFeatureToAllProjects($client, $output, $featureName, $force);
        } else {
            $projectIds = array_filter(explode(',', $projectsOption), 'is_numeric');
            $this->addFeatureToSelectedProjects($client, $output, $featureName, $projectIds, $force);
        }
        $output->writeln("\nDONE with following results:\n");
        $this->printResult($output, $checkAllProjects, $force);

        return 0;
    }

    private function printResult(OutputInterface $output, bool $checkAll, bool $force): void
    {
        $projectsOutput = sprintf(
            "%d projects where disabled\n"
            . "%d projects have the feature already\n"
            . '%d ' . ($force ? "projects updated" : "projects can be updated in force mode") . "\n",
            $this->projectsDisabled,
            $this->projectsWithFeature,
            $this->projectsUpdated
        );

        $output->writeln(
            ($checkAll ? sprintf(
                "Checked %d maintainers \n"
                . "Checked %d organizations \n",
                $this->maintainersChecked,
                $this->orgsChecked
            ) : '')
            . $projectsOutput
        );
    }
}
