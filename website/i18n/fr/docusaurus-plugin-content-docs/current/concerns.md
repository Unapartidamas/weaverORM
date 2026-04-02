---
id: concerns
title: Concerns et traits
---

Weaver ORM fournit des **concerns** intégrés — des traits PHP qui ajoutent des comportements courants (timestamps, suppressions douces, génération UUID) aux entités et à leurs mappers. Ils suivent la même philosophie de mapping explicite : chaque concern contribue à la fois la logique PHP côté entité et les définitions de colonnes côté mapper.

## HasTimestamps

Gère automatiquement les colonnes `created_at` et `updated_at`. Quand une entité est persistée pour la première fois, les deux timestamps sont définis. À chaque mise à jour ultérieure, `updated_at` est rafraîchi à l'heure actuelle.

### Trait d'entité

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
        // HasTimestamps ajoute :
        //   public readonly DateTimeImmutable $createdAt
        //   public readonly ?DateTimeImmutable $updatedAt
    ) {}
}
```

### Trait mapper

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
            ...$this->timestampColumns(),  // ajoute created_at, updated_at
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
            // HasTimestampsMapper::dehydrateTimestamps() gère le reste
            ...$this->dehydrateTimestamps($entity),
        ];
    }
}
```

### Colonnes générées

```sql
created_at  DATETIME NOT NULL,
updated_at  DATETIME NULL,
```

### Comportement

| Action | created_at | updated_at |
|---|---|---|
| `save()` sur une nouvelle entité (id est null) | Défini à `now()` | Défini à `now()` |
| `save()` sur une entité existante | Inchangé | Défini à `now()` |
| Surcharge manuelle | Défini explicitement si passé au constructeur | Défini explicitement si passé au constructeur |

---

## HasSoftDeletes

Ajoute une colonne timestamp `deleted_at`. Au lieu d'émettre une instruction `DELETE`, Weaver définit `deleted_at = now()`. Toutes les requêtes ultérieures excluent automatiquement les lignes supprimées de manière douce, sauf si vous activez `withTrashed()` ou `onlyTrashed()`.

### Trait d'entité

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
        // HasTimestamps ajoute : createdAt, updatedAt
        // HasSoftDeletes ajoute : ?deletedAt
    ) {}

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

### Trait mapper

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

### Colonne générée

```sql
deleted_at  DATETIME NULL,
```

### Interrogation des enregistrements supprimés de manière douce

```php
// Par défaut : exclut les lignes supprimées de manière douce (WHERE deleted_at IS NULL ajouté automatiquement)
$users = $userRepository->findAll();

// Inclure les lignes supprimées de manière douce avec les lignes actives
$users = $userRepository->query()
    ->withTrashed()
    ->get();

// Retourner UNIQUEMENT les lignes supprimées de manière douce
$deleted = $userRepository->query()
    ->onlyTrashed()
    ->get();

// Restaurer une entité supprimée de manière douce
$user = $userRepository->query()->onlyTrashed()->where('id', 42)->first();
$userRepository->restore($user);

// Suppression définitive (supprimer définitivement de la base de données)
$userRepository->forceDelete($user);
```

### Suppression douce via repository

```php
// Émet UPDATE users SET deleted_at = now() WHERE id = ?
$userRepository->delete($user);
```

### Cascade de suppression douce

Quand une entité avec `HasSoftDeletes` est supprimée de manière douce, vous pouvez demander à Weaver de cascader vers les entités liées :

```php
// Dans les relations() de UserMapper :
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->localKey('id')
    ->cascadeSoftDelete(true);
```

---

## HasUuid

Génère une clé primaire UUID v7 avant que l'entité soit persistée pour la première fois. Cela élimine le besoin d'appeler manuellement `Uuid::v7()` dans chaque méthode factory.

### Trait d'entité

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
        // HasUuid ajoute : public readonly string $id (UUID v7 auto-généré)
        public readonly string $title,
        public readonly string $body,
    ) {}
}
```

### Trait mapper

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

### Colonne générée

```sql
id  CHAR(36) NOT NULL,
PRIMARY KEY (id)
```

### Créer des entités avec HasUuid

Comme le trait gère la génération de l'ID, vous ne fournissez pas l'`id` dans le constructeur :

```php
$article = Article::create(
    title: 'Introduction à Weaver ORM',
    body:  'Weaver est un ORM PHP 8.4+...',
);

$articleRepository->save($article);

echo $article->id; // ex. '01956f2e-3a7b-7000-9c1d-4b8f2a1c3e5d'
```

Le format UUID v7 garantit que les clés primaires sont ordonnées dans le temps, ce qui réduit les fractionnements de pages B-tree et donne un tri implicite par ordre d'insertion.

---

## Combiner les concerns

Les concerns peuvent être librement combinés. Une entité typique avec les trois :

```php
final class Invoice
{
    use HasUuid;
    use HasTimestamps;
    use HasSoftDeletes;

    public function __construct(
        // id: string (UUID v7, auto-généré)
        // createdAt: DateTimeImmutable (auto-géré)
        // updatedAt: ?DateTimeImmutable (auto-géré)
        // deletedAt: ?DateTimeImmutable (suppression douce)
        public readonly string $number,
        public readonly string $total,
    ) {}
}
```

Mapper :

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

SQL généré :

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
