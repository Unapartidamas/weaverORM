#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Weaver\\Benchmark\\';
    $base = __DIR__ . '/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $base . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use Weaver\ORM\DBAL\ConnectionFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Illuminate\Database\Capsule\Manager as Capsule;
use Weaver\Benchmark\Fixtures\BenchUser;
use Weaver\Benchmark\Fixtures\BenchUserMapper;
use Weaver\Benchmark\Fixtures\DoctrineUser;
use Weaver\Benchmark\Fixtures\EloquentUser;
use Weaver\Benchmark\Fixtures\WeaverUser;
use Weaver\Benchmark\Fixtures\WeaverUserMapper;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Relation\RelationLoader;

$iterations = (int) ($argv[1] ?? 500);
if ($iterations < 1) {
    $iterations = 500;
}

$createTableSql = 'CREATE TABLE IF NOT EXISTS bench_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL DEFAULT \'\',
    email TEXT NOT NULL DEFAULT \'\',
    age INTEGER NOT NULL DEFAULT 25,
    created_at TEXT NOT NULL DEFAULT \'\'
)';

$weaverCreateTableSql = 'CREATE TABLE IF NOT EXISTS bench_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL DEFAULT \'\',
    email TEXT NOT NULL DEFAULT \'\',
    age INTEGER NOT NULL DEFAULT 25,
    registered_at TEXT NOT NULL DEFAULT \'\'
)';

$now = date('Y-m-d H:i:s');

// ─── Weaver ORM setup ────────────────────────────────────────────────
$weaverConn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
$weaverConn->executeStatement($weaverCreateTableSql);

$registry = new MapperRegistry();
$registry->register(new WeaverUserMapper());
$hydrator = new EntityHydrator($registry, $weaverConn);
$dispatcher = new LifecycleEventDispatcher();
$resolver = new InsertOrderResolver($registry);
$relationLoader = new RelationLoader($weaverConn, $registry, $hydrator);

function makeWeaverWorkspace(
    \Weaver\ORM\DBAL\Connection $conn,
    MapperRegistry $registry,
    EntityHydrator $hydrator,
    LifecycleEventDispatcher $dispatcher,
    InsertOrderResolver $resolver,
): EntityWorkspace {
    $uow = new UnitOfWork($conn, $registry, $hydrator, $dispatcher, $resolver);
    return new EntityWorkspace('bench', $conn, $registry, $uow);
}

function makeWeaverQb(
    \Weaver\ORM\DBAL\Connection $conn,
    MapperRegistry $registry,
    EntityHydrator $hydrator,
    RelationLoader $relationLoader,
): EntityQueryBuilder {
    return new EntityQueryBuilder(
        $conn,
        WeaverUser::class,
        $registry->get(WeaverUser::class),
        $hydrator,
        $relationLoader,
    );
}

// ─── Doctrine ORM setup ─────────────────────────────────────────────
$doctrineConfig = ORMSetup::createAttributeMetadataConfiguration(
    [__DIR__ . '/Fixtures'],
    true,
);
$doctrineConfig->enableNativeLazyObjects(true);
$doctrineConn = \Doctrine\DBAL\DriverManager::getConnection(
    ['driver' => 'pdo_sqlite', 'memory' => true],
    $doctrineConfig,
);
$em = new EntityManager($doctrineConn, $doctrineConfig);
$schemaTool = new SchemaTool($em);
$schemaTool->createSchema([$em->getClassMetadata(DoctrineUser::class)]);

// ─── Eloquent setup ─────────────────────────────────────────────────
$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

Capsule::schema()->create('bench_users', function ($table) {
    $table->increments('id');
    $table->string('name', 255)->default('');
    $table->string('email', 255)->default('');
    $table->integer('age')->default(25);
    $table->string('created_at', 50)->default('');
});

// ─── Benchmark harness ──────────────────────────────────────────────
$results = [];
$orms = ['Weaver ORM', 'Doctrine ORM', 'Eloquent'];

