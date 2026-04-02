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

class EventEntity
{
    public ?int $id         = null;
    public string $title    = '';
    public string $created_at = '';
}

class EventMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return EventEntity::class;
    }

    public function getTableName(): string
    {
        return 'events';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',         'id',         'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title',      'title',      'string',  length: 100),
            new ColumnDefinition('created_at', 'created_at', 'string',  length: 50),
        ];
    }
}

final class WhereDateTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private EventMapper $mapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE events (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                title      TEXT    NOT NULL DEFAULT \'\',
                created_at TEXT    NOT NULL DEFAULT \'\'
            )'
        );

        $this->registry = new MapperRegistry();
        $this->mapper   = new EventMapper();
        $this->registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }





    private function makeQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            EventEntity::class,
            $this->mapper,
            $this->hydrator,
        );
    }

    private function seed(): void
    {

        $this->connection->insert('events', ['title' => 'Morning Meeting',  'created_at' => '2024-01-15 08:30:00']);

        $this->connection->insert('events', ['title' => 'Afternoon Session', 'created_at' => '2024-01-15 14:45:00']);

        $this->connection->insert('events', ['title' => 'March Event',       'created_at' => '2024-03-15 12:00:00']);

        $this->connection->insert('events', ['title' => 'Next Year Event',   'created_at' => '2025-03-20 09:00:00']);

        $this->connection->insert('events', ['title' => 'June Night',        'created_at' => '2024-06-05 23:59:00']);
    }






    public function test_whereDate_equality_returns_matching_rows(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->whereDate('created_at', '=', '2024-01-15')
            ->get();

        self::assertInstanceOf(EntityCollection::class, $result);
        self::assertCount(2, $result);

        $titles = array_map(static fn (EventEntity $e) => $e->title, $result->toArray());
        self::assertContains('Morning Meeting', $titles);
        self::assertContains('Afternoon Session', $titles);
    }


    public function test_whereDate_greater_than_returns_rows_after_date(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->whereDate('created_at', '>', '2024-01-01')
            ->get();


        self::assertCount(5, $result);
    }


    public function test_whereYear_equality_returns_rows_from_year(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->whereYear('created_at', '=', 2024)
            ->get();


        self::assertCount(4, $result);

        $titles = array_map(static fn (EventEntity $e) => $e->title, $result->toArray());
        self::assertNotContains('Next Year Event', $titles);
    }


    public function test_whereMonth_equality_returns_rows_from_march(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->whereMonth('created_at', '=', 3)
            ->get();


        self::assertCount(2, $result);

        $titles = array_map(static fn (EventEntity $e) => $e->title, $result->toArray());
        self::assertContains('March Event', $titles);
        self::assertContains('Next Year Event', $titles);
    }


    public function test_whereDay_equality_returns_rows_on_day_15(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->whereDay('created_at', '=', 15)
            ->get();


        self::assertCount(3, $result);

        $titles = array_map(static fn (EventEntity $e) => $e->title, $result->toArray());
        self::assertContains('Morning Meeting', $titles);
        self::assertContains('Afternoon Session', $titles);
        self::assertContains('March Event', $titles);
    }


    public function test_whereTime_gte_noon_returns_afternoon_rows(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->whereTime('created_at', '>=', '12:00:00')
            ->get();


        self::assertCount(3, $result);

        $titles = array_map(static fn (EventEntity $e) => $e->title, $result->toArray());
        self::assertContains('Afternoon Session', $titles);
        self::assertContains('March Event', $titles);
        self::assertContains('June Night', $titles);
        self::assertNotContains('Morning Meeting', $titles);
    }


    public function test_chaining_whereYear_and_whereMonth_works(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->whereYear('created_at', '=', 2024)
            ->whereMonth('created_at', '=', 3)
            ->get();


        self::assertCount(1, $result);
        self::assertSame('March Event', $result->toArray()[0]->title);
    }


    public function test_no_rows_matched_returns_empty_collection(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->whereDate('created_at', '=', '2000-01-01')
            ->get();

        self::assertInstanceOf(EntityCollection::class, $result);
        self::assertCount(0, $result);
        self::assertTrue($result->isEmpty());
    }


    public function test_whereDate_accepts_DateTimeInterface(): void
    {
        $this->seed();

        $dt = new \DateTimeImmutable('2024-03-15');

        $result = $this->makeQb()
            ->whereDate('created_at', '=', $dt)
            ->get();

        self::assertCount(1, $result);
        self::assertSame('March Event', $result->toArray()[0]->title);
    }


    public function test_whereYear_not_equal_excludes_year(): void
    {
        $this->seed();

        $result = $this->makeQb()
            ->whereYear('created_at', '!=', 2025)
            ->get();


        self::assertCount(4, $result);

        $titles = array_map(static fn (EventEntity $e) => $e->title, $result->toArray());
        self::assertNotContains('Next Year Event', $titles);
    }


    public function test_invalid_operator_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeQb()->whereDate('created_at', 'LIKE', '2024-01-15');
    }
}
