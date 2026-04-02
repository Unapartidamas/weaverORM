<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\ConnectionFactory;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\DBAL\QueryBuilder as DbalQb;
use Weaver\ORM\Query\EntityQueryBuilder;

class SelectSubUser
{
    public ?int    $id   = null;
    public string  $name = '';
    public int     $age  = 0;
}

class SelectSubUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return SelectSubUser::class;
    }

    public function getTableName(): string
    {
        return 'ss_users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string',  length: 100),
            new ColumnDefinition('age',  'age',  'integer'),
        ];
    }
}

final class SelectSubTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private SelectSubUserMapper       $mapper;
    private EntityHydrator            $hydrator;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE ss_users (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT    NOT NULL DEFAULT \'\',
                age  INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE ss_posts (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title   TEXT    NOT NULL DEFAULT \'\'
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE ss_admins (
                user_id INTEGER NOT NULL
            )'
        );

        $registry = new MapperRegistry();
        $this->mapper = new SelectSubUserMapper();
        $registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($registry, $this->connection);
    }





    private function makeQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            SelectSubUser::class,
            $this->mapper,
            $this->hydrator,
        );
    }

    private function seedUsers(): void
    {

        $this->connection->insert('ss_users', ['name' => 'Alice', 'age' => 20]);
        $this->connection->insert('ss_users', ['name' => 'Bob',   'age' => 30]);
        $this->connection->insert('ss_users', ['name' => 'Carol', 'age' => 40]);
    }

    private function seedPosts(): void
    {

        $this->connection->insert('ss_posts', ['user_id' => 1, 'title' => 'Post A1']);
        $this->connection->insert('ss_posts', ['user_id' => 1, 'title' => 'Post A2']);
        $this->connection->insert('ss_posts', ['user_id' => 2, 'title' => 'Post B1']);
    }

    private function seedAdmins(): void
    {

        $this->connection->insert('ss_admins', ['user_id' => 2]);
    }







    public function test_selectSub_adds_count_column(): void
    {
        $this->seedUsers();
        $this->seedPosts();

        $rows = $this->makeQb()
            ->selectSub(
                'SELECT COUNT(*) FROM ss_posts WHERE ss_posts.user_id = e.id',
                'posts_count'
            )
            ->orderBy('e.id', 'ASC')
            ->fetchRaw();

        self::assertCount(3, $rows);

        self::assertSame('2', (string) $rows[0]['posts_count']);
        self::assertSame('1', (string) $rows[1]['posts_count']);
        self::assertSame('0', (string) $rows[2]['posts_count']);
    }



    public function test_selectSub_value_accessible_via_fetchRaw(): void
    {
        $this->seedUsers();
        $this->seedPosts();

        $rows = $this->makeQb()
            ->selectSub(
                'SELECT COUNT(*) FROM ss_posts WHERE ss_posts.user_id = e.id',
                'posts_count'
            )
            ->where('e.name', 'Alice')
            ->fetchRaw();

        self::assertCount(1, $rows);
        self::assertSame('2', (string) $rows[0]['posts_count']);
        self::assertSame('Alice', $rows[0]['name']);
    }



    public function test_addSelectSub_preserves_existing_columns(): void
    {
        $this->seedUsers();
        $this->seedPosts();


        $rows = $this->makeQb()
            ->select('e.id', 'e.name')
            ->addSelectSub(
                'SELECT COUNT(*) FROM ss_posts WHERE ss_posts.user_id = e.id',
                'posts_count'
            )
            ->orderBy('e.id', 'ASC')
            ->fetchRaw();

        self::assertCount(3, $rows);


        self::assertArrayHasKey('id',          $rows[0]);
        self::assertArrayHasKey('name',        $rows[0]);
        self::assertArrayHasKey('posts_count', $rows[0]);


        self::assertSame('Alice', $rows[0]['name']);
        self::assertSame('2',     (string) $rows[0]['posts_count']);
        self::assertSame('Bob',   $rows[1]['name']);
        self::assertSame('1',     (string) $rows[1]['posts_count']);
    }



    public function test_whereInSubquery_filters_correctly(): void
    {
        $this->seedUsers();
        $this->seedAdmins();

        $admins = $this->makeQb()
            ->whereInSubquery('e.id', 'SELECT user_id FROM ss_admins')
            ->fetchRaw();

        self::assertCount(1, $admins);
        self::assertSame('Bob', $admins[0]['name']);
    }



    public function test_whereNotInSubquery_excludes_matching_rows(): void
    {
        $this->seedUsers();
        $this->seedAdmins();

        $nonAdmins = $this->makeQb()
            ->whereNotInSubquery('e.id', 'SELECT user_id FROM ss_admins')
            ->orderBy('e.id', 'ASC')
            ->fetchRaw();

        self::assertCount(2, $nonAdmins);
        $names = array_column($nonAdmins, 'name');
        self::assertSame(['Alice', 'Carol'], $names);
    }



    public function test_whereSubquery_with_aggregate_comparison(): void
    {
        $this->seedUsers();


        $aboveAverage = $this->makeQb()
            ->whereSubquery('e.age', '>', 'SELECT AVG(age) FROM ss_users')
            ->fetchRaw();

        self::assertCount(1, $aboveAverage);
        self::assertSame('Carol', $aboveAverage[0]['name']);
    }



    public function test_selectSub_with_closure(): void
    {
        $this->seedUsers();
        $this->seedPosts();

        $rows = $this->makeQb()
            ->selectSub(
                static function (DbalQb $qb): void {
                    $qb->select('COUNT(*)')
                       ->from('ss_posts')
                       ->where('ss_posts.user_id = e.id');
                },
                'posts_count'
            )
            ->orderBy('e.id', 'ASC')
            ->fetchRaw();

        self::assertCount(3, $rows);
        self::assertSame('2', (string) $rows[0]['posts_count']);
        self::assertSame('1', (string) $rows[1]['posts_count']);
        self::assertSame('0', (string) $rows[2]['posts_count']);
    }



    public function test_whereInSubquery_with_closure(): void
    {
        $this->seedUsers();
        $this->seedAdmins();

        $admins = $this->makeQb()
            ->whereInSubquery(
                'e.id',
                static function (DbalQb $qb): void {
                    $qb->select('user_id')->from('ss_admins');
                }
            )
            ->fetchRaw();

        self::assertCount(1, $admins);
        self::assertSame('Bob', $admins[0]['name']);
    }
}
