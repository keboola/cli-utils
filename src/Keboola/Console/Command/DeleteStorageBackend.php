<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteStorageBackend extends Command
{
    protected function configure()
    {
        $this
            ->setName('manage:delete-backend')
            ->setDescription('Set keboola.touch attribute to all tables. This will invalidate async export caches.')
            ->addArgument('token', InputArgument::REQUIRED, 'storage api token')
            ->addArgument('ids', InputArgument::REQUIRED, 'list of IDs separated')
            ->addArgument('url', InputArgument::REQUIRED, 'Stack URL')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $apiToken = $input->getArgument('token');
        $apiUrl = $input->getArgument('url');
        $ids = $input->getArgument('ids');
        $force = (bool) $input->getOption('force');
        $output->writeln('DANGER: Using force mode! Backend will be removed.');

        $client = $this->createClient($apiUrl, $apiToken);

        $allBackends = $client->listStorageBackend();
        $allBackendsAssociative = [];
        foreach ($allBackends as $backend) {
            $allBackendsAssociative[$backend['id']] = $backend;
        }
        foreach (explode(',', $ids) as $id) {
            // get backend detail and print it

            $output->write(sprintf(
                'Removing backend "%s" (%s) by "%s" - ',
                $id,
                $allBackendsAssociative[$id]['host'],
                $allBackendsAssociative[$id]['owner']
            ));
            if($force){
                $output->writeln('really');
                $client->removeStorageBackend($id);
            } else {
                $output->writeln('just kidding - dry mode');
            }

        }
    }


    private function createClient(string $host, string $token): Client
    {
        return new Client([
            'url' => $host,
            'token' => $token,
        ]);
    }
}
