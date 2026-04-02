<?php

declare(strict_types=1);

namespace Weaver\ORM\Repository;

use Weaver\ORM\Manager\EntityWorkspace;

final class RepositoryFactory
{

    private array $cache = [];

    public function getRepository(
        EntityWorkspace $workspace,
        string $entityClass,
        string $repositoryClass = EntityRepository::class,
    ): EntityRepository {
        $cacheKey = $entityClass . '@' . $repositoryClass;

        if (!isset($this->cache[$cacheKey])) {
            $this->cache[$cacheKey] = new $repositoryClass($workspace, $entityClass);
        }

        return $this->cache[$cacheKey];
    }
}
