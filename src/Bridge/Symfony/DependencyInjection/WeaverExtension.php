<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Weaver\ORM\Connection\ConnectionFactory;
use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Contract\EntityMapperInterface;
use Weaver\ORM\DBAL\Connection as WeaverConnection;
use Weaver\ORM\DBAL\Platform\PyroSqlPlatform;
use Weaver\ORM\DBAL\Platform\PostgresPlatform;
use Weaver\ORM\DBAL\Platform\MysqlPlatform;
use Weaver\ORM\DBAL\Platform\SqlitePlatform;
use Weaver\ORM\Manager\WorkspaceRegistry;
use Weaver\ORM\Mapping\MapperRegistry;

final class WeaverExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('weaver.default_connection', $config['default_connection']);
        $container->setParameter('weaver.max_rows_safety_limit', $config['max_rows_safety_limit']);
        $container->setParameter('weaver.debug', $config['debug']);
        $container->setParameter('weaver.n1_detector', $config['n1_detector']);

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 4) . '/config'));
        $loader->load('services.php');

        $container->registerForAutoconfiguration(EntityMapperInterface::class)
            ->addTag('weaver.mapper')
            ->setPublic(true);

        $connectionFactoryDef = new Definition(ConnectionFactory::class);
        $connectionFactoryDef->setPublic(true);
        $container->setDefinition(ConnectionFactory::class, $connectionFactoryDef);

        $connections = $config['connections'] ?? [];
        $defaultConnection = $config['default_connection'];

        $connectionRegistryDef = new Definition(ConnectionRegistry::class, [
            $connections,
            $defaultConnection,
        ]);
        $connectionRegistryDef->setPublic(true);
        $container->setDefinition(ConnectionRegistry::class, $connectionRegistryDef);

        $workspaceRegistryDef = new Definition(WorkspaceRegistry::class, [
            new Reference(ConnectionRegistry::class),
            null,
            $defaultConnection,
        ]);
        $workspaceRegistryDef->setPublic(true);
        $container->setDefinition(WorkspaceRegistry::class, $workspaceRegistryDef);

        // Register Weaver\ORM\DBAL\Connection for the default connection
        $defaultConfig = $connections[$defaultConnection] ?? [];
        $driver = $defaultConfig['driver'] ?? 'pdo_mysql';

        $weaverConnDef = new Definition(WeaverConnection::class);
        $weaverConnDef->setPublic(true);
        $weaverConnDef->setFactory([self::class, 'createWeaverConnection']);
        $weaverConnDef->setArguments([$defaultConfig, $driver]);
        $container->setDefinition(WeaverConnection::class, $weaverConnDef);

        // Also alias Doctrine\DBAL\Connection if not already defined
        if (!$container->has(\Doctrine\DBAL\Connection::class)) {
            $dbalConnDef = new Definition(\Doctrine\DBAL\Connection::class);
            $dbalConnDef->setFactory([new Reference(ConnectionRegistry::class), 'getConnection']);
            $dbalConnDef->setPublic(true);
            $container->setDefinition(\Doctrine\DBAL\Connection::class, $dbalConnDef);
        }
    }

    public static function createWeaverConnection(array $config, string $driver): WeaverConnection
    {
        $platform = match ($driver) {
            'pyrosql' => new PyroSqlPlatform(),
            'pdo_pgsql' => new PostgresPlatform(),
            'pdo_mysql' => new MysqlPlatform(),
            'pdo_sqlite' => new SqlitePlatform(),
            default => new PostgresPlatform(),
        };

        $dsn = match ($driver) {
            'pyrosql' => sprintf('pyrosql:host=%s;port=%d;dbname=%s',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 12520,
                $config['dbname'] ?? '',
            ),
            'pdo_pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 5432,
                $config['dbname'] ?? '',
            ),
            'pdo_mysql' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 3306,
                $config['dbname'] ?? '',
                $config['charset'] ?? 'utf8mb4',
            ),
            'pdo_sqlite' => sprintf('sqlite:%s', $config['path'] ?? ':memory:'),
            default => throw new \RuntimeException("Unsupported driver: {$driver}"),
        };

        $pdo = new \PDO($dsn, $config['user'] ?? null, $config['password'] ?? null);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new WeaverConnection($pdo, $platform);
    }

    public function getAlias(): string
    {
        return 'weaver';
    }
}
