<?php

declare(strict_types=1);

namespace Weaver\ORM\Cache;

use Psr\SimpleCache\CacheInterface;

final class ArrayCache implements CacheInterface
{
    private array $store = [];
    private array $ttls = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->store[$key];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;

        if ($ttl !== null) {
            $seconds = $ttl instanceof \DateInterval
                ? (int) (new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp()
                : $ttl;
            $this->ttls[$key] = time() + $seconds;
        } else {
            unset($this->ttls[$key]);
        }

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key], $this->ttls[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        $this->ttls = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
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

    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->store)) {
            return false;
        }

        if (isset($this->ttls[$key]) && $this->ttls[$key] < time()) {
            unset($this->store[$key], $this->ttls[$key]);
            return false;
        }

        return true;
    }
}
