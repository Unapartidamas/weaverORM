<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Bridge\Laravel;

use PHPUnit\Framework\TestCase;

final class WeaverServiceProviderTest extends TestCase
{
    public function test_config_has_correct_defaults(): void
    {
        $config = $this->loadConfig();

        self::assertArrayHasKey('default_connection', $config);
        self::assertArrayHasKey('debug', $config);
        self::assertArrayHasKey('n1_detector', $config);
        self::assertArrayHasKey('max_rows_safety_limit', $config);
        self::assertArrayHasKey('mapper_directories', $config);
        self::assertSame(100000, $config['max_rows_safety_limit']);
    }

    public function test_config_has_connections_array(): void
    {
        $config = $this->loadConfig();

        self::assertArrayHasKey('connections', $config);
        self::assertIsArray($config['connections']);
        self::assertArrayHasKey('default', $config['connections']);

        $defaultConn = $config['connections']['default'];
        self::assertArrayHasKey('driver', $defaultConn);
        self::assertArrayHasKey('host', $defaultConn);
        self::assertArrayHasKey('dbname', $defaultConn);
        self::assertArrayHasKey('user', $defaultConn);
        self::assertArrayHasKey('charset', $defaultConn);
    }

    public function test_config_has_cache_section(): void
    {
        $config = $this->loadConfig();

        self::assertArrayHasKey('cache', $config);
        self::assertFalse($config['cache']['enabled']);
        self::assertSame(3600, $config['cache']['default_ttl']);
    }

    public function test_config_reads_env_defaults(): void
    {
        $config = $this->loadConfig();
        $defaultConn = $config['connections']['default'];

        self::assertSame('127.0.0.1', $defaultConn['host']);
        self::assertSame('forge', $defaultConn['dbname']);
        self::assertSame('forge', $defaultConn['user']);
        self::assertSame('', $defaultConn['password']);
        self::assertSame('utf8mb4', $defaultConn['charset']);
    }

    private function loadConfig(): array
    {
        $envFn = function (string $key, mixed $default = null): mixed {
            $value = getenv($key);
            return $value !== false ? $value : $default;
        };

        $appPathFn = function (string $path = ''): string {
            return '/tmp/app' . ($path !== '' ? '/' . $path : '');
        };

        $code = file_get_contents(__DIR__ . '/../../../src/Bridge/Laravel/config/weaver.php');
        $code = str_replace(['env(', 'app_path('], ['$envFn(', '$appPathFn('], $code);
        $code = str_replace('<?php', '', $code);
        $code = str_replace('declare(strict_types=1);', '', $code);

        return eval($code);
    }
}
