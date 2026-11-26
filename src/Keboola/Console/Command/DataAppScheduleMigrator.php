<?php

namespace Keboola\Console\Command;

use GuzzleHttp\Exception\GuzzleException;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Symfony\Component\Console\Output\OutputInterface;

class DataAppScheduleMigrator
{
    private const SCHEDULER_COMPONENT_ID = 'keboola.scheduler';
    private const FLOW_COMPONENT_ID = 'keboola.flow';
    private const LEGACY_COMPONENT_ID = 'keboola.data-apps';
    private const NEW_COMPONENT_ID = 'keboola.data-app-control';
    private const CHANGE_DESCRIPTION = 'AJDA-3868: retarget schedule from keboola.data-apps to a keboola.flow with a keboola.data-app-control task';
    private const PHASE_ID = '5b0e1c3a-7d1f-4a52-9a43-0c7f4b8e2a10';
    private const TASK_ID = '9c4d2f6e-3b8a-4e17-8f25-6a1d0e7b3c42';
    private const SUPPORTED_LEGACY_TASK_VALUES = [null, 'app-start', 'start'];
    private const MARKER_PATTERN = '/\[migrated-from-data-apps:([^\]]+)\]/';

    /**
     * @return array{
     *     schedulesScanned: int,
     *     schedulesMigrated: int,
     *     schedulesAlreadyMigrated: int,
     *     flowsCreated: int,
     *     schedulesSkippedUnsupported: int,
     *     schedulesSkippedUnresolvable: int,
     *     schedulesFailed: int
     * }
     */
    public function migrateProject(
        Components $components,
        SchedulerClient $scheduler,
        OutputInterface $output,
        string $projectId,
        bool $isForce
    ): array {
        $counts = [
            'schedulesScanned' => 0,
            'schedulesMigrated' => 0,
            'schedulesAlreadyMigrated' => 0,
            'flowsCreated' => 0,
            'schedulesSkippedUnsupported' => 0,
            'schedulesSkippedUnresolvable' => 0,
            'schedulesFailed' => 0,
        ];
        $prefix = $isForce ? 'FORCE: ' : 'DRY-RUN: ';

        $flowIdsByDataAppsConfigId = $this->indexMigratedFlows($components);
        $migratedFlowIds = array_flip($flowIdsByDataAppsConfigId);
        /** @var array<string, array{appId: ?string, name: string, reason: ?string}> $dataAppsConfigCache */
        $dataAppsConfigCache = [];

        $schedules = $components->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId(self::SCHEDULER_COMPONENT_ID)
                ->setIsDeleted(false)
        );

        foreach ($schedules as $schedule) {
            $counts['schedulesScanned']++;
            $scheduleId = (string) $schedule['id'];
            $configuration = $schedule['configuration'] ?? null;
            $target = is_array($configuration) ? ($configuration['target'] ?? null) : null;
            if (!is_array($configuration) || !is_array($target)) {
                continue;
            }

            $label = sprintf(
                'project "%s", schedule config "%s" ("%s")',
                $projectId,
                $scheduleId,
                $schedule['name'] ?? ''
            );
            $targetComponentId = $target['componentId'] ?? null;
            $targetConfigurationId = (string) ($target['configurationId'] ?? '');

            if ($targetComponentId === self::FLOW_COMPONENT_ID && isset($migratedFlowIds[$targetConfigurationId])) {
                $output->writeln(sprintf('%sSkipping %s: already migrated to flow "%s"', $prefix, $label, $targetConfigurationId));
                try {
                    if ($isForce) {
                        $scheduler->refreshSchedule($scheduleId);
                    }
                    $counts['schedulesAlreadyMigrated']++;
                } catch (SchedulerClientException $e) {
                    $counts['schedulesFailed']++;
                    $output->writeln(sprintf(' - error while refreshing schedule config "%s": %s', $scheduleId, $e->getMessage()));
                }
                continue;
            }

            if ($targetComponentId !== self::LEGACY_COMPONENT_ID) {
                continue;
            }

            $hasRowsOrTag = ($target['configurationRowIds'] ?? []) !== [] || ($target['tag'] ?? '') !== '';
            if ($hasRowsOrTag || ($target['mode'] ?? null) !== 'run') {
                $output->writeln(sprintf(
                    '%sSkipping %s: unsupported schedule target (configuration rows, tag or a mode other than "run")',
                    $prefix,
                    $label
                ));
                $counts['schedulesSkippedUnsupported']++;
                continue;
            }

            $dataApp = $dataAppsConfigCache[$targetConfigurationId]
                ??= $this->resolveDataApp($components, $targetConfigurationId);
            if ($dataApp['appId'] === null) {
                if ($dataApp['reason'] === 'unsupported-task') {
                    $output->writeln(sprintf('%sSkipping %s: unsupported keboola.data-apps task', $prefix, $label));
                    $counts['schedulesSkippedUnsupported']++;
                } else {
                    $output->writeln(sprintf(
                        '%sSkipping %s: could not resolve app id (missing/inaccessible keboola.data-apps config or no'
                        . ' app id) - needs manual follow-up',
                        $prefix,
                        $label
                    ));
                    $counts['schedulesSkippedUnresolvable']++;
                }
                continue;
            }

            try {
                $flowId = $flowIdsByDataAppsConfigId[$targetConfigurationId] ?? null;
                $flowMessage = $flowId === null ? 'new flow' : sprintf('flow "%s"', $flowId);
                $output->writeln(sprintf(
                    '%sMigrating %s: target %s "%s" -> %s with %s (appId "%s")',
                    $prefix,
                    $label,
                    self::LEGACY_COMPONENT_ID,
                    $targetConfigurationId,
                    $flowMessage,
                    self::NEW_COMPONENT_ID,
                    $dataApp['appId']
                ));

                if ($flowId === null) {
                    if ($isForce) {
                        $flowId = $this->createFlow($components, $targetConfigurationId, $dataApp['name'], $dataApp['appId']);
                        $migratedFlowIds[$flowId] = $targetConfigurationId;
                    } else {
                        $flowId = 'dry-run-' . $targetConfigurationId;
                    }
                    $counts['flowsCreated']++;
                    $flowIdsByDataAppsConfigId[$targetConfigurationId] = $flowId;
                }

                if ($isForce) {
                    $output->writeln(sprintf(
                        'ROLLBACK: %s, old target %s, new flow "%s"',
                        $label,
                        json_encode($target),
                        $flowId
                    ));
                    $newTarget = $target;
                    $newTarget['componentId'] = self::FLOW_COMPONENT_ID;
                    $newTarget['configurationId'] = $flowId;
                    $configuration['target'] = $newTarget;
                    $components->updateConfiguration(
                        (new Configuration())
                            ->setComponentId(self::SCHEDULER_COMPONENT_ID)
                            ->setConfigurationId($scheduleId)
                            ->setConfiguration($configuration)
                            ->setChangeDescription(self::CHANGE_DESCRIPTION)
                    );
                    $scheduler->refreshSchedule($scheduleId);
                }
                $counts['schedulesMigrated']++;
            } catch (ClientException | SchedulerClientException | GuzzleException $e) {
                $counts['schedulesFailed']++;
                $output->writeln(sprintf(' - error while migrating schedule config "%s": %s', $scheduleId, $e->getMessage()));
            }
        }

