<?php

namespace Keboola\Console\Command;

use Keboola\Csv\CsvFile;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AddFeatureFromCSV extends AddFeature
{
    public const ARG_CSV_FILE = 'csvFile';

    protected function configure(): void
    {
        $this
            ->setName('manage:add-feature-to-templates:from-csv')
            ->setDescription('Adds a feature to all project templates in stacks defined by CSV in form <stack>,<MAPI token> (with no header). Bulk operation.')
            ->addOption(self::OPT_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run')
            ->addArgument(self::ARG_CSV_FILE, InputArgument::REQUIRED, 'path to CSV file with stacks/tokens')
            ->addArgument(self::ARG_FEATURE_NAME, InputArgument::REQUIRED, 'feature name')
            ->addArgument(self::ARG_FEATURE_DESC, InputArgument::OPTIONAL, 'feature description', '');
    }

    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $args = $input->getArguments();
        $force = $input->getOption(self::OPT_FORCE);
        $csvFile = new CsvFile($args[self::ARG_CSV_FILE]);

        $csvFile->rewind();

        while ($csvFile->valid()) {
            $row = $csvFile->current();

            $host = $row[0];

            if (count($row) < 2) {
                $output->writeln("Error: invalid form of CSV.");
                return 1;
            }

            $output->writeln(sprintf('>>> Starting %s <<<', $host));

            $client = $this->createClient($host, $row[1]);
            $this->createFeature($client, $args[self::ARG_FEATURE_NAME], $args[self::ARG_FEATURE_DESC], $force, $output);
            $this->addFeature($client, $args[self::ARG_FEATURE_NAME], $force, $output);

            $output->writeln(sprintf(">>> Finished %s <<<\n", $host));

            $csvFile->next();
        }

        return 0;
    }
}
