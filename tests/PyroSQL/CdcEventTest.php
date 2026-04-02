<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\Cdc\CdcEvent;

final class CdcEventTest extends TestCase
{
    private function makeEvent(string $operation, array $before = [], array $after = []): CdcEvent
    {
        return new CdcEvent(
            operation: $operation,
            table: 'users',
            before: $before,
            after: $after,
            lsn: 1,
            transactionId: 'tx1',
            timestamp: new \DateTimeImmutable(),
        );
    }

    public function test_is_insert_returns_true_for_insert_operation(): void
    {
        $event = $this->makeEvent('INSERT', [], ['name' => 'Alice']);

        self::assertTrue($event->isInsert());
    }

    public function test_is_update_returns_true_for_update_operation(): void
    {
        $event = $this->makeEvent('UPDATE', ['name' => 'Alice'], ['name' => 'Bob']);

        self::assertTrue($event->isUpdate());
    }

    public function test_is_delete_returns_true_for_delete_operation(): void
    {
        $event = $this->makeEvent('DELETE', ['name' => 'Alice']);

        self::assertTrue($event->isDelete());
    }

    public function test_is_insert_returns_false_for_non_insert(): void
    {
        $event = $this->makeEvent('UPDATE', ['name' => 'Alice'], ['name' => 'Bob']);

        self::assertFalse($event->isInsert());
    }

    public function test_get_changed_fields_for_insert_returns_all_after_keys(): void
    {
        $event = $this->makeEvent('INSERT', [], ['name' => 'Alice', 'age' => 30]);

        self::assertSame(['name', 'age'], $event->getChangedFields());
    }

    public function test_get_changed_fields_for_delete_returns_all_before_keys(): void
    {
        $event = $this->makeEvent('DELETE', ['name' => 'Alice', 'age' => 30]);

        self::assertSame(['name', 'age'], $event->getChangedFields());
    }

    public function test_get_changed_fields_for_update_returns_only_changed_fields(): void
    {
        $event = $this->makeEvent(
            'UPDATE',
            before: ['name' => 'Alice', 'age' => 30],
            after: ['name' => 'Bob', 'age' => 30],
        );

        self::assertSame(['name'], $event->getChangedFields());
    }

    public function test_get_changed_fields_for_update_returns_empty_when_nothing_changed(): void
    {
        $event = $this->makeEvent(
            'UPDATE',
            before: ['name' => 'Alice', 'age' => 30],
            after: ['name' => 'Alice', 'age' => 30],
        );

        self::assertSame([], $event->getChangedFields());
    }

    public function test_get_changed_fields_for_update_when_field_exists_only_in_after(): void
    {


        $event = $this->makeEvent(
            'UPDATE',
            before: ['name' => 'Alice'],
            after: ['name' => 'Alice', 'nickname' => 'Ally'],
        );

        self::assertSame(['nickname'], $event->getChangedFields());
    }

    public function test_get_changed_fields_for_update_when_field_exists_only_in_before(): void
    {


        $event = $this->makeEvent(
            'UPDATE',
            before: ['name' => 'Alice', 'nickname' => 'Ally'],
            after: ['name' => 'Alice'],
        );

        self::assertSame(['nickname'], $event->getChangedFields());
    }





    public function test_is_delete_returns_false_for_non_delete(): void
    {
        $event = $this->makeEvent('INSERT', [], ['name' => 'Alice']);

        self::assertFalse($event->isDelete());
    }

    public function test_is_update_returns_false_for_non_update(): void
    {
        $event = $this->makeEvent('DELETE', ['name' => 'Alice']);

        self::assertFalse($event->isUpdate());
    }

    public function test_operation_string_is_exposed_as_public_property(): void
    {
        $event = $this->makeEvent('UPDATE');

        self::assertSame('UPDATE', $event->operation);
    }

    public function test_operation_is_case_sensitive_for_predicates(): void
    {



        $event = $this->makeEvent('insert');

        self::assertFalse($event->isInsert());
        self::assertFalse($event->isUpdate());
        self::assertFalse($event->isDelete());
    }





    public function test_get_changed_fields_treats_numeric_string_equal_to_integer(): void
    {


        $event = $this->makeEvent(
            'UPDATE',
            before: ['age' => '30'],
            after:  ['age' => 30],
        );

        self::assertSame([], $event->getChangedFields());
    }

    public function test_get_changed_fields_for_update_with_all_fields_changed(): void
    {
        $event = $this->makeEvent(
            'UPDATE',
            before: ['a' => 1, 'b' => 2, 'c' => 3],
            after:  ['a' => 9, 'b' => 8, 'c' => 7],
        );

        self::assertSame(['a', 'b', 'c'], $event->getChangedFields());
    }

    public function test_get_changed_fields_for_insert_with_empty_after_returns_empty(): void
    {
        $event = $this->makeEvent('INSERT', [], []);

        self::assertSame([], $event->getChangedFields());
    }

    public function test_get_changed_fields_for_delete_with_empty_before_returns_empty(): void
    {
        $event = $this->makeEvent('DELETE', [], []);

        self::assertSame([], $event->getChangedFields());
    }





    public function test_public_properties_are_accessible(): void
    {
        $timestamp = new \DateTimeImmutable('2024-06-01');

        $event = new CdcEvent(
            operation:     'INSERT',
            table:         'payments',
            before:        [],
            after:         ['amount' => 100],
            lsn:           77,
            transactionId: 'tx-prop',
            timestamp:     $timestamp,
        );

        self::assertSame('INSERT', $event->operation);
        self::assertSame('payments', $event->table);
        self::assertSame([], $event->before);
        self::assertSame(['amount' => 100], $event->after);
        self::assertSame(77, $event->lsn);
        self::assertSame('tx-prop', $event->transactionId);
        self::assertSame($timestamp, $event->timestamp);
    }

    public function test_null_value_changing_to_string_is_reported_as_changed(): void
    {

        $event = $this->makeEvent(
            'UPDATE',
            before: ['description' => null],
            after:  ['description' => 'hello'],
        );

        self::assertSame(['description'], $event->getChangedFields());
    }
}