function bench(string $label, string $orm, \Closure $setup, \Closure $fn, int $iterations, array &$results): void
{
    $setup();

    for ($w = 0; $w < min(50, (int) ($iterations * 0.1)); $w++) {
        $fn($w);
    }

    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $fn($i);
        $elapsed = hrtime(true) - $start;
        $times[] = $elapsed;
    }

    sort($times);
    $count = count($times);
    $trim = max(1, (int) ($count * 0.05));
    $trimmed = array_slice($times, $trim, $count - 2 * $trim);
    if ($trimmed === []) {
        $trimmed = $times;
    }
    $sum = array_sum($trimmed);
    $avg = $sum / count($trimmed);
    $opsPerSec = count($trimmed) / ($sum / 1e9);

    $results[$label][$orm] = [
        'avg_ns' => $avg,
        'ops_sec' => $opsPerSec,
    ];
}

// ─── 1. Single INSERT ───────────────────────────────────────────────
echo "Running: Single INSERT...";
flush();

$weaverWs = makeWeaverWorkspace($weaverConn, $registry, $hydrator, $dispatcher, $resolver);

bench('Single INSERT', 'Weaver ORM',
    function () use ($weaverConn) { $weaverConn->executeStatement('DELETE FROM bench_users'); },
    function (int $i) use ($weaverWs, $weaverConn, $now) {
        $u = new WeaverUser();
        $u->name = 'User ' . $i;
        $u->email = 'user' . $i . '@bench.test';
        $u->age = 25;
        $u->registeredAt = $now;
        $weaverWs->add($u);
        $weaverWs->push();
        $weaverWs->reset();
        $weaverConn->executeStatement('DELETE FROM bench_users');
    },
    $iterations, $results
);

bench('Single INSERT', 'Doctrine ORM',
    function () use ($em, $doctrineConn) {
        $doctrineConn->executeStatement('DELETE FROM bench_users');
        $em->clear();
    },
    function (int $i) use ($em, $doctrineConn, $now) {
        $u = new DoctrineUser();
        $u->name = 'User ' . $i;
        $u->email = 'user' . $i . '@bench.test';
        $u->age = 25;
        $u->createdAt = $now;
        $em->persist($u);
        $em->flush();
        $em->clear();
        $doctrineConn->executeStatement('DELETE FROM bench_users');
    },
    $iterations, $results
);

bench('Single INSERT', 'Eloquent',
    function () { EloquentUser::query()->delete(); },
    function (int $i) use ($now) {
        EloquentUser::create([
            'name' => 'User ' . $i,
            'email' => 'user' . $i . '@bench.test',
            'age' => 25,
            'created_at' => $now,
        ]);
        EloquentUser::query()->delete();
    },
    $iterations, $results
);

echo " done\n";

// ─── 2. Batch INSERT (100 rows) ─────────────────────────────────────
echo "Running: Batch INSERT (100 rows)...";
flush();

$weaverWsBatch = makeWeaverWorkspace($weaverConn, $registry, $hydrator, $dispatcher, $resolver);

bench('Batch INSERT (100 rows)', 'Weaver ORM',
    function () use ($weaverConn) { $weaverConn->executeStatement('DELETE FROM bench_users'); },
    function (int $i) use ($weaverWsBatch, $weaverConn, $now) {
        for ($j = 0; $j < 100; $j++) {
            $u = new WeaverUser();
            $u->name = 'User ' . $i . '_' . $j;
            $u->email = 'user' . $i . '_' . $j . '@bench.test';
            $u->age = 20 + ($j % 40);
            $u->registeredAt = $now;
            $weaverWsBatch->add($u);
        }
        $weaverWsBatch->push();
        $weaverWsBatch->reset();
        $weaverConn->executeStatement('DELETE FROM bench_users');
    },
    $iterations, $results
);

bench('Batch INSERT (100 rows)', 'Doctrine ORM',
    function () use ($em, $doctrineConn) {
        $doctrineConn->executeStatement('DELETE FROM bench_users');
        $em->clear();
    },
    function (int $i) use ($em, $doctrineConn, $now) {
        for ($j = 0; $j < 100; $j++) {
            $u = new DoctrineUser();
            $u->name = 'User ' . $i . '_' . $j;
            $u->email = 'user' . $i . '_' . $j . '@bench.test';
            $u->age = 20 + ($j % 40);
            $u->createdAt = $now;
            $em->persist($u);
        }
        $em->flush();
        $em->clear();
        $doctrineConn->executeStatement('DELETE FROM bench_users');
    },
    $iterations, $results
);

