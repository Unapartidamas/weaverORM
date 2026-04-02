<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Bridge;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Transaction\TransactionManager;

class BenchUserWorkspace
{
    public ?int $id = null;
    public string $name = '';
    public string $email = '';
    public ?int $age = null;
    public ?float $score = null;
    public ?string $bio = null;
    public int $active = 1;
}

class BenchUserWorkspaceMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return BenchUserWorkspace::class;
    }

    public function getTableName(): string
    {
        return 'bench_users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id', 'id', 'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string', length: 255),
            new ColumnDefinition('email', 'email', 'string', length: 255),
            new ColumnDefinition('age', 'age', 'integer', nullable: true),
            new ColumnDefinition('score', 'score', 'float', nullable: true),
            new ColumnDefinition('bio', 'bio', 'text', nullable: true),
            new ColumnDefinition('active', 'active', 'integer'),
        ];
    }
}

final class WorkspaceEquivalenceTest extends TestCase
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE bench_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            age INTEGER,
            score REAL,
            bio TEXT,
            active INTEGER DEFAULT 1
        )
    SQL;

    private function createWeaverSetup(): array
    {
        $conn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement(self::SCHEMA);

        $registry = new MapperRegistry();
        $registry->register(new BenchUserWorkspaceMapper());

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

    private function makeUser(
        string $name = 'Test',
        string $email = 'test@test.com',
        ?int $age = null,
        ?float $score = null,
        ?string $bio = null,
        int $active = 1,
    ): BenchUserWorkspace {
        $u = new BenchUserWorkspace();
        $u->name   = $name;
        $u->email  = $email;
        $u->age    = $age;
        $u->score  = $score;
        $u->bio    = $bio;
        $u->active = $active;

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

    private function dbalCount(Connection $conn): int
    {
        return (int) $conn->fetchOne('SELECT COUNT(*) FROM bench_users');
    }

    private function assertRowsMatch(Connection $wConn, Connection $dConn): void
    {
        $wRows = $this->dbalAll($wConn, 'SELECT * FROM bench_users ORDER BY id');
        $dRows = $this->dbalAll($dConn, 'SELECT * FROM bench_users ORDER BY id');

        self::assertCount(count($dRows), $wRows, 'Row count mismatch');

        for ($i = 0; $i < count($dRows); $i++) {
            foreach (['name', 'email', 'bio'] as $field) {
                self::assertSame($dRows[$i][$field], $wRows[$i][$field], "Field {$field} mismatch at row {$i}");
            }
            self::assertSame(
                $dRows[$i]['age'] === null ? null : (int) $dRows[$i]['age'],
                $wRows[$i]['age'] === null ? null : (int) $wRows[$i]['age'],
                "Field age mismatch at row {$i}",
            );
            self::assertEquals(
                $dRows[$i]['score'] === null ? null : (float) $dRows[$i]['score'],
                $wRows[$i]['score'] === null ? null : (float) $wRows[$i]['score'],
                "Field score mismatch at row {$i}",
            );
            self::assertSame(
                (int) ($dRows[$i]['active'] ?? 1),
                (int) ($wRows[$i]['active'] ?? 1),
                "Field active mismatch at row {$i}",
            );
        }
    }

    // ── add() equivalence ──

    public function test_add_single_entity_matches_dbal_insert(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $ws->add($this->makeUser('Alice', 'alice@test.com', 30, 9.5, 'Hello', 1));
        $ws->push();

        $dc->insert('bench_users', ['name' => 'Alice', 'email' => 'alice@test.com', 'age' => 30, 'score' => 9.5, 'bio' => 'Hello', 'active' => 1]);

        $this->assertRowsMatch($wc, $dc);
    }

    public function test_add_multiple_entities_matches_dbal_inserts(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        for ($i = 1; $i <= 5; $i++) {
            $ws->add($this->makeUser("User{$i}", "u{$i}@t.com", $i * 10));
            $dc->insert('bench_users', ['name' => "User{$i}", 'email' => "u{$i}@t.com", 'age' => $i * 10]);
        }
        $ws->push();

        $this->assertRowsMatch($wc, $dc);
    }

    public function test_add_entity_with_null_fields_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $ws->add($this->makeUser('NullUser', 'null@t.com'));
        $ws->push();

        $dc->insert('bench_users', ['name' => 'NullUser', 'email' => 'null@t.com']);

        $wRow = $this->dbalRow($wc, 'SELECT * FROM bench_users WHERE id = 1');
        $dRow = $this->dbalRow($dc, 'SELECT * FROM bench_users WHERE id = 1');

        self::assertNull($wRow['age']);
        self::assertNull($dRow['age']);
        self::assertNull($wRow['score']);
        self::assertNull($dRow['score']);
        self::assertNull($wRow['bio']);
        self::assertNull($dRow['bio']);
    }

    public function test_add_entity_with_empty_string_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $ws->add($this->makeUser('Empty', 'empty@t.com', bio: ''));
        $ws->push();

        $dc->insert('bench_users', ['name' => 'Empty', 'email' => 'empty@t.com', 'bio' => '']);

        $wRow = $this->dbalRow($wc, 'SELECT * FROM bench_users WHERE id = 1');
        $dRow = $this->dbalRow($dc, 'SELECT * FROM bench_users WHERE id = 1');

        self::assertSame('', $wRow['bio']);
        self::assertSame($dRow['bio'], $wRow['bio']);
    }

    public function test_add_entity_with_zero_values_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $ws->add($this->makeUser('Zero', 'zero@t.com', 0, 0.0, null, 0));
        $ws->push();

        $dc->insert('bench_users', ['name' => 'Zero', 'email' => 'zero@t.com', 'age' => 0, 'score' => 0.0, 'active' => 0]);

        $wRow = $this->dbalRow($wc, 'SELECT * FROM bench_users WHERE id = 1');
        $dRow = $this->dbalRow($dc, 'SELECT * FROM bench_users WHERE id = 1');

        self::assertSame((int) $dRow['age'], (int) $wRow['age']);
        self::assertSame(0, (int) $wRow['age']);
        self::assertEquals(0.0, (float) $wRow['score']);
        self::assertSame((int) $dRow['active'], (int) $wRow['active']);
        self::assertSame(0, (int) $wRow['active']);
    }

    public function test_add_entity_with_negative_numbers_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $ws->add($this->makeUser('Neg', 'neg@t.com', -42, -3.14));
        $ws->push();

        $dc->insert('bench_users', ['name' => 'Neg', 'email' => 'neg@t.com', 'age' => -42, 'score' => -3.14]);

        $this->assertRowsMatch($wc, $dc);
    }

    public function test_add_entity_with_max_int_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $maxInt = 2147483647;
        $ws->add($this->makeUser('MaxInt', 'max@t.com', $maxInt));
        $ws->push();

        $dc->insert('bench_users', ['name' => 'MaxInt', 'email' => 'max@t.com', 'age' => $maxInt]);

        $wRow = $this->dbalRow($wc, 'SELECT * FROM bench_users WHERE id = 1');
        $dRow = $this->dbalRow($dc, 'SELECT * FROM bench_users WHERE id = 1');

        self::assertSame((int) $dRow['age'], (int) $wRow['age']);
        self::assertSame($maxInt, (int) $wRow['age']);
    }

    public function test_add_entity_with_unicode_text_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $unicode = 'Ünïcödé ñ 日本語 中文 العربية 한국어 🎉';
        $ws->add($this->makeUser('Unicode', 'uni@t.com', bio: $unicode));
        $ws->push();

        $dc->insert('bench_users', ['name' => 'Unicode', 'email' => 'uni@t.com', 'bio' => $unicode]);

        $wRow = $this->dbalRow($wc, 'SELECT * FROM bench_users WHERE id = 1');
        $dRow = $this->dbalRow($dc, 'SELECT * FROM bench_users WHERE id = 1');

        self::assertSame($unicode, $wRow['bio']);
        self::assertSame($dRow['bio'], $wRow['bio']);
    }

    public function test_add_entity_with_special_chars_quotes_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $special = "It's a \"test\" with <html> & O'Reilly\n\ttabs";
        $ws->add($this->makeUser('Special', 'spec@t.com', bio: $special));
        $ws->push();

        $dc->insert('bench_users', ['name' => 'Special', 'email' => 'spec@t.com', 'bio' => $special]);

        $wRow = $this->dbalRow($wc, 'SELECT * FROM bench_users WHERE id = 1');
        $dRow = $this->dbalRow($dc, 'SELECT * FROM bench_users WHERE id = 1');

        self::assertSame($special, $wRow['bio']);
        self::assertSame($dRow['bio'], $wRow['bio']);
    }

    public function test_add_entity_with_very_long_string_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $longStr = str_repeat('A', 10240);
        $ws->add($this->makeUser('Long', 'long@t.com', bio: $longStr));
        $ws->push();

        $dc->insert('bench_users', ['name' => 'Long', 'email' => 'long@t.com', 'bio' => $longStr]);

        $wRow = $this->dbalRow($wc, 'SELECT * FROM bench_users WHERE id = 1');
        $dRow = $this->dbalRow($dc, 'SELECT * FROM bench_users WHERE id = 1');

        self::assertSame(10240, strlen($wRow['bio']));
        self::assertSame($dRow['bio'], $wRow['bio']);
    }

    public function test_add_100_entities_matches_dbal_100_inserts(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        for ($i = 1; $i <= 100; $i++) {
            $ws->add($this->makeUser("User{$i}", "u{$i}@t.com", $i));
            $dc->insert('bench_users', ['name' => "User{$i}", 'email' => "u{$i}@t.com", 'age' => $i]);
        }
        $ws->push();

        self::assertSame(100, $this->dbalCount($wc));
        self::assertSame(100, $this->dbalCount($dc));
        $this->assertRowsMatch($wc, $dc);
    }

    public function test_add_entity_auto_increment_ids_sequential(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        for ($i = 1; $i <= 5; $i++) {
            $ws->add($this->makeUser("U{$i}", "u{$i}@t.com"));
            $dc->insert('bench_users', ['name' => "U{$i}", 'email' => "u{$i}@t.com"]);
        }
        $ws->push();

        $wIds = array_column($this->dbalAll($wc, 'SELECT id FROM bench_users ORDER BY id'), 'id');
        $dIds = array_column($this->dbalAll($dc, 'SELECT id FROM bench_users ORDER BY id'), 'id');

        self::assertSame([1, 2, 3, 4, 5], array_map('intval', $wIds));
        self::assertSame(array_map('intval', $dIds), array_map('intval', $wIds));
    }

    // ── delete() equivalence ──

    public function test_delete_by_pk_matches_dbal_delete(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('Del', 'del@t.com');
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'Del', 'email' => 'del@t.com']);

        $ws->delete($u);
        $ws->push($u);
        $dc->delete('bench_users', ['id' => 1]);

        self::assertSame(0, $this->dbalCount($wc));
        self::assertSame(0, $this->dbalCount($dc));
    }

    public function test_delete_multiple_entities_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $u = $this->makeUser("U{$i}", "u{$i}@t.com");
            $ws->add($u);
            $users[] = $u;
            $dc->insert('bench_users', ['name' => "U{$i}", 'email' => "u{$i}@t.com"]);
        }
        $ws->push();

        $ws->delete($users[0]);
        $ws->delete($users[2]);
        $ws->delete($users[4]);
        $ws->push();

        $dc->delete('bench_users', ['id' => 1]);
        $dc->delete('bench_users', ['id' => 3]);
        $dc->delete('bench_users', ['id' => 5]);

        self::assertSame(2, $this->dbalCount($wc));
        self::assertSame(2, $this->dbalCount($dc));
        $this->assertRowsMatch($wc, $dc);
    }

    public function test_delete_then_reinsert_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('ReIns', 'reins@t.com', 25);
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'ReIns', 'email' => 'reins@t.com', 'age' => 25]);

        $ws->delete($u);
        $ws->push($u);
        $dc->delete('bench_users', ['id' => 1]);

        $u2 = $this->makeUser('ReIns2', 'reins2@t.com', 30);
        $ws->add($u2);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'ReIns2', 'email' => 'reins2@t.com', 'age' => 30]);

        self::assertSame(1, $this->dbalCount($wc));
        self::assertSame(1, $this->dbalCount($dc));

        $wRow = $this->dbalRow($wc, 'SELECT * FROM bench_users ORDER BY id DESC LIMIT 1');
        $dRow = $this->dbalRow($dc, 'SELECT * FROM bench_users ORDER BY id DESC LIMIT 1');

        self::assertSame($dRow['name'], $wRow['name']);
        self::assertSame('ReIns2', $wRow['name']);
    }

    public function test_delete_nonexistent_entity_is_noop(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Ghost', 'ghost@t.com');
        $u->id = 9999;

        $ws->delete($u);
        $ws->push($u);

        self::assertSame(0, $this->dbalCount($wc));
    }

    public function test_delete_all_entities_leaves_empty_table(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $users = [];
        for ($i = 1; $i <= 3; $i++) {
            $u = $this->makeUser("U{$i}", "u{$i}@t.com");
            $ws->add($u);
            $users[] = $u;
            $dc->insert('bench_users', ['name' => "U{$i}", 'email' => "u{$i}@t.com"]);
        }
        $ws->push();

        foreach ($users as $u) {
            $ws->delete($u);
        }
        $ws->push();

        $dc->executeStatement('DELETE FROM bench_users');

        self::assertSame(0, $this->dbalCount($wc));
        self::assertSame(0, $this->dbalCount($dc));
    }

    // ── push() equivalence ──

    public function test_push_persists_all_pending_adds(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        for ($i = 1; $i <= 3; $i++) {
            $ws->add($this->makeUser("U{$i}", "u{$i}@t.com"));
            $dc->insert('bench_users', ['name' => "U{$i}", 'email' => "u{$i}@t.com"]);
        }
        $ws->push();

        self::assertSame(3, $this->dbalCount($wc));
        $this->assertRowsMatch($wc, $dc);
    }

    public function test_push_persists_all_pending_updates(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $users = [];
        for ($i = 1; $i <= 3; $i++) {
            $u = $this->makeUser("U{$i}", "u{$i}@t.com", $i * 10);
            $ws->add($u);
            $users[] = $u;
            $dc->insert('bench_users', ['name' => "U{$i}", 'email' => "u{$i}@t.com", 'age' => $i * 10]);
        }
        $ws->push();

        foreach ($users as $idx => $u) {
            $u->age = ($idx + 1) * 100;
        }
        $ws->push();

        for ($i = 1; $i <= 3; $i++) {
            $dc->update('bench_users', ['age' => $i * 100], ['id' => $i]);
        }

        $this->assertRowsMatch($wc, $dc);
    }

    public function test_push_persists_all_pending_deletes(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $users = [];
        for ($i = 1; $i <= 3; $i++) {
            $u = $this->makeUser("U{$i}", "u{$i}@t.com");
            $ws->add($u);
            $users[] = $u;
            $dc->insert('bench_users', ['name' => "U{$i}", 'email' => "u{$i}@t.com"]);
        }
        $ws->push();

        foreach ($users as $u) {
            $ws->delete($u);
        }
        $ws->push();

        $dc->executeStatement('DELETE FROM bench_users');

        self::assertSame(0, $this->dbalCount($wc));
        self::assertSame(0, $this->dbalCount($dc));
    }

    public function test_push_mixed_adds_updates_deletes(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u1 = $this->makeUser('Keep', 'keep@t.com', 10);
        $u2 = $this->makeUser('Del', 'del@t.com', 20);
        $ws->add($u1);
        $ws->add($u2);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'Keep', 'email' => 'keep@t.com', 'age' => 10]);
        $dc->insert('bench_users', ['name' => 'Del', 'email' => 'del@t.com', 'age' => 20]);

        $u1->age = 99;
        $ws->delete($u2);
        $u3 = $this->makeUser('New', 'new@t.com', 30);
        $ws->add($u3);
        $ws->push();

        $dc->update('bench_users', ['age' => 99], ['id' => 1]);
        $dc->delete('bench_users', ['id' => 2]);
        $dc->insert('bench_users', ['name' => 'New', 'email' => 'new@t.com', 'age' => 30]);

        self::assertSame(2, $this->dbalCount($wc));
        self::assertSame(2, $this->dbalCount($dc));
        $this->assertRowsMatch($wc, $dc);
    }

    public function test_push_without_changes_is_noop(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('NoOp', 'noop@t.com');
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'NoOp', 'email' => 'noop@t.com']);

        $ws->push();

        $this->assertRowsMatch($wc, $dc);
    }

    public function test_push_single_entity_only_pushes_that_entity(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u1 = $this->makeUser('U1', 'u1@t.com', 10);
        $u2 = $this->makeUser('U2', 'u2@t.com', 20);
        $ws->add($u1);
        $ws->add($u2);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'U1', 'email' => 'u1@t.com', 'age' => 10]);
        $dc->insert('bench_users', ['name' => 'U2', 'email' => 'u2@t.com', 'age' => 20]);

        $u1->age = 99;
        $u2->age = 88;

        $ws->push($u1);
        $dc->update('bench_users', ['age' => 99], ['id' => 1]);

        $wRow1 = $this->dbalRow($wc, 'SELECT age FROM bench_users WHERE id = 1');
        $wRow2 = $this->dbalRow($wc, 'SELECT age FROM bench_users WHERE id = 2');

        self::assertSame(99, (int) $wRow1['age']);
        self::assertSame(20, (int) $wRow2['age']);
    }

    public function test_multiple_pushes_produce_correct_cumulative_state(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('Cumul', 'cumul@t.com', 1);
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'Cumul', 'email' => 'cumul@t.com', 'age' => 1]);

        $u->age = 2;
        $ws->push();
        $dc->update('bench_users', ['age' => 2], ['id' => 1]);

        $u->age = 3;
        $ws->push();
        $dc->update('bench_users', ['age' => 3], ['id' => 1]);

        $this->assertRowsMatch($wc, $dc);
        $wRow = $this->dbalRow($wc, 'SELECT age FROM bench_users WHERE id = 1');
        self::assertSame(3, (int) $wRow['age']);
    }

    // ── update (modify+push) equivalence ──

    public function test_update_single_field_matches_dbal_update(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('Upd', 'upd@t.com', 25);
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'Upd', 'email' => 'upd@t.com', 'age' => 25]);

        $u->name = 'Updated';
        $ws->push();
        $dc->update('bench_users', ['name' => 'Updated'], ['id' => 1]);

        $this->assertRowsMatch($wc, $dc);
    }

    public function test_update_multiple_fields_matches_dbal_update(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('Multi', 'multi@t.com', 20, 5.0, 'Old bio');
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'Multi', 'email' => 'multi@t.com', 'age' => 20, 'score' => 5.0, 'bio' => 'Old bio']);

        $u->name  = 'MultiUpd';
        $u->age   = 30;
        $u->score = 9.9;
        $u->bio   = 'New bio';
        $ws->push();
        $dc->update('bench_users', ['name' => 'MultiUpd', 'age' => 30, 'score' => 9.9, 'bio' => 'New bio'], ['id' => 1]);

        $this->assertRowsMatch($wc, $dc);
    }

    public function test_update_to_null_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('NullUpd', 'nullupd@t.com', 25, 5.0, 'Bio');
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'NullUpd', 'email' => 'nullupd@t.com', 'age' => 25, 'score' => 5.0, 'bio' => 'Bio']);

        $u->age   = null;
        $u->score = null;
        $u->bio   = null;
        $ws->push();
        $dc->update('bench_users', ['age' => null, 'score' => null, 'bio' => null], ['id' => 1]);

        $wRow = $this->dbalRow($wc, 'SELECT * FROM bench_users WHERE id = 1');
        $dRow = $this->dbalRow($dc, 'SELECT * FROM bench_users WHERE id = 1');

        self::assertNull($wRow['age']);
        self::assertNull($wRow['score']);
        self::assertNull($wRow['bio']);
        self::assertSame($dRow['age'], $wRow['age']);
        self::assertSame($dRow['score'], $wRow['score']);
        self::assertSame($dRow['bio'], $wRow['bio']);
    }

    public function test_update_to_empty_string_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('EmptyUpd', 'emptyupd@t.com', bio: 'Has bio');
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'EmptyUpd', 'email' => 'emptyupd@t.com', 'bio' => 'Has bio']);

        $u->bio = '';
        $ws->push();
        $dc->update('bench_users', ['bio' => ''], ['id' => 1]);

        $wRow = $this->dbalRow($wc, 'SELECT bio FROM bench_users WHERE id = 1');
        $dRow = $this->dbalRow($dc, 'SELECT bio FROM bench_users WHERE id = 1');

        self::assertSame('', $wRow['bio']);
        self::assertSame($dRow['bio'], $wRow['bio']);
    }

    public function test_update_numeric_field_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('NumUpd', 'numupd@t.com', 10, 1.1);
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'NumUpd', 'email' => 'numupd@t.com', 'age' => 10, 'score' => 1.1]);

        $u->age   = -999;
        $u->score = 99999.99;
        $ws->push();
        $dc->update('bench_users', ['age' => -999, 'score' => 99999.99], ['id' => 1]);

        $this->assertRowsMatch($wc, $dc);
    }

    public function test_update_same_value_produces_no_change(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Same', 'same@t.com', 25);
        $ws->add($u);
        $ws->push();

        $u->name = 'Same';
        $u->age  = 25;

        $before = $this->dbalRow($wc, 'SELECT * FROM bench_users WHERE id = 1');
        $ws->push();
        $after = $this->dbalRow($wc, 'SELECT * FROM bench_users WHERE id = 1');

        self::assertSame($before, $after);
        self::assertFalse($ws->isDirty($u));
    }

    public function test_update_after_reload_matches_dbal(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('Reload', 'reload@t.com', 10);
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'Reload', 'email' => 'reload@t.com', 'age' => 10]);

        $ws->reload($u);
        $u->age = 50;
        $ws->push();
        $dc->update('bench_users', ['age' => 50], ['id' => 1]);

        $this->assertRowsMatch($wc, $dc);
    }

    // ── reload() equivalence ──

    public function test_reload_restores_original_values_from_db(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Orig', 'orig@t.com', 30);
        $ws->add($u);
        $ws->push();

        $u->name = 'Changed';
        $u->age  = 99;

        $ws->reload($u);

        self::assertSame('Orig', $u->name);
        self::assertSame(30, $u->age);
    }

    public function test_reload_after_external_update_reflects_new_values(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Ext', 'ext@t.com', 10);
        $ws->add($u);
        $ws->push();

        $wc->update('bench_users', ['name' => 'ExternallyChanged', 'age' => 77], ['id' => 1]);

        $ws->reload($u);

        self::assertSame('ExternallyChanged', $u->name);
        self::assertSame(77, $u->age);
    }

    // ── untrack() equivalence ──

    public function test_untrack_entity_changes_not_persisted_on_push(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Untrack', 'untrack@t.com', 10);
        $ws->add($u);
        $ws->push();

        $u->name = 'ChangedButUntracked';
        $ws->untrack($u);
        $ws->push();

        $row = $this->dbalRow($wc, 'SELECT name FROM bench_users WHERE id = 1');
        self::assertSame('Untrack', $row['name']);
    }

    public function test_untrack_then_readd_works(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('ReAdd', 'readd@t.com', 10);
        $ws->add($u);
        $ws->push();

        $ws->untrack($u);

        $u2 = $this->makeUser('ReAdded', 'readded@t.com', 20);
        $ws->add($u2);
        $ws->push();

        self::assertSame(2, $this->dbalCount($wc));
    }

    // ── isTracked/isDirty/isNew/isManaged/isDeleted ──

    public function test_isTracked_true_after_add(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Track', 'track@t.com');
        $ws->add($u);

        self::assertTrue($ws->isTracked($u));
    }

    public function test_isTracked_false_after_untrack(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('UnTr', 'untr@t.com');
        $ws->add($u);
        $ws->push();
        $ws->untrack($u);

        self::assertFalse($ws->isTracked($u));
    }

    public function test_isDirty_false_on_clean_entity(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Clean', 'clean@t.com', 10);
        $ws->add($u);
        $ws->push();

        self::assertFalse($ws->isDirty($u));
    }

    public function test_isDirty_true_after_modification(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Dirty', 'dirty@t.com', 10);
        $ws->add($u);
        $ws->push();

        $u->age = 99;

        self::assertTrue($ws->isDirty($u));
    }

    public function test_isNew_true_before_push(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('New', 'new@t.com');
        $ws->add($u);

        self::assertTrue($ws->isNew($u));
    }

    public function test_isNew_false_after_push(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('NotNew', 'notnew@t.com');
        $ws->add($u);
        $ws->push();

        self::assertFalse($ws->isNew($u));
    }

    public function test_isManaged_true_after_push(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Managed', 'managed@t.com');
        $ws->add($u);
        $ws->push();

        self::assertTrue($ws->isManaged($u));
    }

    public function test_isDeleted_true_after_delete(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Deleted', 'deleted@t.com');
        $ws->add($u);
        $ws->push();

        $ws->delete($u);

        self::assertTrue($ws->isDeleted($u));
    }

    // ── getChanges/getDirtyProperties/getOriginalValue ──

    public function test_getChanges_returns_modified_fields(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('Chg', 'chg@t.com', 10, 5.0);
        $ws->add($u);
        $ws->push();

        $u->age   = 20;
        $u->score = 9.9;

        $changes = $ws->getChanges($u);

        self::assertArrayHasKey('age', $changes);
        self::assertArrayHasKey('score', $changes);
        self::assertSame(10, $changes['age']['old']);
        self::assertSame(20, $changes['age']['new']);
        self::assertSame(5.0, $changes['score']['old']);
        self::assertSame(9.9, $changes['score']['new']);
    }

    public function test_getChanges_empty_on_clean_entity(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('NoChg', 'nochg@t.com', 10);
        $ws->add($u);
        $ws->push();

        self::assertSame([], $ws->getChanges($u));
    }

    public function test_getDirtyProperties_lists_changed_props(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('DirtyP', 'dirtyp@t.com', 10, 5.0);
        $ws->add($u);
        $ws->push();

        $u->name = 'Changed';
        $u->age  = 99;

        $props = $ws->getDirtyProperties($u);

        self::assertContains('name', $props);
        self::assertContains('age', $props);
        self::assertNotContains('email', $props);
        self::assertNotContains('score', $props);
    }

    public function test_getOriginalValue_returns_pre_modification_value(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('OrigVal', 'origval@t.com', 42);
        $ws->add($u);
        $ws->push();

        $u->age = 99;

        self::assertSame(42, $ws->getOriginalValue($u, 'age'));
    }

    // ── reset() equivalence ──

    public function test_reset_clears_all_tracked_entities(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u1 = $this->makeUser('R1', 'r1@t.com');
        $u2 = $this->makeUser('R2', 'r2@t.com');
        $ws->add($u1);
        $ws->add($u2);
        $ws->push();

        $ws->reset();

        self::assertFalse($ws->isTracked($u1));
        self::assertFalse($ws->isTracked($u2));
        self::assertFalse($ws->isManaged($u1));
        self::assertFalse($ws->isManaged($u2));
    }

    public function test_reset_pending_changes_not_persisted(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $u = $this->makeUser('ResetPend', 'resetpend@t.com');
        $ws->add($u);
        $ws->reset();
        $ws->push();

        self::assertSame(0, $this->dbalCount($wc));
    }

    // ── merge() equivalence ──

    public function test_merge_detached_entity_updates_db(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('Merge', 'merge@t.com', 10);
        $ws->add($u);
        $ws->push();
        $dc->insert('bench_users', ['name' => 'Merge', 'email' => 'merge@t.com', 'age' => 10]);

        $ws->untrack($u);

        $u->name = 'Merged';
        $u->age  = 50;

        $managed = $ws->merge($u);
        $ws->push();
        $dc->update('bench_users', ['name' => 'Merged', 'age' => 50], ['id' => 1]);

        $this->assertRowsMatch($wc, $dc);
        self::assertSame('Merged', $managed->name);
    }

    // ── upsert() equivalence ──

    public function test_upsert_inserts_new_entity(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('Upsert', 'upsert@t.com', 25);
        $ws->upsert($u);
        $dc->insert('bench_users', ['name' => 'Upsert', 'email' => 'upsert@t.com', 'age' => 25]);

        $this->assertRowsMatch($wc, $dc);
    }

    public function test_upsert_updates_existing_entity(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $u = $this->makeUser('UpsOrig', 'upsorig@t.com', 10);
        $ws->upsert($u);
        $dc->insert('bench_users', ['name' => 'UpsOrig', 'email' => 'upsorig@t.com', 'age' => 10]);

        self::assertSame(1, $this->dbalCount($wc));

        $u->name = 'UpsUpdated';
        $u->age  = 99;
        $ws->push();
        $dc->update('bench_users', ['name' => 'UpsUpdated', 'age' => 99], ['id' => 1]);

        self::assertSame(1, $this->dbalCount($wc));
        $this->assertRowsMatch($wc, $dc);
    }

    // ── addBatch/pushBatch ──

    public function test_addBatch_and_pushBatch_matches_individual_adds(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $entities = [];
        for ($i = 1; $i <= 10; $i++) {
            $entities[] = $this->makeUser("Batch{$i}", "batch{$i}@t.com", $i * 5);
            $dc->insert('bench_users', ['name' => "Batch{$i}", 'email' => "batch{$i}@t.com", 'age' => $i * 5]);
        }

        $ws->addBatch($entities);
        $ws->pushBatch();

        self::assertSame(10, $this->dbalCount($wc));
        self::assertSame(10, $this->dbalCount($dc));
        $this->assertRowsMatch($wc, $dc);
    }

    public function test_pushBatch_returns_correct_affected_count(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();

        $entities = [];
        for ($i = 1; $i <= 7; $i++) {
            $entities[] = $this->makeUser("PB{$i}", "pb{$i}@t.com");
        }

        $ws->addBatch($entities);
        $affected = $ws->pushBatch();

        self::assertSame(7, $affected);
        self::assertSame(7, $this->dbalCount($wc));
    }

    // ── Transactions ──

    public function test_transaction_commit_persists_all(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $wc->beginTransaction();
        $ws->add($this->makeUser('TxUser', 'tx@t.com', 33));
        $ws->push();
        $wc->commit();

        $dc->beginTransaction();
        $dc->insert('bench_users', ['name' => 'TxUser', 'email' => 'tx@t.com', 'age' => 33]);
        $dc->commit();

        $this->assertRowsMatch($wc, $dc);
    }

    public function test_transaction_rollback_reverts_all(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $wc->beginTransaction();
        $ws->add($this->makeUser('RbUser', 'rb@t.com'));
        $ws->push();
        $wc->rollBack();

        $dc->beginTransaction();
        $dc->insert('bench_users', ['name' => 'RbUser', 'email' => 'rb@t.com']);
        $dc->rollBack();

        self::assertSame(0, $this->dbalCount($wc));
        self::assertSame(0, $this->dbalCount($dc));
    }

    public function test_nested_transaction_savepoint_works(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $tm = new TransactionManager($wc);

        $tm->begin();
        $ws->add($this->makeUser('Outer', 'outer@t.com'));
        $ws->push();

        $tm->savepoint('sp1');
        $ws->add($this->makeUser('Inner', 'inner@t.com'));
        $ws->push();
        $tm->releaseSavepoint('sp1');

        $tm->commit();

        $dc->beginTransaction();
        $dc->insert('bench_users', ['name' => 'Outer', 'email' => 'outer@t.com']);
        $dc->insert('bench_users', ['name' => 'Inner', 'email' => 'inner@t.com']);
        $dc->commit();

        self::assertSame(2, $this->dbalCount($wc));
        $this->assertRowsMatch($wc, $dc);
    }

    public function test_nested_rollback_only_reverts_inner(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $tm = new TransactionManager($wc);

        $tm->begin();
        $ws->add($this->makeUser('Outer', 'outer@t.com'));
        $ws->push();

        $tm->savepoint('sp1');
        $ws->add($this->makeUser('Inner', 'inner@t.com'));
        $ws->push();
        $tm->rollbackTo('sp1');

        $tm->commit();

        $dc->beginTransaction();
        $dc->insert('bench_users', ['name' => 'Outer', 'email' => 'outer@t.com']);
        $dc->createSavepoint('sp1');
        $dc->insert('bench_users', ['name' => 'Inner', 'email' => 'inner@t.com']);
        $dc->rollbackSavepoint('sp1');
        $dc->commit();

        self::assertSame(1, $this->dbalCount($wc));
        self::assertSame(1, $this->dbalCount($dc));
        $this->assertRowsMatch($wc, $dc);
    }

    public function test_transactional_callback_commits_on_success(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $tm = new TransactionManager($wc);

        $tm->transactional(function () use ($ws) {
            $ws->add($this->makeUser('TxCb', 'txcb@t.com', 44));
            $ws->push();
        });

        $dc->beginTransaction();
        $dc->insert('bench_users', ['name' => 'TxCb', 'email' => 'txcb@t.com', 'age' => 44]);
        $dc->commit();

        self::assertSame(1, $this->dbalCount($wc));
        $this->assertRowsMatch($wc, $dc);
    }

    public function test_transactional_callback_rollbacks_on_exception(): void
    {
        [$ws, $wc] = $this->createWeaverSetup();
        $dc = $this->createDbalSetup();

        $tm = new TransactionManager($wc);

        try {
            $tm->transactional(function () use ($ws) {
                $ws->add($this->makeUser('TxFail', 'txfail@t.com'));
                $ws->push();
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
        }

        try {
            $dc->beginTransaction();
            $dc->insert('bench_users', ['name' => 'TxFail', 'email' => 'txfail@t.com']);
            throw new \RuntimeException('fail');
        } catch (\RuntimeException) {
            $dc->rollBack();
        }

        self::assertSame(0, $this->dbalCount($wc));
        self::assertSame(0, $this->dbalCount($dc));
    }
}
