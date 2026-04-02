---
id: cdc
title: चेंज डेटा कैप्चर
sidebar_label: CDC
---

Change Data Capture (CDC) is a technique for observing every `INSERT`, `UPDATE`, and `DELETE` that occurs on a database table, in real time, without polling. PyroSQL exposes a streaming `SUBSCRIBE TO CHANGES ON <table>` SQL command that yields rows as DML operations are committed to the WAL (Write-Ahead Log). Weaver ORM wraps this mechanism in `CdcSubscriber` and hydrates each row into a typed `CdcEvent`.

**Common use cases:**

- **Audit logs** — record every change to sensitive tables without modifying application code.
- **Cache invalidation** — invalidate Redis or Memcached keys the moment a row changes.
- **Real-time sync** — propagate changes to downstream systems such as Elasticsearch or a message queue.
- **Event sourcing** — treat the WAL as an ordered event stream.

---

## `CdcSubscriber`

```php
use Weaver\ORM\PyroSQL\Cdc\CdcSubscriber;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

$driver     = new PyroSqlDriver($connection);
$subscriber = new CdcSubscriber($connection, $driver);
```

### `subscribe(string $table, ?string $from = 'latest'): Generator`

Subscribe to changes on a single table. Returns a `Generator` that yields `CdcEvent` objects as DML operations are committed. The generator is long-running; use `break` to stop consuming events.

- `$from = 'latest'` — receive only events that occur after the subscription is opened.
- `$from = '12345678'` — replay events from WAL LSN `12345678` onwards.
- `$from = null` — use the server default start position.

```php
foreach ($subscriber->subscribe('orders') as $event) {
    // handle event
    if ($event->isInsert()) {
        echo 'New order: ' . $event->after['id'] . "\n";
    }
}
```

Executes:
```sql
SUBSCRIBE TO CHANGES ON "orders" FROM latest
```

Replay from a specific LSN:
```php
foreach ($subscriber->subscribe('orders', from: '50000000') as $event) {
    // replay events from LSN 50000000
}
```

```sql
SUBSCRIBE TO CHANGES ON "orders" FROM 50000000
```

### `subscribeMany(array $tables, ?string $from = 'latest'): Generator`

Subscribe to changes on multiple tables simultaneously. PyroSQL multiplexes the streams; each yielded `CdcEvent` contains the originating table name in `$event->table`.

```php
foreach ($subscriber->subscribeMany(['orders', 'order_items', 'customers']) as $event) {
    match ($event->table) {
        'orders'      => $this->handleOrderChange($event),
        'order_items' => $this->handleItemChange($event),
        'customers'   => $this->handleCustomerChange($event),
        default       => null,
    };
}
```

Executes:
```sql
SUBSCRIBE TO CHANGES ON "orders", "order_items", "customers" FROM latest
```

---

## `CdcEvent`

`CdcEvent` is a readonly value object that captures a single DML operation. It is hydrated from the raw CDC result row returned by PyroSQL.

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `$operation` | `string` | `'INSERT'`, `'UPDATE'`, or `'DELETE'` |
| `$table` | `string` | Fully-qualified table name |
| `$before` | `array` | Column values before the change (empty for INSERT) |
| `$after` | `array` | Column values after the change (empty for DELETE) |
| `$lsn` | `int` | WAL Log Sequence Number — monotonically increasing |
| `$transactionId` | `string` | Unique identifier of the originating transaction |
| `$timestamp` | `DateTimeImmutable` | Wall-clock commit time |

### Operation predicates

```php
$event->isInsert(); // bool
$event->isUpdate(); // bool
$event->isDelete(); // bool
```

### `getChangedFields(): string[]`

Returns the names of columns whose values changed.

- For `INSERT`: all keys in `$after` (every column was "added").
- For `DELETE`: all keys in `$before` (every column was "removed").
- For `UPDATE`: only columns where `before[field] !== after[field]`.

```php
if ($event->isUpdate()) {
    $changed = $event->getChangedFields();
    // e.g. ['status', 'updated_at']
}
```

---

## Full example: audit trail for the `orders` table

This example records every change to the `orders` table into an `order_audit_log` table, including who changed what and when.

```php
use Weaver\ORM\PyroSQL\Cdc\CdcSubscriber;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

class OrderAuditWorker
{
    public function __construct(
        private readonly CdcSubscriber $subscriber,
        private readonly \Doctrine\DBAL\Connection $auditConnection,
    ) {}

    public function run(): void
    {
        foreach ($this->subscriber->subscribe('orders') as $event) {
            $this->auditConnection->insert('order_audit_log', [
                'operation'      => $event->operation,
                'order_id'       => $event->after['id'] ?? $event->before['id'] ?? null,
                'changed_fields' => implode(',', $event->getChangedFields()),
                'before_state'   => json_encode($event->before),
                'after_state'    => json_encode($event->after),
                'lsn'            => $event->lsn,
                'transaction_id' => $event->transactionId,
                'committed_at'   => $event->timestamp->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
```

### Cache invalidation example

Invalidate a product cache key whenever a product row is updated or deleted:

```php
foreach ($subscriber->subscribe('products') as $event) {
    if ($event->isUpdate() || $event->isDelete()) {
        $productId = $event->before['id'];
        $cache->delete("product:{$productId}");

        // Also invalidate category listing cache if the category changed
        if (in_array('category_id', $event->getChangedFields(), true)) {
            $oldCategoryId = $event->before['category_id'];
            $newCategoryId = $event->after['category_id'] ?? null;
            $cache->delete("category:{$oldCategoryId}:products");
            if ($newCategoryId !== null) {
                $cache->delete("category:{$newCategoryId}:products");
            }
        }
    }
}
```

### Replaying events from a checkpoint

Store the last-processed LSN to resume from where you left off after a restart:

```php
$lastLsn = $checkpointStore->getLastLsn('orders-worker') ?? 'latest';

foreach ($subscriber->subscribe('orders', from: (string) $lastLsn) as $event) {
    $this->process($event);

    // Persist the checkpoint after each successfully processed event
    $checkpointStore->save('orders-worker', $event->lsn);
}
```
