<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Profiler;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Profiler\N1Detector;
use Weaver\ORM\Profiler\N1Warning;
use Weaver\ORM\Profiler\QueryProfiler;
use Weaver\ORM\Profiler\QueryRecord;
use Weaver\ORM\Testing\QueryAssertions;

final class N1DetectorTest extends TestCase
{
    use QueryAssertions;






    public function test_detect_returns_warning_when_same_query_repeated_above_threshold(): void
    {
        $profiler = new QueryProfiler();


        for ($i = 1; $i <= 5; $i++) {
            $profiler->record("SELECT * FROM users WHERE id = {$i}", [], 0.5);
        }

        $detector  = new N1Detector($profiler);
        $warnings  = $detector->detect(threshold: 3);

        self::assertCount(1, $warnings);
        self::assertInstanceOf(N1Warning::class, $warnings[0]);
        self::assertSame(5, $warnings[0]->occurrences);
        self::assertStringContainsString('with()', $warnings[0]->suggestion);
    }





    public function test_detect_returns_empty_when_queries_are_varied(): void
    {
        $profiler = new QueryProfiler();

        $profiler->record('SELECT * FROM users WHERE id = 1', [], 0.3);
        $profiler->record('SELECT * FROM posts WHERE user_id = 1', [], 0.4);
        $profiler->record('SELECT COUNT(*) FROM comments', [], 0.2);

        $detector = new N1Detector($profiler);
        $warnings = $detector->detect(threshold: 3);

        self::assertCount(0, $warnings);
    }





    public function test_detect_below_threshold_does_not_warn(): void
    {
        $profiler = new QueryProfiler();


        $profiler->record('SELECT * FROM orders WHERE id = 1', [], 0.1);
        $profiler->record('SELECT * FROM orders WHERE id = 2', [], 0.1);

        $detector = new N1Detector($profiler);
        $warnings = $detector->detect(threshold: 3);

        self::assertCount(0, $warnings);
    }






    public function test_assert_query_count_passes_with_correct_count(): void
    {
        $conn = $this->createProfilingConnection();
        $conn->executeStatement('CREATE TABLE probe (id INTEGER PRIMARY KEY)');

        $this->assertQueryCount(1, function () use ($conn): void {
            $conn->executeQuery('SELECT * FROM probe');
        });


        self::assertTrue(true);
    }





    public function test_assert_query_count_fails_when_count_mismatches(): void
    {
        $conn = $this->createProfilingConnection();
        $conn->executeStatement('CREATE TABLE probe2 (id INTEGER PRIMARY KEY)');

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertQueryCount(1, function () use ($conn): void {

            $conn->executeQuery('SELECT * FROM probe2');
            $conn->executeQuery('SELECT * FROM probe2');
        });
    }





    public function test_assert_no_n_plus_one_detects_pattern(): void
    {

        $profiler = new QueryProfiler();
        for ($i = 1; $i <= 4; $i++) {
            $profiler->record("SELECT * FROM tags WHERE post_id = {$i}", [], 0.1);
        }

        $detector = new N1Detector($profiler);
        self::assertTrue($detector->hasNPlusOneIssues(threshold: 3));

        $warnings = $detector->detect(threshold: 3);
        self::assertCount(1, $warnings);
        self::assertGreaterThanOrEqual(4, $warnings[0]->occurrences);
    }





    public function test_capture_queries_returns_all_executed_queries(): void
    {
        $conn = $this->createProfilingConnection();
        $conn->executeStatement('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $conn->executeStatement("INSERT INTO items VALUES (1, 'alpha')");
        $conn->executeStatement("INSERT INTO items VALUES (2, 'beta')");

        $records = $this->captureQueries(function () use ($conn): void {
            $conn->executeQuery('SELECT * FROM items');
            $conn->executeQuery('SELECT * FROM items WHERE id = 1');
        });

        self::assertCount(2, $records);
        self::assertContainsOnlyInstancesOf(QueryRecord::class, $records);
        self::assertStringContainsString('SELECT', $records[0]->sql);
    }





    public function test_has_n_plus_one_issues_returns_false_when_no_issues(): void
    {
        $profiler = new QueryProfiler();
        $profiler->record('SELECT * FROM users', [], 1.0);
        $profiler->record('SELECT * FROM posts WHERE user_id = 1', [], 0.5);

        $detector = new N1Detector($profiler);
        self::assertFalse($detector->hasNPlusOneIssues(threshold: 3));
    }
}
