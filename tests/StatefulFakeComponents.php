<?php

namespace Keboola\Console\Tests;

use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;

/**
 * In-memory Storage Components double that keeps the effect of writes, so a second migration run
 * sees what the first one wrote.
 */
class StatefulFakeComponents extends Components
{
    /** @var array<string, array<string, array<string, mixed>>> componentId => configurationId => configuration data */
    public array $configs = [];

    /** @var array<int, array<string, mixed>> */
    public array $createCalls = [];

    /** @var array<int, array<string, mixed>> */
    public array $updateCalls = [];

    /** @var array<int, string> "componentId/configurationId" whose update must fail */
    public array $failUpdateFor = [];

    public bool $failCreate = false;

    public ?\Throwable $failCreateWith = null;

    public bool $commitThenFailCreate = false;

    public ?\Throwable $failListWith = null;

    private int $nextId = 1;

    /**
     * @param array<string, array<int, array<string, mixed>>> $configsByComponent
     */
    public function __construct(array $configsByComponent = [])
    {
        foreach ($configsByComponent as $componentId => $configs) {
            foreach ($configs as $config) {
                $this->configs[$componentId][(string) $config['id']] = $config;
            }
        }
    }

    public function listComponentConfigurations(ListComponentConfigurationsOptions $options)
    {
        if ($this->failListWith !== null) {
            throw $this->failListWith;
        }
        return array_values($this->configs[$options->getComponentId()] ?? []);
    }

    public function getConfiguration($componentId, $configurationId)
    {
        if (!isset($this->configs[$componentId][$configurationId])) {
            throw new ClientException(sprintf('Configuration "%s" of component "%s" not found', $configurationId, $componentId));
        }

        return $this->configs[$componentId][$configurationId];
    }

    public function addConfiguration(Configuration $options)
    {
        if ($this->failCreateWith !== null) {
            throw $this->failCreateWith;
        }
        if ($this->failCreate) {
            throw new ClientException('Storage is unavailable');
        }
        $id = 'new-' . $this->nextId++;
        $data = [
            'id' => $id,
            'name' => $options->getName(),
            'description' => $options->getDescription(),
            'configuration' => $options->getConfiguration(),
            'isDisabled' => (bool) $options->getIsDisabled(),
        ];
        $this->configs[$options->getComponentId()][$id] = $data;
        if ($this->commitThenFailCreate) {
            $this->commitThenFailCreate = false;
            throw new ClientException('Gateway timeout');
        }
        $this->createCalls[] = ['componentId' => $options->getComponentId(), 'changeDescription' => $options->getChangeDescription()] + $data;

        return $data;
    }

    public function updateConfiguration(Configuration $options)
    {
        $componentId = $options->getComponentId();
        $configurationId = $options->getConfigurationId();
        if (in_array($componentId . '/' . $configurationId, $this->failUpdateFor, true)) {
            throw new ClientException('Storage is unavailable');
        }
        $this->configs[$componentId][$configurationId]['configuration'] = $options->getConfiguration();
        $this->updateCalls[] = [
            'componentId' => $componentId,
            'configurationId' => $configurationId,
            'configuration' => $options->getConfiguration(),
            'changeDescription' => $options->getChangeDescription(),
        ];

        return $this->configs[$componentId][$configurationId];
    }
}
