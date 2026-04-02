<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

final readonly class IndexDefinition
{

    public function __construct(
        private array $columns,
        private bool $unique = false,
        private ?string $name = null,
        private ?string $where = null,
        private string $type = 'btree',
    ) {}

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getWhere(): ?string
    {
        return $this->where;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
