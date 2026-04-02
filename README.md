# Weaver ORM

A modern, high-performance ORM for PHP 8.4+. Zero reflection, no proxy classes, no memory leaks, no Doctrine dependency. Worker-safe by design.

Compatible with **Symfony**, **Laravel**, and **CodeIgniter 4**.

## Benchmarks

Weaver ORM vs Doctrine ORM vs Eloquent (SQLite in-memory, 2000 iterations, PHP 8.4):

| Operation | Weaver ORM | Doctrine ORM | Eloquent | Winner |
|---|---|---|---|---|
| Single INSERT | 0.069ms | 0.143ms | 0.352ms | **Weaver 2.1x** |
| Batch INSERT (100 rows) | 2.89ms | 7.57ms | 27.74ms | **Weaver 2.6x** |
| SELECT by PK | 0.068ms | 0.090ms | 0.245ms | **Weaver 1.3x** |
| Complex SELECT | 0.216ms | 0.595ms | 0.519ms | **Weaver 2.8x** |
| UPDATE | 0.061ms | 0.091ms | 0.266ms | **Weaver 1.5x** |
| Hydration (100 rows) | 0.834ms | 2.148ms | 2.448ms | **Weaver 2.6x** |

**Weaver wins 6/6 benchmarks.** Run `php benchmark/run-orm-comparison.php` to reproduce.

## Why Weaver?

| | Doctrine ORM | Eloquent | Weaver ORM |
|---|---|---|---|
| Dependencies | doctrine/dbal, doctrine/common, ... | illuminate/database, ... | **ext-pdo only** |
| PHP version | 8.1+ | 8.2+ | **8.4+** |
| Proxy classes | Generated, reflection-heavy | N/A (ActiveRecord) | **None** (PHP 8.4 property hooks) |
| Memory | Identity map grows unbounded | Model instances per query | **Worker-safe**, request-scoped |
| Query language | DQL | Eloquent Builder | **Plain SQL query builder** |
| PyroSQL | Not supported | Not supported | **Native** (time travel, vectors, CDC) |

## Requirements

- **PHP 8.4+**
- **ext-pdo** (+ driver: pdo_pgsql, pdo_mysql, or pdo_sqlite)

Zero framework dependencies. No Doctrine, no Symfony, no Laravel in the core.

## Installation

```bash
composer require unapartidamas/weaver-orm
```

### Symfony

```php
// config/bundles.php
return [
    Weaver\ORM\Bridge\Symfony\WeaverBundle::class => ['all' => true],
];
```

### Laravel

```php
// config/app.php (or auto-discovered)
'providers' => [
    Weaver\ORM\Bridge\Laravel\WeaverServiceProvider::class,
],
```

### CodeIgniter 4

```php
// Use the service locator
$workspace = \Weaver\ORM\Bridge\CodeIgniter\WeaverService::workspace();
```

## Quick Start

```php
use Weaver\ORM\Mapping\Attribute\{Entity, Id, Column, Timestamps};

#[Entity(table: 'users')]
#[Timestamps]
class User
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    #[Column]
    public string $email;
}
```

```php
$workspace->add($user);
$workspace->push();

$users = $repo->query()
    ->where('name', 'LIKE', '%ali%')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

$workspace->delete($user);
$workspace->push();
```

## Configuration

```yaml
# config/packages/weaver.yaml
weaver:
    connections:
        default:
            driver: pdo_pgsql  # pdo_mysql, pdo_sqlite, pyrosql
            url: '%env(DATABASE_URL)%'
    default_connection: default
    debug: '%kernel.debug%'
    n1_detector: '%kernel.debug%'
```

## API

| Doctrine | Weaver | Description |
|---|---|---|
| `persist()` | `add()` | Track a new entity |
| `flush()` | `push()` | Write all changes to DB |
| `remove()` | `delete()` | Mark for deletion |
| `detach()` | `untrack()` | Stop tracking entity |
| `contains()` | `isTracked()` | Check if managed |
| `refresh()` | `reload()` | Re-read from DB |
| `clear()` | `reset()` | Clear tracked entities |
| `EntityManager` | `EntityWorkspace` | Main entry point |

## Features

**Core** - Entity mapping with attributes, HasOne/HasMany/BelongsTo/BelongsToMany/MorphOne/MorphMany relations, identity map, change tracking, lazy loading (PHP 8.4 hooks), optimistic + pessimistic locking, nested embeddables, single-table + joined-table inheritance, batch operations, criteria pattern.

**Query Builder** - Fluent API, global/local scopes, runtime filter toggle, eager loading, cursor + offset pagination, query result caching.

**Caching** - Second Level Cache (PSR-16), query result cache, per-entity cache regions.

**Multi-Database** - Named connections, `#[Connection('analytics')]` per entity, WorkspaceRegistry, read/write splitting.

**Schema** - Generation from mappers, diff, validation. Commands: `schema:create`, `schema:update`, `schema:drop`, `schema:diff`.

**Events** - Lifecycle hooks (`#[BeforeAdd]`, `#[AfterAdd]`, etc.), priorities, Symfony/Laravel/CI4 dispatcher integration.

**Testing** - `EntityFactory`, `DatabaseTransactions` trait, `RefreshDatabase` trait, SQLite in-memory.

**Profiling** - Query profiler, N+1 detection, Symfony Web Debug Toolbar.

**PyroSQL** - Time travel (AS OF), branching, CDC, approximate queries, vector search, WASM UDFs, native syntax (FIND, ADD, CHANGE, REMOVE, SEARCH, NEAREST).

## Migrating from Doctrine

```php
use Weaver\ORM\Bridge\Doctrine\DoctrineCompatEntityManager;

$em = new DoctrineCompatEntityManager($workspace);
$em->persist($entity);  // calls $workspace->add()
$em->flush();            // calls $workspace->push()
```

## Documentation

[unapartidamas.github.io/weaverORM](https://unapartidamas.github.io/weaverORM/)

## License

[MIT](LICENSE)