        return $counts;
    }

    /**
     * @return array<string, string> keboola.data-apps configuration id => id of the flow created for it
     */
    private function indexMigratedFlows(Components $components): array
    {
        $index = [];
        $flows = $components->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId(self::FLOW_COMPONENT_ID)
                ->setIsDeleted(false)
        );
        foreach ($flows as $flow) {
            $description = $flow['description'] ?? '';
            if (is_string($description) && preg_match(self::MARKER_PATTERN, $description, $matches) === 1) {
                $index[$matches[1]] = (string) $flow['id'];
            }
        }

        return $index;
    }

    /**
     * @return array{appId: ?string, name: string, reason: ?string}
     */
    private function resolveDataApp(Components $components, string $configurationId): array
    {
        try {
            $dataApp = $components->getConfiguration(self::LEGACY_COMPONENT_ID, $configurationId);
        } catch (ClientException $e) {
            return ['appId' => null, 'name' => '', 'reason' => 'unresolvable'];
        }

        $name = is_array($dataApp) && is_string($dataApp['name'] ?? null) ? $dataApp['name'] : $configurationId;
        $configuration = is_array($dataApp) ? ($dataApp['configuration'] ?? null) : null;
        $parameters = is_array($configuration) ? ($configuration['parameters'] ?? null) : null;
        if (!is_array($parameters) || !is_scalar($parameters['id'] ?? null) || (string) $parameters['id'] === '') {
            return ['appId' => null, 'name' => $name, 'reason' => 'unresolvable'];
        }
        if (!in_array($parameters['task'] ?? null, self::SUPPORTED_LEGACY_TASK_VALUES, true)) {
            return ['appId' => null, 'name' => $name, 'reason' => 'unsupported-task'];
        }

        return ['appId' => (string) $parameters['id'], 'name' => $name, 'reason' => null];
    }

    private function createFlow(Components $components, string $dataAppsConfigurationId, string $dataAppName, string $appId): string
    {
        $created = $components->addConfiguration(
            (new Configuration())
                ->setComponentId(self::FLOW_COMPONENT_ID)
                ->setName(sprintf('%s - start app', $dataAppName))
                ->setDescription(sprintf(
                    'Starts data app %s. Created from the schedule of keboola.data-apps configuration %s.'
                    . ' [migrated-from-data-apps:%s]',
                    $appId,
                    $dataAppsConfigurationId,
                    $dataAppsConfigurationId
                ))
                ->setConfiguration([
                    'phases' => [['id' => self::PHASE_ID, 'name' => 'Start data app']],
                    'tasks' => [[
                        'id' => self::TASK_ID,
                        'name' => 'Start data app',
                        'phase' => self::PHASE_ID,
                        'task' => [
                            'type' => 'job',
                            'mode' => 'run',
                            'componentId' => self::NEW_COMPONENT_ID,
                            'configData' => ['parameters' => ['appId' => $appId]],
                        ],
                        'enabled' => true,
                    ]],
                ])
                ->setChangeDescription(self::CHANGE_DESCRIPTION)
        );

        return (string) $created['id'];
    }
}
