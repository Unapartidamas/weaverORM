<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query\Criteria;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Query\Criteria\Criteria;

final class CriteriaTest extends TestCase
{
    public function test_where_creates_expression(): void
    {
        $criteria = (new Criteria())->where('status', '=', 'active');

        $expressions = $criteria->getExpressions();

        self::assertCount(1, $expressions);
        self::assertSame('status', $expressions[0]->field);
        self::assertSame('=', $expressions[0]->operator);
        self::assertSame('active', $expressions[0]->value);
        self::assertSame('AND', $expressions[0]->boolean);
    }

    public function test_andWhere_adds_expression(): void
    {
        $criteria = (new Criteria())
            ->where('status', '=', 'active')
            ->andWhere('age', '>', 18);

        $expressions = $criteria->getExpressions();

        self::assertCount(2, $expressions);
        self::assertSame('age', $expressions[1]->field);
        self::assertSame('>', $expressions[1]->operator);
        self::assertSame(18, $expressions[1]->value);
        self::assertSame('AND', $expressions[1]->boolean);
    }

    public function test_orWhere_adds_or_expression(): void
    {
        $criteria = (new Criteria())
            ->where('status', '=', 'active')
            ->orWhere('role', '=', 'admin');

        $expressions = $criteria->getExpressions();

        self::assertCount(2, $expressions);
        self::assertSame('role', $expressions[1]->field);
        self::assertSame('=', $expressions[1]->operator);
        self::assertSame('admin', $expressions[1]->value);
        self::assertSame('OR', $expressions[1]->boolean);
    }

    public function test_orderBy_sets_ordering(): void
    {
        $criteria = (new Criteria())
            ->orderBy('name', 'ASC')
            ->orderBy('created_at', 'DESC');

        $orderings = $criteria->getOrderings();

        self::assertCount(2, $orderings);
        self::assertSame('name', $orderings[0]['field']);
        self::assertSame('ASC', $orderings[0]['direction']);
        self::assertSame('created_at', $orderings[1]['field']);
        self::assertSame('DESC', $orderings[1]['direction']);
    }

    public function test_limit_and_offset(): void
    {
        $criteria = (new Criteria())
            ->limit(25)
            ->offset(50);

        self::assertSame(25, $criteria->getLimit());
        self::assertSame(50, $criteria->getOffset());
    }

    public function test_getExpressions_returns_all(): void
    {
        $criteria = (new Criteria())
            ->where('a', '=', 1)
            ->andWhere('b', '!=', 2)
            ->orWhere('c', 'LIKE', '%test%')
            ->andWhere('d', 'IN', [1, 2, 3])
            ->andWhere('e', 'IS NULL', null);

        self::assertCount(5, $criteria->getExpressions());
    }
}
