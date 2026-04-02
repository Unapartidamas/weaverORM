<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Repository;

use BadMethodCallException;
use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Repository\EntityRepository;

class ScopeUser
{
    public ?int $id     = null;
    public string $name  = '';
    public string $status = 'active';
    public int $age = 0;
}

class ScopeUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return ScopeUser::class;
    }

    public function getTableName(): string
    {
        return 'scope_users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',     'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',   'name',   'string',  length: 100),
            new ColumnDefinition('status', 'status', 'string',  length: 50),
            new ColumnDefinition('age',    'age',    'integer'),
        ];
    }
}

class ScopeUserRepository extends EntityRepository
{
    public function scopeActive(EntityQueryBuilder $qb): void
    {
        $qb->where('status', 'active');
    }

    public function scopeAdult(EntityQueryBuilder $qb): void
    {
        $qb->where('age', '>=', 18);
    }

    public function scopeOlderThan(EntityQueryBuilder $qb, int $age): void
    {
        $qb->where('age', '>', $age);
    }
}

final class ScopeTest extends TestCase
{
    private Connection $connection;
    private EntityWorkspace $workspace;
    private ScopeUserRepository $repo;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE scope_users (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                name   TEXT    NOT NULL DEFAULT \'\',
                status TEXT    NOT NULL DEFAULT \'active\',
                age    INTEGER NOT NULL DEFAULT 0
            )'
        );

        $mapperRegistry = new MapperRegistry();
        $mapperRegistry->register(new ScopeUserMapper());

        $hydrator   = new EntityHydrator($mapperRegistry, $this->connection);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($mapperRegistry);
        $unitOfWork = new UnitOfWork($this->connection, $mapperRegistry, $hydrator, $dispatcher, $resolver);

        $this->workspace   = new EntityWorkspace('test', $this->connection, $mapperRegistry, $unitOfWork);
        $this->repo = new ScopeUserRepository($this->workspace, ScopeUser::class);
    }





    private function insertUser(string $name, string $status, int $age): int
    {
        $this->connection->executeStatement(
            'INSERT INTO scope_users (name, status, age) VALUES (:name, :status, :age)',
            ['name' => $name, 'status' => $status, 'age' => $age],
        );

        return (int) $this->connection->lastInsertId();
    }

    private function seedUsers(): void
    {
        $this->insertUser('Alice',   'active',   25);
        $this->insertUser('Bob',     'inactive', 30);
        $this->insertUser('Carol',   'active',   15);
        $this->insertUser('Dave',    'active',   40);
        $this->insertUser('Eve',     'inactive', 17);
    }






    public function test_scope_active_returns_only_active_users(): void
    {
        $this->seedUsers();

        $result = $this->repo->active()->get();

        self::assertInstanceOf(EntityCollection::class, $result);
        self::assertCount(3, $result);

        $names = array_map(static fn (ScopeUser $u): string => $u->name, $result->toArray());
        self::assertContains('Alice', $names);
        self::assertContains('Carol', $names);
        self::assertContains('Dave',  $names);
        self::assertNotContains('Bob', $names);
        self::assertNotContains('Eve', $names);
    }


    public function test_scope_active_and_adult_chains_two_scopes(): void
    {
        $this->seedUsers();

        $result = $this->repo->active()->adult()->get();

        self::assertInstanceOf(EntityCollection::class, $result);

        self::assertCount(2, $result);

        $names = array_map(static fn (ScopeUser $u): string => $u->name, $result->toArray());
        self::assertContains('Alice', $names);
        self::assertContains('Dave',  $names);
        self::assertNotContains('Carol', $names);
    }


    public function test_scope_combined_with_where(): void
    {
        $this->seedUsers();

        $result = $this->repo->active()->where('name', 'Alice')->get();

        self::assertCount(1, $result);

        $user = $result->toArray()[0];
        self::assertSame('Alice', $user->name);
    }


    public function test_scope_with_order_by(): void
    {
        $this->seedUsers();

        $result = $this->repo->active()->orderBy('age', 'ASC')->get();

        self::assertCount(3, $result);
        $ages = array_map(static fn (ScopeUser $u): int => $u->age, $result->toArray());

        self::assertSame([15, 25, 40], array_values($ages));
    }


    public function test_scope_count_returns_correct_count(): void
    {
        $this->seedUsers();

        $count = $this->repo->active()->count();

        self::assertSame(3, $count);
    }


    public function test_scope_first_returns_first_match(): void
    {
        $this->seedUsers();

        $user = $this->repo->active()->orderBy('age', 'ASC')->first();

        self::assertInstanceOf(ScopeUser::class, $user);

        self::assertSame('Carol', $user->name);
    }


    public function test_scope_with_parameter(): void
    {
        $this->seedUsers();

        $result = $this->repo->olderThan(30)->get();

        self::assertCount(1, $result);

        $user = $result->toArray()[0];

        self::assertSame('Dave', $user->name);
    }


    public function test_undefined_scope_throws_bad_method_call_exception(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/nonExistent|scopeNonExistent/i');


        $this->repo->nonExistent();
    }


    public function test_scope_method_works_like_magic_call(): void
    {
        $this->seedUsers();

        $result = $this->repo->scope('active')->get();

        self::assertCount(3, $result);
    }


    public function test_scope_method_chaining(): void
    {
        $this->seedUsers();

        $result = $this->repo->scope('active')->scope('adult')->get();

        self::assertCount(2, $result);
    }


    public function test_scope_method_throws_for_undefined_scope(): void
    {
        $this->expectException(BadMethodCallException::class);

        $this->repo->scope('nonExistent');
    }
}
