<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteStorageBackend extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('manage:delete-backend')
            ->setDescription('Delete storage backends from a stack by their IDs. Dry-run by default.')
            ->addArgument('token', InputArgument::REQUIRED, 'manage api token')
            ->addArgument('ids', InputArgument::REQUIRED, 'list of storage backend IDs separated by a comma')
            ->addArgument('url', InputArgument::REQUIRED, 'Stack URL')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apiToken = $input->getArgument('token');
        assert(is_string($apiToken));
        $apiUrl = $input->getArgument('url');
        assert(is_string($apiUrl));
        $ids = $input->getArgument('ids');
        assert(is_string($ids));
        $force = (bool) $input->getOption('force');
        $output->writeln($force ? 'DANGER: Using force mode! Backend will be removed.' : 'DRY RUN');

        $client = $this->createClient($apiUrl, $apiToken);

        $allBackends = $client->listStorageBackend();
        $allBackendsAssociative = [];
        foreach ($allBackends as $backend) {
            $allBackendsAssociative[$backend['id']] = $backend;
        }
        $backendIds = array_filter(array_map('trim', explode(',', $ids)), 'is_numeric');
        foreach ($backendIds as $id) {
            if (!array_key_exists($id, $allBackendsAssociative)) {
                $output->writeln(sprintf('Backend with ID "%s" does not exist, skipping...', $id));
                continue;
            }
            $output->write(sprintf(
                'Removing backend "%s" (%s) by "%s" - ',
                $id,
                $allBackendsAssociative[$id]['host'],
                $allBackendsAssociative[$id]['owner']
            ));
            if ($force) {
                try {
                    $client->removeStorageBackend((int) $id);
                    $output->writeln(sprintf(' - Backend "%s" succesfully removed.', $id));

                } catch (ClientException $e) {
                    $output->writeln(sprintf(' - Error while removing backend "%s": %s', $id, $e->getMessage()));
                }
            } else {
                $output->writeln('just kidding - dry mode');
            }
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
}
