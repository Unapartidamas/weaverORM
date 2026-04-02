<?php

declare(strict_types=1);

namespace Weaver\ORM\Contract;

use Weaver\ORM\Query\EntityQueryBuilder;

interface ScopeInterface
{
    public function apply(EntityQueryBuilder $qb): void;

    public function getName(): string;
}
