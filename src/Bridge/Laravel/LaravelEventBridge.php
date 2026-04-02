<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel;

use Illuminate\Contracts\Events\Dispatcher;

final class LaravelEventBridge
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
    ) {}

    public function dispatch(string $eventName, object $event): void
    {
        $this->dispatcher->dispatch($eventName, [$event]);
    }
}
