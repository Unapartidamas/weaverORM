<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use Weaver\ORM\Tests\Fixture\Entity\User;
use Weaver\ORM\Tests\Fixture\WeaverIntegrationTestCase;
use Weaver\ORM\PyroSQL\Query\TimeTravelQueryBuilder;

final class TimeTravelQueryBuilderTest extends WeaverIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    private function makeTimeTravelBuilder(): TimeTravelQueryBuilder
    {
        return new TimeTravelQueryBuilder($this->makeQueryBuilder(User::class), 'users');
    }

    public function test_as_of_sets_expression(): void
    {
        $ttqb = $this->makeTimeTravelBuilder()
            ->asOf(new \DateTimeImmutable('2024-01-15 12:00:00'));

        self::assertStringContainsString("AS OF TIMESTAMP '2024-01-15 12:00:00'", $ttqb->getAsOfExpression());
    }

    public function test_as_of_version_sets_lsn_expression(): void
    {
        $ttqb = $this->makeTimeTravelBuilder()->asOfVersion(42);

        self::assertStringContainsString('AS OF LSN 42', $ttqb->getAsOfExpression());
    }

    public function test_current_removes_expression(): void
    {
        $ttqb = $this->makeTimeTravelBuilder()
            ->asOf(new \DateTimeImmutable('2024-01-15 12:00:00'))
            ->current();

        self::assertNull($ttqb->getAsOfExpression());
    }

    public function test_to_sql_injects_as_of_after_table_alias(): void
    {
        $ttqb = $this->makeTimeTravelBuilder()
            ->asOf(new \DateTimeImmutable('2024-01-15 12:00:00'));

        self::assertStringContainsString('AS OF TIMESTAMP', $ttqb->toSQL());
    }

    public function test_to_sql_without_as_of_returns_plain_sql(): void
    {
        $ttqb = $this->makeTimeTravelBuilder();

        self::assertStringNotContainsString('AS OF', $ttqb->toSQL());
    }

    public function test_clone_produces_independent_instance(): void
    {
        $a = $this->makeTimeTravelBuilder()->asOf(new \DateTimeImmutable('2024-01-15 12:00:00'));
        $b = clone $a;

        $b = $b->current();

        self::assertNotNull($a->getAsOfExpression());
        self::assertNull($b->getAsOfExpression());
    }

    public function test_as_of_returns_new_instance_not_same(): void
    {
        $a = $this->makeTimeTravelBuilder();
        $b = $a->asOf(new \DateTimeImmutable('2024-01-15 12:00:00'));

        self::assertNotSame($a, $b);
    }
}
