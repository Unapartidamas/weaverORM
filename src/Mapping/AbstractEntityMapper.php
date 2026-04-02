<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

use Weaver\ORM\Contract\EntityMapperInterface;

abstract class AbstractEntityMapper implements EntityMapperInterface
{

    private ?array $columnsByProperty = null;

    private ?array $columnsByName = null;

    private ?array $writableColumns = null;

    private ?array $persistableColumns = null;

    private ?array $relationsByProperty = null;

    abstract public function getEntityClass(): string;

    abstract public function getTableName(): string;

    abstract public function getColumns(): array;

    public function getRelations(): array
    {
        return [];
    }

    public function getSchema(): ?string
    {
        return null;
    }

    public function getPrimaryKey(): string
    {
        foreach ($this->getColumns() as $column) {
            if ($column->isPrimary()) {
                return $column->getColumn();
            }
        }

        return 'id';
    }

    public function getPrimaryKeyColumns(): array
    {
        return [$this->getPrimaryKey()];
    }

    public function isComposite(): bool
    {
        return count($this->getPrimaryKeyColumns()) > 1;
    }

    public function extractCompositeKey(object $entity): CompositeKey
    {
        $values = [];

        foreach ($this->getColumns() as $col) {
            if ($col->isPrimary()) {
                $values[$col->getColumn()] = $this->getProperty($entity, $col->getProperty());
            }
        }

        return new CompositeKey($values);
    }

    public function getIndexes(): array
    {
        return [];
    }

    public function getEmbedded(): array
    {
        return [];
    }

    public function getInheritanceMapping(): ?InheritanceMapping
    {
        return null;
    }

    public function getInheritanceJoinTable(): ?string
    {
        return null;
    }

    public function getInheritanceJoinKey(): string
    {
        return 'id';
    }

    public function getOwnColumns(): array
    {
        return $this->getColumns();
    }

    public function newInstance(): object
    {
        $class = $this->getEntityClass();

        return new $class();
    }

    public function setProperty(object $entity, string $property, mixed $value): void
    {
        try {
            $entity->$property = $value;
        } catch (\TypeError $e) {

            if ($value instanceof \Weaver\ORM\Collection\EntityCollection) {
                $entity->$property = $value->toArray();
            } else {
                throw $e;
            }
        }
    }

    public function getProperty(object $entity, string $property): mixed
    {
        return $entity->$property;
    }

    public function getColumn(string $property): ?ColumnDefinition
    {
        return $this->buildColumnsByProperty()[$property] ?? null;
    }

    public function getColumnByName(string $column): ?ColumnDefinition
    {
        return $this->buildColumnsByName()[$column] ?? null;
    }

    public function getRelation(string $property): ?RelationDefinition
    {
        return $this->buildRelationsByProperty()[$property] ?? null;
    }

    public function getWritableColumns(): array
    {
        if ($this->writableColumns !== null) {
            return $this->writableColumns;
        }

        $this->writableColumns = array_values(
            array_filter(
                $this->getColumns(),
                static fn (ColumnDefinition $col): bool => !$col->isGenerated() && !$col->isVirtual(),
            )
        );

        return $this->writableColumns;
    }

    public function getPersistableColumns(): array
    {
        if ($this->persistableColumns !== null) {
            return $this->persistableColumns;
        }

        $this->persistableColumns = array_values(
            array_filter(
                $this->getWritableColumns(),
                static fn (ColumnDefinition $col): bool => !($col->isPrimary() && $col->isAutoIncrement()),
            )
        );

        return $this->persistableColumns;
    }

    private function buildColumnsByProperty(): array
    {
        if ($this->columnsByProperty !== null) {
            return $this->columnsByProperty;
        }

        $this->columnsByProperty = [];

        foreach ($this->getColumns() as $column) {
            $this->columnsByProperty[$column->getProperty()] = $column;
        }

        return $this->columnsByProperty;
    }

    private function buildColumnsByName(): array
    {
        if ($this->columnsByName !== null) {
            return $this->columnsByName;
        }

        $this->columnsByName = [];

        foreach ($this->getColumns() as $column) {
            $this->columnsByName[$column->getColumn()] = $column;
        }

        return $this->columnsByName;
    }

    private function buildRelationsByProperty(): array
    {
        if ($this->relationsByProperty !== null) {
            return $this->relationsByProperty;
        }

        $this->relationsByProperty = [];

        foreach ($this->getRelations() as $relation) {
            $this->relationsByProperty[$relation->getProperty()] = $relation;
        }

        return $this->relationsByProperty;
    }
}
