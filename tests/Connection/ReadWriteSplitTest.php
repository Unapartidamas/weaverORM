<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Connection;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Connection\ReadWriteConnection;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Transaction\TransactionManager;

class RwProduct
{
    public ?int $id   = null;
    public string $name = '';
}

class RwProductMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return RwProduct::class;
    }

    public function getTableName(): string
    {
        return 'products';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string',  length: 100),
        ];
    }
}

function makeConn(): Connection
{
    $conn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
    $conn->executeStatement(
        'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT \'\')'
    );
    return $conn;
}

final class ReadWriteSplitTest extends TestCase
{





    public function test_entity_manager_exposes_read_and_write_connections_separately(): void
    {
        $writeConn = makeConn();
        $readConn  = makeConn();

        $rw     = new ReadWriteConnection($writeConn, $readConn);
        $mapper = new RwProductMapper();
        $registry = new MapperRegistry();
        $registry->register($mapper);
        $hydrator   = new EntityHydrator($registry, $writeConn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($writeConn, $registry, $hydrator, $dispatcher, $resolver);

        $workspace = new EntityWorkspace('default', $writeConn, $registry, $uow, $rw);

        self::assertSame($writeConn, $workspace->getWriteConnection());
        self::assertSame($readConn,  $workspace->getReadConnection());
    }

    public function test_query_builder_uses_read_connection_when_rw_configured(): void
    {
        $writeConn = makeConn();
        $readConn  = makeConn();


        $readConn->insert('products', ['name' => 'ReadOnly Item']);

        $rw = new ReadWriteConnection($writeConn, $readConn);

        $mapper   = new RwProductMapper();
        $registry = new MapperRegistry();
        $registry->register($mapper);
        $hydrator   = new EntityHydrator($registry, $writeConn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($writeConn, $registry, $hydrator, $dispatcher, $resolver);

        $workspace = new EntityWorkspace('default', $writeConn, $registry, $uow, $rw);


        $readHydrator = new EntityHydrator($registry, $readConn);
        $qb = new EntityQueryBuilder(
            connection:  $workspace->getReadConnection(),
            entityClass: RwProduct::class,
            mapper:      $mapper,
            hydrator:    $readHydrator,
        );

        $results = $qb->get();


        self::assertCount(1, $results);

        $product = $results->first();
        self::assertIsObject($product);
        self::assertSame('ReadOnly Item', $product->name);


        $count = $writeConn->fetchOne('SELECT COUNT(*) FROM products');
        self::assertSame('0', (string) $count);
    }





    public function test_unit_of_work_flush_uses_write_connection(): void
    {
        $writeConn = makeConn();
        $readConn  = makeConn();

        $mapper   = new RwProductMapper();
        $registry = new MapperRegistry();
        $registry->register($mapper);
        $hydrator   = new EntityHydrator($registry, $writeConn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);


        $uow = new UnitOfWork($writeConn, $registry, $hydrator, $dispatcher, $resolver);

        $rw = new ReadWriteConnection($writeConn, $readConn);
        $workspace = new EntityWorkspace('default', $writeConn, $registry, $uow, $rw);

        $product = new RwProduct();
        $product->name = 'WriteItem';

        $uow->add($product);
        $workspace->push();


        $writeCount = $writeConn->fetchOne('SELECT COUNT(*) FROM products');
        self::assertSame('1', (string) $writeCount);


        $readCount = $readConn->fetchOne('SELECT COUNT(*) FROM products');
        self::assertSame('0', (string) $readCount);
    }





    public function test_transaction_manager_uses_write_connection(): void
    {
        $writeConn = makeConn();
        $readConn  = makeConn();

        $rw = new ReadWriteConnection($writeConn, $readConn);

        $mapper   = new RwProductMapper();
        $registry = new MapperRegistry();
        $registry->register($mapper);
        $hydrator   = new EntityHydrator($registry, $writeConn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($writeConn, $registry, $hydrator, $dispatcher, $resolver);

        $workspace = new EntityWorkspace('default', $writeConn, $registry, $uow, $rw);


        $txManager = new TransactionManager($workspace->getWriteConnection());

        $txManager->transactional(function () use ($writeConn): void {
            $writeConn->insert('products', ['name' => 'TransactionItem']);
        });


        $writeCount = $writeConn->fetchOne('SELECT COUNT(*) FROM products');
        self::assertSame('1', (string) $writeCount);


        $readCount = $readConn->fetchOne('SELECT COUNT(*) FROM products');
        self::assertSame('0', (string) $readCount);


        self::assertFalse($txManager->isActive());
    }

    public function test_transaction_rollback_on_write_connection(): void
    {
        $writeConn = makeConn();
        $readConn  = makeConn();

        $rw        = new ReadWriteConnection($writeConn, $readConn);
        $txManager = new TransactionManager($rw->getWriteConnection());

        try {
            $txManager->transactional(function () use ($writeConn): never {
                $writeConn->insert('products', ['name' => 'ShouldRollBack']);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {

        }


        $writeCount = $writeConn->fetchOne('SELECT COUNT(*) FROM products');
        self::assertSame('0', (string) $writeCount);
    }





    public function test_single_connection_mode_read_returns_primary_connection(): void
    {
        $conn = makeConn();

        $mapper   = new RwProductMapper();
        $registry = new MapperRegistry();
        $registry->register($mapper);
        $hydrator   = new EntityHydrator($registry, $conn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($conn, $registry, $hydrator, $dispatcher, $resolver);


        $workspace = new EntityWorkspace('default', $conn, $registry, $uow);

        self::assertSame($conn, $workspace->getConnection());
        self::assertSame($conn, $workspace->getReadConnection());
        self::assertSame($conn, $workspace->getWriteConnection());
    }

    public function test_single_connection_mode_flush_works(): void
    {
        $conn = makeConn();

        $mapper   = new RwProductMapper();
        $registry = new MapperRegistry();
        $registry->register($mapper);
        $hydrator   = new EntityHydrator($registry, $conn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($conn, $registry, $hydrator, $dispatcher, $resolver);

        $workspace = new EntityWorkspace('default', $conn, $registry, $uow);

        $product = new RwProduct();
        $product->name = 'SingleConnItem';

        $uow->add($product);
        $workspace->push();

        $count = $conn->fetchOne('SELECT COUNT(*) FROM products');
        self::assertSame('1', (string) $count);
    }

    public function test_single_connection_mode_query_builder_works(): void
    {
        $conn = makeConn();
        $conn->insert('products', ['name' => 'Solo']);

        $mapper   = new RwProductMapper();
        $registry = new MapperRegistry();
        $registry->register($mapper);
        $hydrator = new EntityHydrator($registry, $conn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($conn, $registry, $hydrator, $dispatcher, $resolver);

        $workspace = new EntityWorkspace('default', $conn, $registry, $uow);

        $qb = new EntityQueryBuilder(
            connection:  $workspace->getReadConnection(),
            entityClass: RwProduct::class,
            mapper:      $mapper,
            hydrator:    $hydrator,
        );

        $results = $qb->get();
        self::assertCount(1, $results);
    }
}
