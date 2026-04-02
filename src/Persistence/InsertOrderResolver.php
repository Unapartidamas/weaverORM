<?php

declare(strict_types=1);

namespace Weaver\ORM\Persistence;

use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Mapping\RelationType;

final readonly class InsertOrderResolver
{
    public function __construct(
        private MapperRegistry $registry,
    ) {}

    public function resolve(array $entityClasses): array
    {

        if (count($entityClasses) <= 1) {
            return $entityClasses;
        }

        $classSet = array_flip($entityClasses);

        $adjacency = [];

        $inDegree  = [];

        foreach ($entityClasses as $class) {
            $adjacency[$class] = [];
            $inDegree[$class]  = 0;
        }

        foreach ($entityClasses as $class) {
            if (!$this->registry->has($class)) {
                continue;
            }

            $mapper = $this->registry->get($class);

            foreach ($mapper->getRelations() as $relation) {
                if ($relation->getType() !== RelationType::BelongsTo) {
                    continue;
                }

                $dependency = $relation->getRelatedEntity();

                if (!isset($classSet[$dependency])) {
                    continue;
                }

                $adjacency[$class][] = $dependency;

            }
        }

        $dependents = [];
        foreach ($entityClasses as $class) {
            $dependents[$class] = [];
            $inDegree[$class]   = 0;
        }

        foreach ($entityClasses as $class) {
            foreach ($adjacency[$class] as $dependency) {

                $dependents[$dependency][] = $class;
                $inDegree[$class]++;
            }
        }

        $queue = [];
        foreach ($entityClasses as $class) {
            if ($inDegree[$class] === 0) {
                $queue[] = $class;
            }
        }

        $result = [];

        while ($queue !== []) {

            $current  = array_shift($queue);
            $result[] = $current;

            foreach ($dependents[$current] as $dependent) {
                $inDegree[$dependent]--;

                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if (count($result) !== count($entityClasses)) {
            throw new \RuntimeException('Circular FK dependency detected');
        }

        return $result;
    }
}
