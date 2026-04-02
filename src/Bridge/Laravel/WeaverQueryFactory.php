<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel;

use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Query\EntityQueryBuilder;

final class WeaverQueryFactory
{
    public function __construct(
        private readonly EntityWorkspace $workspace,
        private readonly EntityHydrator $hydrator,
    ) {}

    public function query(string $entityClass): EntityQueryBuilder
    {
        $mapper = $this->workspace->getMapperRegistry()->get($entityClass);

        return new EntityQueryBuilder(
            connection: $this->workspace->getConnection(),
            entityClass: $entityClass,
            mapper: $mapper,
            hydrator: $this->hydrator,
        );
    }
}
