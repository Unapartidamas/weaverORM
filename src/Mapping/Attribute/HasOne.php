<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class HasOne
{

    public function __construct(
        public readonly string $target,
        public readonly string $foreignKey,
        public readonly string $localKey = 'id',
        public readonly array $cascade = [],
        public readonly string $inversedBy = '',
        public readonly string $mappedBy = '',
    ) {}
}
