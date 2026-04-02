---
id: time-travel
title: Zeitreise-Abfragen
sidebar_label: Time Travel
---

PyroSQL maintains a complete history of every row that has ever existed in the database. Time travel queries let you read data **as it appeared at any previous point in time** by appending an `AS OF` clause to the `FROM` clause of a `SELECT` statement. This is useful for audit log replay, point-in-time recovery, compliance reports, and debugging data mutations.

---

## How it works

A time travel query replaces the normal `FROM table alias` clause with:

```sql
FROM orders e AS OF TIMESTAMP '2024-01-01 12:00:00'
-- or
FROM orders e AS OF LSN 12345
```

PyroSQL intercepts the `AS OF` modifier and reads row versions from its WAL-backed history store instead of the current live data. The rest of the query — `WHERE`, `ORDER BY`, `LIMIT`, joins, aggregates — works identically to a normal query.

---

## `TimeTravelQueryBuilder`

`TimeTravelQueryBuilder` is a decorator around `EntityQueryBuilder` that injects the `AS OF` clause at execution time. All standard query-builder methods (`where`, `orderBy`, `limit`, etc.) are proxied to the inner builder unchanged.

### Getting a builder from a repository

The easiest way to obtain a `TimeTravelQueryBuilder` is through `PyroQueryBuilderExtension::queryAsOf()`. This creates a builder already scoped to the given timestamp:

```php
use App\Repository\OrderRepository;

$orders = $orderRepo->queryAsOf(new \DateTimeImmutable('2024-01-01 12:00:00'))
    ->where('status', 'active')
    ->orderBy('created_at', 'DESC')
    ->get();
```

You can also construct `TimeTravelQueryBuilder` directly if you need more control:

```php
use Weaver\ORM\PyroSQL\Query\TimeTravelQueryBuilder;
use Weaver\ORM\Query\EntityQueryBuilder;

$inner = new EntityQueryBuilder(/* ... */);
$ttqb  = new TimeTravelQueryBuilder($inner, 'orders');
```

---

## `->asOf(DateTimeImmutable $timestamp)`

Query data as it existed at a specific wall-clock time.

```php
$snapshot = $orderRepo
    ->queryAsOf(new \DateTimeImmutable('2024-06-15 09:00:00'))
    ->where('customer_id', 42)
    ->get();
```

Generates:

```sql
SELECT e.* FROM orders e AS OF TIMESTAMP '2024-06-15 09:00:00'
WHERE e.customer_id = 42
```

---

## `->asOfVersion(int $lsn)`

Query data as it existed at a specific WAL Log Sequence Number (LSN). LSNs are monotonically increasing integers that identify exact positions in the replication log. Use this when you need sub-second precision or when you captured the LSN at the time of a specific transaction.

```php
$snapshot = $orderRepo
    ->queryAsOf(new \DateTimeImmutable('now'))  // start fresh
    ->asOfVersion(98765432)
    ->where('status', 'pending')
    ->get();
```

Generates:

```sql
SELECT e.* FROM orders e AS OF LSN 98765432
WHERE e.status = 'pending'
```

---

## `->current()`

Remove any `AS OF` clause and return to querying live data. Useful when you have a shared builder instance and want to reset it:

```php
$ttqb = $orderRepo->queryAsOf(new \DateTimeImmutable('2023-01-01'));

// Query historical data
$historical = $ttqb->where('status', 'shipped')->get();

// Reset to live data
$live = $ttqb->current()->where('status', 'pending')->get();
```

---

## `->getAsOfExpression()`

Returns the raw `AS OF …` SQL fragment currently set on the builder, or `null` when querying current data. Useful for logging or debugging.

```php
$ttqb = $orderRepo->queryAsOf(new \DateTimeImmutable('2024-01-01'));

echo $ttqb->getAsOfExpression();
// "AS OF TIMESTAMP '2024-01-01 00:00:00'"
```

---

## How the session GUC `pyrosql.as_of_expr` works

For execution paths that go through DBAL internals (such as paginated queries that issue both a `COUNT` and a `SELECT`), `TimeTravelQueryBuilder` communicates the time context to PyroSQL via a session-level GUC (Grand Unified Configuration) variable:

```sql
SET LOCAL pyrosql.as_of_expr = 'AS OF TIMESTAMP ''2024-01-01 00:00:00''';
-- ... execute the query ...
RESET pyrosql.as_of_expr;
```

PyroSQL reads this session variable and applies the `AS OF` constraint to every statement executed within the same transaction until it is cleared. The `SET LOCAL` scope ensures the variable is automatically reset at the end of the transaction even if an exception occurs.

For simpler queries `toSQL()` injects the clause directly via string replacement instead:

```php
echo $ttqb->toSQL();
// SELECT e.* FROM orders e AS OF TIMESTAMP '2024-01-01 00:00:00' WHERE ...
```

---

## Full examples

### Audit log replay

Reconstruct the state of a user's account at the time a support ticket was filed:

```php
$ticketFiledAt = new \DateTimeImmutable('2024-03-10 14:23:00');

$user = $userRepo
    ->queryAsOf($ticketFiledAt)
    ->where('id', $userId)
    ->firstOrFail();

$activeSubscriptions = $subscriptionRepo
    ->queryAsOf($ticketFiledAt)
    ->where('user_id', $userId)
    ->where('status', 'active')
    ->get();

// $user and $activeSubscriptions reflect the exact database state
// at 2024-03-10 14:23:00, regardless of subsequent changes.
```

### Point-in-time recovery comparison

Compare the current state of a table against what it looked like before a bad migration:

```php
$beforeMigrationLsn = 50_000_000; // captured before running the migration

$currentOrders  = $orderRepo->query()->where('status', 'cancelled')->count();
$historicOrders = $orderRepo
    ->queryAsOf(new \DateTimeImmutable('now'))
    ->asOfVersion($beforeMigrationLsn)
    ->where('status', 'cancelled')
    ->count();

printf(
    'Cancelled orders changed from %d to %d after migration.',
    $historicOrders,
    $currentOrders,
);
```

### Paginated time-travel

All execution methods — `get()`, `first()`, `firstOrFail()`, `count()`, `exists()`, and `paginate()` — honour the `AS OF` clause:

```php
$page = $orderRepo
    ->queryAsOf(new \DateTimeImmutable('2024-01-01'))
    ->where('region', 'EU')
    ->orderBy('created_at', 'DESC')
    ->paginate(page: 1, perPage: 25);

foreach ($page->items() as $order) {
    // historical EU orders from 2024-01-01, page 1
}
```
