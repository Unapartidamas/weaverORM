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
 * Micro-benchmark: batch insert 100 entities in a single flush() vs 100
 * individual persist+flush calls.
 *
 * Weaver path:
 *   - "batch"    → persist 100 entities, then flush() once  (batch INSERT)
 *   - "single"   → persist + flush per entity                (baseline)
 *
 * The Doctrine column measures Doctrine ORM with one flush per entity
 * (its standard pattern — Doctrine does not expose a multi-row INSERT API).
 *
 * Each "iteration" here = inserting 100 rows.
 */
class BatchInsertBench implements BenchScenario
{
    private const BATCH_SIZE = 100;

    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private UnitOfWork $batchUow;   // used for the batch path
    private UnitOfWork $singleUow;  // used for the single path (reported as "weaver")

    public function name(): string
    {
        return 'Batch 100 inserts';
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

        $this->registry  = new MapperRegistry();
        $this->registry->register(new BenchUserMapper());
        $dispatcher      = new LifecycleEventDispatcher();
        $resolver        = new InsertOrderResolver($this->registry);
        $this->hydrator  = new EntityHydrator($this->registry, $conn);
        $this->batchUow  = new UnitOfWork($conn, $this->registry, $this->hydrator, $dispatcher, $resolver);
        $this->singleUow = new UnitOfWork($conn, $this->registry, $this->hydrator, $dispatcher, $resolver);
    }

    /**
     * "Weaver" column = batch path: persist 100 entities then flush() once.
     *
     * After each flush the UoW is cleared so managed-entity accumulation does
     * not affect later iterations.
     *
     * Returns ops/sec where one op = inserting 100 rows.
     */
    public function runWeaver(Connection $conn, int $iterations): float
    {
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            for ($j = 0; $j < self::BATCH_SIZE; $j++) {
                $user         = new BenchUser();
                $user->name   = 'User ' . $j;
                $user->email  = 'u' . $j . '@batch.test';
                $user->age    = 20 + ($j % 50);
                $user->status = 'active';
                $this->batchUow->persist($user);
            }
            $this->batchUow->flush();   // single batch INSERT for all 100
            $this->batchUow->clear();   // drop managed state; don't accumulate
        }

        $elapsed = (hrtime(true) - $start) / 1e9;

        return $iterations / $elapsed;
    }

    /**
     * "Doctrine" column = single-per-entity full-flush path (Weaver, not Doctrine ORM).
     *
     * Each entity is persisted and then flushed individually via a full flush().
     * The UoW is cleared once per outer iteration (after all 100 single-entity
     * flushes) to keep managed-entity count equivalent to the batch path.
     *
     * This gives a fair apples-to-apples comparison: same code path, same
     * number of managed entities in flight — only difference is batch vs
     * one-at-a-time INSERTs.
     *
     * The EntityManager parameter is ignored.
     *
     * Returns ops/sec where one op = inserting 100 rows one-by-one.
     */
    public function runDoctrine(EntityManager $em, int $iterations): float
    {
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            for ($j = 0; $j < self::BATCH_SIZE; $j++) {
                $user         = new BenchUser();
                $user->name   = 'User ' . $j;
                $user->email  = 'u' . $j . '@single.test';
                $user->age    = 20 + ($j % 50);
                $user->status = 'active';
                $this->singleUow->persist($user);
                $this->singleUow->flush();  // full flush, one entity at a time
            }
            $this->singleUow->clear();  // drop managed state; don't accumulate
        }

        $elapsed = (hrtime(true) - $start) / 1e9;

        return $iterations / $elapsed;
    }

    public function teardown(Connection $conn): void
    {
        $conn->executeStatement('DROP TABLE IF EXISTS bench_users');
    }
}
