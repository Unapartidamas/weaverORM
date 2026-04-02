<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Bridge;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Exception\EntityNotFoundException;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Pagination\CursorPage;
use Weaver\ORM\Pagination\Page;
use Weaver\ORM\Pagination\Paginator;
use Weaver\ORM\Pagination\SimplePage;
use Weaver\ORM\Persistence\BatchProcessor;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Repository\EntityRepository;
use Weaver\ORM\Transaction\TransactionManager;

require_once __DIR__ . '/EquivalenceTest.php';

final class CollectionRepositoryEquivalenceTest extends TestCase
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            age INTEGER,
            score REAL,
            active INTEGER DEFAULT 1,
            bio TEXT,
            registered_at TEXT
        )
    SQL;

    private Connection $wConn;
    private Connection $dConn;
    private EntityWorkspace $workspace;
    private MapperRegistry $registry;

    protected function setUp(): void
    {
        [$this->workspace, $this->wConn] = $this->createWeaverSetup();
        $this->dConn = $this->createDbalSetup();
        $this->registry = $this->workspace->getMapperRegistry();

        $this->seed($this->wConn);
        $this->seed($this->dConn);
    }

    private function createWeaverSetup(): array
    {
        $conn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement(self::SCHEMA);

        $registry = new MapperRegistry();
        $registry->register(new BenchUserMapper());

        $hydrator   = new EntityHydrator($registry, $conn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($conn, $registry, $hydrator, $dispatcher, $resolver);

        $workspace = new EntityWorkspace('default', $conn, $registry, $uow);

        return [$workspace, $conn];
    }

    private function createDbalSetup(): Connection
    {
        $conn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement(self::SCHEMA);

        return $conn;
    }

    private function seed(Connection $conn): void
    {
        $categories = ['admin', 'editor', 'viewer'];
        for ($i = 1; $i <= 50; $i++) {
            $conn->insert('users', [
                'name'          => "User{$i}",
                'email'         => "user{$i}@test.com",
                'age'           => 18 + ($i % 40),
                'score'         => round($i * 1.7, 2),
                'active'        => $i % 3 === 0 ? 0 : 1,
                'bio'           => $categories[$i % 3] . ' account',
                'registered_at' => sprintf('2025-%02d-%02d', (($i - 1) % 12) + 1, (($i - 1) % 28) + 1),
            ]);
        }
    }

    private function createQueryBuilder(Connection $conn): EntityQueryBuilder
    {
        $mapper   = $this->registry->get(BenchUser::class);
        $hydrator = new EntityHydrator($this->registry, $conn);

        return new EntityQueryBuilder($conn, BenchUser::class, $mapper, $hydrator);
    }

    private function makeCollection(Connection $conn): EntityCollection
    {
        return $this->createQueryBuilder($conn)->get();
    }

    private function getRepository(): EntityRepository
    {
        return $this->workspace->getRepository(BenchUser::class);
    }

    private function freshConn(): Connection
    {
        $conn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement(self::SCHEMA);

        return $conn;
    }

    // ── EntityCollection tests ──

    public function test_count_matches_db_count(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $dbCount    = (int) $this->wConn->fetchOne('SELECT COUNT(*) FROM users');

        self::assertSame($dbCount, $collection->count());
        self::assertSame(50, $collection->count());
    }

    public function test_isEmpty_true_on_empty_collection(): void
    {
        $collection = new EntityCollection([]);

        self::assertTrue($collection->isEmpty());
    }

    public function test_isEmpty_false_on_nonempty(): void
    {
        $collection = $this->makeCollection($this->wConn);

        self::assertFalse($collection->isEmpty());
    }

    public function test_first_returns_first_item(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $first      = $collection->first();

        self::assertInstanceOf(BenchUser::class, $first);
        self::assertSame('User1', $first->name);
    }

    public function test_last_returns_last_item(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $last       = $collection->last();

        self::assertInstanceOf(BenchUser::class, $last);
        self::assertSame('User50', $last->name);
    }

    public function test_filter_reduces_collection(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $filtered   = $collection->filter(fn (BenchUser $u) => $u->active === 1);

        $dbActive = (int) $this->wConn->fetchOne('SELECT COUNT(*) FROM users WHERE active = 1');
        self::assertSame($dbActive, $filtered->count());
        self::assertLessThan($collection->count(), $filtered->count());
    }

    public function test_map_transforms_items(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $names      = $collection->map(fn (BenchUser $u) => $u->name);

        self::assertCount(50, $names);
        self::assertSame('User1', $names[0]);
        self::assertSame('User50', $names[49]);
    }

    public function test_pluck_extracts_property(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $names      = $collection->pluck('name');

        self::assertCount(50, $names);
        self::assertSame('User1', $names[0]);
        self::assertContains('User25', $names);
    }

    public function test_pluck_with_key_by(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $emailById  = $collection->pluck('email', 'name');

        self::assertArrayHasKey('User1', $emailById);
        self::assertSame('user1@test.com', $emailById['User1']);
    }

    public function test_keyBy_indexes_by_property(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $byName     = $collection->keyBy('name');

        self::assertArrayHasKey('User10', $byName);
        self::assertInstanceOf(BenchUser::class, $byName['User10']);
        self::assertSame('user10@test.com', $byName['User10']->email);
    }

    public function test_groupBy_groups_by_property(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $groups     = $collection->groupBy('active');

        self::assertArrayHasKey(1, $groups);
        self::assertArrayHasKey(0, $groups);

        $activeCount   = (int) $this->wConn->fetchOne('SELECT COUNT(*) FROM users WHERE active = 1');
        $inactiveCount = (int) $this->wConn->fetchOne('SELECT COUNT(*) FROM users WHERE active = 0');

        self::assertInstanceOf(EntityCollection::class, $groups[1]);
        self::assertSame($activeCount, $groups[1]->count());
        self::assertSame($inactiveCount, $groups[0]->count());
    }

    public function test_contains_true_for_existing(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $first      = $collection->first();

        self::assertTrue($collection->contains($first));
    }

    public function test_contains_false_for_nonexistent(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $stranger   = new BenchUser();
        $stranger->name = 'Ghost';

        self::assertFalse($collection->contains($stranger));
    }

    public function test_containsWhere_finds_by_property(): void
    {
        $collection = $this->makeCollection($this->wConn);

        self::assertTrue($collection->containsWhere('name', 'User25'));
        self::assertFalse($collection->containsWhere('name', 'NonExistent'));
    }

    public function test_firstWhere_finds_by_property(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $found      = $collection->firstWhere('name', 'User30');

        self::assertInstanceOf(BenchUser::class, $found);
        self::assertSame('user30@test.com', $found->email);

        $notFound = $collection->firstWhere('name', 'Nobody');
        self::assertNull($notFound);
    }

    public function test_add_appends_to_collection(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $extra      = new BenchUser();
        $extra->name = 'Extra';

        $newCollection = $collection->add($extra);

        self::assertSame(51, $newCollection->count());
        self::assertSame('Extra', $newCollection->last()->name);
        self::assertSame(50, $collection->count());
    }

    public function test_merge_combines_collections(): void
    {
        $coll1 = new EntityCollection([
            (object) ['name' => 'A'],
            (object) ['name' => 'B'],
        ]);
        $coll2 = new EntityCollection([
            (object) ['name' => 'C'],
        ]);

        $merged = $coll1->merge($coll2);

        self::assertSame(3, $merged->count());
    }

    public function test_toArray_returns_raw_array(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $arr        = $collection->toArray();

        self::assertIsArray($arr);
        self::assertCount(50, $arr);
        self::assertInstanceOf(BenchUser::class, $arr[0]);
    }

    public function test_jsonSerialize_returns_array(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $json       = $collection->jsonSerialize();

        self::assertIsArray($json);
        self::assertCount(50, $json);
        self::assertSame(array_values($json), $json);
    }

    public function test_iteration_with_foreach(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $count      = 0;

        foreach ($collection as $item) {
            self::assertInstanceOf(BenchUser::class, $item);
            $count++;
        }

        self::assertSame(50, $count);
    }

    public function test_each_executes_callback(): void
    {
        $collection = $this->makeCollection($this->wConn);
        $names      = [];

        $result = $collection->each(function (BenchUser $u) use (&$names) {
            $names[] = $u->name;
        });

        self::assertCount(50, $names);
        self::assertSame('User1', $names[0]);
        self::assertSame($collection, $result);
    }

    // ── Repository tests ──

    public function test_find_by_pk_returns_correct_entity(): void
    {
        $repo   = $this->getRepository();
        $entity = $repo->find(1);

        self::assertInstanceOf(BenchUser::class, $entity);
        self::assertSame('User1', $entity->name);

        $dRow = $this->dConn->fetchAssociative('SELECT * FROM users WHERE id = 1');
        self::assertSame($dRow['name'], $entity->name);
        self::assertSame($dRow['email'], $entity->email);
    }

    public function test_find_nonexistent_returns_null(): void
    {
        $repo = $this->getRepository();

        self::assertNull($repo->find(9999));
    }

    public function test_findOrFail_throws_for_nonexistent(): void
    {
        $repo = $this->getRepository();

        $this->expectException(EntityNotFoundException::class);
        $repo->findOrFail(9999);
    }

    public function test_findAll_returns_all_entities(): void
    {
        $repo       = $this->getRepository();
        $collection = $repo->findAll();

        $dbalCount = (int) $this->dConn->fetchOne('SELECT COUNT(*) FROM users');

        self::assertInstanceOf(EntityCollection::class, $collection);
        self::assertSame($dbalCount, $collection->count());
        self::assertSame(50, $collection->count());
    }

    public function test_findBy_with_criteria(): void
    {
        $repo       = $this->getRepository();
        $collection = $repo->findBy(['active' => 0]);

        $dbalCount = (int) $this->dConn->fetchOne('SELECT COUNT(*) FROM users WHERE active = 0');
        self::assertSame($dbalCount, $collection->count());

        foreach ($collection as $user) {
            self::assertSame(0, $user->active);
        }
    }

    public function test_findBy_with_orderBy(): void
    {
        $repo       = $this->getRepository();
        $collection = $repo->findBy([], ['age' => 'DESC']);

        $items = $collection->toArray();
        for ($i = 1; $i < count($items); $i++) {
            self::assertGreaterThanOrEqual($items[$i]->age, $items[$i - 1]->age);
        }
    }

    public function test_findBy_with_limit(): void
    {
        $repo       = $this->getRepository();
        $collection = $repo->findBy([], [], 10);

        self::assertSame(10, $collection->count());
    }

    public function test_findBy_with_offset(): void
    {
        $repo = $this->getRepository();

        $all    = $repo->findBy([], ['id' => 'ASC']);
        $offset = $repo->findBy([], ['id' => 'ASC'], 5, 10);

        $allArr    = $all->toArray();
        $offsetArr = $offset->toArray();

        self::assertSame(5, $offset->count());
        self::assertSame($allArr[10]->name, $offsetArr[0]->name);
    }

    public function test_findOneBy_returns_single(): void
    {
        $repo = $this->getRepository();
        $user = $repo->findOneBy(['name' => 'User5']);

        self::assertInstanceOf(BenchUser::class, $user);
        self::assertSame('User5', $user->name);
    }

    public function test_findOneBy_returns_null_when_none(): void
    {
        $repo = $this->getRepository();

        self::assertNull($repo->findOneBy(['name' => 'Ghost']));
    }

    public function test_count_returns_total(): void
    {
        $repo      = $this->getRepository();
        $dbalCount = (int) $this->dConn->fetchOne('SELECT COUNT(*) FROM users');

        self::assertSame($dbalCount, $repo->count());
    }

    public function test_count_with_criteria(): void
    {
        $repo      = $this->getRepository();
        $dbalCount = (int) $this->dConn->fetchOne('SELECT COUNT(*) FROM users WHERE active = 1');

        self::assertSame($dbalCount, $repo->count(['active' => 1]));
    }

    public function test_repository_find_returns_same_data_as_dbal_fetch(): void
    {
        $repo   = $this->getRepository();
        $entity = $repo->find(25);

        $dRow = $this->dConn->fetchAssociative('SELECT * FROM users WHERE id = 25');

        self::assertNotNull($entity);
        self::assertNotFalse($dRow);
        self::assertSame($dRow['name'], $entity->name);
        self::assertSame($dRow['email'], $entity->email);
        self::assertSame((int) $dRow['age'], $entity->age);
        self::assertEqualsWithDelta((float) $dRow['score'], $entity->score, 0.01);
        self::assertSame((int) $dRow['active'], $entity->active);
        self::assertSame($dRow['bio'], $entity->bio);
        self::assertSame($dRow['registered_at'], $entity->registeredAt);
    }

    // ── TransactionManager tests ──

    public function test_begin_commit_persists_changes(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        $tm->begin();
        $conn->insert('users', ['name' => 'TxUser', 'email' => 'tx@test.com']);
        $tm->commit();

        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_begin_rollback_reverts_changes(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        $tm->begin();
        $conn->insert('users', ['name' => 'RbUser', 'email' => 'rb@test.com']);
        $tm->rollback();

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_transactional_success_commits(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        $result = $tm->transactional(function () use ($conn) {
            $conn->insert('users', ['name' => 'TxOk', 'email' => 'ok@test.com']);
            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_transactional_exception_rollbacks(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        try {
            $tm->transactional(function () use ($conn) {
                $conn->insert('users', ['name' => 'TxFail', 'email' => 'fail@test.com']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_nested_begin_uses_savepoints(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        $tm->begin();
        $conn->insert('users', ['name' => 'Outer', 'email' => 'outer@test.com']);

        $tm->transactional(function () use ($conn) {
            $conn->insert('users', ['name' => 'Inner', 'email' => 'inner@test.com']);
        });

        $tm->commit();

        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_nested_rollback_only_reverts_to_savepoint(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        $tm->begin();
        $conn->insert('users', ['name' => 'Outer', 'email' => 'outer@test.com']);

        try {
            $tm->transactional(function () use ($conn) {
                $conn->insert('users', ['name' => 'InnerFail', 'email' => 'innerfail@test.com']);
                throw new \RuntimeException('inner boom');
            });
        } catch (\RuntimeException) {
        }

        $tm->commit();

        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
        $row = $conn->fetchAssociative('SELECT name FROM users LIMIT 1');
        self::assertSame('Outer', $row['name']);
    }

    public function test_savepoint_create_and_rollback(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        $tm->begin();
        $conn->insert('users', ['name' => 'Before', 'email' => 'before@test.com']);
        $tm->savepoint('sp1');
        $conn->insert('users', ['name' => 'After', 'email' => 'after@test.com']);
        $tm->rollbackTo('sp1');
        $tm->commit();

        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
        $row = $conn->fetchAssociative('SELECT name FROM users');
        self::assertSame('Before', $row['name']);
    }

    public function test_savepoint_release(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        $tm->begin();
        $tm->savepoint('sp_release');
        $conn->insert('users', ['name' => 'Released', 'email' => 'released@test.com']);
        $tm->releaseSavepoint('sp_release');
        $tm->commit();

        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_isActive_true_during_transaction(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        self::assertFalse($tm->isActive());
        $tm->begin();
        self::assertTrue($tm->isActive());
        $tm->commit();
    }

    public function test_isActive_false_outside_transaction(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        self::assertFalse($tm->isActive());

        $tm->begin();
        $tm->commit();

        self::assertFalse($tm->isActive());
    }

    public function test_getDepth_tracks_nesting_level(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        self::assertSame(0, $tm->getDepth());
        $tm->begin();
        self::assertSame(1, $tm->getDepth());
        $tm->begin();
        self::assertSame(2, $tm->getDepth());
        $tm->commit();
        self::assertSame(1, $tm->getDepth());
        $tm->commit();
        self::assertSame(0, $tm->getDepth());
    }

    public function test_withDeadlockRetry_retries_on_failure(): void
    {
        $conn = $this->freshConn();
        $tm   = new TransactionManager($conn);

        $attempts = 0;
        $result = $tm->withDeadlockRetry(function () use (&$attempts) {
            $attempts++;
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(1, $attempts);
    }

    // ── BatchProcessor tests ──

    public function test_insertBatch_matches_individual_inserts(): void
    {
        $batchConn = $this->freshConn();
        $indivConn = $this->freshConn();

        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $row = ['name' => "Batch{$i}", 'email' => "batch{$i}@test.com", 'age' => 20 + $i, 'active' => 1];
            $rows[] = $row;
            $indivConn->insert('users', $row);
        }

        $bp       = new BatchProcessor($batchConn);
        $affected = $bp->insertBatch('users', $rows);

        self::assertSame(10, $affected);

        $batchRows = $batchConn->fetchAllAssociative('SELECT name, email, age FROM users ORDER BY name');
        $indivRows = $indivConn->fetchAllAssociative('SELECT name, email, age FROM users ORDER BY name');

        self::assertSame($indivRows, $batchRows);
    }

    public function test_insertBatch_with_100_rows(): void
    {
        $conn = $this->freshConn();
        $bp   = new BatchProcessor($conn);
        $rows = [];

        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['name' => "Big{$i}", 'email' => "big{$i}@test.com", 'age' => $i, 'active' => 1];
        }

        $affected = $bp->insertBatch('users', $rows);

        self::assertSame(100, $affected);
        self::assertSame(100, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_insertBatch_with_different_columns(): void
    {
        $conn = $this->freshConn();
        $bp   = new BatchProcessor($conn);
        $rows = [
            ['name' => 'A', 'email' => 'a@test.com', 'age' => 25, 'active' => 1, 'bio' => 'hello'],
            ['name' => 'B', 'email' => 'b@test.com', 'age' => 30, 'active' => 0, 'bio' => 'world'],
        ];

        $bp->insertBatch('users', $rows);

        $result = $conn->fetchAllAssociative('SELECT name, bio FROM users ORDER BY name');
        self::assertSame('A', $result[0]['name']);
        self::assertSame('hello', $result[0]['bio']);
        self::assertSame('B', $result[1]['name']);
        self::assertSame('world', $result[1]['bio']);
    }

    public function test_updateBatch_matches_individual_updates(): void
    {
        $batchConn = $this->freshConn();
        $indivConn = $this->freshConn();

        $seedRows = [];
        for ($i = 1; $i <= 5; $i++) {
            $row = ['name' => "Upd{$i}", 'email' => "upd{$i}@test.com", 'age' => 20 + $i, 'active' => 1];
            $seedRows[] = $row;
            $batchConn->insert('users', $row);
            $indivConn->insert('users', $row);
        }

        $updates = [];
        for ($i = 1; $i <= 5; $i++) {
            $updates[] = ['id' => $i, 'name' => "Updated{$i}", 'email' => "updated{$i}@test.com", 'age' => 30 + $i, 'active' => 0];
            $indivConn->update('users', ['name' => "Updated{$i}", 'email' => "updated{$i}@test.com", 'age' => 30 + $i, 'active' => 0], ['id' => $i]);
        }

        $bp       = new BatchProcessor($batchConn);
        $affected = $bp->updateBatch('users', $updates);

        self::assertSame(5, $affected);

        $batchRows = $batchConn->fetchAllAssociative('SELECT id, name, email, age, active FROM users ORDER BY id');
        $indivRows = $indivConn->fetchAllAssociative('SELECT id, name, email, age, active FROM users ORDER BY id');

        self::assertSame($indivRows, $batchRows);
    }

    public function test_deleteBatch_matches_individual_deletes(): void
    {
        $batchConn = $this->freshConn();
        $indivConn = $this->freshConn();

        for ($i = 1; $i <= 10; $i++) {
            $row = ['name' => "Del{$i}", 'email' => "del{$i}@test.com", 'active' => 1];
            $batchConn->insert('users', $row);
            $indivConn->insert('users', $row);
        }

        $idsToDelete = [2, 5, 8];
        foreach ($idsToDelete as $id) {
            $indivConn->delete('users', ['id' => $id]);
        }

        $bp       = new BatchProcessor($batchConn);
        $affected = $bp->deleteBatch('users', $idsToDelete);

        self::assertSame(3, $affected);

        $batchIds = $batchConn->fetchFirstColumn('SELECT id FROM users ORDER BY id');
        $indivIds = $indivConn->fetchFirstColumn('SELECT id FROM users ORDER BY id');

        self::assertSame($indivIds, $batchIds);
    }

    public function test_upsertBatch_inserts_new_rows(): void
    {
        $conn = $this->freshConn();
        $conn->executeStatement('CREATE UNIQUE INDEX ux_users_email ON users(email)');

        $bp   = new BatchProcessor($conn);
        $rows = [
            ['name' => 'New1', 'email' => 'new1@test.com', 'active' => 1],
            ['name' => 'New2', 'email' => 'new2@test.com', 'active' => 1],
        ];

        $bp->upsertBatch('users', $rows, 'email');

        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_upsertBatch_updates_existing_rows(): void
    {
        $conn = $this->freshConn();
        $conn->executeStatement('CREATE UNIQUE INDEX ux_users_email ON users(email)');

        $conn->insert('users', ['name' => 'Old', 'email' => 'upsert@test.com', 'active' => 1]);

        $bp   = new BatchProcessor($conn);
        $rows = [
            ['name' => 'Updated', 'email' => 'upsert@test.com', 'active' => 0],
        ];

        $bp->upsertBatch('users', $rows, 'email');

        $row = $conn->fetchAssociative('SELECT name, active FROM users WHERE email = ?', ['upsert@test.com']);
        self::assertSame('Updated', $row['name']);
        self::assertSame(0, (int) $row['active']);
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_insertBatch_chunking_produces_same_result(): void
    {
        $fullConn  = $this->freshConn();
        $chunkConn = $this->freshConn();

        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = ['name' => "Chunk{$i}", 'email' => "chunk{$i}@test.com", 'age' => $i, 'active' => 1];
        }

        $bpFull  = new BatchProcessor($fullConn);
        $bpChunk = new BatchProcessor($chunkConn);

        $bpFull->insertBatch('users', $rows, 500);
        $bpChunk->insertBatch('users', $rows, 5);

        $fullRows  = $fullConn->fetchAllAssociative('SELECT name, email, age FROM users ORDER BY name');
        $chunkRows = $chunkConn->fetchAllAssociative('SELECT name, email, age FROM users ORDER BY name');

        self::assertSame($fullRows, $chunkRows);
    }

    public function test_batch_returns_correct_affected_count(): void
    {
        $conn = $this->freshConn();
        $bp   = new BatchProcessor($conn);

        $affected = $bp->insertBatch('users', []);
        self::assertSame(0, $affected);

        $affected = $bp->insertBatch('users', [
            ['name' => 'A', 'email' => 'a@t.com', 'active' => 1],
            ['name' => 'B', 'email' => 'b@t.com', 'active' => 1],
            ['name' => 'C', 'email' => 'c@t.com', 'active' => 1],
        ]);
        self::assertSame(3, $affected);

        $deleted = $bp->deleteBatch('users', [1, 3]);
        self::assertSame(2, $deleted);

        $deleted = $bp->deleteBatch('users', []);
        self::assertSame(0, $deleted);
    }

    // ── Pagination tests ──

    public function test_paginate_page1_matches_limit_offset(): void
    {
        $qb   = $this->createQueryBuilder($this->wConn)->orderBy('id');
        $page = $qb->paginate(1, 10);

        $dbalRows = $this->dConn->fetchAllAssociative('SELECT * FROM users ORDER BY id LIMIT 10 OFFSET 0');

        self::assertInstanceOf(Page::class, $page);
        self::assertSame(10, $page->items->count());
        self::assertSame($dbalRows[0]['name'], $page->items->first()->name);
        self::assertSame($dbalRows[9]['name'], $page->items->last()->name);
    }

    public function test_paginate_page2_matches_offset(): void
    {
        $qb   = $this->createQueryBuilder($this->wConn)->orderBy('id');
        $page = $qb->paginate(2, 10);

        $dbalRows = $this->dConn->fetchAllAssociative('SELECT * FROM users ORDER BY id LIMIT 10 OFFSET 10');

        self::assertSame(10, $page->items->count());
        self::assertSame($dbalRows[0]['name'], $page->items->first()->name);
    }

    public function test_paginate_last_page_has_fewer_items(): void
    {
        $qb   = $this->createQueryBuilder($this->wConn)->orderBy('id');
        $page = $qb->paginate(3, 20);

        self::assertSame(10, $page->items->count());
    }

    public function test_paginate_total_count_is_correct(): void
    {
        $qb   = $this->createQueryBuilder($this->wConn)->orderBy('id');
        $page = $qb->paginate(1, 10);

        self::assertSame(50, $page->total);
    }

    public function test_paginate_hasMore_true_when_more_pages(): void
    {
        $qb   = $this->createQueryBuilder($this->wConn)->orderBy('id');
        $page = $qb->paginate(1, 10);

        self::assertTrue($page->hasMorePages);
    }

    public function test_paginate_hasMore_false_on_last_page(): void
    {
        $qb   = $this->createQueryBuilder($this->wConn)->orderBy('id');
        $page = $qb->paginate(5, 10);

        self::assertFalse($page->hasMorePages);
    }

    public function test_simplePaginate_no_total_count(): void
    {
        $qb   = $this->createQueryBuilder($this->wConn)->orderBy('id');
        $page = $qb->simplePaginate(1, 10);

        self::assertInstanceOf(SimplePage::class, $page);
        self::assertSame(10, $page->items->count());
        self::assertTrue($page->hasMorePages);

        $reflection = new \ReflectionClass($page);
        $properties = array_map(fn ($p) => $p->getName(), $reflection->getProperties());
        self::assertNotContains('total', $properties);
    }

    public function test_cursorPaginate_returns_cursor_page(): void
    {
        $qb        = $this->createQueryBuilder($this->wConn);
        $paginator = new Paginator();
        $page      = $paginator->cursorPaginate($qb, 10, 'id');

        self::assertInstanceOf(CursorPage::class, $page);
        self::assertSame(10, $page->items->count());
        self::assertTrue($page->hasMorePages);
        self::assertNotNull($page->nextCursor);

        $page2 = $paginator->cursorPaginate(
            $this->createQueryBuilder($this->wConn),
            10,
            'id',
            $page->nextCursor,
        );

        self::assertSame(10, $page2->items->count());
        self::assertNotSame(
            $page->items->first()->name,
            $page2->items->first()->name,
        );
    }
}
