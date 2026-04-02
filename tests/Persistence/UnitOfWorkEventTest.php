<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Persistence;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Event\LifecycleEvents;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Schema\SchemaGenerator;

class Note
{
    public ?int $id       = null;
    public string $body   = '';
}

class NoteMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return Note::class;
    }

    public function getTableName(): string
    {
        return 'notes';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true, nullable: false),
            new ColumnDefinition('body', 'body', 'string',  nullable: false, length: 1000),
        ];
    }
}

final class UnitOfWorkEventTest extends TestCase
{
    private Connection $connection;
    private MapperRegistry $registry;
    private LifecycleEventDispatcher $dispatcher;
    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->registry = new MapperRegistry();
        $this->registry->register(new NoteMapper());


        $generator = new SchemaGenerator($this->registry, $this->connection->getDatabasePlatform());
        foreach ($generator->generateSql() as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->dispatcher = new LifecycleEventDispatcher();

        $hydrator = new EntityHydrator($this->registry, $this->connection);
        $resolver = new InsertOrderResolver($this->registry);

        $this->uow = new UnitOfWork(
            $this->connection,
            $this->registry,
            $hydrator,
            $this->dispatcher,
            $resolver,
        );
    }





    private function makeNote(string $body = 'Hello'): Note
    {
        $note       = new Note();
        $note->body = $body;

        return $note;
    }



    private function insertNoteDirectly(string $body): Note
    {
        $this->connection->executeStatement(
            'INSERT INTO `notes` (`body`) VALUES (?)',
            [$body],
        );

        $id         = (int) $this->connection->lastInsertId();
        $note       = new Note();
        $note->id   = $id;
        $note->body = $body;

        return $note;
    }





    public function test_pre_persist_event_fires_on_flush(): void
    {
        $fired = false;

        $this->dispatcher->addListener('prePersist', function () use (&$fired): void {
            $fired = true;
        });

        $note = $this->makeNote();
        $this->uow->add($note);
        $this->uow->push();

        $this->assertTrue($fired, 'prePersist listener must have been called during flush.');
    }





    public function test_post_persist_event_fires_after_insert(): void
    {
        $firedEntity = null;

        $this->dispatcher->addListener('postPersist', function ($event) use (&$firedEntity): void {
            $firedEntity = $event->getEntity();
        });

        $note = $this->makeNote('Post-persist test');
        $this->uow->add($note);
        $this->uow->push();

        $this->assertNotNull($firedEntity, 'postPersist listener must fire after flush.');
        $this->assertSame($note, $firedEntity, 'postPersist event must carry the persisted entity.');
        $this->assertNotNull($note->id, 'Entity should have its auto-increment ID assigned after INSERT.');
        $this->assertGreaterThan(0, $note->id);
    }





    public function test_pre_update_event_fires_with_changeset(): void
    {

        $note = $this->makeNote('Original body');
        $this->uow->add($note);
        $this->uow->push();

        $capturedChangeset = null;

        $this->dispatcher->addListener('preUpdate', function ($event) use (&$capturedChangeset): void {
            $capturedChangeset = $event->getChangeset();
        });


        $note->body = 'Updated body';
        $this->uow->push();

        $this->assertNotNull($capturedChangeset, 'preUpdate must fire when a managed entity is dirty.');
        $this->assertArrayHasKey('body', $capturedChangeset, 'Changeset must include the "body" column.');
        $this->assertSame('Updated body', $capturedChangeset['body']);
    }





    public function test_post_update_event_fires_after_update(): void
    {
        $note = $this->makeNote('Before update');
        $this->uow->add($note);
        $this->uow->push();

        $postUpdateFired = false;

        $this->dispatcher->addListener('postUpdate', function () use (&$postUpdateFired): void {
            $postUpdateFired = true;
        });

        $note->body = 'After update';
        $this->uow->push();

        $this->assertTrue($postUpdateFired, 'postUpdate listener must fire after an UPDATE is executed.');
    }





    public function test_pre_remove_event_fires_before_delete(): void
    {
        $note = $this->insertNoteDirectly('To be removed');


        $this->uow->track($note, Note::class);

        $rowExistedAtPreRemove = false;

        $this->dispatcher->addListener('preRemove', function () use (&$rowExistedAtPreRemove, $note): void {

            $row = $this->connection->fetchAssociative(
                'SELECT id FROM notes WHERE id = ?',
                [$note->id],
            );
            $rowExistedAtPreRemove = ($row !== false);
        });

        $this->uow->delete($note);
        $this->uow->push();

        $this->assertTrue(
            $rowExistedAtPreRemove,
            'preRemove must fire BEFORE the DELETE; the row must still exist at that point.',
        );
    }





    public function test_post_remove_event_fires_after_delete(): void
    {
        $note = $this->insertNoteDirectly('To be deleted');
        $this->uow->track($note, Note::class);

        $postRemoveFired = false;

        $this->dispatcher->addListener('postRemove', function () use (&$postRemoveFired): void {
            $postRemoveFired = true;
        });

        $this->uow->delete($note);
        $this->uow->push();

        $this->assertTrue($postRemoveFired, 'postRemove listener must fire after the DELETE.');


        $row = $this->connection->fetchAssociative(
            'SELECT id FROM notes WHERE id = ?',
            [$note->id],
        );

        $this->assertFalse($row, 'Row should no longer exist in the DB after postRemove fires.');
    }





    public function test_pre_flush_event_fires_at_start_of_flush(): void
    {
        $order = [];

        $this->dispatcher->addListener('preFlush', function () use (&$order): void {
            $order[] = 'preFlush';
        });

        $this->dispatcher->addListener('postPersist', function () use (&$order): void {
            $order[] = 'postPersist';
        });

        $this->uow->add($this->makeNote('Ordering test'));
        $this->uow->push();

        $this->assertSame('preFlush', $order[0], 'preFlush must be the first event fired during flush.');
    }





    public function test_post_flush_event_fires_at_end_of_flush(): void
    {
        $order = [];

        $this->dispatcher->addListener('postPersist', function () use (&$order): void {
            $order[] = 'postPersist';
        });

        $this->dispatcher->addListener('postFlush', function () use (&$order): void {
            $order[] = 'postFlush';
        });

        $this->uow->add($this->makeNote('Flush ordering test'));
        $this->uow->push();

        $this->assertNotEmpty($order, 'At least one event should have fired.');
        $this->assertSame(
            'postFlush',
            end($order),
            'postFlush must be the last event fired during flush.',
        );
    }
}
