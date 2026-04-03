<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

final class ExpiryDefinition
{
    public function __construct(
        public readonly int $duration,
        public readonly string $unit,
    ) {}

    public function toSql(): string
    {
        return sprintf('EXPIRE AFTER %d %s', $this->duration, strtoupper($this->unit));
    }
}
