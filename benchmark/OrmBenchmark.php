<?php

declare(strict_types=1);

namespace Weaver\Benchmark;

final class OrmBenchmark
{
    private array $results = [];

    public function measure(string $label, string $orm, \Closure $fn, int $iterations): void
    {
        $times = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $fn($i);
            $elapsed = hrtime(true) - $start;
            $times[] = $elapsed;
        }

        sort($times);
        $count = count($times);
        $sum = array_sum($times);
        $avg = $sum / $count;
        $min = $times[0];
        $max = $times[$count - 1];
        $opsPerSec = $count / ($sum / 1e9);

        $this->results[$label][$orm] = [
            'avg_ns' => $avg,
            'min_ns' => $min,
            'max_ns' => $max,
            'ops_sec' => $opsPerSec,
            'iterations' => $count,
            'total_ns' => $sum,
        ];
    }

    public function measureMemory(string $orm, \Closure $fn): int
    {
        gc_collect_cycles();
        $fn();
        $peak = memory_get_peak_usage(true);
        gc_collect_cycles();
        return $peak;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function printReport(int $iterations, array $memoryResults): void
    {
        $orms = ['Weaver ORM', 'Raw DBAL', 'Raw PDO'];

        echo PHP_EOL;
        echo 'Weaver ORM Benchmark -- Weaver vs Raw DBAL vs Raw PDO' . PHP_EOL;
        echo str_repeat('=', 60) . PHP_EOL;
        echo sprintf('SQLite :memory: | PHP %s | %d iterations', PHP_VERSION, $iterations) . PHP_EOL;
        echo PHP_EOL;

        foreach ($this->results as $label => $ormResults) {
            echo $label . ':' . PHP_EOL;

            $avgTimes = [];
            foreach ($orms as $orm) {
                if (!isset($ormResults[$orm])) {
                    continue;
                }
                $r = $ormResults[$orm];
                $avgMs = $r['avg_ns'] / 1e6;
                $avgTimes[$orm] = $avgMs;
                echo sprintf(
                    "  %-16s %8.3fms avg  (%s ops/sec)  min=%.3fms  max=%.3fms",
                    $orm . ':',
                    $avgMs,
                    number_format((int) round($r['ops_sec'])),
                    $r['min_ns'] / 1e6,
                    $r['max_ns'] / 1e6,
                ) . PHP_EOL;
            }

            if (isset($avgTimes['Weaver ORM'], $avgTimes['Raw DBAL']) && $avgTimes['Raw DBAL'] > 0) {
                $overhead = (($avgTimes['Weaver ORM'] - $avgTimes['Raw DBAL']) / $avgTimes['Raw DBAL']) * 100;
                echo sprintf('  Weaver overhead vs DBAL: %+.0f%%', $overhead) . PHP_EOL;
            }
            if (isset($avgTimes['Weaver ORM'], $avgTimes['Raw PDO']) && $avgTimes['Raw PDO'] > 0) {
                $overhead = (($avgTimes['Weaver ORM'] - $avgTimes['Raw PDO']) / $avgTimes['Raw PDO']) * 100;
                echo sprintf('  Weaver overhead vs PDO:  %+.0f%%', $overhead) . PHP_EOL;
            }

            echo PHP_EOL;
        }

        echo 'Memory Usage:' . PHP_EOL;
        foreach ($orms as $orm) {
            if (!isset($memoryResults[$orm])) {
                continue;
            }
            $bytes = $memoryResults[$orm];
            if ($bytes >= 1024 * 1024) {
                $formatted = sprintf('%.1f MB', $bytes / (1024 * 1024));
            } elseif ($bytes >= 1024) {
                $formatted = sprintf('%.1f KB', $bytes / 1024);
            } else {
                $formatted = $bytes . ' B';
            }
            echo sprintf('  %-16s %s peak', $orm . ':', $formatted) . PHP_EOL;
        }
        echo PHP_EOL;
    }
}
