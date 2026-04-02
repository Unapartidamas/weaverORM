---
id: entity-mapping
title: エンティティマッピング
---

Weaver ORM は、すべてのマッピング情報を専用の**マッパークラス**に配置することで、ドメインオブジェクトと永続化メタデータを分離します。このページではマッパー設定のすべての側面を説明します。

## アトリビュートではなくマッパーを使う理由

Doctrine ORM は PHP 8 アトリビュートを通じてマッピングメタデータをエンティティクラスに直接配置します：

```php
// Doctrine のアプローチ — エンティティがデータベースを知っている
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

Weaver はそれらを厳密に分離します：

```
エンティティクラス  →  純粋な PHP オブジェクト、ORM 依存ゼロ
マッパークラス      →  すべての永続化知識はここに
```

利点：
- **実行時リフレクションゼロ。** マッパーは配列とスカラーを返す純粋な PHP です。
- **プロキシクラスなし。** ディスク上のコード生成が不要です。
- **ワーカーセーフ。** マッパーはリクエストごとの状態を持ちません。
- **独立したテスト可能性。** Symfony を起動せずにユニットテストでマッパーをインスタンス化して検査できます。
- **完全に grep 可能。** すべてのカラム名、型、オプションはプレーンテキストで表示され、`git diff` に現れます。

## マッパーとエンティティ：責務

| 関心事 | 配置場所 |
|---|---|
| ビジネスロジック、不変条件 | エンティティクラス |
| プロパティと PHP 型 | エンティティクラス |
| テーブル名とスキーマ | マッパー |
| カラム名、型、オプション | マッパー |
| インデックスと制約 | マッパー |
| ハイドレーション（行 → エンティティ） | マッパー |
| 抽出（エンティティ → 行） | マッパー |
| リレーション | マッパー |

## 基本的なエンティティ定義

エンティティは任意の PHP クラスです。何も継承せず、何も実装せず、`Weaver\ORM` から何もインポートしません。

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

エンティティは以下のいずれかにできます：
- **イミュータブル**（推奨）— ミューテーションメソッドは新しいインスタンスを返す
- **ミュータブル** — パブリックプロパティまたはセッターも可
- **抽象** — 継承階層のため

## AbstractMapper

すべてのエンティティには正確に1つのマッパーが必要です。`Weaver\ORM\Mapping\AbstractMapper` を継承するクラスを作成し、必要なメソッドを実装します。

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

### 必須のマッパーメソッド

| メソッド | 目的 |
|---|---|
| `table(): string` | データベース内のテーブル名 |
| `primaryKey(): string\|array` | プライマリキーのカラム名 |
| `schema(): SchemaDefinition` | DDL とマイグレーション用のすべてのカラム定義 |
| `hydrate(array $row): object` | 生のデータベース行からエンティティを構築 |
| `dehydrate(object $entity): array` | エンティティをカラム => 値の配列にシリアライズ |

### オプションのマッパーメソッド

| メソッド | 目的 |
|---|---|
| `readOnly(): bool` | ビューバックエンドエンティティには `true` を返す（INSERT/UPDATE/DELETE なし） |
| `discriminatorColumn(): ?string` | 単一テーブル継承に使用 |
| `discriminatorMap(): array` | 単一テーブル継承に使用 |
| `parentMapper(): ?string` | クラステーブル継承に使用 |

## カラム型

すべてのカラム定義は `ColumnDefinition` のスタティックファクトリメソッドを使用します。各メソッドはフルーエントな設定 API を持つ `ColumnDefinition` インスタンスを返します。

### string（文字列）

`VARCHAR(n)` にマップします。デフォルトの長さは 255 です。

```php
ColumnDefinition::string('username')                    // VARCHAR(255) NOT NULL
ColumnDefinition::string('slug', 100)                   // VARCHAR(100) NOT NULL
ColumnDefinition::string('nickname')->nullable()        // VARCHAR(255) NULL
```

### integer、bigint、smallint（整数型）

```php
ColumnDefinition::integer('sort_order')                 // INT NOT NULL
ColumnDefinition::integer('quantity')->default(0)       // INT NOT NULL DEFAULT 0
ColumnDefinition::integer('stock')->unsigned()          // INT UNSIGNED NOT NULL
ColumnDefinition::bigint('view_count')->default(0)      // BIGINT NOT NULL DEFAULT 0
ColumnDefinition::smallint('priority')->unsigned()      // SMALLINT UNSIGNED NOT NULL
```

### float と decimal（浮動小数点と固定小数点）

金融値には `decimal` を使用し、座標や計測値には `float` を使用します。

```php
ColumnDefinition::float('latitude')
ColumnDefinition::float('longitude')
ColumnDefinition::decimal('price', 10, 2)              // DECIMAL(10,2) NOT NULL
ColumnDefinition::decimal('tax_rate', 5, 4)->default('0.0000')
```

精度を保持するために `decimal` を文字列としてハイドレートします：

```php
price: $row['price'],  // 文字列として保持し、Money 値オブジェクトに渡す
```

### boolean（真偽値）

MySQL では `TINYINT(1)`、PostgreSQL/SQLite では `BOOLEAN` にマップします。

```php
ColumnDefinition::boolean('is_active')->default(true)
ColumnDefinition::boolean('email_verified')->default(false)
```

`hydrate` で常に明示的にキャストします：

```php
isActive: (bool) $row['is_active'],
```

### datetime、date、time（日時型）

```php
ColumnDefinition::datetime('published_at')->nullable()   // DATETIME NULL
ColumnDefinition::date('birth_date')->nullable()         // DATE NULL
ColumnDefinition::time('opens_at')                       // TIME NOT NULL
```

`datetime` はミュータブルな `\DateTime` を返します。新しいコードには `datetimeImmutable` を推奨します：

```php
ColumnDefinition::datetimeImmutable('created_at')        // DATETIME NOT NULL
ColumnDefinition::datetimeImmutable('updated_at')->nullable()
```

ハイドレーション：

```php
createdAt: new \DateTimeImmutable($row['created_at']),
updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
```

抽出：

```php
'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
'updated_at' => $entity->updatedAt?->format('Y-m-d H:i:s'),
```

### json（JSON型）

`JSON` にマップします（MySQL 5.7.8+、PostgreSQL、SQLite）。エンコード／デコードは `hydrate` / `dehydrate` で制御します。

```php
ColumnDefinition::json('metadata')->nullable()
ColumnDefinition::json('settings')
```

ハイドレーション：

```php
metadata: $row['metadata'] !== null
    ? json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR)
    : null,
