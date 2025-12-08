<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NotifyProjects extends Command
{

    protected function configure(): void
    {
        $this
            ->setName('storage:notify-projects')
            ->setDescription('Send notification to projects.')
            ->addArgument('token', InputArgument::REQUIRED, 'manage api token')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'API URL', 'https://connection.keboola.com')
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $fh = fopen('php://stdin', 'r');
        if (!$fh) {
            throw new \Exception('Error on input read');
        }

        $client = new Client([
            'token' => $input->getArgument('token'),
            'url' => $input->getOption('url'),
        ]);

        $lineNumber = 0;
        while ($row = fgetcsv($fh)) {
            if ($lineNumber === 0) {
                $this->validateHeader($row);
            } else {
                $this->notifyProject(
                    $client,
                    $output,
                    (int) $row[0],
                    (string) $row[1],
                    (string) $row[2]
                );
            }
            $lineNumber++;
        }

        return 0;
    }

    /**
     * @param array<int, string|null> $header
     */
    private function validateHeader(array $header): void
    {
        $expectedHeader = ['projectId', 'notificationTitle', 'notificationMessage'];
        if ($header !== $expectedHeader) {
            throw new \Exception(sprintf(
                'Invalid input header: %s Expected header: %s',
                implode(',', $header),
                implode(',', $expectedHeader)
            ));
        }
    }

    private function notifyProject(
        Client $client,
        OutputInterface $output,
        int $projectId,
        string $notificationTitle,
        string $notificationMessage
    ): void
    {
        $output->writeln("Sending notification to project $projectId");

        // @phpstan-ignore-next-line method.notFound
        $client->addNotification([
            'type' => 'common',
            'projectId' => (int) $projectId,
            'title' => $notificationTitle,
            'message' => $notificationMessage,
        ]);
    }
}
