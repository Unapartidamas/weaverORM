---
id: doctrine-bridge
title: Migrating from Doctrine
---

Weaver ORM ships a compatibility bridge that lets you migrate an existing Doctrine ORM codebase incrementally. The bridge exposes the familiar Doctrine `EntityManager` API as a thin, deprecated wrapper over the Weaver internals so that existing code continues to compile and run while you migrate file by file.

---

## DoctrineCompatEntityManager

`Weaver\ORM\Bridge\Doctrine\DoctrineCompatEntityManager` wraps `EntityWorkspace` and forwards all Doctrine `EntityManager` calls to their Weaver equivalents. Every method on this wrapper is tagged `@deprecated` to guide migration.

```php
<?php

use Weaver\ORM\Bridge\Doctrine\DoctrineCompatEntityManager;

// Register as the 'doctrine.orm.entity_manager' alias in Symfony:
// config/services.yaml
//   Doctrine\ORM\EntityManagerInterface: '@Weaver\ORM\Bridge\Doctrine\DoctrineCompatEntityManager'

class LegacyOrderService
{
    public function __construct(
        private readonly \Doctrine\ORM\EntityManagerInterface $em, // still works
    ) {}

    public function createOrder(array $data): Order
    {
        $order = new Order($data);
        $this->em->persist($order);  // calls workspace->add() internally
        $this->em->flush();          // calls workspace->push() internally
        return $order;
    }
}
```

---

## Method mapping

| Doctrine method | Weaver equivalent | Notes |
|---|---|---|
| `persist($entity)` | `add($entity)` | Marks entity for INSERT |
| `flush()` | `push()` | Executes all pending SQL |
| `remove($entity)` | `delete($entity)` | Marks entity for DELETE |
| `refresh($entity)` | `reload($entity)` | Reloads from database |
| `detach($entity)` | `untrack($entity)` | Removes from tracking |
| `contains($entity)` | `isTracked($entity)` | Returns bool |
| `clear()` | `reset()` | Clears identity map |
| `find($class, $id)` | `$repo->find($id)` | Use repository directly |
| `getRepository($class)` | `$repo` (DI) | Inject repository instead |
| `beginTransaction()` | `$txManager->begin()` | Via TransactionManager |
| `commit()` | `$txManager->commit()` | Via TransactionManager |
| `rollback()` | `$txManager->rollback()` | Via TransactionManager |
| `wrapInTransaction(fn)` | `$txManager->run(fn)` | Recommended approach |

---

## Lifecycle attribute mapping

Doctrine lifecycle attributes are remapped transparently through the bridge. Your existing entity classes do not need to change.

| Doctrine attribute | Weaver attribute | Event timing |
|---|---|---|
| `#[PrePersist]` | `#[BeforeAdd]` | Before INSERT |
| `#[PostPersist]` | `#[AfterAdd]` | After INSERT |
| `#[PreUpdate]` | `#[BeforeUpdate]` | Before UPDATE |
| `#[PostUpdate]` | `#[AfterUpdate]` | After UPDATE |
| `#[PreRemove]` | `#[BeforeDelete]` | Before DELETE |
| `#[PostRemove]` | `#[AfterDelete]` | After DELETE |
| `#[PostLoad]` | `#[AfterLoad]` | After hydration |

---

## Step-by-step migration guide

### Step 1 — Install Weaver ORM alongside Doctrine

Weaver ORM and Doctrine can coexist in the same project during migration. Install Weaver and register the Symfony bundle without removing Doctrine.

```bash
composer require weaver/orm
```

```php
// config/bundles.php
return [
    // ...
    Weaver\ORM\Symfony\WeaverBundle::class => ['all' => true],
];
```

### Step 2 — Register the compatibility alias

Point the `Doctrine\ORM\EntityManagerInterface` service alias at the Weaver compatibility wrapper so all existing code continues to receive a working `EntityManager`.

```yaml
# config/services.yaml
services:
    Doctrine\ORM\EntityManagerInterface:
        alias: Weaver\ORM\Bridge\Doctrine\DoctrineCompatEntityManager
        public: true
```

Run your test suite. All existing tests should still pass.

