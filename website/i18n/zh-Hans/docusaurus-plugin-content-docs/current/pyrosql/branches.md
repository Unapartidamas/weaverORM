---
id: branches
title: 分支
sidebar_label: Branches
---

PyroSQL branching works like Git branches, but for data. A branch is a **lightweight, copy-on-write snapshot** of the entire database. It can be queried and mutated independently of its parent, then either merged back or discarded — without affecting any other branch.

Because branches use copy-on-write storage, creating a branch is nearly instantaneous and consumes no additional disk space until rows are actually modified on the branch.

---

## Use cases

- **Feature branches** — develop schema migrations or data transformations in isolation before promoting them to production.
- **Staging environments** — give each developer or CI job its own live copy of production data without duplicating storage.
- **A/B testing** — run two variants of an application against diverging data sets and merge the winning variant.
- **Safe bulk operations** — run a destructive `UPDATE` or `DELETE` on a branch, verify the result, then merge; discard on failure.

---

## `PyroBranchManager`

`PyroBranchManager` is the entry point for all branch lifecycle operations. It requires a DBAL connection and a `PyroSqlDriver` instance, and all its methods assert that the connection is backed by PyroSQL before executing.

```php
use Weaver\ORM\PyroSQL\Branch\PyroBranchManager;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

$driver  = new PyroSqlDriver($connection);
$manager = new PyroBranchManager($connection, $driver);
```

### `create(string $name, string $from = 'main', ?DateTimeImmutable $asOf = null): PyroBranch`

Create a new branch from an existing one. By default branches off `main` at the current state. Pass `$asOf` to branch from a historical snapshot.

```php
// Branch off the current state of main
$branch = $manager->create('feature/new-pricing');

// Branch off main as it existed on a specific date
$branch = $manager->create(
    name: 'audit/q1-snapshot',
    from: 'main',
    asOf: new \DateTimeImmutable('2024-03-31 23:59:59'),
);
```

Executes:
```sql
CREATE BRANCH "feature/new-pricing" FROM "main"
-- or
CREATE BRANCH "audit/q1-snapshot" FROM "main" AS OF TIMESTAMP '2024-03-31 23:59:59'
```

### `list(): PyroBranch[]`

Return all branches visible on the current connection, ordered by creation time ascending.

```php
foreach ($manager->list() as $branch) {
    printf(
        "%-30s  parent: %-20s  created: %s\n",
        $branch->getName(),
        $branch->getParentName(),
        $branch->getCreatedAt()->format('Y-m-d H:i:s'),
    );
}
```

### `exists(string $name): bool`

Check whether a branch with the given name exists.

```php
if (!$manager->exists('feature/new-pricing')) {
    $branch = $manager->create('feature/new-pricing');
}
```

### `delete(string $name): void`

Permanently drop a branch. Throws `BranchNotFoundException` if the branch does not exist.

```php
$manager->delete('feature/old-experiment');
```

### `switch(string $name): void`

Switch the session context to the named branch. All subsequent queries on the current connection are routed to this branch until switched again.

```php
$manager->switch('feature/new-pricing');

// All queries now read from / write to the feature/new-pricing branch
$products = $productRepo->query()->where('active', true)->get();
```

Executes:
```sql
SET pyrosql.branch = 'feature/new-pricing'
```

---

## `PyroBranch`

`PyroBranch` is a value object representing a branch. Obtain instances via `PyroBranchManager::create()` or `PyroBranchManager::get()`.

### `connection(): Doctrine\DBAL\Connection`

Set the session context to this branch and return the DBAL connection. All subsequent queries on the returned connection are scoped to the branch.

```php
$branch = $manager->create('feature/new-pricing');
$conn   = $branch->connection();

// Use $conn directly for raw queries on this branch
$conn->executeStatement("UPDATE products SET price = price * 1.05");
```

### `mergeTo(string $targetBranch = 'main'): void`

Merge this branch back into another branch (defaults to `main`). Changes made on the branch are applied to the target.

```php
$branch->mergeTo('main');
// or merge into a different branch
$branch->mergeTo('staging');
```

Executes:
```sql
MERGE BRANCH "feature/new-pricing" INTO "main"
```

### `delete(): void`

Drop this branch permanently.

```php
$branch->delete();
```

### `storageBytes(): int`

Returns the number of bytes consumed by this branch's copy-on-write delta versus its parent. A freshly created branch with no modifications returns `0`.

```php
$bytes = $branch->storageBytes();
printf("Branch %s uses %.2f MB of storage.\n", $branch->getName(), $bytes / 1_048_576);
```

---

## Branch naming

Branch names follow the same rules as PyroSQL identifiers. Names may contain letters, digits, hyphens, underscores, and forward slashes (e.g. `feature/my-branch`). Quoting is handled automatically by `PyroBranchManager`.

---

## Full example: create a branch, modify data, merge back

```php
use Weaver\ORM\PyroSQL\Branch\PyroBranchManager;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

$driver  = new PyroSqlDriver($connection);
$manager = new PyroBranchManager($connection, $driver);

// 1. Create a feature branch off main
$branch = $manager->create('feature/price-increase');

// 2. Switch the session to the branch
$branchConn = $branch->connection();

// 3. Make changes on the branch
$branchConn->executeStatement(
    "UPDATE products SET price = ROUND(price * 1.10, 2) WHERE category = 'electronics'"
);

// 4. Verify the changes look correct
$branchConn->executeStatement("SET pyrosql.branch = 'feature/price-increase'");
$affectedCount = (int) $branchConn->fetchOne(
    "SELECT COUNT(*) FROM products WHERE category = 'electronics'"
);
printf("Updated %d products on branch.\n", $affectedCount);

// 5. Check storage overhead
printf("Branch storage: %d bytes\n", $branch->storageBytes());

// 6a. Merge the branch into main if satisfied
$branch->mergeTo('main');
$branch->delete();

// 6b. Or just discard if not satisfied
// $branch->delete();
```
