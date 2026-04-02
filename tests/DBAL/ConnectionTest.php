<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\DBAL;

use PDO;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\Platform\SqlitePlatform;

final class ConnectionTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->conn = new Connection($pdo, new SqlitePlatform());
        $this->conn->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)');
    }

    public function test_insert_and_fetch(): void
    {
        $this->conn->insert('users', ['name' => 'Alice', 'email' => 'alice@test.com']);

        $row = $this->conn->fetchAssociative('SELECT * FROM users WHERE name = ?', ['Alice']);

        self::assertIsArray($row);
        self::assertSame('Alice', $row['name']);
        self::assertSame('alice@test.com', $row['email']);
    }

    public function test_update_returns_affected_count(): void
    {
        $this->conn->insert('users', ['name' => 'Bob', 'email' => 'bob@test.com']);
        $this->conn->insert('users', ['name' => 'Bob', 'email' => 'bob2@test.com']);

        $affected = $this->conn->update('users', ['email' => 'updated@test.com'], ['name' => 'Bob']);

        self::assertSame(2, $affected);
    }

    public function test_delete_returns_affected_count(): void
    {
        $this->conn->insert('users', ['name' => 'Charlie', 'email' => 'c@test.com']);
        $this->conn->insert('users', ['name' => 'Charlie', 'email' => 'c2@test.com']);

        $affected = $this->conn->delete('users', ['name' => 'Charlie']);

        self::assertSame(2, $affected);
    }

    public function test_transaction_commit(): void
    {
        $this->conn->beginTransaction();
        $this->conn->insert('users', ['name' => 'TxUser', 'email' => 'tx@test.com']);
        $this->conn->commit();

        $row = $this->conn->fetchAssociative('SELECT * FROM users WHERE name = ?', ['TxUser']);
        self::assertIsArray($row);
        self::assertSame('TxUser', $row['name']);
    }

    public function test_transaction_rollback(): void
    {
        $this->conn->beginTransaction();
        $this->conn->insert('users', ['name' => 'RollbackUser', 'email' => 'rb@test.com']);
        $this->conn->rollBack();

        $row = $this->conn->fetchAssociative('SELECT * FROM users WHERE name = ?', ['RollbackUser']);
        self::assertFalse($row);
    }

    public function test_prepared_statement(): void
    {
        $this->conn->insert('users', ['name' => 'Prepared', 'email' => 'prep@test.com']);

        $stmt = $this->conn->prepare('SELECT * FROM users WHERE name = ?');
        $result = $stmt->execute(['Prepared']);

        $row = $result->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame('Prepared', $row['name']);
    }

    public function test_quote_value(): void
    {
        $quoted = $this->conn->quote("it's a test");
        self::assertStringContainsString("it''s a test", $quoted);
    }

    public function test_quote_identifier(): void
    {
        $quoted = $this->conn->quoteIdentifier('users');
        self::assertSame('"users"', $quoted);
    }

    public function test_fetch_all_associative(): void
    {
        $this->conn->insert('users', ['name' => 'A', 'email' => 'a@t.com']);
        $this->conn->insert('users', ['name' => 'B', 'email' => 'b@t.com']);

        $rows = $this->conn->fetchAllAssociative('SELECT * FROM users ORDER BY name');
        self::assertCount(2, $rows);
    }

    public function test_fetch_one(): void
    {
        $this->conn->insert('users', ['name' => 'Single', 'email' => 's@t.com']);

        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM users');
        self::assertEquals(1, $count);
    }

    public function test_is_transaction_active(): void
    {
        self::assertFalse($this->conn->isTransactionActive());
        $this->conn->beginTransaction();
        self::assertTrue($this->conn->isTransactionActive());
        $this->conn->rollBack();
        self::assertFalse($this->conn->isTransactionActive());
    }

    public function test_last_insert_id(): void
    {
        $this->conn->insert('users', ['name' => 'Last', 'email' => 'last@t.com']);
        $id = $this->conn->lastInsertId();
        self::assertNotEmpty($id);
    }
}
