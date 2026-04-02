<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class TableNotFoundException extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        $table = 'unknown';
        if (preg_match('/relation "(\w+)" does not exist/', $e->getMessage(), $m)) {
            $table = $m[1];
        } elseif (preg_match("/Table '([^']+)' doesn't exist/", $e->getMessage(), $m)) {
            $table = $m[1];
        } elseif (preg_match('/no such table: (\w+)/', $e->getMessage(), $m)) {
            $table = $m[1];
        }

        return new self(
            "Table '{$table}' does not exist.",
            $sql,
            $e->getCode() ?: 'HY000',
            $e,
        );
    }
}
