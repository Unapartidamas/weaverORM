---
id: entity-mapping
title: 实体映射
---

Weaver ORM 通过将所有映射信息放入专用的**映射器类（Mapper Class）**中，将领域对象与持久化元数据分离。本页涵盖映射器配置的各个方面。

## 为何使用映射器而非属性（Attribute）？

Doctrine ORM 通过 PHP 8 属性将映射元数据直接放在实体类上：

```php
// Doctrine 方式 — 实体了解数据库
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

Weaver 严格分离两者：

```
实体类        →  纯 PHP 对象，零 ORM 依赖
映射器类      →  所有持久化知识都在这里
```

优势：
- **零运行时反射。** 映射器是纯 PHP，返回数组和标量。
- **无代理类。** 无需磁盘上的代码生成。
- **Worker 安全。** 映射器不保存任何每请求状态。
- **独立可测试。** 在单元测试中实例化和检查映射器，无需启动 Symfony。
- **完全可 grep 搜索。** 每个列名、每种类型、每个选项都以纯文本呈现，显示在 `git diff` 中。

## 映射器与实体：职责划分

| 关注点 | 归属 |
|---|---|
| 业务逻辑、不变量 | 实体类 |
| 属性和 PHP 类型 | 实体类 |
| 表名和模式 | 映射器 |
| 列名、类型、选项 | 映射器 |
| 索引和约束 | 映射器 |
| 水化（行 → 实体） | 映射器 |
| 提取（实体 → 行） | 映射器 |
| 关联关系 | 映射器 |

## 基本实体定义

实体可以是任何 PHP 类。它不继承任何类，不实现任何接口，也不从 `Weaver\ORM` 引入任何内容。

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

实体可以是：
- **不可变的**（推荐）— 变更方法返回新实例
- **可变的** — 公开属性或 setter 均可
- **抽象的** — 用于继承层次结构

## AbstractMapper

每个实体都需要且只需要一个映射器。创建一个继承 `Weaver\ORM\Mapping\AbstractMapper` 的类并实现必要的方法。

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

### 必需的映射器方法

| 方法 | 用途 |
|---|---|
| `table(): string` | 数据库中的表名 |
| `primaryKey(): string\|array` | 主键的列名 |
| `schema(): SchemaDefinition` | 用于 DDL 和迁移的所有列定义 |
| `hydrate(array $row): object` | 从原始数据库行构建实体 |
| `dehydrate(object $entity): array` | 将实体序列化为列 => 值数组 |

### 可选的映射器方法

| 方法 | 用途 |
|---|---|
| `readOnly(): bool` | 对视图支撑的实体返回 `true`（禁止 INSERT/UPDATE/DELETE） |
| `discriminatorColumn(): ?string` | 用于单表继承（STI） |
| `discriminatorMap(): array` | 用于单表继承（STI） |
| `parentMapper(): ?string` | 用于类表继承（CTI） |

## 列类型

所有列定义都使用 `ColumnDefinition` 上的静态工厂方法。每个方法返回一个带有流式配置 API 的 `ColumnDefinition` 实例。

### string

映射到 `VARCHAR(n)`。默认长度为 255。

```php
ColumnDefinition::string('username')                    // VARCHAR(255) NOT NULL
ColumnDefinition::string('slug', 100)                   // VARCHAR(100) NOT NULL
ColumnDefinition::string('nickname')->nullable()        // VARCHAR(255) NULL
```

### integer、bigint、smallint

```php
ColumnDefinition::integer('sort_order')                 // INT NOT NULL
ColumnDefinition::integer('quantity')->default(0)       // INT NOT NULL DEFAULT 0
ColumnDefinition::integer('stock')->unsigned()          // INT UNSIGNED NOT NULL
ColumnDefinition::bigint('view_count')->default(0)      // BIGINT NOT NULL DEFAULT 0
ColumnDefinition::smallint('priority')->unsigned()      // SMALLINT UNSIGNED NOT NULL
```

### float 和 decimal

财务值使用 `decimal`；坐标和测量值使用 `float`。

```php
ColumnDefinition::float('latitude')
ColumnDefinition::float('longitude')
ColumnDefinition::decimal('price', 10, 2)              // DECIMAL(10,2) NOT NULL
ColumnDefinition::decimal('tax_rate', 5, 4)->default('0.0000')
```

水化 `decimal` 时保持字符串以保留精度：

```php
price: $row['price'],  // 保持字符串，传给 Money 值对象
```

### boolean

在 MySQL 上映射到 `TINYINT(1)`，在 PostgreSQL/SQLite 上映射到 `BOOLEAN`。

```php
ColumnDefinition::boolean('is_active')->default(true)
ColumnDefinition::boolean('email_verified')->default(false)
```

在 `hydrate` 中始终进行显式转换：

```php
isActive: (bool) $row['is_active'],
```

### datetime、date、time

```php
ColumnDefinition::datetime('published_at')->nullable()   // DATETIME NULL
ColumnDefinition::date('birth_date')->nullable()         // DATE NULL
ColumnDefinition::time('opens_at')                       // TIME NOT NULL
```

`datetime` 返回可变的 `\DateTime`。新代码推荐使用 `datetimeImmutable`：

```php
ColumnDefinition::datetimeImmutable('created_at')        // DATETIME NOT NULL
ColumnDefinition::datetimeImmutable('updated_at')->nullable()
```

水化：

```php
createdAt: new \DateTimeImmutable($row['created_at']),
updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
```

提取：

```php
'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
'updated_at' => $entity->updatedAt?->format('Y-m-d H:i:s'),
```

### json

映射到 `JSON`（MySQL 5.7.8+、PostgreSQL、SQLite）。编码/解码在 `hydrate` / `dehydrate` 中控制。

```php
ColumnDefinition::json('metadata')->nullable()
ColumnDefinition::json('settings')
```

水化：

```php
metadata: $row['metadata'] !== null
    ? json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR)
    : null,
