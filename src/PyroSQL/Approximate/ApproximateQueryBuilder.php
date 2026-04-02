<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Approximate;

use Weaver\ORM\Query\EntityQueryBuilder;

final class ApproximateQueryBuilder
{
    private bool $fallbackEnabled = false;

    public function __construct(
        private readonly EntityQueryBuilder $inner,
        private readonly \Weaver\ORM\DBAL\Connection $connection,
        private readonly float $within = 5.0,
        private readonly float $confidence = 95.0,
    ) {}

    public function within(float $percent): static
    {
        return new self($this->inner, $this->connection, $percent, $this->confidence);
    }

    public function confidence(float $percent): static
    {
        return new self($this->inner, $this->connection, $this->within, $percent);
    }

    public function withFallback(): static
    {
        $clone                  = clone $this;
        $clone->fallbackEnabled = true;

        return $clone;
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

    public function whereRaw(string $expression, array $bindings = []): static
    {
        $this->inner->whereRaw($expression, $bindings);

        return $this;
    }

    public function count(): ApproximateResult
    {
        return $this->executeApproximate('COUNT(*)');
    }

    public function sum(string $column): ApproximateResult
    {
        return $this->executeApproximate("SUM({$column})");
    }

    public function avg(string $column): ApproximateResult
    {
        return $this->executeApproximate("AVG({$column})");
    }

    private function executeApproximate(string $aggregate): ApproximateResult
    {
        $sql    = $this->buildApproximateSQL($aggregate);
        $params = $this->extractParameters();
        $types  = $this->extractParameterTypes();

        try {
            $row = $this->connection->fetchAssociative($sql, $params);
        } catch (\Throwable $e) {
            if ($this->fallbackEnabled) {
                return $this->executeFallback($aggregate);
            }

            throw $e;
        }

        if ($row === false) {
            return new ApproximateResult(
                value:         0,
                errorMargin:   0.0,
                confidence:    100.0,
                sampledRows:   0,
                totalRows:     0,
                isApproximate: false,
            );
        }

        if (isset($row['_vk_is_approximate'])) {
            return $this->parseApproximateResult($row);
        }

        $value = reset($row);

        return new ApproximateResult(
            value:         $value,
            errorMargin:   0.0,
            confidence:    100.0,
            sampledRows:   is_numeric($row['_vk_sampled_rows'] ?? 0) ? (int) ($row['_vk_sampled_rows'] ?? 0) : 0,
            totalRows:     is_numeric($row['_vk_total_rows']   ?? 0) ? (int) ($row['_vk_total_rows']   ?? 0) : 0,
            isApproximate: false,
        );
    }

    private function executeFallback(string $aggregate): ApproximateResult
    {
        $innerSql = $this->inner->toSQL();

        $fallbackSql = preg_replace(
            '/^(\/\*.*?\*\/\s*)?SELECT\s+.*?\s+FROM\s/si',
            '$1SELECT ' . $aggregate . ' AS _value FROM ',
            $innerSql,
            1,
        ) ?? $innerSql;

        $params = $this->extractParameters();
        $types  = $this->extractParameterTypes();

        $row = $this->connection->fetchAssociative($fallbackSql, $params);

        $value = $row !== false ? ($row['_value'] ?? reset($row)) : 0;

        return new ApproximateResult(
            value:         $value,
            errorMargin:   0.0,
            confidence:    100.0,
            sampledRows:   0,
            totalRows:     0,
            isApproximate: false,
        );
    }

    private function buildApproximateSQL(string $aggregate): string
    {
        $innerSql = $this->inner->toSQL();

        $approxSelect = sprintf(
            'SELECT APPROXIMATE %s WITHIN %s%% CONFIDENCE %s%%',
            $aggregate,
            $this->within,
            $this->confidence,
        );

        return preg_replace(
            '/^(\/\*.*?\*\/\s*)?SELECT\s+.*?\s+FROM\s/si',
            $approxSelect . ' FROM ',
            $innerSql,
            1,
        ) ?? $innerSql;
    }

    private function parseApproximateResult(array $row): ApproximateResult
    {

        $value = null;

        foreach ($row as $key => $val) {
            if (!str_starts_with((string) $key, '_vk_')) {
                $value = $val;
                break;
            }
        }

        return new ApproximateResult(
            value:         $value,
            errorMargin:   (float) ($row['_vk_error_margin']  ?? 0.0),
            confidence:    (float) ($row['_vk_confidence']    ?? $this->confidence),
            sampledRows:   (int)   ($row['_vk_sampled_rows']  ?? 0),
            totalRows:     (int)   ($row['_vk_total_rows']    ?? 0),
            isApproximate: (bool)  ($row['_vk_is_approximate'] ?? true),
        );
    }

    private function extractParameters(): array
    {
        return $this->inner->getDbalQueryBuilder()->getParameters();
    }

    private function extractParameterTypes(): array
    {
        return $this->inner->getDbalQueryBuilder()->getParameterTypes();
    }
}
