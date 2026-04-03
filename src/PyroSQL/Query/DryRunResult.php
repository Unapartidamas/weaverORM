<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Query;

final class DryRunResult
{
    public function __construct(
        public readonly int $estimatedRowsAffected,
        public readonly array $warnings,
        public readonly array $metrics,
    ) {}

    public static function fromRows(array $rows): self
    {
        $metrics = $rows[0] ?? [];

        return new self(
            estimatedRowsAffected: (int) ($metrics['estimated_rows'] ?? $metrics['rows_affected'] ?? 0),
            warnings:              array_filter(array_column($rows, 'warning')),
            metrics:               $metrics,
        );
    }
}
