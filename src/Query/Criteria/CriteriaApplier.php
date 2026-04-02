<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Criteria;

use Weaver\ORM\Query\EntityQueryBuilder;

final class CriteriaApplier
{
    public function apply(Criteria $criteria, EntityQueryBuilder $qb): EntityQueryBuilder
    {
        foreach ($criteria->getExpressions() as $expression) {
            $operator = strtoupper($expression->operator);

            if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
                if ($expression->boolean === 'OR') {
                    $qb->orWhere($expression->field . ' ' . $operator);
                } else {
                    $qb->where($expression->field . ' ' . $operator);
                }
                continue;
            }

            if ($operator === 'IN' || $operator === 'NOT IN') {
                if ($expression->boolean === 'OR') {
                    $qb->orWhere($expression->field, $operator, $expression->value);
                } else {
                    $qb->where($expression->field, $operator, $expression->value);
                }
                continue;
            }

            if ($expression->boolean === 'OR') {
                $qb->orWhere($expression->field, $operator, $expression->value);
            } else {
                $qb->where($expression->field, $operator, $expression->value);
            }
        }

        foreach ($criteria->getOrderings() as $ordering) {
            $qb->orderBy($ordering['field'], $ordering['direction']);
        }

        if ($criteria->getLimit() !== null) {
            $qb->limit($criteria->getLimit());
        }

        if ($criteria->getOffset() !== null) {
            $qb->offset($criteria->getOffset());
        }

        return $qb;
    }
}
