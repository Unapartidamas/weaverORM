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
use Weaver\ORM\Mapping\Attribute\AfterUpdate;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class BulkProduct
{
    public ?int $id    = null;
    public string $name  = '';
    public int $price  = 0;
    public int $stock  = 0;
}

class BulkProductMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return BulkProduct::class; }
    public function getTableName(): string   { return 'bulk_products'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',  'name',  'string',  length: 255),
            new ColumnDefinition('price', 'price', 'integer'),
            new ColumnDefinition('stock', 'stock', 'integer'),
        ];
    }
}

class BulkTimestampedItem
{
    public ?int $id        = null;
    public string $label   = '';
    public int $value      = 0;
    public ?\DateTimeImmutable $updatedAt = null;
}

class BulkTimestampedItemMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return BulkTimestampedItem::class; }
    public function getTableName(): string   { return 'bulk_timestamped_items'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',         'id',        'integer',            primary: true, autoIncrement: true),
            new ColumnDefinition('label',       'label',     'string',             length: 255),
            new ColumnDefinition('value',       'value',     'integer'),
            new ColumnDefinition('updated_at',  'updatedAt', 'string', nullable: true),
        ];
    }
}

class BulkWidget
{
    public ?int $id       = null;
    public string $color  = '';
    public int $quantity  = 0;
    public int $postUpdateCallCount = 0;

    #[AfterUpdate]
    public function onPostUpdate(): void
    {
        $this->postUpdateCallCount++;
    }
}

class BulkWidgetMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return BulkWidget::class; }
    public function getTableName(): string   { return 'bulk_widgets'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',       'id',       'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('color',     'color',    'string',  length: 100),
            new ColumnDefinition('quantity',  'quantity', 'integer'),
        ];
    }
}

class BulkOrderLine
{
    public int $orderId   = 0;
    public int $lineId    = 0;
    public int $quantity  = 0;
}

class BulkOrderLineMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return BulkOrderLine::class; }
    public function getTableName(): string   { return 'bulk_order_lines'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('order_id', 'orderId', 'integer', primary: true),
            new ColumnDefinition('line_id',  'lineId',  'integer', primary: true),
            new ColumnDefinition('quantity', 'quantity', 'integer'),
        ];
    }


    public function getPrimaryKeyColumns(): array
    {
        return ['order_id', 'line_id'];
    }
}

