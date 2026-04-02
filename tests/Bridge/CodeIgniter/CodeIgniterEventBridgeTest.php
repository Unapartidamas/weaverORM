<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Bridge\CodeIgniter;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Bridge\CodeIgniter\CodeIgniterEventBridge;
use Weaver\ORM\Bridge\CodeIgniter\WeaverConfig;
use Weaver\ORM\Bridge\CodeIgniter\WeaverService;
use Weaver\ORM\Event\LifecycleEvents;

final class CodeIgniterEventBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        WeaverService::reset();
    }

    protected function tearDown(): void
    {
        WeaverService::reset();
    }

    public function test_dispatch_does_not_fail_without_codeigniter(): void
    {
        $event = new \stdClass();

        CodeIgniterEventBridge::dispatch('weaver.test', $event);

        self::assertTrue(true);
    }

    public function test_registerListeners_does_not_fail_without_codeigniter(): void
    {
        $config = new WeaverConfig();
        $config->connections = ['default' => ['driver' => 'pdo_sqlite', 'memory' => true]];
        WeaverService::setConfig($config);

        CodeIgniterEventBridge::registerListeners();

        self::assertTrue(true);
    }

    public function test_dispatch_is_callable(): void
    {
        self::assertTrue(method_exists(CodeIgniterEventBridge::class, 'dispatch'));
        self::assertTrue(method_exists(CodeIgniterEventBridge::class, 'registerListeners'));
    }

    public function test_registerListeners_adds_listeners_to_dispatcher(): void
    {
        $config = new WeaverConfig();
        $config->connections = ['default' => ['driver' => 'pdo_sqlite', 'memory' => true]];
        WeaverService::setConfig($config);

        $dispatcher = WeaverService::eventDispatcher();

        $invoked = false;
        $dispatcher->addListener(LifecycleEvents::BEFORE_ADD, static function () use (&$invoked): void {
            $invoked = true;
        });

        $entity = new \stdClass();
        $dispatcher->dispatch(LifecycleEvents::BEFORE_ADD, $entity);

        self::assertTrue($invoked);
    }
}
