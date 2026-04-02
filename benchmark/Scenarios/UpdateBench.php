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
 * Compares Weaver dirty-check + UPDATE vs Doctrine ORM find + flush.
 *
 * 500 rows are pre-inserted in both DBs. Each iteration:
 *  - Weaver:   hydrate entity from row, mutate a field, UnitOfWork flush (dirty check + UPDATE)
 *  - Doctrine: $em->find(), modify name, $em->flush(), $em->clear()
 *
 * Iterations: 200
 */
class UpdateBench implements BenchScenario
{
    private const SEED_COUNT = 500;

    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private UnitOfWork $uow;

    /** @var int[] Pre-loaded IDs for Weaver */
    private array $ids = [];

    /** @var int[] Pre-loaded IDs for Doctrine */
    private array $doctrineIds = [];

    public function name(): string
    {
        return 'Update (200)';
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

        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($this->registry);
        $this->uow  = new UnitOfWork($conn, $this->registry, $this->hydrator, $dispatcher, $resolver);

        // Pre-seed rows for Weaver.
        $conn->beginTransaction();
        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $conn->insert('bench_users', [
                'name'   => 'User ' . $i,
                'email'  => 'u' . $i . '@bench.test',
                'age'    => 20 + ($i % 50),
                'status' => 'active',
            ]);
        }
        $conn->commit();

        // Cache IDs.
        $rows = $conn->fetchAllAssociative('SELECT id FROM bench_users');
        foreach ($rows as $row) {
            $this->ids[] = (int) $row['id'];
        }
    }

    public function runWeaver(Connection $conn, int $iterations): float
    {
        $idCount = count($this->ids);
        $start   = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $id = $this->ids[$i % $idCount];

            $row = $conn->fetchAssociative(
                'SELECT * FROM bench_users WHERE id = ?',
                [$id]
            );

            if ($row === false) {
                continue;
            }

            /** @var BenchUser $user */
            $user = $this->hydrator->hydrate(BenchUser::class, $row);

            // Register as managed with a snapshot so UoW can dirty-check.
            $this->uow->track($user, BenchUser::class);

            // Mutate a field.
            $user->name = 'Updated ' . $i;

            // Flush only this entity — triggers dirty-check + UPDATE.
            $this->uow->flush($user);

            // Detach so the UoW does not hold a reference indefinitely.
            $this->uow->detach($user);
        }

        $elapsed = (hrtime(true) - $start) / 1e9;

        return $iterations / $elapsed;
    }

    public function runDoctrine(EntityManager $em, int $iterations): float
    {
        // Seed Doctrine DB and collect IDs.
        if (empty($this->doctrineIds)) {
            for ($i = 0; $i < self::SEED_COUNT; $i++) {
                $user         = new DoctrineUser();
                $user->name   = 'User ' . $i;
                $user->email  = 'u' . $i . '@bench.test';
                $user->age    = 20 + ($i % 50);
                $user->status = 'active';
                $em->persist($user);
            }
            $em->flush();
            $em->clear();

            $rows = $em->getConnection()->fetchAllAssociative('SELECT id FROM doctrine_bench_users');
            foreach ($rows as $row) {
                $this->doctrineIds[] = (int) $row['id'];
            }
        }

        $idCount = count($this->doctrineIds);
        $start   = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $id = $this->doctrineIds[$i % $idCount];

            $em->clear();

            /** @var DoctrineUser|null $user */
            $user = $em->find(DoctrineUser::class, $id);

            if ($user === null) {
                continue;
            }

            $user->name = 'Updated ' . $i;
            $em->flush();
        }

        $elapsed = (hrtime(true) - $start) / 1e9;

        return $iterations / $elapsed;
    }

    public function teardown(Connection $conn): void
    {
        $conn->executeStatement('DROP TABLE IF EXISTS bench_users');
        $this->ids         = [];
        $this->doctrineIds = [];
    }
}
