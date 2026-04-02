<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Persistence;

use Weaver\ORM\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Persistence\BatchProcessor;

final class BatchProcessorTest extends TestCase
{
    private function createConnection(): Connection&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createMock(Connection::class);
    }

    public function test_insertBatch_generates_multi_row_insert(): void
    {
        $connection = $this->createConnection();

        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(function (string $sql): bool {
                    return str_contains($sql, 'INSERT INTO')
                        && str_contains($sql, 'VALUES')
                        && substr_count($sql, '(:', ) >= 2;
                }),
                self::isType('array'),
            )
            ->willReturn(2);

        $processor = new BatchProcessor($connection);
        $result = $processor->insertBatch('users', [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        ]);

        self::assertSame(2, $result);
    }

    public function test_insertBatch_chunks_large_batches(): void
    {
        $connection = $this->createConnection();

        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['name' => 'User' . $i];
        }

        $connection->expects(self::exactly(3))
            ->method('executeStatement')
            ->willReturn(2, 2, 1);

        $processor = new BatchProcessor($connection);
        $result = $processor->insertBatch('users', $rows, chunkSize: 2);

        self::assertSame(5, $result);
    }

    public function test_updateBatch_generates_case_when(): void
    {
        $connection = $this->createConnection();

        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(function (string $sql): bool {
                    return str_contains($sql, 'UPDATE')
                        && str_contains($sql, 'CASE')
                        && str_contains($sql, 'WHEN')
                        && str_contains($sql, 'IN');
                }),
                self::isType('array'),
            )
            ->willReturn(2);

        $processor = new BatchProcessor($connection);
        $result = $processor->updateBatch('users', [
            ['id' => 1, 'name' => 'Alice Updated'],
            ['id' => 2, 'name' => 'Bob Updated'],
        ]);

        self::assertSame(2, $result);
    }

    public function test_deleteBatch_generates_delete_in(): void
    {
        $connection = $this->createConnection();

        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(function (string $sql): bool {
                    return str_contains($sql, 'DELETE FROM')
                        && str_contains($sql, 'IN');
                }),
                self::isType('array'),
            )
            ->willReturn(3);

        $processor = new BatchProcessor($connection);
        $result = $processor->deleteBatch('users', [1, 2, 3]);

        self::assertSame(3, $result);
    }

    public function test_upsertBatch_generates_on_conflict(): void
    {
        $connection = $this->createConnection();

        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(function (string $sql): bool {
                    return str_contains($sql, 'INSERT INTO')
                        && str_contains($sql, 'ON CONFLICT')
                        && str_contains($sql, 'DO UPDATE SET')
                        && str_contains($sql, 'EXCLUDED');
                }),
                self::isType('array'),
            )
            ->willReturn(2);

        $processor = new BatchProcessor($connection);
        $result = $processor->upsertBatch('users', [
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
        ], 'id');

        self::assertSame(2, $result);
    }
}
