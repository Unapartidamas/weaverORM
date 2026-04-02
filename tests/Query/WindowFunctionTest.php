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

class WinUser
{
    public ?int $id      = null;
    public string $name   = '';
    public string $status = '';
    public int $age       = 0;
    public int $value     = 0;
}

class WinUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return WinUser::class;
    }

    public function getTableName(): string
    {
        return 'win_users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',     'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',   'name',   'string',  length: 100),
            new ColumnDefinition('status', 'status', 'string',  length: 50),
            new ColumnDefinition('age',    'age',    'integer'),
            new ColumnDefinition('value',  'value',  'integer'),
        ];
    }
}

final class WindowFunctionTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private WinUserMapper $mapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE win_users (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                name   TEXT    NOT NULL DEFAULT \'\',
                status TEXT    NOT NULL DEFAULT \'\',
                age    INTEGER NOT NULL DEFAULT 0,
                value  INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->registry = new MapperRegistry();
        $this->mapper   = new WinUserMapper();
        $this->registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);


        $this->connection->insert('win_users', ['name' => 'Alice',   'status' => 'active',   'age' => 30, 'value' => 100]);
        $this->connection->insert('win_users', ['name' => 'Bob',     'status' => 'inactive', 'age' => 25, 'value' => 200]);
        $this->connection->insert('win_users', ['name' => 'Charlie', 'status' => 'active',   'age' => 35, 'value' => 150]);
        $this->connection->insert('win_users', ['name' => 'Dave',    'status' => 'inactive', 'age' => 28, 'value' => 300]);
        $this->connection->insert('win_users', ['name' => 'Eve',     'status' => 'active',   'age' => 22, 'value' => 50]);
    }





    private function makeQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            WinUser::class,
            $this->mapper,
            $this->hydrator,
        );
    }






    public function testRowNumberWithoutPartition(): void
    {
        $rows = $this->makeQb()
            ->rowNumber('rn', orderBy: 'id')
            ->orderBy('id', 'ASC')
            ->fetchRaw();

        $this->assertCount(5, $rows);

        foreach ($rows as $i => $row) {
            $this->assertArrayHasKey('rn', $row);
            $this->assertSame((string) ($i + 1), (string) $row['rn']);
        }
    }


    public function testRowNumberWithPartition(): void
    {
        $rows = $this->makeQb()
            ->rowNumber('rn', partitionBy: 'status', orderBy: 'id')
            ->orderBy('status', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->fetchRaw();

        $this->assertCount(5, $rows);


        $byStatus = [];

        foreach ($rows as $row) {
            $byStatus[$row['status']][] = (int) $row['rn'];
        }


        foreach ($byStatus as $status => $rns) {
            sort($rns);
            $expected = range(1, count($rns));
            $this->assertSame($expected, $rns, "ROW_NUMBER in group '{$status}' should be sequential from 1");
        }
    }


    public function testRankWithinGroups(): void
    {
        $rows = $this->makeQb()
            ->rank('r', partitionBy: 'status', orderBy: 'age')
            ->orderBy('status', 'ASC')
            ->addOrderBy('age', 'ASC')
            ->fetchRaw();

        $this->assertCount(5, $rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('r', $row);
            $rank = (int) $row['r'];
            $this->assertGreaterThanOrEqual(1, $rank);
        }


        $active = array_filter($rows, fn($r) => $r['status'] === 'active');
        $activeRanks = array_column(array_values($active), 'r');
        $activeRanks = array_map('intval', $activeRanks);
        sort($activeRanks);
        $this->assertSame([1, 2, 3], $activeRanks);
    }


    public function testLagGetsPreviousValue(): void
    {
        $rows = $this->makeQb()
            ->lag('age', 'prev_age', orderBy: 'id')
            ->orderBy('id', 'ASC')
            ->fetchRaw();

        $this->assertCount(5, $rows);


        $this->assertNull($rows[0]['prev_age']);


        $this->assertSame('30', (string) $rows[1]['prev_age']);


        $this->assertSame('25', (string) $rows[2]['prev_age']);
    }


    public function testLeadGetsNextValue(): void
    {
        $rows = $this->makeQb()
            ->lead('age', 'next_age', orderBy: 'id')
            ->orderBy('id', 'ASC')
            ->fetchRaw();

        $this->assertCount(5, $rows);


        $this->assertNull($rows[4]['next_age']);


        $this->assertSame('25', (string) $rows[0]['next_age']);


        $this->assertSame('35', (string) $rows[1]['next_age']);
    }


    public function testSumOverRunningTotal(): void
    {
        $rows = $this->makeQb()
            ->sumOver('value', 'running_total', orderBy: 'id')
            ->orderBy('id', 'ASC')
            ->fetchRaw();

        $this->assertCount(5, $rows);



        $expectedTotals = [100, 300, 450, 750, 800];

        foreach ($rows as $i => $row) {
            $this->assertArrayHasKey('running_total', $row);
            $this->assertSame($expectedTotals[$i], (int) $row['running_total']);
        }
    }


    public function testSelectWindowGenericMethod(): void
    {
        $rows = $this->makeQb()
            ->selectWindow('DENSE_RANK', 'dr', partitionBy: 'status', orderBy: 'age')
            ->orderBy('status', 'ASC')
            ->addOrderBy('age', 'ASC')
            ->fetchRaw();

        $this->assertCount(5, $rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('dr', $row);
            $this->assertGreaterThanOrEqual(1, (int) $row['dr']);
        }
    }


    public function testDenseRank(): void
    {
        $rows = $this->makeQb()
            ->denseRank('dr', partitionBy: 'status', orderBy: 'age')
            ->orderBy('status', 'ASC')
            ->addOrderBy('age', 'ASC')
            ->fetchRaw();

        $this->assertCount(5, $rows);


        $active = array_values(array_filter($rows, fn($r) => $r['status'] === 'active'));
        $this->assertSame(1, (int) $active[0]['dr']);
        $this->assertSame(2, (int) $active[1]['dr']);
        $this->assertSame(3, (int) $active[2]['dr']);
    }


    public function testAvgOver(): void
    {
        $rows = $this->makeQb()
            ->avgOver('value', 'avg_val', partitionBy: 'status')
            ->orderBy('status', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->fetchRaw();

        $this->assertCount(5, $rows);



        foreach ($rows as $row) {
            $this->assertArrayHasKey('avg_val', $row);

            if ($row['status'] === 'active') {
                $this->assertSame(100.0, (float) $row['avg_val']);
            } else {
                $this->assertSame(250.0, (float) $row['avg_val']);
            }
        }
    }


    public function testCountOver(): void
    {
        $rows = $this->makeQb()
            ->countOver('id', 'cnt', partitionBy: 'status')
            ->orderBy('status', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->fetchRaw();

        $this->assertCount(5, $rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('cnt', $row);

            if ($row['status'] === 'active') {
                $this->assertSame(3, (int) $row['cnt']);
            } else {
                $this->assertSame(2, (int) $row['cnt']);
            }
        }
    }
}
