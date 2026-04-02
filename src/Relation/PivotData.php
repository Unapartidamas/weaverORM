<?php

declare(strict_types=1);

namespace Weaver\ORM\Relation;

final readonly class PivotData
{

    public function __construct(private array $data) {}

    public function get(string $column, mixed $default = null): mixed
    {
        return array_key_exists($column, $this->data) ? $this->data[$column] : $default;
    }

    public function has(string $column): bool
    {
        return array_key_exists($column, $this->data);
    }

    public function all(): array
    {
        return $this->data;
    }
}
