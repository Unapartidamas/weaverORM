<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Scenarios;

use Weaver\ORM\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Weaver\Benchmark\Fixtures\BenchUser;
use Weaver\Benchmark\Fixtures\BenchUserMapper;
use Weaver\Benchmark\Fixtures\DoctrineUser;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

/**
 * Compares Weaver UnitOfWork insert (persist + flush) vs Doctrine ORM EntityManager insert.
 *
 * Each iteration inserts a single user row.
 * Iterations: 500
 */
class InsertBench implements BenchScenario
{
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private UnitOfWork $uow;

    public function name(): string
    {
        return 'Insert (500)';
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
        $dispatcher     = new LifecycleEventDispatcher();
        $resolver       = new InsertOrderResolver($this->registry);
        $this->uow      = new UnitOfWork($conn, $this->registry, $this->hydrator, $dispatcher, $resolver);
    }

    public function runWeaver(Connection $conn, int $iterations): float
    {
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $user         = new BenchUser();
            $user->name   = 'User ' . $i;
            $user->email  = 'user' . $i . '@bench.test';
            $user->age    = 20 + ($i % 50);
            $user->status = 'active';

            $this->uow->persist($user);
            $this->uow->flush($user);
        }

        $elapsed = (hrtime(true) - $start) / 1e9;

        return $iterations / $elapsed;
    }

    public function runDoctrine(EntityManager $em, int $iterations): float
    {
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $user         = new DoctrineUser();
            $user->name   = 'User ' . $i;
            $user->email  = 'user' . $i . '@bench.test';
            $user->age    = 20 + ($i % 50);
            $user->status = 'active';

            $em->persist($user);
            $em->flush();
        }

        $elapsed = (hrtime(true) - $start) / 1e9;

        return $iterations / $elapsed;
    }

    public function teardown(Connection $conn): void
    {
        $conn->executeStatement('DROP TABLE IF EXISTS bench_users');
    }
}
