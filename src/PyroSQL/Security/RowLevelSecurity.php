<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Security;

final class RowLevelSecurity
{
    private function __construct() {}

    public static function protect(string $table, string $condition): string
    {
        return "PROTECT {$table} WHERE {$condition}";
    }

    public static function enableRls(string $table): string
    {
        return "ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY";
    }

    public static function disableRls(string $table): string
    {
        return "ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY";
    }

    public static function createPolicy(string $name, string $table, string $using, ?string $check = null): string
    {
        $sql = "CREATE POLICY {$name} ON {$table} USING ({$using})";

        if ($check !== null) {
            $sql .= " WITH CHECK ({$check})";
        }

        return $sql;
    }

    public static function dropPolicy(string $name, string $table): string
    {
        return "DROP POLICY {$name} ON {$table}";
    }
}
