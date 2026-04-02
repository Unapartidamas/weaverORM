<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\Cdc\CdcEvent;
use Weaver\ORM\PyroSQL\Cdc\CdcSubscriber;
use Weaver\ORM\PyroSQL\Exception\UnsupportedDriverFeatureException;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

final class CdcSubscriberTest extends TestCase
{






    private function makeSubscriber(
        array &$executedSqls,
        array $rows,
        bool $driverSupports = true,
    ): CdcSubscriber {

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(...[...$rows, false]);



        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')
            ->willReturnCallback(static fn (string $id): string => '"' . $id . '"');
        $connection->method('query')
            ->willReturnCallback(function (string $sql) use (&$executedSqls, $result): Result {
                $executedSqls[] = $sql;

                return $result;
            });



        $driverVersion = $driverSupports ? '1.0.0' : '';
        $connection->method('fetchAssociative')
            ->willReturn(['v' => $driverVersion]);

        $driver = new PyroSqlDriver($connection);

        return new CdcSubscriber($connection, $driver);
    }





    public function test_subscribe_builds_sql_with_quoted_table_name(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, []);

        iterator_to_array($subscriber->subscribe('users'));

        self::assertCount(1, $sqls);
        self::assertStringContainsString('"users"', $sqls[0]);
        self::assertStringStartsWith('SUBSCRIBE TO CHANGES ON', $sqls[0]);
    }

    public function test_subscribe_with_from_latest_appends_from_latest(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, []);

        iterator_to_array($subscriber->subscribe('orders', 'latest'));

        self::assertStringEndsWith('FROM latest', $sqls[0]);
    }

    public function test_subscribe_with_numeric_lsn_appends_from_lsn(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, []);

        iterator_to_array($subscriber->subscribe('orders', '12345'));

        self::assertStringEndsWith('FROM 12345', $sqls[0]);
    }

    public function test_subscribe_with_from_null_does_not_append_from_clause(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, []);

        iterator_to_array($subscriber->subscribe('orders', null));

        self::assertStringNotContainsString('FROM', $sqls[0]);
    }







    public function test_subscribe_with_non_numeric_from_coerces_to_zero(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, []);

        iterator_to_array($subscriber->subscribe('users', 'not-a-number'));

        self::assertStringEndsWith('FROM 0', $sqls[0]);
    }





    public function test_subscribe_returns_empty_generator_when_result_has_no_rows(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, []);

        $events = iterator_to_array($subscriber->subscribe('empty_table'));

        self::assertSame([], $events);
    }





    public function test_subscribe_yields_hydrated_cdc_event_from_row(): void
    {
        $row = [
            'operation'      => 'UPDATE',
            'table_name'     => 'users',
            'before'         => '{"name":"Alice","age":30}',
            'after'          => '{"name":"Bob","age":30}',
            'lsn'            => 99,
            'transaction_id' => 'tx-abc',
            'committed_at'   => '2024-06-01T12:00:00+00:00',
        ];

        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, [$row]);
        $events = iterator_to_array($subscriber->subscribe('users'));

        self::assertCount(1, $events);

        $event = $events[0];
        self::assertInstanceOf(CdcEvent::class, $event);
        self::assertSame('UPDATE', $event->operation);
        self::assertSame('users', $event->table);
        self::assertSame(['name' => 'Alice', 'age' => 30], $event->before);
        self::assertSame(['name' => 'Bob', 'age' => 30], $event->after);
        self::assertSame(99, $event->lsn);
        self::assertSame('tx-abc', $event->transactionId);
        self::assertSame('2024-06-01T12:00:00+00:00', $event->timestamp->format(\DateTimeInterface::ATOM));
    }





    public function test_hydrate_insert_event_has_empty_before_array(): void
    {
        $row = [
            'operation'      => 'INSERT',
            'table_name'     => 'users',
            'before'         => null,
            'after'          => '{"id":1,"name":"Alice"}',
            'lsn'            => 1,
            'transaction_id' => 'tx-1',
            'committed_at'   => '2024-01-01T00:00:00+00:00',
        ];

        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, [$row]);
        $events = iterator_to_array($subscriber->subscribe('users'));

        $event = $events[0];
        self::assertTrue($event->isInsert());
        self::assertSame([], $event->before);
        self::assertSame(['id' => 1, 'name' => 'Alice'], $event->after);
    }

    public function test_hydrate_delete_event_has_empty_after_array(): void
    {
        $row = [
            'operation'      => 'DELETE',
            'table_name'     => 'users',
            'before'         => '{"id":1,"name":"Alice"}',
            'after'          => null,
            'lsn'            => 2,
            'transaction_id' => 'tx-2',
            'committed_at'   => '2024-01-01T00:00:00+00:00',
        ];

        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, [$row]);
        $events = iterator_to_array($subscriber->subscribe('users'));

        $event = $events[0];
        self::assertTrue($event->isDelete());
        self::assertSame(['id' => 1, 'name' => 'Alice'], $event->before);
        self::assertSame([], $event->after);
    }





    public function test_hydrate_uses_datetime_now_when_committed_at_absent(): void
    {
        $before = new \DateTimeImmutable();

        $row = [
            'operation'      => 'INSERT',
            'table_name'     => 'items',
            'before'         => null,
            'after'          => '{"id":7}',
            'lsn'            => 3,
            'transaction_id' => 'tx-3',

        ];

        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, [$row]);
        $events = iterator_to_array($subscriber->subscribe('items'));

        $after = new \DateTimeImmutable();

        $event = $events[0];
        self::assertGreaterThanOrEqual($before, $event->timestamp);
        self::assertLessThanOrEqual($after, $event->timestamp);
    }





    public function test_hydrate_uppercases_operation_string(): void
    {
        $row = [
            'operation'      => 'insert',
            'table_name'     => 'users',
            'before'         => null,
            'after'          => '{"id":5}',
            'lsn'            => 20,
            'transaction_id' => 'tx-uc',
            'committed_at'   => '2024-01-01T00:00:00+00:00',
        ];

        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, [$row]);
        $events = iterator_to_array($subscriber->subscribe('users'));

        self::assertSame('INSERT', $events[0]->operation);
    }

    public function test_hydrate_defaults_lsn_to_zero_when_absent(): void
    {
        $row = [
            'operation'      => 'INSERT',
            'table_name'     => 'items',
            'before'         => null,
            'after'          => null,
            'transaction_id' => 'tx-nolsn',
            'committed_at'   => '2024-01-01T00:00:00+00:00',

        ];

        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, [$row]);
        $events = iterator_to_array($subscriber->subscribe('items'));

        self::assertSame(0, $events[0]->lsn);
    }





    public function test_subscribe_many_builds_sql_with_all_tables_quoted(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, []);

        iterator_to_array($subscriber->subscribeMany(['users', 'orders', 'products']));

        self::assertCount(1, $sqls);
        self::assertStringContainsString('"users"', $sqls[0]);
        self::assertStringContainsString('"orders"', $sqls[0]);
        self::assertStringContainsString('"products"', $sqls[0]);
        self::assertStringStartsWith('SUBSCRIBE TO CHANGES ON', $sqls[0]);
    }

    public function test_subscribe_many_with_numeric_lsn_appends_from_lsn(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, []);

        iterator_to_array($subscriber->subscribeMany(['events'], '9999'));

        self::assertStringEndsWith('FROM 9999', $sqls[0]);
    }

    public function test_subscribe_many_with_from_null_omits_from_clause(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, []);

        iterator_to_array($subscriber->subscribeMany(['events'], null));

        self::assertStringNotContainsString('FROM', $sqls[0]);
    }





    public function test_subscribe_many_yields_cdc_event_objects_from_rows(): void
    {
        $rows = [
            [
                'operation'      => 'INSERT',
                'table_name'     => 'users',
                'before'         => null,
                'after'          => '{"id":1}',
                'lsn'            => 10,
                'transaction_id' => 'tx-m1',
                'committed_at'   => '2024-03-01T00:00:00+00:00',
            ],
            [
                'operation'      => 'DELETE',
                'table_name'     => 'orders',
                'before'         => '{"id":2}',
                'after'          => null,
                'lsn'            => 11,
                'transaction_id' => 'tx-m2',
                'committed_at'   => '2024-03-01T00:01:00+00:00',
            ],
        ];

        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, $rows);
        $events = iterator_to_array($subscriber->subscribeMany(['users', 'orders']));

        self::assertCount(2, $events);
        self::assertInstanceOf(CdcEvent::class, $events[0]);
        self::assertSame('INSERT', $events[0]->operation);
        self::assertSame('users', $events[0]->table);
        self::assertInstanceOf(CdcEvent::class, $events[1]);
        self::assertSame('DELETE', $events[1]->operation);
        self::assertSame('orders', $events[1]->table);
    }





    public function test_subscribe_many_throws_when_driver_does_not_support_cdc(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, [], driverSupports: false);

        $this->expectException(UnsupportedDriverFeatureException::class);


        iterator_to_array($subscriber->subscribeMany(['users']));
    }

    public function test_subscribe_throws_when_driver_does_not_support_cdc(): void
    {
        $sqls = [];
        $subscriber = $this->makeSubscriber($sqls, [], driverSupports: false);

        $this->expectException(UnsupportedDriverFeatureException::class);

        iterator_to_array($subscriber->subscribe('users'));
    }
}