bench('Batch INSERT (100 rows)', 'Eloquent',
    function () { EloquentUser::query()->delete(); },
    function (int $i) use ($now) {
        for ($j = 0; $j < 100; $j++) {
            EloquentUser::create([
                'name' => 'User ' . $i . '_' . $j,
                'email' => 'user' . $i . '_' . $j . '@bench.test',
                'age' => 20 + ($j % 40),
                'created_at' => $now,
            ]);
        }
        EloquentUser::query()->delete();
    },
    $iterations, $results
);

echo " done\n";

// ─── 3. SELECT by PK ────────────────────────────────────────────────
echo "Running: SELECT by PK...";
flush();

for ($j = 1; $j <= 50; $j++) {
    $weaverConn->insert('bench_users', ['name' => 'User ' . $j, 'email' => 'u' . $j . '@t.com', 'age' => 20 + $j, 'registered_at' => $now]);
    $doctrineConn->insert('bench_users', ['name' => 'User ' . $j, 'email' => 'u' . $j . '@t.com', 'age' => 20 + $j, 'created_at' => $now]);
    EloquentUser::create(['name' => 'User ' . $j, 'email' => 'u' . $j . '@t.com', 'age' => 20 + $j, 'created_at' => $now]);
}
$em->clear();

bench('SELECT by PK', 'Weaver ORM',
    function () {},
    function (int $i) use ($weaverConn, $registry, $hydrator, $relationLoader) {
        $id = ($i % 50) + 1;
        $qb = makeWeaverQb($weaverConn, $registry, $hydrator, $relationLoader);
        $qb->where('id', '=', $id)->first();
    },
    $iterations, $results
);

bench('SELECT by PK', 'Doctrine ORM',
    function () use ($em) { $em->clear(); },
    function (int $i) use ($em) {
        $id = ($i % 50) + 1;
        $em->find(DoctrineUser::class, $id);
        $em->clear();
    },
    $iterations, $results
);

bench('SELECT by PK', 'Eloquent',
    function () {},
    function (int $i) {
        $id = ($i % 50) + 1;
        EloquentUser::find($id);
    },
    $iterations, $results
);

echo " done\n";

// ─── 4. SELECT with WHERE + ORDER + LIMIT ───────────────────────────
echo "Running: Complex SELECT...";
flush();

bench('Complex SELECT (WHERE+ORDER+LIMIT)', 'Weaver ORM',
    function () {},
    function (int $i) use ($weaverConn, $registry, $hydrator, $relationLoader) {
        $qb = makeWeaverQb($weaverConn, $registry, $hydrator, $relationLoader);
        $qb->where('age', '>', 25)->orderBy('name', 'ASC')->limit(10)->get();
    },
    $iterations, $results
);

bench('Complex SELECT (WHERE+ORDER+LIMIT)', 'Doctrine ORM',
    function () use ($em) { $em->clear(); },
    function (int $i) use ($em) {
        $em->createQueryBuilder()
            ->select('u')
            ->from(DoctrineUser::class, 'u')
            ->where('u.age > :age')
            ->orderBy('u.name', 'ASC')
            ->setMaxResults(10)
            ->setParameter('age', 25)
            ->getQuery()
            ->getResult();
        $em->clear();
    },
    $iterations, $results
);

bench('Complex SELECT (WHERE+ORDER+LIMIT)', 'Eloquent',
    function () {},
    function (int $i) {
        EloquentUser::where('age', '>', 25)->orderBy('name')->limit(10)->get();
    },
    $iterations, $results
);

echo " done\n";

// ─── 5. UPDATE ──────────────────────────────────────────────────────
echo "Running: UPDATE...";
flush();

$weaverWsUpdate = makeWeaverWorkspace($weaverConn, $registry, $hydrator, $dispatcher, $resolver);

bench('UPDATE', 'Weaver ORM',
    function () {},
    function (int $i) use ($weaverWsUpdate, $weaverConn, $registry, $hydrator, $relationLoader) {
        $id = ($i % 50) + 1;
        $qb = makeWeaverQb($weaverConn, $registry, $hydrator, $relationLoader);
        $user = $qb->where('id', '=', $id)->first();
        if ($user === null) return;
        $user = $weaverWsUpdate->merge($user);
        $user->name = 'Updated ' . $i;
        $weaverWsUpdate->push();
        $weaverWsUpdate->reset();
    },
    $iterations, $results
);

bench('UPDATE', 'Doctrine ORM',
    function () use ($em) { $em->clear(); },
    function (int $i) use ($em) {
        $id = ($i % 50) + 1;
        $user = $em->find(DoctrineUser::class, $id);
        if ($user === null) return;
        $user->name = 'Updated ' . $i;
        $em->flush();
        $em->clear();
    },
    $iterations, $results
);

