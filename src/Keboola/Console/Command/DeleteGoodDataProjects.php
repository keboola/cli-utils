<?php
namespace Keboola\Console\Command;

use Console_CommandLine_Exception;
use Exception;
use Keboola\Csv\CsvFile;
use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteGoodDataProjects extends Command
{

    const ARG_SOURCE_FILE = 'file';
    const OPTION_FORCE = 'force';

    protected function configure()
    {
        $this
            ->setName('gooddata:delete-projects')
            ->setDescription('Delete GoodData project in bulk')
            ->addArgument(self::ARG_SOURCE_FILE, InputArgument::OPTIONAL, 'source CSV file', 'projects.csv')
            ->addOption(self::OPTION_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run')
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sourceFile = $input->getArgument(self::ARG_SOURCE_FILE);
        $output->writeln(sprintf('Fetching projects from "%s"', $sourceFile));
        $force = $input->getOption(self::OPTION_FORCE);
        $output->writeln($force ? 'FORCE MODE' : 'DRY RUN');

        if ($force) {
            sleep(1);
        }

        $requiredEnvs = [
            'GOODDATA_LOGIN',
            'GOODDATA_PASSWORD'
        ];
        $optionalEnvs = [
            'GOODDATA_URL'
        ];
        $missing = array_diff_key(array_flip($requiredEnvs), array_filter(getenv()));
        if (count($missing)) {
            throw new Exception(sprintf(
                'Missing env variable: %s',
                implode(array_keys($missing))
            ));
        }

        $config = array_intersect_key(
            array_filter(getenv()),
            array_flip(array_merge($requiredEnvs, $optionalEnvs))
        );

        if (!file_exists($sourceFile)) {
            throw new Exception(sprintf(
                'Source file does not exist: %s',
                $sourceFile
            ));
        }

        $projects = new CsvFile($sourceFile);
        $projectCount = count(iterator_to_array($projects));
        $output->writeln(sprintf(
            'Found "%s" projects',
            $projectCount
        ));

        if ($projectCount) {
            $client = new \Keboola\GoodData\Client($config['GOODDATA_URL'] ?? null);
            $output->writeln(sprintf(
                'Logging in as "%s" to "%s"',
                $config['GOODDATA_LOGIN'],
                $config['GOODDATA_URL'] ?? \Keboola\GoodData\Client::API_URL
            ));
            $client->login($config['GOODDATA_LOGIN'], $config['GOODDATA_PASSWORD']);

            $progress = new ProgressBar($output);
            $progress->start($projectCount);

            foreach ($projects as $row) {
                $pid = $row[0];
                try {
                    if ($force) {
                        $client->getProjects()->deleteProject($pid);
                    } else {
                        $project = $client->getProjects()->getProject($pid);
                    }
                    $progress->advance();
                } catch (\Exception $e) {
                    $output->writeln(sprintf(
                        PHP_EOL . 'Project "%s" failed: %s',
                        $pid,
                        $e->getMessage()
                    ));
                }
            }
            $progress->finish();
        }

        $output->writeln('Done');
    }
}
