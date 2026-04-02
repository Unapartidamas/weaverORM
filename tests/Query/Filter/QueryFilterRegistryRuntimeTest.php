<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query\Filter;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Query\Filter\QueryFilterInterface;
use Weaver\ORM\Query\Filter\QueryFilterRegistry;

final class QueryFilterRegistryRuntimeTest extends TestCase
{
    private function createFilter(string $entityClass, string $clause): QueryFilterInterface
    {
        return new class($entityClass, $clause) implements QueryFilterInterface {
            public function __construct(
                private readonly string $entityClass,
                private readonly string $clause,
            ) {
            }

            public function supports(string $entityClass): bool
            {
                return $entityClass === $this->entityClass;
            }

            public function apply(EntityQueryBuilder $qb): void
            {
            }

            public function getClause(): string
            {
                return $this->clause;
            }
        };
    }

    public function test_disable_filter_excludes_from_queries(): void
    {
        $registry = new QueryFilterRegistry();
        $filter = $this->createFilter('App\\Entity\\Post', 'active = 1');

        $registry->register('active', $filter);
        $registry->disable('active');

        self::assertSame([], $registry->getFiltersFor('App\\Entity\\Post'));
    }

    public function test_enable_filter_reactivates(): void
    {
        $registry = new QueryFilterRegistry();
        $filter = $this->createFilter('App\\Entity\\Post', 'active = 1');

        $registry->register('active', $filter);
        $registry->disable('active');
        $registry->enable('active');

        self::assertCount(1, $registry->getFiltersFor('App\\Entity\\Post'));
    }

    public function test_isEnabled_returns_correct_state(): void
    {
        $registry = new QueryFilterRegistry();
        $filter = $this->createFilter('App\\Entity\\Post', 'active = 1');

        $registry->register('active', $filter);
        self::assertTrue($registry->isEnabled('active'));

        $registry->disable('active');
        self::assertFalse($registry->isEnabled('active'));

        $registry->enable('active');
        self::assertTrue($registry->isEnabled('active'));
    }

    public function test_getEnabledFilters_returns_only_enabled(): void
    {
        $registry = new QueryFilterRegistry();
        $filterA = $this->createFilter('App\\Entity\\Post', 'active = 1');
        $filterB = $this->createFilter('App\\Entity\\Post', 'published = 1');
        $filterC = $this->createFilter('App\\Entity\\Post', 'visible = 1');

        $registry->register('active', $filterA);
        $registry->register('published', $filterB);
        $registry->register('visible', $filterC);

        $registry->disable('published');

        $enabled = $registry->getEnabledFilters();

        self::assertCount(2, $enabled);
        self::assertArrayHasKey('active', $enabled);
        self::assertArrayHasKey('visible', $enabled);
        self::assertArrayNotHasKey('published', $enabled);
    }
}
