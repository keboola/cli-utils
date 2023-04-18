<?php

namespace Keboola\Console\Command;

use Exception;
use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AddFeature extends Command
{
    public const OPT_FORCE = 'force';
    public const ARG_FEATURE_NAME = 'featureName';
    public const ARG_FEATURE_TITLE = 'featureTitle';
    public const ARG_FEATURE_DESC = 'featureDesc';
    public const ARG_TOKEN = 'token';
    public const ARG_URL = 'url';

    protected function configure(): void
    {
        $this
            ->setName('manage:add-feature-to-templates')
            ->setDescription('Adds a feature to all project templates')
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'manage api token')
            ->addArgument(self::ARG_URL, InputArgument::REQUIRED, 'API URL')
            ->addArgument(self::ARG_FEATURE_NAME, InputArgument::REQUIRED, 'feature name')
            ->addArgument(self::ARG_FEATURE_TITLE, InputArgument::REQUIRED, 'feature title')
            ->addArgument(self::ARG_FEATURE_DESC, InputArgument::OPTIONAL, 'feature description', '')
            ->addOption(self::OPT_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run');
    }

    protected function addFeature(Client $client, string $featureName, bool $force, OutputInterface $output): void
    {
        $templates = $client->getProjectTemplates();

        foreach ($templates as $templateId) {
            $projectFeatureExists = false;

            $featuresInThisTemplate = $client->getProjectTemplateFeatures($templateId['id']);
            foreach ($featuresInThisTemplate as $featureInTemplate) {
                if ($featureName === $featureInTemplate['name']) {
                    $projectFeatureExists = true;
                    break;
                }
            }

            if ($projectFeatureExists) {
                $output->writeln(sprintf('Feature "%s" already exists in template "%s". Skipping.', $featureName, $templateId['id']));
            } else {
                if ($force) {
                    $output->writeln(sprintf('Feature "%s" DOES NOT exist in template "%s" yet. Adding...', $featureName, $templateId['id']));
                    try {
                        $client->addProjectTemplateFeature($templateId['id'], $featureName);
                        $output->writeln('...Success');
                    } catch (Exception $e) {
                        $output->writeln(sprintf('...Error: %s', $e->getMessage()));
                    }
                } else {
                    $output->writeln(sprintf('Feature "%s" DOES NOT exist in template "%s" yet. Enable force mode with -f option', $featureName, $templateId['id']));
                }
            }
        }
    }

    protected function createClient(string $host, string $token): Client
    {
        return new Client([
            'url' => $host,
            'token' => $token,
        ]);
    }

    protected function createFeature(Client $client, string $featureName, string $featureTitle, string $featureDesc, bool $force, OutputInterface $output): void
    {
        $features = $client->listFeatures(['type' => 'project']);

        $featureExists = false;
        foreach ($features as $feature) {
            if ($featureName === $feature['name']) {
                $featureExists = true;
                break;
            }
        }

        if ($featureExists) {
            $output->writeln(sprintf('Feature "%s" already exists. Skipping.', $featureName));
        } else {
            if ($force) {
                $output->writeln(sprintf('Feature "%s" DOES NOT exist yet. Adding...', $featureName));
                try {
                    $client->createFeature($featureName, 'project', $featureTitle, $featureDesc);
                    $output->writeln('...Success');
                } catch (Exception $e) {
                    $output->writeln(sprintf('...Error: %s', $e->getMessage()));
                }
            } else {
                $output->writeln(sprintf('Feature "%s" DOES NOT exist yet. Enable force mode with -f option', $featureName));
            }
        }
    }

    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $args = $input->getArguments();
        $force = $input->getOption(self::OPT_FORCE);

        $client = $this->createClient($args[self::ARG_URL], $args[self::ARG_TOKEN]);
        $this->createFeature($client, $args[self::ARG_FEATURE_NAME], $args[self::ARG_FEATURE_TITLE], $args[self::ARG_FEATURE_DESC], $force, $output);
        $this->addFeature($client, $args[self::ARG_FEATURE_NAME], $force, $output);

        return 0;
    }
}
