<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Relation\RelationLoader;
use Weaver\ORM\Schema\SchemaGenerator;
use Weaver\ORM\Tests\Fixture\Mapper\CommentMapper;
use Weaver\ORM\Tests\Fixture\Mapper\PostMapper;
use Weaver\ORM\Tests\Fixture\Mapper\ProfileMapper;
use Weaver\ORM\Tests\Fixture\Mapper\UserMapper;

abstract class WeaverIntegrationTestCase extends TestCase
{
    protected Connection $connection;
    protected MapperRegistry $registry;
    protected EntityHydrator $hydrator;
    protected UnitOfWork $unitOfWork;
    protected RelationLoader $relationLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->registry = new MapperRegistry();
        $this->registry->register(new UserMapper());
        $this->registry->register(new PostMapper());
        $this->registry->register(new ProfileMapper());
        $this->registry->register(new CommentMapper());

        $this->hydrator       = new EntityHydrator($this->registry, $this->connection);
        $dispatcher           = new LifecycleEventDispatcher();
        $resolver             = new InsertOrderResolver($this->registry);
        $this->unitOfWork     = new UnitOfWork($this->connection, $this->registry, $this->hydrator, $dispatcher, $resolver);
        $this->relationLoader = new RelationLoader($this->connection, $this->registry, $this->hydrator);

        $generator = new SchemaGenerator($this->registry, $this->connection->getDatabasePlatform());
        foreach ($generator->generateSql() as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    protected function makeQueryBuilder(string $entityClass): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            $entityClass,
            $this->registry->get($entityClass),
            $this->hydrator,
            $this->relationLoader,
        );
    }

    protected function seedUsers(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO users (email, name, role, active) VALUES
            ('alice@example.com', 'Alice', 'admin', 1),
            ('bob@example.com',   'Bob',   'user',  1),
            ('carol@example.com', 'Carol', 'user',  0)"
        );
    }

    protected function seedPosts(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO posts (title, status, user_id) VALUES
            ('Hello World', 'published', 1),
            ('Draft Post',  'draft',     1),
            ('Bobs Post',   'published', 2)"
        );
    }

    protected function assertDatabaseHas(string $table, array $criteria): void
    {
        $conditions = [];
        $params = [];
        foreach ($criteria as $col => $val) {
            $conditions[] = "{$col} = ?";
            $params[] = $val;
        }
        $sql = "SELECT COUNT(*) FROM {$table}";
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        self::assertGreaterThan(
            0,
            (int) $this->connection->fetchOne($sql, $params),
            "No row in '{$table}' matching " . json_encode($criteria)
        );
    }

    protected function assertDatabaseCount(string $table, int $expected): void
    {
        $count = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$table}");
        self::assertSame($expected, $count, "Table '{$table}' has {$count} rows, expected {$expected}");
    }
}
