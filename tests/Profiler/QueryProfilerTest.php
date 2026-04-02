<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Profiler;


use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Profiler\ProfilingMiddleware;
use Weaver\ORM\Profiler\QueryProfiler;
use Weaver\ORM\Profiler\QueryRecord;

final class QueryProfilerTest extends TestCase
{
    public function test_profiler_records_sql_and_duration(): void
    {
        $profiler = new QueryProfiler();
        $profiler->record('SELECT 1', [], 12.5);

        $records = $profiler->getRecords();

        self::assertCount(1, $records);
        self::assertInstanceOf(QueryRecord::class, $records[0]);
        self::assertSame('SELECT 1', $records[0]->sql);
        self::assertSame([], $records[0]->params);
        self::assertSame(12.5, $records[0]->durationMs);
        self::assertInstanceOf(\DateTimeImmutable::class, $records[0]->recordedAt);
    }

    public function test_get_query_count_increments_correctly(): void
    {
        $profiler = new QueryProfiler();

        self::assertSame(0, $profiler->getQueryCount());

        $profiler->record('SELECT 1', [], 1.0);
        self::assertSame(1, $profiler->getQueryCount());

        $profiler->record('SELECT 2', [], 2.0);
        self::assertSame(2, $profiler->getQueryCount());

        $profiler->record('SELECT 3', ['a' => 1], 3.0);
        self::assertSame(3, $profiler->getQueryCount());
    }

    public function test_get_total_time_sums_durations(): void
    {
        $profiler = new QueryProfiler();
        $profiler->record('SELECT 1', [], 10.0);
        $profiler->record('SELECT 2', [], 20.5);
        $profiler->record('SELECT 3', [], 5.25);

        self::assertSame(35.75, $profiler->getTotalTime());
    }

    public function test_get_slow_queries_returns_only_queries_over_threshold(): void
    {
        $profiler = new QueryProfiler();
        $profiler->record('SELECT fast', [], 10.0);
        $profiler->record('SELECT medium', [], 49.99);
        $profiler->record('SELECT exactly_threshold', [], 50.0);
        $profiler->record('SELECT slow', [], 150.0);
        $profiler->record('SELECT very_slow', [], 200.5);

        $slow = $profiler->getSlowQueries(50.0);

        self::assertCount(3, $slow);
        self::assertSame('SELECT exactly_threshold', $slow[0]->sql);
        self::assertSame('SELECT slow', $slow[1]->sql);
        self::assertSame('SELECT very_slow', $slow[2]->sql);
    }

    public function test_reset_clears_all_records(): void
    {
        $profiler = new QueryProfiler();
        $profiler->record('SELECT 1', [], 1.0);
        $profiler->record('SELECT 2', [], 2.0);

        self::assertSame(2, $profiler->getQueryCount());

        $profiler->reset();

        self::assertSame(0, $profiler->getQueryCount());
        self::assertSame([], $profiler->getRecords());
        self::assertSame(0.0, $profiler->getTotalTime());
    }

    public function test_disable_stops_recording(): void
    {
        $profiler = new QueryProfiler();

        self::assertTrue($profiler->isEnabled());

        $profiler->record('SELECT before', [], 5.0);
        self::assertSame(1, $profiler->getQueryCount());

        $profiler->disable();

        self::assertFalse($profiler->isEnabled());

        $profiler->record('SELECT after_disable', [], 10.0);
        $profiler->record('SELECT another', [], 20.0);


        self::assertSame(1, $profiler->getQueryCount());
        self::assertSame('SELECT before', $profiler->getRecords()[0]->sql);

        $profiler->enable();
        self::assertTrue($profiler->isEnabled());

        $profiler->record('SELECT after_enable', [], 3.0);
        self::assertSame(2, $profiler->getQueryCount());
    }

    public function test_profiler_integrates_with_sqlite_connection_via_profiling_connection(): void
    {
        $profiler = new QueryProfiler();

        $inner = ConnectionFactory::create(
            ['driver' => 'pdo_sqlite', 'memory' => true],
        );

        $conn = new \Weaver\ORM\Profiler\ProfilingConnection($inner, $profiler);

        self::assertSame(0, $profiler->getQueryCount());

        $conn->executeQuery('SELECT 1');

        self::assertSame(1, $profiler->getQueryCount());

        $record = $profiler->getRecords()[0];
        self::assertSame('SELECT 1', $record->sql);
        self::assertGreaterThanOrEqual(0.0, $record->durationMs);

        $conn->executeQuery('SELECT 2');

        self::assertSame(2, $profiler->getQueryCount());
        self::assertGreaterThan(0.0, $profiler->getTotalTime());
    }
}
