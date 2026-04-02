<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Mapping\RelationType;
use Weaver\ORM\Query\EntityQueryBuilder;

class ImplicitUser
{
    public ?int $id   = null;
    public string $name = '';
    public int $age   = 0;
}

class ImplicitPost
{
    public ?int $id      = null;
    public string $title  = '';
    public ?int $user_id = null;
}

class ImplicitUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return ImplicitUser::class; }
    public function getTableName(): string   { return 'implicit_users'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string',  length: 100),
            new ColumnDefinition('age',  'age',  'integer'),
        ];
    }
}

class ImplicitPostMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return ImplicitPost::class; }
    public function getTableName(): string   { return 'implicit_posts'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',      'id',      'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title',   'title',   'string',  length: 200),
            new ColumnDefinition('user_id', 'user_id', 'integer', nullable: true),
        ];
    }

    public function getRelations(): array
    {
        return [
            new RelationDefinition(
                property:      'user',
                type:          RelationType::BelongsTo,
                relatedEntity: ImplicitUser::class,
                relatedMapper: ImplicitUserMapper::class,
                foreignKey:    'user_id',
                ownerKey:      'id',
            ),
        ];
    }
}

final class ImplicitJoinTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private ImplicitPostMapper $postMapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE implicit_users (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT    NOT NULL DEFAULT \'\',
                age  INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE implicit_posts (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                title   TEXT    NOT NULL DEFAULT \'\',
                user_id INTEGER NULL
            )'
        );

        $this->registry   = new MapperRegistry();
        $this->postMapper = new ImplicitPostMapper();
        $userMapper       = new ImplicitUserMapper();
        $this->registry->register($this->postMapper);
        $this->registry->register($userMapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }

    private function makePostQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            ImplicitPost::class,
            $this->postMapper,
            $this->hydrator,
        );
    }

    private function insertUser(string $name, int $age): int
    {
        $this->connection->executeStatement(
            'INSERT INTO implicit_users (name, age) VALUES (:name, :age)',
            ['name' => $name, 'age' => $age],
        );

        return (int) $this->connection->lastInsertId();
    }

    private function insertPost(string $title, ?int $userId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO implicit_posts (title, user_id) VALUES (:title, :user_id)',
            ['title' => $title, 'user_id' => $userId],
        );
    }





    public function testWhereOnRelationColumnAutoJoins(): void
    {
        $aliceId = $this->insertUser('Alice', 30);
        $bobId   = $this->insertUser('Bob', 25);

        $this->insertPost('Post by Alice', $aliceId);
        $this->insertPost('Post by Bob',   $bobId);

        $sql = $this->makePostQb()
            ->where('user.name', 'Alice')
            ->toSQL();

        self::assertStringContainsString('LEFT JOIN', $sql);
        self::assertStringContainsString('implicit_users', $sql);
        self::assertStringContainsString('rel_user', $sql);
    }





    public function testAutoJoinNotDuplicated(): void
    {
        $aliceId = $this->insertUser('Alice', 30);
        $this->insertPost('Post A', $aliceId);

        $sql = $this->makePostQb()
            ->where('user.name', 'Alice')
            ->where('user.name', 'Alice')
            ->toSQL();


        self::assertSame(1, substr_count($sql, 'implicit_users'));
    }





    public function testOrderByWithImplicitJoin(): void
    {
        $aliceId = $this->insertUser('Alice', 30);
        $bobId   = $this->insertUser('Bob', 25);

        $this->insertPost('Post by Alice', $aliceId);
        $this->insertPost('Post by Bob',   $bobId);

        $sql = $this->makePostQb()
            ->orderBy('user.name')
            ->toSQL();

        self::assertStringContainsString('LEFT JOIN', $sql);
        self::assertStringContainsString('rel_user.name', $sql);
        self::assertStringContainsString('ORDER BY', $sql);
    }





    public function testMultipleConditionsSameRelationShareJoin(): void
    {
        $aliceId = $this->insertUser('Alice', 30);
        $this->insertPost('Post A', $aliceId);

        $sql = $this->makePostQb()
            ->where('user.name', 'Alice')
            ->where('user.age', '>', 18)
            ->toSQL();


        self::assertSame(1, substr_count($sql, 'implicit_users'));
        self::assertStringContainsString('rel_user.name', $sql);
        self::assertStringContainsString('rel_user.age', $sql);
    }





    public function testReturnsCorrectEntities(): void
    {
        $aliceId = $this->insertUser('Alice', 30);
        $bobId   = $this->insertUser('Bob', 25);

        $this->insertPost('Alice Post 1', $aliceId);
        $this->insertPost('Alice Post 2', $aliceId);
        $this->insertPost('Bob Post',     $bobId);


        $posts = $this->makePostQb()
            ->where('user.name', 'Alice')
            ->get()
            ->toArray();

        self::assertCount(2, $posts);

        foreach ($posts as $post) {
            self::assertStringContainsString('Alice', $post->title);
        }
    }





    public function testExplicitJoinAndImplicitWhereCoexist(): void
    {
        $aliceId = $this->insertUser('Alice', 30);
        $this->insertPost('Alice Post', $aliceId);



        $sql = $this->makePostQb()
            ->join('implicit_users', 'u_explicit', 'u_explicit.id = e.user_id')
            ->where('user.name', 'Alice')
            ->toSQL();

        self::assertStringContainsString('u_explicit', $sql);
        self::assertStringContainsString('rel_user', $sql);
        self::assertStringContainsString('rel_user.name', $sql);
    }





    public function testWhereInWithImplicitJoin(): void
    {
        $aliceId = $this->insertUser('Alice', 30);
        $bobId   = $this->insertUser('Bob', 25);
        $carolId = $this->insertUser('Carol', 22);

        $this->insertPost('Alice Post', $aliceId);
        $this->insertPost('Bob Post',   $bobId);
        $this->insertPost('Carol Post', $carolId);


        $posts = $this->makePostQb()
            ->whereIn('user.name', ['Alice', 'Bob'])
            ->get()
            ->toArray();

        self::assertCount(2, $posts);
        $titles = array_map(fn ($p) => $p->title, $posts);
        self::assertContains('Alice Post', $titles);
        self::assertContains('Bob Post', $titles);
    }
}