```

抽出：

```php
'metadata' => $entity->metadata !== null
    ? json_encode($entity->metadata, JSON_THROW_ON_ERROR)
    : null,
```

### text、blob（テキスト、バイナリ）

```php
ColumnDefinition::text('body')                           // TEXT NOT NULL
ColumnDefinition::text('description')->nullable()        // TEXT NULL
ColumnDefinition::blob('thumbnail')                      // BLOB NOT NULL
```

### guid（UUID を CHAR(36) として）

```php
ColumnDefinition::guid('external_ref')->nullable()       // CHAR(36) NULL
```

## プライマリキーの型

### 自動インクリメント整数

```php
ColumnDefinition::integer('id')->autoIncrement()->unsigned()
```

```sql
id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id)
```

Weaver は値が `null` の場合、`INSERT` から `id` を省略し、生成された値を自動的に読み取ります。

### UUID v4（ランダム）

```php
ColumnDefinition::guid('id')->primaryKey()
```

永続化前にエンティティのファクトリメソッドで UUID を生成します：

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v4(), name: $name);
}
```

### UUID v7（時系列順、推奨）

UUID v7 にはミリ秒タイムスタンプのプレフィックスが含まれており、キーが単調増加するため、ランダム UUID と比較して B-tree のページ分割が大幅に削減されます。

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

