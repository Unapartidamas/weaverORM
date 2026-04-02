---
id: entity-mapping
title: Mapeo de Entidades
---

Weaver ORM separa los objetos de dominio de los metadatos de persistencia poniendo toda la información de mapeo en una **clase mapper** dedicada. Esta página cubre todos los aspectos de la configuración del mapper.

## ¿Por qué mappers en lugar de atributos?

Doctrine ORM pone los metadatos de mapeo directamente en la clase de entidad mediante atributos de PHP 8:

```php
// Enfoque Doctrine — la entidad conoce la base de datos
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

Weaver los mantiene estrictamente separados:

```
Clase de entidad    →  objeto PHP simple, sin dependencias del ORM
Clase mapper        →  todo el conocimiento de persistencia vive aquí
```

Beneficios:
- **Sin reflexión en tiempo de ejecución.** El mapper es PHP simple que retorna arrays y escalares.
- **Sin clases proxy.** No se necesita generación de código en disco.
- **Seguro para workers.** Los mappers no tienen estado por petición.
- **Testeable en aislamiento.** Instancia e inspecciona un mapper en una prueba unitaria sin arrancar Symfony.
- **Completamente buscable con grep.** Cada nombre de columna, cada tipo, cada opción aparece en texto plano y se muestra en `git diff`.

## Mapper vs entidad: responsabilidades

| Responsabilidad | Se ubica en |
|---|---|
| Lógica de negocio, invariantes | Clase de entidad |
| Propiedades y tipos PHP | Clase de entidad |
| Nombre de tabla y esquema | Mapper |
| Nombres de columnas, tipos, opciones | Mapper |
| Índices y restricciones | Mapper |
| Hidratación (fila → entidad) | Mapper |
| Extracción (entidad → fila) | Mapper |
| Relaciones | Mapper |

## Definición básica de entidad

Una entidad es cualquier clase PHP. No extiende nada, no implementa nada, ni importa nada de `Weaver\ORM`.

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

La entidad puede ser:
- **Inmutable** (recomendado) — los métodos de mutación retornan nuevas instancias
- **Mutable** — las propiedades públicas o setters están bien
- **Abstracta** — para jerarquías de herencia

## AbstractMapper

Cada entidad necesita exactamente un mapper. Crea una clase que extienda `Weaver\ORM\Mapping\AbstractMapper` e implementa los métodos requeridos.

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

### Métodos requeridos del mapper

| Método | Propósito |
|---|---|
| `table(): string` | Nombre de la tabla en la base de datos |
| `primaryKey(): string\|array` | Nombre(s) de columna para la clave primaria |
| `schema(): SchemaDefinition` | Todas las definiciones de columnas para DDL y migraciones |
| `hydrate(array $row): object` | Construye una entidad desde una fila de base de datos sin procesar |
| `dehydrate(object $entity): array` | Serializa una entidad a un array columna => valor |

### Métodos opcionales del mapper

| Método | Propósito |
|---|---|
| `readOnly(): bool` | Retorna `true` para entidades respaldadas por vistas (sin INSERT/UPDATE/DELETE) |
| `discriminatorColumn(): ?string` | Usado para Herencia de Tabla Única |
| `discriminatorMap(): array` | Usado para Herencia de Tabla Única |
| `parentMapper(): ?string` | Usado para Herencia de Tabla de Clase |

## Tipos de columnas

Todas las definiciones de columnas usan métodos de fábrica estáticos en `ColumnDefinition`. Cada método retorna una instancia de `ColumnDefinition` con una API de configuración fluida.

### string

Se mapea a `VARCHAR(n)`. La longitud predeterminada es 255.

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

### float y decimal

Usa `decimal` para valores financieros; `float` para coordenadas y medidas.

```php
ColumnDefinition::float('latitude')
ColumnDefinition::float('longitude')
ColumnDefinition::decimal('price', 10, 2)              // DECIMAL(10,2) NOT NULL
ColumnDefinition::decimal('tax_rate', 5, 4)->default('0.0000')
```

Hidrata `decimal` como string para preservar la precisión:

```php
price: $row['price'],  // mantener como string, pasar a un objeto de valor Money
```

### boolean

Se mapea a `TINYINT(1)` en MySQL, `BOOLEAN` en PostgreSQL/SQLite.

```php
ColumnDefinition::boolean('is_active')->default(true)
ColumnDefinition::boolean('email_verified')->default(false)
```

Siempre castea explícitamente en `hydrate`:

```php
isActive: (bool) $row['is_active'],
```

### datetime, date, time

```php
ColumnDefinition::datetime('published_at')->nullable()   // DATETIME NULL
ColumnDefinition::date('birth_date')->nullable()         // DATE NULL
ColumnDefinition::time('opens_at')                       // TIME NOT NULL
```

`datetime` retorna un `\DateTime` mutable. Prefiere `datetimeImmutable` para código nuevo:

```php
ColumnDefinition::datetimeImmutable('created_at')        // DATETIME NOT NULL
ColumnDefinition::datetimeImmutable('updated_at')->nullable()
```

Hidratación:

```php
createdAt: new \DateTimeImmutable($row['created_at']),
updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
```

Extracción:

```php
'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
'updated_at' => $entity->updatedAt?->format('Y-m-d H:i:s'),
```

### json

Se mapea a `JSON` (MySQL 5.7.8+, PostgreSQL, SQLite). Controlas la codificación/decodificación en `hydrate` / `dehydrate`.

```php
ColumnDefinition::json('metadata')->nullable()
ColumnDefinition::json('settings')
```

Hidratación:

```php
metadata: $row['metadata'] !== null
    ? json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR)
    : null,
