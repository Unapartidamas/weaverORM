<?php

declare(strict_types=1);

namespace Weaver\ORM\Contract;

use Weaver\ORM\Collection\EntityCollection;

interface HydratorInterface
{

    public function hydrate(string $entityClass, array $row): object;

    public function hydrateMany(string $entityClass, array $rows): EntityCollection;

    public function extract(object $entity, string $entityClass): array;

    public function extractChangeset(object $entity, string $entityClass, array $snapshot): array;
}
