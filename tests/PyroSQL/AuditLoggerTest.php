<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\PyroSQL\Audit\AuditLogger;

final class AuditLoggerTest extends TestCase
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

    public function test_enable_executes_alter_table(): void
    {
        $connection = $this->makeConnection();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('ALTER TABLE "orders" SET (pyrosql.audit = on)');

        $logger = new AuditLogger($connection);
        $logger->enable('orders');
    }

    public function test_disable_executes_alter_table(): void
    {
        $connection = $this->makeConnection();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('ALTER TABLE "orders" SET (pyrosql.audit = off)');

        $logger = new AuditLogger($connection);
        $logger->disable('orders');
    }

    public function test_getHistory_queries_audit_table(): void
    {
        $connection = $this->makeConnection();

        $expected = [
            ['operation' => 'INSERT', 'changed_at' => '2026-03-01 10:00:00', 'row_id' => '1'],
        ];

        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                'SELECT * FROM pyrosql_audit."orders_log" ORDER BY changed_at DESC',
                [],
            )
            ->willReturn($expected);

        $logger = new AuditLogger($connection);
        $result = $logger->getHistory('orders');

        self::assertSame($expected, $result);
    }

    public function test_getHistory_with_date_filters(): void
    {
        $connection = $this->makeConnection();

        $since = new \DateTimeImmutable('2026-01-01 00:00:00');
        $until = new \DateTimeImmutable('2026-03-31 23:59:59');

        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                'SELECT * FROM pyrosql_audit."orders_log" WHERE changed_at >= ? AND changed_at <= ? ORDER BY changed_at DESC LIMIT 50',
                ['2026-01-01 00:00:00', '2026-03-31 23:59:59'],
            )
            ->willReturn([]);

        $logger = new AuditLogger($connection);
        $logger->getHistory('orders', $since, $until, 50);
    }

    public function test_getChangesForRow_queries_by_pk(): void
    {
        $connection = $this->makeConnection();

        $expected = [
            ['operation' => 'UPDATE', 'changed_at' => '2026-02-15 12:00:00', 'row_id' => '42'],
        ];

        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                'SELECT * FROM pyrosql_audit."orders_log" WHERE row_id = ? ORDER BY changed_at DESC',
                [42],
            )
            ->willReturn($expected);

        $logger = new AuditLogger($connection);
        $result = $logger->getChangesForRow('orders', 42);

        self::assertSame($expected, $result);
    }
}
