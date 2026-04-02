<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Persistence;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class RdmPost
{
    public ?int $id      = null;
    public string $title = '';
    public int $score    = 0;
}

class RdmPostMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return RdmPost::class;
    }

    public function getTableName(): string
    {
        return 'rdm_posts';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title', 'title', 'string',  length: 255),
            new ColumnDefinition('score', 'score', 'integer'),
        ];
    }
}

final class RefreshDetachMergeTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private LifecycleEventDispatcher $dispatcher;
    private InsertOrderResolver $resolver;
    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE rdm_posts (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT    NOT NULL DEFAULT \'\',
                score INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->registry   = new MapperRegistry();
        $this->registry->register(new RdmPostMapper());

        $this->hydrator   = new EntityHydrator($this->registry, $this->connection);
        $this->dispatcher = new LifecycleEventDispatcher();
        $this->resolver   = new InsertOrderResolver($this->registry);
        $this->uow        = new UnitOfWork(
            $this->connection,
            $this->registry,
            $this->hydrator,
            $this->dispatcher,
            $this->resolver,
        );
    }





    public function test_refresh_reverts_in_memory_changes_to_db_values(): void
    {
        $post        = new RdmPost();
        $post->title = 'Original';
        $post->score = 5;

        $this->uow->add($post);
        $this->uow->push();


        $post->title = 'Dirty';
        $post->score = 999;


        $this->uow->reload($post);

        self::assertSame('Original', $post->title, 'title must revert to DB value');
        self::assertSame(5, $post->score, 'score must revert to DB value');
    }

    public function test_refresh_on_non_existent_pk_throws_runtime_exception(): void
    {
        $post     = new RdmPost();
        $post->id = 99999;

        $this->expectException(\RuntimeException::class);
        $this->uow->reload($post);
    }

    public function test_refresh_updates_snapshot_so_subsequent_flush_is_noop(): void
    {
        $post        = new RdmPost();
        $post->title = 'Snap';
        $post->score = 1;

        $this->uow->add($post);
        $this->uow->push();

        $this->uow->reload($post);



        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM rdm_posts WHERE id = ?',
            [$post->id],
        );
        self::assertNotFalse($row);
        self::assertSame('Snap', $row['title']);
    }





    public function test_detach_prevents_update_on_flush(): void
    {
        $post        = new RdmPost();
        $post->title = 'Persist Me';
        $post->score = 10;

        $this->uow->add($post);
        $this->uow->push();

        self::assertNotNull($post->id);


        $this->uow->untrack($post);

        $post->title = 'Should Not Save';


        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM rdm_posts WHERE id = ?',
            [$post->id],
        );
        self::assertNotFalse($row);
        self::assertSame('Persist Me', $row['title'], 'Detached entity must not be updated');
    }

    public function test_detach_of_untracked_entity_is_noop(): void
    {
        $post        = new RdmPost();
        $post->title = 'Never tracked';


        $this->uow->untrack($post);

        self::assertFalse($this->uow->isTracked($post));
    }





    public function test_merge_of_detached_entity_with_known_pk_returns_managed_instance(): void
    {

        $post        = new RdmPost();
        $post->title = 'Before Merge';
        $post->score = 1;

        $this->uow->add($post);
        $this->uow->push();

        $managedId = $post->id;
        self::assertNotNull($managedId);


        $this->uow->untrack($post);


        $detached        = new RdmPost();
        $detached->id    = $managedId;
        $detached->title = 'After Merge';
        $detached->score = 42;


        $managed = $this->uow->merge($detached);

        self::assertNotSame($detached, $managed, 'merge() should return a different managed instance when entity exists in DB');
        self::assertTrue($this->uow->isTracked($managed));
        self::assertSame('After Merge', $managed->title);
        self::assertSame(42, $managed->score);


        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM rdm_posts WHERE id = ?',
            [$managedId],
        );
        self::assertNotFalse($row);
        self::assertSame('After Merge', $row['title']);
        self::assertSame(42, (int) $row['score']);
    }

    public function test_merge_of_entity_not_in_db_calls_persist_and_inserts_on_flush(): void
    {
        $detached        = new RdmPost();
        $detached->id    = null;
        $detached->title = 'Brand New';
        $detached->score = 7;

        $result = $this->uow->merge($detached);


        self::assertSame($detached, $result);
        self::assertTrue($this->uow->isTracked($result));

        $this->uow->push();

        self::assertNotNull($result->id);

        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rdm_posts');
        self::assertSame(1, $count);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM rdm_posts WHERE id = ?',
            [$result->id],
        );
        self::assertNotFalse($row);
        self::assertSame('Brand New', $row['title']);
    }

    public function test_merge_of_already_managed_entity_copies_values_onto_existing_managed(): void
    {

        $post        = new RdmPost();
        $post->title = 'Managed';
        $post->score = 1;

        $this->uow->add($post);
        $this->uow->push();

        $managedId = $post->id;


        $detached        = new RdmPost();
        $detached->id    = $managedId;
        $detached->title = 'Copied Over';
        $detached->score = 99;


        $returned = $this->uow->merge($detached);

        self::assertSame($post, $returned, 'merge() must return the already-managed instance');
        self::assertSame('Copied Over', $post->title);
        self::assertSame(99, $post->score);
    }
}
