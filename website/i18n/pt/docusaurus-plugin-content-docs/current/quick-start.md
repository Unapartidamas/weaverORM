---
id: quick-start
title: Início Rápido
---

Este guia percorre um exemplo completo funcional: definindo uma entidade `User`, escrevendo seu mapper e realizando operações básicas de persistência usando o `EntityWorkspace`.

## Passo 1 — Definir a entidade

Uma entidade é uma classe PHP simples. Ela não importa nada do `Weaver\ORM`.

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

Observe: sem `use Doctrine\...`, sem atributos `#[ORM\...]`, sem classe base. A entidade é um objeto de valor PHP puro que pode ser testado unitariamente sem inicializar o Symfony ou conectar a um banco de dados.

## Passo 2 — Escrever o mapper

O mapper é uma classe separada que informa ao Weaver como o `User` mapeia para a tabela `users`. Ele estende `AbstractMapper` e implementa quatro métodos.

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

Mappers que estendem `AbstractMapper` são detectados automaticamente e registrados pelo Weaver quando `autoconfigure: true` está habilitado em `config/services.yaml` (padrão do Symfony).

## Passo 3 — Criar a tabela no banco de dados

```bash
docker compose exec app bin/console weaver:schema:create
```

Para visualizar o SQL sem executá-lo:

```bash
docker compose exec app bin/console weaver:schema:create --dump-sql
```

## Passo 4 — Escrever um repositório

Estenda `AbstractRepository` para obter uma API de consulta tipada e reutilizável para sua entidade.

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

O `AbstractRepository` fornece `findById()`, `findAll()`, `save()`, `delete()` e `query()` prontos para uso.

## Passo 5 — Usar o EntityWorkspace para persistência

Em um controller, comando ou serviço, injete o `UserRepository` (e opcionalmente o `EntityWorkspace` para controle direto da unidade de trabalho).

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

    // --- CRIAR ---
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

    // --- LER ---
    #[Route('/{id}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Não encontrado'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'is_active'  => $user->isActive,
            'created_at' => $user->createdAt->format('c'),
        ]);
    }

    // --- ATUALIZAR ---
    #[Route('/{id}/email', methods: ['PATCH'])]
    public function updateEmail(int $id, Request $request): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Não encontrado'], Response::HTTP_NOT_FOUND);
        }

        // Entidades são imutáveis — crie uma nova versão e salve-a
        $updated = $user->withEmail($request->toArray()['email']);
        $this->users->save($updated);

        return $this->json(['email' => $updated->email]);
    }

    // --- EXCLUIR ---
    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Não encontrado'], Response::HTTP_NOT_FOUND);
        }

        $this->users->delete($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
```

## O que acontece internamente

Quando você chama `$this->users->save($user)`:

1. O Weaver chama `dehydrate($user)` no seu mapper para obter um array de pares coluna → valor.
2. Se `id` for `null`, emite um `INSERT` e escreve o ID gerado de volta na entidade.
3. Se `id` estiver definido, emite um `UPDATE ... WHERE id = ?`.
4. Sem rastreamento de mudanças, sem diff de sujeira, sem classes proxy. A semântica é explícita.

## Próximos passos

- [Mapeamento de Entidades](/entity-mapping) — todos os tipos de coluna, opções de chave primária e configuração de mapper
- [Relações](/relations) — HasOne, HasMany, BelongsTo, BelongsToMany e mais
- [Configuração do Symfony](/configuration) — referência completa do `weaver.yaml` com réplicas de leitura e opções de debug
