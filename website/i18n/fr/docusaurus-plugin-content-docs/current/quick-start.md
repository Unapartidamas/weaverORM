---
id: quick-start
title: Démarrage rapide
---

Ce guide présente un exemple complet et fonctionnel : définir une entité `User`, écrire son mapper et effectuer des opérations de persistance de base avec `EntityWorkspace`.

## Étape 1 — Définir l'entité

Une entité est une classe PHP simple. Elle n'importe rien de `Weaver\ORM`.

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

Remarque : pas de `use Doctrine\...`, pas d'attributs `#[ORM\...]`, pas de classe de base. L'entité est un pur objet valeur PHP qui peut être testé unitairement sans démarrer Symfony ni se connecter à une base de données.

## Étape 2 — Écrire le mapper

Le mapper est une classe séparée qui indique à Weaver comment `User` se mappe sur la table `users`. Il étend `AbstractMapper` et implémente quatre méthodes.

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

Les mappers qui étendent `AbstractMapper` sont auto-détectés et enregistrés par Weaver lorsque `autoconfigure: true` est activé dans `config/services.yaml` (la valeur par défaut de Symfony).

## Étape 3 — Créer la table de base de données

```bash
docker compose exec app bin/console weaver:schema:create
```

Pour prévisualiser le SQL sans l'exécuter :

```bash
docker compose exec app bin/console weaver:schema:create --dump-sql
```

## Étape 4 — Écrire un repository

Étendez `AbstractRepository` pour obtenir une API de requête typée et réutilisable pour votre entité.

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

`AbstractRepository` fournit `findById()`, `findAll()`, `save()`, `delete()` et `query()` prêts à l'emploi.

## Étape 5 — Utiliser EntityWorkspace pour la persistance

Dans un contrôleur, une commande ou un service, injectez `UserRepository` (et optionnellement `EntityWorkspace` pour un contrôle direct de l'unité de travail).

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

    // --- CRÉATION ---
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

    // --- LECTURE ---
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

    // --- MISE À JOUR ---
    #[Route('/{id}/email', methods: ['PATCH'])]
    public function updateEmail(int $id, Request $request): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        // Les entités sont immuables — créez une nouvelle version et sauvegardez-la
        $updated = $user->withEmail($request->toArray()['email']);
        $this->users->save($updated);

        return $this->json(['email' => $updated->email]);
    }

    // --- SUPPRESSION ---
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

## Ce qui se passe sous le capot

Lorsque vous appelez `$this->users->save($user)` :

1. Weaver appelle `dehydrate($user)` sur votre mapper pour obtenir un tableau de paires colonne → valeur.
2. Si `id` est `null`, il émet un `INSERT` et réécrit l'ID généré sur l'entité.
3. Si `id` est défini, il émet un `UPDATE ... WHERE id = ?`.
4. Pas de suivi des changements, pas de diff de vérification de saleté, pas de classes proxy. La sémantique est explicite.

## Prochaines étapes

- [Mapping d'entités](/entity-mapping) — tous les types de colonnes, les options de clé primaire et la configuration du mapper
- [Relations](/relations) — HasOne, HasMany, BelongsTo, BelongsToMany, et plus
- [Configuration Symfony](/configuration) — référence complète `weaver.yaml` avec réplicas de lecture et options de débogage
