<?php

namespace Keboola\Console\Tests;

use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;

/**
 * Minimal in-memory double for Keboola\StorageApi\Components, sufficient to drive
 * DataAppOrchestratorTaskMigrator without any real Storage API access.
 */
class FakeComponents extends Components
{
    /** @var array<string, array<int, array<string, mixed>>> componentId => list of configuration data */
    private array $configsByComponent;

    /** @var array<string, array<string, mixed>> "componentId/configurationId" => configuration data, used by getConfiguration() */
    private array $singleConfigsById;

    /** @var array<int, array<string, mixed>> */
    public array $updateCalls = [];

    public int $getConfigurationCalls = 0;

    /**
     * @param array<string, array<int, array<string, mixed>>> $configsByComponent
     * @param array<string, array<string, mixed>> $singleConfigsById
     */
    public function __construct(array $configsByComponent, array $singleConfigsById = [])
    {
        $this->configsByComponent = $configsByComponent;
        $this->singleConfigsById = $singleConfigsById;
    }

    public function listComponentConfigurations(ListComponentConfigurationsOptions $options)
    {
        return $this->configsByComponent[$options->getComponentId()] ?? [];
    }

    public function getConfiguration($componentId, $configurationId)
    {
        $this->getConfigurationCalls++;
        $key = $componentId . '/' . $configurationId;
        if (!isset($this->singleConfigsById[$key])) {
            throw new ClientException(sprintf('Configuration "%s" of component "%s" not found', $configurationId, $componentId));
        }

        return $this->singleConfigsById[$key];
    }

    public function updateConfiguration(Configuration $options)
    {
        $this->updateCalls[] = [
            'componentId' => $options->getComponentId(),
            'configurationId' => $options->getConfigurationId(),
            'configuration' => $options->getConfiguration(),
            'changeDescription' => $options->getChangeDescription(),
        ];

        return [];
    }
}
