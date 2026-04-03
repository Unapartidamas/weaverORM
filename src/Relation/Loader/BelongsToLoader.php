<?php

declare(strict_types=1);

namespace Weaver\ORM\Relation\Loader;

use Weaver\ORM\DBAL\ArrayParameterType;
use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Relation\EagerLoadPlan;

final readonly class BelongsToLoader
{
    public function __construct(
        private Connection $connection,
        private EntityHydrator $hydrator,
        private MapperRegistry $registry,
    ) {}

    public function load(array $parents, RelationDefinition $relation, EagerLoadPlan $plan): void
    {
        if ($parents === []) {
            return;
        }

        $relatedEntityClass = $relation->getRelatedEntity();
        $relatedMapper      = $this->registry->get($relatedEntityClass);
        $parentMapper       = $this->registry->get($parents[0]::class);

        $foreignKeyColumn = $relation->getForeignKey()
            ?? throw new \LogicException(
                sprintf(
                    'BelongsTo relation "%s" on "%s" is missing a foreignKey.',
                    $relation->getProperty(),
                    $parents[0]::class,
                )
            );

        $fkProperty = $this->resolvePropertyForColumn($parentMapper, $foreignKeyColumn);

        $fkValues = array_values(
            array_unique(
                array_filter(
                    array_map(
                        fn (object $parent): mixed => $parentMapper->getProperty($parent, $fkProperty),
                        $parents,
                    ),
                    fn (mixed $v): bool => $v !== null,
                )
            )
        );

        if ($fkValues === []) {
            $this->assignNulls($parents, $parentMapper, $relation);
            return;
        }

        $relatedPkColumn   = $relation->getOwnerKey() ?? $relatedMapper->getPrimaryKey();
        $relatedPkProperty = $this->resolvePropertyForColumn($relatedMapper, $relatedPkColumn);

        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($relatedMapper->getTableName())
            ->where($relatedPkColumn . ' IN (:ids)')
            ->setParameter('ids', $fkValues, $this->arrayParamType($fkValues));

        $constraint = $plan->getConstraint();
        if ($constraint instanceof \Closure) {
            $constraint($qb);
        }

        $rows     = $qb->executeQuery()->fetchAllAssociative();
        $entities = [];

        foreach ($rows as $row) {
            $entities[] = $this->hydrator->hydrate($relatedEntityClass, $row);
        }

        $indexed = [];

        foreach ($entities as $entity) {
            $pkValue          = $relatedMapper->getProperty($entity, $relatedPkProperty);
            $indexed[$pkValue] = $entity;
        }

        $relProperty = $relation->getProperty();

        foreach ($parents as $parent) {
            $fkValue = $parentMapper->getProperty($parent, $fkProperty);
            $related = $fkValue !== null ? ($indexed[$fkValue] ?? null) : null;
            if ($related !== null) {
                $parentMapper->setProperty($parent, $relProperty, $related);
            }
        }

        if ($plan->hasChildren() && $entities !== []) {
            $nestedLoader = new \Weaver\ORM\Relation\RelationLoader(
                $this->connection,
                $this->registry,
                $this->hydrator,
            );

            $nestedLoader->load(
                new EntityCollection($entities),
                $relatedEntityClass,
                $plan->getChildrenAsWithArray(),
            );
        }
    }

    private function assignNulls(
        array $parents,
        \Weaver\ORM\Contract\EntityMapperInterface $parentMapper,
        RelationDefinition $relation,
    ): void {
        $prop = $relation->getProperty();
        foreach ($parents as $parent) {
            try {
                $parentMapper->setProperty($parent, $prop, null);
            } catch (\TypeError) {
                // Skip: property is non-nullable (e.g., typed as Category, not ?Category)
            }
        }
    }

    private function resolvePropertyForColumn(
        \Weaver\ORM\Contract\EntityMapperInterface $mapper,
        string $column,
    ): string {
        $colDef = $mapper->getColumnByName($column);

        return $colDef instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $colDef->getProperty() : $column;
    }

    private function arrayParamType(array $ids): ArrayParameterType
    {
        if ($ids === []) {
            return ArrayParameterType::INTEGER;
        }

        return is_string(reset($ids))
            ? ArrayParameterType::STRING
            : ArrayParameterType::INTEGER;
    }
}
