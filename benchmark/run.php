<?php

declare(strict_types=1);

/**
 * Weaver ORM vs Doctrine ORM benchmark runner.
 *
 * Usage:
 *   docker run --rm weaver-orm php benchmark/run.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Weaver\ORM\DBAL\ConnectionFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Weaver\Benchmark\BenchmarkRunner;
use Weaver\Benchmark\Fixtures\DoctrinePost;
use Weaver\Benchmark\Fixtures\DoctrineUser;
use Weaver\Benchmark\Scenarios\BatchInsertBench;
use Weaver\Benchmark\Scenarios\HydrationBench;
use Weaver\Benchmark\Scenarios\InsertBench;
use Weaver\Benchmark\Scenarios\QueryBench;
use Weaver\Benchmark\Scenarios\UpdateBench;

// Bootstrap autoloader for benchmark namespace.
// composer.json only maps Weaver\ORM\ → src/, so we register the benchmark
// namespace manually here (avoids touching composer.json).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Weaver\\Benchmark\\';
    $base   = __DIR__ . '/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $base . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Create a Weaver SQLite in-memory connection — fast, reproducible, no external deps.
$connection = ConnectionFactory::create([
    'driver' => 'pdo_sqlite',
    'memory' => true,
]);

// Create a separate Doctrine ORM EntityManager with its own SQLite in-memory DB.
function createDoctrineEntityManager(): EntityManager
{
    $conn = ConnectionFactory::create([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]);

    // Use ArrayAdapter cache (production-like, no disk I/O, no APCu required)
    $cache = new ArrayAdapter();

    $config = ORMSetup::createAttributeMetadataConfiguration(
        paths: [__DIR__ . '/Fixtures'],
        isDevMode: false,           // production mode
        cache: $cache,              // metadata + query cache
    );

    // Use PHP 8.4 native lazy objects — no need for symfony/var-exporter proxy generation.
    $config->enableNativeLazyObjects(true);

    // Pre-generate proxies into a temp dir so there's no on-demand generation overhead
    $proxyDir = sys_get_temp_dir() . '/weaver-bench-proxies';
    @mkdir($proxyDir, 0777, true);
    $config->setProxyDir($proxyDir);
    $config->setAutoGenerateProxyClasses(false); // pre-generate, not on-demand

    $em = new EntityManager($conn, $config);

    // Create schema for Doctrine entities.
    $schemaTool = new SchemaTool($em);
    $schemaTool->createSchema([
        $em->getClassMetadata(DoctrineUser::class),
        $em->getClassMetadata(DoctrinePost::class),
    ]);

    // Generate proxies upfront (warmup, not measured)
    $factory = $em->getProxyFactory();
    $factory->generateProxyClasses([
        $em->getClassMetadata(DoctrineUser::class),
        $em->getClassMetadata(DoctrinePost::class),
    ]);

    return $em;
}

$doctrineEm = createDoctrineEntityManager();
$runner     = new BenchmarkRunner($connection, $doctrineEm);

// -------------------------------------------------------------------------
// Register and run scenarios
// -------------------------------------------------------------------------

$scenarios = [
    new InsertBench(),
    new BatchInsertBench(),
    new HydrationBench(),
    new UpdateBench(),
    new QueryBench(),
];

$iterations = [
    InsertBench::class       => 500,
    BatchInsertBench::class  => 50,
    HydrationBench::class    => 50,
    UpdateBench::class       => 200,
    QueryBench::class        => 100,
];

echo PHP_EOL;
echo 'Weaver ORM  vs  Doctrine ORM  —  SQLite in-memory' . PHP_EOL;
echo str_repeat('─', 72) . PHP_EOL;
echo 'Config: Doctrine ORM 3.x, isDevMode=false, ArrayAdapter cache, proxies pre-generated' . PHP_EOL;
echo '        Weaver ORM, clone snapshots, prepared statement cache' . PHP_EOL;
echo str_repeat('─', 72) . PHP_EOL;
echo PHP_EOL;

foreach ($scenarios as $scenario) {
    $n = $iterations[$scenario::class] ?? 100;
    echo sprintf('Running: %-20s  (%d iterations + %d warmup)…', $scenario->name(), $n, max(1, (int) ceil($n * 0.1)));
    flush();
    $runner->run($scenario, $n);
    echo ' done' . PHP_EOL;
}

echo PHP_EOL;
$runner->printTable();
echo PHP_EOL;
