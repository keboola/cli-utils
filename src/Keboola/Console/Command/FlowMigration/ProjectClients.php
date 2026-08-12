<?php

declare(strict_types=1);

namespace Keboola\Console\Command\FlowMigration;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\StorageApi\Components;

/**
 * Per-project API clients bound to one ephemeral project storage token.
 */
class ProjectClients
{
    public Components $components;
    public JobQueueClient $queueClient;

    public function __construct(Components $components, JobQueueClient $queueClient)
    {
        $this->components = $components;
        $this->queueClient = $queueClient;
    }
}
