---
id: entity-mapping
title: Entity-Mapping
---

Weaver ORM trennt Domain-Objekte von Persistenz-Metadaten, indem alle Mapping-Informationen in einer dedizierten **Mapper-Klasse** abgelegt werden. Diese Seite behandelt jeden Aspekt der Mapper-Konfiguration.

## Warum Mapper statt Attribute?

Doctrine ORM platziert Mapping-Metadaten direkt auf der Entity-Klasse über PHP-8-Attribute:

```php
// Doctrine-Ansatz — Entity kennt die Datenbank
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

Weaver hält sie strikt getrennt:

```
Entity-Klasse      →  einfaches PHP-Objekt, keinerlei ORM-Abhängigkeiten
Mapper-Klasse      →  das gesamte Persistenzwissen lebt hier
```

Vorteile:
- **Keine Laufzeit-Reflection.** Der Mapper ist reines PHP, das Arrays und Skalare zurückgibt.
- **Keine Proxy-Klassen.** Keine Codegenerierung auf der Festplatte erforderlich.
- **Worker-sicher.** Mapper halten keinen anfragespezifischen Zustand.
- **Isoliert testbar.** Einen Mapper in einem Unit-Test instanziieren und prüfen, ohne Symfony zu booten.
- **Vollständig durchsuchbar.** Jeder Spaltenname, jeder Typ, jede Option erscheint im Klartext und taucht in `git diff` auf.

## Mapper vs. Entity: Verantwortlichkeiten

| Anliegen | Lebt in |
|---|---|
| Geschäftslogik, Invarianten | Entity-Klasse |
| Eigenschaften und PHP-Typen | Entity-Klasse |
| Tabellenname und Schema | Mapper |
| Spaltennamen, Typen, Optionen | Mapper |
| Indizes und Constraints | Mapper |
| Hydration (Zeile → Entity) | Mapper |
| Extraktion (Entity → Zeile) | Mapper |
| Beziehungen | Mapper |

## Grundlegende Entity-Definition

Eine Entity ist eine beliebige PHP-Klasse. Sie erweitert nichts, implementiert nichts und importiert nichts aus `Weaver\ORM`.

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

Die Entity kann sein:
- **Unveränderlich** (empfohlen) — mutierende Methoden geben neue Instanzen zurück
- **Veränderlich** — öffentliche Eigenschaften oder Setter sind erlaubt
- **Abstrakt** — für Vererbungshierarchien

## AbstractMapper

Jede Entity benötigt genau einen Mapper. Erstellen Sie eine Klasse, die `Weaver\ORM\Mapping\AbstractMapper` erweitert, und implementieren Sie die erforderlichen Methoden.

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

### Erforderliche Mapper-Methoden

| Methode | Zweck |
|---|---|
| `table(): string` | Tabellenname in der Datenbank |
| `primaryKey(): string\|array` | Spaltenname(n) für den Primärschlüssel |
| `schema(): SchemaDefinition` | Alle Spaltendefinitionen für DDL und Migrationen |
| `hydrate(array $row): object` | Entity aus einer rohen Datenbankzeile erstellen |
| `dehydrate(object $entity): array` | Entity in ein Spalte => Wert-Array serialisieren |

### Optionale Mapper-Methoden

| Methode | Zweck |
|---|---|
| `readOnly(): bool` | `true` zurückgeben für view-basierte Entities (kein INSERT/UPDATE/DELETE) |
| `discriminatorColumn(): ?string` | Wird für Single Table Inheritance verwendet |
| `discriminatorMap(): array` | Wird für Single Table Inheritance verwendet |
| `parentMapper(): ?string` | Wird für Class Table Inheritance verwendet |

## Spaltentypen

Alle Spaltendefinitionen verwenden statische Factory-Methoden auf `ColumnDefinition`. Jede Methode gibt eine `ColumnDefinition`-Instanz mit einer Fluent-Konfigurations-API zurück.

### string

Wird auf `VARCHAR(n)` abgebildet. Standardlänge ist 255.

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

### float und decimal

Verwenden Sie `decimal` für Finanzwerte; `float` für Koordinaten und Messungen.

```php
ColumnDefinition::float('latitude')
ColumnDefinition::float('longitude')
ColumnDefinition::decimal('price', 10, 2)              // DECIMAL(10,2) NOT NULL
ColumnDefinition::decimal('tax_rate', 5, 4)->default('0.0000')
```

Hydratisieren Sie `decimal` als String, um die Genauigkeit zu erhalten:

```php
price: $row['price'],  // als String behalten, an ein Money-Value-Object übergeben
```

### boolean

Wird auf `TINYINT(1)` bei MySQL, `BOOLEAN` bei PostgreSQL/SQLite abgebildet.

```php
ColumnDefinition::boolean('is_active')->default(true)
ColumnDefinition::boolean('email_verified')->default(false)
```

Immer explizit in `hydrate` casten:

```php
isActive: (bool) $row['is_active'],
```

### datetime, date, time

```php
ColumnDefinition::datetime('published_at')->nullable()   // DATETIME NULL
ColumnDefinition::date('birth_date')->nullable()         // DATE NULL
ColumnDefinition::time('opens_at')                       // TIME NOT NULL
```

`datetime` gibt ein veränderliches `\DateTime` zurück. Bevorzugen Sie `datetimeImmutable` für neuen Code:

```php
ColumnDefinition::datetimeImmutable('created_at')        // DATETIME NOT NULL
ColumnDefinition::datetimeImmutable('updated_at')->nullable()
```

Hydration:

```php
createdAt: new \DateTimeImmutable($row['created_at']),
updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
```

Extraktion:

```php
'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
'updated_at' => $entity->updatedAt?->format('Y-m-d H:i:s'),
```

### json

Wird auf `JSON` abgebildet (MySQL 5.7.8+, PostgreSQL, SQLite). Die Kodierung/Dekodierung kontrollieren Sie in `hydrate` / `dehydrate`.

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

Extraktion:

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

### guid (UUID als CHAR(36))

```php
ColumnDefinition::guid('external_ref')->nullable()       // CHAR(36) NULL
```

## Primärschlüsseltypen

### Auto-Increment-Integer

```php
ColumnDefinition::integer('id')->autoIncrement()->unsigned()
```

```sql
id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id)
```

Weaver lässt `id` bei `INSERT` weg, wenn der Wert `null` ist, und liest den generierten Wert automatisch zurück.

### UUID v4 (zufällig)

```php
ColumnDefinition::guid('id')->primaryKey()
```

Die UUID in der Entity-Factory-Methode vor dem Persistieren generieren:

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v4(), name: $name);
}
```

