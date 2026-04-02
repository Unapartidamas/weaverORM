<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Psr\SimpleCache\CacheInterface;

final class LaravelCacheAdapter implements CacheInterface
{
    public function __construct(
        private readonly Repository $store,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        if ($ttl === null) {
            $this->store->forever($key, $value);
        } else {
            $this->store->put($key, $value, $ttl);
        }

        return true;
    }

    public function delete(string $key): bool
    {
        return $this->store->forget($key);
    }

    public function clear(): bool
    {
        return $this->store->flush();
    }

    public function has(string $key): bool
    {
        return $this->store->has($key);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }
}
