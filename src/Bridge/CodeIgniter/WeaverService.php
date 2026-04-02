<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\CodeIgniter;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\Connection\ConnectionFactory;
use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Manager\WorkspaceRegistry;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

final class WeaverService
{
    private static array $instances = [];
    private static ?WeaverConfig $config = null;

    public static function setConfig(WeaverConfig $config): void
    {
        self::$config = $config;
    }

    public static function getConfig(): WeaverConfig
    {
        if (self::$config !== null) {
            return self::$config;
        }

        if (class_exists('Config\\Weaver')) {
            self::$config = new \Config\Weaver();
        } else {
            self::$config = new WeaverConfig();
        }

        return self::$config;
    }

    public static function connectionFactory(bool $getShared = true): ConnectionFactory
    {
        if ($getShared && isset(self::$instances[ConnectionFactory::class])) {
            return self::$instances[ConnectionFactory::class];
        }

        $instance = new ConnectionFactory();

        if ($getShared) {
            self::$instances[ConnectionFactory::class] = $instance;
        }

        return $instance;
    }

    public static function connectionRegistry(bool $getShared = true): ConnectionRegistry
    {
        if ($getShared && isset(self::$instances[ConnectionRegistry::class])) {
            return self::$instances[ConnectionRegistry::class];
        }

        $config = self::getConfig();
        $instance = new ConnectionRegistry($config->connections, $config->defaultConnection);

        if ($getShared) {
            self::$instances[ConnectionRegistry::class] = $instance;
        }

        return $instance;
    }

    public static function mapperRegistry(bool $getShared = true): MapperRegistry
    {
        if ($getShared && isset(self::$instances[MapperRegistry::class])) {
            return self::$instances[MapperRegistry::class];
        }

        $instance = new MapperRegistry();

        if ($getShared) {
            self::$instances[MapperRegistry::class] = $instance;
        }

        return $instance;
    }

    public static function eventDispatcher(bool $getShared = true): LifecycleEventDispatcher
    {
        if ($getShared && isset(self::$instances[LifecycleEventDispatcher::class])) {
            return self::$instances[LifecycleEventDispatcher::class];
        }

        $instance = new LifecycleEventDispatcher();

        if ($getShared) {
            self::$instances[LifecycleEventDispatcher::class] = $instance;
        }

        return $instance;
    }

    public static function workspace(string $connection = 'default', bool $getShared = true): EntityWorkspace
    {
        return self::workspaceRegistry($getShared)->getWorkspace($connection);
    }

    public static function workspaceRegistry(bool $getShared = true): WorkspaceRegistry
    {
        if ($getShared && isset(self::$instances[WorkspaceRegistry::class])) {
            return self::$instances[WorkspaceRegistry::class];
        }

        $connectionRegistry = self::connectionRegistry($getShared);
        $mapperRegistry = self::mapperRegistry($getShared);
        $dispatcher = self::eventDispatcher($getShared);
        $config = self::getConfig();

        $factory = static function (string $name, Connection $conn) use ($mapperRegistry, $dispatcher): EntityWorkspace {
            $hydrator = new EntityHydrator($mapperRegistry, $conn);
            $resolver = new InsertOrderResolver($mapperRegistry);
            $unitOfWork = new UnitOfWork($conn, $mapperRegistry, $hydrator, $dispatcher, $resolver);

            return new EntityWorkspace($name, $conn, $mapperRegistry, $unitOfWork);
        };

        $instance = new WorkspaceRegistry($connectionRegistry, $factory, $config->defaultConnection);

        if ($getShared) {
            self::$instances[WorkspaceRegistry::class] = $instance;
        }

        return $instance;
    }

    public static function reset(): void
    {
        self::$instances = [];
        self::$config = null;
    }
}
