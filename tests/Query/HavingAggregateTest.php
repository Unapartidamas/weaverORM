<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\ConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

class HavingEntry
{
    public ?int $id      = null;
    public string $status = '';
    public int $value    = 0;
}

class HavingEntryMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return HavingEntry::class;
    }

    public function getTableName(): string
    {
        return 'having_entries';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',     'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('status', 'status', 'string',  length: 50),
            new ColumnDefinition('value',  'value',  'integer'),
        ];
    }
}

final class HavingAggregateTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private HavingEntryMapper $mapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE having_entries (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                status TEXT    NOT NULL DEFAULT \'\',
                value  INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->registry = new MapperRegistry();
        $this->mapper   = new HavingEntryMapper();
        $this->registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }





    private function makeQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            HavingEntry::class,
            $this->mapper,
            $this->hydrator,
        );
    }



    private function seed(): void
    {
        $rows = [
            ['status' => 'active',  'value' => 10],
            ['status' => 'active',  'value' => 20],
            ['status' => 'active',  'value' => 30],
            ['status' => 'active',  'value' => 40],
            ['status' => 'pending', 'value' => 5],
            ['status' => 'pending', 'value' => 200],
            ['status' => 'closed',  'value' => 50],
        ];

        foreach ($rows as $row) {
            $this->connection->insert('having_entries', $row);
        }
    }






    public function test_havingCount_filters_groups_with_more_than_two_rows(): void
    {
        $this->seed();


        $rows = $this->makeQb()
            ->select('status', 'COUNT(id) AS cnt')
            ->groupBy('status')
            ->havingCount('id', '>', 2)
            ->fetchRaw();

        self::assertCount(1, $rows);
        self::assertSame('active', $rows[0]['status']);
        self::assertSame('4', (string) $rows[0]['cnt']);
    }


    public function test_havingSum_filters_groups_by_sum(): void
    {
        $this->seed();


        $rows = $this->makeQb()
            ->select('status', 'SUM(value) AS total')
            ->groupBy('status')
            ->havingSum('value', '>=', 100)
            ->fetchRaw();

        self::assertCount(2, $rows);
        $statuses = array_column($rows, 'status');
        sort($statuses);
        self::assertSame(['active', 'pending'], $statuses);
    }


    public function test_havingAvg_filters_groups_by_average(): void
    {
        $this->seed();


        $rows = $this->makeQb()
            ->select('status', 'AVG(value) AS avg_val')
            ->groupBy('status')
            ->havingAvg('value', '<', 50)
            ->fetchRaw();

        self::assertCount(1, $rows);
        self::assertSame('active', $rows[0]['status']);
    }


    public function test_havingMin_filters_groups_by_minimum(): void
    {
        $this->seed();


        $this->connection->insert('having_entries', ['status' => 'special', 'value' => 1]);
        $this->connection->insert('having_entries', ['status' => 'special', 'value' => 99]);


        $rows = $this->makeQb()
            ->select('status', 'MIN(value) AS min_val')
            ->groupBy('status')
            ->havingMin('value', '=', 1)
            ->fetchRaw();

        self::assertCount(1, $rows);
        self::assertSame('special', $rows[0]['status']);
    }


    public function test_havingMax_filters_groups_by_maximum(): void
    {
        $this->seed();


        $rows = $this->makeQb()
            ->select('status', 'MAX(value) AS max_val')
            ->groupBy('status')
            ->havingMax('value', '<', 100)
            ->fetchRaw();

        self::assertCount(2, $rows);
        $statuses = array_column($rows, 'status');
        sort($statuses);
        self::assertSame(['active', 'closed'], $statuses);
    }


    public function test_invalid_operator_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid HAVING operator/');

        $this->makeQb()->havingCount('id', 'LIKE', 5);
    }


    public function test_invalid_operator_on_havingSum_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeQb()->havingSum('value', 'BETWEEN', 10);
    }


    public function test_orHavingRaw_appends_or_having_expression(): void
    {
        $this->seed();




        $rows = $this->makeQb()
            ->select('status', 'COUNT(id) AS cnt')
            ->groupBy('status')
            ->havingRaw('COUNT(id) > 10')
            ->orHavingRaw('COUNT(id) = 1')
            ->fetchRaw();


        self::assertCount(1, $rows);
        self::assertSame('closed', $rows[0]['status']);
    }


    public function test_havingBetween_filters_by_range(): void
    {
        $this->seed();









        $this->connection->executeStatement('DELETE FROM having_entries');
        $this->connection->insert('having_entries', ['status' => 'low',  'value' => 5]);
        $this->connection->insert('having_entries', ['status' => 'mid',  'value' => 50]);
        $this->connection->insert('having_entries', ['status' => 'high', 'value' => 500]);



        $rows = $this->makeQb()
            ->select('status', 'value')
            ->groupBy('status', 'value')
            ->havingBetween('value', 1, 100)
            ->fetchRaw();

        self::assertCount(2, $rows);
        $statuses = array_column($rows, 'status');
        sort($statuses);
        self::assertSame(['low', 'mid'], $statuses);
    }
}