### ナチュラル文字列キー

ビジネスキーが自然にユニークな場合（国コード、通貨コード、スラッグ）：

```php
ColumnDefinition::string('code', 3)->primaryKey()
```

### 複合プライマリキー

```php
ColumnDefinition::integer('user_id')->primaryKey(),
ColumnDefinition::integer('role_id')->primaryKey(),
ColumnDefinition::datetimeImmutable('assigned_at'),
```

```sql
PRIMARY KEY (user_id, role_id)
```

## カラムオプション

すべてのオプションは `ColumnDefinition` のフルーエントメソッドとして使用できます：

| メソッド | 効果 |
|---|---|
| `->nullable()` | カラムが NULL 値を受け入れる |
| `->default($value)` | DDL に DEFAULT 句を設定する |
| `->unsigned()` | UNSIGNED を適用する（整数型のみ） |
| `->unique()` | UNIQUE 制約を追加する |
| `->primaryKey()` | カラムをプライマリキーの一部としてマークする |
| `->autoIncrement()` | AUTO_INCREMENT を追加する（整数 PK のみ） |
| `->generated()` | カラムは DB で計算済み；INSERT/UPDATE から除外される |
| `->comment(string)` | カラムレベルの DDL コメントを追加する |

## PHP 8.1 列挙型マッピング

PHP のバックド列挙型（`string` または `int` バッキング型）はデータベースカラムに自然にマップします。

### 文字列バックド列挙型

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

マッパー：

```php
ColumnDefinition::string('status', 20)
    ->comment('pending|confirmed|shipped|delivered|cancelled')
```

ハイドレーション：

```php
status: OrderStatus::from($row['status']),
```

抽出：

```php
'status' => $entity->status->value,
```

### 整数バックド列挙型

```php
enum Priority: int
{
    case Low    = 1;
    case Normal = 2;
    case High   = 3;
    case Urgent = 4;
}
```

マッパー：

```php
ColumnDefinition::smallint('priority')->unsigned()
```

ハイドレーション：

```php
priority: Priority::from((int) $row['priority']),
```

### Nullable 列挙型

```php
ColumnDefinition::string('resolution', 20)->nullable()
```

ハイドレーション：

```php
resolution: $row['resolution'] !== null
    ? Resolution::from($row['resolution'])
    : null,
```

:::tip
常に `->value`（例：`'pending'`）を格納し、`->name`（例：`'Pending'`）は格納しないでください。ラベルは PHP で自由に変更できますが、値はマイグレーションなしには変更できません。
:::

## 生成／計算カラム

データベースエンジンによって設定されるカラム（例：`GENERATED ALWAYS AS`）は、`INSERT` および `UPDATE` ステートメントから除外する必要があります。

```php
ColumnDefinition::string('full_name', 162)->generated(),
ColumnDefinition::decimal('total', 10, 2)->generated(),
```

Weaver は `generated` カラムを書き込みペイロードから自動的に除去します。それらは `hydrate` には引き続き現れます。

## カラムエイリアス

PHP プロパティ名がデータベースカラム名と異なる場合にエイリアスを使用します：

```php
// PHP プロパティ 'email' は DB カラム 'usr_email' にマップ
ColumnDefinition::string('email')->alias('usr_email')
```

`hydrate` ではカラム名（エイリアス）を配列キーとして使用します：

```php
email: $row['usr_email'],
```

`dehydrate` ではカラム名をキーとして返します：

```php
'usr_email' => $entity->email,
```

## Symfony でのマッパー登録

`config/services.yaml` で `autoconfigure: true` が設定されている場合（Symfony のデフォルト）、設定された `mapper_paths` 内の `AbstractMapper` を継承するクラスは自動的にタグ付けされ登録されます — 手動のサービス定義は不要です。

明示的な登録やデフォルトの上書きには：

```yaml
# config/services.yaml
services:
    App\Mapper\UserMapper:
        tags:
            - { name: weaver.mapper }
```
