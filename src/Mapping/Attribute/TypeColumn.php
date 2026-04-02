<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class TypeColumn
{
    public function __construct(
        public readonly string $name = 'type',
        public readonly string $type = 'string',
        public readonly int $length = 255,
    ) {}
}
