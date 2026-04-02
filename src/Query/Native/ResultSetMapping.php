<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Native;

final class ResultSetMapping
{

    private array $entities = [];

    private array $joinedEntityParentProperty = [];

    private array $fieldMappings = [];

    private ?string $rootAlias = null;

    public function addRootEntity(string $entityClass, string $alias): static
    {
        $this->entities[$alias] = $entityClass;
        $this->rootAlias        = $alias;
        return $this;
    }

    public function addJoinedEntity(
        string $entityClass,
        string $alias,
        string $parentAlias,
        string $parentProperty,
    ): static {
        $this->entities[$alias]                  = $entityClass;
        $this->joinedEntityParentProperty[$alias] = $parentProperty;
        return $this;
    }

    public function addFieldMapping(string $alias, string $column, string $property): static
    {
        $this->fieldMappings[$alias][$column] = $property;
        return $this;
    }

    public function getRootEntityClass(): string
    {
        if ($this->rootAlias === null) {
            throw new \LogicException('No root entity registered. Call addRootEntity() first.');
        }
        return $this->entities[$this->rootAlias];
    }

    public function getRootAlias(): string
    {
        return $this->rootAlias ?? throw new \LogicException('No root entity registered.');
    }

    public function getEntities(): array { return $this->entities; }

    public function getJoinedEntityParentProperties(): array { return $this->joinedEntityParentProperty; }

    public function getFieldMappings(string $alias): array { return $this->fieldMappings[$alias] ?? []; }

    public function isJoinedAlias(string $alias): bool { return isset($this->joinedEntityParentProperty[$alias]); }
}
