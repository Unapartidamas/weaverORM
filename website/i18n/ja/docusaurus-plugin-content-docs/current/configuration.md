---
id: configuration
title: Symfony 設定
---

Weaver ORM は `config/packages/weaver.yaml` で設定します。このページではすべての利用可能なオプションを説明します。

## 最小設定

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
```

## 完全な設定リファレンス

```yaml
# config/packages/weaver.yaml
weaver_orm:

    # ------------------------------------------------------------------ #
    # プライマリ（書き込み）接続
    # ------------------------------------------------------------------ #
    connection:
        driver: pdo_pgsql           # pdo_pgsql | pdo_mysql | pdo_sqlite | pyrosql
        url: '%env(DATABASE_URL)%'  # DSN は以下の個別オプションより優先されます

        # 個別オプション（url: の代替）
        # host:     '%env(DB_HOST)%'
        # port:     '%env(int:DB_PORT)%'
        # dbname:   '%env(DB_NAME)%'
        # user:     '%env(DB_USER)%'
        # password: '%env(DB_PASSWORD)%'

        # 接続プールオプション（FrankenPHP / RoadRunner）
        # persistent: true
        # charset:    utf8mb4      # MySQL のみ

    # ------------------------------------------------------------------ #
    # リードレプリカ（オプション）
    # ------------------------------------------------------------------ #
    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'

    # ------------------------------------------------------------------ #
    # マッパー検出
    # ------------------------------------------------------------------ #
    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
        # 複数の境界コンテキスト用にパスを追加：
        # - '%kernel.project_dir%/src/Billing/Mapper'
        # - '%kernel.project_dir%/src/Catalog/Mapper'

    # ------------------------------------------------------------------ #
    # マイグレーション
    # ------------------------------------------------------------------ #
    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'

    # ------------------------------------------------------------------ #
    # デバッグと安全設定
    # ------------------------------------------------------------------ #
    debug: '%kernel.debug%'         # すべてのクエリを Symfony プロファイラーにログ出力

    # 開発環境で N+1 クエリパターンを検出して警告する
    n1_detector: true               # debug: true の場合のみ有効

    # SELECT が N 行を超える場合に例外をスローします。
    # 本番環境での意図しないフルテーブルスキャンから保護します。
    # 無効にするには 0 に設定。
    max_rows_safety_limit: 5000
```

## 接続ドライバー

### pdo_pgsql — PostgreSQL

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'
```

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/myapp?serverVersion=16&charset=utf8"
```

### pdo_mysql — MySQL / MariaDB

```yaml
weaver_orm:
    connection:
        driver: pdo_mysql
        url: '%env(DATABASE_URL)%'
```

```dotenv
DATABASE_URL="mysql://app:secret@db:3306/myapp?serverVersion=8.0&charset=utf8mb4"
```

### pdo_sqlite — SQLite（テスト／組み込み）

```yaml
weaver_orm:
    connection:
        driver: pdo_sqlite
        url: '%env(DATABASE_URL)%'
```

```dotenv
# インメモリ（インテグレーションテストに便利）：
DATABASE_URL="sqlite:///:memory:"

# ファイルベース：
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db"
```

### pyrosql — PyroSQL 分析エンジン

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    # 分析クエリ用の専用リード接続としての PyroSQL
    read_connection:
        driver: pyrosql
        url: '%env(VALKARNSQL_URL)%'
```

## 環境変数

典型的な `.env` / `.env.local` の設定：

```dotenv
# .env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# .env.local（VCS にコミットしない）
DATABASE_URL="postgresql://app:mylocalpwd@db:5432/app_dev?serverVersion=16&charset=utf8"

# リードレプリカ（オプション）
DATABASE_READ_URL="postgresql://app_ro:readpwd@db-replica:5432/app?serverVersion=16&charset=utf8"
```

## リードレプリカの設定

`read_connection` が定義されると、Weaver は `SELECT` クエリをリード接続に、`INSERT` / `UPDATE` / `DELETE` クエリをプライマリ接続に自動的にルーティングします。

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'
```

クエリを強制的にプライマリ接続に向けるには（例：書き込み直後）、`->onPrimary()` を使用します：

```php
// レプリカをバイパスして常にプライマリから読み取る
$user = $this->users->query()
    ->onPrimary()
    ->where('id', '=', $id)
    ->first();
```

## デバッグモードと N+1 検出器

`debug: true`（`dev` 環境での Symfony のデフォルト）の場合、Weaver は：

- すべての SQL クエリとそのバインディングを Symfony Web プロファイラーにログ出力します。
- `n1_detector: true` の場合、**N+1 検出器**を有効にします。検出器はイーガーロードパターンを検査し、リレーションが事前ロードなしに複数のエンティティでアクセスされたことを検出した場合、プロファイラーに警告を発します。

```yaml
# config/packages/dev/weaver.yaml
weaver_orm:
    debug: true
    n1_detector: true
    max_rows_safety_limit: 1000  # 開発環境ではより厳格に
```

```yaml
# config/packages/prod/weaver.yaml
weaver_orm:
    debug: false
    n1_detector: false
    max_rows_safety_limit: 5000
```

## 複数の境界コンテキスト

アプリケーションが複数のデータベースを使用している場合、または境界コンテキスト間の厳密な分離が必要な場合は、Symfony のサービスタグ付けを直接使用して複数の接続セットを登録できます。詳細は[高度な設定ガイド](#)を参照してください。
