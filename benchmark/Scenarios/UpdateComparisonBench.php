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

final class UpdateComparisonBench
{
    private const SEED_COUNT = 100;

    public function run(OrmBenchmark $bench, Connection $conn, \PDO $pdo, int $iterations): void
    {
        $this->seed($conn);

        $registry = new MapperRegistry();
        $registry->register(new BenchUserMapper());
        $hydrator = new EntityHydrator($registry, $conn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver = new InsertOrderResolver($registry);

        $bench->measure('UPDATE (change tracking)', 'Weaver ORM', function (int $i) use ($conn, $registry, $hydrator, $dispatcher, $resolver) {
            $id = ($i % self::SEED_COUNT) + 1;
            $uow = new UnitOfWork($conn, $registry, $hydrator, $dispatcher, $resolver);
            $workspace = new EntityWorkspace('bench', $conn, $registry, $uow);

            $row = $conn->fetchAssociative('SELECT * FROM bench_users WHERE id = ?', [$id]);
            $user = $hydrator->hydrate(BenchUser::class, $row);
            $uow->track($user, BenchUser::class);

            $user->name = 'Updated ' . $i;
            $workspace->push();
        }, $iterations);

        $bench->measure('UPDATE (change tracking)', 'Raw DBAL', function (int $i) use ($conn) {
            $id = ($i % self::SEED_COUNT) + 1;
            $conn->update('bench_users', ['name' => 'Updated ' . $i], ['id' => $id]);
        }, $iterations);

        $updateStmt = $pdo->prepare('UPDATE bench_users SET name = ? WHERE id = ?');

        $bench->measure('UPDATE (change tracking)', 'Raw PDO', function (int $i) use ($updateStmt) {
            $id = ($i % self::SEED_COUNT) + 1;
            $updateStmt->execute(['Updated ' . $i, $id]);
        }, $iterations);
    }

    private function seed(Connection $conn): void
    {
        $conn->beginTransaction();
        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $conn->insert('bench_users', [
                'name' => 'User ' . $i,
                'email' => 'u' . $i . '@bench.test',
                'age' => 20 + ($i % 50),
                'status' => 'active',
            ]);
        }
        $conn->commit();
    }
}
