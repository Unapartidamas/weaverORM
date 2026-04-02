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

final readonly class HasOneThroughLoader
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

        $relatedEntityClass      = $relation->getRelatedEntity();
        $relatedMapper           = $this->registry->get($relatedEntityClass);
        $parentMapper            = $this->registry->get($parents[0]::class);

        $throughEntity = $relation->getThroughEntity()
            ?? throw new \LogicException(
                sprintf(
                    'HasOneThrough relation "%s" on "%s" is missing a throughEntity.',
                    $relation->getProperty(),
                    $parents[0]::class,
                )
            );

        $throughMapper = $this->registry->get($throughEntity);

        $throughLocalKey = $relation->getThroughLocalKey()
            ?? throw new \LogicException(
                sprintf(
                    'HasOneThrough relation "%s" on "%s" is missing a throughLocalKey.',
                    $relation->getProperty(),
                    $parents[0]::class,
                )
            );

        $throughForeignKey = $relation->getThroughForeignKey()
            ?? throw new \LogicException(
                sprintf(
                    'HasOneThrough relation "%s" on "%s" is missing a throughForeignKey.',
                    $relation->getProperty(),
                    $parents[0]::class,
                )
            );

        $ownerKeyColumn   = $relation->getOwnerKey() ?? $parentMapper->getPrimaryKey();
        $ownerKeyProperty = $this->resolvePropertyForColumn($parentMapper, $ownerKeyColumn);

        $relatedPkColumn = $relation->getForeignKey() ?? $relatedMapper->getPrimaryKey();

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

        $parentKeyAlias = '__through_parent_key';

        $qb = $this->connection->createQueryBuilder()
            ->select(
                'related.*',
                'interm.' . $throughLocalKey . ' AS ' . $parentKeyAlias,
            )
            ->from($relatedMapper->getTableName(), 'related')
            ->innerJoin(
                'related',
                $throughMapper->getTableName(),
                'interm',
                'interm.' . $throughForeignKey . ' = related.' . $relatedPkColumn,
            )
            ->where('interm.' . $throughLocalKey . ' IN (:ids)')
            ->setParameter('ids', $ids, $this->arrayParamType($ids));

        $constraint = $plan->getConstraint();
        if ($constraint instanceof \Closure) {
            $constraint($qb);
        }

        $rows     = $qb->executeQuery()->fetchAllAssociative();
        $entities = [];

        $indexed = [];

        foreach ($rows as $row) {
            $parentKeyValue = $row[$parentKeyAlias];

            unset($row[$parentKeyAlias]);

            $entity                   = $this->hydrator->hydrate($relatedEntityClass, $row);
            $indexed[$parentKeyValue] = $entity;
            $entities[]               = $entity;
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
