<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use Weaver\ORM\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\Exception\UnsupportedDriverFeatureException;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

final class PyroSqlDriverTest extends TestCase
{
    public function test_is_pyrosql_returns_true_when_guc_returns_version(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['v' => '1.4.2']);

        $driver = new PyroSqlDriver($connection);

        self::assertTrue($driver->isPyroSql());
        self::assertSame('1.4.2', $driver->getVersion());
    }

    public function test_is_pyrosql_returns_false_when_guc_empty(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['v' => '']);

        $driver = new PyroSqlDriver($connection);

        self::assertFalse($driver->isPyroSql());
    }

    public function test_is_pyrosql_returns_false_when_query_throws(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willThrowException(new \Exception('connection error'));

        $driver = new PyroSqlDriver($connection);

        self::assertFalse($driver->isPyroSql());
    }

    public function test_is_pyrosql_result_is_cached(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(['v' => '1.4.2']);

        $driver = new PyroSqlDriver($connection);

        $driver->isPyroSql();
        $driver->isPyroSql();
    }

    public function test_all_support_methods_true_when_pyrosql(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['v' => '2.0.0']);

        $driver = new PyroSqlDriver($connection);

        self::assertTrue($driver->supportsTimeTravel());
        self::assertTrue($driver->supportsBranching());
        self::assertTrue($driver->supportsVectors());
        self::assertTrue($driver->supportsApproximate());
        self::assertTrue($driver->supportsCdc());
        self::assertTrue($driver->supportsWasmUdfs());
    }

    public function test_assert_supports_throws_when_not_pyrosql(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['v' => '']);

        $driver = new PyroSqlDriver($connection);

        $this->expectException(UnsupportedDriverFeatureException::class);
        $driver->assertSupports('branching');
    }

    public function test_get_version_returns_null_when_not_pyrosql(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['v' => '']);

        $driver = new PyroSqlDriver($connection);

        self::assertNull($driver->getVersion());
    }
}
