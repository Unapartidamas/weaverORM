---
id: entity-mapping
title: Entity Mapping
---

Weaver ORM separates domain objects from persistence metadata by putting all mapping information in a dedicated **mapper class**. This page covers every aspect of mapper configuration.

## Why mappers instead of attributes?

Doctrine ORM puts mapping metadata directly on the entity class via PHP 8 attributes:

```php
// Doctrine approach — entity knows about the database
#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;
}
```

Weaver keeps them strictly separated:

```
Entity class        →  plain PHP object, zero ORM dependencies
Mapper class        →  all persistence knowledge lives here
```

Benefits:
- **Zero runtime reflection.** The mapper is plain PHP returning arrays and scalars.
- **No proxy classes.** No on-disk code generation needed.
- **Worker-safe.** Mappers hold no per-request state.
- **Testable in isolation.** Instantiate and inspect a mapper in a unit test without booting Symfony.
- **Fully greppable.** Every column name, every type, every option appears in plain text and shows up in `git diff`.

## Mapper vs entity: responsibilities

| Concern | Lives in |
|---|---|
| Business logic, invariants | Entity class |
| Properties and PHP types | Entity class |
| Table name and schema | Mapper |
| Column names, types, options | Mapper |
| Indexes and constraints | Mapper |
| Hydration (row → entity) | Mapper |
| Extraction (entity → row) | Mapper |
| Relations | Mapper |

## Basic entity definition

An entity is any PHP class. It does not extend anything, implement anything, or import anything from `Weaver\ORM`.

```php
<?php
// src/Entity/User.php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;

final class User
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $isActive,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public function withEmail(string $email): self
    {
        return new self(
            id:        $this->id,
            name:      $this->name,
            email:     $email,
            isActive:  $this->isActive,
            createdAt: $this->createdAt,
        );
    }
}
```

The entity can be:
- **Immutable** (recommended) — mutating methods return new instances
- **Mutable** — public properties or setters are fine
- **Abstract** — for inheritance hierarchies

## AbstractMapper

Every entity needs exactly one mapper. Create a class extending `Weaver\ORM\Mapping\AbstractMapper` and implement the required methods.

```php
<?php
// src/Mapper/UserMapper.php

declare(strict_types=1);

namespace App\Mapper;

use App\Entity\User;
use DateTimeImmutable;
use Weaver\ORM\Mapping\AbstractMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\SchemaDefinition;

final class UserMapper extends AbstractMapper
{
    public function table(): string
    {
        return 'users';
    }

    public function primaryKey(): string|array
    {
        return 'id';
    }

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ColumnDefinition::integer('id')->autoIncrement()->unsigned(),
            ColumnDefinition::string('name', 120)->notNull(),
            ColumnDefinition::string('email', 254)->unique()->notNull(),
            ColumnDefinition::boolean('is_active')->notNull()->default(true),
            ColumnDefinition::datetime('created_at')->notNull(),
        );
    }

    public function hydrate(array $row): User
    {
        return new User(
            id:        (int) $row['id'],
            name:      $row['name'],
            email:     $row['email'],
            isActive:  (bool) $row['is_active'],
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }

    public function dehydrate(object $entity): array
    {
        /** @var User $entity */
        $data = [
            'name'       => $entity->name,
            'email'      => $entity->email,
            'is_active'  => $entity->isActive,
            'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
        ];

        if ($entity->id !== null) {
            $data['id'] = $entity->id;
        }

        return $data;
    }
}
```

### Required mapper methods

| Method | Purpose |
|---|---|
| `table(): string` | Table name in the database |
| `primaryKey(): string\|array` | Column name(s) for the primary key |
| `schema(): SchemaDefinition` | All column definitions for DDL and migrations |
| `hydrate(array $row): object` | Build an entity from a raw database row |
| `dehydrate(object $entity): array` | Serialize an entity to a column => value array |

### Optional mapper methods

