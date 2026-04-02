---
id: overview
title: PyroSQL 概述
sidebar_label: Overview
---

PyroSQL is a custom database engine written in Rust. It speaks the **PostgreSQL wire protocol version 3** natively, which means every PostgreSQL-compatible library, driver, and tool connects to it without modification — including the PDO `pgsql` driver that Doctrine DBAL uses under the hood.

On top of standard SQL, PyroSQL adds a set of HTAP (Hybrid Transactional/Analytical Processing) capabilities: time-travel queries, database branching, vector similarity search, approximate query processing, Change Data Capture streaming, and WebAssembly user-defined functions. All of these are exposed through Weaver ORM's PyroSQL integration layer.

---

## Configuring the connection

Add the PyroSQL driver to your Symfony configuration by setting `driver: pyrosql`. Because PyroSQL speaks the PostgreSQL wire protocol, all the standard PostgreSQL connection parameters apply.

```yaml
# config/packages/weaver_orm.yaml
weaver_orm:
    connections:
        default:
            driver:   pyrosql
            host:     127.0.0.1
            port:     5432
            dbname:   myapp
            user:     app
            password: secret
```

The `pyrosql` driver identifier tells Weaver ORM to wrap the underlying DBAL connection with `PyroSqlDriver`, which probes for PyroSQL-specific capabilities on the first use.

---

## Feature detection with `PyroSqlDriver`

`PyroSqlDriver` detects whether the active connection is backed by PyroSQL and exposes its available features. Detection is performed exactly once via a lightweight `current_setting('pyrosql.version', true)` query; the result is cached for the lifetime of the object. All `supports*()` methods are safe to call on a plain PostgreSQL connection — they return `false` without throwing.

```php
use Weaver\ORM\PyroSQL\PyroSqlDriver;

$driver = new PyroSqlDriver($connection);

if ($driver->isPyroSql()) {
    echo 'Connected to PyroSQL ' . $driver->getVersion(); // e.g. "1.4.2"
}

// Per-feature checks
$driver->supportsTimeTravel();   // bool
$driver->supportsBranching();    // bool
$driver->supportsVectors();      // bool
$driver->supportsApproximate();  // bool
$driver->supportsAutoIndexing(); // bool
$driver->supportsCdc();          // bool
$driver->supportsWasmUdfs();     // bool
```

To assert that a feature is available and throw a descriptive exception when it is not, use `assertSupports()`:

```php
$driver->assertSupports('branching');
// throws UnsupportedDriverFeatureException if not on PyroSQL
```

---

## Adding PyroSQL methods to a repository

The `PyroQueryBuilderExtension` trait adds two PyroSQL-specific builder factories to any repository. Mix it into a repository that extends `AbstractRepository`:

```php
use Weaver\ORM\Repository\AbstractRepository;
use Weaver\ORM\PyroSQL\PyroQueryBuilderExtension;

class OrderRepository extends AbstractRepository
{
    use PyroQueryBuilderExtension;

    protected string $entityClass = Order::class;

    protected function getConnection(): \Doctrine\DBAL\Connection
    {
        return $this->connection;
    }

    protected function getMapper(): \Weaver\ORM\Mapping\AbstractEntityMapper
    {
        return $this->registry->get($this->entityClass);
    }

    protected function getHydrator(): \Weaver\ORM\Hydration\EntityHydrator
    {
        return $this->hydrator;
    }

    protected function getRelationLoader(): \Weaver\ORM\Relation\RelationLoader
    {
        return $this->relationLoader;
    }
}
```

The trait adds:

- `queryAsOf(DateTimeImmutable $timestamp): TimeTravelQueryBuilder` — time-travel queries scoped to a specific point in time.
- `approximate(float $within = 5.0, float $confidence = 95.0): ApproximateQueryBuilder` — approximate aggregate queries with statistical guarantees.

---

## Available features

| Feature | Driver method | Documentation |
|---------|---------------|---------------|
| Time-travel queries (`AS OF`) | `supportsTimeTravel()` | [Time Travel](./time-travel.md) |
| Database branching | `supportsBranching()` | [Branches](./branches.md) |
| Change Data Capture | `supportsCdc()` | [CDC](./cdc.md) |
| Approximate query processing | `supportsApproximate()` | [Approximate Queries](./approximate.md) |
| Vector similarity search | `supportsVectors()` | [Vector Search](./vectors.md) |
| WASM user-defined functions | `supportsWasmUdfs()` | [WASM UDFs](./wasm-udfs.md) |
| Auto-indexing hints | `supportsAutoIndexing()` | — |

All features require a PyroSQL connection. On a plain PostgreSQL server every `supports*()` method returns `false` and any attempt to use PyroSQL-specific classes throws `UnsupportedDriverFeatureException`.
