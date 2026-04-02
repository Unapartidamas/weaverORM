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
use Weaver\Benchmark\OrmBenchmark;
use Weaver\Benchmark\Scenarios\InsertComparisonBench;
use Weaver\Benchmark\Scenarios\BatchInsertComparisonBench;
use Weaver\Benchmark\Scenarios\SelectComparisonBench;
use Weaver\Benchmark\Scenarios\HydrationComparisonBench;
use Weaver\Benchmark\Scenarios\UpdateComparisonBench;
use Weaver\Benchmark\Scenarios\QueryComplexComparisonBench;

$iterations = (int) ($argv[1] ?? 1000);
if ($iterations < 1) {
    $iterations = 1000;
}

$conn = ConnectionFactory::create([
    'driver' => 'pdo_sqlite',
    'memory' => true,
]);

$pdo = $conn->getNativeConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$createTable = 'CREATE TABLE IF NOT EXISTS bench_users (
    id     INTEGER PRIMARY KEY AUTOINCREMENT,
    name   TEXT    NOT NULL DEFAULT \'\',
    email  TEXT    NOT NULL DEFAULT \'\',
    age    INTEGER NOT NULL DEFAULT 0,
    status TEXT    NOT NULL DEFAULT \'active\'
)';

$conn->executeStatement($createTable);

$bench = new OrmBenchmark();

$scenarios = [
    'Single INSERT' => new InsertComparisonBench(),
    'Batch INSERT' => new BatchInsertComparisonBench(),
    'SELECT by PK' => new SelectComparisonBench(),
    'Hydration' => new HydrationComparisonBench(),
    'UPDATE' => new UpdateComparisonBench(),
    'Complex Query' => new QueryComplexComparisonBench(),
];

foreach ($scenarios as $label => $scenario) {
    echo sprintf('Running: %s...', $label);
    flush();

    $conn->executeStatement('DROP TABLE IF EXISTS bench_users');
    $conn->executeStatement($createTable);

    $scenario->run($bench, $conn, $pdo, $iterations);
    echo ' done' . PHP_EOL;

    gc_collect_cycles();
}

$memoryResults = [];

$conn->executeStatement('DROP TABLE IF EXISTS bench_users');
$conn->executeStatement($createTable);

$registry = new \Weaver\ORM\Mapping\MapperRegistry();
$registry->register(new \Weaver\Benchmark\Fixtures\BenchUserMapper());
$hydrator = new \Weaver\ORM\Hydration\EntityHydrator($registry, $conn);
$dispatcher = new \Weaver\ORM\Event\LifecycleEventDispatcher();
$resolver = new \Weaver\ORM\Persistence\InsertOrderResolver($registry);

gc_collect_cycles();
$memBefore = memory_get_usage(false);

$uow = new \Weaver\ORM\Persistence\UnitOfWork($conn, $registry, $hydrator, $dispatcher, $resolver);
$workspace = new \Weaver\ORM\Manager\EntityWorkspace('bench', $conn, $registry, $uow);
$entities = [];
for ($i = 0; $i < 500; $i++) {
    $user = new \Weaver\Benchmark\Fixtures\BenchUser();
    $user->name = 'MemTest ' . $i;
    $user->email = 'mem' . $i . '@test.com';
    $user->age = 25;
    $user->status = 'active';
    $workspace->add($user);
    $entities[] = $user;
}
$memoryResults['Weaver ORM'] = memory_get_usage(false) - $memBefore;
$workspace->push();
unset($entities, $workspace, $uow);
gc_collect_cycles();

$memBefore = memory_get_usage(false);
$dbalEntities = [];
$conn->executeStatement('DELETE FROM bench_users');
$conn->beginTransaction();
for ($i = 0; $i < 500; $i++) {
    $conn->insert('bench_users', [
        'name' => 'MemTest ' . $i,
        'email' => 'mem' . $i . '@test.com',
        'age' => 25,
        'status' => 'active',
    ]);
    $dbalEntities[] = ['name' => 'MemTest ' . $i, 'email' => 'mem' . $i . '@test.com', 'age' => 25, 'status' => 'active'];
}
$conn->commit();
$memoryResults['Raw DBAL'] = memory_get_usage(false) - $memBefore;
unset($dbalEntities);
gc_collect_cycles();

$memBefore = memory_get_usage(false);
$pdoEntities = [];
$pdo->exec('DELETE FROM bench_users');
$stmt = $pdo->prepare('INSERT INTO bench_users (name, email, age, status) VALUES (?, ?, ?, ?)');
$pdo->beginTransaction();
for ($i = 0; $i < 500; $i++) {
    $stmt->execute(['MemTest ' . $i, 'mem' . $i . '@test.com', 25, 'active']);
    $pdoEntities[] = ['name' => 'MemTest ' . $i, 'email' => 'mem' . $i . '@test.com', 'age' => 25, 'status' => 'active'];
}
$pdo->commit();
$memoryResults['Raw PDO'] = memory_get_usage(false) - $memBefore;
unset($pdoEntities);
gc_collect_cycles();

$bench->printReport($iterations, $memoryResults);
