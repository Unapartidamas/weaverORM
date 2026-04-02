<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Branch;

final readonly class PyroBranch
{
    public function __construct(
        private string $name,
        private string $parentName,
        private \DateTimeImmutable $createdAt,
        private \Weaver\ORM\DBAL\Connection $connection,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getParentName(): string
    {
        return $this->parentName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function connection(): \Weaver\ORM\DBAL\Connection
    {
        $this->connection->executeStatement(
            "SET pyrosql.branch = " . $this->connection->quote($this->name)
        );

        return $this->connection;
    }

    public function mergeTo(string $targetBranch = 'main'): void
    {
        $this->connection->executeStatement(
            "MERGE BRANCH " . $this->connection->quoteIdentifier($this->name)
            . " INTO " . $this->connection->quoteIdentifier($targetBranch)
        );
    }

    public function delete(): void
    {
        $this->connection->executeStatement("DROP BRANCH " . $this->connection->quoteIdentifier($this->name));
    }

    public function storageBytes(): int
    {
        $row = $this->connection->fetchAssociative(
            "SELECT storage_bytes FROM pyrosql_branches WHERE name = ?",
            [$this->name]
        );

        $raw = ($row !== false) ? ($row['storage_bytes'] ?? 0) : 0;
        return is_numeric($raw) ? (int) $raw : 0;
    }
}
