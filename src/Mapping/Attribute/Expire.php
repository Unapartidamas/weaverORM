<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Expire
{
    public function __construct(
        public readonly int $duration,
        public readonly string $unit = 'DAYS',
    ) {}
}
