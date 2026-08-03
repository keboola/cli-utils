<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client as ManageClient;
use Keboola\ServiceClient\ServiceClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\Tokens;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListExternalBuckets extends Command
{
    private const ARGUMENT_MANAGE_TOKEN = 'manage-token';
    private const ARGUMENT_HOSTNAME_SUFFIX = 'hostname-suffix';

    protected function configure(): void
    {
        $this
            ->setName('manage:list-external-buckets')
            ->setDescription(
                'Read-only: list all external buckets (hasExternalSchema) across all projects of a stack '
                . 'with created date, isReadOnly flag and whether KBC.description metadata is set.'
            )
            ->addArgument(
                self::ARGUMENT_MANAGE_TOKEN,
                InputArgument::REQUIRED,
                'Manage API token (super admin) used to create short-lived project storage tokens.'
            )
            ->addArgument(
                self::ARGUMENT_HOSTNAME_SUFFIX,
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manageToken = $input->getArgument(self::ARGUMENT_MANAGE_TOKEN);
        assert(is_string($manageToken));
        $hostnameSuffix = $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX);
        assert(is_string($hostnameSuffix));
        assert($hostnameSuffix !== '');

        $serviceClient = new ServiceClient($hostnameSuffix);
        $connectionUrl = $serviceClient->getConnectionServiceUrl();
        $manageClient = new ManageClient(['token' => $manageToken, 'url' => $connectionUrl]);

        $output->writeln('projectId,projectName,bucketId,created,isReadOnly,linked,hasDescription,description');

        $externalBucketsFound = 0;
        $projectsChecked = 0;
        $projectsSkipped = 0;
        $seenProjects = [];

        foreach ($manageClient->listMaintainers() as $maintainer) {
            foreach ($manageClient->listMaintainerOrganizations($maintainer['id']) as $organization) {
                foreach ($manageClient->listOrganizationProjects($organization['id']) as $project) {
                    $projectId = (int) $project['id'];
                    if (isset($seenProjects[$projectId])) {
                        continue;
                    }
                    $seenProjects[$projectId] = true;

                    try {
                        $storageToken = $manageClient->createProjectStorageToken(
                            $projectId,
                            [
                                'description' => 'List external buckets (read-only audit)',
                                'expiresIn' => 900,
                                'canManageBuckets' => true,
                            ]
                        );
                    } catch (\Throwable $e) {
                        $output->writeln(sprintf(
                            '<error># Access denied or error for project "%s" ("%s"): %s</error>',
                            $projectId,
                            $project['name'],
                            $e->getMessage(),
                        ));
                        $projectsSkipped++;
                        continue;
                    }
                    assert(is_string($storageToken['token']));
                    $projectsChecked++;

                    $storageClient = new StorageApiClient([
                        'token' => $storageToken['token'],
                        'url' => $connectionUrl,
                    ]);

                    try {
                        $buckets = $storageClient->listBuckets(['include' => 'metadata']);
                        foreach ($buckets as $bucket) {
                            if (($bucket['hasExternalSchema'] ?? false) !== true) {
                                continue;
                            }
                            $externalBucketsFound++;

                            $description = null;
                            foreach ($bucket['metadata'] ?? [] as $metadata) {
                                if ($metadata['key'] === 'KBC.description') {
                                    $description = (string) $metadata['value'];
                                    break;
                                }
                            }

                            $output->writeln(sprintf(
                                '%d,"%s",%s,%s,%s,%s,%s,"%s"',
                                $projectId,
                                str_replace('"', '""', (string) $project['name']),
                                $bucket['id'],
                                $bucket['created'],
                                ($bucket['isReadOnly'] ?? false) ? 'READ_ONLY' : 'writable',
                                isset($bucket['sourceBucket']) ? 'linked' : 'own',
                                $description !== null ? 'yes' : 'no',
                                str_replace('"', '""', (string) ($description ?? '')),
                            ));
                        }
                    } finally {
                        $tokensClient = new Tokens($storageClient);
                        assert(is_scalar($storageToken['id']));
                        $tokensClient->dropToken((int) $storageToken['id']);
                    }
                }
            }
        }

        $output->writeln(sprintf(
            '# Done: %d external buckets in %d projects checked (%d projects skipped).',
            $externalBucketsFound,
            $projectsChecked,
            $projectsSkipped,
        ));

        return 0;
    }
}
