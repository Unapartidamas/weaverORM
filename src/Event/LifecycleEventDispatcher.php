<?php

declare(strict_types=1);

namespace Weaver\ORM\Event;

final class LifecycleEventDispatcher
{

    private array $listeners = [];

    private array $entityListenerObjects = [];

    private array $sorted = [];

    public function __construct(private readonly ?object $symfonyDispatcher = null)
    {
    }

    public function addListener(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventName][] = ['listener' => $listener, 'priority' => $priority];
        unset($this->sorted[$eventName]);
    }

    public function addEntityListener(string $entityClass, object $listener, string $method, string $eventName, int $priority = 0): void
    {
        $key = $entityClass . '.' . $eventName;
        $this->listeners[$key][] = ['listener' => [$listener, $method], 'priority' => $priority];
        unset($this->sorted[$key]);
    }

    public function addEntityListenerObject(string $entityClass, object $listener): void
    {
        $this->entityListenerObjects[$entityClass][] = $listener;
    }

    public function dispatch(string $eventName, object $entity, ?array $changeset = null): void
    {
        $event = new LifecycleEvent(
            eventName: $eventName,
            entity: $entity,
            entityClass: $entity::class,
            changeset: $changeset,
        );

        foreach ($this->getSortedListeners($eventName) as $listener) {
            $listener($event);
        }

        $entityKey = $entity::class . '.' . $eventName;
        foreach ($this->getSortedListeners($entityKey) as $listener) {
            $listener($event);
        }

        $methodName = $this->eventNameToMethod($eventName);
        foreach ($this->entityListenerObjects[$entity::class] ?? [] as $listenerObject) {
            if (method_exists($listenerObject, $methodName)) {

                if ($eventName === LifecycleEvents::BEFORE_UPDATE) {
                    $listenerObject->{$methodName}($entity, $changeset ?? []);
                } else {
                    $listenerObject->{$methodName}($entity);
                }
            }
        }

        if ($this->symfonyDispatcher instanceof \Symfony\Contracts\EventDispatcher\EventDispatcherInterface) {
            $this->symfonyDispatcher->dispatch($event, $eventName);
        }
    }

    public function dispatchFlush(string $eventName): void
    {
        foreach ($this->getSortedListeners($eventName) as $listener) {
            $listener();
        }

        if ($this->symfonyDispatcher instanceof \Symfony\Contracts\EventDispatcher\EventDispatcherInterface) {

            $this->symfonyDispatcher->dispatch(new \stdClass(), $eventName);
        }
    }

    public function dispatchOnFlush(OnFlushEvent $event): void
    {
        foreach ($this->getSortedListeners(LifecycleEvents::ON_PUSH) as $listener) {
            $listener($event);
        }

        if ($this->symfonyDispatcher instanceof \Symfony\Contracts\EventDispatcher\EventDispatcherInterface) {
            $this->symfonyDispatcher->dispatch($event, LifecycleEvents::ON_PUSH);
        }
    }

    public function clearListeners(): void
    {
        $this->listeners = [];
        $this->entityListenerObjects = [];
        $this->sorted = [];
    }

    private function getSortedListeners(string $eventName): array
    {
        if (isset($this->sorted[$eventName])) {
            return $this->sorted[$eventName];
        }

        $entries = $this->listeners[$eventName] ?? [];

        if ($entries === []) {
            return $this->sorted[$eventName] = [];
        }

        usort($entries, static fn (array $a, array $b) => $b['priority'] <=> $a['priority']);

        $this->sorted[$eventName] = array_column($entries, 'listener');

        return $this->sorted[$eventName];
    }

    private function eventNameToMethod(string $eventName): string
    {

        $suffix = str_replace('weaver.', '', $eventName);

        return lcfirst(str_replace('_', '', ucwords($suffix, '_')));
    }
}
