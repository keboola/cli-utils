<?php

declare(strict_types=1);

namespace Keboola\Console\Tests\FlowMigration;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class FakeJobQueueClientTest extends TestCase
{
    public function testMakeJobBuildsRealDtoWithTerminalFlag(): void
    {
        $running = FakeJobQueueClient::makeJob('1', 'processing');
        $finished = FakeJobQueueClient::makeJob('1', 'success', 42, ['message' => 'ok']);

        $this->assertFalse($running->isFinished);
        $this->assertTrue($finished->isFinished);
        $this->assertSame('success', $finished->status);
        $this->assertSame(42, $finished->durationSeconds);
        $this->assertSame(['message' => 'ok'], $finished->result);
    }

    public function testGetJobConsumesScriptedSequenceAndThrowsThrowables(): void
    {
        $fake = new FakeJobQueueClient([], ['job-1' => [
            new RuntimeException('network blip'),
            FakeJobQueueClient::makeJob('job-1', 'success'),
        ]]);

        try {
            $fake->getJob('job-1');
            $this->fail('First scripted outcome should throw');
        } catch (RuntimeException $e) {
            $this->assertSame('network blip', $e->getMessage());
        }

        $this->assertSame('success', $fake->getJob('job-1')->status);
        $this->assertSame([['getJob', 'job-1'], ['getJob', 'job-1']], $fake->calls);
    }
}
