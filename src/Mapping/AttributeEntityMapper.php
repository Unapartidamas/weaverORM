<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

final class AttributeEntityMapper extends AbstractEntityMapper
{

    public function __construct(
        private readonly string $entityClass,
        private readonly string $tableName,
        private readonly array $columns,
        private readonly array $relations,
        private readonly array $indexes = [],
        private readonly array $embedded = [],
        private readonly ?ExpiryDefinition $expiry = null,
    ) {}

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getRelations(): array
    {
        return $this->relations;
    }

    public function getIndexes(): array
    {
        return $this->indexes;
    }

    public function getEmbedded(): array
    {
        return $this->embedded;
    }

    public function getExpiry(): ?ExpiryDefinition
    {
        return $this->expiry;
    }

    public function getPrimaryKeyColumns(): array
    {
        $pkColumns = [];

        foreach ($this->columns as $col) {
            if ($col->isPrimary()) {
                $pkColumns[] = $col->getColumn();
            }
        }

        return $pkColumns !== [] ? $pkColumns : [$this->getPrimaryKey()];
    }

    public function getInheritanceMapping(): ?InheritanceMapping
    {
        static $cache = [];
        $class = $this->getEntityClass();

        if (!array_key_exists($class, $cache)) {
            $cache[$class] = (new AttributeMapperFactory())->buildInheritanceMapping($class);
        }

        return $cache[$class];
    }

    public function getInheritanceJoinTable(): ?string
    {
        $class = $this->getEntityClass();
        $ref   = new \ReflectionClass($class);

        if ($ref->getAttributes(\Weaver\ORM\Mapping\Attribute\Inheritance::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
            return null;
        }

        $entityAttrs = $ref->getAttributes(\Weaver\ORM\Mapping\Attribute\Entity::class);

        if ($entityAttrs === []) {
            return null;
        }

        $entityAttr = $entityAttrs[0]->newInstance();

        return $entityAttr->table;
    }

    public function getInheritanceJoinKey(): string
    {
        return 'id';
    }

    public function setProperty(object $entity, string $property, mixed $value): void
    {
        if (str_contains($property, '.')) {
            $parts = explode('.', $property);
            $target = $entity;

            for ($i = 0; $i < count($parts) - 1; $i++) {
                $target = $target->{$parts[$i]} ?? null;
                if ($target === null) {
                    return;
                }
            }

            $target->{$parts[count($parts) - 1]} = $value;
            return;
        }

        parent::setProperty($entity, $property, $value);
    }

    public function getProperty(object $entity, string $property): mixed
    {
        if (str_contains($property, '.')) {
            $parts = explode('.', $property);
            $target = $entity;

            for ($i = 0; $i < count($parts) - 1; $i++) {
                $target = $target->{$parts[$i]} ?? null;
                if ($target === null) {
                    return null;
                }
            }

            return $target->{$parts[count($parts) - 1]};
        }

        return parent::getProperty($entity, $property);
    }
}
