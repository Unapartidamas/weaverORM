<?php

declare(strict_types=1);

namespace Weaver\ORM\Event;

final readonly class LifecycleEvent
{
    public function __construct(
        private string $eventName,
        private object $entity,
        private string $entityClass,

        private ?array $changeset = null,
    ) {}

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getEntity(): object
    {
        return $this->entity;
    }

    public function getEntityClass(): string
    {

        $class = $this->entityClass;

        return $class;
    }

    public function getChangeset(): ?array
    {
        return $this->changeset;
    }

    public function hasChangeset(): bool
    {
        return $this->changeset !== null;
    }

    public function isPersist(): bool
    {
        return $this->eventName === LifecycleEvents::BEFORE_ADD
            || $this->eventName === LifecycleEvents::AFTER_ADD;
    }

    public function isUpdate(): bool
    {
        return $this->eventName === LifecycleEvents::BEFORE_UPDATE
            || $this->eventName === LifecycleEvents::AFTER_UPDATE;
    }

    public function isRemove(): bool
    {
        return $this->eventName === LifecycleEvents::BEFORE_DELETE
            || $this->eventName === LifecycleEvents::AFTER_DELETE;
    }

    public function isLoad(): bool
    {
        return $this->eventName === LifecycleEvents::AFTER_LOAD;
    }
}
