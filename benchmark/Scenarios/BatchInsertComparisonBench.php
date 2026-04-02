<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Scenarios;

use Weaver\ORM\DBAL\Connection;
use Weaver\Benchmark\Fixtures\BenchUser;
use Weaver\Benchmark\Fixtures\BenchUserMapper;
use Weaver\Benchmark\OrmBenchmark;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

final class BatchInsertComparisonBench
{
    private const BATCH_SIZE = 100;

    public function run(OrmBenchmark $bench, Connection $conn, \PDO $pdo, int $iterations): void
    {
        $registry = new MapperRegistry();
        $registry->register(new BenchUserMapper());
        $hydrator = new EntityHydrator($registry, $conn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver = new InsertOrderResolver($registry);

        $bench->measure('Batch INSERT (100 rows)', 'Weaver ORM', function (int $i) use ($conn, $registry, $hydrator, $dispatcher, $resolver) {
            $uow = new UnitOfWork($conn, $registry, $hydrator, $dispatcher, $resolver);
            $workspace = new EntityWorkspace('bench', $conn, $registry, $uow);

            for ($j = 0; $j < self::BATCH_SIZE; $j++) {
                $user = new BenchUser();
                $user->name = 'User ' . $j;
                $user->email = 'u' . $j . '@batch.test';
                $user->age = 20 + ($j % 50);
                $user->status = 'active';
                $workspace->add($user);
            }
            $workspace->push();
            $conn->executeStatement('DELETE FROM bench_users');
        }, $iterations);

        $bench->measure('Batch INSERT (100 rows)', 'Raw DBAL', function (int $i) use ($conn) {
            $conn->beginTransaction();
            for ($j = 0; $j < self::BATCH_SIZE; $j++) {
                $conn->insert('bench_users', [
                    'name' => 'User ' . $j,
                    'email' => 'u' . $j . '@batch.test',
                    'age' => 20 + ($j % 50),
                    'status' => 'active',
                ]);
            }
            $conn->commit();
            $conn->executeStatement('DELETE FROM bench_users');
        }, $iterations);

        $stmt = $pdo->prepare('INSERT INTO bench_users (name, email, age, status) VALUES (?, ?, ?, ?)');
        $deleteStmt = $pdo->prepare('DELETE FROM bench_users');

        $bench->measure('Batch INSERT (100 rows)', 'Raw PDO', function (int $i) use ($pdo, $stmt, $deleteStmt) {
            $pdo->beginTransaction();
            for ($j = 0; $j < self::BATCH_SIZE; $j++) {
                $stmt->execute(['User ' . $j, 'u' . $j . '@batch.test', 20 + ($j % 50), 'active']);
            }
            $pdo->commit();
            $deleteStmt->execute();
        }, $iterations);
    }
}
