<?php

declare(strict_types=1);

namespace Weaver\ORM\Cache\Attribute;

use Weaver\ORM\Cache\CacheUsage;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Cache
{
    public function __construct(
        public readonly string $region = 'default',
        public readonly int $ttl = 3600,
        public readonly CacheUsage $usage = CacheUsage::READ_WRITE,
    ) {}
}
