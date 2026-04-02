---
id: quick-start
title: Quick Start
---

This guide walks through a complete working example: defining a `User` entity, writing its mapper, and performing basic persistence operations using `EntityWorkspace`.

## Step 1 — Define the entity

An entity is a plain PHP class. It imports nothing from `Weaver\ORM`.

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

Notice: no `use Doctrine\...`, no `#[ORM\...]` attributes, no base class. The entity is a pure PHP value object that can be unit-tested without booting Symfony or connecting to a database.

## Step 2 — Write the mapper

The mapper is a separate class that tells Weaver how `User` maps to the `users` table. It extends `AbstractMapper` and implements four methods.

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

Mappers that extend `AbstractMapper` are auto-detected and registered by Weaver when `autoconfigure: true` is enabled in `config/services.yaml` (the Symfony default).

## Step 3 — Create the database table

```bash
docker compose exec app bin/console weaver:schema:create
```

To preview the SQL without executing it:

```bash
docker compose exec app bin/console weaver:schema:create --dump-sql
```

## Step 4 — Write a repository

Extend `AbstractRepository` to get a typed, reusable query API for your entity.

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

`AbstractRepository` provides `findById()`, `findAll()`, `save()`, `delete()`, and `query()` out of the box.

## Step 5 — Use EntityWorkspace for persistence

In a controller, command, or service, inject `UserRepository` (and optionally `EntityWorkspace` for direct unit-of-work control).

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

    // --- CREATE ---
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

    // --- READ ---
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

    // --- UPDATE ---
    #[Route('/{id}/email', methods: ['PATCH'])]
    public function updateEmail(int $id, Request $request): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        // Entities are immutable — create a new version and save it
        $updated = $user->withEmail($request->toArray()['email']);
        $this->users->save($updated);

        return $this->json(['email' => $updated->email]);
    }

    // --- DELETE ---
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

## What happens under the hood

When you call `$this->users->save($user)`:

1. Weaver calls `dehydrate($user)` on your mapper to get an array of column → value pairs.
2. If `id` is `null`, it issues an `INSERT` and writes the generated ID back onto the entity.
3. If `id` is set, it issues an `UPDATE ... WHERE id = ?`.
4. No change-tracking, no dirty-check diff, no proxy classes. The semantics are explicit.

## Next steps

- [Entity Mapping](/entity-mapping) — all column types, primary key options, and mapper configuration
- [Relations](/relations) — HasOne, HasMany, BelongsTo, BelongsToMany, and more
- [Symfony Configuration](/configuration) — full `weaver.yaml` reference with read replicas and debug options
