<?php

declare(strict_types=1);

namespace Weaver\ORM\Testing;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use Weaver\ORM\Profiler\ProfilingConnection;
use Weaver\ORM\Profiler\ProfilingMiddleware;
use Weaver\ORM\Profiler\N1Detector;
use Weaver\ORM\Profiler\QueryProfiler;
use Weaver\ORM\Profiler\QueryRecord;

trait QueryAssertions
{
    private ?QueryProfiler $queryProfiler = null;

    protected function createProfilingConnection(array $params = []): ProfilingConnection
    {
        $this->queryProfiler = new QueryProfiler();

        if ($params === []) {
            $params = ['driver' => 'pdo_sqlite', 'path' => ':memory:'];
        }

        $connection = ConnectionFactory::create($params);
        $middleware = new ProfilingMiddleware($this->queryProfiler);

        return $middleware->wrap($connection);
    }

    protected function setUpQueryProfiling(Connection $conn): ProfilingConnection
    {
        $this->queryProfiler = new QueryProfiler();
        $middleware = new ProfilingMiddleware($this->queryProfiler);

        return $middleware->wrap($conn);
    }

    protected function assertQueryCount(int $expected, callable $callback, string $message = ''): void
    {
        $this->ensureProfiler();
        $this->queryProfiler->reset();

        $callback();

        $actual = $this->queryProfiler->getQueryCount();
        $msg = $message !== '' ? $message : "Expected {$expected} queries but {$actual} were executed.";

        static::assertSame($expected, $actual, $msg);
    }

    protected function assertNoNPlusOne(callable $callback, int $threshold = 3, string $message = ''): void
    {
        $this->ensureProfiler();
        $this->queryProfiler->reset();

        $callback();

        $detector = new N1Detector($this->queryProfiler);
        $warnings = $detector->detect($threshold);

        if (count($warnings) > 0) {
            $details = implode('; ', array_map(
                static fn($w) => "'{$w->sqlTemplate}' appeared {$w->occurrences} times",
                $warnings,
            ));
            $msg = $message !== ''
                ? $message
                : "N+1 query pattern detected: {$details}";
            static::fail($msg);
        }

        static::assertTrue(true);
    }

    protected function assertQueriesContain(string $sqlFragment, callable $callback): void
    {
        $this->ensureProfiler();
        $this->queryProfiler->reset();

        $callback();

        $records = $this->queryProfiler->getRecords();
        $found = false;

        foreach ($records as $record) {
            if (str_contains($record->sql, $sqlFragment)) {
                $found = true;
                break;
            }
        }

        static::assertTrue(
            $found,
            "No query containing '{$sqlFragment}' was executed. Queries: "
                . implode(', ', array_map(static fn($r) => "'{$r->sql}'", $records))
        );
    }

    protected function captureQueries(callable $callback): array
    {
        $this->ensureProfiler();
        $this->queryProfiler->reset();

        $callback();

        return $this->queryProfiler->getRecords();
    }

    private function ensureProfiler(): void
    {
        if ($this->queryProfiler === null) {
            throw new \LogicException(
                'QueryProfiler not initialised. Call createProfilingConnection() or setUpQueryProfiling() first.'
            );
        }
    }
}
