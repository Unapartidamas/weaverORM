---
id: quick-start
title: 快速开始
---

本指南通过一个完整的工作示例，带你了解：定义 `User` 实体、编写其映射器，以及使用 `EntityWorkspace`（实体工作区）执行基本的持久化操作。

## 第一步 — 定义实体

实体是一个普通的 PHP 类，不引入任何来自 `Weaver\ORM` 的内容。

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

    public function deactivate(): self
    {
        return new self(
            id:        $this->id,
            name:      $this->name,
            email:     $this->email,
            isActive:  false,
            createdAt: $this->createdAt,
        );
    }
}
```

注意：没有 `use Doctrine\...`，没有 `#[ORM\...]` 属性，没有基类。实体是一个纯粹的 PHP 值对象，无需启动 Symfony 或连接数据库即可进行单元测试。

## 第二步 — 编写映射器

映射器是一个独立的类，告知 Weaver `User` 如何映射到 `users` 表。它继承 `AbstractMapper` 并实现四个方法。

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

继承 `AbstractMapper` 的映射器会在 `config/services.yaml` 中启用 `autoconfigure: true`（Symfony 默认设置）时，由 Weaver 自动检测并注册。

## 第三步 — 创建数据库表

```bash
docker compose exec app bin/console weaver:schema:create
```

预览 SQL 而不执行：

```bash
docker compose exec app bin/console weaver:schema:create --dump-sql
```

## 第四步 — 编写仓储

继承 `AbstractRepository` 以获得针对实体的类型安全、可复用的查询 API。

```php
<?php
// src/Repository/UserRepository.php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Mapper\UserMapper;
use Weaver\ORM\Repository\AbstractRepository;

/** @extends AbstractRepository<User> */
final class UserRepository extends AbstractRepository
{
    protected function mapper(): UserMapper
    {
        return $this->get(UserMapper::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->query()
            ->where('email', '=', $email)
            ->first();
    }

    /** @return User[] */
    public function findActive(): array
    {
        return $this->query()
            ->where('is_active', '=', true)
            ->orderBy('name', 'ASC')
            ->get();
    }
}
```

`AbstractRepository` 内置提供了 `findById()`、`findAll()`、`save()`、`delete()` 和 `query()` 方法。

## 第五步 — 使用 EntityWorkspace 进行持久化

在控制器、命令或服务中，注入 `UserRepository`（以及可选的 `EntityWorkspace` 用于直接的工作单元控制）。

```php
<?php
// src/Controller/UserController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/users')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    // --- 创建 ---
    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();

        $user = new User(
            id:        null,
            name:      $data['name'],
            email:     $data['email'],
            isActive:  true,
            createdAt: new DateTimeImmutable(),
        );

        $this->users->save($user);

        return $this->json(['id' => $user->id], Response::HTTP_CREATED);
    }

    // --- 读取 ---
    #[Route('/{id}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'is_active'  => $user->isActive,
            'created_at' => $user->createdAt->format('c'),
        ]);
    }

    // --- 更新 ---
    #[Route('/{id}/email', methods: ['PATCH'])]
    public function updateEmail(int $id, Request $request): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        // 实体是不可变的 — 创建新版本并保存
        $updated = $user->withEmail($request->toArray()['email']);
        $this->users->save($updated);

        return $this->json(['email' => $updated->email]);
    }

    // --- 删除 ---
    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $this->users->delete($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
```

## 底层发生了什么

当你调用 `$this->users->save($user)` 时：

1. Weaver 调用映射器上的 `dehydrate($user)`，获得一个列名 → 值的数组。
2. 如果 `id` 为 `null`，则发出 `INSERT` 并将生成的 ID 写回实体。
3. 如果 `id` 已设置，则发出 `UPDATE ... WHERE id = ?`。
4. 无变更追踪，无脏检查对比，无代理类。语义是显式的。

## 下一步

- [实体映射](/entity-mapping) — 所有列类型、主键选项和映射器配置
- [关联关系](/relations) — HasOne、HasMany、BelongsTo、BelongsToMany 等
- [Symfony 配置](/configuration) — 完整的 `weaver.yaml` 参考，包含只读副本和调试选项
