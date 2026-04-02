---
id: concerns
title: Concerns と Traits
---

Weaver ORM provides built-in **concerns** — PHP traits that add common behaviour (timestamps, soft deletes, UUID generation) to entities and their mappers. They follow the same explicit-mapping philosophy: each concern contributes both the entity-side PHP logic and the mapper-side column definitions.

## HasTimestamps

Automatically manages `created_at` and `updated_at` columns. When an entity is first persisted, both timestamps are set. On every subsequent update, `updated_at` is refreshed to the current time.

### Entity trait

```php
<?php
// src/Entity/Post.php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Weaver\ORM\Concerns\HasTimestamps;

final class Post
{
    use HasTimestamps;

    public function __construct(
        public readonly ?int $id,
        public readonly string $title,
        public readonly string $body,
        // HasTimestamps adds:
        //   public readonly DateTimeImmutable $createdAt
        //   public readonly ?DateTimeImmutable $updatedAt
    ) {}
}
```

### Mapper trait

```php
<?php
// src/Mapper/PostMapper.php

declare(strict_types=1);

namespace App\Mapper;

use App\Entity\Post;
use Weaver\ORM\Mapping\AbstractMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\SchemaDefinition;
use Weaver\ORM\Concerns\Mapper\HasTimestampsMapper;

final class PostMapper extends AbstractMapper
{
    use HasTimestampsMapper;

    public function table(): string { return 'posts'; }
    public function primaryKey(): string { return 'id'; }

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ColumnDefinition::integer('id')->autoIncrement()->unsigned(),
            ColumnDefinition::string('title')->notNull(),
            ColumnDefinition::text('body')->notNull(),
            ...$this->timestampColumns(),  // adds created_at, updated_at
        );
    }

    public function hydrate(array $row): Post
    {
        return new Post(
            id:        (int) $row['id'],
            title:     $row['title'],
            body:      $row['body'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: isset($row['updated_at'])
                ? new \DateTimeImmutable($row['updated_at'])
                : null,
        );
    }

    public function dehydrate(object $entity): array
    {
        return [
            'id'    => $entity->id,
            'title' => $entity->title,
            'body'  => $entity->body,
            // HasTimestampsMapper::dehydrateTimestamps() handles the rest
            ...$this->dehydrateTimestamps($entity),
        ];
    }
}
```

### Generated columns

```sql
created_at  DATETIME NOT NULL,
updated_at  DATETIME NULL,
```

### Behaviour

| Action | created_at | updated_at |
|---|---|---|
| `save()` on new entity (id is null) | Set to `now()` | Set to `now()` |
| `save()` on existing entity | Unchanged | Set to `now()` |
| Manual override | Set explicitly if passed to constructor | Set explicitly if passed to constructor |

---

## HasSoftDeletes

Adds a `deleted_at` timestamp column. Instead of issuing a `DELETE` statement, Weaver sets `deleted_at = now()`. All subsequent queries automatically exclude soft-deleted rows unless you opt in with `withTrashed()` or `onlyTrashed()`.

### Entity trait

```php
<?php
// src/Entity/User.php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Weaver\ORM\Concerns\HasSoftDeletes;
use Weaver\ORM\Concerns\HasTimestamps;

final class User
{
    use HasTimestamps;
    use HasSoftDeletes;

    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $email,
        // HasTimestamps adds: createdAt, updatedAt
        // HasSoftDeletes adds: ?deletedAt
    ) {}

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

### Mapper trait

```php
<?php
// src/Mapper/UserMapper.php

use Weaver\ORM\Concerns\Mapper\HasSoftDeletesMapper;
use Weaver\ORM\Concerns\Mapper\HasTimestampsMapper;

final class UserMapper extends AbstractMapper
{
    use HasTimestampsMapper;
    use HasSoftDeletesMapper;

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ColumnDefinition::integer('id')->autoIncrement()->unsigned(),
            ColumnDefinition::string('name', 120)->notNull(),
            ColumnDefinition::string('email', 254)->unique()->notNull(),
            ...$this->timestampColumns(),    // created_at, updated_at
            ...$this->softDeleteColumns(),   // deleted_at
        );
    }

    public function hydrate(array $row): User
    {
        return new User(
            id:        (int) $row['id'],
            name:      $row['name'],
            email:     $row['email'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
            deletedAt: isset($row['deleted_at']) ? new \DateTimeImmutable($row['deleted_at']) : null,
        );
    }
}
```

### Generated column

```sql
deleted_at  DATETIME NULL,
```

### Querying soft-deleted records

```php
// Default: excludes soft-deleted rows (WHERE deleted_at IS NULL added automatically)
$users = $userRepository->findAll();

