<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\QueryCache;

use Weaver\ORM\DBAL\Connection;

final class QueryCacheManager
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function enable(): void
    {
        $this->connection->executeStatement('SET pyrosql.query_cache = on');
    }

    public function disable(): void
    {
        $this->connection->executeStatement('SET pyrosql.query_cache = off');
    }

    public function isEnabled(): bool
    {
        $row = $this->connection->fetchAssociative(
            "SELECT current_setting('pyrosql.query_cache', true) AS v"
        );

        return $row !== false && ($row['v'] ?? '') === 'on';
    }

    public function invalidate(?string $table = null): void
    {
        if ($table !== null) {
            $this->connection->executeStatement(
                'SELECT pyrosql_invalidate_query_cache(' . $this->connection->quote($table) . ')'
            );
        } else {
            $this->connection->executeStatement('SELECT pyrosql_invalidate_query_cache()');
        }
    }

    public function getStats(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT hit_rate, miss_rate, size_bytes, entries_count FROM pyrosql_stats.query_cache'
        );

        if ($row === false) {
            return ['hit_rate' => 0.0, 'miss_rate' => 0.0, 'size_bytes' => 0, 'entries_count' => 0];
        }

        return $row;
    }

    public function setMaxSize(int $megabytes): void
    {
        $this->connection->executeStatement(
            "SET pyrosql.query_cache_size = '" . $megabytes . "MB'"
        );
    }
}
