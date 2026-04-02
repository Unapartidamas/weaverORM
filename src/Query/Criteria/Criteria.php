<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Criteria;

final class Criteria
{
    private const SUPPORTED_OPERATORS = [
        '=', '!=', '<', '>', '<=', '>=',
        'IN', 'NOT IN', 'LIKE', 'IS NULL', 'IS NOT NULL',
    ];

    private array $expressions = [];

    private array $orderings = [];

    private ?int $maxResults = null;

    private ?int $firstResult = null;

    public function where(string $field, string $operator, mixed $value = null): static
    {
        $this->validateOperator($operator);
        $this->expressions = [new Expression($field, $operator, $value, 'AND')];

        return $this;
    }

    public function andWhere(string $field, string $operator, mixed $value = null): static
    {
        $this->validateOperator($operator);
        $this->expressions[] = new Expression($field, $operator, $value, 'AND');

        return $this;
    }

    public function orWhere(string $field, string $operator, mixed $value = null): static
    {
        $this->validateOperator($operator);
        $this->expressions[] = new Expression($field, $operator, $value, 'OR');

        return $this;
    }

    public function orderBy(string $field, string $direction = 'ASC'): static
    {
        $this->orderings[] = ['field' => $field, 'direction' => strtoupper($direction)];

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->maxResults = $limit;

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->firstResult = $offset;

        return $this;
    }

    public function getExpressions(): array
    {
        return $this->expressions;
    }

    public function getOrderings(): array
    {
        return $this->orderings;
    }

    public function getLimit(): ?int
    {
        return $this->maxResults;
    }

    public function getOffset(): ?int
    {
        return $this->firstResult;
    }

    private function validateOperator(string $operator): void
    {
        if (!in_array(strtoupper($operator), self::SUPPORTED_OPERATORS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported operator "%s".', $operator));
        }
    }
}
