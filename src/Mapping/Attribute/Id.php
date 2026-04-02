<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Id
{
    public function __construct(
        public readonly string $type = 'integer',
        public readonly bool $autoIncrement = true,
    ) {}
}
