<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Persistence;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Exception\EntityNotFoundException;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class RdtPost
{
    public ?int $id        = null;
    public string $title   = '';
    public int $views      = 0;
    public bool $postLoaded = false;
}

class RdtPostMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return RdtPost::class;
    }

    public function getTableName(): string
    {
        return 'rdt_posts';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title', 'title', 'string',  length: 255),
            new ColumnDefinition('views', 'views', 'integer'),
        ];
    }
}

final class RefreshDetachTest extends TestCase
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
            'CREATE TABLE rdt_posts (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT    NOT NULL DEFAULT \'\',
                views INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->registry   = new MapperRegistry();
        $this->registry->register(new RdtPostMapper());

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





    public function test_refresh_reloads_db_values_overwriting_local_changes(): void
    {
        $post        = new RdtPost();
        $post->title = 'Original Title';
        $post->views = 10;

        $this->uow->add($post);
        $this->uow->push();


        $post->title = 'Dirty Title';
        $post->views = 999;


        $this->uow->reload($post);

        self::assertSame('Original Title', $post->title, 'title must revert to DB value after refresh');
        self::assertSame(10, $post->views, 'views must revert to DB value after refresh');
    }





    public function test_refresh_updates_snapshot_so_entity_is_not_dirty_after(): void
    {
        $post        = new RdtPost();
        $post->title = 'Snap Title';
        $post->views = 5;

        $this->uow->add($post);
        $this->uow->push();


        $post->title = 'Dirty';


        $this->uow->reload($post);


        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM rdt_posts WHERE id = ?',
            [$post->id],
        );
        self::assertNotFalse($row);
        self::assertSame('Snap Title', $row['title'], 'flush after refresh must be a no-op (no UPDATE issued)');
    }





    public function test_refresh_throws_entity_not_found_exception_for_missing_row(): void
    {
        $post     = new RdtPost();
        $post->id = 77777;

        $this->expectException(\RuntimeException::class);

        $this->uow->reload($post);
    }





    public function test_detach_stops_tracking_subsequent_flush_does_not_update(): void
    {
        $post        = new RdtPost();
        $post->title = 'Before Detach';
        $post->views = 1;

        $this->uow->add($post);
        $this->uow->push();


        $this->uow->untrack($post);

        $post->title = 'After Detach - should not save';

        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM rdt_posts WHERE id = ?',
            [$post->id],
        );
        self::assertNotFalse($row);
        self::assertSame('Before Detach', $row['title'], 'Detached entity title must not be updated by flush');
    }





    public function test_detach_removes_entity_from_identity_map(): void
    {
        $post        = new RdtPost();
        $post->title = 'Tracked';
        $post->views = 0;

        $this->uow->add($post);

        self::assertTrue($this->uow->isTracked($post), 'entity must be tracked after persist()');

        $this->uow->untrack($post);

        self::assertFalse($this->uow->isTracked($post), 'entity must not be tracked after detach()');
    }





    public function test_merge_returns_managed_instance_for_already_tracked_entity(): void
    {
        $post        = new RdtPost();
        $post->title = 'Managed';
        $post->views = 3;

        $this->uow->add($post);
        $this->uow->push();

        $managedId = $post->id;
        self::assertNotNull($managedId);


        $copy        = new RdtPost();
        $copy->id    = $managedId;
        $copy->title = 'Merged Title';
        $copy->views = 77;

        $returned = $this->uow->merge($copy);


        self::assertSame($post, $returned, 'merge() must return the already-managed instance');
        self::assertSame('Merged Title', $post->title);
        self::assertSame(77, $post->views);
    }





    public function test_merge_reattaches_detached_entity_and_returns_managed_instance(): void
    {
        $post        = new RdtPost();
        $post->title = 'Before Detach';
        $post->views = 2;

        $this->uow->add($post);
        $this->uow->push();

        $managedId = $post->id;
        self::assertNotNull($managedId);


        $this->uow->untrack($post);
        self::assertFalse($this->uow->isTracked($post));


        $detached        = new RdtPost();
        $detached->id    = $managedId;
        $detached->title = 'Re-attached Title';
        $detached->views = 55;

        $managed = $this->uow->merge($detached);


        self::assertTrue($this->uow->isTracked($managed), 'merged entity must be tracked');
        self::assertSame('Re-attached Title', $managed->title);
        self::assertSame(55, $managed->views);


        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM rdt_posts WHERE id = ?',
            [$managedId],
        );
        self::assertNotFalse($row);
        self::assertSame('Re-attached Title', $row['title']);
        self::assertSame(55, (int) $row['views']);
    }





    public function test_contains_returns_true_for_managed_and_false_for_detached(): void
    {
        $post        = new RdtPost();
        $post->title = 'Contains Test';
        $post->views = 0;


        self::assertFalse($this->uow->isTracked($post), 'entity must not be tracked before persist()');


        $this->uow->add($post);
        self::assertTrue($this->uow->isTracked($post), 'entity must be tracked after persist()');


        $this->uow->push();
        self::assertTrue($this->uow->isTracked($post), 'entity must remain tracked after flush()');


        $this->uow->untrack($post);
        self::assertFalse($this->uow->isTracked($post), 'entity must not be tracked after detach()');
    }





    public function test_modify_then_detach_then_modify_then_flush_issues_no_update(): void
    {
        $post        = new RdtPost();
        $post->title = 'Clean State';
        $post->views = 0;

        $this->uow->add($post);
        $this->uow->push();

        $savedId = $post->id;
        self::assertNotNull($savedId);


        $post->title = 'First Mutation';


        $this->uow->untrack($post);


        $post->title = 'Second Mutation';
        $post->views = 42;


        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM rdt_posts WHERE id = ?',
            [$savedId],
        );
        self::assertNotFalse($row);
        self::assertSame('Clean State', $row['title'], 'neither mutation must reach the DB after detach');
        self::assertSame(0, (int) $row['views'], 'views must remain 0 after detach+flush');
    }
}
