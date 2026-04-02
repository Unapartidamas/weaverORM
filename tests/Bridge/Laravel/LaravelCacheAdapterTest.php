<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Bridge\Laravel;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Bridge\Laravel\LaravelCacheAdapter;

final class LaravelCacheAdapterTest extends TestCase
{
    private object $store;
    private LaravelCacheAdapter $adapter;

    protected function setUp(): void
    {
        if (!interface_exists(\Illuminate\Contracts\Cache\Repository::class)) {
            self::markTestSkipped('illuminate/cache not installed.');
        }

        $this->store = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $this->adapter = new LaravelCacheAdapter($this->store);
    }

    public function test_get_delegates_to_laravel_cache(): void
    {
        $this->store->expects(self::once())
            ->method('get')
            ->with('key', null)
            ->willReturn('value');

        self::assertSame('value', $this->adapter->get('key'));
    }

    public function test_set_delegates_to_laravel_cache(): void
    {
        $this->store->expects(self::once())
            ->method('put')
            ->with('key', 'value', 60);

        self::assertTrue($this->adapter->set('key', 'value', 60));
    }

    public function test_delete_delegates_to_laravel_cache(): void
    {
        $this->store->expects(self::once())
            ->method('forget')
            ->with('key')
            ->willReturn(true);

        self::assertTrue($this->adapter->delete('key'));
    }

    public function test_has_delegates_to_laravel_cache(): void
    {
        $this->store->expects(self::once())
            ->method('has')
            ->with('key')
            ->willReturn(true);

        self::assertTrue($this->adapter->has('key'));
    }
}
