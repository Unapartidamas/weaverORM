---
id: installation
title: Installation
---

## Requirements

Before installing Weaver ORM, verify your environment meets the minimum requirements:

| Requirement | Version |
|---|---|
| PHP | **8.4** or higher |
| Symfony | **7.0** or higher |
| doctrine/dbal | 4.0 (pulled in automatically) |
| Database | MySQL 8.0+ / PostgreSQL 14+ / SQLite 3.35+ |

## Step 1 — Install via Composer

```bash
docker compose exec app composer require weaver/orm
```

This pulls in:

- `weaver/orm` — the core mapper, query builder, and unit-of-work
- `weaver/orm-bundle` — the Symfony bundle (auto-registered by Symfony Flex)
- `doctrine/dbal ^4.0` — used as the connection and schema abstraction layer (not Doctrine ORM)

:::info Docker
All commands in this documentation assume you are running inside a Docker container. Adjust the service name (`app`) to match your `docker-compose.yml`.
:::

## Step 2 — Register the bundle

If you use Symfony Flex the bundle is registered automatically. If not, add it manually to `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... other bundles ...
    Weaver\ORM\Bundle\WeaverOrmBundle::class => ['all' => true],
];
```

## Step 3 — Create the configuration file

Create `config/packages/weaver.yaml` with a minimal connection configuration:

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'

    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'
```

Add the database URL to your `.env` file (or `.env.local` for local overrides):

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=16&charset=utf8"
```

## Step 4 — Verify the installation

```bash
docker compose exec app bin/console weaver:info
```

Expected output:

```
Weaver ORM — version 1.0.0
Connection:   pdo_pgsql (connected)
Mapper paths: src/Mapper (0 mappers found)
Migrations:   migrations/weaver (0 migrations)
```

## Supported database drivers

| Driver | Database |
|---|---|
| `pdo_pgsql` | PostgreSQL 14+ |
| `pdo_mysql` | MySQL 8.0+ / MariaDB 10.6+ |
| `pdo_sqlite` | SQLite 3.35+ |
| `pyrosql` | PyroSQL (analytical, read-optimised) |

## Optional packages

### PyroSQL (analytical read replica)

```bash
docker compose exec app composer require weaver/pyrosql-adapter
```

Enables a high-performance in-process analytical engine as a secondary connection for read-heavy queries and reporting.

### MongoDB document mapper

```bash
docker compose exec app composer require mongodb/mongodb
```

Requires the `ext-mongodb` PHP extension. Enables `AbstractDocumentMapper` for document-oriented storage alongside the relational mapper.

### Symfony Messenger integration

```bash
docker compose exec app composer require symfony/messenger
```

Enables the outbox pattern and async domain event publishing from within entity lifecycle hooks.

### Query result caching

```bash
docker compose exec app composer require symfony/cache
```

Enables `->cache(ttl: 60)` on query builder chains to store hydrated results in a PSR-6 cache pool.
