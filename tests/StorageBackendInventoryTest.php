<?php

declare(strict_types=1);

namespace Keboola\Console\Tests;

use Keboola\Console\Command\StorageBackendInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StorageBackendInventoryTest extends TestCase
{
    private StorageBackendInventory $inventory;

    protected function setUp(): void
    {
        $this->inventory = new StorageBackendInventory();
    }

    /**
     * @return array<string, array{string, int, int, string}>
     */
    public static function verdictProvider(): array
    {
        return [
            'unsupported unused' =>
                ['mysql', 0, 0, StorageBackendInventory::VERDICT_DELETE_UNSUPPORTED_UNUSED],
            'unsupported with projects' =>
                ['redshift', 3, 0, StorageBackendInventory::VERDICT_REVIEW_UNSUPPORTED_IN_USE],
            'unsupported with maintainer only' =>
                ['supabase', 0, 1, StorageBackendInventory::VERDICT_REVIEW_UNSUPPORTED_IN_USE],
            'snowflake unused' => ['snowflake', 0, 0, StorageBackendInventory::VERDICT_DELETE_UNUSED],
            'bigquery unused' => ['bigquery', 0, 0, StorageBackendInventory::VERDICT_DELETE_UNUSED],
            'snowflake maintainer only' => ['snowflake', 0, 1, StorageBackendInventory::VERDICT_REVIEW_MAINTAINER_ONLY],
            'snowflake live' => ['snowflake', 5, 0, StorageBackendInventory::VERDICT_KEEP],
            'bigquery live with maintainer' => ['bigquery', 1, 1, StorageBackendInventory::VERDICT_KEEP],
        ];
    }

    #[DataProvider('verdictProvider')]
    public function testResolveVerdict(string $type, int $projects, int $maintainers, string $expected): void
    {
        $this->assertSame($expected, $this->inventory->resolveVerdict($type, $projects, $maintainers));
    }

    public function testBuildRowMapsListFields(): void
    {
        $row = $this->inventory->buildRow([
            'id' => 42,
            'backend' => 'snowflake',
            'host' => 'acme.snowflakecomputing.com',
            'region' => 'us-east-1',
            'owner' => 'client-Acme',
            'technicalOwner' => 'byodb',
            'assignedProjectsCount' => 7,
            'assignedMaintainersCount' => 1,
            'stats' => ['bucketsCount' => 3],
            'created' => '2024-05-04T12:00:00+0200',
        ]);

        $this->assertNotNull($row);
        $this->assertSame(42, $row['id']);
        $this->assertSame('acme.snowflakecomputing.com', $row['host']);
        $this->assertSame(7, $row['projects']);
        $this->assertSame(1, $row['maintainers']);
        $this->assertSame(3, $row['buckets']);
        $this->assertSame('2024-05-04', $row['created']);
        $this->assertSame(StorageBackendInventory::VERDICT_KEEP, $row['verdict']);
        // Detail columns start empty; they are filled by applyDetail().
        $this->assertSame('', $row['loginType']);
        $this->assertSame('', $row['keyRotated']);
    }

    public function testBuildRowFallsBackToFolderIdForBigquery(): void
    {
        $row = $this->inventory->buildRow(['id' => 9, 'backend' => 'bigquery', 'folderId' => 'folders/123']);

        $this->assertNotNull($row);
        $this->assertSame('folders/123', $row['host']);
    }

    public function testBuildRowDefaultsMissingFields(): void
    {
        $row = $this->inventory->buildRow(['id' => 5]);

        $this->assertNotNull($row);
        $this->assertSame('', $row['backend']);
        $this->assertSame(0, $row['projects']);
        $this->assertSame(0, $row['maintainers']);
        $this->assertSame(0, $row['buckets']);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function invalidIdProvider(): array
    {
        return [
            'missing id' => [['backend' => 'snowflake']],
            'non-numeric id' => [['id' => 'abc', 'backend' => 'snowflake']],
            'zero id' => [['id' => 0, 'backend' => 'snowflake']],
            'negative id' => [['id' => -3, 'backend' => 'snowflake']],
        ];
    }

    /**
     * @param array<mixed> $backend
     */
    #[DataProvider('invalidIdProvider')]
    public function testBuildRowRejectsEntriesWithoutUsableId(array $backend): void
    {
        $this->assertNull($this->inventory->buildRow($backend));
    }

    public function testApplyDetailFillsDetailColumns(): void
    {
        $row = $this->inventory->buildRow(['id' => 1, 'backend' => 'snowflake']);
        $this->assertNotNull($row);

        $row = $this->inventory->applyDetail($row, [
            'loginType' => 'snowflake-service-keypair',
            'keyPairLastRotatedAt' => '2026-08-04T12:00:06+0200',
            'useDynamicBackends' => true,
            'useSso' => false,
            'isSsoEnabled' => true,
            'isSsoConfigured' => false,
        ]);

        $this->assertSame('snowflake-service-keypair', $row['loginType']);
        $this->assertSame('2026-08-04', $row['keyRotated']);
        $this->assertSame('1', $row['dynBackends']);
        $this->assertSame('0', $row['useSso']);
        $this->assertSame('1', $row['ssoEnabled']);
        $this->assertSame('0', $row['ssoConfigured']);
    }

    public function testApplyDetailWithEmptyDetailLeavesColumnsEmpty(): void
    {
        $row = $this->inventory->buildRow(['id' => 1, 'backend' => 'bigquery']);
        $this->assertNotNull($row);

        $row = $this->inventory->applyDetail($row, []);

        $this->assertSame('', $row['loginType']);
        $this->assertSame('', $row['keyRotated']);
        $this->assertSame('', $row['dynBackends']);
        $this->assertSame('', $row['useSso']);
    }

    public function testApplyDetailDoesNotTouchCountsOrVerdict(): void
    {
        $row = $this->inventory->buildRow([
            'id' => 17,
            'backend' => 'snowflake',
            'assignedProjectsCount' => 300,
            'assignedMaintainersCount' => 2,
        ]);
        $this->assertNotNull($row);

        $row = $this->inventory->applyDetail($row, ['assignedProjectsCount' => 0, 'loginType' => 'default']);

        $this->assertSame(300, $row['projects']);
        $this->assertSame(2, $row['maintainers']);
        $this->assertSame(StorageBackendInventory::VERDICT_KEEP, $row['verdict']);
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function sampleRows(): array
    {
        $build = function (int $id, string $type, int $projects, int $maintainers): array {
            $row = $this->inventory->buildRow([
                'id' => $id,
                'backend' => $type,
                'assignedProjectsCount' => $projects,
                'assignedMaintainersCount' => $maintainers,
            ]);
            $this->assertNotNull($row);
            return $row;
        };

        return [
            $build(1, 'snowflake', 100, 2),   // KEEP
            $build(2, 'snowflake', 0, 0),     // DELETE_UNUSED
            $build(3, 'mysql', 0, 0),         // DELETE_UNSUPPORTED_UNUSED
            $build(4, 'redshift', 2, 0),      // REVIEW_UNSUPPORTED_IN_USE
            $build(5, 'bigquery', 0, 1),      // REVIEW_MAINTAINER_ONLY
        ];
    }

    public function testFilterRowsUnsupportedOnly(): void
    {
        $filtered = $this->inventory->filterRows($this->sampleRows(), true, null);

        $this->assertSame([3, 4], array_column($filtered, 'id'));
    }

    public function testFilterRowsByVerdict(): void
    {
        $filtered = $this->inventory->filterRows(
            $this->sampleRows(),
            false,
            StorageBackendInventory::VERDICT_DELETE_UNUSED
        );

        $this->assertSame([2], array_column($filtered, 'id'));
    }

    public function testFilterRowsComposesBothFilters(): void
    {
        $filtered = $this->inventory->filterRows(
            $this->sampleRows(),
            true,
            StorageBackendInventory::VERDICT_DELETE_UNSUPPORTED_UNUSED
        );

        $this->assertSame([3], array_column($filtered, 'id'));
    }

    public function testFilterRowsWithoutFiltersReturnsAll(): void
    {
        $this->assertCount(5, $this->inventory->filterRows($this->sampleRows(), false, null));
    }

    public function testSummarize(): void
    {
        $summary = $this->inventory->summarize($this->sampleRows());

        $this->assertSame(['snowflake' => 2, 'mysql' => 1, 'redshift' => 1, 'bigquery' => 1], $summary['byType']);
        $this->assertSame(1, $summary['byVerdict'][StorageBackendInventory::VERDICT_KEEP]);
        $this->assertSame(
            ['2'],
            $summary['deleteIds'][StorageBackendInventory::VERDICT_DELETE_UNUSED]
        );
        $this->assertSame(
            ['3'],
            $summary['deleteIds'][StorageBackendInventory::VERDICT_DELETE_UNSUPPORTED_UNUSED]
        );
        $this->assertSame(2, $summary['needsReview']);
    }

    public function testSummarizeOnFilteredRowsCoversOnlyThoseRows(): void
    {
        $filtered = $this->inventory->filterRows($this->sampleRows(), true, null);
        $summary = $this->inventory->summarize($filtered);

        // The paste-ready delete list must not leak IDs hidden by the filter (e.g. id 2).
        $this->assertSame(
            ['3'],
            $summary['deleteIds'][StorageBackendInventory::VERDICT_DELETE_UNSUPPORTED_UNUSED]
        );
        $this->assertSame([], $summary['deleteIds'][StorageBackendInventory::VERDICT_DELETE_UNUSED]);
    }
}
