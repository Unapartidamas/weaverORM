<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Bridge\Laravel;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Bridge\Laravel\LaravelEventBridge;

final class LaravelEventBridgeTest extends TestCase
{
    private object $dispatcher;
    private LaravelEventBridge $bridge;

    protected function setUp(): void
    {
        if (!interface_exists(\Illuminate\Contracts\Events\Dispatcher::class)) {
            self::markTestSkipped('illuminate/events not installed.');
        }

        $this->dispatcher = $this->createMock(\Illuminate\Contracts\Events\Dispatcher::class);
        $this->bridge = new LaravelEventBridge($this->dispatcher);
    }

    public function test_dispatch_fires_event_on_laravel_dispatcher(): void
    {
        $event = new \stdClass();

        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with('weaver.before_add', [$event]);

        $this->bridge->dispatch('weaver.before_add', $event);
    }

    public function test_dispatch_passes_event_object(): void
    {
        $event = new \stdClass();
        $event->entity = 'test_entity';

        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                'weaver.after_add',
                self::callback(fn (array $args) => $args[0] === $event && $args[0]->entity === 'test_entity'),
            );

        $this->bridge->dispatch('weaver.after_add', $event);
    }
}
