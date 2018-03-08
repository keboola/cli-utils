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

class ProjectsRemoveFeature extends Command
{

    protected function configure()
    {
        $this
            ->setName('storage:projects-remove-feature')
            ->setDescription('Remove feature from multiple projects')
            ->addArgument('token', InputArgument::REQUIRED, 'manage token')
            ->addArgument('feature', InputArgument::REQUIRED, 'feature')
            ->addArgument('projects', InputArgument::REQUIRED, 'single project id or range (eg 10..500)')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'API URL', 'https://connection.keboola.com')
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

        $manageClient = new \Keboola\ManageApi\Client([
            "token" => $token,
            "url" => $input->getOption("url"),
        ]);

        foreach ($projects as $projectId) {
            $output->writeln("Removing feature from project " . $projectId);
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
                if (!in_array($feature, $projectInfo["features"])) {
                    $output->writeln("Feature '{$feature}' not found in the project.");
                } else {
                    $manageClient->removeProjectFeature($projectId, $feature);
                    $output->writeln("Feature '{$feature}' successfully removed.");
                }
            }
            $output->write("\n");
        }
    }
}
