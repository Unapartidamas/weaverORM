<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class TypeMap
{

    public function __construct(
        public readonly array $map = [],
    ) {}
}
