<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\CodeIgniter;

use Weaver\ORM\Event\LifecycleEvents;

final class CodeIgniterEventBridge
{
    public static function dispatch(string $eventName, object $event): void
    {
        if (!class_exists('CodeIgniter\\Events\\Events') || !defined('APPPATH')) {
            return;
        }

        try {
            \CodeIgniter\Events\Events::trigger($eventName, $event);
        } catch (\Throwable) {
        }
    }

    public static function registerListeners(): void
    {
        if (!class_exists('CodeIgniter\\Events\\Events')) {
            return;
        }

        $dispatcher = WeaverService::eventDispatcher();

        $events = [
            LifecycleEvents::BEFORE_ADD,
            LifecycleEvents::AFTER_ADD,
            LifecycleEvents::BEFORE_UPDATE,
            LifecycleEvents::AFTER_UPDATE,
            LifecycleEvents::BEFORE_DELETE,
            LifecycleEvents::AFTER_DELETE,
            LifecycleEvents::AFTER_LOAD,
            LifecycleEvents::ON_PUSH,
        ];

        foreach ($events as $eventName) {
            $dispatcher->addListener($eventName, static function (object $event) use ($eventName): void {
                self::dispatch($eventName, $event);
            });
        }
    }
}
