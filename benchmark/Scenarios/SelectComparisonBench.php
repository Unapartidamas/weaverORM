<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Scenarios;

use Weaver\ORM\DBAL\Connection;
use Weaver\Benchmark\Fixtures\BenchUser;
use Weaver\Benchmark\Fixtures\BenchUserMapper;
use Weaver\Benchmark\OrmBenchmark;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

final class SelectComparisonBench
{
    private const SEED_COUNT = 1000;

    public function run(OrmBenchmark $bench, Connection $conn, \PDO $pdo, int $iterations): void
    {
        $this->seed($conn);

        $registry = new MapperRegistry();
        $registry->register(new BenchUserMapper());
        $hydrator = new EntityHydrator($registry, $conn);
        $mapper = $registry->get(BenchUser::class);

        $bench->measure('SELECT by PK', 'Weaver ORM', function (int $i) use ($conn, $registry, $hydrator, $mapper) {
            $id = ($i % self::SEED_COUNT) + 1;
            (new EntityQueryBuilder($conn, BenchUser::class, $mapper, $hydrator))
                ->where('id', $id)
                ->first();
        }, $iterations);

        $bench->measure('SELECT by PK', 'Raw DBAL', function (int $i) use ($conn) {
            $id = ($i % self::SEED_COUNT) + 1;
            $conn->fetchAssociative('SELECT * FROM bench_users WHERE id = ?', [$id]);
        }, $iterations);

        $selectStmt = $pdo->prepare('SELECT * FROM bench_users WHERE id = ?');

        $bench->measure('SELECT by PK', 'Raw PDO', function (int $i) use ($selectStmt) {
            $id = ($i % self::SEED_COUNT) + 1;
            $selectStmt->execute([$id]);
            $selectStmt->fetch(\PDO::FETCH_ASSOC);
            $selectStmt->closeCursor();
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
                'status' => $i % 2 === 0 ? 'active' : 'inactive',
            ]);
        }
        $conn->commit();
    }
}