```

Extracción:

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

### guid (UUID como CHAR(36))

```php
ColumnDefinition::guid('external_ref')->nullable()       // CHAR(36) NULL
```

## Tipos de clave primaria

### Entero autoincremental

```php
ColumnDefinition::integer('id')->autoIncrement()->unsigned()
```

```sql
id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id)
```

Weaver omite `id` del `INSERT` cuando el valor es `null` y lee el valor generado automáticamente.

### UUID v4 (aleatorio)

```php
ColumnDefinition::guid('id')->primaryKey()
```

Genera el UUID en el método de fábrica de la entidad antes de persistir:

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v4(), name: $name);
}
```

### UUID v7 (ordenado por tiempo, recomendado)

UUID v7 incluye un prefijo de timestamp en milisegundos, lo que hace que las claves sean monótonamente crecientes y reduce dramáticamente las divisiones de página B-tree comparado con UUIDs aleatorios.

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

### Clave string natural

Cuando la clave de negocio es naturalmente única (código de país, código de moneda, slug):

```php
ColumnDefinition::string('code', 3)->primaryKey()
```

### Clave primaria compuesta

```php
ColumnDefinition::integer('user_id')->primaryKey(),
ColumnDefinition::integer('role_id')->primaryKey(),
ColumnDefinition::datetimeImmutable('assigned_at'),
```

```sql
PRIMARY KEY (user_id, role_id)
```

## Opciones de columna

Todas las opciones están disponibles como métodos fluidos en `ColumnDefinition`:

| Método | Efecto |
|---|---|
| `->nullable()` | La columna acepta valores NULL |
| `->default($value)` | Establece una cláusula DEFAULT en el DDL |
| `->unsigned()` | Aplica UNSIGNED (solo tipos enteros) |
| `->unique()` | Añade una restricción UNIQUE |
| `->primaryKey()` | Marca la columna como parte de la clave primaria |
| `->autoIncrement()` | Añade AUTO_INCREMENT (solo PKs enteras) |
| `->generated()` | La columna está calculada por la BD; excluida de INSERT/UPDATE |
| `->comment(string)` | Añade un comentario DDL a nivel de columna |

## Mapeo de enums PHP 8.1

Los enums respaldados de PHP (`string` o `int` como tipo de respaldo) se mapean naturalmente a columnas de base de datos.

### Enum respaldado por string

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

Hidratación:

```php
status: OrderStatus::from($row['status']),
```

Extracción:

```php
'status' => $entity->status->value,
```

### Enum respaldado por int

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

Hidratación:

```php
priority: Priority::from((int) $row['priority']),
```

### Enum nullable

```php
ColumnDefinition::string('resolution', 20)->nullable()
```

Hidratación:

```php
resolution: $row['resolution'] !== null
    ? Resolution::from($row['resolution'])
    : null,
```

:::tip
Siempre almacena `->value` (por ejemplo `'pending'`), nunca `->name` (por ejemplo `'Pending'`). Las etiquetas pueden renombrarse libremente en PHP; los valores no pueden sin una migración.
:::

## Columnas generadas / calculadas

Las columnas pobladas por el motor de base de datos (por ejemplo `GENERATED ALWAYS AS`) deben excluirse de las sentencias `INSERT` y `UPDATE`.

```php
ColumnDefinition::string('full_name', 162)->generated(),
ColumnDefinition::decimal('total', 10, 2)->generated(),
```

Weaver elimina las columnas `generated` de los payloads de escritura automáticamente. Todavía aparecen en `hydrate`.

## Alias de columnas

Usa un alias cuando el nombre de la propiedad PHP difiere del nombre de la columna en la base de datos:

```php
// La propiedad PHP 'email' se mapea a la columna DB 'usr_email'
ColumnDefinition::string('email')->alias('usr_email')
```

En `hydrate`, usa el nombre de la columna (el alias) como clave del array:

```php
email: $row['usr_email'],
```

En `dehydrate`, retorna el nombre de la columna como clave:

```php
'usr_email' => $entity->email,
```

## Registro de mappers en Symfony

Si `autoconfigure: true` está establecido en `config/services.yaml` (el valor predeterminado de Symfony), cualquier clase que extienda `AbstractMapper` en las `mapper_paths` configuradas se etiqueta y registra automáticamente — no se necesita definición de servicio manual.

Para registro explícito o para sobreescribir valores predeterminados:

```yaml
# config/services.yaml
services:
    App\Mapper\UserMapper:
        tags:
            - { name: weaver.mapper }
```
