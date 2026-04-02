<?php

declare(strict_types=1);

namespace Weaver\ORM\Contract;

interface EntityMapperInterface
{

    public function getEntityClass(): string;

    public function getTableName(): string;

    public function getSchema(): ?string;

    public function getColumns(): array;

    public function getRelations(): array;

    public function getPrimaryKey(): string;

    public function newInstance(): object;

    public function setProperty(object $entity, string $property, mixed $value): void;

    public function getProperty(object $entity, string $property): mixed;

    public function getColumnByName(string $column): ?\Weaver\ORM\Mapping\ColumnDefinition;

    public function getRelation(string $property): ?\Weaver\ORM\Mapping\RelationDefinition;

    public function getIndexes(): array;

    public function getPersistableColumns(): array;
}
