---
id: transactions
title: Transacciones
---

Weaver ORM provides `TransactionManager`, a dedicated service for managing database transactions. It supports simple atomic blocks, manual begin/commit/rollback, nested transactions via savepoints, and optimistic locking.

---

## TransactionManager

Inject `Weaver\ORM\Transaction\TransactionManager` wherever you need transaction control:

```php
<?php

use Weaver\ORM\Transaction\TransactionManager;

class OrderService
{
    public function __construct(
        private readonly TransactionManager $txManager,
        private readonly EntityWorkspace     $workspace,
    ) {}
}
```

---

## `run(callable $callback)` — atomic block

The simplest and recommended way to wrap work in a transaction. Pass a callable; `run()` begins a transaction, executes the callable, and commits. If the callable throws, the transaction is rolled back automatically and the exception is re-thrown.

```php
<?php

$this->txManager->run(function (): void {
    $order = new Order(customerId: $customerId);
    $this->workspace->add($order);

    foreach ($lineItems as $item) {
        $this->workspace->add($item);
    }

    $this->workspace->push(); // INSERTs run inside the transaction
});
// auto-committed here, or rolled back if an exception was thrown
```

The return value of the callable is forwarded as the return value of `run()`:

```php
<?php

$orderId = $this->txManager->run(function () use ($data): int {
    $order = Order::fromArray($data);
    $this->workspace->add($order);
    $this->workspace->push();

    return $order->getId();
});
```

---

## Manual begin / commit / rollback

For cases where you need fine-grained control over the transaction lifecycle:

### `begin()`

Opens a new transaction. Throws `TransactionException` if a transaction is already active.

```php
<?php

$this->txManager->begin();
```

### `commit()`

Commits the active transaction.

```php
<?php

$this->txManager->commit();
```

### `rollback()`

Rolls back the active transaction.

```php
<?php

$this->txManager->rollback();
```

### Full example

```php
<?php

$this->txManager->begin();

try {
    $account = $this->accountRepo->findOrFail($id);
    $account->debit($amount);
    $this->workspace->push();

    $this->ledgerService->record($account, $amount);

    $this->txManager->commit();
} catch (\Throwable $e) {
    $this->txManager->rollback();
    throw $e;
}
```

---

## Nested transactions

Weaver ORM supports nested transaction calls transparently through **savepoints**.

When `begin()` is called while a transaction is already active, a savepoint is created instead of opening a second transaction. `commit()` releases the savepoint; `rollback()` rolls back only to the savepoint, leaving the outer transaction intact.

```php
<?php

$this->txManager->run(function (): void {
    // Outer transaction
    $this->workspace->add($invoice);
    $this->workspace->push();

    // Inner block — creates a savepoint internally
    $this->txManager->run(function (): void {
        $this->workspace->add($auditEntry);
        $this->workspace->push();
        // savepoint released (partial commit within outer tx)
    });

    // Outer transaction still open here
    $this->workspace->add($notification);
    $this->workspace->push();
});
// Outer transaction committed
```

If the inner block throws and its rollback is caught by the caller, the outer transaction can continue:

```php
<?php

$this->txManager->run(function (): void {
    $this->workspace->add($order);
    $this->workspace->push();

    try {
        // attempt to send notification — non-critical
        $this->txManager->run(function (): void {
            $this->notificationService->send($order);
        });
    } catch (NotificationException $e) {
        // notification failed but the savepoint is rolled back,
        // the outer transaction (and the order INSERT) remains intact
        $this->logger->warning('Notification failed', ['exception' => $e]);
    }
});
```

---

## Optimistic locking

Optimistic locking detects concurrent modification of the same row without holding a database lock. Add a `#[Version]` attribute to an integer or datetime column. Weaver automatically increments the version on every UPDATE and checks it against the value the entity was loaded with.

### Setting up the version column

```php
<?php

namespace App\Entity;

use Weaver\ORM\Locking\Version;

class Product
{
    public int $id;
    public string $name;
    public int $stock;

    #[Version]
    public int $version = 1;
}
```

### Handling a conflict

When another process has incremented the version between your load and your flush, `push()` throws `Weaver\ORM\Exception\OptimisticLockException`:

```php
<?php

use Weaver\ORM\Exception\OptimisticLockException;

try {
    $product = $this->productRepo->findOrFail($id);
    $product->decrementStock($quantity);
    $this->workspace->push();
} catch (OptimisticLockException $e) {
    // Reload and retry
    $this->workspace->reload($product);

    $product->decrementStock($quantity);
    $this->workspace->push();
}
```

`OptimisticLockException` exposes:

- `getEntity()` — the entity that caused the conflict
- `getExpectedVersion()` — the version your process held
- `getActualVersion()` — the version currently in the database

### Explicit version check

You can check the version before flushing without relying on the exception:

```php
<?php

$product = $this->productRepo->findOrFail($id);
$snapshot = $product->version; // save the version at load time

// … later, after user edits …

if ($product->version !== $snapshot) {
    throw new \RuntimeException('Another user has modified this product. Please refresh and try again.');
}

$this->workspace->push();
```

---

## Deadlock handling and retry

Wrap retryable operations with the `retry()` helper on `TransactionManager`:

```php
<?php

$this->txManager->retry(
    attempts: 3,
    callback: function (): void {
        $this->txManager->run(function (): void {
            $account = $this->accountRepo->findOrFail($id);
            $account->incrementBalance($amount);
            $this->workspace->push();
        });
    },
);
```

`retry()` catches `DeadlockException` and re-runs the callback up to the specified number of times with a short exponential back-off. Any other exception is re-thrown immediately.
