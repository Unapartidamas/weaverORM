<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Query;

final class ProfileResult
{
    public function __construct(
        public readonly float $executionTimeMs,
        public readonly int $rowsScanned,
        public readonly int $rowsReturned,
        public readonly array $metrics,
        public readonly array $data,
    ) {}

    public static function fromRows(array $rows): self
    {
        $metrics = $rows[0] ?? [];

        return new self(
            executionTimeMs: (float) ($metrics['execution_time_ms'] ?? $metrics['time_ms'] ?? 0),
            rowsScanned:     (int) ($metrics['rows_scanned'] ?? 0),
            rowsReturned:    (int) ($metrics['rows_returned'] ?? 0),
            metrics:         $metrics,
            data:            array_slice($rows, 1),
        );
    }
}
