<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Bridge\CodeIgniter;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Bridge\CodeIgniter\WeaverConfig;

final class WeaverConfigTest extends TestCase
{
    public function test_default_values_are_set(): void
    {
        $config = new WeaverConfig();

        self::assertSame('default', $config->defaultConnection);
        self::assertFalse($config->debug);
        self::assertFalse($config->n1Detector);
        self::assertSame(100000, $config->maxRowsSafetyLimit);
        self::assertSame([], $config->mapperDirectories);
        self::assertFalse($config->cache['enabled']);
        self::assertNull($config->cache['adapter']);
        self::assertSame(3600, $config->cache['defaultTtl']);
    }

    public function test_connections_has_default_entry(): void
    {
        $config = new WeaverConfig();

        self::assertArrayHasKey('default', $config->connections);
        self::assertSame('pdo_mysql', $config->connections['default']['driver']);
        self::assertSame('127.0.0.1', $config->connections['default']['host']);
        self::assertSame(3306, $config->connections['default']['port']);
        self::assertSame('utf8mb4', $config->connections['default']['charset']);
    }

    public function test_config_properties_are_public(): void
    {
        $reflection = new \ReflectionClass(WeaverConfig::class);

        $publicProperties = array_map(
            static fn (\ReflectionProperty $p) => $p->getName(),
            $reflection->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        self::assertContains('defaultConnection', $publicProperties);
        self::assertContains('connections', $publicProperties);
        self::assertContains('debug', $publicProperties);
        self::assertContains('n1Detector', $publicProperties);
        self::assertContains('maxRowsSafetyLimit', $publicProperties);
        self::assertContains('mapperDirectories', $publicProperties);
        self::assertContains('cache', $publicProperties);
    }
}
