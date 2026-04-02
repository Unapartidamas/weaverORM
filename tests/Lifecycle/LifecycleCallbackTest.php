<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Lifecycle;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Lifecycle\EntityLifecycleInvoker;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\Attribute\AfterLoad;
use Weaver\ORM\Mapping\Attribute\AfterAdd;
use Weaver\ORM\Mapping\Attribute\AfterDelete;
use Weaver\ORM\Mapping\Attribute\AfterUpdate;
use Weaver\ORM\Mapping\Attribute\BeforeAdd;
use Weaver\ORM\Mapping\Attribute\BeforeDelete;
use Weaver\ORM\Mapping\Attribute\BeforeUpdate;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class OrderEntity
{
    public ?int $id = null;
    public string $name = '';

    public array $events = [];

    #[BeforeAdd]
    public function onPrePersist(): void
    {
        $this->events[] = 'pre_persist';
    }

    #[AfterAdd]
    public function onPostPersist(): void
    {
        $this->events[] = 'post_persist';
    }

    #[BeforeUpdate]
    public function onPreUpdate(): void
    {
        $this->events[] = 'pre_update';
    }

    #[AfterUpdate]
    public function onPostUpdate(): void
    {
        $this->events[] = 'post_update';
    }

    #[BeforeDelete]
    public function onPreRemove(): void
    {
        $this->events[] = 'pre_remove';
    }

    #[AfterDelete]
    public function onPostRemove(): void
    {
        $this->events[] = 'post_remove';
    }

    #[AfterLoad]
    public function onPostLoad(): void
    {
        $this->events[] = 'post_load';
    }
}

class OrderEntityMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return OrderEntity::class;
    }

    public function getTableName(): string
    {
        return 'orders';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string',  length: 255),
        ];
    }
}

final class LifecycleCallbackTest extends TestCase
{
    private EntityLifecycleInvoker $invoker;

    protected function setUp(): void
    {
        $this->invoker = new EntityLifecycleInvoker();
    }

    public function test_invokes_pre_persist_method(): void
    {
        $entity = new OrderEntity();
        $this->invoker->invoke($entity, BeforeAdd::class);
        self::assertSame(['pre_persist'], $entity->events);
    }

    public function test_invokes_post_persist_method(): void
    {
        $entity = new OrderEntity();
        $this->invoker->invoke($entity, AfterAdd::class);
        self::assertSame(['post_persist'], $entity->events);
    }

    public function test_invokes_pre_update_method(): void
    {
        $entity = new OrderEntity();
        $this->invoker->invoke($entity, BeforeUpdate::class);
        self::assertSame(['pre_update'], $entity->events);
    }

    public function test_invokes_post_update_method(): void
    {
        $entity = new OrderEntity();
        $this->invoker->invoke($entity, AfterUpdate::class);
        self::assertSame(['post_update'], $entity->events);
    }

    public function test_invokes_pre_remove_method(): void
    {
        $entity = new OrderEntity();
        $this->invoker->invoke($entity, BeforeDelete::class);
        self::assertSame(['pre_remove'], $entity->events);
    }

    public function test_invokes_post_remove_method(): void
    {
        $entity = new OrderEntity();
        $this->invoker->invoke($entity, AfterDelete::class);
        self::assertSame(['post_remove'], $entity->events);
    }

    public function test_invokes_post_load_method(): void
    {
        $entity = new OrderEntity();
        $this->invoker->invoke($entity, AfterLoad::class);
        self::assertSame(['post_load'], $entity->events);
    }

    public function test_no_methods_invoked_for_unrelated_attribute(): void
    {
        $entity = new OrderEntity();

        $this->invoker->invoke($entity, AfterLoad::class);
        self::assertNotContains('pre_persist', $entity->events);
        self::assertNotContains('post_persist', $entity->events);
    }

    public function test_cache_is_reused_across_multiple_calls(): void
    {
        $entity1 = new OrderEntity();
        $entity2 = new OrderEntity();

        $this->invoker->invoke($entity1, BeforeAdd::class);
        $this->invoker->invoke($entity2, BeforeAdd::class);


        self::assertSame(['pre_persist'], $entity1->events);
        self::assertSame(['pre_persist'], $entity2->events);
    }

    public function test_entity_with_no_lifecycle_methods_does_not_error(): void
    {
        $plain = new class () {};

        $this->invoker->invoke($plain, BeforeAdd::class);
        $this->addToAssertionCount(1);
    }





    private function buildUow(): array
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement(
            'CREATE TABLE orders (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $registry = new MapperRegistry();
        $registry->register(new OrderEntityMapper());

        $invoker    = new EntityLifecycleInvoker();
        $hydrator   = new EntityHydrator($registry, $connection, null, $invoker);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($connection, $registry, $hydrator, $dispatcher, $resolver, $invoker);

        return [$uow, $connection, $registry, $hydrator];
    }

    public function test_uow_fires_pre_and_post_persist_on_flush(): void
    {
        [$uow] = $this->buildUow();

        $entity = new OrderEntity();
        $entity->name = 'Test';

        $uow->add($entity);

        self::assertContains('pre_persist', $entity->events, 'BeforeAdd must fire on persist()');

        $uow->push();
        self::assertContains('post_persist', $entity->events, 'AfterAdd must fire after INSERT');
    }

    public function test_uow_fires_pre_and_post_update_on_flush(): void
    {
        [$uow] = $this->buildUow();

        $entity = new OrderEntity();
        $entity->name = 'Original';

        $uow->add($entity);
        $uow->push();

        $entity->events = [];

        $entity->name = 'Updated';
        $uow->add($entity);
        $uow->push();

        self::assertContains('pre_update', $entity->events, 'BeforeUpdate must fire before UPDATE');
        self::assertContains('post_update', $entity->events, 'AfterUpdate must fire after UPDATE');
    }

    public function test_uow_fires_pre_and_post_remove_on_flush(): void
    {
        [$uow] = $this->buildUow();

        $entity = new OrderEntity();
        $entity->name = 'ToDelete';

        $uow->add($entity);
        $uow->push();

        $entity->events = [];

        $uow->delete($entity);
        $uow->push();

        self::assertContains('pre_remove', $entity->events, 'BeforeDelete must fire before DELETE');
        self::assertContains('post_remove', $entity->events, 'AfterDelete must fire after DELETE');
    }

    public function test_hydrator_fires_post_load(): void
    {
        [, $connection, $registry, $hydrator] = $this->buildUow();

        $connection->executeStatement(
            'INSERT INTO orders (name) VALUES (?)',
            ['Loaded Order'],
        );

        $row = $connection->fetchAssociative('SELECT * FROM orders LIMIT 1');
        self::assertNotFalse($row);


        $entity = $hydrator->hydrate(OrderEntity::class, $row);

        self::assertContains('post_load', $entity->events, 'AfterLoad must fire after hydration');
    }
}
