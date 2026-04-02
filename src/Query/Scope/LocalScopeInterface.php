<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Scope;

use Weaver\ORM\Query\EntityQueryBuilder;

interface LocalScopeInterface
{
    public function apply(EntityQueryBuilder $qb): void;
}
