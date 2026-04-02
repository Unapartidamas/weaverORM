<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\FullText;

final class TrigramSearch
{
    private function __construct() {}

    public static function similar(string $column, string $query, float $threshold = 0.3): string
    {
        return "similarity({$column}, '{$query}') > {$threshold}";
    }

    public static function createTrigramIndex(string $table, string $column): string
    {
        $indexName = "idx_{$table}_{$column}_trgm";

        return "CREATE INDEX {$indexName} ON {$table} USING GIN({$column} gin_trgm_ops)";
    }
}
