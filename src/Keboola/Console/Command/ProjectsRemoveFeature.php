<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectsRemoveFeature extends Command
{
    /** @var int */
    private $maintainersChecked = 0;

    /** @var int */
    private $orgsChecked = 0;

    /** @var int */
    private $projectsDisabled = 0;

    /** @var int */
    private $projectsWithoutFeature = 0;

    /** @var int */
    private $projectsUpdated = 0;

    protected function configure(): void
    {
        $this
            ->setName('manage:projects-remove-feature')
            ->setDescription('Remove feature from multiple projects')
            ->addArgument('token', InputArgument::REQUIRED, 'manage token')
            ->addArgument('url', InputArgument::REQUIRED, 'Stack URL')
            ->addArgument('feature', InputArgument::REQUIRED, 'feature name')
            ->addArgument('projects', InputArgument::REQUIRED, 'list of IDs separated by comma ("1,7,146") or "ALL"')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $apiToken = (string) $input->getArgument('token');
        $apiUrl = (string) $input->getArgument('url');
        $featureName = (string) $input->getArgument('feature');
        $projects = (string) $input->getArgument('projects');
        $allProjects = strtolower($projects) === 'all';

        $force = (bool) $input->getOption('force');

        $client = $this->createClient($apiUrl, $apiToken);

        if (!$this->featureExists($client, $featureName)) {
            $output->writeln(sprintf('Feature %s does NOT exist', $featureName));
            return 1;
        }

        if ($allProjects) {
            $this->removeFeatureFromAllProjects($client, $output, $featureName, $force);
        } else {
            $projectIds = array_filter(explode(',', $projects), 'is_numeric');
            $this->removeFeatureFromSelectedProjects($client, $output, $featureName, $projectIds, $force);
        }
        $output->writeln('');

        $output->writeln('DONE with following results:');
        $this->printResult($output, $allProjects);

        if (!$force) {
            $output->writeln('');
            $output->writeln('Command was run in <comment>dry-run</comment> mode. To actually apply changes run it with --force flag.');
        }

        return 0;
    }

    private function createClient(string $host, string $token): Client
    {
        return new Client([
            'url' => $host,
            'token' => $token,
        ]);
    }

    private function removeFeatureFromAllProjects(
        Client $client,
        OutputInterface $output,
        string $feature,
        bool $force
    ): void {
        $maintainers = $client->listMaintainers();
        foreach ($maintainers as $maintainer) {
            $this->maintainersChecked++;
            $organizations = $client->listMaintainerOrganizations($maintainer['id']);
            foreach ($organizations as $organization) {
                $this->orgsChecked++;

                $projects = $client->listOrganizationProjects($organization['id']);
                foreach ($projects as $project) {
                    $output->write(sprintf('Project <comment>%s</comment>: ', $project['id']));
                    $this->removeFeatureFromProject($client, $output, $project, $feature, $force);
                }
            }
        }
    }

    /**
     * @param array<int, string> $projectIds
     */
    private function removeFeatureFromSelectedProjects(
        Client $client,
        OutputInterface $output,
        string $featureName,
        array $projectIds,
        bool $force
    ): void {
        foreach ($projectIds as $projectId) {
            $output->write(sprintf('Project <comment>%s</comment>: ', $projectId));

            try {
                $project = $client->getProject($projectId);
                $this->removeFeatureFromProject($client, $output, $project, $featureName, $force);
            } catch (ClientException $e) {
                if ($e->getCode() === 404) {
                    $output->writeln('<error>not found</error>');
                } else {
                    $output->writeln(sprintf('<error>error</error>: %s', $e->getMessage()));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $projectInfo
     */
    private function removeFeatureFromProject(
        Client $client,
        OutputInterface $output,
        array $projectInfo,
        string $featureName,
        bool $force
    ): void {
        if (isset($projectInfo['isDisabled']) && $projectInfo['isDisabled']) {
            $output->writeln('project is disabled, <comment>skipping</comment>');
            $this->projectsDisabled++;

            return;
        }

        $features = $projectInfo['features'];
        assert(is_array($features));
        if (!in_array($featureName, $features, true)) {
            $output->writeln('doesn\'t have the feature, <comment>skipping</comment>');
            $this->projectsWithoutFeature++;

            return;
        }

        if ($force) {
            $client->removeProjectFeature($projectInfo['id'], $featureName);
        }

        $output->writeln('feature <info>removed</info>');
        $this->projectsUpdated++;
    }

    private function featureExists(Client $client, string $featureName): bool
    {
        $projects = $client->listFeatures(['type' => 'project']);
        foreach ($projects as $project) {
            if ($project['name'] === $featureName) {
                return true;
            }
        }
        return false;
    }

    private function printResult(OutputInterface $output, bool $checkAll): void
    {
        if ($checkAll) {
            $output->writeln(sprintf('  Checked %d maintainers', $this->maintainersChecked));
            $output->writeln(sprintf('  Checked %d organizations', $this->orgsChecked));
        }

        $output->writeln(sprintf('  %d projects disabled', $this->projectsDisabled));
        $output->writeln(sprintf('  %d projects already without the feature', $this->projectsWithoutFeature));
        $output->writeln(sprintf('  %d projects updated', $this->projectsUpdated));
    }
}
