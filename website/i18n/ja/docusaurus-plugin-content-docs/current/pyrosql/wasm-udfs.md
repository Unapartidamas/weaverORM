---
id: wasm-udfs
title: WASM UDFs
sidebar_label: WASM UDFs
---

PyroSQL supports **WebAssembly User-Defined Functions (WASM UDFs)** — custom SQL functions compiled to the WebAssembly binary format and executed inside the database engine. WASM UDFs run in a sandboxed, deterministic environment with near-native performance and no external network access. Once registered, they are callable from any SQL statement.

**Common use cases:**

- **ML inference** — sentiment analysis, classification, or scoring functions compiled from Python or Rust.
- **Text processing** — tokenisation, stemming, or custom normalisation logic.
- **Domain-specific calculations** — financial formulae, geo computations, or physics models that are expensive to round-trip to the application layer.
- **Data validation** — complex constraints that cannot be expressed in SQL.

---

## `WasmUdfManager`

`WasmUdfManager` provides a PHP interface for registering, inspecting, and dropping WASM UDFs in PyroSQL.

```php
use Weaver\ORM\PyroSQL\WasmUdf\WasmUdfManager;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

$driver  = new PyroSqlDriver($connection);
$manager = new WasmUdfManager($connection, $driver);
```

---

## Registering functions

### `registerFromFile()`

Register a WASM UDF from a `.wasm` binary file. The file is read from disk, base64-encoded, and sent to PyroSQL in a `CREATE FUNCTION` statement.

```php
$manager->registerFromFile(
    name:       'sentiment_score',
    wasmPath:   '/var/app/wasm/sentiment.wasm',
    returnType: 'FLOAT',
    args:       ['TEXT'],
);
```

Executes:
```sql
CREATE FUNCTION "sentiment_score"(TEXT) RETURNS FLOAT
LANGUAGE wasm AS '<base64-encoded wasm binary>'
```

Pass `$replace = true` to overwrite an existing function without raising an error:

```php
$manager->registerFromFile(
    name:       'sentiment_score',
    wasmPath:   '/var/app/wasm/sentiment_v2.wasm',
    returnType: 'FLOAT',
    args:       ['TEXT'],
    replace:    true,
);
```

Executes:
```sql
CREATE OR REPLACE FUNCTION "sentiment_score"(TEXT) RETURNS FLOAT
LANGUAGE wasm AS '...'
```

### `registerFromBase64()`

Register a WASM UDF from a base64-encoded binary string. Useful when the `.wasm` binary is stored in a secrets manager, a database, or arrives over a network.

```php
$base64 = base64_encode(file_get_contents('/var/app/wasm/classify.wasm'));

$manager->registerFromBase64(
    name:       'classify_intent',
    base64:     $base64,
    returnType: 'TEXT',
    args:       ['TEXT'],
    replace:    false,
);
```

---

## Supported SQL types

Both `$returnType` and `$args` accept standard SQL type strings. The type validator accepts `TEXT`, `INT`, `FLOAT`, `DOUBLE PRECISION`, `BOOLEAN`, `CHAR(n)`, `VARCHAR(n)`, and similar ANSI-style type expressions.

```php
// Multi-argument function
$manager->registerFromFile(
    name:       'levenshtein_similarity',
    wasmPath:   '/var/app/wasm/levenshtein.wasm',
    returnType: 'FLOAT',
    args:       ['TEXT', 'TEXT'],
);
```

---

## Introspection

### `list(): array`

Returns all WASM UDFs registered in the current database. Each entry contains `name`, `return_type`, `arg_types`, and `created_at`.

```php
foreach ($manager->list() as $fn) {
    printf(
        "%-30s  (%s) → %s  registered: %s\n",
        $fn['name'],
        $fn['arg_types'],
        $fn['return_type'],
        $fn['created_at'],
    );
}
```

### `exists(string $name): bool`

Returns `true` when a WASM UDF with the given name is registered.

```php
if (!$manager->exists('sentiment_score')) {
    $manager->registerFromFile(
        name:       'sentiment_score',
        wasmPath:   '/var/app/wasm/sentiment.wasm',
        returnType: 'FLOAT',
        args:       ['TEXT'],
    );
}
```

---

## Dropping functions

### `drop(string $name): void`

Drop a registered WASM UDF by name. Throws if the function does not exist.

```php
$manager->drop('sentiment_score');
```

Executes:
```sql
DROP FUNCTION "sentiment_score"
```

### `dropIfExists(string $name): void`

Drop a WASM UDF silently if it exists, or do nothing if it does not.

```php
$manager->dropIfExists('sentiment_score');
```

Executes:
```sql
DROP FUNCTION IF EXISTS "sentiment_score"
```

---

## Calling WASM UDFs from SQL

Once registered, a WASM UDF is available in any SQL statement like a built-in function:

```sql
SELECT id, review_text, sentiment_score(review_text) AS score
FROM reviews
WHERE sentiment_score(review_text) < 0.3
ORDER BY score ASC
LIMIT 50;
```

From PHP using DBAL:

```php
$negativeReviews = $connection->fetchAllAssociative(
    "SELECT id, review_text, sentiment_score(review_text) AS score
     FROM reviews
     WHERE sentiment_score(review_text) < 0.3
     ORDER BY score ASC
     LIMIT ?",
    [50],
);
```

---

## Full example: registering and using a sentiment analysis function

This example assumes a Rust-based sentiment model has been compiled to WebAssembly and exposes a single `score(text: &str) -> f32` export.

```php
use Weaver\ORM\PyroSQL\WasmUdf\WasmUdfManager;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

class SentimentUdfInstaller
{
    public function __construct(
        private readonly WasmUdfManager $manager,
    ) {}

    public function install(string $wasmPath): void
    {
        $this->manager->registerFromFile(
            name:       'sentiment_score',
            wasmPath:   $wasmPath,
            returnType: 'FLOAT',
            args:       ['TEXT'],
            replace:    true,  // safe to re-run on deployments
        );

        echo "sentiment_score registered successfully.\n";
    }

    public function uninstall(): void
    {
        $this->manager->dropIfExists('sentiment_score');
    }
}

// Registration (e.g. in a migration or setup command):
$installer = new SentimentUdfInstaller(
    new WasmUdfManager($connection, new PyroSqlDriver($connection))
);
$installer->install('/var/app/wasm/sentiment.wasm');

// Usage in application code:
$lowRatedReviews = $connection->fetchAllAssociative(
    "SELECT
         r.id,
         r.product_id,
         r.review_text,
         sentiment_score(r.review_text) AS sentiment
     FROM reviews r
     JOIN products p ON p.id = r.product_id
     WHERE p.category = ?
       AND sentiment_score(r.review_text) < 0.25
     ORDER BY sentiment ASC
     LIMIT 100",
    ['electronics'],
);

foreach ($lowRatedReviews as $review) {
    printf(
        "[%.3f] product=%d — %s\n",
        $review['sentiment'],
        $review['product_id'],
        mb_strimwidth($review['review_text'], 0, 80, '…'),
    );
}
```

### Using a multi-argument WASM UDF

Register a Levenshtein similarity function that accepts two strings:

```php
$manager->registerFromFile(
    name:       'levenshtein_similarity',
    wasmPath:   '/var/app/wasm/levenshtein.wasm',
    returnType: 'FLOAT',
    args:       ['TEXT', 'TEXT'],
);
```

```sql
SELECT id, name, levenshtein_similarity(name, 'iphone 15') AS similarity
FROM products
WHERE levenshtein_similarity(name, 'iphone 15') > 0.7
ORDER BY similarity DESC
LIMIT 10;
```
