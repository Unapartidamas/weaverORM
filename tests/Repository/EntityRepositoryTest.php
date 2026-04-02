<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Repository;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Exception\EntityNotFoundException;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Repository\EntityRepository;
use Weaver\ORM\Repository\RepositoryFactory;

class RepoUser
{
    public ?int $id     = null;
    public string $name  = '';
    public string $email = '';
    public string $status = 'active';
}

class RepoUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return RepoUser::class;
    }

    public function getTableName(): string
    {
        return 'repo_users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',     'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',   'name',   'string',  length: 100),
            new ColumnDefinition('email',  'email',  'string',  length: 200),
            new ColumnDefinition('status', 'status', 'string',  length: 50),
        ];
    }
}

class CustomRepoUserRepository extends EntityRepository
{
    public function findActive(): EntityCollection
    {
        return $this->query()->where('status', 'active')->get();
    }
}

final class EntityRepositoryTest extends TestCase
{
    private Connection $connection;
    private EntityWorkspace $workspace;
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE repo_users (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                name   TEXT    NOT NULL DEFAULT \'\',
                email  TEXT    NOT NULL DEFAULT \'\',
                status TEXT    NOT NULL DEFAULT \'active\'
            )'
        );

        $mapperRegistry = new MapperRegistry();
        $mapperRegistry->register(new RepoUserMapper());

        $hydrator   = new EntityHydrator($mapperRegistry, $this->connection);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($mapperRegistry);
        $unitOfWork = new UnitOfWork($this->connection, $mapperRegistry, $hydrator, $dispatcher, $resolver);

        $this->workspace   = new EntityWorkspace('test', $this->connection, $mapperRegistry, $unitOfWork);
        $this->repo = $this->workspace->getRepository(RepoUser::class);
    }





    private function insertUser(string $name, string $email, string $status = 'active'): int
    {
        $this->connection->executeStatement(
            'INSERT INTO repo_users (name, email, status) VALUES (:name, :email, :status)',
            ['name' => $name, 'email' => $email, 'status' => $status],
        );

        return (int) $this->connection->lastInsertId();
    }






    public function test_find_returns_entity_by_pk(): void
    {
        $id = $this->insertUser('Alice', 'alice@example.com');

        $entity = $this->repo->find($id);

        self::assertInstanceOf(RepoUser::class, $entity);
        self::assertSame($id, $entity->id);
        self::assertSame('Alice', $entity->name);
        self::assertSame('alice@example.com', $entity->email);
    }


    public function test_find_returns_null_for_missing_pk(): void
    {
        $entity = $this->repo->find(9999);

        self::assertNull($entity);
    }


    public function test_find_or_fail_throws_for_missing_pk(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->repo->findOrFail(9999);
    }


    public function test_find_all_returns_all_rows(): void
    {
        $this->insertUser('Alice', 'alice@example.com');
        $this->insertUser('Bob',   'bob@example.com', 'inactive');
        $this->insertUser('Carol', 'carol@example.com');

        $result = $this->repo->findAll();

        self::assertInstanceOf(EntityCollection::class, $result);
        self::assertCount(3, $result);
    }


    public function test_find_by_filters_by_criteria(): void
    {
        $this->insertUser('Alice', 'alice@example.com', 'active');
        $this->insertUser('Bob',   'bob@example.com',   'inactive');
        $this->insertUser('Carol', 'carol@example.com', 'active');

        $result = $this->repo->findBy(['status' => 'active']);

        self::assertCount(2, $result);

        $names = array_map(static fn (RepoUser $u): string => $u->name, $result->toArray());
        self::assertContains('Alice', $names);
        self::assertContains('Carol', $names);
        self::assertNotContains('Bob', $names);
    }


    public function test_find_by_with_order_limit_offset(): void
    {
        $this->insertUser('Charlie', 'charlie@example.com', 'active');
        $this->insertUser('Alice',   'alice@example.com',   'active');
        $this->insertUser('Bob',     'bob@example.com',     'active');
        $this->insertUser('Dave',    'dave@example.com',    'active');


        $result = $this->repo->findBy(
            ['status' => 'active'],
            ['name' => 'ASC'],
            limit: 2,
            offset: 1,
        );

        self::assertCount(2, $result);

        $names = array_map(static fn (RepoUser $u): string => $u->name, $result->toArray());

        self::assertSame(['Bob', 'Charlie'], array_values($names));
    }


    public function test_find_one_by_returns_first_match(): void
    {
        $this->insertUser('Alice', 'alice@example.com', 'active');
        $this->insertUser('Bob',   'bob@example.com',   'inactive');

        $found = $this->repo->findOneBy(['email' => 'alice@example.com']);
        self::assertInstanceOf(RepoUser::class, $found);
        self::assertSame('Alice', $found->name);

        $notFound = $this->repo->findOneBy(['email' => 'nobody@example.com']);
        self::assertNull($notFound);
    }


    public function test_count_with_criteria_returns_correct_count(): void
    {
        $this->insertUser('Alice', 'alice@example.com', 'active');
        $this->insertUser('Bob',   'bob@example.com',   'inactive');
        $this->insertUser('Carol', 'carol@example.com', 'active');

        $activeCount = $this->repo->count(['status' => 'active']);
        self::assertSame(2, $activeCount);

        $totalCount = $this->repo->count();
        self::assertSame(3, $totalCount);
    }


    public function test_custom_repository_subclass_works_via_entity_manager(): void
    {
        $this->insertUser('Alice', 'alice@example.com', 'active');
        $this->insertUser('Bob',   'bob@example.com',   'inactive');
        $this->insertUser('Carol', 'carol@example.com', 'active');


        $customRepo = $this->workspace->getRepository(RepoUser::class, CustomRepoUserRepository::class);

        self::assertInstanceOf(CustomRepoUserRepository::class, $customRepo);

        $activeUsers = $customRepo->findActive();
        self::assertInstanceOf(EntityCollection::class, $activeUsers);
        self::assertCount(2, $activeUsers);

        $names = array_map(static fn (RepoUser $u): string => $u->name, $activeUsers->toArray());
        self::assertContains('Alice', $names);
        self::assertContains('Carol', $names);
    }


    public function test_repository_factory_caches_instances(): void
    {
        $repo1 = $this->workspace->getRepository(RepoUser::class);
        $repo2 = $this->workspace->getRepository(RepoUser::class);

        self::assertSame($repo1, $repo2);
    }


    public function test_find_or_fail_returns_entity_when_found(): void
    {
        $id = $this->insertUser('Alice', 'alice@example.com');

        $entity = $this->repo->findOrFail($id);

        self::assertInstanceOf(RepoUser::class, $entity);
        self::assertSame($id, $entity->id);
    }
}
