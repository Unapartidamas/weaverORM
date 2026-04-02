<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Cache\ArrayCache;
use Weaver\ORM\Cache\CacheConfiguration;
use Weaver\ORM\Cache\CacheRegion;
use Weaver\ORM\Cache\SecondLevelCache;

final class SecondLevelCacheTest extends TestCase
{
    private ArrayCache $adapter;
    private SecondLevelCache $cache;

    protected function setUp(): void
    {
        $this->adapter = new ArrayCache();
        $config = new CacheConfiguration(enabled: true, defaultTtl: 300);
        $this->cache = new SecondLevelCache($this->adapter, $config);
    }

    public function testPutAndGet(): void
    {
        $this->cache->put('App\\Entity\\User', 1, ['id' => 1, 'name' => 'Alice']);

        $data = $this->cache->get('App\\Entity\\User', 1);

        self::assertSame(['id' => 1, 'name' => 'Alice'], $data);
    }

    public function testGetReturnsNullWhenMissing(): void
    {
        $data = $this->cache->get('App\\Entity\\User', 999);

        self::assertNull($data);
    }

    public function testContains(): void
    {
        self::assertFalse($this->cache->contains('App\\Entity\\User', 1));

        $this->cache->put('App\\Entity\\User', 1, ['id' => 1]);

        self::assertTrue($this->cache->contains('App\\Entity\\User', 1));
    }

    public function testEvict(): void
    {
        $this->cache->put('App\\Entity\\User', 1, ['id' => 1]);
        self::assertTrue($this->cache->contains('App\\Entity\\User', 1));

        $this->cache->evict('App\\Entity\\User', 1);

        self::assertFalse($this->cache->contains('App\\Entity\\User', 1));
        self::assertNull($this->cache->get('App\\Entity\\User', 1));
    }

    public function testEvictAll(): void
    {
        $this->cache->put('App\\Entity\\User', 1, ['id' => 1]);
        $this->cache->put('App\\Entity\\User', 2, ['id' => 2]);
        $this->cache->put('App\\Entity\\User', 3, ['id' => 3]);

        $this->cache->evictAll('App\\Entity\\User');

        self::assertNull($this->cache->get('App\\Entity\\User', 1));
        self::assertNull($this->cache->get('App\\Entity\\User', 2));
        self::assertNull($this->cache->get('App\\Entity\\User', 3));
    }

    public function testEvictRegion(): void
    {
        $this->cache->put('App\\Entity\\User', 1, ['id' => 1]);
        $this->cache->put('App\\Entity\\User', 2, ['id' => 2]);

        $region = $this->cache->getRegionForEntity('App\\Entity\\User');

        $this->cache->evictRegion($region->name);

        self::assertNull($this->cache->get('App\\Entity\\User', 1));
        self::assertNull($this->cache->get('App\\Entity\\User', 2));
    }

    public function testPutWithCustomTtl(): void
    {
        $this->cache->put('App\\Entity\\User', 1, ['id' => 1], 60);

        $data = $this->cache->get('App\\Entity\\User', 1);
        self::assertSame(['id' => 1], $data);
    }

    public function testRegionForEntityUsesShortName(): void
    {
        $region = $this->cache->getRegionForEntity('App\\Entity\\User');

        self::assertSame('user', $region->name);
    }

    public function testCacheRegionBuildKey(): void
    {
        $region = new CacheRegion('user', 3600);

        $key = $region->buildKey('App\\Entity\\User', 42);

        self::assertSame('weaver_l2.user.' . md5('App\\Entity\\User') . '.42', $key);
    }

    public function testDifferentEntitiesDoNotCollide(): void
    {
        $this->cache->put('App\\Entity\\User', 1, ['type' => 'user']);
        $this->cache->put('App\\Entity\\Post', 1, ['type' => 'post']);

        self::assertSame(['type' => 'user'], $this->cache->get('App\\Entity\\User', 1));
        self::assertSame(['type' => 'post'], $this->cache->get('App\\Entity\\Post', 1));
    }

    public function testEvictAllDoesNotAffectOtherEntities(): void
    {
        $this->cache->put('App\\Entity\\User', 1, ['type' => 'user']);
        $this->cache->put('App\\Entity\\Post', 1, ['type' => 'post']);

        $this->cache->evictAll('App\\Entity\\User');

        self::assertNull($this->cache->get('App\\Entity\\User', 1));
        self::assertSame(['type' => 'post'], $this->cache->get('App\\Entity\\Post', 1));
    }
}
