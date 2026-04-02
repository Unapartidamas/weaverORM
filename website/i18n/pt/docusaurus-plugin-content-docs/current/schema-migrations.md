---
id: schema-migrations
title: Schema e Migrações
---

Weaver ORM generates SQL DDL directly from your mapper classes. The `SchemaGenerator` reads every registered mapper, converts it into a Doctrine DBAL `Schema` object, and can create, update, or drop your database schema through a set of Symfony console commands.

---

## SchemaGenerator

`Weaver\ORM\Schema\SchemaGenerator` introspects mappers registered in the `MapperRegistry` and builds a complete database schema. Each mapper describes its table, columns, indexes, and foreign keys using PHP attributes.

### Defining a mapper

```php
<?php

namespace App\Mapping;

use Weaver\ORM\Mapping\AbstractMapper;
use Weaver\ORM\Mapping\Attributes\Column;
use Weaver\ORM\Mapping\Attributes\Table;
use Weaver\ORM\Mapping\Attributes\Index;
use Weaver\ORM\Mapping\Attributes\UniqueIndex;
use Weaver\ORM\Mapping\Attributes\ForeignKey;
use App\Entity\Order;

#[Table(name: 'orders')]
#[Index(columns: ['customer_id'], name: 'idx_orders_customer_id')]
#[Index(columns: ['status', 'created_at'], name: 'idx_orders_status_created_at')]
class OrderMapper extends AbstractMapper
{
    public string $entity = Order::class;

    #[Column(type: 'bigint', autoIncrement: true, primary: true)]
    public int $id;

    #[Column(type: 'bigint', nullable: false)]
    #[ForeignKey(references: 'customers', column: 'id', onDelete: 'CASCADE')]
    public int $customerId;

    #[Column(type: 'string', length: 20, nullable: false, default: 'pending')]
    public string $status;

    #[Column(type: 'decimal', precision: 10, scale: 2, nullable: false)]
    public string $total;

    #[Column(type: 'datetime_immutable', nullable: false)]
    public \DateTimeImmutable $createdAt;

    #[Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $deletedAt;
}
```

### Generating the schema programmatically

```php
<?php

use Weaver\ORM\Schema\SchemaGenerator;
use Doctrine\DBAL\Connection;

$schema   = $schemaGenerator->generate();        // DBAL Schema object
$platform = $connection->getDatabasePlatform();

$sqls = $schema->toSql($platform);              // array of CREATE TABLE statements
foreach ($sqls as $sql) {
    $connection->executeStatement($sql);
}
```

---

## Console commands

The Weaver Symfony bundle registers the following console commands:

### `weaver:schema:create`

Creates all tables that do not yet exist in the database. Safe to run on a fresh database or after adding new mappers.

```bash
php bin/console weaver:schema:create
```

Output:
```
Creating schema...
  + CREATE TABLE users (...)
  + CREATE TABLE orders (...)
  + CREATE TABLE order_items (...)
Schema created successfully.
```

### `weaver:schema:update`

Compares the current schema (derived from mappers) with the live database schema and applies the minimum set of ALTER statements needed to synchronise them.

```bash
php bin/console weaver:schema:update
```

```
Updating schema...
  ~ ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL
  ~ CREATE INDEX idx_users_phone ON users (phone)
Schema updated successfully.
```

Use `--dry-run` to preview changes without executing them:

```bash
php bin/console weaver:schema:update --dry-run
```

### `weaver:schema:drop`

Drops all tables managed by Weaver. Use with caution in production.

```bash
php bin/console weaver:schema:drop --force
```

### `weaver:schema:diff`

Shows the difference between the schema derived from your mappers and the current state of the database, without making any changes.

```bash
php bin/console weaver:schema:diff
```

```
Schema differences detected:

  [+] Column users.phone (VARCHAR 30, nullable)
  [+] Index idx_users_phone on users (phone)
  [-] Column orders.legacy_ref (removed from mapper)
```

The `+` prefix means the mapper defines something not yet in the database; `-` means the database has something the mapper no longer defines.

### `weaver:schema:sql`

Dumps the full DDL SQL for the current mapper schema to stdout. Useful for code review or feeding into external migration tools.

```bash
php bin/console weaver:schema:sql
```

```sql
CREATE TABLE users (
    id BIGINT AUTO_INCREMENT NOT NULL,
    email VARCHAR(180) NOT NULL,
    ...
    PRIMARY KEY (id)
);
...
```

---

## SchemaDiffer and SchemaDiff

`Weaver\ORM\Schema\SchemaDiffer` compares two DBAL `Schema` objects and returns a `SchemaDiff` value object describing every difference.

```php
<?php

use Weaver\ORM\Schema\SchemaDiffer;

$fromSchema = $this->schemaInspector->fromDatabase($connection); // current DB state
$toSchema   = $this->schemaGenerator->generate();                // mapper-defined state

$differ = new SchemaDiffer();
$diff   = $differ->diff($fromSchema, $toSchema);

// Inspect the diff:
foreach ($diff->addedTables() as $table) {
    echo "New table: {$table->getName()}\n";
}

foreach ($diff->modifiedTables() as $tableDiff) {
    foreach ($tableDiff->addedColumns() as $col) {
        echo "New column: {$tableDiff->getTableName()}.{$col->getName()}\n";
    }
    foreach ($tableDiff->removedColumns() as $col) {
        echo "Removed column: {$tableDiff->getTableName()}.{$col->getName()}\n";
    }
}

foreach ($diff->removedTables() as $table) {
    echo "Dropped table: {$table->getName()}\n";
}

// Convert to SQL:
$sqls = $diff->toSql($connection->getDatabasePlatform());
```

### `SchemaDiff` API

| Method | Returns | Description |
|---|---|---|
| `addedTables()` | `Table[]` | Tables present in mapper schema but not in DB |
| `removedTables()` | `Table[]` | Tables present in DB but not in mapper schema |
| `modifiedTables()` | `TableDiff[]` | Tables with column or index changes |
| `toSql($platform)` | `string[]` | All ALTER/CREATE/DROP statements to migrate |
| `isEmpty()` | `bool` | True when mapper and DB are in sync |

---

## Integration with Doctrine Migrations

Weaver ORM can act as the schema source for Doctrine Migrations. Configure the `WeaverSchemaProvider` as the Doctrine Migrations schema provider:

```yaml
# config/packages/doctrine_migrations.yaml
doctrine_migrations:
    schema:
        filter: '~^(?!messenger_messages)~'
    schema_provider: Weaver\ORM\Bridge\DoctrineMigrations\WeaverSchemaProvider
```

Then generate a migration as usual:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

The generated migration will contain the SQL produced by comparing the Weaver mapper schema against the database state, exactly as `weaver:schema:diff` would show.

This approach keeps Doctrine Migrations as the execution engine (with its version tracking and rollback support) while using Weaver mappers as the single source of truth for the schema definition.
