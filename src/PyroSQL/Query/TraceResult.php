<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Query;

final class TraceResult
{
    public function __construct(
        public readonly array $steps,
        public readonly array $data,
        public readonly array $raw,
    ) {}

    public static function fromRows(array $rows): self
    {
        return new self(
            steps: $rows,
            data:  [],
            raw:   $rows,
        );
    }
}
