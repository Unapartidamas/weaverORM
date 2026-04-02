<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\AutoIndex;

use Weaver\ORM\DBAL\Connection;

final class IndexAdvisor
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function suggest(string $table): array
    {
        return $this->connection->fetchAllAssociative(
            'SUGGEST INDEXES FOR ' . $this->connection->quoteIdentifier($table)
        );
    }

    public function tryIndex(string $table, string|array $columns, ?string $sql = null): array
    {
        $cols = is_array($columns) ? implode(', ', $columns) : $columns;
        $tryStmt = 'TRY INDEX ON ' . $this->connection->quoteIdentifier($table) . '(' . $cols . ')';

        if ($sql !== null) {
            $tryStmt .= ' FOR ' . $sql;
        }

        return $this->connection->fetchAllAssociative($tryStmt);
    }

    public function analyzeWorkload(?int $hours = 24): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM pyrosql_stats.workload_summary WHERE recorded_at >= NOW() - INTERVAL ' . $this->connection->quote($hours . ' hours') . ' ORDER BY query_count DESC'
        );
    }

    public function getUnusedIndexes(string $table): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM pyrosql_stats.unused_indexes WHERE table_name = ?',
            [$table],
        );
    }

    public function getMissingIndexes(string $table): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM pyrosql_stats.missing_indexes WHERE table_name = ?',
            [$table],
        );
    }
}
