<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

final class WhereLikeTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private ItemMapper $mapper;
    private EntityQueryBuilder $qb;

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

        $registry = new MapperRegistry();
        $this->mapper = new ItemMapper();
        $registry->register($this->mapper);
        $hydrator = new EntityHydrator($registry, $this->connection);

        $this->qb = new EntityQueryBuilder(
            $this->connection,
            Item::class,
            $this->mapper,
            $hydrator,
        );
    }

    private function seed(): void
    {
        foreach (['foobar', 'foobaz', 'barbaz', 'Azimuth'] as $name) {
            $this->connection->insert('items', [
                'name'     => $name,
                'price'    => 1,
                'category' => 'test',
                'tag'      => null,
            ]);
        }
    }

    private function makeQb(): EntityQueryBuilder
    {
        $registry = new MapperRegistry();
        $mapper   = new ItemMapper();
        $registry->register($mapper);
        $hydrator = new EntityHydrator($registry, $this->connection);

        return new EntityQueryBuilder(
            $this->connection,
            Item::class,
            $mapper,
            $hydrator,
        );
    }





    public function test_where_like_returns_matching_rows(): void
    {
        $this->seed();

        $result = $this->makeQb()->whereLike('name', 'foo%')->get();

        self::assertCount(2, $result);
        foreach ($result as $item) {
            self::assertStringStartsWith('foo', $item->name);
        }
    }





    public function test_where_not_like_excludes_matching_rows(): void
    {
        $this->seed();

        $result = $this->makeQb()->whereNotLike('name', 'foo%')->get();


        self::assertCount(2, $result);
        foreach ($result as $item) {
            self::assertStringNotContainsString('foo', substr($item->name, 0, 3));
        }
    }





    public function test_where_i_like_matches_case_insensitively(): void
    {
        $this->seed();


        $result = $this->makeQb()->whereILike('name', 'FOO%')->get();

        self::assertCount(2, $result);
        foreach ($result as $item) {
            self::assertStringStartsWith('foo', strtolower($item->name));
        }
    }





    public function test_where_starts_with_returns_rows_starting_with_value(): void
    {
        $this->seed();

        $result = $this->makeQb()->whereStartsWith('name', 'foo')->get();

        self::assertCount(2, $result);
        foreach ($result as $item) {
            self::assertStringStartsWith('foo', $item->name);
        }
    }





    public function test_where_ends_with_returns_rows_ending_with_value(): void
    {
        $this->seed();

        $result = $this->makeQb()->whereEndsWith('name', 'baz')->get();


        self::assertCount(2, $result);
        foreach ($result as $item) {
            self::assertStringEndsWith('baz', $item->name);
        }
    }





    public function test_where_contains_returns_rows_containing_value(): void
    {
        $this->seed();

        $result = $this->makeQb()->whereContains('name', 'oo')->get();


        self::assertCount(2, $result);
        foreach ($result as $item) {
            self::assertStringContainsString('oo', $item->name);
        }
    }





    public function test_chaining_starts_with_and_ends_with(): void
    {
        $this->seed();


        $this->connection->insert('items', [
            'name'     => 'Abyz',
            'price'    => 1,
            'category' => 'test',
            'tag'      => null,
        ]);

        $result = $this->makeQb()
            ->whereStartsWith('name', 'A')
            ->whereEndsWith('name', 'z')
            ->get();


        self::assertCount(1, $result);
        self::assertSame('Abyz', $result->first()->name);
    }
}
