<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\PyroSQL\QueryCache\QueryCacheManager;

final class QueryCacheManagerTest extends TestCase
{
    private function makeConnection(): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('quoteIdentifier')
            ->willReturnCallback(static fn(string $id): string => '"' . $id . '"');

        $connection->method('quote')
            ->willReturnCallback(static fn(string $v): string => "'" . addslashes($v) . "'");

        return $connection;
    }

    public function test_enable_sets_query_cache_on(): void
    {
        $connection = $this->makeConnection();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('SET pyrosql.query_cache = on');

        $manager = new QueryCacheManager($connection);
        $manager->enable();
    }

    public function test_disable_sets_query_cache_off(): void
    {
        $connection = $this->makeConnection();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('SET pyrosql.query_cache = off');

        $manager = new QueryCacheManager($connection);
        $manager->disable();
    }

    public function test_invalidate_table_executes_invalidate(): void
    {
        $connection = $this->makeConnection();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with("SELECT pyrosql_invalidate_query_cache('orders')");

        $manager = new QueryCacheManager($connection);
        $manager->invalidate('orders');
    }

    public function test_invalidate_all_executes_invalidate(): void
    {
        $connection = $this->makeConnection();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('SELECT pyrosql_invalidate_query_cache()');

        $manager = new QueryCacheManager($connection);
        $manager->invalidate();
    }

    public function test_getStats_returns_stats_array(): void
    {
        $connection = $this->makeConnection();

        $expected = [
            'hit_rate' => '0.85',
            'miss_rate' => '0.15',
            'size_bytes' => '104857600',
            'entries_count' => '1250',
        ];

        $connection->expects($this->once())
            ->method('fetchAssociative')
            ->with('SELECT hit_rate, miss_rate, size_bytes, entries_count FROM pyrosql_stats.query_cache')
            ->willReturn($expected);

        $manager = new QueryCacheManager($connection);
        $result = $manager->getStats();

        self::assertSame($expected, $result);
    }
}
