<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB\Mapping;

final readonly class IndexDefinition
{

    public function __construct(
        public readonly array $keys,
        public readonly bool $unique = false,
        public readonly ?string $name = null,
        public readonly ?int $ttl = null,
        public readonly ?string $sparse = null,
        public readonly ?array $partialFilter = null,
        public readonly string $type = 'default',
    ) {}

    public function getKeys(): array
    {
        return $this->keys;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    public function getSparse(): ?string
    {
        return $this->sparse;
    }

    public function getPartialFilter(): ?array
    {
        return $this->partialFilter;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
