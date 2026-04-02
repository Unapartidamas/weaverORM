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

final readonly class HasOneLoader
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

        $ownerKeyColumn   = $relation->getOwnerKey() ?? $parentMapper->getPrimaryKey();
        $ownerKeyProperty = $this->resolvePropertyForColumn($parentMapper, $ownerKeyColumn);

        $ids = array_values(
            array_unique(
                array_map(
                    fn (object $parent): mixed => $parentMapper->getProperty($parent, $ownerKeyProperty),
                    $parents,
                )
            )
        );

        if ($ids === []) {
            $this->assignNulls($parents, $parentMapper, $relation);
            return;
        }

        $foreignKeyColumn = $relation->getForeignKey()
            ?? throw new \LogicException(
                sprintf(
                    'HasOne relation "%s" on "%s" is missing a foreignKey.',
                    $relation->getProperty(),
                    $parents[0]::class,
                )
            );

        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($relatedMapper->getTableName())
            ->where($foreignKeyColumn . ' IN (:ids)')
            ->setParameter('ids', $ids, $this->arrayParamType($ids));

        $constraint = $plan->getConstraint();
        if ($constraint instanceof \Closure) {
            $constraint($qb);
        }

        $rows     = $qb->executeQuery()->fetchAllAssociative();
        $entities = [];

        foreach ($rows as $row) {
            $entities[] = $this->hydrator->hydrate($relatedEntityClass, $row);
        }

        $fkProperty = $this->resolvePropertyForColumn($relatedMapper, $foreignKeyColumn);
        $indexed    = [];

        foreach ($entities as $entity) {
            $fkValue          = $relatedMapper->getProperty($entity, $fkProperty);
            $indexed[$fkValue] = $entity;
        }

        $relProperty = $relation->getProperty();

        foreach ($parents as $parent) {
            $parentKeyValue = $parentMapper->getProperty($parent, $ownerKeyProperty);
            $parentMapper->setProperty(
                $parent,
                $relProperty,
                $indexed[$parentKeyValue] ?? null,
            );
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
        foreach ($parents as $parent) {
            $parentMapper->setProperty($parent, $relation->getProperty(), null);
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
