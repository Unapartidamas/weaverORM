---
id: intro
title: 什么是 Weaver ORM？
sidebar_label: 简介
slug: /
---

Weaver ORM 是一个面向 Symfony 应用的 PHP 8.4+ 对象关系映射器（ORM），其核心理念只有一条：**领域对象对数据库应当毫无感知**。实体类上无需注解，无需生成代理类，无需运行时反射——只需纯粹的 PHP 对象，以及将其与 SQL 相互转换的显式映射器（Mapper）类。

## Weaver 解决的问题

### Doctrine 代理对象（Proxy Objects）

Doctrine 将每个关联实体包裹在代理类中，通过拦截属性访问来在首次触碰时触发 SQL 查询。在传统的请求/响应周期中这是透明的，但它会悄然引发 N+1 查询问题，并使调试变得困难（`var_dump($post->getAuthor())` 打印的是代理对象，而非 `User`）。

在长期运行的 PHP Worker（RoadRunner、FrankenPHP、Swoole、Symfony Messenger）中，`EntityManager` 会在请求之间积累过期状态，必须在每个请求边界手动重置——这是很容易犯的错误，也是难以诊断的 Bug。

### 基于反射（Reflection）的水化

Doctrine 使用 `ReflectionProperty` 直接在实体对象上设置私有/受保护属性，绕过了领域逻辑。每次请求都必须重新解析 PHP 属性（Attribute）或命中预热缓存；代理类必须存在于磁盘上。

### 无界身份映射（Identity Map）

Doctrine `EntityManager` 在请求的整个生命周期内将所有已加载的实体保存在内存中。加载大型结果集会导致内存无限增长。解决方案 `$em->clear()` 会分离所有实体，包括你忘记重新持久化的那些。

## Weaver 的不同之处

Weaver 基于四项原则构建：

1. **纯粹的 PHP 对象作为实体。** 你的 `User` 类对 ORM 零依赖。无属性（Attribute）、无基类、无接口。它是一个纯粹的值对象或领域对象，无需启动 Symfony 即可进行单元测试。

2. **显式映射器类。** 独立的 `UserMapper` 类描述了 `User` 如何映射到 `users` 表。列类型、关联关系、主键——全部集中在一处，全部用纯 PHP 编写，完全可被 grep 搜索和静态分析。

3. **无代理，无隐式懒加载。** 关联关系始终通过 `->with(['relation'])` 显式加载。你始终清楚地知道何时执行了哪些 SQL。

4. **天生的 Worker 安全设计。** 映射器无状态，每个 Worker 进程加载一次。每个 HTTP 请求或 Messenger 任务都拥有自己的实体工作区（EntityWorkspace，即工作单元），因此请求之间不存在共享的可变状态。

## 核心差异一览

| 特性 | Doctrine ORM | Weaver ORM |
|---|---|---|
| 代理类生成 | 必需 | 不需要 |
| 运行时反射 | 是 | 从不 |
| 懒加载 | 隐式（代理） | 仅显式 |
| 实体注解/属性 | 在实体类上 | 独立的映射器类 |
| Worker 进程重置重启 | 是 | 否 |
| N+1 预防 | 手动 `JOIN FETCH` | 由 `with()` 强制执行 |
| 1 万行数据内存占用 | ~48 MB | ~11 MB |
| 1 万行数据水化时间 | ~420 ms | ~95 ms |
| PHPStan / 静态分析 | 部分支持（魔法代理） | 完全支持（显式映射器） |

> 基准测试环境：PHP 8.4、PostgreSQL 16、Ubuntu 22.04，10,000 条包含 `Profile` 关联的 `User` 行。结果因硬件和查询复杂度而异。

## 架构概览

```
Entity（纯 PHP 类 — 零 ORM 耦合）
    │
    └── Mapper（表名、列、关联关系、水化/提取）
            │
            └── EntityWorkspace → QueryBuilder → PDO/DBAL
```

`EntityWorkspace` 取代了 Doctrine 的 `EntityManager`。它是一个请求范围的工作单元，追踪哪些实体需要在 `flush()` 调用时执行插入、更新或删除。由于它是请求范围的，请求之间不会发生身份映射泄漏。

## PyroSQL 支持

Weaver 内置对 **PyroSQL** 的可选支持。PyroSQL 是一个高性能的进程内分析 SQL 引擎。它可以用作聚合查询、报表和大型数据集操作的只读副本，而无需访问主关系数据库。详情参见 [PyroSQL 章节](/pyrosql)。

## 系统要求

| 依赖项 | 最低版本 |
|---|---|
| PHP | 8.4 |
| Symfony | 7.0 |
| doctrine/dbal | 4.0（仅连接层） |
| MySQL | 8.0 |
| PostgreSQL | 14 |
| SQLite | 3.35 |

可选依赖：

- `symfony/messenger` — 异步事件发布与发件箱（Outbox）模式
- `symfony/cache` — 查询结果缓存
- `mongodb/mongodb` + `ext-mongodb` — MongoDB 文档映射器支持

## Weaver 不适用的场景

Weaver 不是 Doctrine 的直接替代品。如果你重度依赖 Doctrine 的 DQL、Criteria API 或基于属性的迁移，则需要重写该层。Weaver 最适合**全新的 Symfony 7+ 项目**，或**正在从 Doctrine 迁移出来**、希望获得显式、可预测的 SQL 以及 Worker 安全持久化的应用程序。
