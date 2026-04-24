<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use InvalidArgumentException;
use Keboola\Csv\CsvFile;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class MassDeleteProjectWorkspaces extends Command
{
    private const ARGUMENT_STACK_SUFFIX = 'stack-suffix';
    private const ARGUMENT_SOURCE_FILE = 'source-file';
    private const OPTION_FORCE = 'force';

    protected function configure(): void
    {
        $this
            ->setName('manage:mass-delete-project-workspaces')
            ->setDescription('Delete all project workspaces based on given list in file. [Works only for SNFLK now].')
            ->addArgument(self::ARGUMENT_STACK_SUFFIX, InputArgument::REQUIRED, 'stack suffix "keboola.com, eu-central-1.keboola.com"')
            ->addArgument(self::ARGUMENT_SOURCE_FILE, InputArgument::REQUIRED, 'Source csv with "project id,workspace schema" columns and no header.')
            ->addOption(self::OPTION_FORCE, 'f', InputOption::VALUE_NONE, 'Write changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stackSuffix = $input->getArgument(self::ARGUMENT_STACK_SUFFIX);
        assert(is_string($stackSuffix));
        $connectionUrl = 'https://connection.' . $stackSuffix;
        $editorUrl = 'https://editor.' . $stackSuffix;
        $sourceFile = $input->getArgument(self::ARGUMENT_SOURCE_FILE);
        assert(is_string($sourceFile));
        $output->writeln(sprintf('Fetching projects from "%s"', $sourceFile));
        $force = (bool) $input->getOption(self::OPTION_FORCE);

        // map by project id
        /**
         * @var array<string, array<int, string>> $map
         */
        $map = [];
        $csv = new CsvFile($sourceFile);
        foreach ($csv as $line) {
            assert(is_array($line));
            if (count($line) !== 2) {
                throw new InvalidArgumentException('File must contain exactly two columns.');
            }
            $projectId = $line[0];
            $workspaceSchema = $line[1];
            assert(is_string($projectId) || is_numeric($projectId));
            assert(is_string($workspaceSchema));
            if (!is_numeric($projectId)) {
                throw new InvalidArgumentException(sprintf('Project id "%s" is not numeric.', $projectId));
            }
            if (!str_starts_with($workspaceSchema, 'WORKSPACE_')) {
                throw new InvalidArgumentException(sprintf('Workspace "%s" does not start with "WORKSPACE_".', $workspaceSchema));
            }

            $projectIdStr = (string) $projectId;
            if (array_key_exists($projectIdStr, $map)) {
                $map[$projectIdStr][] = $workspaceSchema;
            } else {
                $map[$projectIdStr] = [$workspaceSchema];
            }
        }

        foreach ($map as $projectId => $workspaceSchemasToDelete) {
            /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new Question(sprintf(
                'Paste storage token for project "%s" to continue.' . PHP_EOL,
                $projectId,
            ));
            $storageToken = $helper->ask($input, $output, $question);
            assert(is_string($storageToken));

            $editorClient = new EditorServiceClient($editorUrl, $storageToken);

            // index sessions by workspaceSchema for quick lookup
            $sessionsBySchema = [];
            foreach ($editorClient->listSessions() as $session) {
                $sessionsBySchema[$session['workspaceSchema']] = $session;
            }

            $notFound = [];
            foreach ($workspaceSchemasToDelete as $schema) {
                if (!isset($sessionsBySchema[$schema])) {
                    $notFound[] = $schema;
                    continue;
                }

                $session = $sessionsBySchema[$schema];

                $output->writeln(sprintf(
                    'Session "%s" with schema "%s" found — configuration %s/%s (branch %s).',
                    $session['id'],
                    $schema,
                    $session['componentId'],
                    $session['configurationId'],
                    $session['branchId'],
                ));

                if ($force) {
                    $branchClient = new BranchAwareClient($session['branchId'], [
                        'token' => $storageToken,
                        'url' => $connectionUrl,
                    ]);
                    $components = new Components($branchClient);
                    // First call moves the configuration to trash, second call permanently purges it.
                    $components->deleteConfiguration($session['componentId'], $session['configurationId']);
                    $components->deleteConfiguration($session['componentId'], $session['configurationId']);

                    $output->writeln(sprintf(
                        'Deleted configuration %s/%s for schema "%s".',
                        $session['componentId'],
                        $session['configurationId'],
                        $schema,
                    ));
                } else {
                    $output->writeln(sprintf(
                        '[DRY-RUN] Would delete configuration %s/%s for schema "%s".',
                        $session['componentId'],
                        $session['configurationId'],
                        $schema,
                    ));
                }
            }

            if (count($notFound) !== 0) {
                $output->writeln([
                    '<error>Following schemas were not found (are deleted or need to be deleted manually):</error>',
                    implode(', ', $notFound),
                ]);
            }
        }

        return 0;
    }
}
