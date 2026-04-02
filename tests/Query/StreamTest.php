<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

class StreamItem
{
    public ?int $id       = null;
    public string $name    = '';
    public int $price      = 0;
    public string $category = '';
}

class StreamItemMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return StreamItem::class;
    }

    public function getTableName(): string
    {
        return 'stream_items';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',       'id',       'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',     'name',     'string',  length: 100),
            new ColumnDefinition('price',    'price',    'integer'),
            new ColumnDefinition('category', 'category', 'string',  length: 50),
        ];
    }
}

final class StreamTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private StreamItemMapper $mapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE stream_items (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                name     TEXT    NOT NULL DEFAULT \'\',
                price    INTEGER NOT NULL DEFAULT 0,
                category TEXT    NOT NULL DEFAULT \'\'
            )'
        );

        $this->registry = new MapperRegistry();
        $this->mapper   = new StreamItemMapper();
        $this->registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }





    private function makeQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            StreamItem::class,
            $this->mapper,
            $this->hydrator,
        );
    }

    private function seed(int $count = 3): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->connection->insert('stream_items', [
                'name'     => 'Item' . $i,
                'price'    => $i * 10,
                'category' => $i % 2 === 0 ? 'even' : 'odd',
            ]);
        }
    }





    public function test_stream_yields_all_entities(): void
    {
        $this->seed(3);

        $generator = $this->makeQb()->stream();

        self::assertInstanceOf(\Generator::class, $generator);

        $entities = iterator_to_array($generator);

        self::assertCount(3, $entities);

        foreach ($entities as $entity) {
            self::assertInstanceOf(StreamItem::class, $entity);
        }
    }





    public function test_stream_with_where_filter_yields_matching_entities(): void
    {
        $this->seed(6);

        $generator = $this->makeQb()->where('category', 'odd')->stream();

        $entities = iterator_to_array($generator);


        self::assertCount(3, $entities);

        foreach ($entities as $entity) {
            self::assertInstanceOf(StreamItem::class, $entity);
            self::assertSame('odd', $entity->category);
        }
    }





    public function test_stream_on_empty_table_yields_nothing(): void
    {

        $generator = $this->makeQb()->stream();

        $entities = iterator_to_array($generator);

        self::assertCount(0, $entities);
    }





    public function test_stream_batched_yields_all_entities_across_batches(): void
    {
        $this->seed(10);


        $generator = $this->makeQb()->streamBatched(3);

        self::assertInstanceOf(\Generator::class, $generator);

        $entities = iterator_to_array($generator);

        self::assertCount(10, $entities);

        foreach ($entities as $entity) {
            self::assertInstanceOf(StreamItem::class, $entity);
        }
    }





    public function test_stream_batched_with_where_filter_yields_matching_entities(): void
    {
        $this->seed(10);


        $generator = $this->makeQb()->where('category', 'odd')->streamBatched(2);

        $entities = iterator_to_array($generator);

        self::assertCount(5, $entities);

        foreach ($entities as $entity) {
            self::assertInstanceOf(StreamItem::class, $entity);
            self::assertSame('odd', $entity->category);
        }
    }





    public function test_stream_batched_on_empty_table_yields_nothing(): void
    {
        $generator = $this->makeQb()->streamBatched(100);

        $entities = iterator_to_array($generator);

        self::assertCount(0, $entities);
    }





    public function test_stream_returns_generator_instance(): void
    {
        $result = $this->makeQb()->stream();

        self::assertInstanceOf(\Generator::class, $result);
    }





    public function test_stream_batched_batch_size_larger_than_total_rows(): void
    {
        $this->seed(5);

        $generator = $this->makeQb()->streamBatched(1000);

        $entities = iterator_to_array($generator);

        self::assertCount(5, $entities);
    }
}
