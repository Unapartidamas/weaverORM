---
id: quick-start
title: クイックスタート
---

このガイドでは、`User` エンティティの定義、マッパーの作成、`EntityWorkspace` を使った基本的な永続化操作という完全な実例を順を追って説明します。

## ステップ 1 — エンティティの定義

エンティティは純粋な PHP クラスです。`Weaver\ORM` から何もインポートしません。

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

注意点：`use Doctrine\...` なし、`#[ORM\...]` アトリビュートなし、基底クラスなし。エンティティは純粋な PHP 値オブジェクトで、Symfony を起動したりデータベースに接続したりすることなくユニットテスト可能です。

## ステップ 2 — マッパーの作成

マッパーは、Weaver に `User` を `users` テーブルにどのようにマップするかを伝える独立したクラスです。`AbstractMapper` を継承し、4つのメソッドを実装します。

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

`AbstractMapper` を継承するマッパーは、`config/services.yaml` で `autoconfigure: true` が有効になっている場合（Symfony のデフォルト）、Weaver によって自動検出・登録されます。

## ステップ 3 — データベーステーブルの作成

```bash
docker compose exec app bin/console weaver:schema:create
```

実行せずに SQL をプレビューするには：

```bash
docker compose exec app bin/console weaver:schema:create --dump-sql
```

## ステップ 4 — リポジトリの作成

`AbstractRepository` を継承して、エンティティ用の型安全で再利用可能なクエリ API を取得します。

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

`AbstractRepository` は `findById()`、`findAll()`、`save()`、`delete()`、`query()` を標準で提供します。

## ステップ 5 — EntityWorkspace を使った永続化

コントローラー、コマンド、またはサービスで `UserRepository` を注入します（直接の作業単位制御には `EntityWorkspace` もオプションとして注入可能）。

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

    // --- 作成 ---
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

    // --- 読み取り ---
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

        // エンティティはイミュータブル — 新しいバージョンを作成して保存する
        $updated = $user->withEmail($request->toArray()['email']);
        $this->users->save($updated);

        return $this->json(['email' => $updated->email]);
    }

    // --- 削除 ---
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

## 内部で何が起きているか

`$this->users->save($user)` を呼び出すと：

1. Weaver はマッパーの `dehydrate($user)` を呼び出して、カラム → 値のペアの配列を取得します。
2. `id` が `null` の場合、`INSERT` を発行し、生成された ID をエンティティに書き戻します。
3. `id` が設定されている場合、`UPDATE ... WHERE id = ?` を発行します。
4. 変更追跡なし、ダーティチェック diff なし、プロキシクラスなし。セマンティクスは明示的です。

## 次のステップ

- [エンティティマッピング](/entity-mapping) — すべてのカラム型、プライマリキーオプション、マッパー設定
- [リレーション](/relations) — HasOne、HasMany、BelongsTo、BelongsToMany など
- [Symfony 設定](/configuration) — リードレプリカとデバッグオプションを含む完全な `weaver.yaml` リファレンス
