<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

use Weaver\ORM\Mapping\Attribute\BelongsTo;
use Weaver\ORM\Mapping\Attribute\Inheritance;
use Weaver\ORM\Mapping\Attribute\TypeColumn;
use Weaver\ORM\Mapping\Attribute\TypeMap;
use Weaver\ORM\Mapping\Attribute\BelongsToMany;
use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Embeddable;
use Weaver\ORM\Mapping\Attribute\Embedded;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\HasMany;
use Weaver\ORM\Mapping\Attribute\HasOne;
use Weaver\ORM\Mapping\Attribute\Id;
use Weaver\ORM\Mapping\Attribute\SoftDeletes;
use Weaver\ORM\Mapping\Attribute\Timestamps;
use Weaver\ORM\Mapping\Attribute\UseUuid;
use Weaver\ORM\Mapping\Attribute\UseUuidV7;
use Weaver\ORM\Mapping\Attribute\Version;

final class AttributeMapperFactory
{

    public function build(string $entityClass): AttributeEntityMapper
    {
        $classRef = new \ReflectionClass($entityClass);

        $entityAttrs = $classRef->getAttributes(Entity::class);

        if ($entityAttrs === []) {
            throw new \InvalidArgumentException(
                sprintf('Class "%s" has no #[Entity] attribute.', $entityClass),
            );
        }

        $entityAttr = $entityAttrs[0]->newInstance();
        $tableName  = $entityAttr->table;

        $hasSoftDeletes = $classRef->getAttributes(SoftDeletes::class) !== [];
        $hasTimestamps  = $classRef->getAttributes(Timestamps::class) !== [];

        $columns   = [];
        $relations = [];
        $embedded  = [];

        foreach ($classRef->getProperties() as $propRef) {
            $propName = $propRef->getName();

            $idAttrs = $propRef->getAttributes(Id::class);
            if ($idAttrs !== []) {

                $idAttr = $idAttrs[0]->newInstance();

                // Auto-detect UUID: if property is typed as ?string or string,
                // use 'guid' type and disable autoIncrement unless explicitly set.
                $idType = $idAttr->type;
                $idAutoIncrement = $idAttr->autoIncrement;
                $propType = $propRef->getType();
                if ($propType instanceof \ReflectionNamedType) {
                    $typeName = $propType->getName();
                    if ($typeName === 'string' && $idType === 'integer') {
                        $idType = 'guid';
                        $idAutoIncrement = false;
                    }
                }

                $columns[] = new ColumnDefinition(
                    column:        $this->toSnakeCase($propName),
                    property:      $propName,
                    type:          $idType,
                    primary:       true,
                    autoIncrement: $idAutoIncrement,
                );
                continue;
            }

            $colAttrs = $propRef->getAttributes(Column::class);
            if ($colAttrs !== []) {

                $colAttr    = $colAttrs[0]->newInstance();
                $columnName = $colAttr->name ?? $this->toSnakeCase($propName);

                $uuidType = $this->resolveUuidType($propRef);

                $isVersion = $propRef->getAttributes(Version::class) !== [];

                if ($uuidType !== null) {
                    $columns[] = new ColumnDefinition(
                        column:   $columnName,
                        property: $propName,
                        type:     'guid',
                        primary:  true,
                        length:   36,
                        nullable: $colAttr->nullable,
                        comment:  $colAttr->comment,
                        unsigned: $colAttr->unsigned,
                        version:  $isVersion,
                    );
                } else {
                    $columns[] = new ColumnDefinition(
                        column:        $columnName,
                        property:      $propName,
                        type:          $this->inferTypeFromProperty($propRef, $colAttr->type),
                        primary:       $colAttr->primary,
                        autoIncrement: $colAttr->autoIncrement,
                        nullable:      $colAttr->nullable,
                        length:        $colAttr->length,
                        default:       $colAttr->default,
                        comment:       $colAttr->comment,
                        unsigned:      $colAttr->unsigned,
                        version:       $isVersion,
                    );
                }
                continue;
            }

            $versionAttrs = $propRef->getAttributes(Version::class);
            if ($versionAttrs !== []) {
                $columns[] = new ColumnDefinition(
                    column:   $this->toSnakeCase($propName),
                    property: $propName,
                    type:     'integer',
                    version:  true,
                );
                continue;
            }

            $standaloneUuidType = $this->resolveUuidType($propRef);
            if ($standaloneUuidType !== null) {
                $columns[] = new ColumnDefinition(
                    column:   $this->toSnakeCase($propName),
                    property: $propName,
                    type:     'guid',
                    primary:  true,
                    length:   36,
                );
                continue;
            }

            $embeddedAttrs = $propRef->getAttributes(Embedded::class);
            if ($embeddedAttrs !== []) {

                $embeddedAttr    = $embeddedAttrs[0]->newInstance();

                $embeddableClass = $embeddedAttr->class;
                $prefix          = $embeddedAttr->prefix;

                $defCols = $this->buildEmbeddableColumns($embeddableClass, $prefix, null);
                $flatCols = $this->buildEmbeddableColumns($embeddableClass, $prefix, $propName);
                $nestedEmbeddables = $this->buildNestedEmbeddables($embeddableClass, $prefix, $propName);

                $embedded[] = new EmbeddedDefinition(
                    property:           $propName,
                    embeddableClass:    $embeddableClass,
                    prefix:             $prefix,
                    columns:            $defCols,
                    nestedEmbeddables:  $nestedEmbeddables,
                );

                foreach ($flatCols as $flatCol) {
                    $columns[] = $flatCol;
                }

                $nestedFlatCols = $this->collectNestedFlatColumns($embeddableClass, $prefix, $propName);
                foreach ($nestedFlatCols as $flatCol) {
                    $columns[] = $flatCol;
                }
                continue;
            }

            $relation = $this->buildRelation($propName, $propRef);
            if ($relation !== null) {
                $relations[] = $relation;
            }
        }

        if ($hasSoftDeletes) {
            $columns[] = new ColumnDefinition(
                column:   'deleted_at',
                property: 'deletedAt',
                type:     'datetime_immutable',
                nullable: true,
            );
        }

        if ($hasTimestamps) {
            $columns[] = new ColumnDefinition(
                column:   'created_at',
                property: 'createdAt',
                type:     'datetime_immutable',
                nullable: true,
            );
            $columns[] = new ColumnDefinition(
                column:   'updated_at',
                property: 'updatedAt',
                type:     'datetime_immutable',
                nullable: true,
            );
        }

        return new AttributeEntityMapper(
            entityClass: $entityClass,
            tableName:   $tableName,
            columns:     $columns,
            relations:   $relations,
            embedded:    $embedded,
        );
    }

