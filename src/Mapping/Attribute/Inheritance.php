<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Inheritance
{
    public function __construct(
        public readonly string $type = 'SINGLE_TABLE',
    ) {}
}
