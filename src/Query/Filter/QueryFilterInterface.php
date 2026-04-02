<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Filter;

use Weaver\ORM\Query\EntityQueryBuilder;

interface QueryFilterInterface
{

    public function supports(string $entityClass): bool;

    public function apply(EntityQueryBuilder $qb): void;
}