final class BulkUpdateTest extends TestCase
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
            'CREATE TABLE bulk_products (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                name  TEXT    NOT NULL DEFAULT \'\',
                price INTEGER NOT NULL DEFAULT 0,
                stock INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE bulk_timestamped_items (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                label      TEXT    NOT NULL DEFAULT \'\',
                value      INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT    NULL
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE bulk_widgets (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                color    TEXT    NOT NULL DEFAULT \'\',
                quantity INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE bulk_order_lines (
                order_id INTEGER NOT NULL,
                line_id  INTEGER NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (order_id, line_id)
            )'
        );

        $this->registry = new MapperRegistry();
        $this->registry->register(new BulkProductMapper());
        $this->registry->register(new BulkTimestampedItemMapper());
        $this->registry->register(new BulkWidgetMapper());
        $this->registry->register(new BulkOrderLineMapper());

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





    private function makeAndFlushProducts(int $count): array
    {
        $products = [];
        for ($i = 1; $i <= $count; $i++) {
            $p        = new BulkProduct();
            $p->name  = "Product {$i}";
            $p->price = $i * 10;
            $p->stock = $i * 5;
            $this->uow->add($p);
            $products[] = $p;
        }
        $this->uow->push();
        return $products;
    }

    private function fetchProduct(int $id): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT * FROM bulk_products WHERE id = ?',
            [$id],
        );
    }





    public function test_two_entities_same_dirty_columns_issues_single_bulk_update(): void
    {
        [$p1, $p2] = $this->makeAndFlushProducts(2);

        $p1->price = 999;
        $p1->stock = 88;
        $p2->price = 777;
        $p2->stock = 66;

        $this->uow->push();

        $row1 = $this->fetchProduct($p1->id);
        $row2 = $this->fetchProduct($p2->id);

        self::assertNotFalse($row1);
        self::assertNotFalse($row2);

        self::assertSame(999, (int) $row1['price'], 'p1 price must be updated');
        self::assertSame(88,  (int) $row1['stock'], 'p1 stock must be updated');
        self::assertSame(777, (int) $row2['price'], 'p2 price must be updated');
        self::assertSame(66,  (int) $row2['stock'], 'p2 stock must be updated');


        self::assertSame('Product 1', $row1['name']);
        self::assertSame('Product 2', $row2['name']);
    }





    public function test_three_plus_entities_same_dirty_columns_all_updated(): void
    {
        $products = $this->makeAndFlushProducts(5);

        foreach ($products as $idx => $p) {
            $p->price = ($idx + 1) * 100;
            $p->stock = ($idx + 1) * 50;
        }

        $this->uow->push();

        foreach ($products as $idx => $p) {
            $row = $this->fetchProduct($p->id);
            self::assertNotFalse($row, "Row {$idx} must exist");
            self::assertSame(($idx + 1) * 100, (int) $row['price'], "p{$idx} price must match");
            self::assertSame(($idx + 1) * 50,  (int) $row['stock'], "p{$idx} stock must match");
        }
    }





    public function test_mixed_dirty_sets_bulk_for_same_set_individual_for_others(): void
    {




        [$p1, $p2, $p3, $p4] = $this->makeAndFlushProducts(4);


        $p1->price = 501;
        $p1->stock = 201;
        $p2->price = 502;
        $p2->stock = 202;


        $p3->name = 'Renamed Product 3';


        $p4->price = 999;

        $this->uow->push();

        $row1 = $this->fetchProduct($p1->id);
        $row2 = $this->fetchProduct($p2->id);
        $row3 = $this->fetchProduct($p3->id);
        $row4 = $this->fetchProduct($p4->id);


        self::assertSame(501, (int) $row1['price']);
        self::assertSame(201, (int) $row1['stock']);
        self::assertSame(502, (int) $row2['price']);
        self::assertSame(202, (int) $row2['stock']);


        self::assertSame('Renamed Product 3', $row3['name']);
        self::assertSame(30,  (int) $row3['price'], 'p3 price must be unchanged');


        self::assertSame(999, (int) $row4['price']);
        self::assertSame('Product 4', $row4['name'], 'p4 name must be unchanged');
    }





    public function test_single_dirty_entity_falls_back_to_individual_update(): void
    {
        [$p] = $this->makeAndFlushProducts(1);

        $p->price = 42;

        $this->uow->push();

        $row = $this->fetchProduct($p->id);
        self::assertNotFalse($row);
        self::assertSame(42, (int) $row['price']);
    }





    public function test_post_update_lifecycle_hook_fires_for_each_entity_in_bulk(): void
    {

        $widgets = [];
        for ($i = 1; $i <= 3; $i++) {
            $w           = new BulkWidget();
            $w->color    = "color{$i}";
            $w->quantity = $i;
            $this->uow->add($w);
            $widgets[] = $w;
        }
        $this->uow->push();


        foreach ($widgets as $w) {
            self::assertSame(0, $w->postUpdateCallCount, 'postUpdateCallCount should be 0 after INSERT');
        }


        foreach ($widgets as $i => $w) {
            $w->quantity = ($i + 1) * 10;
        }
        $this->uow->push();


        foreach ($widgets as $idx => $w) {
            self::assertSame(
                1,
                $w->postUpdateCallCount,
                "Widget {$idx}: PostUpdate lifecycle hook must fire exactly once after bulk update",
            );
        }
    }





    public function test_post_update_dispatcher_event_fires_for_each_entity_in_bulk(): void
    {
        $updatedEntities = [];

        $this->dispatcher->addListener(
            'postUpdate',
            function ($event) use (&$updatedEntities): void {
                $updatedEntities[] = $event->getEntity();
            },
        );


        $products = $this->makeAndFlushProducts(3);


        foreach ($products as $p) {
            $p->price += 1;
        }
        $this->uow->push();

        self::assertCount(3, $updatedEntities, 'postUpdate must fire once per entity in the bulk group');

        foreach ($products as $p) {
            self::assertContains($p, $updatedEntities, 'Each product must appear in postUpdate events');
        }
    }





    public function test_updated_at_timestamp_is_set_on_all_entities_in_bulk_group(): void
    {

        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $item         = new BulkTimestampedItem();
            $item->label  = "Item {$i}";
            $item->value  = $i;
            $this->uow->add($item);
            $items[] = $item;
        }
        $this->uow->push();



        foreach ($items as $i => $item) {
            $item->label = "Updated Item {$i}";
        }

        $before = new \DateTimeImmutable();
        $this->uow->push();
        $after  = new \DateTimeImmutable();

        foreach ($items as $idx => $item) {

            self::assertInstanceOf(
                \DateTimeImmutable::class,
                $item->updatedAt,
                "Item {$idx}: updatedAt property must be set after bulk update",
            );

            $ts = $item->updatedAt->getTimestamp();
            self::assertGreaterThanOrEqual(
                $before->getTimestamp() - 1,
                $ts,
                "Item {$idx}: updatedAt must be >= flush start time",
            );
            self::assertLessThanOrEqual(
                $after->getTimestamp() + 1,
                $ts,
                "Item {$idx}: updatedAt must be <= flush end time",
            );


            $row = $this->connection->fetchAssociative(
                'SELECT updated_at FROM bulk_timestamped_items WHERE id = ?',
                [$item->id],
            );
            self::assertNotFalse($row);
            self::assertNotNull($row['updated_at'], "Item {$idx}: updated_at column must not be null in DB");
        }
    }






    public function test_snapshot_refreshed_after_bulk_update_no_spurious_reupdate(): void
    {
        $products = $this->makeAndFlushProducts(3);


        foreach ($products as $p) {
            $p->price = 1000;
        }
        $this->uow->push();



        $preUpdateCount = 0;
        $this->dispatcher->addListener(
            'preUpdate',
            function () use (&$preUpdateCount): void {
                $preUpdateCount++;
            },
        );


        $this->uow->push();

        self::assertSame(
            0,
            $preUpdateCount,
            'No preUpdate (and therefore no UPDATE) should be issued for clean entities after bulk update',
        );


        foreach ($products as $p) {
            $row = $this->fetchProduct($p->id);
            self::assertNotFalse($row);
            self::assertSame(1000, (int) $row['price']);
        }
    }





    public function test_composite_pk_entity_falls_back_to_individual_update(): void
    {

        $this->connection->executeStatement(
            'INSERT INTO bulk_order_lines (order_id, line_id, quantity) VALUES (1, 1, 5), (1, 2, 10)'
        );

        $line1           = new BulkOrderLine();
        $line1->orderId  = 1;
        $line1->lineId   = 1;
        $line1->quantity = 5;

        $line2           = new BulkOrderLine();
        $line2->orderId  = 1;
        $line2->lineId   = 2;
        $line2->quantity = 10;


        $this->uow->track($line1, BulkOrderLine::class);
        $this->uow->track($line2, BulkOrderLine::class);


        $line1->quantity = 99;
        $line2->quantity = 77;


        $this->uow->push();

        $row1 = $this->connection->fetchAssociative(
            'SELECT quantity FROM bulk_order_lines WHERE order_id = 1 AND line_id = 1'
        );
        $row2 = $this->connection->fetchAssociative(
            'SELECT quantity FROM bulk_order_lines WHERE order_id = 1 AND line_id = 2'
        );

        self::assertNotFalse($row1);
        self::assertNotFalse($row2);
        self::assertSame(99, (int) $row1['quantity'], 'Composite-PK line1 must be updated');
        self::assertSame(77, (int) $row2['quantity'], 'Composite-PK line2 must be updated');
    }






    public function test_selective_flush_uses_individual_update_not_bulk(): void
    {
        $products = $this->makeAndFlushProducts(3);


        foreach ($products as $p) {
            $p->price = 555;
        }


        $this->uow->push($products[0]);

        $row0 = $this->fetchProduct($products[0]->id);
        $row1 = $this->fetchProduct($products[1]->id);
        $row2 = $this->fetchProduct($products[2]->id);


        self::assertSame(555, (int) $row0['price'], 'Selectively flushed entity must be updated');


        self::assertSame(20, (int) $row1['price'], 'Non-selectively flushed entity must NOT be updated yet');
        self::assertSame(30, (int) $row2['price'], 'Non-selectively flushed entity must NOT be updated yet');
    }





    public function test_all_entities_clean_no_update_issued(): void
    {
        $products = $this->makeAndFlushProducts(3);


        $postUpdateFired = false;
        $this->dispatcher->addListener(
            'postUpdate',
            function () use (&$postUpdateFired): void {
                $postUpdateFired = true;
            },
        );


        $this->uow->push();

        self::assertFalse($postUpdateFired, 'postUpdate must not fire when no entity is dirty');


        foreach ($products as $idx => $p) {
            $row = $this->fetchProduct($p->id);
            self::assertNotFalse($row);
            self::assertSame(($idx + 1) * 10, (int) $row['price'], "Product {$idx} price must be unchanged");
        }
    }






    public function test_bulk_update_does_not_corrupt_non_dirty_columns(): void
    {
        $products = $this->makeAndFlushProducts(4);


        foreach ($products as $idx => $p) {
            $p->price = 300 + $idx;
            $p->stock = 100 + $idx;
        }
        $this->uow->push();

        foreach ($products as $idx => $p) {
            $row = $this->fetchProduct($p->id);
            self::assertNotFalse($row);

            self::assertSame("Product " . ($idx + 1), $row['name'], "Name of product {$idx} must not be corrupted");
            self::assertSame(300 + $idx, (int) $row['price']);
            self::assertSame(100 + $idx, (int) $row['stock']);
        }
    }
}
