<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\ClientException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationsAddFeature extends ProjectsAddFeature
{
    const string ARG_ORGANIZATIONS = 'organizations';

    protected function configure(): void
    {
        $this
            ->setName('manage:organizations-add-feature')
            ->setDescription('Add feature to all projects in organizations.')
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'manage token')
            ->addArgument(self::ARG_URL, InputArgument::REQUIRED, 'Stack URL')
            ->addArgument(self::ARG_FEATURE, InputArgument::REQUIRED, 'feature')
            ->addArgument(self::ARG_ORGANIZATIONS, InputArgument::REQUIRED, 'list of IDs separated by comma')
            ->addOption(self::OPT_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run');
    }

    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $args = $input->getArguments();
        $force = (bool) $input->getOption(self::OPT_FORCE);
        $featureName = $args[self::ARG_FEATURE];
        $orgIDsArg = $args[self::ARG_ORGANIZATIONS];
        $client = $this->createClient($args[self::ARG_URL], $args[self::ARG_TOKEN]);

        if (!$this->checkIfFeatureExists($client, $featureName)) {
            $output->writeln(sprintf('Feature %s does NOT exist', $featureName));
            return 1;
        }

        $failedOrgs = [];
        $successFullOrgs = [];
        $orgIds = array_filter(explode(',', $orgIDsArg), 'is_numeric');
        foreach ($orgIds as $orgId) {
            try {
                $orgDetail = $client->getOrganization($orgId);
            } catch (ClientException $e) {
                $output->writeln(sprintf('ERROR: Cannot proceed org "%s" due "%s"', $orgId, $e->getMessage()));
                $failedOrgs[] = $orgId;
            }
            $output->writeln(sprintf('Adding feature to organization "%s" ("%s")', $orgDetail['id'], $orgDetail['name']));
            $projectIds = array_map(fn($prj) => $prj['id'], $orgDetail['projects']);
            $this->addFeatureToSelectedProjects($client, $output, $featureName, $projectIds, $force);
            $successFullOrgs[] = $orgId;
        }

        $output->writeln("\nDONE with following results:\n");
        $this->printResult($output, $force, $successFullOrgs, $failedOrgs);

        return 0;
    }

    private function printResult(OutputInterface $output, bool $force, array $successFullOrgs, array $failedOrgs): void
    {
        $failedOrgsString = (count($failedOrgs) > 0) ? \sprintf(' (%s)', implode(', ', $failedOrgs)) : '';
        $output->writeln(sprintf(
                "Processed %d organizations and %s failed\n"
                . "%d projects where disabled\n"
                . "%d projects have the feature already\n"
                . '%d ' . ($force ? "projects updated" : "projects can be updated in force mode") . "\n",
                count($successFullOrgs),
                count($failedOrgs) . $failedOrgsString,
                $this->projectsDisabled,
                $this->projectsWithFeature,
                $this->projectsUpdated,
            )
        );
    }
}
