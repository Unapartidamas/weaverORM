<?php

declare(strict_types=1);

namespace Weaver\ORM\Repository;

use Psr\SimpleCache\CacheInterface;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Pagination\Page;
use Weaver\ORM\Query\EntityQueryBuilder;

abstract class CachingRepository extends AbstractRepository
{
    private ?CacheInterface $cache = null;
    private int $ttl = 3600;
    private string $cachePrefix = '';

    public function withCache(CacheInterface $cache, int $ttl = 3600, string $prefix = ''): static
    {
        $clone = clone $this;
        $clone->cache      = $cache;
        $clone->ttl        = $ttl;
        $clone->cachePrefix = $prefix !== '' ? $prefix : $this->entityClass;
        return $clone;
    }

    public function find(mixed $id): ?object
    {
        if ($this->cache === null) {
            return parent::find($id);
        }

        $key = $this->cacheKey($id);

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $entity = parent::find($id);

        if ($entity !== null) {
            $this->cache->set($key, $entity, $this->ttl);
        }

        return $entity;
    }

    public function save(object $entity): void
    {
        parent::save($entity);

        if ($this->cache !== null) {
            $mapper = $this->getMapper();
            $id     = $mapper->getProperty($entity, $mapper->getPrimaryKey());
            if ($id !== null) {
                $this->cache->delete($this->cacheKey($id));
            }
        }
    }

    public function delete(object $entity): void
    {
        if ($this->cache !== null) {
            $mapper = $this->getMapper();
            $id     = $mapper->getProperty($entity, $mapper->getPrimaryKey());
            if ($id !== null) {
                $this->cache->delete($this->cacheKey($id));
            }
        }

        parent::delete($entity);
    }

    public function invalidate(mixed $id): void
    {
        $this->cache?->delete($this->cacheKey($id));
    }

    public function invalidateAll(): void
    {
        $this->cache?->clear();
    }

    private function cacheKey(mixed $id): string
    {
        return $this->cachePrefix . ':' . $id;
    }
}
