<?php

declare(strict_types=1);

/**
 * Weaver ORM vs Doctrine ORM benchmark runner — PostgreSQL edition.
 *
 * Reads database connection parameters from environment variables:
 *   DB_DRIVER   (default: pdo_pgsql)
 *   DB_HOST     (default: localhost)
 *   DB_PORT     (default: 5432)
 *   DB_NAME     (default: weaver_bench)
 *   DB_USER     (default: weaver)
 *   DB_PASSWORD (default: weaver)
 *
 * Usage:
 *   docker compose -f docker-compose.bench.yml up --build --abort-on-container-exit
 */

require __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Weaver\ORM\DBAL\ConnectionFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Weaver\Benchmark\BenchmarkRunner;
use Weaver\Benchmark\Fixtures\DoctrinePost;
use Weaver\Benchmark\Fixtures\DoctrineUser;
use Weaver\Benchmark\Middleware\BacktickToDoubleQuoteMiddleware;
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

/**
 * Build a DBAL connection for Weaver using env vars.
 *
 * The {@see BacktickToDoubleQuoteMiddleware} is injected so that:
 *  - Weaver UnitOfWork backtick-quoted identifiers are rewritten to
 *    PostgreSQL double-quote style.
 *  - Scenario setup() SQLite DDL (AUTOINCREMENT, TEXT) is rewritten to
 *    PostgreSQL-compatible DDL (SERIAL, VARCHAR/TEXT).
 */
function createWeaverConnection(): \Weaver\ORM\DBAL\Connection
{
    $dbalConfig = new DBALConfiguration();
    $dbalConfig->setMiddlewares([new BacktickToDoubleQuoteMiddleware()]);

    return ConnectionFactory::create(
        [
            'driver'   => (string) (getenv('DB_DRIVER') ?: 'pdo_pgsql'),
            'host'     => (string) (getenv('DB_HOST')   ?: 'localhost'),
            'port'     => (int)    (getenv('DB_PORT')   ?: 5432),
            'dbname'   => (string) (getenv('DB_NAME')   ?: 'weaver_bench'),
            'user'     => (string) (getenv('DB_USER')   ?: 'weaver'),
            'password' => (string) (getenv('DB_PASSWORD') ?: 'weaver'),
        ],
        $dbalConfig,
    );
}

/**
 * Create a Doctrine EntityManager connected to the same PostgreSQL instance.
 *
 * Doctrine uses the doctrine_bench_users / doctrine_bench_posts tables (as
 * defined in the entity mappings) on the same weaver_bench database.
 */
function createDoctrineEntityManager(): EntityManager
{
    $conn = ConnectionFactory::create([
        'driver'   => (string) (getenv('DB_DRIVER') ?: 'pdo_pgsql'),
        'host'     => (string) (getenv('DB_HOST')   ?: 'localhost'),
        'port'     => (int)    (getenv('DB_PORT')   ?: 5432),
        'dbname'   => (string) (getenv('DB_NAME')   ?: 'weaver_bench'),
        'user'     => (string) (getenv('DB_USER')   ?: 'weaver'),
        'password' => (string) (getenv('DB_PASSWORD') ?: 'weaver'),
    ]);

    // Use ArrayAdapter cache (production-like, no disk I/O, no APCu required).
    $cache = new ArrayAdapter();

    $config = ORMSetup::createAttributeMetadataConfiguration(
        paths: [__DIR__ . '/Fixtures'],
        isDevMode: false,
        cache: $cache,
    );

    // Use PHP 8.4 native lazy objects — no need for symfony/var-exporter proxy generation.
    $config->enableNativeLazyObjects(true);

    // Pre-generate proxies into a temp dir so there is no on-demand generation overhead.
    $proxyDir = sys_get_temp_dir() . '/weaver-bench-proxies-pg';
    @mkdir($proxyDir, 0777, true);
    $config->setProxyDir($proxyDir);
    $config->setAutoGenerateProxyClasses(false);

    $em = new EntityManager($conn, $config);

    // Drop existing schema and recreate it fresh.
    $schemaTool = new SchemaTool($em);
    $classes    = [
        $em->getClassMetadata(DoctrineUser::class),
        $em->getClassMetadata(DoctrinePost::class),
    ];

    try {
        $schemaTool->dropSchema($classes);
    } catch (\Throwable) {
        // Tables may not exist on first run; ignore.
    }

    $schemaTool->createSchema($classes);

    // Generate proxies upfront (warmup, not measured).
    $em->getProxyFactory()->generateProxyClasses([
        $em->getClassMetadata(DoctrineUser::class),
        $em->getClassMetadata(DoctrinePost::class),
    ]);

    return $em;
}

// -------------------------------------------------------------------------
// Bootstrap connections
// -------------------------------------------------------------------------

$weaverConnection = createWeaverConnection();

// Ensure the bench tables are absent at startup so that the first scenario
// setup() (which is intercepted and rewritten by BacktickToDoubleQuoteMiddleware
// to use PostgreSQL DDL) can create them cleanly.
$weaverConnection->executeStatement('DROP TABLE IF EXISTS bench_posts');
$weaverConnection->executeStatement('DROP TABLE IF EXISTS bench_users');

$doctrineEm = createDoctrineEntityManager();

$runner = new BenchmarkRunner($weaverConnection, $doctrineEm);

// -------------------------------------------------------------------------
// Register and run scenarios
// -------------------------------------------------------------------------

$scenarios = [
    new InsertBench(),
    new HydrationBench(),
    new UpdateBench(),
    new QueryBench(),
];

$iterations = [
    InsertBench::class    => 500,
    HydrationBench::class => 50,
    UpdateBench::class    => 200,
    QueryBench::class     => 500,
];

echo PHP_EOL;
echo 'Weaver ORM  vs  Doctrine ORM  —  PostgreSQL 16' . PHP_EOL;
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
