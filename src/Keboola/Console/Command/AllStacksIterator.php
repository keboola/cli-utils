<?php

namespace Keboola\Console\Command;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class AllStacksIterator extends Command
{
    public const ARG_COMMAND = 'command-to-run';
    public const ARG_PARAMS = 'params';

    protected function configure(): void
    {
        $this
            ->setName('manage:call-on-stacks')
            ->addArgument(self::ARG_COMMAND, InputArgument::REQUIRED, 'command')
            ->addArgument(self::ARG_PARAMS, InputArgument::REQUIRED, 'params');
    }

    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $commandName = $input->getArgument(self::ARG_COMMAND);
        assert(is_string($commandName));
        $application = $this->getApplication();
        if ($application === null) {
            throw new Exception('Application not found');
        }
        $command = $application->find($commandName);
        $cmndInput = $input->getArgument(self::ARG_PARAMS);
        assert(is_string($cmndInput));

        $stacksFile = file_get_contents('http-client.env.json');
        $stacksTokensFile = file_get_contents('http-client.private.env.json');

        if (!$stacksFile || !$stacksTokensFile) {
            throw new Exception('Input http-client files are not available');
        }

        $stacks = json_decode($stacksFile, true);
        $tokens = json_decode($stacksTokensFile, true);

        if (!is_array($stacks) || !is_array($tokens)) {
            throw new Exception('Invalid JSON format in http-client files');
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        // iterates over all stacks
        foreach ($stacks as $stackName => $stack) {
            if (!is_array($stack) || !isset($stack['host'])) {
                $output->writeln(sprintf('Invalid stack configuration for %s. Skipping', (string) $stackName));
                continue;
            }

            if (!isset($tokens[$stackName]) || $tokens[$stackName] === '' || !is_array($tokens[$stackName])) {
                $output->writeln(sprintf('Token for %s not found or it is empty. Skipping', (string) $stackName));
                continue;
            }

            $token = $tokens[$stackName];

            if (!isset($token['manageToken'])) {
                $output->writeln(sprintf('Manage token for %s not found. Skipping', (string) $stackName));
                continue;
            }

            // build input for the target command. It adds token and host from http-client files.
            $manageToken = $token['manageToken'];
            $stackHost = $stack['host'];
            assert(is_string($manageToken));
            assert(is_string($stackHost));
            $inputForThisStack = sprintf('%s %s %s %s', $commandName, $manageToken, $stackHost, $cmndInput);
            $cmdInput = new StringInput($inputForThisStack);

            // confirm from the user
            $question = new ConfirmationQuestion(
                sprintf('Run this command for stack %s? [y/N] ', $stackName),
                false,
                '/^(y)/i'
            );
            $answer = $helper->ask($input, $output, $question);
            $output->writeln('');

            if ($answer) {
                $output->writeln(sprintf('> Calling command on %s', $stackName));
                $output->writeln(sprintf('> %s', $inputForThisStack));
                // running the command
                $returnCode = $command->run($cmdInput, $output);
                $output->writeln(sprintf('> Command finished with %d return code', $returnCode));
            } else {
                $output->writeln(sprintf('> Skipping %s', $stackName));
            }
            $output->writeln('');
        }
        return 0;
    }
}
