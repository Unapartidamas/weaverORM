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

final class QueryComplexComparisonBench
{
    private const SEED_COUNT = 1000;

    public function run(OrmBenchmark $bench, Connection $conn, \PDO $pdo, int $iterations): void
    {
        $this->seed($conn);

        $registry = new MapperRegistry();
        $registry->register(new BenchUserMapper());
        $hydrator = new EntityHydrator($registry, $conn);
        $mapper = $registry->get(BenchUser::class);

        $bench->measure('Complex Query (WHERE+ORDER+LIMIT)', 'Weaver ORM', function (int $i) use ($conn, $registry, $hydrator, $mapper) {
            $age = 20 + ($i % 50);
            (new EntityQueryBuilder($conn, BenchUser::class, $mapper, $hydrator))
                ->where('status', 'active')
                ->where('age', '>=', $age)
                ->orderBy('name', 'ASC')
                ->limit(25)
                ->get();
        }, $iterations);

        $bench->measure('Complex Query (WHERE+ORDER+LIMIT)', 'Raw DBAL', function (int $i) use ($conn) {
            $age = 20 + ($i % 50);
            $qb = $conn->createQueryBuilder();
            $qb->select('*')
                ->from('bench_users')
                ->where('status = :status')
                ->andWhere('age >= :age')
                ->orderBy('name', 'ASC')
                ->setMaxResults(25)
                ->setParameter('status', 'active')
                ->setParameter('age', $age);
            $qb->executeQuery()->fetchAllAssociative();
        }, $iterations);

        $complexStmt = $pdo->prepare(
            'SELECT * FROM bench_users WHERE status = ? AND age >= ? ORDER BY name ASC LIMIT 25'
        );

        $bench->measure('Complex Query (WHERE+ORDER+LIMIT)', 'Raw PDO', function (int $i) use ($complexStmt) {
            $age = 20 + ($i % 50);
            $complexStmt->execute(['active', $age]);
            $complexStmt->fetchAll(\PDO::FETCH_ASSOC);
        }, $iterations);
    }

    private function seed(Connection $conn): void
    {
        $conn->beginTransaction();
        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $conn->insert('bench_users', [
                'name' => 'User ' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'email' => 'u' . $i . '@bench.test',
                'age' => 20 + ($i % 50),
                'status' => $i % 2 === 0 ? 'active' : 'inactive',
            ]);
        }
        $conn->commit();
    }
}
