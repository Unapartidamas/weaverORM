<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class BelongsToMany
{

    public function __construct(
        public readonly string $target,
        public readonly string $pivotTable,
        public readonly string $foreignPivotKey,
        public readonly string $relatedPivotKey,
        public readonly string $localKey = 'id',
        public readonly string $relatedKey = 'id',
        public readonly array $cascade = [],
        public readonly array $orderBy = [],
        public readonly string $inversedBy = '',
        public readonly string $mappedBy = '',
    ) {}
}
