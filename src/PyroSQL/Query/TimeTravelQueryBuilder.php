<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Query;

use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Pagination\Page;
use Weaver\ORM\Query\EntityQueryBuilder;

final class TimeTravelQueryBuilder
{

    private ?string $asOfExpression = null;

    public function __construct(
        private EntityQueryBuilder $inner,
        private readonly string $tableName,
    ) {}

    public function asOf(\DateTimeImmutable $timestamp): static
    {
        $clone                 = clone $this;
        $clone->asOfExpression = "AS OF TIMESTAMP '" . $timestamp->format('Y-m-d H:i:s') . "'";

        return $clone;
    }

    public function asOfVersion(int $lsn): static
    {
        $clone                 = clone $this;
        $clone->asOfExpression = "AS OF LSN {$lsn}";

        return $clone;
    }

    public function current(): static
    {
        $clone                 = clone $this;
        $clone->asOfExpression = null;

        return $clone;
    }

    public function getAsOfExpression(): ?string
    {
        return $this->asOfExpression;
    }

    public function toSQL(): string
    {
        $sql = $this->inner->toSQL();

        if ($this->asOfExpression === null) {
            return $sql;
        }

        $pattern = '/\bFROM\s+' . preg_quote($this->tableName, '/') . '\s+(\w+)/';

        return preg_replace_callback(
            $pattern,
            fn (array $m): string => $m[0] . ' ' . $this->asOfExpression,
            $sql,
            1,
        ) ?? $sql;
    }

    public function where(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        $this->inner->where($column, $operatorOrValue, $value);

        return $this;
    }

    public function orWhere(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        $this->inner->orWhere($column, $operatorOrValue, $value);

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->inner->whereNull($column);

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->inner->whereNotNull($column);

        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        $this->inner->whereIn($column, $values);

        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        $this->inner->whereNotIn($column, $values);

        return $this;
    }

    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $this->inner->whereBetween($column, $min, $max);

        return $this;
    }

    public function whereRaw(string $expression, array $bindings = []): static
    {
        $this->inner->whereRaw($expression, $bindings);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->inner->orderBy($column, $direction);

        return $this;
    }

    public function orderByDesc(string $column): static
    {
        $this->inner->orderByDesc($column);

        return $this;
    }

    public function orderByRaw(string $expression): static
    {
        $this->inner->orderByRaw($expression);

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->inner->limit($limit);

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->inner->offset($offset);

        return $this;
    }

    public function select(string ...$columns): static
    {
        $this->inner->select(...$columns);

        return $this;
    }

    public function addSelect(string ...$columns): static
    {
        $this->inner->addSelect(...$columns);

        return $this;
    }

    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->inner->selectRaw($expression, $bindings);

        return $this;
    }

    public function with(string|array ...$relations): static
    {
        $this->inner->with(...$relations);

        return $this;
    }

    public function withTrashed(): static
    {
        $this->inner->withTrashed();

        return $this;
    }

    public function onlyTrashed(): static
    {
        $this->inner->onlyTrashed();

        return $this;
    }

    public function withoutTrashed(): static
    {
        $this->inner->withoutTrashed();

        return $this;
    }

    public function setParameter(string $key, mixed $value, \Weaver\ORM\DBAL\ParameterType|string|null $type = null): static
    {
        $this->inner->setParameter($key, $value, $type);

        return $this;
    }

    public function comment(string $text): static
    {
        $this->inner->comment($text);

        return $this;
    }

    public function get(array $with = []): EntityCollection
    {
        if ($this->asOfExpression === null) {
            return $this->inner->get($with);
        }

        $result = $this->executeWithAsOf(fn (): EntityCollection => $this->inner->get($with));
        assert($result instanceof EntityCollection);

        return $result;
    }

    public function first(array $with = []): ?object
    {
        if ($this->asOfExpression === null) {
            return $this->inner->first($with);
        }

        $result = $this->executeWithAsOf(fn (): ?object => $this->inner->first($with));

        return is_object($result) ? $result : null;
    }

    public function firstOrFail(array $with = []): object
    {
        if ($this->asOfExpression === null) {
            return $this->inner->firstOrFail($with);
        }

        $result = $this->executeWithAsOf(fn (): object => $this->inner->firstOrFail($with));
        assert(is_object($result));

        return $result;
    }

    public function count(?string $column = null): int
    {
        if ($this->asOfExpression === null) {
            return $this->inner->count($column);
        }

        $result = $this->executeWithAsOf(fn (): int => $this->inner->count($column));

        return is_int($result) ? $result : (is_numeric($result) ? (int) $result : 0);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function paginate(int $page = 1, int $perPage = 15, array $with = []): Page
    {
        if ($this->asOfExpression === null) {
            return $this->inner->paginate($page, $perPage, $with);
        }

        $result = $this->executeWithAsOf(fn (): Page => $this->inner->paginate($page, $perPage, $with));
        assert($result instanceof Page);

        return $result;
    }

    private function executeWithAsOf(callable $callback): mixed
    {

        $this->inner->setParameter('__vk_as_of__', $this->asOfExpression ?? '');
        $connection = $this->getInnerConnection();

        try {
            $connection->executeStatement(
                "SET LOCAL pyrosql.as_of_expr = " . $connection->quote((string) $this->asOfExpression)
            );

            return $callback();
        } finally {
            try {
                $connection->executeStatement("RESET pyrosql.as_of_expr");
            } catch (\Throwable) {

            }
        }
    }

    private function getInnerConnection(): \Weaver\ORM\DBAL\Connection
    {
        return $this->inner->getConnection();
    }

    public function __clone(): void
    {
        $this->inner = clone $this->inner;
    }
}
