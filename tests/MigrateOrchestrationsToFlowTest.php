<?php

declare(strict_types=1);

namespace Keboola\Console\Tests;

use Keboola\Console\Command\MigrateOrchestrationsToFlow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MigrateOrchestrationsToFlowTest extends TestCase
{
    /**
     * @return array<int, string>|string|null
     */
    private function invokePrivate(string $method, string $argument): array|string|null
    {
        $command = new MigrateOrchestrationsToFlow();
        $reflection = (new ReflectionClass($command))->getMethod($method);
        $reflection->setAccessible(true);

        /** @var array<int, string>|string|null $result */
        $result = $reflection->invoke($command, $argument);

        return $result;
    }

    #[DataProvider('provideUrls')]
    public function testHostnameSuffixFromUrl(string $url, ?string $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('hostnameSuffixFromUrl', $url));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function provideUrls(): iterable
    {
        yield 'azure ne stack' => [
            'https://connection.north-europe.azure.keboola.com',
            'north-europe.azure.keboola.com',
        ];
        yield 'aws us stack' => ['https://connection.keboola.com', 'keboola.com'];
        yield 'trailing slash is fine' => ['https://connection.keboola.com/', 'keboola.com'];
        yield 'missing connection prefix' => ['https://queue.keboola.com', null];
        yield 'not a url' => ['not-a-url', null];
        yield 'bare connection host' => ['https://connection.', null];
    }

    /**
     * @param array<int, string>|null $expected
     */
    #[DataProvider('provideProjectLists')]
    public function testParseProjectIdList(string $input, ?array $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('parseProjectIdList', $input));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<int, string>|null}>
     */
    public static function provideProjectLists(): iterable
    {
        yield 'plain list' => ['1,2,3', ['1', '2', '3']];
        yield 'whitespace is trimmed' => ['1, 2 ,3', ['1', '2', '3']];
        yield 'duplicates are removed' => ['1,2,1', ['1', '2']];
        yield 'non-numeric entry invalidates the list' => ['1,foo', null];
        yield 'decimal is rejected' => ['1.2', null];
        yield 'negative is rejected' => ['-1', null];
        yield 'empty string is rejected' => ['', null];
    }

    /**
     * @param array<int, string>|null $expected
     */
    #[DataProvider('provideProjectFiles')]
    public function testParseProjectIdsFile(string $contents, ?array $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('parseProjectIdsFile', $contents));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<int, string>|null}>
     */
    public static function provideProjectFiles(): iterable
    {
        yield 'one id per line' => ["100\n200\n", ['100', '200']];
        yield 'blank lines and comments are ignored' => ["100\n\n# staging batch\n200\n", ['100', '200']];
        yield 'windows line endings' => ["100\r\n200\r\n", ['100', '200']];
        yield 'duplicates are removed' => ["100\n200\n100\n", ['100', '200']];
        yield 'non-numeric line invalidates the file' => ["100\nfoo\n", null];
        yield 'empty file is a valid empty list' => ['', []];
    }
}
