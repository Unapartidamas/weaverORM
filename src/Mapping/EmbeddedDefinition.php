<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

final readonly class EmbeddedDefinition
{

    public function __construct(
        public readonly string $property,
        public readonly string $embeddableClass,
        public readonly string $prefix,
        public readonly array $columns,
        public readonly array $nestedEmbeddables = [],
    ) {}
}
