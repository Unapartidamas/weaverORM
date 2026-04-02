<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\PyroSQL\PubSub\PubSubManager;

final class PubSubManagerTest extends TestCase
{
    private function makeConnection(): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('quoteIdentifier')
            ->willReturnCallback(static fn(string $id): string => '"' . $id . '"');

        $connection->method('quote')
            ->willReturnCallback(static fn(string $v): string => "'" . addslashes($v) . "'");

        return $connection;
    }

    public function test_publish_executes_notify(): void
    {
        $connection = $this->makeConnection();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('NOTIFY "orders", \'row inserted\'');

        $manager = new PubSubManager($connection);
        $manager->publish('orders', 'row inserted');
    }

    public function test_subscribe_executes_listen(): void
    {
        $connection = $this->makeConnection();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('LISTEN "orders"');

        $manager = new PubSubManager($connection);
        $manager->subscribe('orders');
    }

    public function test_unsubscribe_executes_unlisten(): void
    {
        $connection = $this->makeConnection();

        $connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql): int {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    $this->assertSame('LISTEN "orders"', $sql);
                } else {
                    $this->assertSame('UNLISTEN "orders"', $sql);
                }
                return 0;
            });

        $manager = new PubSubManager($connection);
        $manager->subscribe('orders');
        $manager->unsubscribe('orders');
    }

    public function test_unsubscribeAll_executes_unlisten_star(): void
    {
        $connection = $this->makeConnection();

        $connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql): int {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    $this->assertSame('LISTEN "events"', $sql);
                } else {
                    $this->assertSame('UNLISTEN *', $sql);
                }
                return 0;
            });

        $manager = new PubSubManager($connection);
        $manager->subscribe('events');
        $manager->unsubscribeAll();

        self::assertSame([], $manager->getSubscribedChannels());
    }

    public function test_getSubscribedChannels_tracks_subscriptions(): void
    {
        $connection = $this->makeConnection();
        $connection->method('executeStatement')->willReturn(0);

        $manager = new PubSubManager($connection);

        self::assertSame([], $manager->getSubscribedChannels());

        $manager->subscribe('orders');
        $manager->subscribe('events');

        self::assertEqualsCanonicalizing(['orders', 'events'], $manager->getSubscribedChannels());

        $manager->unsubscribe('orders');

        self::assertSame(['events'], $manager->getSubscribedChannels());
    }
}
