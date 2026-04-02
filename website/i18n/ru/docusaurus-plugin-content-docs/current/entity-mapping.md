---
id: entity-mapping
title: Маппинг сущностей
---

Weaver ORM разделяет доменные объекты и метаданные сохранения, размещая всю информацию о маппинге в выделенном **классе-маппере**. На этой странице описаны все аспекты конфигурации маппера.

## Почему маппера вместо атрибутов?

Doctrine ORM помещает метаданные маппинга прямо на класс сущности через атрибуты PHP 8:

```php
// Подход Doctrine — сущность знает о базе данных
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

Weaver строго их разделяет:

```
Класс сущности  →  чистый PHP-объект, нет зависимостей от ORM
Класс маппера   →  всё знание о сохранении находится здесь
```

Преимущества:
- **Нулевая рефлексия во время выполнения.** Маппер — это чистый PHP, возвращающий массивы и скаляры.
- **Никаких прокси-классов.** Не требуется генерация кода на диске.
- **Безопасность для воркеров.** Маппера не хранят состояние на уровне запроса.
- **Тестируемость в изоляции.** Создайте и проверьте маппер в юнит-тесте без запуска Symfony.
- **Полная прослеживаемость через grep.** Каждое имя колонки, каждый тип, каждая опция присутствует в виде обычного текста и виден в `git diff`.

## Маппер и сущность: распределение ответственностей

| Аспект | Где находится |
|---|---|
| Бизнес-логика, инварианты | Класс сущности |
| Свойства и PHP-типы | Класс сущности |
| Имя таблицы и схема | Маппер |
| Имена колонок, типы, опции | Маппер |
| Индексы и ограничения | Маппер |
| Гидрация (строка → сущность) | Маппер |
| Извлечение (сущность → строка) | Маппер |
| Связи | Маппер |

## Базовое определение сущности

Сущность — это любой PHP-класс. Он ничего не расширяет, не реализует и не импортирует из `Weaver\ORM`.

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

Сущность может быть:
- **Неизменяемой** (рекомендуется) — мутирующие методы возвращают новые экземпляры
- **Изменяемой** — публичные свойства или сеттеры допустимы
- **Абстрактной** — для иерархий наследования

## AbstractMapper

Каждой сущности нужен ровно один маппер. Создайте класс, расширяющий `Weaver\ORM\Mapping\AbstractMapper`, и реализуйте необходимые методы.

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

### Обязательные методы маппера

| Метод | Назначение |
|---|---|
| `table(): string` | Имя таблицы в базе данных |
| `primaryKey(): string\|array` | Имена колонок первичного ключа |
| `schema(): SchemaDefinition` | Все определения колонок для DDL и миграций |
| `hydrate(array $row): object` | Создание сущности из строки базы данных |
| `dehydrate(object $entity): array` | Сериализация сущности в массив колонка => значение |

### Необязательные методы маппера

| Метод | Назначение |
|---|---|
| `readOnly(): bool` | Вернуть `true` для сущностей на основе представлений (без INSERT/UPDATE/DELETE) |
| `discriminatorColumn(): ?string` | Используется для Single Table Inheritance |
| `discriminatorMap(): array` | Используется для Single Table Inheritance |
| `parentMapper(): ?string` | Используется для Class Table Inheritance |

## Типы колонок

Все определения колонок используют статические фабричные методы `ColumnDefinition`. Каждый метод возвращает экземпляр `ColumnDefinition` с fluent-API конфигурации.

### string

Отображается на `VARCHAR(n)`. Длина по умолчанию — 255.

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

### float и decimal

Используйте `decimal` для финансовых значений; `float` — для координат и измерений.

```php
ColumnDefinition::float('latitude')
ColumnDefinition::float('longitude')
ColumnDefinition::decimal('price', 10, 2)              // DECIMAL(10,2) NOT NULL
ColumnDefinition::decimal('tax_rate', 5, 4)->default('0.0000')
```

Гидрируйте `decimal` как строку для сохранения точности:

```php
price: $row['price'],  // оставить как строку, передать в объект-значение Money
```

### boolean

Отображается на `TINYINT(1)` в MySQL, `BOOLEAN` в PostgreSQL/SQLite.

```php
ColumnDefinition::boolean('is_active')->default(true)
ColumnDefinition::boolean('email_verified')->default(false)
```

Всегда выполняйте явное приведение в `hydrate`:

```php
isActive: (bool) $row['is_active'],
```

### datetime, date, time

```php
ColumnDefinition::datetime('published_at')->nullable()   // DATETIME NULL
ColumnDefinition::date('birth_date')->nullable()         // DATE NULL
ColumnDefinition::time('opens_at')                       // TIME NOT NULL
```

`datetime` возвращает изменяемый `\DateTime`. Для нового кода предпочтительно `datetimeImmutable`:

```php
ColumnDefinition::datetimeImmutable('created_at')        // DATETIME NOT NULL
ColumnDefinition::datetimeImmutable('updated_at')->nullable()
```

Гидрация:

```php
createdAt: new \DateTimeImmutable($row['created_at']),
updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
```

Извлечение:

```php
'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
'updated_at' => $entity->updatedAt?->format('Y-m-d H:i:s'),
```

### json

Отображается на `JSON` (MySQL 5.7.8+, PostgreSQL, SQLite). Кодирование/декодирование управляется в `hydrate` / `dehydrate`.

```php
ColumnDefinition::json('metadata')->nullable()
ColumnDefinition::json('settings')
```

Гидрация:

```php
metadata: $row['metadata'] !== null
    ? json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR)
    : null,
