<?php

declare(strict_types=1);

namespace Weaver\ORM\Profiler;

final class QueryProfiler
{

    private array $records = [];
    private bool $enabled = true;

    public function record(string $sql, array $params, float $durationMs): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->records[] = new QueryRecord(
            sql: $sql,
            params: $params,
            durationMs: $durationMs,
            recordedAt: new \DateTimeImmutable(),
        );
    }

    public function getRecords(): array
    {
        return $this->records;
    }

    public function getTotalTime(): float
    {
        return (float) array_sum(
            array_map(static fn (QueryRecord $r): float => $r->durationMs, $this->records)
        );
    }

    public function getQueryCount(): int
    {
        return count($this->records);
    }

    public function getSlowQueries(float $thresholdMs = 100.0): array
    {
        return array_values(
            array_filter(
                $this->records,
                static fn (QueryRecord $r): bool => $r->durationMs >= $thresholdMs,
            )
        );
    }

    public function reset(): void
    {
        $this->records = [];
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function dump(): void
    {
        $count = $this->getQueryCount();
        $total = $this->getTotalTime();

        echo sprintf("Query Profiler — %d queries, %.3f ms total\n", $count, $total);
        echo str_pad('', 80, '-') . "\n";
        echo sprintf("%-6s %-10s %s\n", '#', 'Time (ms)', 'SQL');
        echo str_pad('', 80, '-') . "\n";

        foreach ($this->records as $i => $record) {
            $sql = strlen($record->sql) > 60 ? substr($record->sql, 0, 57) . '...' : $record->sql;
            echo sprintf("%-6d %-10.3f %s\n", $i + 1, $record->durationMs, $sql);
        }

        echo str_pad('', 80, '-') . "\n";
    }
}
