<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Cache\ArrayCache;
use Weaver\ORM\Cache\QueryResultCache;

final class QueryResultCacheTest extends TestCase
{
    private ArrayCache $adapter;
    private QueryResultCache $cache;

    protected function setUp(): void
    {
        $this->adapter = new ArrayCache();
        $this->cache = new QueryResultCache($this->adapter);
    }

    public function testPutAndGet(): void
    {
        $rows = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];

        $this->cache->put('my-query-key', $rows, 120);

        self::assertSame($rows, $this->cache->get('my-query-key'));
    }

    public function testGetReturnsNullWhenMissing(): void
    {
        self::assertNull($this->cache->get('nonexistent'));
    }

    public function testInvalidate(): void
    {
        $this->cache->put('key1', [['id' => 1]]);

        $this->cache->invalidate('key1');

        self::assertNull($this->cache->get('key1'));
    }

    public function testInvalidateAll(): void
    {
        $this->cache->put('key1', [['id' => 1]]);
        $this->cache->put('key2', [['id' => 2]]);
        $this->cache->put('key3', [['id' => 3]]);

        $this->cache->invalidateAll();

        self::assertNull($this->cache->get('key1'));
        self::assertNull($this->cache->get('key2'));
        self::assertNull($this->cache->get('key3'));
    }

    public function testPutOverwritesExistingEntry(): void
    {
        $this->cache->put('key1', [['id' => 1]]);
        $this->cache->put('key1', [['id' => 99]]);

        self::assertSame([['id' => 99]], $this->cache->get('key1'));
    }

    public function testInvalidateNonExistentKeyDoesNotFail(): void
    {
        $this->cache->invalidate('does-not-exist');

        self::assertNull($this->cache->get('does-not-exist'));
    }

    public function testInvalidateAllWithNoEntriesDoesNotFail(): void
    {
        $this->cache->invalidateAll();

        self::assertNull($this->cache->get('anything'));
    }

    public function testMultipleKeysIndependent(): void
    {
        $this->cache->put('alpha', [['v' => 'a']]);
        $this->cache->put('beta', [['v' => 'b']]);

        $this->cache->invalidate('alpha');

        self::assertNull($this->cache->get('alpha'));
        self::assertSame([['v' => 'b']], $this->cache->get('beta'));
    }
}
