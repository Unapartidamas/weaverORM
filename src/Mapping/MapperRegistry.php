<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

use Weaver\ORM\Contract\EntityMapperInterface;
use Weaver\ORM\Exception\MapperNotFoundException;

final class MapperRegistry
{

    private array $mappers = [];

    public function register(EntityMapperInterface $mapper): void
    {
        $this->mappers[$mapper->getEntityClass()] = $mapper;
    }

    public function get(string $entityClass): EntityMapperInterface
    {
        if (!isset($this->mappers[$entityClass])) {
            throw MapperNotFoundException::forEntity($entityClass);
        }

        return $this->mappers[$entityClass];
    }

    public function has(string $entityClass): bool
    {
        return isset($this->mappers[$entityClass]);
    }

    public function all(): array
    {
        return $this->mappers;
    }

    public function getByTableName(string $table): EntityMapperInterface
    {
        foreach ($this->mappers as $mapper) {
            if ($mapper->getTableName() === $table) {
                return $mapper;
            }
        }

        throw MapperNotFoundException::forTable($table);
    }
}
