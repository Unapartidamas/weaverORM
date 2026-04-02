---
id: quick-start
title: Schnellstart
---

Dieser Leitfaden führt Sie durch ein vollständiges, funktionierendes Beispiel: die Definition einer `User`-Entity, das Schreiben des zugehörigen Mappers und die Durchführung grundlegender Persistenzoperationen mit `EntityWorkspace`.

## Schritt 1 — Die Entity definieren

Eine Entity ist eine einfache PHP-Klasse. Sie importiert nichts aus `Weaver\ORM`.

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

Zu beachten: kein `use Doctrine\...`, keine `#[ORM\...]`-Attribute, keine Basisklasse. Die Entity ist ein reines PHP Value Object, das ohne Symfony-Boot und ohne Datenbankverbindung unit-getestet werden kann.

## Schritt 2 — Den Mapper schreiben

Der Mapper ist eine separate Klasse, die Weaver mitteilt, wie `User` auf die `users`-Tabelle abgebildet wird. Er erweitert `AbstractMapper` und implementiert vier Methoden.

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

Mapper, die `AbstractMapper` erweitern, werden von Weaver automatisch erkannt und registriert, wenn `autoconfigure: true` in `config/services.yaml` aktiviert ist (Symfony-Standard).

## Schritt 3 — Die Datenbanktabelle erstellen

```bash
docker compose exec app bin/console weaver:schema:create
```

Um eine Vorschau des SQL ohne Ausführung zu erhalten:

```bash
docker compose exec app bin/console weaver:schema:create --dump-sql
```

## Schritt 4 — Ein Repository schreiben

Erweitern Sie `AbstractRepository`, um eine typisierte, wiederverwendbare Abfrage-API für Ihre Entity zu erhalten.

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

`AbstractRepository` stellt `findById()`, `findAll()`, `save()`, `delete()` und `query()` sofort bereit.

## Schritt 5 — EntityWorkspace für Persistenz verwenden

Injizieren Sie in einem Controller, Command oder Service `UserRepository` (und optional `EntityWorkspace` für direkte Unit-of-Work-Kontrolle).

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

    // --- ERSTELLEN ---
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

    // --- LESEN ---
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

    // --- AKTUALISIEREN ---
    #[Route('/{id}/email', methods: ['PATCH'])]
    public function updateEmail(int $id, Request $request): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        // Entities sind unveränderlich — neue Version erstellen und speichern
        $updated = $user->withEmail($request->toArray()['email']);
        $this->users->save($updated);

        return $this->json(['email' => $updated->email]);
    }

    // --- LÖSCHEN ---
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

## Was im Hintergrund passiert

Wenn Sie `$this->users->save($user)` aufrufen:

1. Weaver ruft `dehydrate($user)` auf Ihrem Mapper auf, um ein Array von Spalte → Wert-Paaren zu erhalten.
2. Wenn `id` `null` ist, wird ein `INSERT` ausgeführt und die generierte ID auf die Entity zurückgeschrieben.
3. Wenn `id` gesetzt ist, wird `UPDATE ... WHERE id = ?` ausgeführt.
4. Kein Change-Tracking, kein Dirty-Check-Diff, keine Proxy-Klassen. Die Semantik ist explizit.

## Nächste Schritte

- [Entity-Mapping](/entity-mapping) — alle Spaltentypen, Primärschlüsseloptionen und Mapper-Konfiguration
- [Beziehungen](/relations) — HasOne, HasMany, BelongsTo, BelongsToMany und mehr
- [Symfony-Konfiguration](/configuration) — vollständige `weaver.yaml`-Referenz mit Read-Replicas und Debug-Optionen
