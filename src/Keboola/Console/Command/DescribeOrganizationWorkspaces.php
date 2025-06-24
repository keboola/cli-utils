<?php
namespace Keboola\Console\Command;

use Keboola\Csv\CsvFile;
use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Tokens;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class DescribeOrganizationWorkspaces extends Command
{
    protected function configure()
    {
        $this
            ->setName('manage:describe-organization-workspaces')
            ->setDescription('Describe workspaces of this organization.')
            ->addArgument(
                'manageToken',
                InputArgument::REQUIRED,
                'Keboola Storage API token to use'
            )
            ->addArgument(
                'organizationId',
                InputArgument::REQUIRED,
                'ID of the organization to clean'
            )
            ->addArgument(
                'outputFile',
                InputArgument::REQUIRED,
                'file to output the csv results'
            )
            ->addArgument(
                'hostnameSuffix',
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $manageToken = $input->getArgument('manageToken');
        $organizationId = $input->getArgument('organizationId');
        $outputFile = $input->getArgument('outputFile');
        $kbcUrl = sprintf('https://connection.%s', $input->getArgument('hostnameSuffix'));
        $manageClient = new Client(['token' => $manageToken, 'url' => $kbcUrl]);
        $organization = $manageClient->getOrganization($organizationId);
        $projects = $organization['projects'];
        $output->writeln(
            sprintf(
                'Checking workspaces for "%d" projects',
                count($projects)
            )
        );

        $storageUrl = 'https://connection.' . $input->getArgument('hostnameSuffix');

        $totalWorkspaces = 0;

        $csvFile = new CsvFile($outputFile);
        $csvFile->writeRow([
            'projectId',
            'projectName',
            'branchId',
            'branchName',
            'componentId',
            'configurationId',
            'creatorEmail',
            'activeUser',
            'createdDate',
            'snowflakeSchema',
            'readOnlyStorageAccess'
        ]);

        foreach ($projects as $project) {
            $projectUsers = $manageClient->listProjectUsers($project['id']);
            try {
                $storageToken = $manageClient->createProjectStorageToken(
                    $project['id'],
                    [
                        'description' => 'Fetching Workspace Details',
                        'expiresIn' => 1800,
                    ]
                );
            } catch (\Throwable $e) {
                if ($e->getCode() === 403) {
                    $output->writeln(sprintf("WARN: Access denied to project: %s", $project['id']));
                    continue;
                }
            }

            $storageClient = new StorageApiClient([
                'token' => $storageToken['token'],
                'url' => $storageUrl,
                'logger' => new ConsoleLogger($output),
            ]);
            $devBranches = new DevBranches($storageClient);
            $branchesList = $devBranches->listBranches();
            $output->writeln(
                sprintf(
                    'Retrieving workspaces for project %s : %s ',
                    $project['id'],
                    $project['name']
                )
            );
            $totalProjectWorkspaces = 0;
            foreach ($branchesList as $branch) {
                $branchId = $branch['id'];
                $branchStorageClient = new BranchAwareClient($branchId, [
                    'token' => $storageToken['token'],
                    'url' => $storageUrl,
                ]);
                $workspacesClient = new Workspaces($branchStorageClient);
                $workspaceList = $workspacesClient->listWorkspaces();
                $output->writeln('Found ' . count($workspaceList) . ' workspaces in branch ' . $branch['name']);
                foreach ($workspaceList as $workspace) {
                    $userInProject = count(array_filter($projectUsers, function ($user) use ($workspace) {
                        return $user['email'] === $workspace['creatorToken']['description'];
                    }));
                    $row = [
                        $project['id'],
                        $project['name'],
                        $branch['id'],
                        $branch['name'],
                        $workspace['component'],
                        $workspace['configurationId'],
                        $workspace['creatorToken']['description'],
                        $userInProject > 0 ? 'true' : 'false',
                        $workspace['created'],
                        $workspace['name'],
                        $workspace['readOnlyStorageAccess']
                    ];
                    $csvFile->writeRow($row);
                    $totalProjectWorkspaces ++;
                }
            }
            $output->writeln(
                sprintf(
                    'Project %s has a total of %d workspaces.',
                    $project['id'],
                    $totalProjectWorkspaces
                )
            );
            $tokensClient = new Tokens($storageClient);
            $tokensClient->dropToken($storageToken['id']);

            $totalWorkspaces += $totalProjectWorkspaces;
        }
        $output->writeln(
            sprintf(
                'A grand total of %d workspaces in this organisation',
                $totalWorkspaces
            )
        );
    }
}
