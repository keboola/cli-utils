<?php
namespace Keboola\Console\Command;

use Keboola\Csv\CsvFile;
use Keboola\ManageApi\ClientException;
use Keboola\StorageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectsAddFeature extends Command
{

    protected function configure()
    {
        $this
            ->setName('storage:projects-add-feature')
            ->setDescription('Add feature to multiple projects')
            ->addArgument('token', InputArgument::REQUIRED, 'manage token')
            ->addArgument('feature', InputArgument::REQUIRED, 'feature')
            ->addArgument('projects', InputArgument::REQUIRED, 'single project id or range (eg 10..500)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');
        $feature = $input->getArgument('feature');
        if (strpos($input->getArgument('projects'), '..')) {
            list($start, $end) = explode('..', $input->getArgument('projects'));
            $projects = range($start, $end);
        } else {
            $projects = [$input->getArgument('projects')];
        }

        $manageClient = new \Keboola\ManageApi\Client(["token" => $token]);

        foreach ($projects as $projectId) {
            $output->writeln("Adding feature to project " . $projectId);
            try {
                $projectInfo = $manageClient->getProject($projectId);
            } catch (ClientException $e) {
                $output->writeln($e->getMessage());
                $output->write("\n");
                continue;
            }

            // Disabled projects
            if (isset($projectInfo["isDisabled"]) && $projectInfo["isDisabled"]) {
                $output->writeln("Project disabled: " . $projectInfo["disabled"]["reason"]);
            } else {
                if (in_array($feature, $projectInfo["features"])) {
                    $output->writeln("Feature '{$feature}' is already set.");
                } else {
                    $manageClient->addProjectFeature($projectId, $feature);
                    $output->writeln("Feature '{$feature}' successfully added.");
                }
            }
            $output->write("\n");
        }
    }
}
