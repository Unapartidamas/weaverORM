<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\FullText;

final class FullTextSearch
{
    private function __construct() {}

    public static function search(string $table, string $column, string $query): string
    {
        return "SEARCH '{$query}' IN {$table}({$column})";
    }

    public static function searchWithRank(string $table, string $column, string $query): string
    {
        return "SELECT *, ts_rank_bm25(to_tsvector({$column}), to_tsquery('{$query}')) AS rank"
            . " FROM {$table}"
            . " WHERE to_tsvector({$column}) @@ to_tsquery('{$query}')"
            . " ORDER BY rank DESC";
    }

    public static function createIndex(string $table, string|array $columns, string $language = 'english'): string
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $expressions = implode(", ", array_map(
            static fn (string $col): string => "to_tsvector('{$language}', {$col})",
            $cols,
        ));
        $colNames = implode('_', $cols);
        $indexName = "idx_{$table}_{$colNames}_fts";

        return "CREATE INDEX {$indexName} ON {$table} USING GIN({$expressions})";
    }

    public static function dropIndex(string $indexName): string
    {
        return "DROP INDEX {$indexName}";
    }
}
