<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL;

use PDO;
use Weaver\ORM\DBAL\Platform\MysqlPlatform;
use Weaver\ORM\DBAL\Platform\PostgresPlatform;
use Weaver\ORM\DBAL\Platform\PyroSqlPlatform;
use Weaver\ORM\DBAL\Platform\SqlitePlatform;
use Weaver\ORM\DBAL\Type\Type;

final class ConnectionFactory
{
    public static function create(array $params): Connection
    {
        Type::registerBuiltins();

        $driver = $params['driver'] ?? throw new \InvalidArgumentException('Missing "driver" parameter.');

        $pdo = match ($driver) {
            'pdo_sqlite' => self::createSqlitePdo($params),
            'pdo_pgsql' => self::createPgsqlPdo($params),
            'pdo_mysql' => self::createMysqlPdo($params),
            'pyrosql' => self::createPyroSqlPdo($params),
            default => throw new \InvalidArgumentException(sprintf('Unsupported driver "%s".', $driver)),
        };

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $platform = match ($driver) {
            'pdo_sqlite' => new SqlitePlatform(),
            'pdo_pgsql' => new PostgresPlatform(),
            'pdo_mysql' => new MysqlPlatform(),
            'pyrosql' => new PyroSqlPlatform(),
        };

        return new Connection($pdo, $platform);
    }

    private static function createSqlitePdo(array $params): PDO
    {
        if (isset($params['memory']) && $params['memory']) {
            return new PDO('sqlite::memory:');
        }

        $path = $params['path'] ?? ':memory:';

        return new PDO('sqlite:' . $path);
    }

    private static function createPgsqlPdo(array $params): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $params['host'] ?? '127.0.0.1',
            $params['port'] ?? '5432',
            $params['dbname'] ?? $params['database'] ?? '',
        );

        return new PDO(
            $dsn,
            $params['user'] ?? $params['username'] ?? '',
            $params['password'] ?? '',
        );
    }

    private static function createMysqlPdo(array $params): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $params['host'] ?? '127.0.0.1',
            $params['port'] ?? '3306',
            $params['dbname'] ?? $params['database'] ?? '',
            $params['charset'] ?? 'utf8mb4',
        );

        return new PDO(
            $dsn,
            $params['user'] ?? $params['username'] ?? '',
            $params['password'] ?? '',
        );
    }

    private static function createPyroSqlPdo(array $params): PDO
    {
        $dsn = sprintf(
            'pyrosql:host=%s;port=%s;dbname=%s',
            $params['host'] ?? '127.0.0.1',
            $params['port'] ?? '12520',
            $params['dbname'] ?? $params['database'] ?? '',
        );

        return new PDO(
            $dsn,
            $params['user'] ?? $params['username'] ?? '',
            $params['password'] ?? '',
        );
    }
}
