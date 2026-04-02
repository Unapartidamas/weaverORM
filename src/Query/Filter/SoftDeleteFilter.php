<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Filter;

use Weaver\ORM\Query\EntityQueryBuilder;

final class SoftDeleteFilter implements QueryFilterInterface
{

    public function __construct(
        private readonly ?array $entityClasses = null,
        private readonly string $column = 'deleted_at',
    ) {}

    public function supports(string $entityClass): bool
    {
        return $this->entityClasses === null
            || in_array($entityClass, $this->entityClasses, true);
    }

    public function apply(EntityQueryBuilder $qb): void
    {
        $qb->whereNull($this->column);
    }
}
