<?php

declare(strict_types=1);

namespace Weaver\ORM\Cache;

final class CacheRegion
{
    public function __construct(
        public readonly string $name,
        public readonly int $ttl = 3600,
        public readonly string $prefix = 'weaver_l2',
    ) {}

    public function buildKey(string $entityClass, string|int $id): string
    {
        return $this->prefix . '.' . $this->name . '.' . md5($entityClass) . '.' . $id;
    }
}
