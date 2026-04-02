<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Bridge\CodeIgniter;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Bridge\CodeIgniter\WeaverConfig;
use Weaver\ORM\Bridge\CodeIgniter\WeaverService;
use Weaver\ORM\Connection\ConnectionFactory;
use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Manager\WorkspaceRegistry;
use Weaver\ORM\Mapping\MapperRegistry;

final class WeaverServiceTest extends TestCase
{
    private function sqliteConfig(): WeaverConfig
    {
        $config = new WeaverConfig();
        $config->defaultConnection = 'default';
        $config->connections = [
            'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ];

        return $config;
    }

    protected function setUp(): void
    {
        WeaverService::reset();
    }

    protected function tearDown(): void
    {
        WeaverService::reset();
    }

    public function test_workspace_returns_entity_workspace(): void
    {
        WeaverService::setConfig($this->sqliteConfig());

        $workspace = WeaverService::workspace();

        self::assertInstanceOf(EntityWorkspace::class, $workspace);
        self::assertSame('default', $workspace->getName());
    }

    public function test_shared_instances_return_same_object(): void
    {
        WeaverService::setConfig($this->sqliteConfig());

        $registry1 = WeaverService::mapperRegistry();
        $registry2 = WeaverService::mapperRegistry();

        self::assertSame($registry1, $registry2);

        $factory1 = WeaverService::connectionFactory();
        $factory2 = WeaverService::connectionFactory();

        self::assertSame($factory1, $factory2);

        $dispatcher1 = WeaverService::eventDispatcher();
        $dispatcher2 = WeaverService::eventDispatcher();

        self::assertSame($dispatcher1, $dispatcher2);
    }

    public function test_reset_clears_all_instances(): void
    {
        WeaverService::setConfig($this->sqliteConfig());

        $registry1 = WeaverService::mapperRegistry();
        $factory1 = WeaverService::connectionFactory();

        WeaverService::reset();
        WeaverService::setConfig($this->sqliteConfig());

        $registry2 = WeaverService::mapperRegistry();
        $factory2 = WeaverService::connectionFactory();

        self::assertNotSame($registry1, $registry2);
        self::assertNotSame($factory1, $factory2);
    }

    public function test_connectionRegistry_returns_registry(): void
    {
        WeaverService::setConfig($this->sqliteConfig());

        $registry = WeaverService::connectionRegistry();

        self::assertInstanceOf(ConnectionRegistry::class, $registry);
        self::assertTrue($registry->hasConnection('default'));
    }

    public function test_non_shared_returns_new_instance(): void
    {
        WeaverService::setConfig($this->sqliteConfig());

        $registry1 = WeaverService::mapperRegistry(false);
        $registry2 = WeaverService::mapperRegistry(false);

        self::assertNotSame($registry1, $registry2);
    }

    public function test_workspaceRegistry_returns_registry(): void
    {
        WeaverService::setConfig($this->sqliteConfig());

        $registry = WeaverService::workspaceRegistry();

        self::assertInstanceOf(WorkspaceRegistry::class, $registry);
    }

    public function test_eventDispatcher_returns_dispatcher(): void
    {
        WeaverService::setConfig($this->sqliteConfig());

        $dispatcher = WeaverService::eventDispatcher();

        self::assertInstanceOf(LifecycleEventDispatcher::class, $dispatcher);
    }

    public function test_connectionFactory_returns_factory(): void
    {
        WeaverService::setConfig($this->sqliteConfig());

        $factory = WeaverService::connectionFactory();

        self::assertInstanceOf(ConnectionFactory::class, $factory);
    }
}
