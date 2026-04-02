<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use Weaver\ORM\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\Branch\PyroBranch;

final class PyroBranchTest extends TestCase
{
    private Connection $connection;
    private PyroBranch $branch;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);

        $this->branch = new PyroBranch(
            name:       'feat',
            parentName: 'main',
            createdAt:  new \DateTimeImmutable('2024-06-01 12:00:00'),
            connection: $this->connection,
        );
    }





    public function test_connection_executes_set_branch_statement(): void
    {
        $this->connection->method('quote')
            ->with('feat')
            ->willReturn("'feat'");

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with("SET pyrosql.branch = 'feat'");

        $this->connection->method('fetchAssociative')->willReturn(false);

        $this->branch->connection();
    }

    public function test_connection_returns_the_same_connection_instance(): void
    {
        $this->connection->method('quote')->willReturn("'feat'");
        $this->connection->method('executeStatement');

        $result = $this->branch->connection();

        self::assertSame($this->connection, $result);
    }





    public function test_mergeTo_executes_merge_branch_sql_with_given_target(): void
    {
        $this->connection->method('quoteIdentifier')
            ->willReturnCallback(fn (string $id): string => '"' . $id . '"');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('MERGE BRANCH "feat" INTO "staging"');

        $this->branch->mergeTo('staging');
    }

    public function test_mergeTo_uses_main_as_default_target(): void
    {
        $this->connection->method('quoteIdentifier')
            ->willReturnCallback(fn (string $id): string => '"' . $id . '"');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('MERGE BRANCH "feat" INTO "main"');

        $this->branch->mergeTo();
    }





    public function test_delete_executes_drop_branch_sql(): void
    {
        $this->connection->method('quoteIdentifier')
            ->with('feat')
            ->willReturn('"feat"');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('DROP BRANCH "feat"');

        $this->branch->delete();
    }





    public function test_storageBytes_returns_value_from_database(): void
    {
        $this->connection->method('fetchAssociative')
            ->with(
                'SELECT storage_bytes FROM pyrosql_branches WHERE name = ?',
                ['feat']
            )
            ->willReturn(['storage_bytes' => '4096']);

        self::assertSame(4096, $this->branch->storageBytes());
    }

    public function test_storageBytes_returns_zero_when_row_not_found(): void
    {
        $this->connection->method('fetchAssociative')
            ->willReturn(false);

        self::assertSame(0, $this->branch->storageBytes());
    }
}
