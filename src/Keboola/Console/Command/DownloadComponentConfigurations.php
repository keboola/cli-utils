<?php

namespace Keboola\Console\Command;

use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DownloadComponentConfigurations extends Command
{
    const ARG_TOKEN = 'token';
    const ARG_URL = 'url';
    const ARG_COMPONENT_ID = 'component-id';
    const OPT_DIR = 'dir';
    const OPT_INCLUDE_DELETED = 'include-deleted';

    protected function configure(): void
    {
        $this
            ->setName('storage:download-configurations')
            ->setDescription('Download all configurations of a component in a project as JSON files')
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'Storage API token')
            ->addArgument(self::ARG_URL, InputArgument::REQUIRED, 'Stack URL, e.g. https://connection.keboola.com')
            ->addArgument(self::ARG_COMPONENT_ID, InputArgument::REQUIRED, 'Component ID, e.g. keboola.ex-db-mysql')
            ->addOption(
                self::OPT_DIR,
                'd',
                InputOption::VALUE_REQUIRED,
                'Output directory (default: ./configurations/<componentId>)'
            )
            ->addOption(
                self::OPT_INCLUDE_DELETED,
                null,
                InputOption::VALUE_NONE,
                'Also download deleted configurations (into a "deleted" subdirectory)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $input->getArgument(self::ARG_TOKEN);
        assert(is_string($token));
        $url = $input->getArgument(self::ARG_URL);
        assert(is_string($url));
        $componentId = $input->getArgument(self::ARG_COMPONENT_ID);
        assert(is_string($componentId));

        $dirOption = $input->getOption(self::OPT_DIR);
        assert($dirOption === null || is_string($dirOption));
        $targetDir = $dirOption ?? getcwd() . '/configurations/' . $componentId;

        $components = new Components(new StorageClient([
            'url' => $url,
            'token' => $token,
        ]));

        $total = $this->downloadConfigurations($components, $componentId, false, $targetDir, $output);
        if ($input->getOption(self::OPT_INCLUDE_DELETED)) {
            $total += $this->downloadConfigurations(
                $components,
                $componentId,
                true,
                $targetDir . '/deleted',
                $output
            );
        }

        $output->writeln(sprintf('Done, %d configuration(s) saved to %s', $total, $targetDir));
        return 0;
    }

    private function downloadConfigurations(
        Components $components,
        string $componentId,
        bool $isDeleted,
        string $targetDir,
        OutputInterface $output
    ): int {
        $configurations = $components->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId($componentId)
                ->setIsDeleted($isDeleted)
        );

        if (count($configurations) === 0) {
            $output->writeln(sprintf(
                'No %sconfigurations of "%s" found in the project',
                $isDeleted ? 'deleted ' : '',
                $componentId
            ));
            return 0;
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true)) {
            throw new \RuntimeException('Cannot create output directory ' . $targetDir);
        }

        foreach ($configurations as $configuration) {
            $configurationId = (string) $configuration['id'];
            // list endpoint does not return rows/state, fetch the full detail
            $detail = $components->getConfiguration($componentId, $configurationId);
            $filename = $targetDir . '/' . $this->safeFilename($configurationId) . '.json';
            file_put_contents(
                $filename,
                json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );
            $output->writeln(sprintf(
                'Saved %s (%s) -> %s',
                $configurationId,
                is_string($detail['name'] ?? null) ? $detail['name'] : '',
                $filename
            ));
        }

        return count($configurations);
    }

    private function safeFilename(string $value): string
    {
        $safe = preg_replace('~[^A-Za-z0-9._-]+~', '_', $value);
        assert(is_string($safe));
        return $safe;
    }
}
