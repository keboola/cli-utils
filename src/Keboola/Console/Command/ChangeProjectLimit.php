<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ChangeProjectLimit extends Command
{

    const ARGUMENT_SOURCE_FILE = 'source-file';
    const OPTION_FORCE = 'force';
    const NEW_LIMITS = [
        [
            'name' => 'storage.jobsParallelism',
            'value' => 50
        ]
    ];

    protected function configure()
    {
        $this
            ->setName('manage:change-project-limit')
            ->addArgument(self::ARGUMENT_SOURCE_FILE, InputArgument::REQUIRED, 'source file')
            ->addOption(self::OPTION_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run')
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sourceFile = $input->getArgument(self::ARGUMENT_SOURCE_FILE);
        $output->writeln(sprintf('Fetching projects from "%s"', $sourceFile));
        $force = $input->getOption(self::OPTION_FORCE);
        $output->writeln($force ? 'FORCE MODE' : 'DRY RUN');

        if ($force) {
            sleep(1);
        }

        $manageTokenUs = getenv('KBC_MANAGE_TOKEN_US');
        $manageTokenEu = getenv('KBC_MANAGE_TOKEN_EU');
        $manageTokenNe = getenv('KBC_MANAGE_TOKEN_NE');
        if (!($manageTokenEu && $manageTokenUs)) {
            throw new \Exception('Missing token');
        }

        $clients['US'] = new Client(['token' => $manageTokenUs, 'url' => 'https://connection.keboola.com']);
        $clients['EU'] = new Client(['token' => $manageTokenEu, 'url' => 'https://connection.eu-central-1.keboola.com/']);
        $clients['NE'] = new Client(['token' => $manageTokenNe, 'url' => 'https://connection.north-europe.azure.keboola.com/']);

        if (!file_exists($sourceFile)) {
            throw new \Exception(sprintf('Cannot open "%s"', $sourceFile));
        }
        $projectsText = trim(file_get_contents($sourceFile));
        if (!$projectsText) {
            return;
        }

        $projects = [];
        foreach (explode(PHP_EOL, $projectsText) as $projectRow) {
            $project = [];
            $parts = explode('-', $projectRow);

            [$project['id'], $project['region']] = $parts;
            $projects[$project['id']] = $project;
        }

        $output->writeln(sprintf('Found "%s" projects', count($projects)));

        foreach ($projects as $project) {
            if (array_key_exists($project['region'], $clients)) {
                /** @var Client $client */
                $client = $clients[$project['region']];
                try {
                    $projectFromApi = $client->getProject($project['id']);
                } catch (\Exception $e) {
                    $output->writeln('Not found '.$project['id'].'-'.$project['region'].' '.$e->getMessage());
                }
                if ($force) {
                    $client->setProjectLimits($project['id'], self::NEW_LIMITS);
                    $output->writeln(sprintf(
                        'Updated project "%s" in "%s"',
                        $projectFromApi['id'],
                        $project['region']
                    ));
                } else {
                    $output->writeln(sprintf(
                        'Would update project "%s" in "%s" with limits %s',
                        $projectFromApi['id'],
                        $project['region'],
                        json_encode(self::NEW_LIMITS)
                    ));
                }
            }
        }
    }
}
