<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\PyroSQL\Partitioning\PartitionManager;

final class PartitionManagerTest extends TestCase
{
    private Connection $connection;
    private PartitionManager $manager;
    private array $executedSql;

    protected function setUp(): void
    {
        $this->executedSql = [];

        $this->connection = $this->createMock(Connection::class);
        $this->connection
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql): int {
                $this->executedSql[] = $sql;
                return 0;
            });

        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['partition_name' => 'events_2025_q1'],
                ['partition_name' => 'events_2025_q2'],
            ]);

        $this->manager = new PartitionManager($this->connection);
    }

    public function test_createRangePartition_executes_correct_sql(): void
    {
        $this->manager->createRangePartition('events', 'created_at', 'events_2025_q1', '2025-01-01', '2025-04-01');

        self::assertCount(1, $this->executedSql);
        self::assertSame(
            "CREATE TABLE events_2025_q1 PARTITION OF events FOR VALUES FROM ('2025-01-01') TO ('2025-04-01')",
            $this->executedSql[0],
        );
    }

    public function test_createListPartition_executes_correct_sql(): void
    {
        $this->manager->createListPartition('orders', 'region', 'orders_us', ['us-east', 'us-west']);

        self::assertCount(1, $this->executedSql);
        self::assertSame(
            "CREATE TABLE orders_us PARTITION OF orders FOR VALUES IN ('us-east', 'us-west')",
            $this->executedSql[0],
        );
    }

    public function test_createHashPartition_executes_correct_sql(): void
    {
        $this->manager->createHashPartition('logs', 'tenant_id', 4, 0, 'logs_p0');

        self::assertCount(1, $this->executedSql);
        self::assertSame(
            'CREATE TABLE logs_p0 PARTITION OF logs FOR VALUES WITH (MODULUS 4, REMAINDER 0)',
            $this->executedSql[0],
        );
    }

    public function test_detachPartition_executes_correct_sql(): void
    {
        $this->manager->detachPartition('events', 'events_2025_q1');

        self::assertCount(1, $this->executedSql);
        self::assertSame(
            'ALTER TABLE events DETACH PARTITION events_2025_q1',
            $this->executedSql[0],
        );
    }

    public function test_attachPartition_executes_correct_sql(): void
    {
        $this->manager->attachPartition('events', 'events_2025_q1', "FOR VALUES FROM ('2025-01-01') TO ('2025-04-01')");

        self::assertCount(1, $this->executedSql);
        self::assertStringContainsString('ATTACH PARTITION events_2025_q1', $this->executedSql[0]);
    }

    public function test_listPartitions_queries_pg_catalog(): void
    {
        $partitions = $this->manager->listPartitions('events');

        self::assertCount(2, $partitions);
        self::assertSame('events_2025_q1', $partitions[0]['partition_name']);
        self::assertSame('events_2025_q2', $partitions[1]['partition_name']);
    }
}
