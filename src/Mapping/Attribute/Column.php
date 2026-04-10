<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(
        public readonly string $type = 'string',
        public readonly ?string $name = null,
        public readonly bool $primary = false,
        public readonly bool $autoIncrement = false,
        public readonly bool $nullable = false,
        public readonly ?int $length = null,
        public readonly ?string $default = null,
        public readonly ?string $comment = null,
        public readonly bool $unsigned = false,
        public readonly bool $unique = false,
    ) {}
}