```

提取：

```php
'metadata' => $entity->metadata !== null
    ? json_encode($entity->metadata, JSON_THROW_ON_ERROR)
    : null,
```

### text、blob

```php
ColumnDefinition::text('body')                           // TEXT NOT NULL
ColumnDefinition::text('description')->nullable()        // TEXT NULL
ColumnDefinition::blob('thumbnail')                      // BLOB NOT NULL
```

### guid（UUID 作为 CHAR(36)）

```php
ColumnDefinition::guid('external_ref')->nullable()       // CHAR(36) NULL
```

## 主键类型

### 自增整数

```php
ColumnDefinition::integer('id')->autoIncrement()->unsigned()
```

```sql
id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id)
```

当值为 `null` 时，Weaver 在 `INSERT` 中省略 `id`，并自动读取生成的值。

### UUID v4（随机）

```php
ColumnDefinition::guid('id')->primaryKey()
```

在持久化前，在实体工厂方法中生成 UUID：

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v4(), name: $name);
}
```

### UUID v7（时间有序，推荐）

UUID v7 包含毫秒级时间戳前缀，使键单调递增，与随机 UUID 相比大幅减少 B 树页面分裂。

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

### 自然字符串键

当业务键本身是唯一的（国家代码、货币代码、slug）：

```php
ColumnDefinition::string('code', 3)->primaryKey()
```

### 复合主键

```php
ColumnDefinition::integer('user_id')->primaryKey(),
ColumnDefinition::integer('role_id')->primaryKey(),
ColumnDefinition::datetimeImmutable('assigned_at'),
```

```sql
PRIMARY KEY (user_id, role_id)
```

## 列选项

所有选项都可作为 `ColumnDefinition` 上的流式方法使用：

| 方法 | 效果 |
|---|---|
| `->nullable()` | 列接受 NULL 值 |
| `->default($value)` | 在 DDL 中设置 DEFAULT 子句 |
| `->unsigned()` | 应用 UNSIGNED（仅整数类型） |
| `->unique()` | 添加 UNIQUE 约束 |
| `->primaryKey()` | 将列标记为主键的一部分 |
| `->autoIncrement()` | 添加 AUTO_INCREMENT（仅整数主键） |
| `->generated()` | 列由数据库计算；从 INSERT/UPDATE 中排除 |
| `->comment(string)` | 添加列级 DDL 注释 |

## PHP 8.1 枚举映射

PHP 有背景值的枚举（`string` 或 `int` 背景类型）可以自然地映射到数据库列。

### 字符串背景枚举

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

映射器：

```php
ColumnDefinition::string('status', 20)
    ->comment('pending|confirmed|shipped|delivered|cancelled')
```

水化：

```php
status: OrderStatus::from($row['status']),
```

提取：

```php
'status' => $entity->status->value,
```

### 整数背景枚举

```php
enum Priority: int
{
    case Low    = 1;
    case Normal = 2;
    case High   = 3;
    case Urgent = 4;
}
```

映射器：

```php
ColumnDefinition::smallint('priority')->unsigned()
```

水化：

```php
priority: Priority::from((int) $row['priority']),
```

### 可空枚举

```php
ColumnDefinition::string('resolution', 20)->nullable()
```

水化：

```php
resolution: $row['resolution'] !== null
    ? Resolution::from($row['resolution'])
    : null,
```

:::tip
始终存储 `->value`（例如 `'pending'`），而非 `->name`（例如 `'Pending'`）。PHP 中的标签可以自由重命名；值则不能，否则需要迁移。
:::

## 生成/计算列

由数据库引擎填充的列（例如 `GENERATED ALWAYS AS`）必须从 `INSERT` 和 `UPDATE` 语句中排除。

```php
ColumnDefinition::string('full_name', 162)->generated(),
ColumnDefinition::decimal('total', 10, 2)->generated(),
```

Weaver 自动从写入载荷中剥离 `generated` 列。它们仍会出现在 `hydrate` 中。

## 列别名

当 PHP 属性名与数据库列名不同时，使用别名：

```php
// PHP 属性 'email' 映射到数据库列 'usr_email'
ColumnDefinition::string('email')->alias('usr_email')
```

在 `hydrate` 中，使用列名（即别名）作为数组键：

```php
email: $row['usr_email'],
```

在 `dehydrate` 中，返回列名作为键：

```php
'usr_email' => $entity->email,
```

## 在 Symfony 中注册映射器

如果 `config/services.yaml` 中设置了 `autoconfigure: true`（Symfony 默认设置），则在配置的 `mapper_paths` 中任何继承 `AbstractMapper` 的类都会被自动标记和注册——无需手动服务定义。

对于显式注册或覆盖默认值：

```yaml
# config/services.yaml
services:
    App\Mapper\UserMapper:
        tags:
            - { name: weaver.mapper }
```
