---
id: workspace
title: EntityWorkspace
---

`EntityWorkspace` is Weaver ORM's central unit-of-work service. It tracks entities across a single request, batches all writes, and flushes them to the database in a single coordinated operation. Think of it as the improved successor to Doctrine's `EntityManager`, redesigned for clarity, worker safety, and explicit intent.

## Key differences from Doctrine EntityManager

| Doctrine EntityManager | Weaver EntityWorkspace |
|---|---|
| `persist($entity)` | `add($entity)` |
| `flush()` | `push()` |
| `remove($entity)` | `delete($entity)` |
| `refresh($entity)` | `reload($entity)` |
| `detach($entity)` | `untrack($entity)` |
| `contains($entity)` | `isTracked($entity)` |
| `clear()` | `reset()` |

One `EntityWorkspace` instance exists per HTTP request. It holds no shared state between requests and implements `ResetInterface` so long-running workers (RoadRunner, FrankenPHP) can call `reset()` between requests instead of rebuilding the container.

---

## Tracking entities

### `add($entity)` — schedule an entity for insertion

Marks a new entity for INSERT on the next `push()`. No SQL is executed immediately.

```php
<?php

use App\Entity\User;
use Weaver\ORM\EntityWorkspace;

final class RegisterUserHandler
{
    public function __construct(
        private readonly EntityWorkspace $workspace,
    ) {}

    public function handle(RegisterUserCommand $command): void
    {
        $user = new User(
            email: $command->email,
            name:  $command->name,
        );

        $this->workspace->add($user);
        $this->workspace->push(); // INSERT executed here
    }
}
```

Calling `add()` on an already-tracked entity is a no-op — it is safe to call multiple times.

### `push()` — flush all pending changes

Executes all queued INSERTs, UPDATEs, and DELETEs in a single database round-trip (wrapped in an implicit transaction). After `push()` completes, every tracked entity is in the MANAGED state with an up-to-date identity.

```php
<?php

$order = new Order(customerId: $customerId, total: $total);
$this->workspace->add($order);

foreach ($lineItems as $item) {
    $this->workspace->add($item);
}

$this->workspace->push(); // all INSERTs committed atomically
```

Insert ordering is resolved automatically via topological sort on foreign-key relationships — child entities are always inserted after their parents, regardless of the order in which they were `add()`-ed.

### `delete($entity)` — schedule an entity for deletion

Marks a tracked entity for DELETE on the next `push()`. The entity stays in memory until `push()` is called.

```php
<?php

$post = $this->postRepository->findOrFail($id);

$this->workspace->delete($post);
$this->workspace->push(); // DELETE executed here
```

If the entity's mapper declares `cascade: ['delete']` on a relationship, associated children are automatically scheduled for deletion as well.

---

## Refreshing and detaching

### `reload($entity)` — refresh from the database

Discards any in-memory changes and re-populates the entity from its current row in the database. Useful after an optimistic lock conflict or when another process may have updated the record.

```php
<?php

try {
    $this->workspace->push();
} catch (OptimisticLockException $e) {
    $this->workspace->reload($product); // reload the latest version
    // re-apply changes and retry
}
```

### `untrack($entity)` — detach from the workspace

Removes the entity from the identity map and change-tracking. After calling `untrack()`, modifications to the entity are invisible to the workspace and will not be flushed.

```php
<?php

// Detach a read-only projection to avoid accidental writes
$report = $this->reportRepository->buildSummary();
$this->workspace->untrack($report);
```

---

## Inspecting entity state

### `isTracked($entity)` — check if the workspace knows about this entity

Returns `true` if the entity is currently in the identity map (NEW, MANAGED, or REMOVED state).

```php
<?php

if (!$this->workspace->isTracked($entity)) {
    $this->workspace->add($entity);
}
```

### `isNew($entity)` — check if the entity has never been persisted

Returns `true` when the entity was `add()`-ed but `push()` has not yet been called.

```php
<?php

if ($this->workspace->isNew($user)) {
    $this->mailer->sendWelcomeEmail($user);
}
```

### `isDirty($entity)` — check if the entity has unsaved changes

Returns `true` when one or more properties have changed since the entity was last loaded or flushed.

```php
<?php

if ($this->workspace->isDirty($product)) {
    $this->auditLogger->log('product.modified', $product->getId());
}

$this->workspace->push();
```

### `isDeleted($entity)` — check if the entity is scheduled for deletion

Returns `true` after `delete()` has been called but before `push()` executes the DELETE.

```php
<?php

if ($this->workspace->isDeleted($entity)) {
    throw new \LogicException('Entity is already scheduled for deletion.');
}
```

### `getChanges($entity)` — inspect dirty fields

Returns an associative array of `[fieldName => [original, current]]` pairs for every property that has changed since the last snapshot. Returns an empty array if the entity is clean.

```php
<?php

$changes = $this->workspace->getChanges($user);
// [
//   'email' => ['old@example.com', 'new@example.com'],
//   'name'  => ['Alice', 'Alice Smith'],
// ]

foreach ($changes as $field => [$old, $new]) {
    $this->auditLog->record($user, $field, $old, $new);
}
```

---

## Resetting workspace state

### `reset()` — clear all tracked entities

Evicts every entity from the identity map and clears all pending queues. Called automatically by the worker kernel between requests when using RoadRunner or FrankenPHP.

```php
<?php

// Manually clear state after a batch import
foreach ($chunks as $chunk) {
    foreach ($chunk as $row) {
        $entity = $this->hydrate($row);
        $this->workspace->add($entity);
    }
    $this->workspace->push();
    $this->workspace->reset(); // free memory before the next chunk
}
```

---

## Transaction support

`EntityWorkspace` integrates with `TransactionManager` to wrap `push()` calls in explicit transactions. See the [Transactions](transactions) page for full details.

```php
<?php

use Weaver\ORM\Transaction\TransactionManager;

final class TransferFundsHandler
{
    public function __construct(
        private readonly EntityWorkspace    $workspace,
        private readonly TransactionManager $txManager,
    ) {}

    public function handle(TransferCommand $cmd): void
    {
        $this->txManager->run(function () use ($cmd): void {
            $source      = $this->accountRepo->findOrFail($cmd->sourceId);
            $destination = $this->accountRepo->findOrFail($cmd->destinationId);

            $source->debit($cmd->amount);
            $destination->credit($cmd->amount);

            $this->workspace->push(); // both UPDATEs within the same transaction
        });
    }
}
```

`push()` reuses any active transaction. If no transaction is open, it opens one implicitly and commits after all SQL is written.

---

## Worker safety

`EntityWorkspace` implements `ResetInterface`. In long-running PHP workers you must call `reset()` (or let the kernel do so) at the start of each request to prevent state leaking between requests.

```php
<?php

// In a RoadRunner worker loop:
while ($request = $worker->waitRequest()) {
    $this->workspace->reset(); // clean slate for each request
    $this->kernel->handle($request);
}
```
