<?php

declare(strict_types=1);

namespace Weaver\ORM\Schema;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\Exception\SchemaValidationException;
use Weaver\ORM\Mapping\MapperRegistry;

final class SchemaValidator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MapperRegistry $registry,
    ) {}

    public function validate(): array
    {
        $existingTables = $this->listTableNames();
        $issues = [];

        foreach ($this->registry->all() as $mapper) {
            $table = $mapper->getTableName();

            if (!in_array($table, $existingTables, true)) {
                $issues[] = new SchemaIssue(
                    SchemaIssueType::MissingTable,
                    $table,
                    "Table `$table` does not exist",
                );

                continue;
            }

            $dbColumns = $this->listColumnNames($table);

            foreach ($mapper->getPersistableColumns() as $colDef) {
                $colName = $colDef->getColumn();

                if (!in_array($colName, $dbColumns, true)) {
                    $issues[] = new SchemaIssue(
                        SchemaIssueType::MissingColumn,
                        $table,
                        "Column `$colName` missing in table `$table`",
                        $colName,
                    );
                }
            }
        }

        return $issues;
    }

    public function assertValid(): void
    {
        $issues = $this->validate();

        if ($issues !== []) {
            throw SchemaValidationException::fromIssues($issues);
        }
    }

    private function listTableNames(): array
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        $sql = match ($platform) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
            'postgresql', 'pyrosql' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
            'mysql' => 'SHOW TABLES',
            default => throw new \RuntimeException('Unsupported platform for schema validation: ' . $platform),
        };

        $rows = $this->connection->fetchAllAssociative($sql);
        $tables = [];
        foreach ($rows as $row) {
            $tables[] = reset($row);
        }

        return $tables;
    }

    private function listColumnNames(string $table): array
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        if ($platform === 'sqlite') {
            $rows = $this->connection->fetchAllAssociative("PRAGMA table_info(\"{$table}\")");
            return array_column($rows, 'name');
        }

        if ($platform === 'postgresql' || $platform === 'pyrosql') {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT column_name FROM information_schema.columns WHERE table_name = ?",
                [$table]
            );
            return array_column($rows, 'column_name');
        }

        if ($platform === 'mysql') {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_name = ?",
                [$table]
            );
            return array_column($rows, 'COLUMN_NAME');
        }

        return [];
    }
}
