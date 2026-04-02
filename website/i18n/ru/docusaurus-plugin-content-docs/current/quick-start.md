---
id: quick-start
title: Быстрый старт
---

Это руководство проведёт вас через полный рабочий пример: определение сущности `User`, написание маппера для неё и выполнение базовых операций сохранения с использованием `EntityWorkspace`.

## Шаг 1 — Определение сущности

Сущность — это обычный PHP-класс. Он ничего не импортирует из `Weaver\ORM`.

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

Обратите внимание: нет `use Doctrine\...`, нет атрибутов `#[ORM\...]`, нет базового класса. Сущность — это чистый объект-значение PHP, который можно тестировать без запуска Symfony или подключения к базе данных.

## Шаг 2 — Написание маппера

Маппер — это отдельный класс, который сообщает Weaver, как `User` отображается на таблицу `users`. Он расширяет `AbstractMapper` и реализует четыре метода.

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

Маппера, расширяющие `AbstractMapper`, автоматически обнаруживаются и регистрируются Weaver, если в `config/services.yaml` включена опция `autoconfigure: true` (по умолчанию в Symfony).

## Шаг 3 — Создание таблицы базы данных

```bash
docker compose exec app bin/console weaver:schema:create
```

Чтобы просмотреть SQL без выполнения:

```bash
docker compose exec app bin/console weaver:schema:create --dump-sql
```

## Шаг 4 — Написание репозитория

Расширьте `AbstractRepository`, чтобы получить типизированный, переиспользуемый API запросов для вашей сущности.

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

`AbstractRepository` предоставляет из коробки методы `findById()`, `findAll()`, `save()`, `delete()` и `query()`.

## Шаг 5 — Использование EntityWorkspace для сохранения

В контроллере, команде или сервисе внедрите `UserRepository` (и при необходимости `EntityWorkspace` для прямого управления единицей работы).

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

    // --- СОЗДАНИЕ ---
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

    // --- ЧТЕНИЕ ---
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

    // --- ОБНОВЛЕНИЕ ---
    #[Route('/{id}/email', methods: ['PATCH'])]
    public function updateEmail(int $id, Request $request): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        // Сущности неизменяемы — создаём новую версию и сохраняем её
        $updated = $user->withEmail($request->toArray()['email']);
        $this->users->save($updated);

        return $this->json(['email' => $updated->email]);
    }

    // --- УДАЛЕНИЕ ---
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

## Что происходит внутри

Когда вы вызываете `$this->users->save($user)`:

1. Weaver вызывает `dehydrate($user)` на вашем маппере, чтобы получить массив пар колонка → значение.
2. Если `id` равен `null`, выполняется `INSERT` и сгенерированный ID записывается обратно в сущность.
3. Если `id` задан, выполняется `UPDATE ... WHERE id = ?`.
4. Никакого отслеживания изменений, никакого сравнения грязных полей, никаких прокси-классов. Семантика явная.

## Следующие шаги

- [Маппинг сущностей (Entity Mapping)](/entity-mapping) — все типы колонок, варианты первичных ключей и конфигурация маппера
- [Связи (Relations)](/relations) — HasOne, HasMany, BelongsTo, BelongsToMany и другие
- [Конфигурация Symfony](/configuration) — полный справочник `weaver.yaml` с репликами чтения и параметрами отладки
