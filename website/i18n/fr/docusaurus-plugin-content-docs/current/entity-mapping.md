---
id: entity-mapping
title: Mapping d'entités
---

Weaver ORM sépare les objets de domaine des métadonnées de persistance en plaçant toutes les informations de mapping dans une **classe mapper** dédiée. Cette page couvre tous les aspects de la configuration des mappers.

## Pourquoi des mappers plutôt que des attributs ?

Doctrine ORM place les métadonnées de mapping directement sur la classe entité via les attributs PHP 8 :

```php
// Approche Doctrine — l'entité connaît la base de données
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

Weaver les sépare strictement :

```
Classe entité       →  objet PHP simple, zéro dépendance ORM
Classe mapper       →  toute la connaissance de persistance réside ici
```

Avantages :
- **Zéro réflexion à l'exécution.** Le mapper est du PHP simple renvoyant des tableaux et des scalaires.
- **Pas de classes proxy.** Aucune génération de code sur disque nécessaire.
- **Sûr pour les workers.** Les mappers ne conservent aucun état par requête.
- **Testable en isolation.** Instanciez et inspectez un mapper dans un test unitaire sans démarrer Symfony.
- **Entièrement consultable via grep.** Chaque nom de colonne, chaque type, chaque option apparaît en texte brut et dans `git diff`.

## Mapper vs entité : responsabilités

| Responsabilité | Réside dans |
|---|---|
| Logique métier, invariants | Classe entité |
| Propriétés et types PHP | Classe entité |
| Nom de table et schéma | Mapper |
| Noms de colonnes, types, options | Mapper |
| Index et contraintes | Mapper |
| Hydratation (ligne → entité) | Mapper |
| Extraction (entité → ligne) | Mapper |
| Relations | Mapper |

## Définition d'entité de base

Une entité est toute classe PHP. Elle n'étend rien, n'implémente rien et n'importe rien de `Weaver\ORM`.

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

L'entité peut être :
- **Immuable** (recommandé) — les méthodes de mutation renvoient de nouvelles instances
- **Mutable** — les propriétés publiques ou les setters sont acceptables
- **Abstraite** — pour les hiérarchies d'héritage

## AbstractMapper

Chaque entité nécessite exactement un mapper. Créez une classe étendant `Weaver\ORM\Mapping\AbstractMapper` et implémentez les méthodes requises.

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

### Méthodes mapper requises

| Méthode | Objectif |
|---|---|
| `table(): string` | Nom de la table dans la base de données |
| `primaryKey(): string\|array` | Nom(s) de colonne pour la clé primaire |
| `schema(): SchemaDefinition` | Toutes les définitions de colonnes pour le DDL et les migrations |
| `hydrate(array $row): object` | Construire une entité à partir d'une ligne de base de données brute |
| `dehydrate(object $entity): array` | Sérialiser une entité en tableau colonne => valeur |

### Méthodes mapper optionnelles

| Méthode | Objectif |
|---|---|
| `readOnly(): bool` | Retourner `true` pour les entités basées sur des vues (pas d'INSERT/UPDATE/DELETE) |
| `discriminatorColumn(): ?string` | Utilisé pour l'héritage de table unique (STI) |
| `discriminatorMap(): array` | Utilisé pour l'héritage de table unique (STI) |
| `parentMapper(): ?string` | Utilisé pour l'héritage de table de classe (CTI) |

## Types de colonnes

Toutes les définitions de colonnes utilisent des méthodes factory statiques sur `ColumnDefinition`. Chaque méthode retourne une instance `ColumnDefinition` avec une API de configuration fluide.

### string

Se mappe sur `VARCHAR(n)`. La longueur par défaut est 255.

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

### float et decimal

Utilisez `decimal` pour les valeurs financières ; `float` pour les coordonnées et les mesures.

```php
ColumnDefinition::float('latitude')
ColumnDefinition::float('longitude')
ColumnDefinition::decimal('price', 10, 2)              // DECIMAL(10,2) NOT NULL
ColumnDefinition::decimal('tax_rate', 5, 4)->default('0.0000')
```

Hydratez `decimal` sous forme de chaîne pour préserver la précision :

```php
price: $row['price'],  // conserver comme chaîne, passer à un objet valeur Money
```

### boolean

Se mappe sur `TINYINT(1)` sur MySQL, `BOOLEAN` sur PostgreSQL/SQLite.

```php
ColumnDefinition::boolean('is_active')->default(true)
ColumnDefinition::boolean('email_verified')->default(false)
```

Toujours caster explicitement dans `hydrate` :

```php
isActive: (bool) $row['is_active'],
```

### datetime, date, time

```php
ColumnDefinition::datetime('published_at')->nullable()   // DATETIME NULL
ColumnDefinition::date('birth_date')->nullable()         // DATE NULL
ColumnDefinition::time('opens_at')                       // TIME NOT NULL
```

`datetime` retourne un `\DateTime` mutable. Préférez `datetimeImmutable` pour le nouveau code :

```php
ColumnDefinition::datetimeImmutable('created_at')        // DATETIME NOT NULL
ColumnDefinition::datetimeImmutable('updated_at')->nullable()
```

Hydratation :

```php
createdAt: new \DateTimeImmutable($row['created_at']),
updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
```

Extraction :

```php
'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
'updated_at' => $entity->updatedAt?->format('Y-m-d H:i:s'),
```

### json

Se mappe sur `JSON` (MySQL 5.7.8+, PostgreSQL, SQLite). Vous contrôlez l'encodage/décodage dans `hydrate` / `dehydrate`.

```php
ColumnDefinition::json('metadata')->nullable()
ColumnDefinition::json('settings')
```

Hydratation :

```php
metadata: $row['metadata'] !== null
    ? json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR)
    : null,
