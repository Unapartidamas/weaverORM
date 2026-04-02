---
id: lifecycle
title: ライフサイクルフック
---

Weaver ORM provides a set of PHP 8 attributes that let you attach callbacks directly to entity methods. These callbacks are invoked at specific points in the entity's persistence lifecycle — before or after insert, update, delete, and load operations.

---

## Lifecycle attributes

Attach these attributes to **instance methods** of your entity class. The method receives no arguments unless stated otherwise.

### `#[BeforeAdd]` — before INSERT

Called immediately before the entity is inserted into the database (replaces Doctrine's `#[PrePersist]`).

```php
<?php

namespace App\Entity;

use Weaver\ORM\Lifecycle\BeforeAdd;

class User
{
    public string $passwordHash = '';
    private string $plainPassword = '';

    #[BeforeAdd]
    public function hashPassword(): void
    {
        if ($this->plainPassword !== '') {
            $this->passwordHash = password_hash($this->plainPassword, PASSWORD_BCRYPT);
            $this->plainPassword = '';
        }
    }
}
```

### `#[AfterAdd]` — after INSERT

Called after the INSERT statement has been executed and the generated primary key has been assigned (replaces Doctrine's `#[PostPersist]`).

```php
<?php

use Weaver\ORM\Lifecycle\AfterAdd;

class Order
{
    public ?int $id = null;

    #[AfterAdd]
    public function generateConfirmationNumber(): void
    {
        // $this->id is now available
        $this->confirmationNumber = sprintf('ORD-%08d', $this->id);
    }
}
```

### `#[BeforeUpdate]` — before UPDATE

Called before the UPDATE statement for a dirty entity.

```php
<?php

use Weaver\ORM\Lifecycle\BeforeUpdate;

class Article
{
    public ?\DateTimeImmutable $updatedAt = null;

    #[BeforeUpdate]
    public function touchTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

### `#[AfterUpdate]` — after UPDATE

Called after the UPDATE statement completes.

```php
<?php

use Weaver\ORM\Lifecycle\AfterUpdate;

class Product
{
    #[AfterUpdate]
    public function clearPricingCache(): void
    {
        // Invalidate any cached pricing computed from this entity
    }
}
```

### `#[BeforeDelete]` — before DELETE

Called before the DELETE statement (or before `deleted_at` is set on soft-delete entities).

```php
<?php

use Weaver\ORM\Lifecycle\BeforeDelete;

class Post
{
    #[BeforeDelete]
    public function archiveComments(): void
    {
        foreach ($this->comments as $comment) {
            $comment->archiveReason = 'parent_deleted';
        }
    }
}
```

### `#[AfterDelete]` — after DELETE

Called after the DELETE statement (or after the soft-delete timestamp is written).

```php
<?php

use Weaver\ORM\Lifecycle\AfterDelete;

class UserAccount
{
    #[AfterDelete]
    public function anonymisePersonalData(): void
    {
        $this->email = 'deleted@example.com';
        $this->name  = 'Deleted User';
    }
}
```

### `#[AfterLoad]` — after loading from the database

Called after an entity has been hydrated from a database row. Use this to reconstruct computed properties or decrypt stored values.

```php
<?php

use Weaver\ORM\Lifecycle\AfterLoad;

class PaymentMethod
{
    public string $encryptedCardNumber = '';
    public string $maskedCardNumber    = '';

    #[AfterLoad]
    public function buildMaskedNumber(): void
    {
        $last4 = substr($this->encryptedCardNumber, -4);
        $this->maskedCardNumber = '**** **** **** ' . $last4;
    }
}
```

---

## Multiple hooks on one entity

An entity can declare multiple methods with the same lifecycle attribute:

```php
<?php

use Weaver\ORM\Lifecycle\BeforeAdd;

class Invoice
{
    #[BeforeAdd]
    public function generateInvoiceNumber(): void
    {
        $this->number = InvoiceNumberGenerator::next();
    }

    #[BeforeAdd]
    public function validateLineItems(): void
    {
        if ($this->items->isEmpty()) {
            throw new \DomainException('Invoice must have at least one line item.');
        }
    }
}
```

Methods are called in the order they are declared.

---

## Lifecycle events

In addition to attribute-based hooks on entity classes, Weaver ORM dispatches Symfony events through the `EventDispatcher`. Listen to these events to react to persistence operations from external services.

Available constants on `Weaver\ORM\Event\LifecycleEvents`:

| Constant | Dispatched when |
|---|---|
| `LifecycleEvents::BEFORE_ADD` | Before an entity is inserted |
| `LifecycleEvents::AFTER_ADD` | After an entity is inserted |
| `LifecycleEvents::BEFORE_UPDATE` | Before a dirty entity is updated |
| `LifecycleEvents::AFTER_UPDATE` | After a dirty entity is updated |
| `LifecycleEvents::BEFORE_DELETE` | Before an entity is deleted |
| `LifecycleEvents::AFTER_DELETE` | After an entity is deleted |
| `LifecycleEvents::AFTER_LOAD` | After an entity is hydrated from the DB |

---

## Event subscribers

Subscribe to lifecycle events from a Symfony service:

```php
<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Weaver\ORM\Event\LifecycleEvents;
use Weaver\ORM\Event\LifecycleEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class UserLifecycleSubscriber
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly Mailer $mailer,
    ) {}

    #[AsEventListener(event: LifecycleEvents::AFTER_ADD)]
    public function onAfterAdd(LifecycleEvent $event): void
    {
        $entity = $event->getEntity();

        if (!$entity instanceof User) {
            return;
        }

        $this->mailer->sendWelcomeEmail($entity);
        $this->auditLogger->log('user.created', $entity->getId());
    }

    #[AsEventListener(event: LifecycleEvents::BEFORE_UPDATE)]
    public function onBeforeUpdate(LifecycleEvent $event): void
    {
        $entity = $event->getEntity();

        if (!$entity instanceof User) {
            return;
        }

        // Access the changeset for the update
        foreach ($event->getChangeset() as $field => [$old, $new]) {
            $this->auditLogger->recordChange('user', $entity->getId(), $field, $old, $new);
        }
    }
}
```

The `LifecycleEvent` object exposes:

- `getEntity(): object` — the entity being persisted
- `getChangeset(): array` — `[field => [old, new]]` pairs (available on `BEFORE_UPDATE` and `AFTER_UPDATE`)

---

## Doctrine bridge attributes

If you are migrating from Doctrine, the original Doctrine lifecycle attributes continue to work through Weaver's compatibility bridge. They are mapped internally to the Weaver equivalents:

| Doctrine attribute | Weaver equivalent |
|---|---|
| `#[PrePersist]` | `#[BeforeAdd]` |
| `#[PostPersist]` | `#[AfterAdd]` |
| `#[PreUpdate]` | `#[BeforeUpdate]` |
| `#[PostUpdate]` | `#[AfterUpdate]` |
| `#[PreRemove]` | `#[BeforeDelete]` |
| `#[PostRemove]` | `#[AfterDelete]` |
| `#[PostLoad]` | `#[AfterLoad]` |

No code changes are required to keep existing Doctrine lifecycle methods working. However, new code should use the Weaver attributes for clarity.

See the [Migrating from Doctrine](doctrine-bridge) page for a full migration guide.
