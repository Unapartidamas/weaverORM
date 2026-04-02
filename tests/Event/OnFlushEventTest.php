<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Event;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Event\LifecycleEvents;
use Weaver\ORM\Event\OnFlushEvent;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Schema\SchemaGenerator;

class Widget
{
    public ?int $id    = null;
    public string $name = '';
    public int $score  = 0;
}

class WidgetMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return Widget::class;
    }

    public function getTableName(): string
    {
        return 'widgets';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true, nullable: false),
            new ColumnDefinition('name',  'name',  'string',  nullable: false, length: 255),
            new ColumnDefinition('score', 'score', 'integer', nullable: false),
        ];
    }
}

final class OnFlushEventTest extends TestCase
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
        $this->registry->register(new WidgetMapper());

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





    private function makeWidget(string $name = 'Gizmo', int $score = 0): Widget
    {
        $w        = new Widget();
        $w->name  = $name;
        $w->score = $score;

        return $w;
    }

    private function persistedWidget(string $name = 'Managed', int $score = 10): Widget
    {
        $w = $this->makeWidget($name, $score);
        $this->uow->add($w);
        $this->uow->push();

        return $w;
    }

    private function countRows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM widgets');
    }





    public function test_on_flush_listener_receives_scheduled_inserts(): void
    {
        $w1 = $this->makeWidget('Alpha', 1);
        $w2 = $this->makeWidget('Beta', 2);

        $this->uow->add($w1);
        $this->uow->add($w2);

        $capturedInserts = null;

        $this->dispatcher->addListener(LifecycleEvents::ON_PUSH, function (OnFlushEvent $e) use (&$capturedInserts): void {
            $capturedInserts = $e->getScheduledEntityInserts();
        });

        $this->uow->push();

        $this->assertNotNull($capturedInserts);
        $this->assertCount(2, $capturedInserts);
        $this->assertContains($w1, $capturedInserts);
        $this->assertContains($w2, $capturedInserts);
    }





    public function test_on_flush_listener_receives_scheduled_updates(): void
    {
        $w = $this->persistedWidget('Original', 5);

        $w->score = 99;

        $capturedUpdates = null;

        $this->dispatcher->addListener(LifecycleEvents::ON_PUSH, function (OnFlushEvent $e) use (&$capturedUpdates): void {
            $capturedUpdates = $e->getScheduledEntityUpdates();
        });

        $this->uow->push();

        $this->assertNotNull($capturedUpdates);
        $this->assertCount(1, $capturedUpdates);
        $this->assertSame($w, $capturedUpdates[0]);
    }





    public function test_on_flush_listener_can_schedule_additional_insert(): void
    {
        $w1 = $this->makeWidget('First', 1);
        $this->uow->add($w1);

        $extraWidget = $this->makeWidget('ExtraFromListener', 42);

        $this->dispatcher->addListener(LifecycleEvents::ON_PUSH, function (OnFlushEvent $e) use ($extraWidget): void {
            $e->scheduleForInsert($extraWidget);
        });

        $this->uow->push();


        $this->assertSame(2, $this->countRows());
        $this->assertNotNull($w1->id);
        $this->assertNotNull($extraWidget->id);


        $row = $this->connection->fetchAssociative(
            'SELECT * FROM widgets WHERE id = ?',
            [$extraWidget->id],
        );
        $this->assertIsArray($row);
        $this->assertSame('ExtraFromListener', $row['name']);
        $this->assertSame(42, (int) $row['score']);
    }





    public function test_on_flush_listener_can_recompute_changeset(): void
    {
        $w = $this->persistedWidget('Unchanged', 0);


        $this->dispatcher->addListener(LifecycleEvents::ON_PUSH, function (OnFlushEvent $e) use ($w): void {
            $w->score = 777;
            $e->recomputeChangeset($w);
        });


        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT score FROM widgets WHERE id = ?',
            [$w->id],
        );
        $this->assertIsArray($row);
        $this->assertSame(777, (int) $row['score']);
    }





    public function test_post_flush_fires_after_all_sql(): void
    {
        $w = $this->makeWidget('PostFlushTest', 3);
        $this->uow->add($w);

        $rowCountAtPostFlush = null;

        $this->dispatcher->addListener('postFlush', function () use (&$rowCountAtPostFlush): void {
            $rowCountAtPostFlush = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM widgets');
        });

        $this->uow->push();


        $this->assertSame(1, $rowCountAtPostFlush);
    }





    public function test_on_flush_fires_before_any_sql(): void
    {
        $w = $this->makeWidget('BeforeSqlTest', 7);
        $this->uow->add($w);

        $rowCountDuringOnFlush = null;

        $this->dispatcher->addListener(LifecycleEvents::ON_PUSH, function (OnFlushEvent $e) use (&$rowCountDuringOnFlush): void {
            $rowCountDuringOnFlush = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM widgets');
        });

        $this->uow->push();


        $this->assertSame(0, $rowCountDuringOnFlush);

        $this->assertSame(1, $this->countRows());
    }





    public function test_on_flush_event_exposes_unit_of_work(): void
    {
        $w = $this->makeWidget('UoWAccessTest', 0);
        $this->uow->add($w);

        $capturedUoW = null;

        $this->dispatcher->addListener(LifecycleEvents::ON_PUSH, function (OnFlushEvent $e) use (&$capturedUoW): void {
            $capturedUoW = $e->getUnitOfWork();
        });

        $this->uow->push();

        $this->assertSame($this->uow, $capturedUoW);
    }





    public function test_on_flush_listener_receives_scheduled_deletes(): void
    {
        $w = $this->persistedWidget('ToBeDeleted', 5);

        $this->uow->delete($w);

        $capturedDeletes = null;

        $this->dispatcher->addListener(LifecycleEvents::ON_PUSH, function (OnFlushEvent $e) use (&$capturedDeletes): void {
            $capturedDeletes = $e->getScheduledEntityDeletes();
        });

        $this->uow->push();

        $this->assertNotNull($capturedDeletes);
        $this->assertCount(1, $capturedDeletes);
        $this->assertSame($w, $capturedDeletes[0]);
    }
}
