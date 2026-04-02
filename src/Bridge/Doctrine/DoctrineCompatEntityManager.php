<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Doctrine;

use Doctrine\DBAL\Connection;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Repository\EntityRepository;

final class DoctrineCompatEntityManager
{
    public function __construct(
        private readonly EntityWorkspace $em,
    ) {}

    public function unwrap(): EntityWorkspace
    {
        return $this->em;
    }

    public function persist(object $entity): void
    {
        $this->em->getUnitOfWork()->add($entity);
    }

    public function remove(object $entity): void
    {
        $this->em->getUnitOfWork()->delete($entity);
    }

    public function flush(?object $entity = null): void
    {
        $this->em->push($entity);
    }

    public function clear(): void
    {
        $this->em->getUnitOfWork()->reset();
    }

    public function detach(object $entity): void
    {
        $this->em->untrack($entity);
    }

    public function contains(object $entity): bool
    {
        return $this->em->isTracked($entity);
    }

    public function refresh(object $entity): void
    {
        $this->em->reload($entity);
    }

    public function getRepository(string $entityClass): EntityRepository
    {
        return $this->em->getRepository($entityClass);
    }

    public function getConnection(): Connection
    {
        return $this->em->getConnection();
    }
}
