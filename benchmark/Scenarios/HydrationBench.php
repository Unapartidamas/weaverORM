<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Scenarios;

use Weaver\ORM\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Weaver\Benchmark\Fixtures\BenchUser;
use Weaver\Benchmark\Fixtures\BenchUserMapper;
use Weaver\Benchmark\Fixtures\DoctrineUser;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\MapperRegistry;

/**
 * Compares Weaver entity hydration vs Doctrine ORM findAll().
 *
 * 500 rows are pre-inserted in both DBs. Each iteration fetches all rows:
 *  - Weaver:   fetchAllAssociative + EntityHydrator::hydrate per row
 *  - Doctrine: EntityManager::getRepository()->findAll()
 *
 * Iterations: 50
 */
class HydrationBench implements BenchScenario
{
    private const SEED_COUNT = 500;

    private MapperRegistry $registry;
    private EntityHydrator $hydrator;

    public function name(): string
    {
        return 'Hydration (500)';
    }

    public function setup(Connection $conn): void
    {
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS bench_users (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                name   TEXT    NOT NULL DEFAULT \'\',
                email  TEXT    NOT NULL DEFAULT \'\',
                age    INTEGER NOT NULL DEFAULT 0,
                status TEXT    NOT NULL DEFAULT \'active\'
            )'
        );

        $this->registry = new MapperRegistry();
        $this->registry->register(new BenchUserMapper());
        $this->hydrator = new EntityHydrator($this->registry, $conn);

        // Pre-seed rows.
        $conn->beginTransaction();
        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $conn->insert('bench_users', [
                'name'   => 'User ' . $i,
                'email'  => 'u' . $i . '@bench.test',
                'age'    => 20 + ($i % 50),
                'status' => $i % 5 === 0 ? 'inactive' : 'active',
            ]);
        }
        $conn->commit();
    }

    public function runWeaver(Connection $conn, int $iterations): float
    {
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $rows = $conn->fetchAllAssociative('SELECT * FROM bench_users');

            foreach ($rows as $row) {
                $this->hydrator->hydrate(BenchUser::class, $row);
            }
        }

        $elapsed = (hrtime(true) - $start) / 1e9;

        return $iterations / $elapsed;
    }

    public function runDoctrine(EntityManager $em, int $iterations): float
    {
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $em->clear();
            $em->getRepository(DoctrineUser::class)->findAll();
        }

        $elapsed = (hrtime(true) - $start) / 1e9;

        return $iterations / $elapsed;
    }

    public function teardown(Connection $conn): void
    {
        $conn->executeStatement('DROP TABLE IF EXISTS bench_users');
    }
}
