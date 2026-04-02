<?php

declare(strict_types=1);

namespace Weaver\ORM\Query;

final readonly class QueryResult
{

    public function __construct(
        private array $rows,
        private string $entityClass,
        private int $totalCount = 0,
    ) {}

    public function getRows(): array
    {
        return $this->rows;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
