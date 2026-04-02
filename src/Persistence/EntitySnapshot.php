<?php

declare(strict_types=1);

namespace Weaver\ORM\Persistence;

final readonly class EntitySnapshot
{

    public function __construct(
        private string $entityClass,
        private array $data,
    ) {}

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function diff(array $current): array
    {
        return array_diff_assoc($current, $this->data);
    }

    public function has(string $column): bool
    {
        return array_key_exists($column, $this->data);
    }

    public function get(string $column): mixed
    {
        return $this->data[$column] ?? null;
    }
}
