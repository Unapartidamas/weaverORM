<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Bridge;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Query\EntityQueryBuilder;

class BenchUser
{
    public ?int $id = null;
    public string $name = '';
    public string $email = '';
    public ?int $age = null;
    public ?float $score = null;
    public int $active = 1;
    public ?string $bio = null;
    public ?string $registeredAt = null;
}

class BenchUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return BenchUser::class;
    }

    public function getTableName(): string
    {
        return 'users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id', 'id', 'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string', length: 255),
            new ColumnDefinition('email', 'email', 'string', length: 255),
            new ColumnDefinition('age', 'age', 'integer', nullable: true),
            new ColumnDefinition('score', 'score', 'float', nullable: true),
            new ColumnDefinition('active', 'active', 'integer'),
            new ColumnDefinition('bio', 'bio', 'text', nullable: true),
            new ColumnDefinition('registered_at', 'registeredAt', 'string', nullable: true),
        ];
    }
}

final class EquivalenceTest extends TestCase
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            age INTEGER,
            score REAL,
            active INTEGER DEFAULT 1,
            bio TEXT,
            registered_at TEXT
        )
    SQL;

    private function createWeaverSetup(): array
    {
        $conn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement(self::SCHEMA);

        $registry = new MapperRegistry();
        $registry->register(new BenchUserMapper());

        $hydrator   = new EntityHydrator($registry, $conn);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($conn, $registry, $hydrator, $dispatcher, $resolver);

        $workspace = new EntityWorkspace('default', $conn, $registry, $uow);

        return [$workspace, $conn];
    }

    private function createDbalSetup(): Connection
    {
        $conn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement(self::SCHEMA);

        return $conn;
    }

    private function createQueryBuilder(Connection $conn, MapperRegistry $registry): EntityQueryBuilder
    {
        $mapper   = $registry->get(BenchUser::class);
        $hydrator = new EntityHydrator($registry, $conn);

        return new EntityQueryBuilder($conn, BenchUser::class, $mapper, $hydrator);
    }

    private function makeBenchUser(string $name, string $email, ?int $age = null, ?float $score = null, int $active = 1, ?string $bio = null, ?string $registeredAt = null): BenchUser
    {
        $u = new BenchUser();
        $u->name      = $name;
        $u->email     = $email;
        $u->age       = $age;
        $u->score     = $score;
        $u->active    = $active;
        $u->bio       = $bio;
        $u->registeredAt = $registeredAt;

        return $u;
    }

    private function dbalRow(Connection $conn, string $sql, array $params = []): array|false
    {
        return $conn->fetchAssociative($sql, $params);
    }

    private function dbalAll(Connection $conn, string $sql, array $params = []): array
    {
        return $conn->fetchAllAssociative($sql, $params);
    }

    public function test_insert_produces_identical_row(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $user = $this->makeBenchUser('Alice', 'alice@test.com', 30, 9.5, 1, 'Hello', '2025-01-01');
        $workspace->add($user);
        $workspace->push();

        $dConn->insert('users', [
            'name'       => 'Alice',
            'email'      => 'alice@test.com',
            'age'        => 30,
            'score'      => 9.5,
            'active'     => 1,
            'bio'        => 'Hello',
            'registered_at' => '2025-01-01',
        ]);

        $wRow = $this->dbalRow($wConn, 'SELECT * FROM users WHERE id = 1');
        $dRow = $this->dbalRow($dConn, 'SELECT * FROM users WHERE id = 1');

        self::assertNotFalse($wRow);
        self::assertNotFalse($dRow);
        self::assertSame($dRow['name'], $wRow['name']);
        self::assertSame($dRow['email'], $wRow['email']);
        self::assertSame((int) $dRow['age'], (int) $wRow['age']);
        self::assertSame($dRow['bio'], $wRow['bio']);
        self::assertSame($dRow['registered_at'], $wRow['registered_at']);
        self::assertEquals((float) $dRow['score'], (float) $wRow['score']);
        self::assertSame((int) $dRow['active'], (int) $wRow['active']);
    }

    public function test_batch_insert_produces_identical_rows(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        for ($i = 1; $i <= 10; $i++) {
            $user = $this->makeBenchUser("User{$i}", "user{$i}@test.com", 20 + $i);
            $workspace->add($user);

            $dConn->insert('users', [
                'name'  => "User{$i}",
                'email' => "user{$i}@test.com",
                'age'   => 20 + $i,
            ]);
        }
        $workspace->push();

        $wRows = $this->dbalAll($wConn, 'SELECT * FROM users ORDER BY id');
        $dRows = $this->dbalAll($dConn, 'SELECT * FROM users ORDER BY id');

        self::assertCount(10, $wRows);
        self::assertCount(10, $dRows);

        for ($i = 0; $i < 10; $i++) {
            self::assertSame($dRows[$i]['name'], $wRows[$i]['name']);
            self::assertSame($dRows[$i]['email'], $wRows[$i]['email']);
            self::assertSame((int) $dRows[$i]['age'], (int) $wRows[$i]['age']);
        }
    }

    public function test_update_produces_identical_result(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $user = $this->makeBenchUser('Bob', 'bob@test.com', 25);
        $workspace->add($user);
        $workspace->push();

        $dConn->insert('users', ['name' => 'Bob', 'email' => 'bob@test.com', 'age' => 25]);

        $user->name  = 'Robert';
        $user->age   = 26;
        $user->email = 'robert@test.com';
        $workspace->push($user);

        $dConn->update('users', ['name' => 'Robert', 'age' => 26, 'email' => 'robert@test.com'], ['id' => 1]);

        $wRow = $this->dbalRow($wConn, 'SELECT * FROM users WHERE id = 1');
        $dRow = $this->dbalRow($dConn, 'SELECT * FROM users WHERE id = 1');

        self::assertSame($dRow['name'], $wRow['name']);
        self::assertSame($dRow['email'], $wRow['email']);
        self::assertSame((int) $dRow['age'], (int) $wRow['age']);
    }

    public function test_delete_produces_identical_result(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $users = [];
        for ($i = 1; $i <= 3; $i++) {
            $u = $this->makeBenchUser("User{$i}", "user{$i}@test.com");
            $workspace->add($u);
            $users[] = $u;

            $dConn->insert('users', ['name' => "User{$i}", 'email' => "user{$i}@test.com"]);
        }
        $workspace->push();

        $workspace->delete($users[1]);
        $workspace->push($users[1]);

        $dConn->delete('users', ['id' => 2]);

        $wRows = $this->dbalAll($wConn, 'SELECT id, name FROM users ORDER BY id');
        $dRows = $this->dbalAll($dConn, 'SELECT id, name FROM users ORDER BY id');

        self::assertCount(2, $wRows);
        self::assertCount(2, $dRows);
        self::assertSame($dRows[0]['name'], $wRows[0]['name']);
        self::assertSame($dRows[1]['name'], $wRows[1]['name']);
    }

    public function test_select_by_pk_returns_identical_data(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $data = ['name' => 'Charlie', 'email' => 'charlie@test.com', 'age' => 40, 'score' => 8.75, 'bio' => 'Developer'];

        $user = $this->makeBenchUser($data['name'], $data['email'], $data['age'], $data['score'], bio: $data['bio']);
        $workspace->add($user);
        $workspace->push();

        $dConn->insert('users', $data);

        $wRow = $this->dbalRow($wConn, 'SELECT * FROM users WHERE id = 1');
        $dRow = $this->dbalRow($dConn, 'SELECT * FROM users WHERE id = 1');

        foreach (['name', 'email', 'bio'] as $field) {
            self::assertSame($dRow[$field], $wRow[$field], "Field {$field} mismatch");
        }
        self::assertSame((int) $dRow['age'], (int) $wRow['age']);
        self::assertEquals((float) $dRow['score'], (float) $wRow['score']);
    }

    public function test_select_with_where_returns_identical_rows(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        for ($i = 1; $i <= 20; $i++) {
            $age = 18 + $i;
            $user = $this->makeBenchUser("User{$i}", "user{$i}@test.com", $age);
            $workspace->add($user);

            $dConn->insert('users', ['name' => "User{$i}", 'email' => "user{$i}@test.com", 'age' => $age]);
        }
        $workspace->push();

        $wRows = $this->dbalAll($wConn, 'SELECT * FROM users WHERE age > 30 ORDER BY id');
        $dRows = $this->dbalAll($dConn, 'SELECT * FROM users WHERE age > 30 ORDER BY id');

        self::assertCount(count($dRows), $wRows);
        for ($i = 0; $i < count($dRows); $i++) {
            self::assertSame($dRows[$i]['name'], $wRows[$i]['name']);
            self::assertSame((int) $dRows[$i]['age'], (int) $wRows[$i]['age']);
        }
    }

    public function test_select_with_order_and_limit_returns_identical_rows(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        for ($i = 1; $i <= 20; $i++) {
            $age = 20 + ($i * 2);
            $user = $this->makeBenchUser("User{$i}", "user{$i}@test.com", $age);
            $workspace->add($user);

            $dConn->insert('users', ['name' => "User{$i}", 'email' => "user{$i}@test.com", 'age' => $age]);
        }
        $workspace->push();

        $wRows = $this->dbalAll($wConn, 'SELECT * FROM users ORDER BY age DESC LIMIT 5');
        $dRows = $this->dbalAll($dConn, 'SELECT * FROM users ORDER BY age DESC LIMIT 5');

        self::assertCount(5, $wRows);
        self::assertCount(5, $dRows);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame($dRows[$i]['name'], $wRows[$i]['name']);
            self::assertSame((int) $dRows[$i]['age'], (int) $wRows[$i]['age']);
        }
    }

    public function test_count_returns_identical_value(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        for ($i = 1; $i <= 15; $i++) {
            $user = $this->makeBenchUser("User{$i}", "user{$i}@test.com");
            $workspace->add($user);

            $dConn->insert('users', ['name' => "User{$i}", 'email' => "user{$i}@test.com"]);
        }
        $workspace->push();

        $registry = $workspace->getMapperRegistry();
        $weaverCount = $this->createQueryBuilder($wConn, $registry)->count();

        $dbalCount = (int) $dConn->fetchOne('SELECT COUNT(*) FROM users');

        self::assertSame($dbalCount, $weaverCount);
        self::assertSame(15, $weaverCount);
    }

    public function test_null_values_handled_identically(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $user = $this->makeBenchUser('NullTester', 'null@test.com');
        $workspace->add($user);
        $workspace->push();

        $dConn->insert('users', ['name' => 'NullTester', 'email' => 'null@test.com']);

        $wRow = $this->dbalRow($wConn, 'SELECT * FROM users WHERE id = 1');
        $dRow = $this->dbalRow($dConn, 'SELECT * FROM users WHERE id = 1');

        self::assertNull($wRow['age']);
        self::assertNull($dRow['age']);
        self::assertNull($wRow['score']);
        self::assertNull($dRow['score']);
        self::assertNull($wRow['bio']);
        self::assertNull($dRow['bio']);
        self::assertNull($wRow['registered_at']);
        self::assertNull($dRow['registered_at']);
    }

    public function test_transaction_commit_produces_identical_state(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $wConn->beginTransaction();
        $user = $this->makeBenchUser('TxUser', 'tx@test.com', 33);
        $workspace->add($user);
        $workspace->push();
        $wConn->commit();

        $dConn->beginTransaction();
        $dConn->insert('users', ['name' => 'TxUser', 'email' => 'tx@test.com', 'age' => 33]);
        $dConn->commit();

        $wRow = $this->dbalRow($wConn, 'SELECT * FROM users WHERE id = 1');
        $dRow = $this->dbalRow($dConn, 'SELECT * FROM users WHERE id = 1');

        self::assertNotFalse($wRow);
        self::assertNotFalse($dRow);
        self::assertSame($dRow['name'], $wRow['name']);
        self::assertSame((int) $dRow['age'], (int) $wRow['age']);
    }

    public function test_transaction_rollback_produces_identical_state(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $wConn->beginTransaction();
        $user = $this->makeBenchUser('RollbackUser', 'rb@test.com');
        $workspace->add($user);
        $workspace->push();
        $wConn->rollBack();

        $dConn->beginTransaction();
        $dConn->insert('users', ['name' => 'RollbackUser', 'email' => 'rb@test.com']);
        $dConn->rollBack();

        $wCount = (int) $wConn->fetchOne('SELECT COUNT(*) FROM users');
        $dCount = (int) $dConn->fetchOne('SELECT COUNT(*) FROM users');

        self::assertSame(0, $wCount);
        self::assertSame(0, $dCount);
    }

    public function test_auto_increment_ids_match(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        for ($i = 1; $i <= 5; $i++) {
            $user = $this->makeBenchUser("User{$i}", "user{$i}@test.com");
            $workspace->add($user);

            $dConn->insert('users', ['name' => "User{$i}", 'email' => "user{$i}@test.com"]);
        }
        $workspace->push();

        $wIds = array_column($this->dbalAll($wConn, 'SELECT id FROM users ORDER BY id'), 'id');
        $dIds = array_column($this->dbalAll($dConn, 'SELECT id FROM users ORDER BY id'), 'id');

        $expected = [1, 2, 3, 4, 5];
        self::assertSame($expected, array_map('intval', $wIds));
        self::assertSame($expected, array_map('intval', $dIds));
    }

    public function test_integer_values_preserved(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $values = [0, 1, -1, 2147483647, -2147483648];
        foreach ($values as $idx => $val) {
            $user = $this->makeBenchUser("IntUser{$idx}", "int{$idx}@test.com", $val);
            $workspace->add($user);

            $dConn->insert('users', ['name' => "IntUser{$idx}", 'email' => "int{$idx}@test.com", 'age' => $val]);
        }
        $workspace->push();

        $wRows = $this->dbalAll($wConn, 'SELECT age FROM users ORDER BY id');
        $dRows = $this->dbalAll($dConn, 'SELECT age FROM users ORDER BY id');

        for ($i = 0; $i < count($values); $i++) {
            self::assertSame((int) $dRows[$i]['age'], (int) $wRows[$i]['age']);
            self::assertSame($values[$i], (int) $wRows[$i]['age']);
        }
    }

    public function test_float_values_preserved(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $values = [0.0, 1.5, -3.14, 99999.99999, 0.000001];
        foreach ($values as $idx => $val) {
            $user = $this->makeBenchUser("FloatUser{$idx}", "float{$idx}@test.com", score: $val);
            $workspace->add($user);

            $dConn->insert('users', ['name' => "FloatUser{$idx}", 'email' => "float{$idx}@test.com", 'score' => $val]);
        }
        $workspace->push();

        $wRows = $this->dbalAll($wConn, 'SELECT score FROM users ORDER BY id');
        $dRows = $this->dbalAll($dConn, 'SELECT score FROM users ORDER BY id');

        for ($i = 0; $i < count($values); $i++) {
            self::assertEqualsWithDelta((float) $dRows[$i]['score'], (float) $wRows[$i]['score'], 0.0001);
        }
    }

    public function test_string_values_preserved(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $strings = [
            "It's a test",
            'She said "hello"',
            "Line1\nLine2",
            'Ünïcödé ñ 日本語',
            '',
            'O\'Reilly & Sons <b>bold</b>',
        ];

        foreach ($strings as $idx => $str) {
            $user = $this->makeBenchUser("StrUser{$idx}", "str{$idx}@test.com", bio: $str);
            $workspace->add($user);

            $dConn->insert('users', ['name' => "StrUser{$idx}", 'email' => "str{$idx}@test.com", 'bio' => $str]);
        }
        $workspace->push();

        $wRows = $this->dbalAll($wConn, 'SELECT bio FROM users ORDER BY id');
        $dRows = $this->dbalAll($dConn, 'SELECT bio FROM users ORDER BY id');

        for ($i = 0; $i < count($strings); $i++) {
            self::assertSame($dRows[$i]['bio'], $wRows[$i]['bio']);
            self::assertSame($strings[$i], $wRows[$i]['bio']);
        }
    }

    public function test_boolean_values_preserved(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $user1 = $this->makeBenchUser('Active', 'active@test.com', active: 1);
        $user2 = $this->makeBenchUser('Inactive', 'inactive@test.com', active: 0);
        $workspace->add($user1);
        $workspace->add($user2);
        $workspace->push();

        $dConn->insert('users', ['name' => 'Active', 'email' => 'active@test.com', 'active' => 1]);
        $dConn->insert('users', ['name' => 'Inactive', 'email' => 'inactive@test.com', 'active' => 0]);

        $wRows = $this->dbalAll($wConn, 'SELECT active FROM users ORDER BY id');
        $dRows = $this->dbalAll($dConn, 'SELECT active FROM users ORDER BY id');

        self::assertSame((int) $dRows[0]['active'], (int) $wRows[0]['active']);
        self::assertSame(1, (int) $wRows[0]['active']);
        self::assertSame((int) $dRows[1]['active'], (int) $wRows[1]['active']);
        self::assertSame(0, (int) $wRows[1]['active']);
    }

    public function test_empty_select_returns_empty_in_both(): void
    {
        [$workspace, $wConn] = $this->createWeaverSetup();
        $dConn = $this->createDbalSetup();

        $wRow = $this->dbalRow($wConn, 'SELECT * FROM users WHERE id = 9999');
        $dRow = $this->dbalRow($dConn, 'SELECT * FROM users WHERE id = 9999');

        self::assertFalse($wRow);
        self::assertFalse($dRow);

        $registry = $workspace->getMapperRegistry();
        $weaverResult = $this->createQueryBuilder($wConn, $registry)
            ->where('id', 9999)
            ->first();

        self::assertNull($weaverResult);
    }
}
