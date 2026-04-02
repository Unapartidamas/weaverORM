<?php

declare(strict_types=1);

namespace Weaver\ORM\Hydration;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\Platform;
use Weaver\ORM\DBAL\Type\Type;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Contract\HydratorInterface;
use Weaver\ORM\Exception\HydrationException;
use Weaver\ORM\Lifecycle\EntityLifecycleInvoker;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\Attribute\Entity as EntityAttribute;
use Weaver\ORM\Mapping\Attribute\AfterLoad;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Proxy\EntityProxyLoader;

final class EntityHydrator implements HydratorInterface
{
    private ?Platform $platformCache = null;

    private array $typeCache = [];

    private array $mapperCache = [];

    private array $columnCache = [];

    private array $entityAttrCache = [];

    public function __construct(
        private readonly MapperRegistry $registry,
        private readonly Connection $connection,
        private readonly ?EntityProxyLoader $proxyLoader = null,
        private readonly EntityLifecycleInvoker $lifecycleInvoker = new EntityLifecycleInvoker(),
    ) {}

    public function hydrate(string $entityClass, array $row): object
    {
        $mapper = $this->getMapper($entityClass);
        $platform = $this->getPlatform();

        $instantiationClass = $entityClass;
        $im = $mapper->getInheritanceMapping();

        if ($im !== null && array_key_exists($im->discriminatorColumn, $row)) {
            $discriminatorValue = $row[$im->discriminatorColumn];
            $resolvedClass = $im->resolveClass($discriminatorValue);
            if ($resolvedClass !== null) {
                $instantiationClass = $resolvedClass;
            }
        }

        if ($this->proxyLoader !== null && $this->hasEntityAttribute($instantiationClass)) {
            $proxyClass = $this->proxyLoader->getProxyClass($instantiationClass);
            $entity = new $proxyClass();
        } elseif ($instantiationClass !== $entityClass) {
            $entity = new $instantiationClass();
        } else {
            $entity = $mapper->newInstance();
        }

        foreach ($this->getColumns($entityClass, $mapper) as [$column, $property, $typeName, $nullable, $enumClass, $typeObj]) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $raw = $row[$column];

            if ($raw === null) {
                $mapper->setProperty($entity, $property, null);
                continue;
            }

            try {
                $phpValue = $typeObj->convertToPHPValue($raw, $platform);
            } catch (\Throwable $e) {
                if ($nullable) {
                    $phpValue = null;
                } else {
                    throw new HydrationException(
                        sprintf(
                            'Hydration failed for entity "%s", column "%s" (type "%s"): %s',
                            $entityClass,
                            $column,
                            $typeName,
                            $e->getMessage(),
                        ),
                        previous: $e,
                    );
                }
            }

            if ($phpValue !== null && is_string($phpValue)) {
                $phpValue = match ($typeName) {
                    'float', 'double' => (float) $phpValue,
                    'integer', 'smallint', 'bigint' => (int) $phpValue,
                    'boolean' => (bool) $phpValue,
                    default => $phpValue,
                };
            }

            if ($enumClass !== null && $phpValue !== null) {
                $scalarValue = is_int($phpValue) ? $phpValue : (is_scalar($phpValue) ? (string) $phpValue : '');
                $phpValue = $enumClass::from($scalarValue);
            }

            $mapper->setProperty($entity, $property, $phpValue);
        }

        if (property_exists($entity, '__weaverLoader')) {
            $entity->__weaverLoader = function (string $relation, object $ent) use ($entityClass): mixed {
                return $this->loadOneRelation($ent, $relation, $entityClass);
            };
        }

        if ($mapper instanceof AbstractEntityMapper) {
            $this->hydrateEmbedded($entity, $row, $mapper);
        }

        $this->lifecycleInvoker->invoke($entity, AfterLoad::class);

        return $entity;
    }

    public function hydrateMany(string $entityClass, array $rows): EntityCollection
    {
        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->hydrate($entityClass, $row);
        }
        return new EntityCollection($entities);
    }

    public function extract(object $entity, string $entityClass): array
    {
        $mapper = $this->getMapper($entityClass);
        $platform = $this->getPlatform();
        $result = [];

        foreach ($mapper->getPersistableColumns() as $colDef) {
            $value = $mapper->getProperty($entity, $colDef->getProperty());

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            if ($value === null) {
                $result[$colDef->getColumn()] = null;
                continue;
            }

            $result[$colDef->getColumn()] = $this->getType($colDef->getType())
                ->convertToDatabaseValue($value, $platform);
        }

        return $result;
    }

    public function extractChangeset(object $entity, string $entityClass, array $snapshot): array
    {
        $current = $this->extract($entity, $entityClass);
        return array_diff_assoc($current, $snapshot);
    }

    private function getPlatform(): Platform
    {
        return $this->platformCache ??= $this->connection->getDatabasePlatform();
    }

    private function getMapper(string $entityClass): AbstractEntityMapper
    {
        return $this->mapperCache[$entityClass] ??= $this->registry->get($entityClass);
    }

    private function getType(string $typeName): Type
    {
        return $this->typeCache[$typeName] ??= Type::getType($typeName);
    }

    private function getColumns(string $entityClass, AbstractEntityMapper $mapper): array
    {
        if (isset($this->columnCache[$entityClass])) {
            return $this->columnCache[$entityClass];
        }

        $cols = [];
        foreach ($mapper->getColumns() as $colDef) {
            $cols[] = [
                $colDef->getColumn(),
                $colDef->getProperty(),
                $colDef->getType(),
                $colDef->isNullable(),
                $colDef->getEnumClass(),
                $this->getType($colDef->getType()),
            ];
        }

        return $this->columnCache[$entityClass] = $cols;
    }

    private function hasEntityAttribute(string $entityClass): bool
    {
        return $this->entityAttrCache[$entityClass] ??= (new \ReflectionClass($entityClass))->getAttributes(EntityAttribute::class) !== [];
    }

    private function loadOneRelation(object $entity, string $relationName, string $entityClass): mixed
    {
        return null;
    }

    private function hydrateEmbedded(object $entity, array $row, AbstractEntityMapper $mapper): void
    {
        foreach ($mapper->getEmbedded() as $embeddedDef) {
            $embeddable = $this->hydrateOneEmbedded($embeddedDef, $row);
            $entity->{$embeddedDef->property} = $embeddable;
        }
    }

    private function hydrateOneEmbedded(\Weaver\ORM\Mapping\EmbeddedDefinition $embeddedDef, array $row): object
    {
        $ref = new \ReflectionClass($embeddedDef->embeddableClass);
        $embeddable = $ref->newInstanceWithoutConstructor();

        foreach ($embeddedDef->columns as $colDef) {
            $columnName = $colDef->getColumn();
            if (!array_key_exists($columnName, $row)) {
                continue;
            }
            $embeddable->{$colDef->getProperty()} = $row[$columnName];
        }

        foreach ($embeddedDef->nestedEmbeddables as $nestedDef) {
            $nested = $this->hydrateOneEmbedded($nestedDef, $row);
            $embeddable->{$nestedDef->property} = $nested;
        }

        return $embeddable;
    }
}