// Include soft-deleted rows alongside active rows
$users = $userRepository->query()
    ->withTrashed()
    ->get();

// Return ONLY soft-deleted rows
$deleted = $userRepository->query()
    ->onlyTrashed()
    ->get();

// Restore a soft-deleted entity
$user = $userRepository->query()->onlyTrashed()->where('id', 42)->first();
$userRepository->restore($user);

// Hard delete (permanently remove from the database)
$userRepository->forceDelete($user);
```

### Soft-delete via repository

```php
// Issues UPDATE users SET deleted_at = now() WHERE id = ?
$userRepository->delete($user);
```

### Cascade soft delete

When an entity with `HasSoftDeletes` is soft-deleted, you can instruct Weaver to cascade to related entities:

```php
// In UserMapper relations():
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->localKey('id')
    ->cascadeSoftDelete(true);
```

---

## HasUuid

Generates a UUID v7 primary key before the entity is first persisted. This eliminates the need to manually call `Uuid::v7()` in every factory method.

### Entity trait

```php
<?php
// src/Entity/Article.php

declare(strict_types=1);

namespace App\Entity;

use Weaver\ORM\Concerns\HasUuid;

final class Article
{
    use HasUuid;

    public function __construct(
        // HasUuid adds: public readonly string $id (UUID v7 auto-generated)
        public readonly string $title,
        public readonly string $body,
    ) {}
}
```

### Mapper trait

```php
<?php
// src/Mapper/ArticleMapper.php

use Weaver\ORM\Concerns\Mapper\HasUuidMapper;

final class ArticleMapper extends AbstractMapper
{
    use HasUuidMapper;

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ...$this->uuidPrimaryKeyColumn(),  // id CHAR(36) NOT NULL PRIMARY KEY
            ColumnDefinition::string('title')->notNull(),
            ColumnDefinition::text('body')->notNull(),
        );
    }

    public function hydrate(array $row): Article
    {
        return new Article(
            id:    $row['id'],
            title: $row['title'],
            body:  $row['body'],
        );
    }

    public function dehydrate(object $entity): array
    {
        return [
            'id'    => $entity->id,
            'title' => $entity->title,
            'body'  => $entity->body,
        ];
    }
}
```

### Generated column

```sql
id  CHAR(36) NOT NULL,
PRIMARY KEY (id)
```

### Creating entities with HasUuid

Because the trait handles ID generation, you do not supply the `id` in the constructor:

```php
$article = Article::create(
    title: 'Introduction to Weaver ORM',
    body:  'Weaver is a PHP 8.4+ ORM...',
);

$articleRepository->save($article);

echo $article->id; // e.g. '01956f2e-3a7b-7000-9c1d-4b8f2a1c3e5d'
```

The UUID v7 format ensures primary keys are time-ordered, which reduces B-tree page splits and gives implicit insertion-order sorting.

---

## Combining concerns

Concerns can be freely combined. A typical entity with all three:

```php
final class Invoice
{
    use HasUuid;
    use HasTimestamps;
    use HasSoftDeletes;

    public function __construct(
        // id: string (UUID v7, auto-generated)
        // createdAt: DateTimeImmutable (auto-managed)
        // updatedAt: ?DateTimeImmutable (auto-managed)
        // deletedAt: ?DateTimeImmutable (soft delete)
        public readonly string $number,
        public readonly string $total,
    ) {}
}
```

Mapper:

```php
final class InvoiceMapper extends AbstractMapper
{
    use HasUuidMapper;
    use HasTimestampsMapper;
    use HasSoftDeletesMapper;

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ...$this->uuidPrimaryKeyColumn(),
            ColumnDefinition::string('number', 20)->unique()->notNull(),
            ColumnDefinition::decimal('total', 12, 2)->notNull(),
            ...$this->timestampColumns(),
            ...$this->softDeleteColumns(),
        );
    }
}
```

Generated SQL:

```sql
CREATE TABLE invoices (
    id          CHAR(36)       NOT NULL,
    number      VARCHAR(20)    NOT NULL,
    total       DECIMAL(12, 2) NOT NULL,
    created_at  DATETIME       NOT NULL,
    updated_at  DATETIME       NULL,
    deleted_at  DATETIME       NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_number (number)
);
```
