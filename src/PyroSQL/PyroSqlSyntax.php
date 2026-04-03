<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL;

final class PyroSqlSyntax
{

    private function __construct() {}

    public static function find(string $table, array $columns = ['*'], ?int $limit = null, ?string $sortBy = null): string
    {
        if ($columns !== ['*']) {
            $qualified = implode(', ', array_map(
                static fn (string $col): string => "{$table}.{$col}",
                $columns,
            ));
            $sql = $limit !== null
                ? "FIND TOP {$limit} {$qualified}"
                : "FIND {$qualified}";
        } else {
            $sql = $limit !== null
                ? "FIND TOP {$limit} {$table}"
                : "FIND {$table}";
        }

        if ($sortBy !== null) {
            $sql .= " SORT BY {$sortBy}";
        }

        return $sql;
    }

    public static function findUnique(string $table, string $column): string
    {
        return "FIND UNIQUE {$table}.{$column}";
    }

    public static function add(string $table, array $data): string
    {
        $pairs = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $pairs[] = "{$key}: '{$value}'";
            } elseif (is_null($value)) {
                $pairs[] = "{$key}: NULL";
            } elseif (is_bool($value)) {
                $pairs[] = "{$key}: " . ($value ? 'TRUE' : 'FALSE');
            } else {
                $pairs[] = "{$key}: {$value}";
            }
        }

        return "ADD {$table} (" . implode(', ', $pairs) . ')';
    }

    public static function change(string $table, array $set, string $where): string
    {
        $setClauses = [];

        foreach ($set as $key => $value) {
            if (is_string($value)) {
                $setClauses[] = "{$key} = '{$value}'";
            } elseif (is_null($value)) {
                $setClauses[] = "{$key} = NULL";
            } elseif (is_bool($value)) {
                $setClauses[] = "{$key} = " . ($value ? 'TRUE' : 'FALSE');
            } else {
                $setClauses[] = "{$key} = {$value}";
            }
        }

        return "CHANGE {$table} SET " . implode(', ', $setClauses) . " WHERE {$where}";
    }

    public static function remove(string $table, string $where): string
    {
        return "REMOVE {$table} WHERE {$where}";
    }

    public static function count(string $table, ?string $where = null): string
    {
        $sql = "COUNT {$table}";

        if ($where !== null) {
            $sql .= " WHERE {$where}";
        }

        return $sql;
    }

    public static function sample(string $table, int $n): string
    {
        return "SAMPLE {$n} FROM {$table}";
    }

    public static function nearest(string $table, string $column, array $vector, int $k = 10): string
    {
        $parts = array_map(
            static fn (mixed $v): string => (string) (is_numeric($v) ? (float) $v : 0.0),
            $vector,
        );
        $literal = '[' . implode(',', $parts) . ']';

        return "NEAREST {$k} TO '{$literal}' FROM {$table}";
    }

    public static function search(string $table, string $column, string $query): string
    {
        return "SEARCH '{$query}' IN {$table}({$column})";
    }

    public static function upsert(string $table, array $data, string|array $conflictColumns): string
    {
        $columns = array_keys($data);
        $values  = [];

        foreach ($data as $value) {
            if (is_string($value)) {
                $values[] = "'{$value}'";
            } elseif (is_null($value)) {
                $values[] = 'NULL';
            } elseif (is_bool($value)) {
                $values[] = $value ? 'TRUE' : 'FALSE';
            } else {
                $values[] = (string) $value;
            }
        }

        $conflict = is_array($conflictColumns) ? implode(', ', $conflictColumns) : $conflictColumns;

        return "UPSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ") ON {$conflict}";
    }

    public static function importCsv(string $filePath, string $table): string
    {
        return "IMPORT CSV '{$filePath}' INTO {$table}";
    }

    public static function protect(string $table, string $where): string
    {
        return "PROTECT {$table} WHERE {$where}";
    }

    public static function inspect(string $table): string
    {
        return "INSPECT {$table}";
    }

    public static function describe(string $table): string
    {
        return self::inspect($table);
    }

    public static function clear(string $table): string
    {
        return "CLEAR {$table}";
    }

    public static function exportCsv(string $table, string $path): string
    {
        return "EXPORT {$table} TO CSV '{$path}'";
    }

    public static function explainVisual(string $sql): string
    {
        return "EXPLAIN VISUAL {$sql}";
    }

    public static function suggest(string $table): string
    {
        return "SUGGEST INDEXES FOR {$table}";
    }

    public static function tryIndex(string $table, string $column): string
    {
        return "TRY INDEX ON {$table}({$column})";
    }

    public static function onBranch(string $branch, string $sql): string
    {
        return "{$sql} ON BRANCH {$branch}";
    }

    public static function history(string $table, string $from, string $to): string
    {
        return "HISTORY {$table} FROM '{$from}' TO '{$to}'";
    }

    public static function diff(string $table, string $t1, string $t2): string
    {
        return "DIFF {$table} BETWEEN '{$t1}' AND '{$t2}'";
    }

    public static function subscribe(string $table): string
    {
        return "SUBSCRIBE TO CHANGES ON {$table}";
    }

    public static function allow(string $role, string $permission, string $table): string
    {
        return "ALLOW {$role} TO {$permission} {$table}";
    }

    // --- Data Expiry / TTL ---

    public static function expireAfter(string $table, int $duration, string $unit = 'DAYS'): string
    {
        return "ALTER TABLE {$table} EXPIRE AFTER {$duration} " . strtoupper($unit);
    }

    public static function noExpire(string $table): string
    {
        return "ALTER TABLE {$table} NO EXPIRE";
    }

    // --- Diagnostic Wrappers ---

    public static function profile(string $sql): string
    {
        return 'PROFILE ' . $sql;
    }

    public static function dryRun(string $sql): string
    {
        return 'DRY RUN ' . $sql;
    }

    public static function trace(string $sql): string
    {
        return 'TRACE ' . $sql;
    }

    // --- Admin Commands ---

    public static function depends(string $table): string
    {
        return "DEPENDS {$table}";
    }

    public static function compact(string $table): string
    {
        return "COMPACT {$table}";
    }

    public static function pin(string $table): string
    {
        return "PIN {$table}";
    }

    public static function unpin(string $table): string
    {
        return "UNPIN {$table}";
    }

    public static function throttle(string $table, ?int $readQps = null, ?int $writeQps = null): string
    {
        if ($readQps !== null && $writeQps !== null) {
            return "THROTTLE {$table} READ {$readQps} WRITE {$writeQps}";
        }

        $qps = $readQps ?? $writeQps ?? 0;
        return "THROTTLE {$table} TO {$qps} QPS";
    }

    public static function throttleOff(string $table): string
    {
        return "THROTTLE {$table} OFF";
    }

    public static function showThrottles(): string
    {
        return 'SHOW THROTTLES';
    }

    public static function split(string $source, string $target1, string $target2, string $column, string $where): string
    {
        return "SPLIT {$source} INTO {$target1}, {$target2} ON {$column} WHERE {$where}";
    }

    public static function merge(array $sources, string $target): string
    {
        return 'MERGE ' . implode(', ', $sources) . " INTO {$target}";
    }
}
