---
id: configuration
title: Symfony 配置
---

Weaver ORM 通过 `config/packages/weaver.yaml` 进行配置。本页涵盖所有可用选项。

## 最简配置

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
```

## 完整配置参考

```yaml
# config/packages/weaver.yaml
weaver_orm:

    # ------------------------------------------------------------------ #
    # 主（写）连接
    # ------------------------------------------------------------------ #
    connection:
        driver: pdo_pgsql           # pdo_pgsql | pdo_mysql | pdo_sqlite | pyrosql
        url: '%env(DATABASE_URL)%'  # DSN 优先于下方的各项独立选项

        # 独立选项（url: 的替代方式）
        # host:     '%env(DB_HOST)%'
        # port:     '%env(int:DB_PORT)%'
        # dbname:   '%env(DB_NAME)%'
        # user:     '%env(DB_USER)%'
        # password: '%env(DB_PASSWORD)%'

        # 连接池选项（FrankenPHP / RoadRunner）
        # persistent: true
        # charset:    utf8mb4      # 仅 MySQL

    # ------------------------------------------------------------------ #
    # 只读副本（可选）
    # ------------------------------------------------------------------ #
    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'

    # ------------------------------------------------------------------ #
    # 映射器自动发现
    # ------------------------------------------------------------------ #
    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
        # 为多个限界上下文添加更多路径：
        # - '%kernel.project_dir%/src/Billing/Mapper'
        # - '%kernel.project_dir%/src/Catalog/Mapper'

    # ------------------------------------------------------------------ #
    # 迁移
    # ------------------------------------------------------------------ #
    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'

    # ------------------------------------------------------------------ #
    # 调试与安全
    # ------------------------------------------------------------------ #
    debug: '%kernel.debug%'         # 将所有查询记录到 Symfony Profiler

    # 在开发环境中检测并警告 N+1 查询模式
    n1_detector: true               # 仅在 debug: true 时激活

    # 如果 SELECT 返回超过 N 行则抛出异常
    # 防止在生产环境中意外进行全表扫描
    # 设为 0 可禁用
    max_rows_safety_limit: 5000
```

## 连接驱动

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

### pdo_sqlite — SQLite（测试 / 嵌入式）

```yaml
weaver_orm:
    connection:
        driver: pdo_sqlite
        url: '%env(DATABASE_URL)%'
```

```dotenv
# 内存模式（适用于集成测试）：
DATABASE_URL="sqlite:///:memory:"

# 基于文件：
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db"
```

### pyrosql — PyroSQL 分析引擎

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    # PyroSQL 作为分析查询的专用读连接
    read_connection:
        driver: pyrosql
        url: '%env(VALKARNSQL_URL)%'
```

## 环境变量

典型的 `.env` / `.env.local` 配置：

```dotenv
# .env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# .env.local（不提交到版本控制）
DATABASE_URL="postgresql://app:mylocalpwd@db:5432/app_dev?serverVersion=16&charset=utf8"

# 只读副本（可选）
DATABASE_READ_URL="postgresql://app_ro:readpwd@db-replica:5432/app?serverVersion=16&charset=utf8"
```

## 只读副本配置

当定义了 `read_connection` 时，Weaver 自动将 `SELECT` 查询路由到读连接，将 `INSERT` / `UPDATE` / `DELETE` 查询路由到主连接。

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'
```

要强制查询走主连接（例如在写操作后立即读取），使用 `->onPrimary()`：

```php
// 始终从主库读取，绕过副本
$user = $this->users->query()
    ->onPrimary()
    ->where('id', '=', $id)
    ->first();
```

## 调试模式与 N+1 检测器

当 `debug: true`（`dev` 环境下的 Symfony 默认值）时，Weaver 会：

- 将每条 SQL 查询及其绑定参数记录到 Symfony Web Profiler。
- 当 `n1_detector: true` 时激活 **N+1 检测器**。该检测器检查预加载模式，如果检测到某个关联在多个实体上被访问但未预加载，则在 Profiler 中发出警告。

```yaml
# config/packages/dev/weaver.yaml
weaver_orm:
    debug: true
    n1_detector: true
    max_rows_safety_limit: 1000  # 开发环境更严格
```

```yaml
# config/packages/prod/weaver.yaml
weaver_orm:
    debug: false
    n1_detector: false
    max_rows_safety_limit: 5000
```

## 多限界上下文

如果你的应用使用多个数据库，或者希望在限界上下文之间进行严格隔离，可以直接使用 Symfony 的服务标记来注册多组连接配置。详情参见[高级配置指南](#)。
