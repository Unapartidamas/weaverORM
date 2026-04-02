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

final class InsertComparisonBench
{
    public function run(OrmBenchmark $bench, Connection $conn, \PDO $pdo, int $iterations): void
    {
        $registry = new MapperRegistry();
        $registry->register(new BenchUserMapper());
        $hydrator = new EntityHydrator($registry, $conn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver = new InsertOrderResolver($registry);

        $bench->measure('Single INSERT', 'Weaver ORM', function (int $i) use ($conn, $registry, $hydrator, $dispatcher, $resolver) {
            $uow = new UnitOfWork($conn, $registry, $hydrator, $dispatcher, $resolver);
            $workspace = new EntityWorkspace('bench', $conn, $registry, $uow);

            $user = new BenchUser();
            $user->name = 'User ' . $i;
            $user->email = 'user' . $i . '@bench.test';
            $user->age = 25;
            $user->status = 'active';

            $workspace->add($user);
            $workspace->push();

            $conn->executeStatement('DELETE FROM bench_users');
        }, $iterations);

        $bench->measure('Single INSERT', 'Raw DBAL', function (int $i) use ($conn) {
            $conn->insert('bench_users', [
                'name' => 'User ' . $i,
                'email' => 'user' . $i . '@bench.test',
                'age' => 25,
                'status' => 'active',
            ]);
            $conn->executeStatement('DELETE FROM bench_users');
        }, $iterations);

        $stmt = $pdo->prepare('INSERT INTO bench_users (name, email, age, status) VALUES (?, ?, ?, ?)');
        $deleteStmt = $pdo->prepare('DELETE FROM bench_users');

        $bench->measure('Single INSERT', 'Raw PDO', function (int $i) use ($stmt, $deleteStmt) {
            $stmt->execute(['User ' . $i, 'user' . $i . '@bench.test', 25, 'active']);
            $deleteStmt->execute();
        }, $iterations);
    }
}