```

Extraction :

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

### guid (UUID sous forme de CHAR(36))

```php
ColumnDefinition::guid('external_ref')->nullable()       // CHAR(36) NULL
```

## Types de clé primaire

### Entier auto-incrémenté

```php
ColumnDefinition::integer('id')->autoIncrement()->unsigned()
```

```sql
id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id)
```

Weaver omet `id` de l'`INSERT` quand la valeur est `null` et relit automatiquement la valeur générée.

### UUID v4 (aléatoire)

```php
ColumnDefinition::guid('id')->primaryKey()
```

Générez l'UUID dans la méthode factory de l'entité avant de persister :

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v4(), name: $name);
}
```

### UUID v7 (ordonné dans le temps, recommandé)

L'UUID v7 inclut un préfixe de timestamp en millisecondes, rendant les clés monotoniquement croissantes et réduisant considérablement les fractionnements de pages B-tree par rapport aux UUIDs aléatoires.

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

### Clé de chaîne naturelle

Quand la clé métier est naturellement unique (code pays, code devise, slug) :

```php
ColumnDefinition::string('code', 3)->primaryKey()
```

### Clé primaire composite

```php
ColumnDefinition::integer('user_id')->primaryKey(),
ColumnDefinition::integer('role_id')->primaryKey(),
ColumnDefinition::datetimeImmutable('assigned_at'),
```

```sql
PRIMARY KEY (user_id, role_id)
```

## Options de colonnes

Toutes les options sont disponibles comme méthodes fluides sur `ColumnDefinition` :

| Méthode | Effet |
|---|---|
| `->nullable()` | La colonne accepte les valeurs NULL |
| `->default($value)` | Définit une clause DEFAULT dans le DDL |
| `->unsigned()` | Applique UNSIGNED (types entiers uniquement) |
| `->unique()` | Ajoute une contrainte UNIQUE |
| `->primaryKey()` | Marque la colonne comme faisant partie de la clé primaire |
| `->autoIncrement()` | Ajoute AUTO_INCREMENT (PKs entières uniquement) |
| `->generated()` | La colonne est calculée par la DB ; exclue des INSERT/UPDATE |
| `->comment(string)` | Ajoute un commentaire DDL au niveau de la colonne |

## Mapping d'enum PHP 8.1

Les enums PHP avec backing type (`string` ou `int`) se mappent naturellement sur les colonnes de base de données.

### Enum à backing string

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

Mapper :

```php
ColumnDefinition::string('status', 20)
    ->comment('pending|confirmed|shipped|delivered|cancelled')
```

Hydratation :

```php
status: OrderStatus::from($row['status']),
```

Extraction :

```php
'status' => $entity->status->value,
```

### Enum à backing int

```php
enum Priority: int
{
    case Low    = 1;
    case Normal = 2;
    case High   = 3;
    case Urgent = 4;
}
```

Mapper :

```php
ColumnDefinition::smallint('priority')->unsigned()
```

Hydratation :

```php
priority: Priority::from((int) $row['priority']),
```

### Enum nullable

```php
ColumnDefinition::string('resolution', 20)->nullable()
```

Hydratation :

```php
resolution: $row['resolution'] !== null
    ? Resolution::from($row['resolution'])
    : null,
```

:::tip
Stockez toujours `->value` (par exemple `'pending'`), jamais `->name` (par exemple `'Pending'`). Les labels peuvent être renommés librement en PHP ; les valeurs ne peuvent pas l'être sans migration.
:::

## Colonnes générées / calculées

Les colonnes renseignées par le moteur de base de données (par exemple `GENERATED ALWAYS AS`) doivent être exclues des instructions `INSERT` et `UPDATE`.

```php
ColumnDefinition::string('full_name', 162)->generated(),
ColumnDefinition::decimal('total', 10, 2)->generated(),
```

Weaver supprime automatiquement les colonnes `generated` des charges utiles d'écriture. Elles apparaissent toujours dans `hydrate`.

## Alias de colonnes

Utilisez un alias quand le nom de propriété PHP diffère du nom de colonne de base de données :

```php
// La propriété PHP 'email' se mappe sur la colonne DB 'usr_email'
ColumnDefinition::string('email')->alias('usr_email')
```

Dans `hydrate`, utilisez le nom de colonne (l'alias) comme clé de tableau :

```php
email: $row['usr_email'],
```

Dans `dehydrate`, retournez le nom de colonne comme clé :

```php
'usr_email' => $entity->email,
```

## Enregistrement des mappers dans Symfony

Si `autoconfigure: true` est défini dans `config/services.yaml` (la valeur par défaut de Symfony), toute classe étendant `AbstractMapper` dans les `mapper_paths` configurés est automatiquement taguée et enregistrée — aucune définition de service manuelle n'est nécessaire.

Pour un enregistrement explicite ou pour surcharger les valeurs par défaut :

```yaml
# config/services.yaml
services:
    App\Mapper\UserMapper:
        tags:
            - { name: weaver.mapper }
```
