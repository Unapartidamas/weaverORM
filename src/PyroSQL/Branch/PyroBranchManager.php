<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Branch;

use Weaver\ORM\PyroSQL\Exception\BranchNotFoundException;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

final readonly class PyroBranchManager
{
    public function __construct(
        private \Weaver\ORM\DBAL\Connection $connection,
        private PyroSqlDriver $driver,
    ) {}

    public function create(
        string $name,
        string $from = 'main',
        ?\DateTimeImmutable $asOf = null,
    ): PyroBranch {
        $this->driver->assertSupports('branching');

        $sql = "CREATE BRANCH " . $this->connection->quoteIdentifier($name)
             . " FROM " . $this->connection->quoteIdentifier($from);

        if ($asOf instanceof \DateTimeImmutable) {
            $sql .= " AS OF TIMESTAMP '" . $asOf->format('Y-m-d H:i:s') . "'";
        }

        $this->connection->executeStatement($sql);

        return $this->get($name);
    }

    public function get(string $name): PyroBranch
    {
        $row = $this->connection->fetchAssociative(
            "SELECT name, parent_name, created_at FROM pyrosql_branches WHERE name = ?",
            [$name]
        );

        if ($row === false) {
            throw BranchNotFoundException::forName($name);
        }

        return new PyroBranch(
            name:       is_scalar($row['name']) ? (string) $row['name'] : '',
            parentName: is_scalar($row['parent_name'] ?? null) ? (string) $row['parent_name'] : 'main',
            createdAt:  new \DateTimeImmutable(is_scalar($row['created_at']) ? (string) $row['created_at'] : 'now'),
            connection: $this->connection,
        );
    }

    public function list(): array
    {
        $this->driver->assertSupports('branching');

        $rows = $this->connection->fetchAllAssociative(
            "SELECT name, parent_name, created_at FROM pyrosql_branches ORDER BY created_at ASC"
        );

        return array_map(
            fn (array $row): PyroBranch => new PyroBranch(
                name:       is_scalar($row['name']) ? (string) $row['name'] : '',
                parentName: is_scalar($row['parent_name'] ?? null) ? (string) $row['parent_name'] : 'main',
                createdAt:  new \DateTimeImmutable(is_scalar($row['created_at']) ? (string) $row['created_at'] : 'now'),
                connection: $this->connection,
            ),
            $rows,
        );
    }

    public function delete(string $name): void
    {
        $this->driver->assertSupports('branching');

        if (!$this->exists($name)) {
            throw BranchNotFoundException::forName($name);
        }

        $this->connection->executeStatement("DROP BRANCH " . $this->connection->quoteIdentifier($name));
    }

    public function exists(string $name): bool
    {
        $this->driver->assertSupports('branching');

        $row = $this->connection->fetchAssociative(
            "SELECT 1 FROM pyrosql_branches WHERE name = ?",
            [$name]
        );

        return $row !== false;
    }

    public function switch(string $name): void
    {
        $this->driver->assertSupports('branching');

        if (!$this->exists($name)) {
            throw BranchNotFoundException::forName($name);
        }

        $this->connection->executeStatement(
            "SET pyrosql.branch = " . $this->connection->quote($name)
        );
    }
}
