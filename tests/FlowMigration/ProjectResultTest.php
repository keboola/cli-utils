<?php

declare(strict_types=1);

namespace Keboola\Console\Tests\FlowMigration;

use Keboola\Console\Command\FlowMigration\ProjectResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProjectResultTest extends TestCase
{
    #[DataProvider('provideStatuses')]
    public function testClassification(string $status, bool $expectedSkipped, bool $expectedFailed): void
    {
        $result = new ProjectResult('123', null, $status, null, null);

        $this->assertSame($expectedSkipped, $result->isSkipped());
        $this->assertSame($expectedFailed, $result->isFailed());
    }

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function provideStatuses(): iterable
    {
        yield 'job success is neither skipped nor failed' => ['success', false, false];
        yield 'job warning counts as migrated, not failed' => ['warning', false, false];
        yield 'job error is failed' => ['error', false, true];
        yield 'job terminated is failed' => ['terminated', false, true];
        yield 'job cancelled is failed' => ['cancelled', false, true];
        yield 'skipped disabled' => [ProjectResult::STATUS_SKIPPED_DISABLED, true, false];
        yield 'skipped no orchestrations' => [
            ProjectResult::STATUS_SKIPPED_NO_ORCHESTRATIONS,
            true,
            false,
        ];
    }
}
