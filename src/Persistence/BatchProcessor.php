<?php

declare(strict_types=1);

namespace Weaver\ORM\Persistence;

use Weaver\ORM\DBAL\Connection;

final class BatchProcessor
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function insertBatch(string $table, array $rows, int $chunkSize = 500): int
    {
        if ($rows === []) {
            return 0;
        }

        $affected = 0;
        $columns = array_keys($rows[0]);
        $quotedCols = array_map(fn (string $c) => $this->quoteIdentifier($c), $columns);
        $colList = implode(', ', $quotedCols);

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $placeholders = [];
            $params = [];
            $i = 0;

            foreach ($chunk as $row) {
                $rowPlaceholders = [];
                foreach ($columns as $col) {
                    $paramName = 'p' . $i;
                    $rowPlaceholders[] = ':' . $paramName;
                    $params[$paramName] = $row[$col] ?? null;
                    $i++;
                }
                $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $this->quoteIdentifier($table),
                $colList,
                implode(', ', $placeholders),
            );

            $affected += $this->connection->executeStatement($sql, $params);
        }

        return $affected;
    }

    public function updateBatch(string $table, array $rows, string $idColumn = 'id', int $chunkSize = 500): int
    {
        if ($rows === []) {
            return 0;
        }

        $affected = 0;
        $columns = array_keys($rows[0]);
        $updateColumns = array_filter($columns, fn (string $c) => $c !== $idColumn);

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $params = [];
            $ids = [];
            $setClauses = [];
            $i = 0;

            foreach ($updateColumns as $col) {
                $cases = [];
                foreach ($chunk as $row) {
                    $idParam = 'id_' . $col . '_' . $i;
                    $valParam = 'val_' . $col . '_' . $i;
                    $cases[] = sprintf('WHEN %s = :%s THEN :%s', $this->quoteIdentifier($idColumn), $idParam, $valParam);
                    $params[$idParam] = $row[$idColumn];
                    $params[$valParam] = $row[$col] ?? null;
                    $i++;
                }
                $setClauses[] = sprintf(
                    '%s = CASE %s END',
                    $this->quoteIdentifier($col),
                    implode(' ', $cases),
                );
            }

            $j = 0;
            foreach ($chunk as $row) {
                $paramName = 'where_id_' . $j;
                $ids[] = ':' . $paramName;
                $params[$paramName] = $row[$idColumn];
                $j++;
            }

            $sql = sprintf(
                'UPDATE %s SET %s WHERE %s IN (%s)',
                $this->quoteIdentifier($table),
                implode(', ', $setClauses),
                $this->quoteIdentifier($idColumn),
                implode(', ', $ids),
            );

            $affected += $this->connection->executeStatement($sql, $params);
        }

        return $affected;
    }

    public function upsertBatch(string $table, array $rows, string|array $conflictColumns, int $chunkSize = 500): int
    {
        if ($rows === []) {
            return 0;
        }

        $affected = 0;
        $columns = array_keys($rows[0]);
        $quotedCols = array_map(fn (string $c) => $this->quoteIdentifier($c), $columns);
        $colList = implode(', ', $quotedCols);

        $conflictCols = is_array($conflictColumns) ? $conflictColumns : [$conflictColumns];
        $quotedConflict = implode(', ', array_map(fn (string $c) => $this->quoteIdentifier($c), $conflictCols));

        $updateCols = array_filter($columns, fn (string $c) => !in_array($c, $conflictCols, true));
        $updateSet = implode(', ', array_map(
            fn (string $c) => sprintf('%s = EXCLUDED.%s', $this->quoteIdentifier($c), $this->quoteIdentifier($c)),
            $updateCols,
        ));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $placeholders = [];
            $params = [];
            $i = 0;

            foreach ($chunk as $row) {
                $rowPlaceholders = [];
                foreach ($columns as $col) {
                    $paramName = 'p' . $i;
                    $rowPlaceholders[] = ':' . $paramName;
                    $params[$paramName] = $row[$col] ?? null;
                    $i++;
                }
                $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO UPDATE SET %s',
                $this->quoteIdentifier($table),
                $colList,
                implode(', ', $placeholders),
                $quotedConflict,
                $updateSet,
            );

            $affected += $this->connection->executeStatement($sql, $params);
        }

        return $affected;
    }

    public function deleteBatch(string $table, array $ids, string $idColumn = 'id', int $chunkSize = 500): int
    {
        if ($ids === []) {
            return 0;
        }

        $affected = 0;

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $params = [];
            $placeholders = [];

            foreach ($chunk as $i => $id) {
                $paramName = 'id_' . $i;
                $placeholders[] = ':' . $paramName;
                $params[$paramName] = $id;
            }

            $sql = sprintf(
                'DELETE FROM %s WHERE %s IN (%s)',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($idColumn),
                implode(', ', $placeholders),
            );

            $affected += $this->connection->executeStatement($sql, $params);
        }

        return $affected;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
