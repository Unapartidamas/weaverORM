<?php

declare(strict_types=1);

namespace Weaver\ORM\Contract;

use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Query\EntityQueryBuilder;

interface RepositoryInterface
{

    public function find(mixed $id): ?object;

    public function findOrFail(mixed $id): object;

    public function findBy(array $criteria, array $with = []): EntityCollection;

    public function findOneBy(array $criteria, array $with = []): ?object;

    public function save(object $entity): void;

    public function delete(object $entity): void;

    public function query(): EntityQueryBuilder;
}
