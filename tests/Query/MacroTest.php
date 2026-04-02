<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use BadMethodCallException;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

final class MacroTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private ItemMapper $mapper;
    private EntityHydrator $hydrator;

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

        $registry      = new MapperRegistry();
        $this->mapper  = new ItemMapper();
        $registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($registry, $this->connection);
    }

    protected function tearDown(): void
    {
        EntityQueryBuilder::flushMacros();
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





    public function test_registered_macro_is_callable(): void
    {
        EntityQueryBuilder::macro('noop', function (): static {
            return $this;
        });

        $qb     = $this->makeQb();
        $result = $qb->noop();

        self::assertSame($qb, $result);
    }





    public function test_macro_receives_additional_arguments(): void
    {
        $received = null;

        EntityQueryBuilder::macro('captureArg', function (int $val) use (&$received): static {
            $received = $val;

            return $this;
        });

        $this->makeQb()->captureArg(99);

        self::assertSame(99, $received);
    }





    public function test_macro_can_chain(): void
    {
        EntityQueryBuilder::macro('chainA', function (): static {
            return $this;
        });

        EntityQueryBuilder::macro('chainB', function (): static {
            return $this;
        });

        $qb     = $this->makeQb();
        $result = $qb->chainA()->chainB();

        self::assertSame($qb, $result);
    }





    public function test_has_macro_returns_true_when_registered(): void
    {
        EntityQueryBuilder::macro('exists', function (): static {
            return $this;
        });

        self::assertTrue(EntityQueryBuilder::hasMacro('exists'));
    }

    public function test_has_macro_returns_false_when_not_registered(): void
    {
        self::assertFalse(EntityQueryBuilder::hasMacro('nonExistentMacro'));
    }





    public function test_calling_unknown_method_throws_bad_method_call_exception(): void
    {
        $this->expectException(BadMethodCallException::class);

        $this->makeQb()->undefinedMethod();
    }





    public function test_flush_macros_clears_all_registered_macros(): void
    {
        EntityQueryBuilder::macro('first',  function (): static { return $this; });
        EntityQueryBuilder::macro('second', function (): static { return $this; });

        self::assertTrue(EntityQueryBuilder::hasMacro('first'));
        self::assertTrue(EntityQueryBuilder::hasMacro('second'));

        EntityQueryBuilder::flushMacros();

        self::assertFalse(EntityQueryBuilder::hasMacro('first'));
        self::assertFalse(EntityQueryBuilder::hasMacro('second'));
    }





    public function test_macro_accessing_this_where_filters_results(): void
    {
        $this->connection->insert('items', ['name' => 'Active',   'price' => 10,  'category' => 'book', 'tag' => null]);
        $this->connection->insert('items', ['name' => 'Inactive', 'price' => 20,  'category' => 'book', 'tag' => null]);
        $this->connection->insert('items', ['name' => 'Active2',  'price' => 30,  'category' => 'book', 'tag' => null]);

        EntityQueryBuilder::macro('filterByName', function (string $name): static {
            return $this->where('name', $name);
        });

        $results = $this->makeQb()->filterByName('Active')->get();

        self::assertCount(1, $results);
        self::assertSame('Active', $results->first()->name);
    }





    public function test_multiple_macros_can_be_registered_and_used_independently(): void
    {
        $this->connection->insert('items', ['name' => 'A', 'price' => 10,  'category' => 'alpha', 'tag' => null]);
        $this->connection->insert('items', ['name' => 'B', 'price' => 20,  'category' => 'beta',  'tag' => null]);
        $this->connection->insert('items', ['name' => 'C', 'price' => 30,  'category' => 'alpha', 'tag' => null]);

        EntityQueryBuilder::macro('inCategory', function (string $cat): static {
            return $this->where('category', $cat);
        });

        EntityQueryBuilder::macro('minPrice', function (int $min): static {
            return $this->where('price', '>=', $min);
        });

        $alphas = $this->makeQb()->inCategory('alpha')->get();
        self::assertCount(2, $alphas);

        $expensive = $this->makeQb()->minPrice(20)->get();
        self::assertCount(2, $expensive);

        $expensiveAlphas = $this->makeQb()->inCategory('alpha')->minPrice(20)->get();
        self::assertCount(1, $expensiveAlphas);
        self::assertSame('C', $expensiveAlphas->first()->name);
    }
}
