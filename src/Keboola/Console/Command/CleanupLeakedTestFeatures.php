<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupLeakedTestFeatures extends Command
{
    private const LEAKED_NAME_PREFIXES = [
        'test-feature-',        // FeaturesTest, Assign*FeatureTest, ProjectTemplateFeatures*Test
        'manage-feature-test-', // UsersTest
        'random-feature-',      // UsersTest, ProjectsTest
        'new-feature-',         // UsersTest
        'first-feature-',       // ProjectsTest
        'second-feature-',      // ProjectsTest
    ];

    private const LIST_SAMPLE_SIZE = 30;
    private const PROGRESS_EVERY = 100;

    private const PRODUCTION_HOSTS = [
        'connection.keboola.com',
        'connection.eu-central-1.keboola.com',
    ];

    protected function configure(): void
    {
        $this
            ->setName('manage:cleanup-leaked-test-features')
            ->setDescription('Delete features leaked by connection E2E tests (matched by test name prefixes)')
            ->addArgument('token', InputArgument::REQUIRED, 'manage token (super-admin)')
            ->addArgument('url', InputArgument::REQUIRED, 'Stack URL')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apiToken = $input->getArgument('token');
        assert(is_string($apiToken));
        $apiUrl = $input->getArgument('url');
        assert(is_string($apiUrl));
        $force = (bool) $input->getOption('force');

        if (in_array(parse_url($apiUrl, PHP_URL_HOST), self::PRODUCTION_HOSTS, true)) {
            $output->writeln(sprintf('<error>Refusing to run against production host %s.</error>', $apiUrl));
            return 1;
        }

        $client = new Client([
            'url' => $apiUrl,
            'token' => $apiToken,
        ]);

        $matched = [];
        foreach (['admin', 'project', 'global'] as $type) {
            $output->write(sprintf('Listing "%s" features... ', $type));
            $features = $client->listFeatures(['type' => $type]);
            $matchedForType = 0;
            foreach ($features as $feature) {
                foreach (self::LEAKED_NAME_PREFIXES as $prefix) {
                    if (strpos((string) $feature['name'], $prefix) === 0) {
                        $matched[] = $feature;
                        $matchedForType++;
                        break;
                    }
                }
            }
            $output->writeln(sprintf('%d total, %d matched', count($features), $matchedForType));
        }

        if ($matched === []) {
            $output->writeln('No leaked test features found.');
            return 0;
        }

        $output->writeln(sprintf('Matched %d leaked test features on %s:', count($matched), $apiUrl));
        $shown = $output->isVerbose() ? $matched : array_slice($matched, 0, self::LIST_SAMPLE_SIZE);
        foreach ($shown as $feature) {
            $output->writeln(sprintf('  [%s] #%d %s', $feature['type'], $feature['id'], $feature['name']));
        }
        if (count($matched) > count($shown)) {
            $output->writeln(sprintf('  ... and %d more (use -v to list all)', count($matched) - count($shown)));
        }

        if (!$force) {
            $output->writeln('');
            $output->writeln('Command was run in <comment>dry-run</comment> mode. To actually apply changes run it with --force flag.');
            return 0;
        }

        $deleted = 0;
        $failed = 0;
        foreach ($matched as $i => $feature) {
            try {
                $output->writeln('Dropping feature');

                $client->removeFeature($feature['id']);
                $deleted++;
            } catch (ClientException $e) {
                $failed++;
                $output->writeln(sprintf(
                    '  <error>FAILED to delete #%d %s: %s</error>',
                    $feature['id'],
                    $feature['name'],
                    $e->getMessage()
                ));
            }
            if (($i + 1) % self::PROGRESS_EVERY === 0) {
                $output->writeln(sprintf('  %d/%d processed (%d deleted, %d failed)', $i + 1, count($matched), $deleted, $failed));
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('Deleted %d features, %d failed.', $deleted, $failed));
        return $failed > 0 ? 1 : 0;
    }
}