| Method | Purpose |
|---|---|
| `readOnly(): bool` | Return `true` for view-backed entities (no INSERT/UPDATE/DELETE) |
| `discriminatorColumn(): ?string` | Used for Single Table Inheritance |
| `discriminatorMap(): array` | Used for Single Table Inheritance |
| `parentMapper(): ?string` | Used for Class Table Inheritance |

## Column types

All column definitions use static factory methods on `ColumnDefinition`. Each method returns a `ColumnDefinition` instance with a fluent configuration API.

### string

Maps to `VARCHAR(n)`. Default length is 255.

```php
ColumnDefinition::string('username')                    // VARCHAR(255) NOT NULL
ColumnDefinition::string('slug', 100)                   // VARCHAR(100) NOT NULL
ColumnDefinition::string('nickname')->nullable()        // VARCHAR(255) NULL
```

### integer, bigint, smallint

```php
ColumnDefinition::integer('sort_order')                 // INT NOT NULL
ColumnDefinition::integer('quantity')->default(0)       // INT NOT NULL DEFAULT 0
ColumnDefinition::integer('stock')->unsigned()          // INT UNSIGNED NOT NULL
ColumnDefinition::bigint('view_count')->default(0)      // BIGINT NOT NULL DEFAULT 0
ColumnDefinition::smallint('priority')->unsigned()      // SMALLINT UNSIGNED NOT NULL
```

### float and decimal

Use `decimal` for financial values; `float` for coordinates and measurements.

```php
ColumnDefinition::float('latitude')
ColumnDefinition::float('longitude')
ColumnDefinition::decimal('price', 10, 2)              // DECIMAL(10,2) NOT NULL
ColumnDefinition::decimal('tax_rate', 5, 4)->default('0.0000')
```

Hydrate `decimal` as a string to preserve precision:

```php
price: $row['price'],  // keep as string, pass to a Money value object
```

### boolean

Maps to `TINYINT(1)` on MySQL, `BOOLEAN` on PostgreSQL/SQLite.

```php
ColumnDefinition::boolean('is_active')->default(true)
ColumnDefinition::boolean('email_verified')->default(false)
```

Always cast explicitly in `hydrate`:

```php
isActive: (bool) $row['is_active'],
```

### datetime, date, time

```php
ColumnDefinition::datetime('published_at')->nullable()   // DATETIME NULL
ColumnDefinition::date('birth_date')->nullable()         // DATE NULL
ColumnDefinition::time('opens_at')                       // TIME NOT NULL
```

`datetime` returns a mutable `\DateTime`. Prefer `datetimeImmutable` for new code:

```php
ColumnDefinition::datetimeImmutable('created_at')        // DATETIME NOT NULL
ColumnDefinition::datetimeImmutable('updated_at')->nullable()
```

Hydration:

```php
createdAt: new \DateTimeImmutable($row['created_at']),
updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
```

Extraction:

```php
'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
'updated_at' => $entity->updatedAt?->format('Y-m-d H:i:s'),
```

### json

Maps to `JSON` (MySQL 5.7.8+, PostgreSQL, SQLite). You control encoding/decoding in `hydrate` / `dehydrate`.

```php
ColumnDefinition::json('metadata')->nullable()
ColumnDefinition::json('settings')
```

Hydration:

```php
metadata: $row['metadata'] !== null
    ? json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR)
    : null,
```

Extraction:

```php
'metadata' => $entity->metadata !== null
    ? json_encode($entity->metadata, JSON_THROW_ON_ERROR)
    : null,
```

### text, blob

```php
ColumnDefinition::text('body')                           // TEXT NOT NULL
ColumnDefinition::text('description')->nullable()        // TEXT NULL
ColumnDefinition::blob('thumbnail')                      // BLOB NOT NULL
```

### guid (UUID as CHAR(36))

```php
ColumnDefinition::guid('external_ref')->nullable()       // CHAR(36) NULL
```

## Primary key types

### Auto-increment integer

```php
ColumnDefinition::integer('id')->autoIncrement()->unsigned()
```

```sql
id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id)
```

