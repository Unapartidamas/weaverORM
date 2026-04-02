<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        return getenv($key) ?: $default;
    }
}
if (!function_exists('app_path')) {
    function app_path(string $path = ''): string {
        return '/tmp/app/' . $path;
    }
}

use Weaver\ORM\DBAL\ConnectionFactory;
use Weaver\ORM\Bridge\Laravel\NullCache;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "OK   $name\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "FAIL $name — " . $e->getMessage() . "\n";
        $failed++;
    }
}

function assertEq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $msg ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true)
        );
    }
}

test('Config defaults are sensible', function () {
    $config = require __DIR__ . '/../src/Bridge/Laravel/config/weaver.php';
    assertEq(false, $config['cache']['enabled'], 'cache should be disabled by default');
    assertEq(3600, $config['cache']['default_ttl'], 'default TTL should be 3600');
    assertEq(false, $config['debug'], 'debug should be false');
    assertEq(100000, $config['max_rows_safety_limit']);
    assertEq('default', $config['default_connection']);
});

class Article
{
    public ?int $id = null;
    public string $title = '';
    public string $body = '';
}

class ArticleMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return Article::class; }
    public function getTableName(): string { return 'articles'; }
    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id', 'id', 'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title', 'title', 'string'),
            new ColumnDefinition('body', 'body', 'string'),
        ];
    }
    public function getPrimaryKey(): string { return 'id'; }
}

$conn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
$conn->executeStatement(
    'CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT NOT NULL)'
);

$mapperRegistry = new MapperRegistry();
$mapperRegistry->register(new ArticleMapper());
$hydrator = new EntityHydrator($mapperRegistry, $conn);
$eventDispatcher = new LifecycleEventDispatcher();
$insertOrderResolver = new InsertOrderResolver($mapperRegistry);
$unitOfWork = new UnitOfWork($conn, $mapperRegistry, $hydrator, $eventDispatcher, $insertOrderResolver);
$workspace = new EntityWorkspace('default', $conn, $mapperRegistry, $unitOfWork);

test('EntityWorkspace created', function () use ($workspace) {
    assertEq('default', $workspace->getName());
});

$article = new Article();
$article->title = 'Hello Weaver';
$article->body = 'Testing Laravel bridge components.';

test('Entity insert (add + push)', function () use ($workspace, $conn, $article) {
    $workspace->add($article);
    $workspace->push();
    assertEq(1, $article->id);
    $row = $conn->fetchAssociative('SELECT * FROM articles WHERE id = ?', [$article->id]);
    assertEq('Hello Weaver', $row['title']);
    assertEq('Testing Laravel bridge components.', $row['body']);
});

test('Entity read via connection', function () use ($conn, $article) {
    $row = $conn->fetchAssociative('SELECT * FROM articles WHERE id = ?', [$article->id]);
    assertEq(false, $row === false, 'row should exist');
    assertEq('Hello Weaver', $row['title']);
});

test('Entity update (modify + push)', function () use ($workspace, $conn, $article) {
    $article->title = 'Updated Title';
    $workspace->push();
    $row = $conn->fetchAssociative('SELECT title FROM articles WHERE id = ?', [$article->id]);
    assertEq('Updated Title', $row['title']);
});

test('Entity delete (delete + push)', function () use ($workspace, $conn, $article) {
    $workspace->delete($article);
    $workspace->push();
    $count = (int) $conn->fetchOne('SELECT COUNT(*) FROM articles');
    assertEq(0, $count, 'row should be deleted');
});

test('Batch insert (5 entities)', function () use ($workspace, $conn) {
    for ($i = 1; $i <= 5; $i++) {
        $a = new Article();
        $a->title = "Batch $i";
        $a->body = "Body $i";
        $workspace->add($a);
    }
    $workspace->push();
    $count = (int) $conn->fetchOne('SELECT COUNT(*) FROM articles');
    assertEq(5, $count, "expected 5 rows after batch insert, got $count");
});

test('NullCache works as PSR-16', function () {
    $cache = new NullCache();
    assertEq(true, $cache->set('foo', 'bar'));
    assertEq(null, $cache->get('foo'));
    assertEq('fallback', $cache->get('foo', 'fallback'));
    assertEq(false, $cache->has('foo'));
    assertEq(true, $cache->delete('foo'));
    assertEq(true, $cache->clear());

    $multi = $cache->getMultiple(['a', 'b'], 'def');
    foreach ($multi as $v) {
        assertEq('def', $v);
    }

    assertEq(true, $cache->setMultiple(['a' => 1, 'b' => 2]));
    assertEq(true, $cache->deleteMultiple(['a', 'b']));
});

test('LaravelCacheAdapter class exists', function () {
    assertEq(true, class_exists(\Weaver\ORM\Bridge\Laravel\LaravelCacheAdapter::class));
});

test('Workspace reset', function () use ($workspace, $conn) {
    $countBefore = (int) $conn->fetchOne('SELECT COUNT(*) FROM articles');
    $a = new Article();
    $a->title = 'Should not persist';
    $a->body = 'Nope';
    $workspace->add($a);
    $workspace->reset();
    $workspace->push();
    $countAfter = (int) $conn->fetchOne('SELECT COUNT(*) FROM articles');
    assertEq($countBefore, $countAfter, 'reset should discard pending entities');
});

echo "\n=== LARAVEL INTEGRATION: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
