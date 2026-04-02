<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Manager;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Manager\Exception\ManagerNotFoundException;
use Weaver\ORM\Manager\WorkspaceRegistry;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

#[\Weaver\ORM\Mapping\Attribute\Connection(name: 'analytics')]
final class StubAnalyticsEntity {}

final class StubDefaultEntity {}

final class WorkspaceRegistryTest extends TestCase
{
    private function makeWorkspaceRegistry(array $connectionConfigs, string $default = 'default'): WorkspaceRegistry
    {
        $connectionRegistry = new ConnectionRegistry($connectionConfigs, $default);

        $factory = function (string $name, Connection $connection): EntityWorkspace {
            $mapperRegistry = new MapperRegistry();
            $hydrator = new EntityHydrator($mapperRegistry, $connection);
            $dispatcher = new LifecycleEventDispatcher();
            $resolver = new InsertOrderResolver($mapperRegistry);
            $unitOfWork = new UnitOfWork($connection, $mapperRegistry, $hydrator, $dispatcher, $resolver);

            return new EntityWorkspace($name, $connection, $mapperRegistry, $unitOfWork);
        };

        return new WorkspaceRegistry($connectionRegistry, $factory, $default);
    }

    public function test_getWorkspace_returns_default(): void
    {
        $registry = $this->makeWorkspaceRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $workspace = $registry->getWorkspace();

        self::assertInstanceOf(EntityWorkspace::class, $workspace);
        self::assertSame('default', $workspace->getName());
    }

    public function test_getWorkspace_returns_named(): void
    {
        $registry = $this->makeWorkspaceRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'analytics' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $workspace = $registry->getWorkspace('analytics');

        self::assertInstanceOf(EntityWorkspace::class, $workspace);
        self::assertSame('analytics', $workspace->getName());
    }

    public function test_getWorkspace_throws_for_unknown(): void
    {
        $registry = $this->makeWorkspaceRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $this->expectException(ManagerNotFoundException::class);

        $registry->getWorkspace('nonexistent');
    }

    public function test_getWorkspaceForEntity_resolves_connection(): void
    {
        $registry = $this->makeWorkspaceRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'analytics' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $workspace = $registry->getWorkspaceForEntity(StubAnalyticsEntity::class);

        self::assertSame('analytics', $workspace->getName());
    }

    public function test_getWorkspaceForEntity_defaults_when_no_attribute(): void
    {
        $registry = $this->makeWorkspaceRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $workspace = $registry->getWorkspaceForEntity(StubDefaultEntity::class);

        self::assertSame('default', $workspace->getName());
    }

    public function test_getWorkspace_returns_same_instance(): void
    {
        $registry = $this->makeWorkspaceRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $w1 = $registry->getWorkspace('default');
        $w2 = $registry->getWorkspace('default');

        self::assertSame($w1, $w2);
    }

    public function test_resetAll_clears_all_workspaces(): void
    {
        $registry = $this->makeWorkspaceRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'analytics' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $w1 = $registry->getWorkspace('default');
        $w2 = $registry->getWorkspace('analytics');

        $registry->resetAll();

        $w3 = $registry->getWorkspace('default');
        $w4 = $registry->getWorkspace('analytics');

        self::assertNotSame($w1, $w3);
        self::assertNotSame($w2, $w4);
    }

    public function test_getWorkspaceNames_returns_all(): void
    {
        $registry = $this->makeWorkspaceRegistry([
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'analytics' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'reporting' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        self::assertSame(['default', 'analytics', 'reporting'], $registry->getWorkspaceNames());
    }

    public function test_getDefaultWorkspace_uses_configured_default(): void
    {
        $registry = $this->makeWorkspaceRegistry([
            'primary' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'secondary' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ], 'primary');

        $workspace = $registry->getDefaultWorkspace();

        self::assertSame('primary', $workspace->getName());
    }
}
