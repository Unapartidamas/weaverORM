<?php

declare(strict_types=1);

namespace Weaver\ORM\Event;

use Weaver\ORM\Persistence\UnitOfWork;

final class OnFlushEvent
{
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
    ) {}

    public function getScheduledEntityInserts(): array
    {
        return $this->unitOfWork->getScheduledEntityInserts();
    }

    public function getScheduledEntityUpdates(): array
    {
        return $this->unitOfWork->getScheduledEntityUpdates();
    }

    public function getScheduledEntityDeletes(): array
    {
        return $this->unitOfWork->getScheduledEntityDeletes();
    }

    public function scheduleForInsert(object $entity): void
    {
        $this->unitOfWork->add($entity);
    }

    public function recomputeChangeset(object $entity): void
    {
        $this->unitOfWork->recomputeSingleEntityChangeset($entity);
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }
}
