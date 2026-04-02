<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Scenarios;

use Weaver\ORM\DBAL\Connection;
use Weaver\Benchmark\Fixtures\BenchUser;
use Weaver\Benchmark\Fixtures\BenchUserMapper;
use Weaver\Benchmark\OrmBenchmark;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\MapperRegistry;

final class HydrationComparisonBench
{
    private const SEED_COUNT = 1000;
    private const HYDRATE_COUNT = 100;

    public function run(OrmBenchmark $bench, Connection $conn, \PDO $pdo, int $iterations): void
    {
        $this->seed($conn);

        $registry = new MapperRegistry();
        $registry->register(new BenchUserMapper());
        $hydrator = new EntityHydrator($registry, $conn);

        $bench->measure('Hydration (100 rows)', 'Weaver ORM', function (int $i) use ($conn, $hydrator) {
            $rows = $conn->fetchAllAssociative(
                'SELECT * FROM bench_users LIMIT ' . self::HYDRATE_COUNT . ' OFFSET ?',
                [($i * self::HYDRATE_COUNT) % self::SEED_COUNT]
            );
            foreach ($rows as $row) {
                $hydrator->hydrate(BenchUser::class, $row);
            }
        }, $iterations);

        $bench->measure('Hydration (100 rows)', 'Raw DBAL', function (int $i) use ($conn) {
            $conn->fetchAllAssociative(
                'SELECT * FROM bench_users LIMIT ' . self::HYDRATE_COUNT . ' OFFSET ?',
                [($i * self::HYDRATE_COUNT) % self::SEED_COUNT]
            );
        }, $iterations);

        $hydrateStmt = $pdo->prepare(
            'SELECT * FROM bench_users LIMIT ' . self::HYDRATE_COUNT . ' OFFSET ?'
        );

        $bench->measure('Hydration (100 rows)', 'Raw PDO', function (int $i) use ($hydrateStmt) {
            $hydrateStmt->execute([($i * self::HYDRATE_COUNT) % self::SEED_COUNT]);
            $rows = $hydrateStmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $obj = new BenchUser();
                $obj->id = (int) $row['id'];
                $obj->name = $row['name'];
                $obj->email = $row['email'];
                $obj->age = (int) $row['age'];
                $obj->status = $row['status'];
            }
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
                'status' => $i % 3 === 0 ? 'inactive' : 'active',
            ]);
        }
        $conn->commit();
    }
}
