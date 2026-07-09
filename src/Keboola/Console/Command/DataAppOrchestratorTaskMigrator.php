<?php

namespace Keboola\Console\Command;

use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Symfony\Component\Console\Output\OutputInterface;

class DataAppOrchestratorTaskMigrator
{
    // Legacy orchestrations and next-gen conditional flows are two distinct components
    // (NOT variants of the same one - verified against a live stack) but store tasks in
    // the same shape, so both need to be scanned for keboola.data-apps references.
    private const FLOW_COMPONENT_IDS = ['keboola.orchestrator', 'keboola.flow'];
    private const LEGACY_COMPONENT_ID = 'keboola.data-apps';
    private const NEW_COMPONENT_ID = 'keboola.data-app-control';
    private const CHANGE_DESCRIPTION = 'AJDA-2445: migrate keboola.data-apps orchestrator/flow tasks to keboola.data-app-control';

    // Tasks with no explicit "task" param (the common case for flow-triggered data apps) or an
    // explicit start variant are safe to migrate. Any other value (delete/terminate/restore/...)
    // is left untouched - those are not used in orchestrations/flows in practice.
    private const SUPPORTED_LEGACY_TASK_VALUES = [null, 'app-start', 'start'];

    /**
     * @return array{configsScanned: int, configsTouched: int, tasksMigrated: int, tasksSkippedUnsupported: int}
     */
    public function migrateProject(Components $components, OutputInterface $output, string $projectId, bool $isForce): array
    {
        $counts = [
            'configsScanned' => 0,
            'configsTouched' => 0,
            'tasksMigrated' => 0,
            'tasksSkippedUnsupported' => 0,
        ];
        // Keyed by keboola.data-apps configId, resolved appId or null - scoped to a single project
        // (config IDs are only unique within a project) to avoid refetching the same referenced
        // config across multiple tasks/configurations.
        $configIdCache = [];

        foreach (self::FLOW_COMPONENT_IDS as $flowComponentId) {
            $this->migrateFlowComponentConfigs($components, $output, $projectId, $flowComponentId, $isForce, $counts, $configIdCache);
        }

        return $counts;
    }

    /**
     * @param array{configsScanned: int, configsTouched: int, tasksMigrated: int, tasksSkippedUnsupported: int} $counts
     * @param array<string, string|null> $configIdCache
     */
    private function migrateFlowComponentConfigs(
        Components $components,
        OutputInterface $output,
        string $projectId,
        string $flowComponentId,
        bool $isForce,
        array &$counts,
        array &$configIdCache
    ): void {
        $prefix = $isForce ? 'FORCE: ' : 'DRY-RUN: ';

        $configurations = $components->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId($flowComponentId)
                ->setIsDeleted(false)
        );

        foreach ($configurations as $configurationData) {
            $counts['configsScanned']++;
            $configurationId = (string) $configurationData['id'];
            $configuration = $configurationData['configuration'];

            if (!isset($configuration['tasks']) || count($configuration['tasks']) === 0) {
                continue;
            }

            $tasks = $configuration['tasks'];
            $configTouched = false;

            foreach ($tasks as $taskKey => $task) {
                if (($task['task']['componentId'] ?? null) !== self::LEGACY_COMPONENT_ID) {
                    continue;
                }

                $taskLabel = sprintf(
                    'project "%s", %s config "%s" ("%s"), task "%s"',
                    $projectId,
                    $flowComponentId,
                    $configurationId,
                    $configurationData['name'] ?? '',
                    $task['name'] ?? ($task['id'] ?? $taskKey)
                );

                $appId = $this->resolveAppId($components, $task['task'], $configIdCache);
                if ($appId === null) {
                    $output->writeln(sprintf('%sSkipping %s: unsupported task shape or unresolvable app id', $prefix, $taskLabel));
                    $counts['tasksSkippedUnsupported']++;
                    continue;
                }

                $output->writeln(sprintf(
                    '%sMigrating %s: %s -> %s (appId "%s")',
                    $prefix,
                    $taskLabel,
                    self::LEGACY_COMPONENT_ID,
                    self::NEW_COMPONENT_ID,
                    $appId
                ));

                // Keep every other field (type, mode, delay, retry, variableOverrides, ...) untouched -
                // the keboola.flow schema requires "type" alongside componentId/mode, and dropping it
                // silently produced an invalid, unreadable task (caught by live verification on canary-orion).
                $newTask = $task['task'];
                $newTask['componentId'] = self::NEW_COMPONENT_ID;
                unset($newTask['configId']);
                $newTask['configData'] = ['parameters' => ['appId' => $appId]];
                $tasks[$taskKey]['task'] = $newTask;
                $configTouched = true;
                $counts['tasksMigrated']++;
            }

            if (!$configTouched) {
                continue;
            }

            $counts['configsTouched']++;
            $configuration['tasks'] = $tasks;

            if ($isForce) {
                $components->updateConfiguration(
                    (new Configuration())
                        ->setComponentId($flowComponentId)
                        ->setConfigurationId($configurationId)
                        ->setConfiguration($configuration)
                        ->setChangeDescription(self::CHANGE_DESCRIPTION)
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $legacyTask
     * @param array<string, string|null> $configIdCache
     */
    private function resolveAppId(Components $components, array $legacyTask, array &$configIdCache): ?string
    {
        $configData = $legacyTask['configData'] ?? null;
        if (is_array($configData) && is_array($configData['parameters'] ?? null)) {
            return $this->extractAppId($configData['parameters']);
        }

        $configId = $legacyTask['configId'] ?? null;
        if (!is_string($configId) || $configId === '') {
            return null;
        }

        if (array_key_exists($configId, $configIdCache)) {
            return $configIdCache[$configId];
        }

        try {
            $sibling = $components->getConfiguration(self::LEGACY_COMPONENT_ID, $configId);
        } catch (ClientException $e) {
            // Referenced config missing/inaccessible - skip just this task rather than aborting
            // the whole project's migration.
            return $configIdCache[$configId] = null;
        }

        $siblingConfiguration = is_array($sibling) ? ($sibling['configuration'] ?? null) : null;
        $resolved = null;
        if (is_array($siblingConfiguration) && is_array($siblingConfiguration['parameters'] ?? null)) {
            $resolved = $this->extractAppId($siblingConfiguration['parameters']);
        }

        return $configIdCache[$configId] = $resolved;
    }

    /**
     * @param array<mixed, mixed> $parameters
     */
    private function extractAppId(array $parameters): ?string
    {
        $id = $parameters['id'] ?? null;
        if (!is_scalar($id)) {
            return null;
        }

        $task = $parameters['task'] ?? null;
        if (!in_array($task, self::SUPPORTED_LEGACY_TASK_VALUES, true)) {
            return null;
        }

        return (string) $id;
    }
}