    public function buildInheritanceMapping(string $entityClass): ?InheritanceMapping
    {
        $ref = new \ReflectionClass($entityClass);

        $inheritanceType = $ref->getAttributes(Inheritance::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($inheritanceType === null) {
            return null;
        }

        $type = $inheritanceType->newInstance()->type;

        $discColAttr = $ref->getAttributes(TypeColumn::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        $discColName = $discColAttr?->newInstance()->name ?? 'type';
        $discColType = $discColAttr?->newInstance()->type ?? 'string';

        $discMapAttr = $ref->getAttributes(TypeMap::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        $discMap = $discMapAttr?->newInstance()->map ?? [];

        return new InheritanceMapping($type, $discColName, $discColType, $discMap);
    }

    private function toSnakeCase(string $name): string
    {
        $snake = preg_replace('/[A-Z]/', '_$0', $name);

        if ($snake === null) {
            return strtolower($name);
        }

        return strtolower(ltrim($snake, '_'));
    }

    private function inferTypeFromProperty(\ReflectionProperty $prop, string $declaredType): string
    {
        // Only auto-detect when the Column attribute uses the default 'string' type
        if ($declaredType !== 'string') {
            return $declaredType;
        }

        $refType = $prop->getType();
        if (!$refType instanceof \ReflectionNamedType) {
            return $declaredType;
        }

        $typeName = $refType->getName();

        return match ($typeName) {
            'int'                      => 'integer',
            'float'                    => 'float',
            'bool'                     => 'boolean',
            'array'                    => 'json',
            'DateTimeImmutable',
            '\DateTimeImmutable'       => 'datetime_immutable',
            'DateTime',
            '\DateTime'                => 'datetime',
            default                    => $declaredType,
        };
    }

    private function resolveUuidType(\ReflectionProperty $prop): ?string
    {
        if ($prop->getAttributes(UseUuidV7::class) !== []) {
            return 'uuid_v7';
        }

        if ($prop->getAttributes(UseUuid::class) !== []) {
            return 'uuid';
        }

        return null;
    }

    private function buildRelation(
        string $propName,
        \ReflectionProperty $propRef,
    ): ?RelationDefinition {

        $attrs = $propRef->getAttributes(HasOne::class);
        if ($attrs !== []) {

            $attr = $attrs[0]->newInstance();

            $target = $attr->target;

            return new RelationDefinition(
                property:      $propName,
                type:          RelationType::HasOne,
                relatedEntity: $target,
                relatedMapper: AttributeEntityMapper::class,
                foreignKey:    $attr->foreignKey,
                ownerKey:      $attr->localKey,
            );
        }

        $attrs = $propRef->getAttributes(HasMany::class);
        if ($attrs !== []) {

            $attr = $attrs[0]->newInstance();

            $target = $attr->target;

            return new RelationDefinition(
                property:      $propName,
                type:          RelationType::HasMany,
                relatedEntity: $target,
                relatedMapper: AttributeEntityMapper::class,
                foreignKey:    $attr->foreignKey,
                ownerKey:      $attr->localKey,
            );
        }

        $attrs = $propRef->getAttributes(BelongsTo::class);
        if ($attrs !== []) {

            $attr = $attrs[0]->newInstance();

            $target = $attr->target;

            return new RelationDefinition(
                property:      $propName,
                type:          RelationType::BelongsTo,
                relatedEntity: $target,
                relatedMapper: AttributeEntityMapper::class,
                foreignKey:    $attr->foreignKey,
                ownerKey:      $attr->ownerKey,
            );
        }

        $attrs = $propRef->getAttributes(BelongsToMany::class);
        if ($attrs !== []) {

            $attr = $attrs[0]->newInstance();

            $target = $attr->target;

            return new RelationDefinition(
                property:         $propName,
                type:             RelationType::BelongsToMany,
                relatedEntity:    $target,
                relatedMapper:    AttributeEntityMapper::class,
                ownerKey:         $attr->localKey,
                pivotTable:       $attr->pivotTable,
                pivotForeignKey:  $attr->foreignPivotKey,
                pivotRelatedKey:  $attr->relatedPivotKey,
            );
        }

        return null;
    }

    private function buildNestedEmbeddables(
        string $embeddableClass,
        string $parentPrefix,
        string $parentProperty,
    ): array {
        $classRef = new \ReflectionClass($embeddableClass);
        $nested = [];

        foreach ($classRef->getProperties() as $propRef) {
            $embeddedAttrs = $propRef->getAttributes(Embedded::class);
            if ($embeddedAttrs === []) {
                continue;
            }

            $embeddedAttr = $embeddedAttrs[0]->newInstance();
            $nestedClass = $embeddedAttr->class;
            $nestedPrefix = $parentPrefix . $embeddedAttr->prefix;
            $propName = $propRef->getName();

            $defCols = $this->buildEmbeddableColumns($nestedClass, $nestedPrefix, null);
            $deepNested = $this->buildNestedEmbeddables($nestedClass, $nestedPrefix, $parentProperty . '.' . $propName);

            $nested[] = new EmbeddedDefinition(
                property:           $propName,
                embeddableClass:    $nestedClass,
                prefix:             $nestedPrefix,
                columns:            $defCols,
                nestedEmbeddables:  $deepNested,
            );
        }

        return $nested;
    }

    private function collectNestedFlatColumns(
        string $embeddableClass,
        string $parentPrefix,
        string $parentProperty,
    ): array {
        $classRef = new \ReflectionClass($embeddableClass);
        $columns = [];

        foreach ($classRef->getProperties() as $propRef) {
            $embeddedAttrs = $propRef->getAttributes(Embedded::class);
            if ($embeddedAttrs === []) {
                continue;
            }

            $embeddedAttr = $embeddedAttrs[0]->newInstance();
            $nestedClass = $embeddedAttr->class;
            $nestedPrefix = $parentPrefix . $embeddedAttr->prefix;
            $propName = $propRef->getName();
            $nestedProperty = $parentProperty . '.' . $propName;

            $flatCols = $this->buildEmbeddableColumns($nestedClass, $nestedPrefix, $nestedProperty);
            foreach ($flatCols as $col) {
                $columns[] = $col;
            }

            $deeper = $this->collectNestedFlatColumns($nestedClass, $nestedPrefix, $nestedProperty);
            foreach ($deeper as $col) {
                $columns[] = $col;
            }
        }

        return $columns;
    }

    private function buildEmbeddableColumns(
        string $embeddableClass,
        string $prefix,
        ?string $entityProperty,
    ): array {
        $classRef = new \ReflectionClass($embeddableClass);
        $columns  = [];

        foreach ($classRef->getProperties() as $propRef) {
            $propName = $propRef->getName();
            $colAttrs = $propRef->getAttributes(Column::class);

            if ($colAttrs === []) {
                continue;
            }

            $colAttr    = $colAttrs[0]->newInstance();
            $columnName = $prefix . ($colAttr->name ?? $this->toSnakeCase($propName));
            $property   = $entityProperty !== null
                ? $entityProperty . '.' . $propName
                : $propName;

            $columns[] = new ColumnDefinition(
                column:        $columnName,
                property:      $property,
                type:          $colAttr->type,
                primary:       $colAttr->primary,
                autoIncrement: $colAttr->autoIncrement,
                nullable:      $colAttr->nullable,
                length:        $colAttr->length,
                default:       $colAttr->default,
                comment:       $colAttr->comment,
                unsigned:      $colAttr->unsigned,
            );
        }

        return $columns;
    }
}
