<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

final class JoinedInheritanceResolver
{
    public function resolveSelectQuery(string $entityClass, InheritanceMapping $mapping): string
    {
        $parentTable = $mapping->parentTable;
        $joinColumn = $mapping->joinColumn;

        $childTable = $mapping->childTables[$entityClass] ?? null;

        if ($parentTable === null) {
            return '';
        }

        if ($childTable !== null) {
            return sprintf(
                'SELECT * FROM %s INNER JOIN %s ON %s.%s = %s.%s',
                $parentTable,
                $childTable,
                $parentTable,
                $joinColumn,
                $childTable,
                $joinColumn,
            );
        }

        $joins = '';
        foreach ($mapping->childTables as $table) {
            $joins .= sprintf(
                ' LEFT JOIN %s ON %s.%s = %s.%s',
                $table,
                $parentTable,
                $joinColumn,
                $table,
                $joinColumn,
            );
        }

        return sprintf('SELECT * FROM %s%s', $parentTable, $joins);
    }

    public function resolveInsertStatements(string $entityClass, array $data, InheritanceMapping $mapping): array
    {
        $parentTable = $mapping->parentTable;
        $childTable = $mapping->childTables[$entityClass] ?? null;

        if ($parentTable === null) {
            return [];
        }

        $parentColumns = $data['parent'] ?? [];
        $childColumns = $data['child'] ?? [];

        $statements = [];

        if ($parentColumns !== []) {
            $cols = implode(', ', array_keys($parentColumns));
            $placeholders = implode(', ', array_fill(0, count($parentColumns), '?'));
            $statements[] = [
                'sql' => sprintf('INSERT INTO %s (%s) VALUES (%s)', $parentTable, $cols, $placeholders),
                'params' => array_values($parentColumns),
            ];
        }

        if ($childTable !== null && $childColumns !== []) {
            $cols = implode(', ', array_keys($childColumns));
            $placeholders = implode(', ', array_fill(0, count($childColumns), '?'));
            $statements[] = [
                'sql' => sprintf('INSERT INTO %s (%s) VALUES (%s)', $childTable, $cols, $placeholders),
                'params' => array_values($childColumns),
            ];
        }

        return $statements;
    }

    public function resolveDeleteStatements(string $entityClass, mixed $id, InheritanceMapping $mapping): array
    {
        $parentTable = $mapping->parentTable;
        $childTable = $mapping->childTables[$entityClass] ?? null;
        $joinColumn = $mapping->joinColumn;

        $statements = [];

        if ($childTable !== null) {
            $statements[] = [
                'sql' => sprintf('DELETE FROM %s WHERE %s = ?', $childTable, $joinColumn),
                'params' => [$id],
            ];
        }

        if ($parentTable !== null) {
            $statements[] = [
                'sql' => sprintf('DELETE FROM %s WHERE %s = ?', $parentTable, $joinColumn),
                'params' => [$id],
            ];
        }

        return $statements;
    }

    public function resolveUpdateStatements(string $entityClass, array $data, InheritanceMapping $mapping): array
    {
        $parentTable = $mapping->parentTable;
        $childTable = $mapping->childTables[$entityClass] ?? null;
        $joinColumn = $mapping->joinColumn;

        $parentColumns = $data['parent'] ?? [];
        $childColumns = $data['child'] ?? [];
        $id = $data['id'] ?? null;

        $statements = [];

        if ($parentColumns !== [] && $parentTable !== null) {
            $setClauses = [];
            $params = [];
            foreach ($parentColumns as $col => $val) {
                $setClauses[] = sprintf('%s = ?', $col);
                $params[] = $val;
            }
            $params[] = $id;
            $statements[] = [
                'sql' => sprintf('UPDATE %s SET %s WHERE %s = ?', $parentTable, implode(', ', $setClauses), $joinColumn),
                'params' => $params,
            ];
        }

        if ($childColumns !== [] && $childTable !== null) {
            $setClauses = [];
            $params = [];
            foreach ($childColumns as $col => $val) {
                $setClauses[] = sprintf('%s = ?', $col);
                $params[] = $val;
            }
            $params[] = $id;
            $statements[] = [
                'sql' => sprintf('UPDATE %s SET %s WHERE %s = ?', $childTable, implode(', ', $setClauses), $joinColumn),
                'params' => $params,
            ];
        }

        return $statements;
    }
}
