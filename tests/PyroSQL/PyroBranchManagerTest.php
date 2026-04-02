<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use Weaver\ORM\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\Branch\PyroBranch;
use Weaver\ORM\PyroSQL\Branch\PyroBranchManager;
use Weaver\ORM\PyroSQL\Exception\BranchNotFoundException;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

final class PyroBranchManagerTest extends TestCase
{
    private Connection $connection;
    private PyroSqlDriver $driver;
    private PyroBranchManager $manager;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);

        $driverConnection = $this->createStub(Connection::class);
        $driverConnection->method('fetchAssociative')->willReturn(['v' => '2.0.0']);
        $this->driver = new PyroSqlDriver($driverConnection);

        $this->manager = new PyroBranchManager($this->connection, $this->driver);
    }

    public function test_create_executes_create_branch_sql(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('CREATE BRANCH'));

        $this->connection->method('fetchAssociative')
            ->willReturn(['name' => 'feat', 'parent_name' => 'main', 'created_at' => '2024-01-01 00:00:00']);

        $result = $this->manager->create('feat', 'main');

        self::assertInstanceOf(PyroBranch::class, $result);
    }

    public function test_create_with_as_of_includes_timestamp_in_sql(): void
    {
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('AS OF TIMESTAMP'));

        $this->connection->method('fetchAssociative')
            ->willReturn(['name' => 'feat', 'parent_name' => 'main', 'created_at' => '2024-01-01 00:00:00']);

        $this->manager->create('feat', 'main', new \DateTimeImmutable('2024-01-01 00:00:00'));
    }

    public function test_delete_throws_when_branch_not_found(): void
    {
        $this->connection->method('fetchAssociative')->willReturn(false);

        $this->expectException(BranchNotFoundException::class);
        $this->manager->delete('nonexistent');
    }

    public function test_exists_returns_true_when_branch_found(): void
    {
        $this->connection->method('fetchAssociative')->willReturn(['1' => 1]);

        self::assertTrue($this->manager->exists('main'));
    }

    public function test_exists_returns_false_when_not_found(): void
    {
        $this->connection->method('fetchAssociative')->willReturn(false);

        self::assertFalse($this->manager->exists('nonexistent'));
    }

    public function test_list_returns_array_of_branches(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturn([['name' => 'main', 'parent_name' => '', 'created_at' => '2024-01-01 00:00:00']]);

        $branches = $this->manager->list();

        self::assertCount(1, $branches);
        self::assertInstanceOf(PyroBranch::class, $branches[0]);
    }





    public function test_switch_executes_set_branch_statement_when_branch_exists(): void
    {

        $this->connection->method('fetchAssociative')->willReturn(['1' => 1]);
        $this->connection->method('quote')
            ->with('feat')
            ->willReturn("'feat'");

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with("SET pyrosql.branch = 'feat'");

        $this->manager->switch('feat');
    }

    public function test_switch_throws_branch_not_found_when_branch_does_not_exist(): void
    {
        $this->connection->method('fetchAssociative')->willReturn(false);

        $this->expectException(BranchNotFoundException::class);
        $this->expectExceptionMessage("PyroSQL branch 'ghost' does not exist.");

        $this->manager->switch('ghost');
    }





    public function test_get_returns_pyros_branch_with_correct_data(): void
    {
        $this->connection->method('fetchAssociative')
            ->willReturn([
                'name'        => 'feat',
                'parent_name' => 'main',
                'created_at'  => '2024-06-01 12:00:00',
            ]);

        $branch = $this->manager->get('feat');

        self::assertInstanceOf(PyroBranch::class, $branch);
        self::assertSame('feat', $branch->getName());
        self::assertSame('main', $branch->getParentName());
        self::assertEquals(new \DateTimeImmutable('2024-06-01 12:00:00'), $branch->getCreatedAt());
    }

    public function test_get_throws_branch_not_found_when_row_is_missing(): void
    {
        $this->connection->method('fetchAssociative')->willReturn(false);

        $this->expectException(BranchNotFoundException::class);
        $this->expectExceptionMessage("PyroSQL branch 'missing' does not exist.");

        $this->manager->get('missing');
    }
}
