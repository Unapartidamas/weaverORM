<?php
declare(strict_types=1);
namespace Weaver\ORM\Testing;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Relation\RelationLoader;
use Weaver\ORM\Schema\SchemaGenerator;

abstract class WeaverTestCase extends TestCase
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
            'path' => ':memory:',
        ]);

        $this->registry = new MapperRegistry();
        $this->registerMappers();

        $this->hydrator    = new EntityHydrator($this->registry, $this->connection);
        $dispatcher        = new LifecycleEventDispatcher();
        $resolver          = new InsertOrderResolver($this->registry);

        $this->unitOfWork = new UnitOfWork(
            $this->connection,
            $this->registry,
            $this->hydrator,
            $dispatcher,
            $resolver,
        );

        $this->relationLoader = new RelationLoader(
            $this->connection,
            $this->registry,
            $this->hydrator,
        );

        $this->createSchema();
    }

    protected function registerMappers(): void {}

    protected function createSchema(): void
    {
        $generator = new SchemaGenerator($this->registry, $this->connection->getDatabasePlatform());
        $statements = $generator->generateSql();

        foreach ($statements as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    protected function assertDatabaseHas(string $table, array $criteria): void
    {
        $params = [];
        $conditions = [];
        foreach ($criteria as $col => $val) {
            $conditions[] = "{$col} = ?";
            $params[] = $val;
        }
        $sql = 'SELECT COUNT(*) FROM ' . $table;
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $raw = $this->connection->fetchOne($sql, $params);
        $count = is_numeric($raw) ? (int) $raw : 0;
        self::assertGreaterThan(0, $count, "Table '{$table}' has no row matching " . json_encode($criteria));
    }

    protected function assertDatabaseMissing(string $table, array $criteria): void
    {
        $params = [];
        $conditions = [];
        foreach ($criteria as $col => $val) {
            $conditions[] = "{$col} = ?";
            $params[] = $val;
        }
        $sql = 'SELECT COUNT(*) FROM ' . $table;
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $raw = $this->connection->fetchOne($sql, $params);
        $count = is_numeric($raw) ? (int) $raw : 0;
        self::assertEquals(0, $count, "Table '{$table}' has a row matching " . json_encode($criteria) . ' but expected none');
    }

    protected function assertDatabaseCount(string $table, int $expected): void
    {
        $raw = $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
        $count = is_numeric($raw) ? (int) $raw : 0;
        self::assertEquals($expected, $count, "Table '{$table}' has {$count} rows, expected {$expected}");
    }

    protected function assertSoftDeleted(string $table, array $criteria): void
    {
        $params = [];
        $conditions = ['deleted_at IS NOT NULL'];
        foreach ($criteria as $col => $val) {
            $conditions[] = "{$col} = ?";
            $params[] = $val;
        }
        $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode(' AND ', $conditions);
        $raw = $this->connection->fetchOne($sql, $params);
        $count = is_numeric($raw) ? (int) $raw : 0;
        self::assertGreaterThan(0, $count, "No soft-deleted row in '{$table}' matching " . json_encode($criteria));
    }
}
