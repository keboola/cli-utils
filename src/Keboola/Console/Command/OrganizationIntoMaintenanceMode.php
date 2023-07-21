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
    const ARGUMENT_REASON = 'disableReason';
    const ARGUMENT_ESTIMATED_END_TIME = 'estimatedEndTime';
    const ARGUMENT_HOSTNAME_SUFFIX = 'hostnameSuffix';
    const OPTION_FORCE = 'force';
    protected function configure()
    {
        $this
            ->setName('manage:set-organization-maintenance-mode')
            ->setDescription('Set maintenance mode for all projects in an organization')
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Use [--force, -f] to do it for real.'
            )
            ->addArgument(self::ARGUMENT_ORGANIZATION_ID, InputArgument::REQUIRED, 'Organization Id')
            ->addArgument(
                self::ARGUMENT_MAINTENANCE_MODE,
                InputArgument::REQUIRED,
                'use "on" to turn on maintenance mode, and "off" to turn it off'
            )
            ->addArgument(
                self::ARGUMENT_REASON,
                InputArgument::REQUIRED,
                'Reason for maintenance (ex Migration)'
            )
            ->addArgument(
                self::ARGUMENT_ESTIMATED_END_TIME,
                InputArgument::OPTIONAL,
                'Estimated time of maintenance (ex + 5 hours)'
            )
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
                'The argument "%s" must be either "on" or "off", not "%s"',
                self::ARGUMENT_MAINTENANCE_MODE,
                $maintenanceMode
            ));
        }
        $reason = $input->getArgument(self::ARGUMENT_REASON);
        $estimatedEndTime = $input->getArgument(self::ARGUMENT_ESTIMATED_END_TIME);
        $organizationId = $input->getArgument(self::ARGUMENT_ORGANIZATION_ID);
        $kbcUrl = sprintf('https://connection.%s', $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX));
        $manageToken = getenv('KBC_MANAGE_TOKEN');
        $manageClient = new Client(['token' => $manageToken, 'url' => $kbcUrl]);

        $organization = $manageClient->getOrganization($organizationId);
        $projects = $organization['projects'];

        $output->writeln(
            sprintf(
                'Will put "%d" projects "%s" maintenance mode',
                count($projects),
                $maintenanceMode
            )
        );
        $force = $input->getOption(self::OPTION_FORCE);
        $output->writeln($force ? 'FORCE MODE' : 'DRY RUN');
        $params = [];
        if ($reason) {
            $params[self::ARGUMENT_REASON] = $reason;
        }
        if ($estimatedEndTime) {
            $params[self::ARGUMENT_ESTIMATED_END_TIME] = $estimatedEndTime;
        }
        foreach ($projects as $project) {
            $output->writeln(
                sprintf(
                    'Putting project %s %s maintenance mode',
                    $project['id'],
                    $maintenanceMode
                )
            );
            if ($force) {
                if ($maintenanceMode === 'on') {
                    $manageClient->disableProject(
                        $project['id'],
                        $params
                    );
                } elseif ($maintenanceMode === 'off') {
                    $manageClient->enableProject(
                        $project['id']
                    );
                }
            }
        }
        $output->writeln('All done.');
    }
}
