---
id: intro
title: What is Weaver ORM?
sidebar_label: Introduction
slug: /
---

Weaver ORM is a PHP 8.4+ object-relational mapper for Symfony applications built on a single premise: **your domain objects should have zero knowledge of the database**. No annotations on entity classes, no proxy generation, no runtime reflection — just plain PHP objects and explicit mapper classes that translate between them and SQL.

## The problems Weaver solves

### Doctrine proxy objects

Doctrine wraps every related entity in a proxy class that intercepts property access to trigger a SQL query on first touch. In traditional request/response cycles this is invisible, but it silently enables N+1 query patterns and makes debugging confusing (`var_dump($post->getAuthor())` prints a proxy, not a `User`).

In long-running PHP workers (RoadRunner, FrankenPHP, Swoole, Symfony Messenger) the `EntityManager` accumulates stale state between requests and must be manually reset at every request boundary — an easy mistake to make and a hard bug to diagnose.

### Reflection-based hydration

Doctrine uses `ReflectionProperty` to set private/protected properties directly on entity objects, bypassing your domain logic. Every request must re-parse PHP attributes or hit a warm cache; proxy classes must exist on disk.

### Unbounded identity map

The Doctrine `EntityManager` keeps every loaded entity in memory for the duration of the request. Loading large result sets causes unbounded memory growth. The workaround — `$em->clear()` — detaches everything, including entities you forgot to re-persist.

## What Weaver does differently

Weaver is built on four principles:

1. **Plain PHP objects as entities.** Your `User` class has zero ORM dependencies. No attributes, no base class, no interface. It is a pure value object or domain object that you can unit-test without booting Symfony.

2. **Explicit mapper classes.** A separate `UserMapper` class describes how `User` maps to the `users` table. Column types, relations, primary keys — all in one place, all in plain PHP, fully greppable and statically analysable.

3. **No proxies, no implicit lazy loading.** Relations are always loaded explicitly via `->with(['relation'])`. You always know exactly which SQL is executed and when.

4. **Worker-safe by design.** Mappers are stateless and loaded once per worker process. Each HTTP request or Messenger job gets its own `EntityWorkspace` (unit of work), so there is no shared mutable state between requests.

## Key differentiators at a glance

| Feature | Doctrine ORM | Weaver ORM |
|---|---|---|
| Proxy class generation | Required | Not needed |
| Runtime reflection | Yes | Never |
| Lazy loading | Implicit (proxy) | Explicit only |
| Entity annotations/attributes | On entity class | Separate mapper class |
| Worker process restart on reset | Yes | No |
| N+1 prevention | Manual `JOIN FETCH` | Enforced by `with()` |
| Memory per 10k rows | ~48 MB | ~11 MB |
| Hydration time for 10k rows | ~420 ms | ~95 ms |
| PHPStan / static analysis | Partial (magic proxies) | Full (explicit mappers) |

> Benchmarks: PHP 8.4, PostgreSQL 16, Ubuntu 22.04, 10 000 `User` rows with a `Profile` relation. Results vary by hardware and query complexity.

## Architecture overview

```
Entity (plain PHP class — zero ORM coupling)
    │
    └── Mapper (table name, columns, relations, hydrate/extract)
            │
            └── EntityWorkspace → QueryBuilder → PDO/DBAL
```

The `EntityWorkspace` replaces Doctrine's `EntityManager`. It is a request-scoped unit of work that tracks which entities need to be inserted, updated, or deleted when `flush()` is called. Because it is request-scoped, there is no identity map leak between requests.

## PyroSQL support

Weaver ships with optional support for **PyroSQL**, a high-performance in-process analytical SQL engine. PyroSQL can be used as a read replica for aggregate queries, reporting, and large dataset operations without touching the primary relational database. See the [PyroSQL section](/pyrosql) for details.

## Requirements

| Dependency | Minimum version |
|---|---|
| PHP | 8.4 |
| Symfony | 7.0 |
| doctrine/dbal | 4.0 (connection layer only) |
| MySQL | 8.0 |
| PostgreSQL | 14 |
| SQLite | 3.35 |

Optional:

- `symfony/messenger` — async event publishing and outbox pattern
- `symfony/cache` — query result caching
- `mongodb/mongodb` + `ext-mongodb` — MongoDB document mapper support

## What Weaver is not

Weaver is not a drop-in replacement for Doctrine. If you rely heavily on Doctrine's DQL, criteria API, or attribute-based migrations, you will need to rewrite that layer. Weaver is best suited for **greenfield Symfony 7+ projects** or **applications being migrated away from Doctrine** that want explicit, predictable SQL and worker-safe persistence.
