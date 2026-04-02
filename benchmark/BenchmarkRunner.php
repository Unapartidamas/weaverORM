<?php

declare(strict_types=1);

namespace Weaver\Benchmark;

use Weaver\ORM\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Weaver\Benchmark\Scenarios\BenchScenario;

/**
 * Orchestrates benchmark scenarios and collects timing / memory statistics.
 *
 * Each scenario is:
 *  1. Set up (Weaver connection)
 *  2. Warmed up with 10 % of the requested iterations (discarded)
 *  3. Re-set up (fresh tables / state)
 *  4. Measured
 *  5. Torn down
 *
 * Reported metrics per scenario:
 *  - Weaver ops/sec
 *  - Doctrine ORM ops/sec (baseline)
 *  - Overhead % = (doctrine_ops - weaver_ops) / doctrine_ops * 100
 *  - Peak memory after the scenario (bytes)
 */
final class BenchmarkRunner
{
    /** @var list<array{name:string, weaver:float, doctrine:float, overhead:float, memory:int}> */
    private array $results = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManager $doctrineEm,
    ) {}

    /**
     * Runs a single scenario and stores its results.
     *
     * @param BenchScenario $scenario   Scenario to execute.
     * @param int           $iterations Number of measured iterations.
     */
    public function run(BenchScenario $scenario, int $iterations): void
    {
        $warmupIterations = max(1, (int) ceil($iterations * 0.1));

        // ---- Warmup pass (Weaver) ----------------------------------------
        $scenario->setup($this->connection);
        $scenario->runWeaver($this->connection, $warmupIterations);
        $scenario->teardown($this->connection);

        // ---- Warmup pass (Doctrine) --------------------------------------
        $scenario->setup($this->connection);
        $scenario->runDoctrine($this->doctrineEm, $warmupIterations);
        $scenario->teardown($this->connection);
        $this->doctrineEm->clear();

        // ---- Reset memory baseline ---------------------------------------
        gc_collect_cycles();

        // ---- Measured Weaver pass ----------------------------------------
        $scenario->setup($this->connection);
        $weaverOps = $scenario->runWeaver($this->connection, $iterations);
        $scenario->teardown($this->connection);

        gc_collect_cycles();

        // ---- Measured Doctrine pass --------------------------------------
        $scenario->setup($this->connection);
        $doctrineOps = $scenario->runDoctrine($this->doctrineEm, $iterations);
        $scenario->teardown($this->connection);
        $this->doctrineEm->clear();

        $peakMemory = memory_get_peak_usage(true);

        gc_collect_cycles();

        // Overhead: how many % slower is Weaver than Doctrine ORM?
        $overhead = $doctrineOps > 0
            ? (($doctrineOps - $weaverOps) / $doctrineOps) * 100.0
            : 0.0;

        $this->results[] = [
            'name'     => $scenario->name(),
            'weaver'   => $weaverOps,
            'doctrine' => $doctrineOps,
            'overhead' => $overhead,
            'memory'   => $peakMemory,
        ];
    }

    /**
     * Prints a formatted UTF-8 box-drawing results table to stdout.
     */
    public function printTable(): void
    {
        // Column widths (content only, without borders/padding).
        $colWidths = [18, 15, 15, 14, 15];

        $line = static function (string $left, string $sep, string $right, array $widths): string {
            $cells = array_map(
                static fn (int $w): string => str_repeat('═', $w + 2),
                $widths
            );

            return $left . implode($sep, $cells) . $right;
        };

        $row = static function (array $cells, array $widths): string {
            $parts = [];
            foreach ($cells as $k => $cell) {
                $w      = $widths[$k];
                $padded = ' ' . str_pad((string) $cell, $w) . ' ';
                $parts[] = $padded;
            }

            return '║' . implode('║', $parts) . '║';
        };

        $top    = $line('╔', '╦', '╗', $colWidths);
        $mid    = $line('╠', '╬', '╣', $colWidths);
        $bottom = $line('╚', '╩', '╝', $colWidths);

        echo $top . PHP_EOL;
        echo $row(
            ['Scenario', 'Weaver ops/s', 'Doctrine ops/s', 'Overhead', 'Peak Memory'],
            $colWidths
        ) . PHP_EOL;
        echo $mid . PHP_EOL;

        foreach ($this->results as $result) {
            $weaverStr   = number_format((int) round($result['weaver']));
            $doctrineStr = number_format((int) round($result['doctrine']));
            $overheadStr = sprintf('%+.1f%%', $result['overhead']);
            $memoryStr   = $this->formatBytes($result['memory']);

            echo $row(
                [$result['name'], $weaverStr, $doctrineStr, $overheadStr, $memoryStr],
                $colWidths
            ) . PHP_EOL;
        }

        echo $bottom . PHP_EOL;
        echo PHP_EOL;
        echo 'Note: Overhead = how much slower Weaver is vs Doctrine ORM.' . PHP_EOL;
        echo '      Negative overhead means Weaver is faster.' . PHP_EOL;
        echo '      Both using SQLite in-memory. PHP 8.4.' . PHP_EOL;
        echo PHP_EOL;
        echo 'Doctrine config: isDevMode=false, ArrayAdapter cache, proxies pre-generated (fair/production config).' . PHP_EOL;
        echo 'Weaver config:   clone snapshots, prepared statement cache.' . PHP_EOL;
    }

    /**
     * @return list<array{name:string, weaver:float, doctrine:float, overhead:float, memory:int}>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
