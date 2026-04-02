<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Embedded
{
    public function __construct(
        public readonly string $class,
        public readonly string $prefix = '',
    ) {}
}
