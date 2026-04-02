<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Persistence;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\CompositeKey;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class OrderItemEntity
{
    public int $orderId   = 0;
    public int $productId = 0;
    public int $quantity  = 0;
}

final class OrderItemEntityMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return OrderItemEntity::class;
    }

    public function getTableName(): string
    {
        return 'order_items';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('order_id',   'orderId',   'integer', primary: true,  autoIncrement: false),
            new ColumnDefinition('product_id', 'productId', 'integer', primary: true,  autoIncrement: false),
            new ColumnDefinition('quantity',   'quantity',  'integer'),
        ];
    }


    public function getPrimaryKeyColumns(): array
    {
        return ['order_id', 'product_id'];
    }
}

final class CompositePkPersistenceTest extends TestCase
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
            'CREATE TABLE order_items (
                order_id   INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                quantity   INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (order_id, product_id)
            )'
        );

        $this->registry = new MapperRegistry();
        $this->registry->register(new OrderItemEntityMapper());

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



    public function test_insert_composite_pk_entity(): void
    {
        $item            = new OrderItemEntity();
        $item->orderId   = 1;
        $item->productId = 10;
        $item->quantity  = 5;

        $this->uow->add($item);
        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM order_items WHERE order_id = ? AND product_id = ?',
            [1, 10],
        );

        self::assertNotFalse($row);
        self::assertSame(1,  (int) $row['order_id']);
        self::assertSame(10, (int) $row['product_id']);
        self::assertSame(5,  (int) $row['quantity']);
    }

    public function test_update_composite_pk_entity_uses_both_pk_columns(): void
    {

        $this->connection->executeStatement(
            'INSERT INTO order_items (order_id, product_id, quantity) VALUES (1, 10, 5)',
        );
        $this->connection->executeStatement(
            'INSERT INTO order_items (order_id, product_id, quantity) VALUES (1, 20, 3)',
        );


        $row = $this->connection->fetchAssociative(
            'SELECT * FROM order_items WHERE order_id = 1 AND product_id = 10',
        );
        self::assertNotFalse($row);

        $item            = new OrderItemEntity();
        $item->orderId   = (int) $row['order_id'];
        $item->productId = (int) $row['product_id'];
        $item->quantity  = (int) $row['quantity'];


        $this->uow->track($item, OrderItemEntity::class);

        $item->quantity = 99;

        $this->uow->push();


        $updated = $this->connection->fetchAssociative(
            'SELECT quantity FROM order_items WHERE order_id = 1 AND product_id = 10',
        );
        self::assertNotFalse($updated);
        self::assertSame(99, (int) $updated['quantity']);


        $other = $this->connection->fetchAssociative(
            'SELECT quantity FROM order_items WHERE order_id = 1 AND product_id = 20',
        );
        self::assertNotFalse($other);
        self::assertSame(3, (int) $other['quantity']);
    }

    public function test_delete_composite_pk_entity(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO order_items (order_id, product_id, quantity) VALUES (2, 30, 7)',
        );
        $this->connection->executeStatement(
            'INSERT INTO order_items (order_id, product_id, quantity) VALUES (2, 40, 2)',
        );

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM order_items WHERE order_id = 2 AND product_id = 30',
        );
        self::assertNotFalse($row);

        $item            = new OrderItemEntity();
        $item->orderId   = (int) $row['order_id'];
        $item->productId = (int) $row['product_id'];
        $item->quantity  = (int) $row['quantity'];


        $this->uow->track($item, OrderItemEntity::class);
        $this->uow->delete($item);
        $this->uow->push();

        $deleted = $this->connection->fetchAssociative(
            'SELECT * FROM order_items WHERE order_id = 2 AND product_id = 30',
        );
        self::assertFalse($deleted);


        $surviving = $this->connection->fetchAssociative(
            'SELECT * FROM order_items WHERE order_id = 2 AND product_id = 40',
        );
        self::assertNotFalse($surviving);
        self::assertSame(2, (int) $surviving['quantity']);
    }

    public function test_extract_composite_key_from_entity(): void
    {
        $mapper = new OrderItemEntityMapper();

        $item            = new OrderItemEntity();
        $item->orderId   = 5;
        $item->productId = 15;

        $key = $mapper->extractCompositeKey($item);

        self::assertSame(['order_id' => 5, 'product_id' => 15], $key->toArray());
    }
}
