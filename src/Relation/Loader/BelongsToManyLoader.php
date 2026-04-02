<?php

declare(strict_types=1);

namespace Weaver\ORM\Relation\Loader;

use Weaver\ORM\DBAL\ArrayParameterType;
use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Hydration\PivotHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Relation\EagerLoadPlan;
use Weaver\ORM\Relation\PivotData;

final readonly class BelongsToManyLoader
{
    private const PIVOT_ALIAS_PREFIX = 'pivot_';

    public function __construct(
        private Connection $connection,
        private EntityHydrator $hydrator,
        private MapperRegistry $registry,
        private PivotHydrator $pivotHydrator,
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

        $pivotTable = $relation->getPivotTable()
            ?? throw new \LogicException(
                sprintf(
                    'BelongsToMany relation "%s" on "%s" is missing a pivotTable.',
                    $relation->getProperty(),
                    $parents[0]::class,
                )
            );

        $pivotParentFk  = $relation->getPivotForeignKey()
            ?? throw new \LogicException(
                sprintf(
                    'BelongsToMany relation "%s" on "%s" is missing a pivotForeignKey.',
                    $relation->getProperty(),
                    $parents[0]::class,
                )
            );

        $pivotRelatedFk = $relation->getPivotRelatedKey()
            ?? throw new \LogicException(
                sprintf(
                    'BelongsToMany relation "%s" on "%s" is missing a pivotRelatedKey.',
                    $relation->getProperty(),
                    $parents[0]::class,
                )
            );

        $relatedPkColumn = $relatedMapper->getPrimaryKey();

        $ids = array_values(
            array_unique(
                array_map(
                    fn (object $parent): mixed => $parentMapper->getProperty($parent, $ownerKeyProperty),
                    $parents,
                )
            )
        );

        if ($ids === []) {
            $this->assignEmptyCollections($parents, $parentMapper, $relation);
            return;
        }

        $selectParts = ['related.*', 'pivot.' . $pivotParentFk . ' AS ' . self::PIVOT_ALIAS_PREFIX . $pivotParentFk];

        $pivotColumnsToSelect = [$pivotParentFk];

        $extraPivotColumns = $relation->getPivotColumns();

        foreach ($extraPivotColumns as $pivotCol) {
            if ($pivotCol === $pivotParentFk) {
                continue;
            }

            $selectParts[]          = 'pivot.' . $pivotCol . ' AS ' . self::PIVOT_ALIAS_PREFIX . $pivotCol;
            $pivotColumnsToSelect[] = $pivotCol;
        }

        if ($relation->hasPivotTimestamps()) {
            foreach (['created_at', 'updated_at'] as $tsCol) {
                $selectParts[]          = 'pivot.' . $tsCol . ' AS ' . self::PIVOT_ALIAS_PREFIX . $tsCol;
                $pivotColumnsToSelect[] = $tsCol;
            }
        }

        $qb = $this->connection->createQueryBuilder()
            ->select(...$selectParts)
            ->from($pivotTable, 'pivot')
            ->innerJoin(
                'pivot',
                $relatedMapper->getTableName(),
                'related',
                'pivot.' . $pivotRelatedFk . ' = related.' . $relatedPkColumn,
            )
            ->where('pivot.' . $pivotParentFk . ' IN (:ids)')
            ->setParameter('ids', $ids, $this->arrayParamType($ids));

        $constraint = $plan->getConstraint();
        if ($constraint instanceof \Closure) {
            $constraint($qb);
        }

        $rows             = $qb->executeQuery()->fetchAllAssociative();
        $entities         = [];
        $pivotParentFkAlias = self::PIVOT_ALIAS_PREFIX . $pivotParentFk;

        $grouped = [];

        foreach ($rows as $row) {
            $parentFkValue = $row[$pivotParentFkAlias];

            $entityRow = array_filter(
                $row,
                fn (string $key): bool => !str_starts_with($key, self::PIVOT_ALIAS_PREFIX),
                ARRAY_FILTER_USE_KEY,
            );

            $entity = $this->hydrator->hydrate($relatedEntityClass, $entityRow);

            $rawPivotData = $this->pivotHydrator->extractPivot(
                $row,
                self::PIVOT_ALIAS_PREFIX,
                [],
            );

            unset($rawPivotData[$pivotParentFk]);

            if ($rawPivotData !== []) {
                try {
                    $parentMapper->setProperty($entity, '__pivot', new PivotData($rawPivotData));
                } catch (\Throwable) {

                }
            }

            $grouped[$parentFkValue][] = $entity;
            $entities[]               = $entity;
        }

        $relProperty = $relation->getProperty();

        foreach ($parents as $parent) {
            $parentKeyValue = $parentMapper->getProperty($parent, $ownerKeyProperty);
            $relatedItems   = $grouped[$parentKeyValue] ?? [];
            $parentMapper->setProperty($parent, $relProperty, new EntityCollection($relatedItems));
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

    private function assignEmptyCollections(
        array $parents,
        \Weaver\ORM\Contract\EntityMapperInterface $parentMapper,
        RelationDefinition $relation,
    ): void {
        foreach ($parents as $parent) {
            $parentMapper->setProperty($parent, $relation->getProperty(), new EntityCollection());
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
