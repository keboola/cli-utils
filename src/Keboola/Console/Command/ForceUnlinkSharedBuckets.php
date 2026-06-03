<?php

namespace Keboola\Console\Command;

use Keboola\StorageApi\Client as StorageApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ForceUnlinkSharedBuckets extends Command
{
    protected function configure()
    {
        $this
            ->setName('storage:force-unlink-shared-buckets')
            ->setDescription('List all buckets in the project and force-unlink those that are shared BY this project and linked TO other projects.')
            ->addArgument('storageToken', InputArgument::REQUIRED, 'Keboola Storage API token to use')
            ->addArgument('url', InputArgument::REQUIRED, 'stack URL. Including https://')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to actually unlink. Otherwise, dry-run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $input->getArgument('storageToken');
        assert(is_string($token));
        $url = $input->getArgument('url');
        assert(is_string($url));
        $isForce = $input->getOption('force');
        $prefix = $isForce ? 'FORCE: ' : 'DRY-RUN: ';

        $client = new StorageApiClient([
            'token' => $token,
            'url' => $url,
        ]);

        $buckets = $client->listBuckets(['include' => 'linkedBuckets']);

        foreach ($buckets as $bucket) {
            if (array_key_exists('linkedBy', $bucket)) {
                foreach ($bucket['linkedBy'] as $link) {
                    if ($isForce) {
                        $client->forceUnlinkBucket($bucket['id'], $link['project']['id']);
                    }
                    $output->writeln(
                        sprintf(
                            '%s bucket "%s" force unlinked from project "%s" (%s)',
                            $prefix,
                            $bucket['id'],
                            $link['project']['name'],
                            $link['project']['id'],
                        )
                    );
                }
            } else {
                $output->writeln(sprintf('No linked buckets found for bucket "%s"', $bucket['id']));
            }
        }

        return 0;
    }
}
