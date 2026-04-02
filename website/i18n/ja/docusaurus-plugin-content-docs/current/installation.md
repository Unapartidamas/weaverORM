---
id: installation
title: インストール
---

## 要件

Weaver ORM をインストールする前に、環境が最小要件を満たしていることを確認してください：

| 要件 | バージョン |
|---|---|
| PHP | **8.4** 以上 |
| Symfony | **7.0** 以上 |
| doctrine/dbal | 4.0（自動的に導入） |
| データベース | MySQL 8.0+ / PostgreSQL 14+ / SQLite 3.35+ |

## ステップ 1 — Composer でインストール

```bash
docker compose exec app composer require weaver/orm
```

これにより以下が導入されます：

- `weaver/orm` — コアマッパー、クエリビルダー、作業単位
- `weaver/orm-bundle` — Symfony バンドル（Symfony Flex により自動登録）
- `doctrine/dbal ^4.0` — 接続およびスキーマ抽象化レイヤーとして使用（Doctrine ORM ではありません）

:::info Docker
このドキュメントのすべてのコマンドは、Docker コンテナ内で実行することを前提としています。サービス名（`app`）を `docker-compose.yml` に合わせて調整してください。
:::

## ステップ 2 — バンドルの登録

Symfony Flex を使用している場合、バンドルは自動的に登録されます。使用していない場合は、`config/bundles.php` に手動で追加してください：

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... 他のバンドル ...
    Weaver\ORM\Bundle\WeaverOrmBundle::class => ['all' => true],
];
```

## ステップ 3 — 設定ファイルの作成

最小限の接続設定で `config/packages/weaver.yaml` を作成します：

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'

    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'
```

データベース URL を `.env` ファイル（ローカル上書きには `.env.local`）に追加します：

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=16&charset=utf8"
```

## ステップ 4 — インストールの確認

```bash
docker compose exec app bin/console weaver:info
```

期待される出力：

```
Weaver ORM — version 1.0.0
Connection:   pdo_pgsql (connected)
Mapper paths: src/Mapper (0 mappers found)
Migrations:   migrations/weaver (0 migrations)
```

## サポートされているデータベースドライバー

| ドライバー | データベース |
|---|---|
| `pdo_pgsql` | PostgreSQL 14+ |
| `pdo_mysql` | MySQL 8.0+ / MariaDB 10.6+ |
| `pdo_sqlite` | SQLite 3.35+ |
| `pyrosql` | PyroSQL（分析用、読み取り最適化） |

## オプションパッケージ

### PyroSQL（分析用リードレプリカ）

```bash
docker compose exec app composer require weaver/pyrosql-adapter
```

読み取り負荷の高いクエリやレポーティング用のセカンダリ接続として、高性能なインプロセス分析エンジンを有効にします。

### MongoDB ドキュメントマッパー

```bash
docker compose exec app composer require mongodb/mongodb
```

`ext-mongodb` PHP 拡張が必要です。リレーショナルマッパーと並行してドキュメント指向ストレージのための `AbstractDocumentMapper` を有効にします。

### Symfony Messenger 統合

```bash
docker compose exec app composer require symfony/messenger
```

エンティティのライフサイクルフック内からのアウトボックスパターンと非同期ドメインイベントパブリッシュを有効にします。

### クエリ結果キャッシング

```bash
docker compose exec app composer require symfony/cache
```

ハイドレートされた結果を PSR-6 キャッシュプールに保存するために、クエリビルダーチェーン上で `->cache(ttl: 60)` を有効にします。
