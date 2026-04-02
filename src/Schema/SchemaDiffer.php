<?php

declare(strict_types=1);

namespace Weaver\ORM\Schema;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\Type\Type;
use Weaver\ORM\Mapping\MapperRegistry;

final class SchemaDiffer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MapperRegistry $registry,
    ) {}

    public function diff(): SchemaDiff
    {
        $existingTables = $this->listTableNames();

        $mappedTables = [];
        foreach ($this->registry->all() as $mapper) {
            $mappedTables[] = $mapper->getTableName();
        }

        $missingTables = array_values(
            array_diff($mappedTables, $existingTables)
        );

        $extraTables = array_values(
            array_diff($existingTables, $mappedTables)
        );

        $missingColumns = [];
        $extraColumns = [];
        $typeMismatches = [];

        foreach ($this->registry->all() as $mapper) {
            $table = $mapper->getTableName();

            if (!in_array($table, $existingTables, true)) {
                continue;
            }

            $dbColumns = $this->listTableColumns($table);

            $mappedColumnNames = [];
            foreach ($mapper->getColumns() as $colDef) {
                if ($colDef->isVirtual()) {
                    continue;
                }
                $mappedColumnNames[] = $colDef->getColumn();
            }

            foreach ($mappedColumnNames as $colName) {
                if (!isset($dbColumns[$colName])) {
                    $missingColumns[$table][] = $colName;
                }
            }

            $dbColumnNames = array_keys($dbColumns);
            foreach ($dbColumnNames as $dbColName) {
                if (!in_array($dbColName, $mappedColumnNames, true)) {
                    $extraColumns[$table][] = $dbColName;
                }
            }

            foreach ($mapper->getColumns() as $colDef) {
                if ($colDef->isVirtual()) {
                    continue;
                }

                $colName = $colDef->getColumn();

                if (!isset($dbColumns[$colName])) {
                    continue;
                }

                $mappedType = $colDef->getType();
                $actualType = $dbColumns[$colName];

                if (!$this->typesMatch($mappedType, $actualType)) {
                    $typeMismatches[$table][$colName] = [
                        'mapped' => $mappedType,
                        'actual' => $actualType,
                    ];
                }
            }
        }

        return new SchemaDiff(
            missingTables:  $missingTables,
            extraTables:    $extraTables,
            missingColumns: $missingColumns,
            extraColumns:   $extraColumns,
            typeMismatches: $typeMismatches,
        );
    }

    private function listTableNames(): array
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        $sql = match ($platform) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
            'postgresql', 'pyrosql' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
            'mysql' => 'SHOW TABLES',
            default => throw new \RuntimeException('Unsupported platform for schema diff: ' . $platform),
        };

        $rows = $this->connection->fetchAllAssociative($sql);
        $tables = [];
        foreach ($rows as $row) {
            $tables[] = reset($row);
        }

        return $tables;
    }

    private function listTableColumns(string $table): array
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        $columns = [];

        if ($platform === 'sqlite') {
            $rows = $this->connection->fetchAllAssociative("PRAGMA table_info(\"{$table}\")");
            foreach ($rows as $row) {
                $columns[$row['name']] = strtolower($row['type']);
            }
        } elseif ($platform === 'postgresql' || $platform === 'pyrosql') {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ?",
                [$table]
            );
            foreach ($rows as $row) {
                $columns[$row['column_name']] = strtolower($row['data_type']);
            }
        } elseif ($platform === 'mysql') {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns WHERE table_name = ?",
                [$table]
            );
            foreach ($rows as $row) {
                $columns[$row['COLUMN_NAME']] = strtolower($row['DATA_TYPE']);
            }
        }

        return $columns;
    }

    private function typesMatch(string $mapped, string $actual): bool
    {
        if ($mapped === $actual) {
            return true;
        }

        $mappedGroup  = $this->typeGroup($mapped);
        $actualGroup  = $this->typeGroup($actual);

        return $mappedGroup === $actualGroup;
    }

    private function typeGroup(string $type): string
    {
        return match ($type) {
            'integer', 'smallint', 'bigint', 'int', 'int4', 'int8', 'serial' => 'integer',
            'string', 'ascii_string', 'text', 'varchar', 'character varying', 'char' => 'string',
            'datetime', 'datetime_immutable', 'datetimetz',
            'datetimetz_immutable', 'timestamp', 'timestamp without time zone',
            'timestamp with time zone' => 'datetime',
            'date', 'date_immutable' => 'date',
            'time', 'time_immutable', 'time without time zone' => 'time',
            'float', 'decimal', 'double precision', 'real', 'numeric' => 'float',
            'boolean', 'bool' => 'boolean',
            'json', 'jsonb', 'array', 'simple_array' => 'json',
            'binary', 'blob', 'bytea' => 'binary',
            'guid', 'uuid' => 'guid',
            default => $type,
        };
    }
}
