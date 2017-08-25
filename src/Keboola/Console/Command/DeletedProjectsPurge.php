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

    protected function configure()
    {
        $this
            ->setName('storage:deleted-projects-purge')
            ->setDescription('Purge deleted projects.')
            ->addArgument('token', InputArgument::REQUIRED, 'manage api token')
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');

        $fh = fopen('php://stdin', 'r');
        if (!$fh) {
            throw new \Exception('Error on input read');
        }

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
                    $row[0],
                    $row[1]
                );
            }
            $lineNumber++;
        }
    }

    private function validateHeader($header)
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

    private function purgeProject(Client $client, OutputInterface $output, $projectId, $projectName)
    {
        $output->writeln(sprintf('Purge %s (%d)', $projectName, $projectId));

        $response = $client->purgeDeletedProject($projectId);
        $output->writeln(" - execution id {$response['commandExecutionId']}");

        $startTime = time();
        $maxWaitTimeSeconds = 600;
        do {
            $deletedProject = $client->getDeletedProject($projectId);
            if (time() - $startTime > $maxWaitTimeSeconds) {
                throw new \Exception("Project {$projectId} purge timeout.");
            }
            sleep(1);
        } while($deletedProject['isPurged'] !== true);

        $output->writeln(sprintf('Purge done %s (%d)', $projectName, $projectId));
    }
}