Weaver omits `id` from `INSERT` when the value is `null` and reads back the generated value automatically.

### UUID v4 (random)

```php
ColumnDefinition::guid('id')->primaryKey()
```

Generate the UUID in the entity factory method before persisting:

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v4(), name: $name);
}
```

### UUID v7 (time-ordered, recommended)

UUID v7 includes a millisecond timestamp prefix, making keys monotonically increasing and dramatically reducing B-tree page splits compared to random UUIDs.

```php
ColumnDefinition::guid('id')->primaryKey()
```

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v7(), name: $name);
}
```

### Natural string key

When the business key is naturally unique (country code, currency code, slug):

```php
ColumnDefinition::string('code', 3)->primaryKey()
```

### Composite primary key

```php
ColumnDefinition::integer('user_id')->primaryKey(),
ColumnDefinition::integer('role_id')->primaryKey(),
ColumnDefinition::datetimeImmutable('assigned_at'),
```

```sql
PRIMARY KEY (user_id, role_id)
```

## Column options

All options are available as fluent methods on `ColumnDefinition`:

| Method | Effect |
|---|---|
| `->nullable()` | Column accepts NULL values |
| `->default($value)` | Sets a DEFAULT clause in DDL |
| `->unsigned()` | Applies UNSIGNED (integer types only) |
| `->unique()` | Adds a UNIQUE constraint |
| `->primaryKey()` | Marks column as part of the primary key |
| `->autoIncrement()` | Adds AUTO_INCREMENT (integer PKs only) |
| `->generated()` | Column is DB-computed; excluded from INSERT/UPDATE |
| `->comment(string)` | Adds a column-level DDL comment |

## PHP 8.1 enum mapping

PHP backed enums (`string` or `int` backing type) map naturally to database columns.

### String-backed enum

```php
enum OrderStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case Shipped   = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
```

Mapper:

```php
ColumnDefinition::string('status', 20)
    ->comment('pending|confirmed|shipped|delivered|cancelled')
```

Hydration:

```php
status: OrderStatus::from($row['status']),
```

Extraction:

```php
'status' => $entity->status->value,
```

### Int-backed enum

```php
enum Priority: int
{
    case Low    = 1;
    case Normal = 2;
    case High   = 3;
    case Urgent = 4;
}
```

Mapper:

```php
ColumnDefinition::smallint('priority')->unsigned()
```

Hydration:

```php
priority: Priority::from((int) $row['priority']),
```

### Nullable enum

```php
ColumnDefinition::string('resolution', 20)->nullable()
```

Hydration:

```php
resolution: $row['resolution'] !== null
    ? Resolution::from($row['resolution'])
    : null,
```

:::tip
Always store `->value` (e.g. `'pending'`), never `->name` (e.g. `'Pending'`). Labels can be renamed freely in PHP; values cannot without a migration.
:::

## Generated / computed columns

Columns populated by the database engine (e.g. `GENERATED ALWAYS AS`) must be excluded from `INSERT` and `UPDATE` statements.

```php
ColumnDefinition::string('full_name', 162)->generated(),
ColumnDefinition::decimal('total', 10, 2)->generated(),
```

Weaver strips `generated` columns from write payloads automatically. They still appear in `hydrate`.

## Column aliases

Use an alias when the PHP property name differs from the database column name:

```php
// PHP property 'email' maps to DB column 'usr_email'
ColumnDefinition::string('email')->alias('usr_email')
```

In `hydrate`, use the column name (the alias) as the array key:

```php
email: $row['usr_email'],
```

In `dehydrate`, return the column name as the key:

```php
'usr_email' => $entity->email,
```

## Registering mappers in Symfony

If `autoconfigure: true` is set in `config/services.yaml` (the Symfony default), any class extending `AbstractMapper` in the configured `mapper_paths` is automatically tagged and registered — no manual service definition needed.

For explicit registration or to override defaults:

```yaml
# config/services.yaml
services:
    App\Mapper\UserMapper:
        tags:
            - { name: weaver.mapper }
```
