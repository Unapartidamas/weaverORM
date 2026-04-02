<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Psr\SimpleCache\CacheInterface;
use Weaver\ORM\Cache\CacheConfiguration;
use Weaver\ORM\Cache\QueryResultCache;
use Weaver\ORM\Cache\SecondLevelCache;
use Weaver\ORM\Connection\ConnectionFactory;
use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Contract\EntityMapperInterface;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Manager\WorkspaceRegistry;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

final class WeaverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/weaver.php', 'weaver');

        $this->app->singleton(ConnectionFactory::class, fn () => new ConnectionFactory());

        $this->app->singleton(ConnectionRegistry::class, fn ($app) => new ConnectionRegistry(
            $app['config']->get('weaver.connections', []),
            $app['config']->get('weaver.default_connection', 'default'),
        ));

        $this->app->singleton(MapperRegistry::class, fn () => new MapperRegistry());

        $this->app->singleton(LifecycleEventDispatcher::class, function ($app) {
            $bridge = new LaravelEventBridge($app->make(Dispatcher::class));
            $dispatcher = new LifecycleEventDispatcher();

            $dispatcher->addListener('*', static function () use ($bridge) {
            });

            return $dispatcher;
        });

        $this->app->singleton(EntityHydrator::class, fn ($app) => new EntityHydrator(
            $app->make(MapperRegistry::class),
            $app->make(ConnectionRegistry::class)->getDefaultConnection(),
        ));

        $this->app->singleton(InsertOrderResolver::class, fn ($app) => new InsertOrderResolver(
            $app->make(MapperRegistry::class),
        ));

        $this->app->singleton(UnitOfWork::class, fn ($app) => new UnitOfWork(
            $app->make(ConnectionRegistry::class)->getDefaultConnection(),
            $app->make(MapperRegistry::class),
            $app->make(EntityHydrator::class),
            $app->make(LifecycleEventDispatcher::class),
            $app->make(InsertOrderResolver::class),
        ));

        $this->app->singleton(EntityWorkspace::class, fn ($app) => new EntityWorkspace(
            name: $app['config']->get('weaver.default_connection', 'default'),
            connection: $app->make(ConnectionRegistry::class)->getDefaultConnection(),
            mapperRegistry: $app->make(MapperRegistry::class),
            unitOfWork: $app->make(UnitOfWork::class),
        ));

        $this->app->singleton(WorkspaceRegistry::class, fn ($app) => new WorkspaceRegistry(
            connectionRegistry: $app->make(ConnectionRegistry::class),
            workspaceFactory: function (string $name, $connection) use ($app) {
                return new EntityWorkspace(
                    name: $name,
                    connection: $connection,
                    mapperRegistry: $app->make(MapperRegistry::class),
                    unitOfWork: new UnitOfWork(
                        $connection,
                        $app->make(MapperRegistry::class),
                        $app->make(EntityHydrator::class),
                        $app->make(LifecycleEventDispatcher::class),
                    ),
                );
            },
            defaultName: $app['config']->get('weaver.default_connection', 'default'),
        ));

        $this->app->singleton(PyroSqlDriver::class, fn ($app) => new PyroSqlDriver(
            $app->make(ConnectionRegistry::class)->getDefaultConnection(),
        ));

        $this->app->singleton('weaver.query_factory', fn ($app) => new WeaverQueryFactory(
            $app->make(EntityWorkspace::class),
            $app->make(EntityHydrator::class),
        ));

        $this->registerCache();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/weaver.php' => $this->app->configPath('weaver.php'),
        ], 'weaver-config');

        $this->discoverMappers();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Command\SchemaCreateCommand::class,
                Command\SchemaUpdateCommand::class,
                Command\SchemaDropCommand::class,
                Command\SchemaDiffCommand::class,
                Command\DebugMappersCommand::class,
            ]);
        }
    }

    private function registerCache(): void
    {
        $this->app->singleton(CacheConfiguration::class, function ($app) {
            $config = $app['config']->get('weaver.cache', []);
            $cacheConfig = new CacheConfiguration(
                enabled: $config['enabled'] ?? false,
                defaultTtl: $config['default_ttl'] ?? 3600,
            );

            if ($cacheConfig->isEnabled()) {
                $store = $config['store'] ?? 'default';
                $cacheStore = $store === 'default'
                    ? $app->make(CacheRepository::class)
                    : $app->make('cache')->store($store);
                $cacheConfig->setAdapter(new LaravelCacheAdapter($cacheStore));
            }

            return $cacheConfig;
        });

        $this->app->singleton(SecondLevelCache::class, function ($app) {
            $config = $app->make(CacheConfiguration::class);

            if (!$config->isEnabled() || $config->getAdapter() === null) {
                return new SecondLevelCache(new NullCache(), $config);
            }

            return new SecondLevelCache($config->getAdapter(), $config);
        });

        $this->app->singleton(QueryResultCache::class, function ($app) {
            $config = $app->make(CacheConfiguration::class);

            if (!$config->isEnabled() || $config->getAdapter() === null) {
                return new QueryResultCache(new NullCache());
            }

            return new QueryResultCache($config->getAdapter());
        });
    }

    private function discoverMappers(): void
    {
        $registry = $this->app->make(MapperRegistry::class);
        $directories = $this->app['config']->get('weaver.mapper_directories', []);

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = $this->resolveClassFromFile($file->getPathname());

                if ($class === null) {
                    continue;
                }

                if (!class_exists($class)) {
                    continue;
                }

                $reflection = new \ReflectionClass($class);

                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                if ($reflection->implementsInterface(EntityMapperInterface::class)) {
                    $registry->register($this->app->make($class));
                }
            }
        }
    }

    private function resolveClassFromFile(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = $matches[1];
        }

        if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($class === null) {
            return null;
        }

        return $namespace !== null ? $namespace . '\\' . $class : $class;
    }
}
