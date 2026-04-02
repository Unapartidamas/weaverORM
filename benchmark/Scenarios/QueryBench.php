<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Scenarios;

use Weaver\ORM\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Weaver\Benchmark\Fixtures\BenchUser;
use Weaver\Benchmark\Fixtures\BenchUserMapper;
use Weaver\Benchmark\Fixtures\DoctrineUser;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

/**
 * Compares Weaver EntityQueryBuilder vs Doctrine ORM findBy / find.
 *
 * 2 000 users seeded (half active, half inactive).
 * Three sub-scenarios per iteration:
 *   A) find by PK  →  1 row
 *   B) WHERE status='active'  →  ~1 000 rows
 *   C) WHERE age = X  →  ~40 rows (X rotates each iteration)
 *
 * Iterations: 500
 */
class QueryBench implements BenchScenario
{
    private const SEED_USERS = 2_000;

    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private bool $doctrineSeeded = false;

    public function name(): string
    {
        return 'Query (500)';
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

        // Seed in batches of 200 to stay within SQLite placeholder limits.
        $conn->beginTransaction();
        for ($i = 0; $i < self::SEED_USERS; $i++) {
            $conn->insert('bench_users', [
                'name'   => 'User ' . $i,
                'email'  => 'u' . $i . '@bench.test',
                'age'    => 20 + ($i % 50),
                'status' => $i % 2 === 0 ? 'active' : 'inactive',
            ]);
        }
        $conn->commit();
    }

    public function runWeaver(Connection $conn, int $iterations): float
    {
        /** @var AbstractEntityMapper $mapper */
        $mapper = $this->registry->get(BenchUser::class);
        $start  = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            // A) PK lookup — 1 row
            (new EntityQueryBuilder($conn, BenchUser::class, $mapper, $this->hydrator))
                ->where('id', ($i % self::SEED_USERS) + 1)
                ->first();

            // B) status filter — ~1 000 rows
            (new EntityQueryBuilder($conn, BenchUser::class, $mapper, $this->hydrator))
                ->where('status', 'active')
                ->get();

            // C) age filter — ~40 rows (age rotates 20–69)
            (new EntityQueryBuilder($conn, BenchUser::class, $mapper, $this->hydrator))
                ->where('age', 20 + ($i % 50))
                ->get();
        }

        $elapsed = (hrtime(true) - $start) / 1e9;

        return $iterations / $elapsed;
    }

    public function runDoctrine(EntityManager $em, int $iterations): float
    {
        if (!$this->doctrineSeeded) {
            $batchSize = 50;
            for ($i = 0; $i < self::SEED_USERS; $i++) {
                $user         = new DoctrineUser();
                $user->name   = 'User ' . $i;
                $user->email  = 'u' . $i . '@bench.test';
                $user->age    = 20 + ($i % 50);
                $user->status = $i % 2 === 0 ? 'active' : 'inactive';
                $em->persist($user);
                if ($i % $batchSize === 0) {
                    $em->flush();
                    $em->clear();
                }
            }
            $em->flush();
            $em->clear();
            $this->doctrineSeeded = true;
        }

        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $em->clear();

            // A) PK lookup — 1 row
            $em->find(DoctrineUser::class, ($i % self::SEED_USERS) + 1);

            // B) status filter — ~1 000 rows
            $em->getRepository(DoctrineUser::class)->findBy(['status' => 'active']);

            // C) age filter — ~40 rows
            $em->getRepository(DoctrineUser::class)->findBy(['age' => 20 + ($i % 50)]);
        }

        $elapsed = (hrtime(true) - $start) / 1e9;

        return $iterations / $elapsed;
    }

    public function teardown(Connection $conn): void
    {
        $conn->executeStatement('DROP TABLE IF EXISTS bench_users');
        $this->doctrineSeeded = false;
    }
}
