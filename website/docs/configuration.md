---
id: configuration
title: Symfony Configuration
---

Weaver ORM is configured via `config/packages/weaver.yaml`. This page covers every available option.

## Minimal configuration

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
```

## Full configuration reference

```yaml
# config/packages/weaver.yaml
weaver_orm:

    # ------------------------------------------------------------------ #
    # Primary (write) connection
    # ------------------------------------------------------------------ #
    connection:
        driver: pdo_pgsql           # pdo_pgsql | pdo_mysql | pdo_sqlite | pyrosql
        url: '%env(DATABASE_URL)%'  # DSN takes priority over individual options below

        # Individual options (alternative to url:)
        # host:     '%env(DB_HOST)%'
        # port:     '%env(int:DB_PORT)%'
        # dbname:   '%env(DB_NAME)%'
        # user:     '%env(DB_USER)%'
        # password: '%env(DB_PASSWORD)%'

        # Connection pool options (FrankenPHP / RoadRunner)
        # persistent: true
        # charset:    utf8mb4      # MySQL only

    # ------------------------------------------------------------------ #
    # Read replica (optional)
    # ------------------------------------------------------------------ #
    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'

    # ------------------------------------------------------------------ #
    # Mapper discovery
    # ------------------------------------------------------------------ #
    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
        # Add more paths for multiple bounded contexts:
        # - '%kernel.project_dir%/src/Billing/Mapper'
        # - '%kernel.project_dir%/src/Catalog/Mapper'

    # ------------------------------------------------------------------ #
    # Migrations
    # ------------------------------------------------------------------ #
    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'

    # ------------------------------------------------------------------ #
    # Debug & safety
    # ------------------------------------------------------------------ #
    debug: '%kernel.debug%'         # Logs all queries to the Symfony profiler

    # Detect and warn about N+1 query patterns in development
    n1_detector: true               # Only active when debug: true

    # Throw an exception if a SELECT would return more than N rows.
    # Protects against accidental full-table scans in production.
    # Set to 0 to disable.
    max_rows_safety_limit: 5000
```

## Connection drivers

### pdo_pgsql — PostgreSQL

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'
```

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/myapp?serverVersion=16&charset=utf8"
```

### pdo_mysql — MySQL / MariaDB

```yaml
weaver_orm:
    connection:
        driver: pdo_mysql
        url: '%env(DATABASE_URL)%'
```

```dotenv
DATABASE_URL="mysql://app:secret@db:3306/myapp?serverVersion=8.0&charset=utf8mb4"
```

### pdo_sqlite — SQLite (testing / embedded)

```yaml
weaver_orm:
    connection:
        driver: pdo_sqlite
        url: '%env(DATABASE_URL)%'
```

```dotenv
# In-memory (useful for integration tests):
DATABASE_URL="sqlite:///:memory:"

# File-based:
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db"
```

### pyrosql — PyroSQL analytical engine

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    # PyroSQL as a dedicated read connection for analytical queries
    read_connection:
        driver: pyrosql
        url: '%env(PYROSQL_URL)%'
```

## Environment variables

A typical `.env` / `.env.local` setup:

```dotenv
# .env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# .env.local (never committed to VCS)
DATABASE_URL="postgresql://app:mylocalpwd@db:5432/app_dev?serverVersion=16&charset=utf8"

# Read replica (optional)
DATABASE_READ_URL="postgresql://app_ro:readpwd@db-replica:5432/app?serverVersion=16&charset=utf8"
```

## Read replica configuration

When `read_connection` is defined, Weaver automatically routes `SELECT` queries to the read connection and `INSERT` / `UPDATE` / `DELETE` queries to the primary connection.

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'
```

To force a query onto the primary connection (e.g. immediately after a write), use `->onPrimary()`:

```php
// Always reads from primary, bypassing the replica
$user = $this->users->query()
    ->onPrimary()
    ->where('id', '=', $id)
    ->first();
```

## Debug mode and the N+1 detector

When `debug: true` (the Symfony default in `dev` environment), Weaver:

- Logs every SQL query with its bindings to the Symfony Web Profiler.
- Activates the **N+1 detector** when `n1_detector: true`. The detector inspects eager-loading patterns and emits a warning in the profiler if it detects that a relation was accessed on multiple entities without being pre-loaded.

```yaml
# config/packages/dev/weaver.yaml
weaver_orm:
    debug: true
    n1_detector: true
    max_rows_safety_limit: 1000  # stricter in dev
```

```yaml
# config/packages/prod/weaver.yaml
weaver_orm:
    debug: false
    n1_detector: false
    max_rows_safety_limit: 5000
```

## Multiple bounded contexts

If your application uses multiple databases or you want strict separation between bounded contexts, you can register multiple connection sets by using Symfony's service tagging directly. See the [advanced configuration guide](#) for details.