### Step 3 — Migrate mappers

Weaver ORM uses its own mapper classes rather than Doctrine XML/YAML/attribute mappings on the entity class. Create a mapper for each entity. Entity classes themselves do not need to change at this stage.

```php
<?php

namespace App\Mapping;

use Weaver\ORM\Mapping\AbstractMapper;
use Weaver\ORM\Mapping\Attributes\Column;
use Weaver\ORM\Mapping\Attributes\Table;
use App\Entity\User;

#[Table(name: 'users')]
class UserMapper extends AbstractMapper
{
    public string $entity = User::class;

    #[Column(type: 'bigint', autoIncrement: true, primary: true)]
    public int $id;

    #[Column(type: 'string', length: 180)]
    public string $email;
    // ...
}
```

### Step 4 — Migrate repositories

Replace `Doctrine\ORM\EntityRepository` with `Weaver\ORM\Repository\EntityRepository`. The method signatures for `find()`, `findBy()`, `findAll()` are compatible, but `findOneBy()` should be replaced with `findBy()` + `->first()` or a custom query method.

```php
<?php

// Before (Doctrine):
use Doctrine\ORM\EntityRepository;

class UserRepository extends EntityRepository
{
    public function findActiveAdmins(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.role = :role')
            ->setParameter('role', 'admin')
            ->andWhere('u.isActive = true')
            ->getQuery()
            ->getResult();
    }
}

// After (Weaver):
use Weaver\ORM\Repository\EntityRepository;

class UserRepository extends EntityRepository
{
    public function findActiveAdmins(): array
    {
        return $this->query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get()
            ->all();
    }
}
```

### Step 5 — Update call sites

Replace Doctrine method calls with their Weaver equivalents in services, controllers, and command handlers.

```php
<?php

// Before:
$this->em->persist($user);
$this->em->flush();

// After:
$this->workspace->add($user);
$this->workspace->push();
```

```php
<?php

// Before:
$this->em->remove($user);
$this->em->flush();

// After:
$this->workspace->delete($user);
$this->workspace->push();
```

```php
<?php

// Before:
$this->em->wrapInTransaction(function () {
    // ...
});

// After:
$this->txManager->run(function (): void {
    // ...
});
```

### Step 6 — Update lifecycle attributes (optional)

Once all entity classes are migrated to Weaver mappers, update the lifecycle attributes to the Weaver equivalents. This is cosmetic — the bridge handles both — but improves clarity and removes the bridge dependency.

```php
<?php

// Before:
use Doctrine\ORM\Mapping as ORM;

#[ORM\PrePersist]
public function onPrePersist(): void { /* ... */ }

// After:
use Weaver\ORM\Lifecycle\BeforeAdd;

#[BeforeAdd]
public function onBeforeAdd(): void { /* ... */ }
```

### Step 7 — Remove Doctrine

Once all mappers, repositories, and call sites are migrated, remove the Doctrine ORM package and the compatibility alias.

```bash
composer remove doctrine/orm doctrine/doctrine-bundle
```

---

## What can be kept as-is

- **Entity classes** — plain PHP objects with no Doctrine annotations or interfaces required. They work unchanged.
- **Lifecycle methods** — Doctrine lifecycle attribute names continue to work through the bridge; migration is optional.
- **Symfony forms** — Weaver entities work with Symfony Form just like Doctrine entities.
- **API Platform** — use `Weaver\ORM\Bridge\ApiPlatform\WeaverStateProvider` and `WeaverStateProcessor` instead of the Doctrine counterparts.

## What must change

- **Repositories** — extend `Weaver\ORM\Repository\EntityRepository` instead of `Doctrine\ORM\EntityRepository`. The `createQueryBuilder('alias')` DQL pattern is replaced by `$this->query()`.
- **Mappers** — Doctrine `#[ORM\Entity]` and `#[ORM\Column]` annotations on entity classes are replaced by standalone mapper classes. Entity classes become pure data holders.
- **Direct EntityManager injection** — the `DoctrineCompatEntityManager` wrapper is intentionally deprecated; migrate all inject sites to `EntityWorkspace` + typed repositories over time.
