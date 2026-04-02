<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Event;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Event\LifecycleEvents;

final class LifecycleEventDispatcherPriorityTest extends TestCase
{
    public function test_listeners_execute_in_priority_order(): void
    {
        $dispatcher = new LifecycleEventDispatcher();
        $order = [];

        $dispatcher->addListener(LifecycleEvents::BEFORE_PUSH, function () use (&$order) {
            $order[] = 'low';
        }, -10);

        $dispatcher->addListener(LifecycleEvents::BEFORE_PUSH, function () use (&$order) {
            $order[] = 'high';
        }, 10);

        $dispatcher->addListener(LifecycleEvents::BEFORE_PUSH, function () use (&$order) {
            $order[] = 'normal';
        }, 0);

        $dispatcher->dispatchFlush(LifecycleEvents::BEFORE_PUSH);

        self::assertSame(['high', 'normal', 'low'], $order);
    }

    public function test_higher_priority_executes_first(): void
    {
        $dispatcher = new LifecycleEventDispatcher();
        $order = [];

        $dispatcher->addListener(LifecycleEvents::AFTER_PUSH, function () use (&$order) {
            $order[] = 'A';
        }, 100);

        $dispatcher->addListener(LifecycleEvents::AFTER_PUSH, function () use (&$order) {
            $order[] = 'B';
        }, 50);

        $dispatcher->addListener(LifecycleEvents::AFTER_PUSH, function () use (&$order) {
            $order[] = 'C';
        }, 200);

        $dispatcher->dispatchFlush(LifecycleEvents::AFTER_PUSH);

        self::assertSame(['C', 'A', 'B'], $order);
    }

    public function test_same_priority_maintains_registration_order(): void
    {
        $dispatcher = new LifecycleEventDispatcher();
        $order = [];

        $dispatcher->addListener(LifecycleEvents::BEFORE_PUSH, function () use (&$order) {
            $order[] = 'first';
        }, 0);

        $dispatcher->addListener(LifecycleEvents::BEFORE_PUSH, function () use (&$order) {
            $order[] = 'second';
        }, 0);

        $dispatcher->addListener(LifecycleEvents::BEFORE_PUSH, function () use (&$order) {
            $order[] = 'third';
        }, 0);

        $dispatcher->dispatchFlush(LifecycleEvents::BEFORE_PUSH);

        self::assertSame(['first', 'second', 'third'], $order);
    }
}