```

Извлечение:

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

### guid (UUID как CHAR(36))

```php
ColumnDefinition::guid('external_ref')->nullable()       // CHAR(36) NULL
```

## Типы первичных ключей

### Автоинкрементный целочисленный ключ

```php
ColumnDefinition::integer('id')->autoIncrement()->unsigned()
```

```sql
id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id)
```

Weaver пропускает `id` в `INSERT`, когда значение равно `null`, и автоматически считывает сгенерированное значение.

### UUID v4 (случайный)

```php
ColumnDefinition::guid('id')->primaryKey()
```

Генерируйте UUID в фабричном методе сущности перед сохранением:

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v4(), name: $name);
}
```

### UUID v7 (с сортировкой по времени, рекомендуется)

UUID v7 содержит префикс с миллисекундной меткой времени, что делает ключи монотонно возрастающими и значительно сокращает расщепление страниц B-дерева по сравнению со случайными UUID.

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

### Натуральный строковый ключ

Когда бизнес-ключ изначально уникален (код страны, код валюты, slug):

```php
ColumnDefinition::string('code', 3)->primaryKey()
```

### Составной первичный ключ

```php
ColumnDefinition::integer('user_id')->primaryKey(),
ColumnDefinition::integer('role_id')->primaryKey(),
ColumnDefinition::datetimeImmutable('assigned_at'),
```

```sql
PRIMARY KEY (user_id, role_id)
```

## Опции колонок

Все опции доступны как fluent-методы `ColumnDefinition`:

| Метод | Эффект |
|---|---|
| `->nullable()` | Колонка принимает NULL |
| `->default($value)` | Устанавливает DEFAULT в DDL |
| `->unsigned()` | Применяет UNSIGNED (только целочисленные типы) |
| `->unique()` | Добавляет ограничение UNIQUE |
| `->primaryKey()` | Помечает колонку как часть первичного ключа |
| `->autoIncrement()` | Добавляет AUTO_INCREMENT (только целочисленные PK) |
| `->generated()` | Колонка вычисляется БД; исключается из INSERT/UPDATE |
| `->comment(string)` | Добавляет комментарий к колонке на уровне DDL |

## Маппинг перечислений PHP 8.1

Перечисления PHP с подкреплением (`string` или `int`) естественно отображаются на колонки базы данных.

### Перечисление с подкреплением строкой

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

Маппер:

```php
ColumnDefinition::string('status', 20)
    ->comment('pending|confirmed|shipped|delivered|cancelled')
```

Гидрация:

```php
status: OrderStatus::from($row['status']),
```

Извлечение:

```php
'status' => $entity->status->value,
```

### Перечисление с подкреплением int

```php
enum Priority: int
{
    case Low    = 1;
    case Normal = 2;
    case High   = 3;
    case Urgent = 4;
}
```

Маппер:

```php
ColumnDefinition::smallint('priority')->unsigned()
```

Гидрация:

```php
priority: Priority::from((int) $row['priority']),
```

### Nullable перечисление

```php
ColumnDefinition::string('resolution', 20)->nullable()
```

Гидрация:

```php
resolution: $row['resolution'] !== null
    ? Resolution::from($row['resolution'])
    : null,
```

:::tip
Всегда сохраняйте `->value` (например, `'pending'`), но не `->name` (например, `'Pending'`). Метки можно свободно переименовывать в PHP; значения без миграции — нельзя.
:::

## Генерируемые / вычисляемые колонки

Колонки, заполняемые движком базы данных (например, `GENERATED ALWAYS AS`), должны исключаться из операторов `INSERT` и `UPDATE`.

```php
ColumnDefinition::string('full_name', 162)->generated(),
ColumnDefinition::decimal('total', 10, 2)->generated(),
```

Weaver автоматически убирает колонки с пометкой `generated` из полезной нагрузки записи. Они всё равно появляются в `hydrate`.

## Псевдонимы колонок

Используйте псевдоним, когда имя PHP-свойства отличается от имени колонки в базе данных:

```php
// PHP-свойство 'email' отображается на колонку БД 'usr_email'
ColumnDefinition::string('email')->alias('usr_email')
```

В `hydrate` используйте имя колонки (псевдоним) как ключ массива:

```php
email: $row['usr_email'],
```

В `dehydrate` возвращайте имя колонки как ключ:

```php
'usr_email' => $entity->email,
```

## Регистрация маппераов в Symfony

Если в `config/services.yaml` установлено `autoconfigure: true` (по умолчанию в Symfony), любой класс, расширяющий `AbstractMapper` из настроенных `mapper_paths`, автоматически помечается тегом и регистрируется — ручное определение сервиса не требуется.

Для явной регистрации или переопределения значений по умолчанию:

```yaml
# config/services.yaml
services:
    App\Mapper\UserMapper:
        tags:
            - { name: weaver.mapper }
```
