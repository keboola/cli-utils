<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use InvalidArgumentException;
use Keboola\Csv\CsvFile;
use Keboola\ManageApi\Client as ManageClient;
use Keboola\ServiceClient\ServiceClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\ClientException as StorageClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Tokens;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DownloadProjectConfigurations extends Command
{
    private const ARGUMENT_MANAGE_TOKEN = 'manage-token';
    private const ARGUMENT_SOURCE_FILE = 'source-file';
    private const ARGUMENT_OUTPUT_DIR = 'output-dir';
    private const ARGUMENT_HOSTNAME_SUFFIX = 'hostname-suffix';

    private const STATE_LIVE = 'live';
    private const STATE_IN_TRASH = 'in_trash';
    private const STATE_NOT_FOUND = 'not_found';

    protected function configure(): void
    {
        $this
            ->setName('manage:download-project-configurations')
            ->setDescription(
                'Read-only: download the full detail (incl. rows) of the listed configurations across projects '
                . 'into JSON files and report every "WORKSPACE_<id>" string found in their content. '
                . 'Configurations sitting in trash are fetched from the deleted listing. Default branch only.'
            )
            ->addArgument(
                self::ARGUMENT_MANAGE_TOKEN,
                InputArgument::REQUIRED,
                'Manage API token (super admin) used to create short-lived project storage tokens.'
            )
            ->addArgument(
                self::ARGUMENT_SOURCE_FILE,
                InputArgument::REQUIRED,
                'Source csv with "projectId,componentId,configurationId" or '
                . '"projectId,workspaceSchema,componentId,configurationId" columns and no header. '
                . 'Duplicate configurations are downloaded once.'
            )
            ->addArgument(
                self::ARGUMENT_OUTPUT_DIR,
                InputArgument::REQUIRED,
                'Directory to write "<projectId>/<componentId>/<configurationId>.json" files to.'
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
        $sourceFile = $input->getArgument(self::ARGUMENT_SOURCE_FILE);
        assert(is_string($sourceFile));
        $outputDir = $input->getArgument(self::ARGUMENT_OUTPUT_DIR);
        assert(is_string($outputDir));
        $hostnameSuffix = $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX);
        assert(is_string($hostnameSuffix));
        assert($hostnameSuffix !== '');

        $serviceClient = new ServiceClient($hostnameSuffix);
        $connectionUrl = $serviceClient->getConnectionServiceUrl();
        $manageClient = new ManageClient(['token' => $manageToken, 'url' => $connectionUrl]);

        /** @var array<string, array<string, array{componentId: string, configurationId: string}>> $map */
        $map = [];
        $totalRows = 0;
        $csv = new CsvFile($sourceFile);
        foreach ($csv as $line) {
            assert(is_array($line));
            if (count($line) !== 3 && count($line) !== 4) {
                throw new InvalidArgumentException(
                    'File must contain three or four columns '
                    . '(projectId,componentId,configurationId or projectId,workspaceSchema,componentId,configurationId).'
                );
            }
            $projectId = $line[0];
            $componentId = count($line) === 4 ? $line[2] : $line[1];
            $configurationId = count($line) === 4 ? $line[3] : $line[2];
            assert(is_string($projectId) || is_numeric($projectId));
            assert(is_string($componentId));
            assert(is_string($configurationId) || is_numeric($configurationId));
            if (!is_numeric($projectId)) {
                throw new InvalidArgumentException(sprintf('Project id "%s" is not numeric.', $projectId));
            }
            if ($componentId === '' || (string) $configurationId === '') {
                throw new InvalidArgumentException(sprintf(
                    'Row for project "%s" has an empty componentId or configurationId.',
                    $projectId
                ));
            }
            $key = $componentId . '/' . (string) $configurationId;
            $map[(string) $projectId][$key] = [
                'componentId' => $componentId,
                'configurationId' => (string) $configurationId,
            ];
            $totalRows++;
        }
        $uniqueCount = array_sum(array_map('count', $map));
        $output->writeln(sprintf(
            'Loaded %d rows, %d unique configurations in %d projects from "%s".',
            $totalRows,
            $uniqueCount,
            count($map),
            $sourceFile
        ));

        /** @var array<string, int> $stateCounts */
        $stateCounts = [];
        /** @var array<string, array<string>> $workspaceRefs config key => found WORKSPACE_ strings */
        $workspaceRefs = [];

        foreach ($map as $projectId => $configs) {
            $projectId = (string) $projectId;
            $output->writeln(sprintf('Project "%s" (%d configurations).', $projectId, count($configs)));
            try {
                $storageToken = $manageClient->createProjectStorageToken(
                    (int) $projectId,
                    [
                        'description' => 'Read-only configuration download',
                        'expiresIn' => 1800,
                        // reading component configurations (incl. trash listing) is not
                        // allowed for a minimal token
                        'canManageBuckets' => true,
                    ]
                );
            } catch (\Throwable $e) {
                if ($e->getCode() === 403) {
                    $output->writeln(sprintf('<error>Access denied to project "%s".</error>', $projectId));
                    $stateCounts['access_denied'] = ($stateCounts['access_denied'] ?? 0) + count($configs);
                    continue;
                }
                throw $e;
            }
            assert(is_string($storageToken['token']));

            $storageClient = new StorageApiClient([
                'token' => $storageToken['token'],
                'url' => $connectionUrl,
            ]);
            $components = new Components($storageClient);

            /** @var array<string, array<string, array<mixed>>> $trashedByComponent componentId => configId => detail */
            $trashedByComponent = [];

            foreach ($configs as $config) {
                $componentId = $config['componentId'];
                $configurationId = $config['configurationId'];
                $state = self::STATE_LIVE;
                $detail = null;
                try {
                    $detail = $components->getConfiguration($componentId, $configurationId);
                } catch (StorageClientException $e) {
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                    // not live in the default branch: look through the trash of the component
                    if (!isset($trashedByComponent[$componentId])) {
                        $trashedByComponent[$componentId] = [];
                        $trashed = $components->listComponentConfigurations(
                            (new ListComponentConfigurationsOptions())
                                ->setComponentId($componentId)
                                ->setIsDeleted(true)
                        );
                        assert(is_array($trashed));
                        foreach ($trashed as $trashedConfig) {
                            assert(is_array($trashedConfig));
                            assert(is_scalar($trashedConfig['id']));
                            $trashedByComponent[$componentId][(string) $trashedConfig['id']] = $trashedConfig;
                        }
                    }
                    if (isset($trashedByComponent[$componentId][$configurationId])) {
                        $state = self::STATE_IN_TRASH;
                        $detail = $trashedByComponent[$componentId][$configurationId];
                    } else {
                        $state = self::STATE_NOT_FOUND;
                    }
                }
                $stateCounts[$state] = ($stateCounts[$state] ?? 0) + 1;

                $label = sprintf('%s/%s (project %s)', $componentId, $configurationId, $projectId);
                if ($detail === null) {
                    $output->writeln(sprintf('  <error>%s: not found (neither live nor in trash)</error>', $label));
                    continue;
                }
                assert(is_array($detail));

                $targetDir = sprintf(
                    '%s/%s/%s',
                    rtrim($outputDir, '/'),
                    $projectId,
                    $this->safeFilename($componentId)
                );
                if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
                    throw new RuntimeException('Cannot create output directory ' . $targetDir);
                }
                $filename = sprintf(
                    '%s/%s%s.json',
                    $targetDir,
                    $this->safeFilename($configurationId),
                    $state === self::STATE_IN_TRASH ? '.deleted' : ''
                );
                $json = json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                assert(is_string($json));
                file_put_contents($filename, $json . "\n");

                preg_match_all('~[A-Z0-9_]*WORKSPACE_\d+~', $json, $matches);
                $found = array_values(array_unique($matches[0]));
                sort($found);
                $workspaceRefs[$projectId . ':' . $componentId . '/' . $configurationId] = $found;

                $output->writeln(sprintf(
                    '  %s: %s, name "%s", %d rows -> %s%s',
                    $label,
                    $state,
                    is_string($detail['name'] ?? null) ? $detail['name'] : '',
                    is_array($detail['rows'] ?? null) ? count($detail['rows']) : 0,
                    $filename,
                    count($found) > 0
                        ? sprintf(' <comment>[WORKSPACE refs: %s]</comment>', implode(', ', $found))
                        : ''
                ));
            }

            $tokensClient = new Tokens($storageClient);
            assert(is_scalar($storageToken['id']));
            $tokensClient->dropToken((int) $storageToken['id']);
        }

        $output->writeln('');
        $output->writeln(sprintf('Downloaded %d unique configurations to "%s":', $uniqueCount, $outputDir));
        ksort($stateCounts);
        foreach ($stateCounts as $state => $count) {
            $output->writeln(sprintf(' - %s: %d', $state, $count));
        }
        $withRefs = array_filter($workspaceRefs, fn(array $refs): bool => count($refs) > 0);
        $output->writeln(sprintf(
            'Configurations containing a "WORKSPACE_<id>" string anywhere in their content: %d',
            count($withRefs)
        ));
        foreach ($withRefs as $key => $refs) {
            $output->writeln(sprintf(' - %s: %s', $key, implode(', ', $refs)));
        }

        return 0;
    }

    private function safeFilename(string $value): string
    {
        $safe = preg_replace('~[^A-Za-z0-9._-]+~', '_', $value);
        assert(is_string($safe));
        return $safe;
    }
}
