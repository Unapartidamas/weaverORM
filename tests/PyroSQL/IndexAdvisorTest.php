<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\PyroSQL\AutoIndex\IndexAdvisor;

final class IndexAdvisorTest extends TestCase
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

    public function test_suggest_executes_suggest_indexes(): void
    {
        $connection = $this->makeConnection();

        $expected = [
            ['index_name' => 'idx_orders_status', 'columns' => 'status', 'type' => 'btree'],
        ];

        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with('SUGGEST INDEXES FOR "orders"')
            ->willReturn($expected);

        $advisor = new IndexAdvisor($connection);
        $result = $advisor->suggest('orders');

        self::assertSame($expected, $result);
    }

    public function test_tryIndex_executes_try_index(): void
    {
        $connection = $this->makeConnection();

        $expected = [
            ['estimated_improvement' => '45%', 'current_cost' => '1200', 'new_cost' => '660'],
        ];

        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with('TRY INDEX ON "orders"(status, customer_id)')
            ->willReturn($expected);

        $advisor = new IndexAdvisor($connection);
        $result = $advisor->tryIndex('orders', ['status', 'customer_id']);

        self::assertSame($expected, $result);
    }

    public function test_getUnusedIndexes_queries_stats(): void
    {
        $connection = $this->makeConnection();

        $expected = [
            ['index_name' => 'idx_orders_old', 'table_name' => 'orders', 'scans' => '0'],
        ];

        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                'SELECT * FROM pyrosql_stats.unused_indexes WHERE table_name = ?',
                ['orders'],
            )
            ->willReturn($expected);

        $advisor = new IndexAdvisor($connection);
        $result = $advisor->getUnusedIndexes('orders');

        self::assertSame($expected, $result);
    }
}
