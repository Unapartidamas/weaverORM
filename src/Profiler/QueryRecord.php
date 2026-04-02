<?php

declare(strict_types=1);

namespace Weaver\ORM\Profiler;

final readonly class QueryRecord
{
    public function __construct(
        public readonly string $sql,
        public readonly array $params,
        public readonly float $durationMs,
        public readonly \DateTimeImmutable $recordedAt = new \DateTimeImmutable(),
        public readonly ?string $backtrace = null,
    ) {}
}
