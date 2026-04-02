---
id: installation
title: 安装
---

## 系统要求

安装 Weaver ORM 之前，请验证你的环境是否满足最低要求：

| 要求 | 版本 |
|---|---|
| PHP | **8.4** 或更高 |
| Symfony | **7.0** 或更高 |
| doctrine/dbal | 4.0（自动引入） |
| 数据库 | MySQL 8.0+ / PostgreSQL 14+ / SQLite 3.35+ |

## 第一步 — 通过 Composer 安装

```bash
docker compose exec app composer require weaver/orm
```

此命令将引入：

- `weaver/orm` — 核心映射器、查询构建器和工作单元
- `weaver/orm-bundle` — Symfony Bundle（由 Symfony Flex 自动注册）
- `doctrine/dbal ^4.0` — 用作连接和模式抽象层（非 Doctrine ORM）

:::info Docker
本文档中的所有命令均假设你在 Docker 容器内运行。请将服务名称（`app`）调整为与你的 `docker-compose.yml` 匹配的名称。
:::

## 第二步 — 注册 Bundle

如果你使用 Symfony Flex，Bundle 会自动注册。否则，请手动将其添加到 `config/bundles.php`：

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... 其他 Bundle ...
    Weaver\ORM\Bundle\WeaverOrmBundle::class => ['all' => true],
];
```

## 第三步 — 创建配置文件

创建 `config/packages/weaver.yaml`，包含最简连接配置：

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

将数据库 URL 添加到 `.env` 文件（或用于本地覆盖的 `.env.local`）：

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=16&charset=utf8"
```

## 第四步 — 验证安装

```bash
docker compose exec app bin/console weaver:info
```

预期输出：

```
Weaver ORM — version 1.0.0
Connection:   pdo_pgsql (connected)
Mapper paths: src/Mapper (0 mappers found)
Migrations:   migrations/weaver (0 migrations)
```

## 支持的数据库驱动

| 驱动 | 数据库 |
|---|---|
| `pdo_pgsql` | PostgreSQL 14+ |
| `pdo_mysql` | MySQL 8.0+ / MariaDB 10.6+ |
| `pdo_sqlite` | SQLite 3.35+ |
| `pyrosql` | PyroSQL（分析型，读优化） |

## 可选扩展包

### PyroSQL（分析型只读副本）

```bash
docker compose exec app composer require weaver/pyrosql-adapter
```

启用高性能进程内分析引擎，作为读密集型查询和报表的辅助连接。

### MongoDB 文档映射器

```bash
docker compose exec app composer require mongodb/mongodb
```

需要 `ext-mongodb` PHP 扩展。启用 `AbstractDocumentMapper`，支持在关系型映射器旁边进行文档导向存储。

### Symfony Messenger 集成

```bash
docker compose exec app composer require symfony/messenger
```

启用发件箱（Outbox）模式和在实体生命周期钩子内进行异步领域事件发布。

### 查询结果缓存

```bash
docker compose exec app composer require symfony/cache
```

在查询构建器链上启用 `->cache(ttl: 60)`，将已水化的结果存储到 PSR-6 缓存池中。
