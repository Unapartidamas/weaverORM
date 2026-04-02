---
id: testing
title: Тестирование
---

Weaver ORM provides a dedicated testing toolkit that makes it straightforward to write fast, reliable tests for code that interacts with the database.

---

## WeaverTestCase

Extend `Weaver\ORM\Testing\WeaverTestCase` as the base class for any test that needs the ORM. It bootstraps a minimal Symfony kernel, configures the database connection, and provides helper methods for working with entities.

```php
<?php

namespace Tests\Integration;

use Weaver\ORM\Testing\WeaverTestCase;
use App\Entity\User;

class UserRepositoryTest extends WeaverTestCase
{
    public function test_find_active_users(): void
    {
        // Create test data using the factory
        $alice = $this->factory(User::class)->create(['status' => 'active']);
        $bob   = $this->factory(User::class)->create(['status' => 'inactive']);

        $result = $this->getRepository(User::class)->findBy(['status' => 'active']);

        $this->assertCount(1, $result);
        $this->assertSame($alice->getId(), $result->first()->getId());
    }
}
```

---

## EntityFactory — creating test fixtures

`Weaver\ORM\Testing\EntityFactory` generates entities with sensible defaults and persists them to the database. Define a factory for each entity by extending `EntityFactory`:

```php
<?php

namespace Tests\Factory;

use App\Entity\User;
use Weaver\ORM\Testing\EntityFactory;

/**
 * @extends EntityFactory<User>
 */
class UserFactory extends EntityFactory
{
    protected function entity(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'name'     => $this->faker->name(),
            'email'    => $this->faker->unique()->safeEmail(),
            'role'     => 'user',
            'status'   => 'active',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ];
    }
}
```

### Using the factory

```php
<?php

// Create and persist one entity with default values
$user = UserFactory::new()->create();

// Override specific fields
$admin = UserFactory::new()->create([
    'role'  => 'admin',
    'email' => 'admin@example.com',
]);

// Create multiple entities at once
$users = UserFactory::new()->createMany(5);

// Create without persisting (useful for unit tests)
$user = UserFactory::new()->make(['name' => 'Draft User']);
```

### Factory states

Define named states to group related overrides:

```php
<?php

class UserFactory extends EntityFactory
{
    // ...

    public function admin(): static
    {
        return $this->state(['role' => 'admin', 'is_staff' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}

// Usage:
$admin           = UserFactory::new()->admin()->create();
$inactiveAdmin   = UserFactory::new()->admin()->inactive()->create();
$unverifiedUsers = UserFactory::new()->unverified()->createMany(3);
```

---

## DatabaseTransactions trait

Use the `DatabaseTransactions` trait to wrap each test in a transaction that is rolled back after the test completes. This is the fastest way to keep tests isolated without truncating tables.

```php
<?php

namespace Tests\Integration;

use Weaver\ORM\Testing\WeaverTestCase;
use Weaver\ORM\Testing\DatabaseTransactions;
use Tests\Factory\OrderFactory;

class OrderRepositoryTest extends WeaverTestCase
{
    use DatabaseTransactions; // transaction opened before each test, rolled back after

    public function test_find_pending_orders(): void
    {
        OrderFactory::new()->create(['status' => 'pending']);
        OrderFactory::new()->create(['status' => 'shipped']);

        $pending = $this->getRepository(\App\Entity\Order::class)
            ->findBy(['status' => 'pending']);

        $this->assertCount(1, $pending);
    }
    // Database is fully clean after this test — no cleanup code needed
}
```

The trait hooks into `setUp` and `tearDown` to begin and roll back the transaction automatically. All changes made during the test — including any `push()` calls — are discarded at the end.

---

## RefreshDatabase trait

`RefreshDatabase` runs the full migration before the first test in the class and truncates all tables between tests. Use this when `DatabaseTransactions` is not suitable (e.g., when testing code that uses its own transactions).

```php
<?php

namespace Tests\Integration;

use Weaver\ORM\Testing\WeaverTestCase;
use Weaver\ORM\Testing\RefreshDatabase;

class InvoiceServiceTest extends WeaverTestCase
{
    use RefreshDatabase;

    public function test_invoice_creation(): void
    {
        // Database is migrated fresh before this class runs
        // and truncated before each test method
    }
}
```

`RefreshDatabase` is slower than `DatabaseTransactions` but supports tests that open nested transactions or test rollback behaviour.

---

## Using SQLite in-memory for fast tests

Configure a separate test database connection pointing to SQLite in-memory:

```yaml
# config/packages/test/doctrine.yaml
doctrine:
    dbal:
        driver: pdo_sqlite
        memory:  true
        charset: UTF8
```

Or in your `.env.test`:

```bash
DATABASE_URL="sqlite:///:memory:"
```

SQLite in-memory databases are created fresh for each test run (or each connection). Combined with `RefreshDatabase`, this gives you the fastest possible integration test suite with no external database dependency.

Weaver ORM's schema commands work with SQLite, so `weaver:schema:create` will build all tables in the in-memory database before your tests run.

---

## Mocking repositories

For pure unit tests that should not touch the database at all, mock the repository interface using PHPUnit:

```php
<?php

namespace Tests\Unit;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserGreetingService;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;

class UserGreetingServiceTest extends TestCase
{
    public function test_greet_returns_welcome_message(): void
    {
        $user = new User(name: 'Alice', email: 'alice@example.com');

        $repository = $this->createMock(UserRepository::class);
        $repository
            ->method('findOrFail')
            ->with(1)
            ->willReturn($user);

        $service  = new UserGreetingService($repository);
        $greeting = $service->greet(userId: 1);

        $this->assertSame('Welcome back, Alice!', $greeting);
    }

    public function test_greet_all_returns_collection(): void
    {
        $users = new EntityCollection([
            new User(name: 'Alice', email: 'alice@example.com'),
            new User(name: 'Bob',   email: 'bob@example.com'),
        ]);

        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willReturn($users);

        $service   = new UserGreetingService($repository);
        $greetings = $service->greetAll();

        $this->assertCount(2, $greetings);
    }
}
```

Since repositories are plain PHP classes (no magic proxies), PHPUnit's `createMock()` works without any special configuration.

---

## Asserting entity state

`WeaverTestCase` provides convenience assertions for common ORM checks:

```php
<?php

// Assert an entity exists in the database
$this->assertEntityExists(User::class, ['email' => 'alice@example.com']);

// Assert an entity does not exist
$this->assertEntityMissing(User::class, ['email' => 'deleted@example.com']);

// Assert a count
$this->assertEntityCount(3, Order::class, ['status' => 'pending']);

// Assert soft-deleted
$this->assertSoftDeleted(Post::class, $postId);
```
