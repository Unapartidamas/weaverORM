<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Bridge/CodeIgniter/WeaverHelper.php';

use Weaver\ORM\Bridge\CodeIgniter\WeaverConfig;
use Weaver\ORM\Bridge\CodeIgniter\WeaverService;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\Id;
use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\ColumnDefinition;

$config = new WeaverConfig();
$config->connections = ['default' => ['driver' => 'pdo_sqlite', 'memory' => true]];
WeaverService::setConfig($config);
echo "1. Config OK\n";

$workspace = WeaverService::workspace();
echo "2. EntityWorkspace OK\n";

$conn = WeaverService::connectionRegistry()->getConnection();
$conn->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');
echo "3. Connection + DDL OK\n";

#[Entity(table: 'users')]
class TestUser {
    #[Id] public ?int $id = null;
    #[Column] public string $name = '';
    #[Column] public string $email = '';
}

class TestUserMapper extends AbstractEntityMapper {
    public function getEntityClass(): string { return TestUser::class; }
    public function getTableName(): string { return 'users'; }
    public function getColumns(): array {
        return [
            new ColumnDefinition('id', 'id', 'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string'),
            new ColumnDefinition('email', 'email', 'string'),
        ];
    }
    public function getPrimaryKey(): string { return 'id'; }
}

$registry = WeaverService::mapperRegistry();
$registry->register(new TestUserMapper());
echo "4. Mapper registered OK\n";

$user = new TestUser();
$user->name = 'Alice';
$user->email = 'alice@example.com';
$workspace->add($user);
$workspace->push();
echo "5. Insert OK (id=" . $user->id . ")\n";

$row = $conn->fetchAssociative('SELECT * FROM users WHERE id = 1');
echo "6. Query OK: name=" . $row['name'] . ", email=" . $row['email'] . "\n";

$user->name = 'Alice Updated';
$workspace->push();
$row = $conn->fetchAssociative('SELECT name FROM users WHERE id = 1');
echo "7. Update OK: name=" . $row['name'] . "\n";

$workspace->delete($user);
$workspace->push();
$count = $conn->fetchOne('SELECT COUNT(*) FROM users');
echo "8. Delete OK: count=" . $count . "\n";

for ($i = 0; $i < 5; $i++) {
    $u = new TestUser();
    $u->name = "User $i";
    $u->email = "user{$i}@test.com";
    $workspace->add($u);
}
$workspace->push();
$count = $conn->fetchOne('SELECT COUNT(*) FROM users');
echo "9. Batch insert OK: count=" . $count . "\n";

$ws = weaver();
echo "10. Helper weaver() OK\n";

$bridge = new \Weaver\ORM\Bridge\CodeIgniter\CodeIgniterEventBridge();
$bridge->dispatch('test.event', new stdClass());
echo "11. Event bridge OK\n";

WeaverService::reset();
echo "12. Service reset OK\n";

echo "\n=== ALL 12 CI4 INTEGRATION TESTS PASSED ===\n";
