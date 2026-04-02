<?php

declare(strict_types=1);

namespace Weaver\ORM\Profiler;

final readonly class N1Warning
{
    public function __construct(
        public readonly string $sqlTemplate,
        public readonly int $occurrences,
        public readonly string $suggestion,
    ) {}
}
