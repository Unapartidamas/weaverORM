<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

final readonly class InheritanceMapping
{
    public const string SINGLE_TABLE = 'SINGLE_TABLE';
    public const string JOINED = 'JOINED';

    public function __construct(
        public readonly string $type,
        public readonly string $discriminatorColumn,
        public readonly string $discriminatorType,
        public readonly array $discriminatorMap,
        public readonly ?string $parentTable = null,
        public readonly array $childTables = [],
        public readonly string $joinColumn = 'id',
    ) {}

    public function resolveClass(mixed $discriminatorValue): ?string
    {
        return $this->discriminatorMap[(string) $discriminatorValue] ?? null;
    }

    public function resolveValue(string $class): ?string
    {
        return array_search($class, $this->discriminatorMap, true) ?: null;
    }
}
