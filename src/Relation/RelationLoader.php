<?php

declare(strict_types=1);

namespace Weaver\ORM\Relation;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Exception\RelationNotFoundException;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Hydration\PivotHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Mapping\RelationType;
use Weaver\ORM\Relation\Loader\BelongsToLoader;
use Weaver\ORM\Relation\Loader\BelongsToManyLoader;
use Weaver\ORM\Relation\Loader\HasManyLoader;
use Weaver\ORM\Relation\Loader\HasManyThroughLoader;
use Weaver\ORM\Relation\Loader\HasOneLoader;
use Weaver\ORM\Relation\Loader\HasOneThroughLoader;
use Weaver\ORM\Relation\Loader\MorphManyLoader;
use Weaver\ORM\Relation\Loader\MorphOneLoader;

class RelationLoader
{
    private readonly HasOneLoader $hasOneLoader;
    private readonly HasManyLoader $hasManyLoader;
    private readonly BelongsToLoader $belongsToLoader;
    private readonly BelongsToManyLoader $belongsToManyLoader;
    private readonly HasOneThroughLoader $hasOneThroughLoader;
    private readonly HasManyThroughLoader $hasManyThroughLoader;
    private readonly MorphOneLoader $morphOneLoader;
    private readonly MorphManyLoader $morphManyLoader;

    public function __construct(
        Connection $connection,
        private readonly MapperRegistry $registry,
        EntityHydrator $hydrator,
    ) {
        $pivotHydrator = new PivotHydrator($connection);

        $this->hasOneLoader         = new HasOneLoader($connection, $hydrator, $registry);
        $this->hasManyLoader        = new HasManyLoader($connection, $hydrator, $registry);
        $this->belongsToLoader      = new BelongsToLoader($connection, $hydrator, $registry);
        $this->belongsToManyLoader  = new BelongsToManyLoader($connection, $hydrator, $registry, $pivotHydrator);
        $this->hasOneThroughLoader  = new HasOneThroughLoader($connection, $hydrator, $registry);
        $this->hasManyThroughLoader = new HasManyThroughLoader($connection, $hydrator, $registry);
        $this->morphOneLoader       = new MorphOneLoader($connection, $hydrator, $registry);
        $this->morphManyLoader      = new MorphManyLoader($connection, $hydrator, $registry);
    }

    public function load(EntityCollection $collection, string $entityClass, array $relations): void
    {
        if ($collection->isEmpty() || $relations === []) {
            return;
        }

        $plans  = EagerLoadPlan::parse($relations);
        $mapper = $this->registry->get($entityClass);

        foreach ($plans as $plan) {
            $relation = $mapper->getRelation($plan->getRelation());

            if (!$relation instanceof \Weaver\ORM\Mapping\RelationDefinition) {
                throw new RelationNotFoundException($entityClass, $plan->getRelation());
            }

            $this->dispatchLoader($collection->toArray(), $relation, $plan);
        }
    }

    public function loadOneRelation(object $entity, string $relationName, string $entityClass): mixed
    {
        $mapper   = $this->registry->get($entityClass);
        $relation = $mapper->getRelation($relationName);

        if ($relation === null) {
            return null;
        }

        $plan = new EagerLoadPlan($relationName);

        $this->dispatchLoader([$entity], $relation, $plan);

        return $mapper->getProperty($entity, $relationName);
    }

    private function dispatchLoader(
        array $parents,
        \Weaver\ORM\Mapping\RelationDefinition $relation,
        EagerLoadPlan $plan,
    ): void {
        match ($relation->getType()) {
            RelationType::HasOne         => $this->hasOneLoader->load($parents, $relation, $plan),
            RelationType::HasMany        => $this->hasManyLoader->load($parents, $relation, $plan),
            RelationType::BelongsTo      => $this->belongsToLoader->load($parents, $relation, $plan),
            RelationType::BelongsToMany  => $this->belongsToManyLoader->load($parents, $relation, $plan),
            RelationType::HasOneThrough  => $this->hasOneThroughLoader->load($parents, $relation, $plan),
            RelationType::HasManyThrough => $this->hasManyThroughLoader->load($parents, $relation, $plan),
            RelationType::MorphOne       => $this->morphOneLoader->load($parents, $relation, $plan),
            RelationType::MorphMany      => $this->morphManyLoader->load($parents, $relation, $plan),
        };
    }
}
