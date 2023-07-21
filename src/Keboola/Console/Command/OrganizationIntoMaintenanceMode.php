<?php


use Keboola\ManageApi\Client;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationIntoMaintenanceMode extends \Symfony\Component\Console\Command\Command
{
    const ARGUMENT_ORGANIZATION_ID = 'organizationId';
    const ARGUMENT_MAINTENANCE_MODE = 'maintenanceMode';
    const ARGUMENT_HOSTNAME_SUFFIX = 'hostnameSuffix';

    protected function configure()
    {
        $this
            ->setName('manage:mass-project-remove-expiration')
            ->setDescription('Set maintenance mode for all projects in an organization')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addArgument(self::ARGUMENT_ORGANIZATION_ID, InputArgument::REQUIRED, 'Organization Id')
            ->addArgument(self::ARGUMENT_MAINTENANCE_MODE, InputArgument::REQUIRED, 'use "on" to turn on maintenance mode, and "off" to turn it off')
            ->addArgument(
                self::ARGUMENT_HOSTNAME_SUFFIX,
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            )
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $maintenanceMode = $input->getArgument(self::ARGUMENT_MAINTENANCE_MODE);
        if (!in_array($maintenanceMode, ['on', 'off'])) {
            throw new Exception(sprintf(
                'The argument "%s" must be either "on" or "off", not "%s"'
            ));
        }
        $organizationId = $input->getArgument(self::ARGUMENT_ORGANIZATION_ID);
        $kbcUrl = sprintf('https://connection.%s', $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX));
        $manageToken = getenv('KBC_MANAGE_TOKEN');
        $manageClient = new Client(['token' => $manageToken, 'url' => $kbcUrl]);

        $organizationDelail = $manageClient->getOrganization($organizationId);


        $output->writeln(
            sprintf(
                'Will put "%d" projects "%s" maintenance mode',
                count($sourceFile),
                $maintenanceMode
            )
        );
        $output->writeln(sprintf('Expiration days "%s"', $expirationDays));
        $force = $input->getOption(self::OPTION_FORCE);
        $output->writeln($force ? 'FORCE MODE' : 'DRY RUN');

        if ($force) {
            sleep(1);
        }


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
    }
}
