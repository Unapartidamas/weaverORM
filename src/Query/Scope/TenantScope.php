<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Scope;

use Weaver\ORM\Contract\ScopeInterface;
use Weaver\ORM\Query\EntityQueryBuilder;

final readonly class TenantScope implements ScopeInterface
{
    public function __construct(
        private string $tenantIdColumn,
        private mixed $tenantId,
    ) {}

    public function apply(EntityQueryBuilder $qb): void
    {
        $qb->where($this->tenantIdColumn, $this->tenantId);
    }

    public function getName(): string
    {
        return 'tenant';
    }
}
