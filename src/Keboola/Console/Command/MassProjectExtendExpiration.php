<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MassProjectExtendExpiration extends Command
{

    const ARGUMENT_STACK_TOKEN = 'token';
    const ARGUMENT_SOURCE_FILE = 'source-file';
    const OPTION_FORCE = 'force';
    const ARGUMENT_TOKEN_STACK = 'token-stack';
    const ARGUMENT_EXPIRATION_DAYS = 'extend-days';

    protected function configure(): void
    {
        $this
            ->setName('manage:mass-project-remove-expiration')
            ->setDescription('Temporary solution for manual TryMode expiration from ZD#14161')
            ->addArgument(self::ARGUMENT_SOURCE_FILE, InputArgument::REQUIRED, 'source file')
            ->addArgument(self::ARGUMENT_EXPIRATION_DAYS, InputArgument::REQUIRED, 'number of days to extend, 0 to remove expiration completely')
            ->addOption(self::OPTION_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run')
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourceFile = $input->getArgument(self::ARGUMENT_SOURCE_FILE);
        assert(is_string($sourceFile));
        $output->writeln(sprintf('Fetching projects from "%s"', $sourceFile));
        $expirationDays = $input->getArgument(self::ARGUMENT_EXPIRATION_DAYS);
        assert(is_string($expirationDays));
        $output->writeln(sprintf('Expiration days "%s"', $expirationDays));
        $force = (bool) $input->getOption(self::OPTION_FORCE);
        $output->writeln($force ? 'FORCE MODE' : 'DRY RUN');

        if ($force) {
            sleep(1);
        }

        $manageTokenUs = getenv('KBC_MANAGE_TOKEN_US');
        $manageTokenEu = getenv('KBC_MANAGE_TOKEN_EU');
        $manageTokenNe = getenv('KBC_MANAGE_TOKEN_NE');

        $clients = [];
        if ($manageTokenUs) {
            $clients['US'] = new Client(['token' => $manageTokenUs, 'url' => 'https://connection.keboola.com']);
        }
        if ($manageTokenEu) {
            $clients['EU'] = new Client(['token' => $manageTokenEu, 'url' => 'https://connection.eu-central-1.keboola.com/']);
        }
        if ($manageTokenNe) {
            $clients['NE'] = new Client(['token' => $manageTokenNe, 'url' => 'https://connection.north-europe.azure.keboola.com/']);
        }

        if (!file_exists($sourceFile)) {
            throw new \Exception(sprintf('Cannot open "%s"', $sourceFile));
        }
        $fileContents = file_get_contents($sourceFile);
        if ($fileContents === false) {
            throw new \Exception(sprintf('Cannot read "%s"', $sourceFile));
        }
        $projectsText = trim($fileContents);
        if (!$projectsText) {
            return 0;
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
                $projectFromApi = $client->getProject($project['id']);
                if ($force) {
                    $updatedProjectFromApi = $client->updateProject($project['id'], ['expirationDays' =>
                        $expirationDays]);
                    $output->writeln(sprintf(
                        'Updated project "%s" in "%s" with current expiration "%s" to new expiration "%s" (%s days) (%s - %s)',
                        $projectFromApi['id'],
                        $project['region'],
                        $projectFromApi['expires'],
                        $updatedProjectFromApi['expires'],
                        $expirationDays,
                        $projectFromApi['organization']['name'],
                        $projectFromApi['name']
                    ));
                } else {
                    $output->writeln(sprintf(
                        'Would update project "%s" in "%s" with current expiration "%s" to new expiration days "%s" (%s - %s)',
                        $projectFromApi['id'],
                        $project['region'],
                        $projectFromApi['expires'],
                        $expirationDays,
                        $projectFromApi['organization']['name'],
                        $projectFromApi['name']
                    ));
                }
            } else {
                $output->writeln(sprintf(
                    'Project "%s" is in "%s" for which there is no client set up',
                    $project['id'],
                    $project['region']
                ));
            }
        }

        return 0;
    }
}
