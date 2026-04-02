<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Scope;

use Weaver\ORM\Contract\ScopeInterface;
use Weaver\ORM\Query\EntityQueryBuilder;

final class SoftDeleteScope implements ScopeInterface
{
    public function apply(EntityQueryBuilder $qb): void
    {

    }

    public function getName(): string
    {
        return 'soft_delete';
    }
}
