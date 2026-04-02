<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Persistence;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\Attribute\AfterAdd;
use Weaver\ORM\Mapping\Attribute\BeforeAdd;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class UpsertProduct
{
    public ?int $id    = null;
    public string $sku  = '';
    public string $name = '';
    public int $price   = 0;
}

class UpsertProductMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return UpsertProduct::class; }
    public function getTableName(): string   { return 'upsert_products'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('sku',   'sku',   'string',  length: 100),
            new ColumnDefinition('name',  'name',  'string',  length: 255),
            new ColumnDefinition('price', 'price', 'integer'),
        ];
    }
}

class UpsertItem
{
    public int $itemId  = 0;
    public string $slug = '';
    public int $stock   = 0;
}

class UpsertItemMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return UpsertItem::class; }
    public function getTableName(): string   { return 'upsert_items'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('item_id', 'itemId', 'integer', primary: true),
            new ColumnDefinition('slug',    'slug',   'string',  length: 200),
            new ColumnDefinition('stock',   'stock',  'integer'),
        ];
    }
}

class UpsertWidget
{
    public ?int $id          = null;
    public string $color     = '';
    public int $prePersistCalls  = 0;
    public int $postPersistCalls = 0;

    #[BeforeAdd]
    public function onPrePersist(): void
    {
        $this->prePersistCalls++;
    }

    #[AfterAdd]
    public function onPostPersist(): void
    {
        $this->postPersistCalls++;
    }
}

class UpsertWidgetMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return UpsertWidget::class; }
    public function getTableName(): string   { return 'upsert_widgets'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('color', 'color', 'string',  length: 100),
        ];
    }
}

final class UpsertTest extends TestCase
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
            'CREATE TABLE upsert_products (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                sku   TEXT    NOT NULL DEFAULT \'\',
                name  TEXT    NOT NULL DEFAULT \'\',
                price INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE upsert_items (
                item_id INTEGER PRIMARY KEY,
                slug    TEXT    NOT NULL DEFAULT \'\',
                stock   INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE upsert_widgets (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                color TEXT    NOT NULL DEFAULT \'\'
            )'
        );

        $this->registry = new MapperRegistry();
        $this->registry->register(new UpsertProductMapper());
        $this->registry->register(new UpsertItemMapper());
        $this->registry->register(new UpsertWidgetMapper());

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





    public function test_upsert_inserts_new_row_when_pk_does_not_exist(): void
    {
        $item          = new UpsertItem();
        $item->itemId  = 42;
        $item->slug    = 'new-item';
        $item->stock   = 10;

        $this->uow->upsert($item);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM upsert_items WHERE item_id = 42',
        );

        self::assertNotFalse($row, 'Row must exist after upsert of new entity');
        self::assertSame('new-item', $row['slug']);
        self::assertSame(10, (int) $row['stock']);
    }





    public function test_upsert_updates_existing_row_when_pk_already_exists(): void
    {

        $this->connection->executeStatement(
            'INSERT INTO upsert_items (item_id, slug, stock) VALUES (7, \'original-slug\', 5)'
        );


        $item         = new UpsertItem();
        $item->itemId = 7;
        $item->slug   = 'updated-slug';
        $item->stock  = 99;

        $this->uow->upsert($item);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM upsert_items WHERE item_id = 7',
        );

        self::assertNotFalse($row, 'Row must still exist after upsert');
        self::assertSame('updated-slug', $row['slug'], 'slug must be updated');
        self::assertSame(99, (int) $row['stock'], 'stock must be updated');


        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM upsert_items');
        self::assertSame(1, $count, 'Upsert must not duplicate rows');
    }





    public function test_after_upsert_new_entity_is_managed(): void
    {
        $item         = new UpsertItem();
        $item->itemId = 100;
        $item->slug   = 'managed-test';
        $item->stock  = 3;

        self::assertFalse($this->uow->isTracked($item), 'Entity must not be managed before upsert');

        $this->uow->upsert($item);

        self::assertTrue($this->uow->isTracked($item), 'Entity must be managed after upsert');
    }






    public function test_after_upsert_snapshot_is_refreshed_no_spurious_update(): void
    {
        $item         = new UpsertItem();
        $item->itemId = 200;
        $item->slug   = 'snap-test';
        $item->stock  = 7;

        $this->uow->upsert($item);



        $preUpdateFired = false;
        $this->dispatcher->addListener(
            'preUpdate',
            function () use (&$preUpdateFired): void {
                $preUpdateFired = true;
            },
        );


        $this->uow->push();

        self::assertFalse($preUpdateFired, 'No preUpdate should fire when entity is clean after upsert');
    }





    public function test_upsert_with_auto_increment_pk_assigns_id(): void
    {
        $product        = new UpsertProduct();
        $product->sku   = 'SKU-001';
        $product->name  = 'Widget Alpha';
        $product->price = 1999;

        self::assertNull($product->id, 'ID must be null before upsert');

        $this->uow->upsert($product);

        self::assertNotNull($product->id, 'ID must be assigned after upsert of new auto-increment entity');
        self::assertIsInt($product->id);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM upsert_products WHERE id = ?',
            [$product->id],
        );

        self::assertNotFalse($row);
        self::assertSame('SKU-001', $row['sku']);
        self::assertSame('Widget Alpha', $row['name']);
        self::assertSame(1999, (int) $row['price']);
    }





    public function test_multiple_upserts_in_sequence(): void
    {

        $item         = new UpsertItem();
        $item->itemId = 300;
        $item->slug   = 'seq-first';
        $item->stock  = 1;
        $this->uow->upsert($item);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM upsert_items WHERE item_id = 300',
        );
        self::assertNotFalse($row);
        self::assertSame('seq-first', $row['slug']);
        self::assertSame(1, (int) $row['stock']);



        $this->uow->untrack($item);

        $item2         = new UpsertItem();
        $item2->itemId = 300;
        $item2->slug   = 'seq-second';
        $item2->stock  = 50;
        $this->uow->upsert($item2);

        $row2 = $this->connection->fetchAssociative(
            'SELECT * FROM upsert_items WHERE item_id = 300',
        );
        self::assertNotFalse($row2);
        self::assertSame('seq-second', $row2['slug'], 'Second upsert must overwrite slug');
        self::assertSame(50, (int) $row2['stock'], 'Second upsert must overwrite stock');


        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM upsert_items');
        self::assertSame(1, $count, 'Multiple upserts on the same PK must not duplicate rows');
    }





    public function test_lifecycle_hooks_fire_on_upsert(): void
    {
        $widget        = new UpsertWidget();
        $widget->color = 'blue';

        self::assertSame(0, $widget->prePersistCalls);
        self::assertSame(0, $widget->postPersistCalls);

        $this->uow->upsert($widget);

        self::assertSame(1, $widget->prePersistCalls, 'prePersist must fire once on upsert');
        self::assertSame(1, $widget->postPersistCalls, 'postPersist must fire once on upsert');
    }





    public function test_pre_persist_not_fired_twice_when_already_fired(): void
    {
        $widget        = new UpsertWidget();
        $widget->color = 'red';


        $this->uow->upsert($widget);
        self::assertSame(1, $widget->prePersistCalls);



        $widget2        = new UpsertWidget();
        $widget2->color = 'green';




        $this->uow->upsert($widget2);
        self::assertSame(1, $widget2->prePersistCalls);


        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM upsert_widgets');
        self::assertSame(2, $count);
    }
}
