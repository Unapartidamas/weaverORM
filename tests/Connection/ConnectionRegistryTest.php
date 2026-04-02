<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Connection;

use Weaver\ORM\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Connection\ConnectionRegistry;

final class ConnectionRegistryTest extends TestCase
{
    private function makeRegistry(array $connections = [], string $default = 'default'): ConnectionRegistry
    {
        return new ConnectionRegistry($connections, $default);
    }

    public function test_getConnection_returns_default(): void
    {
        $registry = $this->makeRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $connection = $registry->getConnection();

        self::assertInstanceOf(Connection::class, $connection);
    }

    public function test_getConnection_returns_named(): void
    {
        $registry = $this->makeRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'secondary' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $conn1 = $registry->getConnection('default');
        $conn2 = $registry->getConnection('secondary');

        self::assertInstanceOf(Connection::class, $conn1);
        self::assertInstanceOf(Connection::class, $conn2);
        self::assertNotSame($conn1, $conn2);
    }

    public function test_getConnection_throws_for_unknown(): void
    {
        $registry = $this->makeRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Connection 'nonexistent' is not configured.");

        $registry->getConnection('nonexistent');
    }

    public function test_getConnectionNames_returns_all_names(): void
    {
        $registry = $this->makeRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'reporting' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'analytics' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        self::assertSame(['default', 'reporting', 'analytics'], $registry->getConnectionNames());
    }

    public function test_hasConnection_returns_true_for_existing(): void
    {
        $registry = $this->makeRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        self::assertTrue($registry->hasConnection('default'));
    }

    public function test_hasConnection_returns_false_for_nonexistent(): void
    {
        $registry = $this->makeRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        self::assertFalse($registry->hasConnection('missing'));
    }

    public function test_getDefaultConnection_uses_configured_default(): void
    {
        $registry = $this->makeRegistry([
            'primary' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'secondary' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ], 'primary');

        $conn = $registry->getDefaultConnection();

        self::assertInstanceOf(Connection::class, $conn);
        self::assertSame($conn, $registry->getConnection('primary'));
    }

    public function test_lazy_initialization_returns_same_instance(): void
    {
        $registry = $this->makeRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $conn1 = $registry->getConnection('default');
        $conn2 = $registry->getConnection('default');

        self::assertSame($conn1, $conn2);
    }

    public function test_close_removes_cached_connection(): void
    {
        $registry = $this->makeRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $conn1 = $registry->getConnection('default');
        $registry->close('default');
        $conn2 = $registry->getConnection('default');

        self::assertNotSame($conn1, $conn2);
    }

    public function test_closeAll_removes_all_cached_connections(): void
    {
        $registry = $this->makeRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'secondary' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $conn1 = $registry->getConnection('default');
        $conn2 = $registry->getConnection('secondary');

        $registry->closeAll();

        self::assertNotSame($conn1, $registry->getConnection('default'));
        self::assertNotSame($conn2, $registry->getConnection('secondary'));
    }
}
