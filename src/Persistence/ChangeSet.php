<?php

declare(strict_types=1);

namespace Weaver\ORM\Persistence;

final readonly class ChangeSet
{

    public function __construct(
        private object $entity,
        private string $entityClass,
        private array $changes,
        private array $originalValues,
    ) {}

    public function getEntity(): object
    {
        return $this->entity;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getOriginalValues(): array
    {
        return $this->originalValues;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function hasChange(string $column): bool
    {
        return array_key_exists($column, $this->changes);
    }

    public function getNewValue(string $column): mixed
    {
        return $this->changes[$column] ?? null;
    }

    public function getOldValue(string $column): mixed
    {
        return $this->originalValues[$column] ?? null;
    }
}
