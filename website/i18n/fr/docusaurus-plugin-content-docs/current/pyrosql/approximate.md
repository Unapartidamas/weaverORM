---
id: approximate
title: Requêtes Approximatives
sidebar_label: Approximate Queries
---

Approximate Query Processing (AQP) lets PyroSQL answer aggregate questions — `COUNT`, `SUM`, `AVG` — by scanning a statistical sample of the data instead of reading every row. The result is returned in milliseconds even on tables with billions of rows, at the cost of a small, bounded error margin.

PyroSQL always reports the actual error and confidence level alongside the approximate value, so you can decide programmatically whether the result is precise enough for your use case.

**When to use AQP:**

- **Analytics dashboards** that refresh every few seconds and can tolerate ±2 % error.
- **Real-time metrics** where a response in 20 ms is more valuable than exact precision.
- **Capacity planning** queries on very large tables where full scans are prohibitively slow.
- **Exploratory analysis** before deciding whether to run a more expensive exact query.

---

## `ApproximateQueryBuilder`

`ApproximateQueryBuilder` is a decorator around `EntityQueryBuilder` that issues `SELECT APPROXIMATE … WITHIN n% CONFIDENCE m%` queries against PyroSQL's AQP engine.

### Getting a builder from a repository

Use `PyroQueryBuilderExtension::approximate()` on any repository that mixes in the trait:

```php
$result = $orderRepo->approximate(within: 2.0, confidence: 99.0)
    ->where('status', 'shipped')
    ->count();
```

You can also construct the builder directly:

```php
use Weaver\ORM\PyroSQL\Approximate\ApproximateQueryBuilder;

$qb     = $orderRepo->query();
$aqp    = new ApproximateQueryBuilder($qb, $connection, within: 5.0, confidence: 95.0);
```

Default values when not specified: `within: 5.0`, `confidence: 95.0`.

---

## Configuration methods

### `->within(float $percent)`

Override the maximum relative error tolerance as a percentage. Returns a new immutable instance.

```php
$aqp = $orderRepo->approximate()->within(1.0); // ±1 % error
```

### `->confidence(float $percent)`

Override the confidence level as a percentage. Higher confidence requires a larger sample. Returns a new immutable instance.

```php
$aqp = $orderRepo->approximate()->confidence(99.0); // 99 % confidence
```

### `->withFallback()`

Enable graceful degradation to an exact SQL aggregate when the AQP engine is not available or the query fails. Returns a modified copy of the builder.

```php
$result = $orderRepo
    ->approximate(within: 2.0, confidence: 95.0)
    ->withFallback()
    ->where('region', 'EU')
    ->count();

// If PyroSQL AQP fails, executes: SELECT COUNT(*) FROM orders WHERE region = 'EU'
// $result->isApproximate will be false in that case.
```

---

## Aggregate methods

All aggregate methods return an `ApproximateResult` value object.

### `->count(): ApproximateResult`

```php
$result = $orderRepo->approximate(within: 2.0, confidence: 99.0)
    ->where('status', 'pending')
    ->count();
```

Generates:
```sql
SELECT APPROXIMATE COUNT(*) WITHIN 2% CONFIDENCE 99%
FROM orders e
WHERE e.status = 'pending'
```

### `->sum(string $column): ApproximateResult`

```php
$result = $orderRepo->approximate(within: 3.0, confidence: 95.0)
    ->where('created_at', '>=', '2024-01-01')
    ->sum('total_amount');
```

Generates:
```sql
SELECT APPROXIMATE SUM(total_amount) WITHIN 3% CONFIDENCE 95%
FROM orders e
WHERE e.created_at >= '2024-01-01'
```

### `->avg(string $column): ApproximateResult`

```php
$result = $productRepo->approximate(within: 5.0)
    ->where('category', 'electronics')
    ->avg('price');
```

Generates:
```sql
SELECT APPROXIMATE AVG(price) WITHIN 5% CONFIDENCE 95%
FROM products e
WHERE e.category = 'electronics'
```

---

## `ApproximateResult`

`ApproximateResult` is a readonly value object that encapsulates the aggregate value and the statistical metadata PyroSQL attaches to every AQP result.

| Property | Type | Description |
|----------|------|-------------|
| `$value` | `mixed` | The aggregate value (count, sum, avg, …) |
| `$errorMargin` | `float` | Relative error as a percentage, e.g. `2.3` = ±2.3 % |
| `$confidence` | `float` | Confidence level, e.g. `99.0` = 99 % |
| `$sampledRows` | `int` | Number of rows actually read from storage |
| `$totalRows` | `int` | Estimated total rows in the scanned range |
| `$isApproximate` | `bool` | `false` when the query fell back to an exact execution |

### Helper methods

```php
$result->getValue();  // mixed — the raw value
$result->toFloat();   // float — cast for numeric comparisons
$result->toInt();     // int   — cast for display / counts

echo $result;
// ≈3141592 (±2.3% @99% confidence)
// or for exact fallbacks:
// =3141592 (±0% @100% confidence)
```

---

## Full examples

### Analytics dashboard counter

```php
$activeUsers = $userRepo
    ->approximate(within: 2.0, confidence: 95.0)
    ->withFallback()
    ->where('last_seen_at', '>=', (new \DateTimeImmutable('-30 days'))->format('Y-m-d'))
    ->count();

return [
    'active_users'   => $activeUsers->toInt(),
    'is_approximate' => $activeUsers->isApproximate,
    'error_margin'   => $activeUsers->errorMargin,   // e.g. 1.8
    'confidence'     => $activeUsers->confidence,    // e.g. 95.0
    'sampled_rows'   => $activeUsers->sampledRows,
    'total_rows'     => $activeUsers->totalRows,
];
```

### Revenue estimate with tight error tolerance

```php
$revenue = $orderRepo
    ->approximate(within: 0.5, confidence: 99.9)
    ->where('status', 'completed')
    ->whereRaw('created_at >= date_trunc(\'month\', NOW())')
    ->sum('total_amount');

if ($revenue->errorMargin > 1.0) {
    // AQP could not meet the tolerance — result may still be useful
    logger()->warning('Revenue estimate exceeds 1% error margin', [
        'actual_margin' => $revenue->errorMargin,
    ]);
}

printf(
    'Monthly revenue: $%.2f (±%.1f%% @ %.0f%% confidence)',
    $revenue->toFloat(),
    $revenue->errorMargin,
    $revenue->confidence,
);
```

### Comparing approximate vs exact

```php
// Fast approximate result for the dashboard tile
$approxCount = $orderRepo
    ->approximate(within: 5.0, confidence: 95.0)
    ->where('status', 'pending')
    ->count();

// Exact count only when the user drills down
$exactCount = $orderRepo->query()
    ->where('status', 'pending')
    ->count();

echo "Approx: {$approxCount} | Exact: {$exactCount}";
// Approx: ≈14823 (±4.1% @95% confidence) | Exact: 14601
```
