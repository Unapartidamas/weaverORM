<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Doctrine;

use Weaver\ORM\Persistence\UnitOfWork;

final class DoctrineCompatUnitOfWork
{
    public function __construct(
        private readonly UnitOfWork $uow,
    ) {}

    public function unwrap(): UnitOfWork
    {
        return $this->uow;
    }

    public function persist(object $entity): void
    {
        $this->uow->add($entity);
    }

    public function remove(object $entity): void
    {
        $this->uow->delete($entity);
    }

    public function flush(): void
    {
        $this->uow->push();
    }

    public function clear(): void
    {
        $this->uow->reset();
    }

    public function detach(object $entity): void
    {
        $this->uow->untrack($entity);
    }

    public function contains(object $entity): bool
    {
        return $this->uow->isTracked($entity);
    }

    public function refresh(object $entity): void
    {
        $this->uow->reload($entity);
    }
}
