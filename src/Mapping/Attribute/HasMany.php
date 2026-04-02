<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class HasMany
{

    public function __construct(
        public readonly string $target,
        public readonly string $foreignKey,
        public readonly string $localKey = 'id',
        public readonly array $cascade = [],
        public readonly array $orderBy = [],
        public readonly string $inversedBy = '',
        public readonly string $mappedBy = '',
    ) {}
}
