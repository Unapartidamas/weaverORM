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

class WcUser
{
    public ?int $id   = null;
    public string $name = '';
}

class WcUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return WcUser::class;
    }

    public function getTableName(): string
    {
        return 'wc_users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string',  length: 100),
        ];
    }
}

final class WithCountTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private WcUserMapper $mapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE wc_users (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT    NOT NULL DEFAULT \'\'
            )',
        );

        $this->connection->executeStatement(
            'CREATE TABLE wc_posts (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title   TEXT    NOT NULL DEFAULT \'\'
            )',
        );

        $this->registry = new MapperRegistry();
        $this->mapper   = new WcUserMapper();
        $this->registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }





    private function makeQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            WcUser::class,
            $this->mapper,
            $this->hydrator,
        );
    }

    private function insertUser(string $name): int
    {
        $this->connection->insert('wc_users', ['name' => $name]);

        return (int) $this->connection->lastInsertId();
    }

    private function insertPost(int $userId, string $title): void
    {
        $this->connection->insert('wc_posts', ['user_id' => $userId, 'title' => $title]);
    }







    public function testWithCountSetsDynamicPropertyOnEachEntity(): void
    {
        $alice = $this->insertUser('Alice');
        $bob   = $this->insertUser('Bob');

        $this->insertPost($alice, 'Post A1');
        $this->insertPost($alice, 'Post A2');
        $this->insertPost($bob,   'Post B1');

        $users = $this->makeQb()
            ->withCount('posts_count', 'wc_posts', 'user_id')
            ->orderBy('id', 'ASC')
            ->get();

        self::assertCount(2, $users);

        $items = $users->toArray();
        self::assertSame(2, $items[0]->posts_count);
        self::assertSame(1, $items[1]->posts_count);
    }



    public function testUserWithNoPostsGetsCountZero(): void
    {
        $this->insertUser('NoPostsUser');

        $users = $this->makeQb()
            ->withCount('posts_count', 'wc_posts', 'user_id')
            ->get();

        self::assertCount(1, $users);

        $user = $users->first();
        self::assertNotNull($user);
        self::assertSame(0, $user->posts_count);
    }



    public function testMultipleWithCountCallsWork(): void
    {

        $this->connection->executeStatement(
            'CREATE TABLE wc_comments (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                body    TEXT    NOT NULL DEFAULT \'\'
            )',
        );

        $alice = $this->insertUser('Alice');
        $bob   = $this->insertUser('Bob');

        $this->insertPost($alice, 'Post A1');
        $this->insertPost($alice, 'Post A2');
        $this->connection->insert('wc_comments', ['user_id' => $alice, 'body' => 'Comment A1']);

        $this->insertPost($bob, 'Post B1');
        $this->connection->insert('wc_comments', ['user_id' => $bob, 'body' => 'Comment B1']);
        $this->connection->insert('wc_comments', ['user_id' => $bob, 'body' => 'Comment B2']);

        $users = $this->makeQb()
            ->withCount('posts_count', 'wc_posts', 'user_id')
            ->withCount('comments_count', 'wc_comments', 'user_id')
            ->orderBy('id', 'ASC')
            ->get();

        self::assertCount(2, $users);

        $items = $users->toArray();


        self::assertSame(2, $items[0]->posts_count);
        self::assertSame(1, $items[0]->comments_count);


        self::assertSame(1, $items[1]->posts_count);
        self::assertSame(2, $items[1]->comments_count);
    }



    public function testWithCountCombinedWithWhereFilter(): void
    {
        $alice = $this->insertUser('Alice');
        $bob   = $this->insertUser('Bob');

        $this->insertPost($alice, 'Post A1');
        $this->insertPost($alice, 'Post A2');
        $this->insertPost($alice, 'Post A3');
        $this->insertPost($bob,   'Post B1');


        $users = $this->makeQb()
            ->withCount('posts_count', 'wc_posts', 'user_id')
            ->where('name', '=', 'Alice')
            ->get();

        self::assertCount(1, $users);

        $alice = $users->first();
        self::assertNotNull($alice);
        self::assertSame(3, $alice->posts_count);
    }



    public function testCountIsCorrectAfterInsertingAdditionalRelatedRows(): void
    {
        $userId = $this->insertUser('Charlie');


        $users = $this->makeQb()
            ->withCount('posts_count', 'wc_posts', 'user_id')
            ->get();

        self::assertSame(0, $users->first()->posts_count);


        $this->insertPost($userId, 'Charlie Post 1');
        $this->insertPost($userId, 'Charlie Post 2');
        $this->insertPost($userId, 'Charlie Post 3');


        $users = $this->makeQb()
            ->withCount('posts_count', 'wc_posts', 'user_id')
            ->get();

        self::assertSame(3, $users->first()->posts_count);
    }
}
