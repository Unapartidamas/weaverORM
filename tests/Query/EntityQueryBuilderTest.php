<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Exception\EntityNotFoundException;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

class Item
{
    public ?int $id       = null;
    public string $name    = '';
    public int $price      = 0;
    public string $category = '';
    public ?string $tag    = null;
}

class ItemMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return Item::class;
    }

    public function getTableName(): string
    {
        return 'items';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',       'id',       'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',     'name',     'string',  length: 100),
            new ColumnDefinition('price',    'price',    'integer'),
            new ColumnDefinition('category', 'category', 'string',  length: 50),
            new ColumnDefinition('tag',      'tag',      'string',  nullable: true, length: 50),
        ];
    }
}

final class EntityQueryBuilderTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private ItemMapper $mapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE items (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                name     TEXT    NOT NULL DEFAULT \'\',
                price    INTEGER NOT NULL DEFAULT 0,
                category TEXT    NOT NULL DEFAULT \'\',
                tag      TEXT    NULL
            )'
        );

        $this->registry = new MapperRegistry();
        $this->mapper   = new ItemMapper();
        $this->registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }





    private function makeQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            Item::class,
            $this->mapper,
            $this->hydrator,
        );
    }

    private function seed(): void
    {
        $this->connection->insert('items', ['name' => 'Alpha',   'price' => 10,  'category' => 'book',       'tag' => null]);
        $this->connection->insert('items', ['name' => 'Beta',    'price' => 100, 'category' => 'electronic', 'tag' => 'sale']);
        $this->connection->insert('items', ['name' => 'Gamma',   'price' => 50,  'category' => 'book',       'tag' => 'sale']);
    }



    public function test_get_returns_all_rows(): void
    {
        $this->seed();

        $result = $this->makeQb()->get();

        self::assertInstanceOf(EntityCollection::class, $result);
        self::assertCount(3, $result);
    }

    public function test_where_equality_filters_results(): void
    {
        $this->seed();

        $books = $this->makeQb()->where('category', 'book')->get();

        self::assertCount(2, $books);
        foreach ($books as $item) {
            self::assertInstanceOf(Item::class, $item);
            self::assertSame('book', $item->category);
        }
    }

    public function test_where_with_operator(): void
    {
        $this->seed();

        $expensive = $this->makeQb()->where('price', '>', 50)->get();

        self::assertCount(1, $expensive);
        self::assertSame(100, $expensive->first()->price);
    }

    public function test_where_closure_groups_conditions(): void
    {
        $this->seed();


        $result = $this->makeQb()
            ->where(static fn (EntityQueryBuilder $q) =>
                $q->where('category', 'book')->orWhere('price', '>', 90)
            )
            ->get();


        self::assertCount(3, $result);
    }

    public function test_or_where(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->where('name', 'Alpha')
            ->orWhere('name', 'Beta')
            ->get();

        self::assertCount(2, $result);
        $names = $result->pluck('name');
        sort($names);
        self::assertSame(['Alpha', 'Beta'], $names);
    }

    public function test_first_returns_first_entity_or_null(): void
    {
        $this->seed();

        $first = $this->makeQb()->orderBy('id', 'ASC')->first();
        self::assertNotNull($first);
        self::assertInstanceOf(Item::class, $first);
        self::assertSame('Alpha', $first->name);


        $this->connection->executeStatement('DELETE FROM items');
        $nullResult = $this->makeQb()->first();
        self::assertNull($nullResult);
    }

    public function test_first_or_fail_throws_when_empty(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->makeQb()->firstOrFail();
    }

    public function test_count_returns_integer(): void
    {
        $this->seed();

        $count = $this->makeQb()->count();

        self::assertIsInt($count);
        self::assertSame(3, $count);
    }

    public function test_count_with_where(): void
    {
        $this->seed();

        $count = $this->makeQb()->where('category', 'electronic')->count();

        self::assertSame(1, $count);
    }

    public function test_exists_returns_true_and_false(): void
    {
        $this->seed();

        self::assertTrue($this->makeQb()->exists());
        self::assertFalse($this->makeQb()->exists() === false);

        self::assertFalse($this->makeQb()->where('name', 'NonExistent')->exists());
        self::assertTrue($this->makeQb()->where('name', 'NonExistent')->doesntExist());
    }

    public function test_pluck_returns_flat_array(): void
    {
        $this->seed();

        $names = $this->makeQb()->orderBy('name', 'ASC')->pluck('name');

        self::assertSame(['Alpha', 'Beta', 'Gamma'], $names);
    }

    public function test_order_by(): void
    {
        $this->seed();

        $first = $this->makeQb()->orderBy('price', 'DESC')->first();

        self::assertNotNull($first);
        self::assertSame(100, $first->price);
    }

    public function test_limit_and_offset(): void
    {
        $this->seed();


        $results = $this->makeQb()
            ->orderBy('id', 'ASC')
            ->limit(2)
            ->offset(1)
            ->get();

        self::assertCount(2, $results);

        self::assertSame('Beta', $results->first()->name);
    }

    public function test_paginate_calculates_pages_correctly(): void
    {

        for ($i = 1; $i <= 5; $i++) {
            $this->connection->insert('items', ['name' => "Item{$i}", 'price' => $i * 10, 'category' => 'test', 'tag' => null]);
        }

        $page = $this->makeQb()->paginate(1, 2);

        self::assertSame(5, $page->total);
        self::assertSame(3, $page->lastPage);
        self::assertSame(2, $page->items->count());
    }

    public function test_paginate_page2_returns_correct_items(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->connection->insert('items', ['name' => "Item{$i}", 'price' => $i * 10, 'category' => 'test', 'tag' => null]);
        }

        $page = $this->makeQb()->orderBy('id', 'ASC')->paginate(2, 2);

        self::assertSame(2, $page->currentPage);
        self::assertCount(2, $page->items);

        $names = $page->items->pluck('name');
        self::assertSame(['Item3', 'Item4'], $names);
    }

    public function test_cursor_yields_all_entities(): void
    {
        $this->seed();

        $yielded = [];
        foreach ($this->makeQb()->cursor() as $entity) {
            $yielded[] = $entity;
        }

        self::assertCount(3, $yielded);
        foreach ($yielded as $entity) {
            self::assertInstanceOf(Item::class, $entity);
        }
    }

    public function test_chunk_processes_in_batches(): void
    {
        $this->seed();

        $batchCount = 0;
        $totalItems = 0;

        $this->makeQb()->chunk(2, static function (EntityCollection $batch) use (&$batchCount, &$totalItems): void {
            $batchCount++;
            $totalItems += $batch->count();
        });


        self::assertSame(2, $batchCount);
        self::assertSame(3, $totalItems);
    }

    public function test_aggregate_sum(): void
    {
        $this->seed();

        $sum = $this->makeQb()->sum('price');

        self::assertSame(160, (int) $sum);
    }

    public function test_aggregate_max(): void
    {
        $this->seed();

        $max = $this->makeQb()->max('price');

        self::assertSame('100', (string) $max);
    }

    public function test_in_random_order_does_not_crash(): void
    {
        $this->seed();

        $result = $this->makeQb()->inRandomOrder()->get();

        self::assertCount(3, $result);
    }

    public function test_where_in(): void
    {
        $this->seed();

        $result = $this->makeQb()->whereIn('id', [1, 2])->get();

        self::assertCount(2, $result);
    }

    public function test_where_null_and_not_null(): void
    {
        $this->seed();


        $nullItems = $this->makeQb()->whereNull('tag')->get();
        self::assertCount(1, $nullItems);
        self::assertSame('Alpha', $nullItems->first()->name);

        $notNullItems = $this->makeQb()->whereNotNull('tag')->get();
        self::assertCount(2, $notNullItems);
    }

    public function test_when_condition_applies_callback_when_true(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->when(true, static fn (EntityQueryBuilder $q) => $q->where('category', 'book'))
            ->get();

        self::assertCount(2, $result);
    }

    public function test_when_condition_skips_callback_when_false(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->when(false, static fn (EntityQueryBuilder $q) => $q->where('category', 'book'))
            ->get();

        self::assertCount(3, $result);
    }

    public function test_to_sql_returns_string(): void
    {
        $sql = $this->makeQb()->toSQL();

        self::assertIsString($sql);
        self::assertStringContainsStringIgnoringCase('SELECT', $sql);
        self::assertStringContainsString('items', $sql);
    }
}
