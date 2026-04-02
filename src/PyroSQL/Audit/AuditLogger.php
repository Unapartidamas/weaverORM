<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Audit;

use Weaver\ORM\DBAL\Connection;

final class AuditLogger
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function enable(string $table): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE ' . $this->connection->quoteIdentifier($table) . ' SET (pyrosql.audit = on)'
        );
    }

    public function disable(string $table): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE ' . $this->connection->quoteIdentifier($table) . ' SET (pyrosql.audit = off)'
        );
    }

    public function getHistory(
        string $table,
        ?\DateTimeInterface $since = null,
        ?\DateTimeInterface $until = null,
        ?int $limit = null,
    ): array {
        $sql = 'SELECT * FROM pyrosql_audit.' . $this->connection->quoteIdentifier($table . '_log');
        $params = [];
        $conditions = [];

        if ($since !== null) {
            $conditions[] = 'changed_at >= ?';
            $params[] = $since->format('Y-m-d H:i:s');
        }

        if ($until !== null) {
            $conditions[] = 'changed_at <= ?';
            $params[] = $until->format('Y-m-d H:i:s');
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY changed_at DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    public function getChangesForRow(string $table, int|string $id): array
    {
        $sql = 'SELECT * FROM pyrosql_audit.' . $this->connection->quoteIdentifier($table . '_log')
             . ' WHERE row_id = ? ORDER BY changed_at DESC';

        return $this->connection->fetchAllAssociative($sql, [$id]);
    }

    public function isEnabled(string $table): bool
    {
        $row = $this->connection->fetchAssociative(
            "SELECT setting FROM pyrosql_audit.enabled_tables WHERE table_name = ?",
            [$table],
        );

        return $row !== false && ($row['setting'] ?? '') === 'on';
    }
}
