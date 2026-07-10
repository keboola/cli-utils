<?php

namespace Keboola\Console\Tests;

use Keboola\Console\Command\MigrateDataAppsOrchestratorTasks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MigrateDataAppsOrchestratorTasksTest extends TestCase
{
    #[DataProvider('provideProjectIdsInput')]
    public function testParseProjectIds(string $input, ?array $expected): void
    {
        $command = new MigrateDataAppsOrchestratorTasks();
        $method = (new ReflectionClass($command))->getMethod('parseProjectIds');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($command, $input));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<int, string>|null}>
     */
    public static function provideProjectIdsInput(): iterable
    {
        yield 'plain list' => ['1,2,3', ['1', '2', '3']];
        yield 'whitespace around entries is trimmed' => ['1, 2 ,3', ['1', '2', '3']];
        yield 'leading zeros are kept as-is' => ['007', ['007']];
        yield 'non-numeric entry invalidates the whole list' => ['1,foo', null];
        yield 'decimal is rejected' => ['1.2', null];
        yield 'exponential notation is rejected' => ['1e3', null];
        yield 'negative number is rejected' => ['-1', null];
        yield 'empty string is rejected' => ['', null];
    }
}
