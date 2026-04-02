<?php

declare(strict_types=1);

namespace Weaver\ORM\Contract;

use Weaver\ORM\Collection\EntityCollection;

interface QueryBuilderInterface
{

    public function where(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static;

    public function orWhere(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static;

    public function orderBy(string $column, string $direction = 'ASC'): static;

    public function limit(int $limit): static;

    public function offset(int $offset): static;

    public function get(array $with = []): EntityCollection;

    public function first(array $with = []): ?object;

    public function count(?string $column = null): int;

    public function exists(): bool;

    public function toSQL(): string;
}
