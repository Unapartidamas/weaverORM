<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Partitioning;

use Weaver\ORM\DBAL\Connection;

final class PartitionManager
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function createRangePartition(string $table, string $column, string $partName, string $from, string $to): void
    {
        $this->connection->executeStatement(
            "CREATE TABLE {$partName} PARTITION OF {$table} FOR VALUES FROM ('{$from}') TO ('{$to}')"
        );
    }

    public function createListPartition(string $table, string $column, string $partName, array $values): void
    {
        $quoted = implode(', ', array_map(
            static fn (string $v): string => "'{$v}'",
            $values,
        ));

        $this->connection->executeStatement(
            "CREATE TABLE {$partName} PARTITION OF {$table} FOR VALUES IN ({$quoted})"
        );
    }

    public function createHashPartition(string $table, string $column, int $modulus, int $remainder, string $partName): void
    {
        $this->connection->executeStatement(
            "CREATE TABLE {$partName} PARTITION OF {$table} FOR VALUES WITH (MODULUS {$modulus}, REMAINDER {$remainder})"
        );
    }

    public function detachPartition(string $table, string $partName): void
    {
        $this->connection->executeStatement(
            "ALTER TABLE {$table} DETACH PARTITION {$partName}"
        );
    }

    public function attachPartition(string $table, string $partName, string $constraint): void
    {
        $this->connection->executeStatement(
            "ALTER TABLE {$table} ATTACH PARTITION {$partName} {$constraint}"
        );
    }

    public function listPartitions(string $table): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT inhrelid::regclass::text AS partition_name"
            . " FROM pg_inherits"
            . " WHERE inhparent = '{$table}'::regclass"
        );
    }
}