### UUID v7 (zeitgeordnet, empfohlen)

UUID v7 enthält ein Millisekundengenauigkeits-Zeitstempel-Präfix, wodurch Schlüssel monoton steigend sind und B-Baum-Seitenteilungen im Vergleich zu zufälligen UUIDs drastisch reduziert werden.

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

### Natürlicher String-Schlüssel

Wenn der Geschäftsschlüssel von Natur aus eindeutig ist (Ländercode, Währungscode, Slug):

```php
ColumnDefinition::string('code', 3)->primaryKey()
```

### Zusammengesetzter Primärschlüssel

```php
ColumnDefinition::integer('user_id')->primaryKey(),
ColumnDefinition::integer('role_id')->primaryKey(),
ColumnDefinition::datetimeImmutable('assigned_at'),
```

```sql
PRIMARY KEY (user_id, role_id)
```

## Spaltenoptionen

Alle Optionen sind als Fluent-Methoden auf `ColumnDefinition` verfügbar:

| Methode | Wirkung |
|---|---|
| `->nullable()` | Spalte akzeptiert NULL-Werte |
| `->default($value)` | Setzt eine DEFAULT-Klausel im DDL |
| `->unsigned()` | Wendet UNSIGNED an (nur Integer-Typen) |
| `->unique()` | Fügt einen UNIQUE-Constraint hinzu |
| `->primaryKey()` | Markiert die Spalte als Teil des Primärschlüssels |
| `->autoIncrement()` | Fügt AUTO_INCREMENT hinzu (nur Integer-PKs) |
| `->generated()` | Spalte wird von der Datenbank berechnet; von INSERT/UPDATE ausgeschlossen |
| `->comment(string)` | Fügt einen DDL-Kommentar auf Spaltenebene hinzu |

## PHP-8.1-Enum-Mapping

PHP-gestützte Enums (`string`- oder `int`-Backing-Typ) werden natürlich auf Datenbankspalten abgebildet.

### String-gestützter Enum

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

Extraktion:

```php
'status' => $entity->status->value,
```

### Int-gestützter Enum

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

### Nullable Enum

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
Speichern Sie immer `->value` (z. B. `'pending'`), niemals `->name` (z. B. `'Pending'`). Labels können frei in PHP umbenannt werden; Werte können es ohne Migration nicht.
:::

## Generierte / berechnete Spalten

Spalten, die von der Datenbank-Engine befüllt werden (z. B. `GENERATED ALWAYS AS`), müssen von `INSERT`- und `UPDATE`-Anweisungen ausgeschlossen werden.

```php
ColumnDefinition::string('full_name', 162)->generated(),
ColumnDefinition::decimal('total', 10, 2)->generated(),
```

Weaver entfernt `generated`-Spalten automatisch aus Schreib-Payloads. Sie erscheinen weiterhin in `hydrate`.

## Spalten-Aliase

Verwenden Sie einen Alias, wenn der PHP-Eigenschaftsname vom Datenbankspaltennamen abweicht:

```php
// PHP-Eigenschaft 'email' wird auf DB-Spalte 'usr_email' abgebildet
ColumnDefinition::string('email')->alias('usr_email')
```

In `hydrate` den Spaltennamen (den Alias) als Array-Schlüssel verwenden:

```php
email: $row['usr_email'],
```

In `dehydrate` den Spaltennamen als Schlüssel zurückgeben:

```php
'usr_email' => $entity->email,
```

## Mapper in Symfony registrieren

Wenn `autoconfigure: true` in `config/services.yaml` gesetzt ist (Symfony-Standard), wird jede Klasse, die `AbstractMapper` in den konfigurierten `mapper_paths` erweitert, automatisch getaggt und registriert — keine manuelle Service-Definition erforderlich.

Für explizite Registrierung oder zum Überschreiben von Standardwerten:

```yaml
# config/services.yaml
services:
    App\Mapper\UserMapper:
        tags:
            - { name: weaver.mapper }
```
