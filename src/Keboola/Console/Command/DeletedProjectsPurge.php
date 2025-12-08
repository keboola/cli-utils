<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeletedProjectsPurge extends Command
{

    protected function configure(): void
    {
        $this
            ->setName('storage:deleted-projects-purge')
            ->setDescription('Purge deleted projects.')
            ->addArgument('token', InputArgument::REQUIRED, 'manage api token')
            ->addOption('ignore-backend-errors', null, InputOption::VALUE_NONE, "Ignore errors from backend and just delete buckets and workspaces metadata")
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $input->getArgument('token');
        assert(is_string($token));

        $fh = fopen('php://stdin', 'r');
        if (!$fh) {
            throw new \Exception('Error on input read');
        }

        $ignoreBackendErrors = (bool) $input->getOption('ignore-backend-errors');

        $output->writeln(sprintf(
            'Ignore backend errors %s',
            $ignoreBackendErrors ? 'On' : 'Off'
        ));

        $client = new Client([
            'token' => $token,
        ]);

        $lineNumber = 0;
        while ($row = fgetcsv($fh)) {
            if ($lineNumber === 0) {
                $this->validateHeader($row);
            } else {
                $this->purgeProject(
                    $client,
                    $output,
                    $ignoreBackendErrors,
                    (int) $row[0],
                    (string) $row[1]
                );
            }
            $lineNumber++;
        }

        return 0;
    }

    private function validateHeader($header): void
    {
        $expectedHeader = ['id', 'name'];
        if ($header !== $expectedHeader) {
            throw new \Exception(sprintf(
                'Invalid input header: %s Expected header: %s',
                implode(',', $header),
                implode(',', $expectedHeader)
            ));
        }
    }

    private function purgeProject(
        Client $client,
        OutputInterface $output,
        bool $ignoreBackendErrors,
        int $projectId,
        string $projectName
    ): void {
        $output->writeln(sprintf('Purge %s (%d)', $projectName, $projectId));

        $response = $client->purgeDeletedProject($projectId, [
            'ignoreBackendErrors' => (bool) $ignoreBackendErrors,
        ]);
        $output->writeln(" - execution id {$response['commandExecutionId']}");

        $startTime = time();
        $maxWaitTimeSeconds = 600;
        do {
            $deletedProject = $client->getDeletedProject($projectId);
            if (time() - $startTime > $maxWaitTimeSeconds) {
                throw new \Exception("Project {$projectId} purge timeout.");
            }
            sleep(1);
        } while ($deletedProject['isPurged'] !== true);

        $output->writeln(sprintf('Purge done %s (%d)', $projectName, $projectId));
    }
}