bench('UPDATE', 'Eloquent',
    function () {},
    function (int $i) {
        $id = ($i % 50) + 1;
        $user = EloquentUser::find($id);
        if ($user === null) return;
        $user->update(['name' => 'Updated ' . $i]);
    },
    $iterations, $results
);

echo " done\n";

// ─── 6. Hydration (100 rows) ────────────────────────────────────────
echo "Running: Hydration (100 rows)...";
flush();

$weaverConn->executeStatement('DELETE FROM bench_users');
$doctrineConn->executeStatement('DELETE FROM bench_users');
EloquentUser::query()->delete();

for ($j = 1; $j <= 100; $j++) {
    $weaverConn->insert('bench_users', ['name' => 'Hydrate ' . $j, 'email' => 'h' . $j . '@t.com', 'age' => 18 + $j, 'registered_at' => $now]);
    $doctrineConn->insert('bench_users', ['name' => 'Hydrate ' . $j, 'email' => 'h' . $j . '@t.com', 'age' => 18 + $j, 'created_at' => $now]);
    EloquentUser::create(['name' => 'Hydrate ' . $j, 'email' => 'h' . $j . '@t.com', 'age' => 18 + $j, 'created_at' => $now]);
}

bench('Hydration (100 rows)', 'Weaver ORM',
    function () {},
    function (int $i) use ($weaverConn, $registry, $hydrator, $relationLoader) {
        $qb = makeWeaverQb($weaverConn, $registry, $hydrator, $relationLoader);
        $qb->limit(100)->get();
    },
    $iterations, $results
);

bench('Hydration (100 rows)', 'Doctrine ORM',
    function () use ($em) { $em->clear(); },
    function (int $i) use ($em) {
        $em->getRepository(DoctrineUser::class)->findBy([], null, 100);
        $em->clear();
    },
    $iterations, $results
);

bench('Hydration (100 rows)', 'Eloquent',
    function () {},
    function (int $i) {
        EloquentUser::limit(100)->get();
    },
    $iterations, $results
);

echo " done\n";

// ─── Print results ──────────────────────────────────────────────────
echo PHP_EOL;
echo 'Weaver ORM Benchmark -- Real ORM Comparison' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;
echo sprintf('SQLite :memory: | PHP %s | %d iterations', PHP_VERSION, $iterations) . PHP_EOL;
echo PHP_EOL;

$wins = array_fill_keys($orms, 0);
$totalBenches = 0;

foreach ($results as $label => $ormResults) {
    $totalBenches++;
    echo $label . ':' . PHP_EOL;

    $avgTimes = [];
    foreach ($orms as $orm) {
        if (!isset($ormResults[$orm])) {
            continue;
        }
        $r = $ormResults[$orm];
        $avgMs = $r['avg_ns'] / 1e6;
        $avgTimes[$orm] = $avgMs;
        echo sprintf(
            "  %-18s %8.3fms avg  (%s ops/sec)",
            $orm . ':',
            $avgMs,
            number_format((int) round($r['ops_sec'])),
        ) . PHP_EOL;
    }

    if (count($avgTimes) > 0) {
        $winner = array_keys($avgTimes, min($avgTimes))[0];
        $wins[$winner]++;
        $winnerMs = $avgTimes[$winner];

        $comparisons = [];
        foreach ($avgTimes as $orm => $ms) {
            if ($orm === $winner) continue;
            if ($winnerMs > 0) {
                $ratio = $ms / $winnerMs;
                $comparisons[] = sprintf('%.1fx faster than %s', $ratio, $orm);
            }
        }

        echo sprintf('  Winner: %s (%s)', $winner, implode(', ', $comparisons)) . PHP_EOL;
    }

    echo PHP_EOL;
}

echo 'SUMMARY:' . PHP_EOL;
foreach ($orms as $orm) {
    echo sprintf('  %-18s %d/%d wins', $orm . ':', $wins[$orm], $totalBenches) . PHP_EOL;
}
echo PHP_EOL;

echo 'NOTE: CI4 Model was excluded because it requires the full CodeIgniter 4' . PHP_EOL;
echo '      framework bootstrap (APPPATH, SYSTEMPATH, etc.) and cannot run standalone.' . PHP_EOL;
echo PHP_EOL;
