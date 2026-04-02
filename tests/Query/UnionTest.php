<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

class UnionProduct
{
    public ?int $id       = null;
    public string $name   = '';
    public int $price     = 0;
    public string $type   = '';
}

class UnionProductMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return UnionProduct::class;
    }

    public function getTableName(): string
    {
        return 'union_products';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',  'name',  'string',  length: 100),
            new ColumnDefinition('price', 'price', 'integer'),
            new ColumnDefinition('type',  'type',  'string',  length: 50),
        ];
    }
}

final class UnionTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private UnionProductMapper $mapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE union_products (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                name  TEXT    NOT NULL DEFAULT \'\',
                price INTEGER NOT NULL DEFAULT 0,
                type  TEXT    NOT NULL DEFAULT \'\'
            )'
        );

        $this->registry = new MapperRegistry();
        $this->mapper   = new UnionProductMapper();
        $this->registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }





    private function makeQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            UnionProduct::class,
            $this->mapper,
            $this->hydrator,
        );
    }

    private function seed(): void
    {

        $this->connection->insert('union_products', ['name' => 'Widget', 'price' => 10, 'type' => 'a']);
        $this->connection->insert('union_products', ['name' => 'Gadget', 'price' => 20, 'type' => 'b']);
        $this->connection->insert('union_products', ['name' => 'Donut',  'price' => 5,  'type' => 'c']);
    }





    public function test_union_deduplicates_rows(): void
    {
        $this->seed();


        $result = $this->makeQb()
            ->select('name')
            ->where('name', 'Widget')
            ->union(
                'SELECT name FROM union_products WHERE name = ?',
                ['Widget'],
            )
            ->fetchRaw();

        self::assertCount(1, $result);
        self::assertSame('Widget', $result[0]['name']);
    }





    public function test_union_all_keeps_duplicates(): void
    {
        $this->seed();


        $result = $this->makeQb()
            ->select('name')
            ->where('name', 'Widget')
            ->unionAll(
                'SELECT name FROM union_products WHERE name = ?',
                ['Widget'],
            )
            ->fetchRaw();

        self::assertCount(2, $result);
        self::assertSame('Widget', $result[0]['name']);
        self::assertSame('Widget', $result[1]['name']);
    }





    public function test_union_with_where_on_base_query(): void
    {
        $this->seed();


        $result = $this->makeQb()
            ->select('name')
            ->where('type', 'a')
            ->union(
                'SELECT name FROM union_products WHERE type = ?',
                ['b'],
            )
            ->fetchRaw();

        $names = array_column($result, 'name');
        sort($names);

        self::assertCount(2, $result);
        self::assertSame(['Gadget', 'Widget'], $names);
    }





    public function test_union_with_where_on_unioned_query(): void
    {
        $this->seed();


        $result = $this->makeQb()
            ->select('name')
            ->where('type', 'a')
            ->union(
                'SELECT name FROM union_products WHERE price > ?',
                [15],
            )
            ->fetchRaw();

        $names = array_column($result, 'name');
        sort($names);


        self::assertCount(2, $result);
        self::assertSame(['Gadget', 'Widget'], $names);
    }





    public function test_union_result_is_hydrated_into_entities(): void
    {
        $this->seed();

        $collection = $this->makeQb()
            ->where('type', 'a')
            ->union(
                'SELECT * FROM union_products WHERE type = ?',
                ['b'],
            )
            ->get();

        self::assertInstanceOf(EntityCollection::class, $collection);
        self::assertCount(2, $collection);

        foreach ($collection as $item) {
            self::assertInstanceOf(UnionProduct::class, $item);
        }

        $names = [];
        foreach ($collection as $item) {

            $names[] = $item->name;
        }
        sort($names);

        self::assertSame(['Gadget', 'Widget'], $names);
    }





    public function test_multiple_unions_chained(): void
    {
        $this->seed();




        $result = $this->makeQb()
            ->select('name')
            ->where('type', 'a')
            ->union(
                'SELECT name FROM union_products WHERE type = ?',
                ['b'],
            )
            ->unionAll(
                'SELECT name FROM union_products WHERE name = ?',
                ['Widget'],
            )
            ->fetchRaw();



        self::assertCount(3, $result);

        $names = array_column($result, 'name');
        sort($names);
        self::assertSame(['Gadget', 'Widget', 'Widget'], $names);
    }





    public function test_empty_base_with_non_empty_union_returns_union_results(): void
    {
        $this->seed();



        $result = $this->makeQb()
            ->select('name')
            ->where('type', 'does_not_exist')
            ->union(
                'SELECT name FROM union_products WHERE price > ?',
                [0],
            )
            ->fetchRaw();

        self::assertCount(3, $result);

        $names = array_column($result, 'name');
        sort($names);
        self::assertSame(['Donut', 'Gadget', 'Widget'], $names);
    }





    public function test_union_closure_receives_dbal_query_builder(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->select('name')
            ->where('type', 'a')
            ->union(static function (\Weaver\ORM\DBAL\QueryBuilder $qb): void {
                $qb->select('name')
                    ->from('union_products')
                    ->where('type = :t')
                    ->setParameter('t', 'b');
            })
            ->fetchRaw();

        $names = array_column($result, 'name');
        sort($names);

        self::assertCount(2, $result);
        self::assertSame(['Gadget', 'Widget'], $names);
    }
}
