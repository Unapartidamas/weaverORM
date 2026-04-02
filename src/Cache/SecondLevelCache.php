<?php

declare(strict_types=1);

namespace Weaver\ORM\Cache;

use Psr\SimpleCache\CacheInterface;

final class SecondLevelCache
{
    private array $regionMap = [];

    public function __construct(
        private readonly CacheInterface $adapter,
        private readonly CacheConfiguration $config = new CacheConfiguration(),
    ) {}

    public function get(string $entityClass, string|int $id): ?array
    {
        $key = $this->buildKey($entityClass, $id);
        $data = $this->adapter->get($key);

        return is_array($data) ? $data : null;
    }

    public function put(string $entityClass, string|int $id, array $data, ?int $ttl = null): void
    {
        $key = $this->buildKey($entityClass, $id);
        $effectiveTtl = $ttl ?? $this->getRegionForEntity($entityClass)->ttl;

        $this->adapter->set($key, $data, $effectiveTtl);

        $trackingKey = $this->regionTrackingKey($this->resolveRegionName($entityClass));
        $tracked = $this->adapter->get($trackingKey);
        if (!is_array($tracked)) {
            $tracked = [];
        }
        $tracked[$key] = true;
        $this->adapter->set($trackingKey, $tracked);

        $classTrackingKey = $this->classTrackingKey($entityClass);
        $classTracked = $this->adapter->get($classTrackingKey);
        if (!is_array($classTracked)) {
            $classTracked = [];
        }
        $classTracked[$key] = true;
        $this->adapter->set($classTrackingKey, $classTracked);
    }

    public function evict(string $entityClass, string|int $id): void
    {
        $key = $this->buildKey($entityClass, $id);
        $this->adapter->delete($key);
    }

    public function evictAll(string $entityClass): void
    {
        $classTrackingKey = $this->classTrackingKey($entityClass);
        $tracked = $this->adapter->get($classTrackingKey);

        if (is_array($tracked)) {
            foreach (array_keys($tracked) as $key) {
                $this->adapter->delete($key);
            }
        }

        $this->adapter->delete($classTrackingKey);
    }

    public function evictRegion(string $region): void
    {
        $trackingKey = $this->regionTrackingKey($region);
        $tracked = $this->adapter->get($trackingKey);

        if (is_array($tracked)) {
            foreach (array_keys($tracked) as $key) {
                $this->adapter->delete($key);
            }
        }

        $this->adapter->delete($trackingKey);
    }

    public function contains(string $entityClass, string|int $id): bool
    {
        $key = $this->buildKey($entityClass, $id);

        return $this->adapter->has($key);
    }

    public function getRegionForEntity(string $entityClass): CacheRegion
    {
        if (isset($this->regionMap[$entityClass])) {
            return $this->regionMap[$entityClass];
        }

        $regionName = $this->resolveRegionName($entityClass);
        $ttl = $this->config->getRegionTtl($regionName);

        $this->regionMap[$entityClass] = new CacheRegion($regionName, $ttl);

        return $this->regionMap[$entityClass];
    }

    private function buildKey(string $entityClass, string|int $id): string
    {
        $region = $this->getRegionForEntity($entityClass);

        return $region->buildKey($entityClass, $id);
    }

    private function resolveRegionName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);

        return strtolower(end($parts));
    }

    private function regionTrackingKey(string $region): string
    {
        return 'weaver_l2_region_' . $region;
    }

    private function classTrackingKey(string $entityClass): string
    {
        return 'weaver_l2_class_' . md5($entityClass);
    }
}
