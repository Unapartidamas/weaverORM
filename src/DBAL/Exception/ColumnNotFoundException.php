<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class ColumnNotFoundException extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        $column = 'unknown';
        if (preg_match('/column "(\w+)" .* does not exist/', $e->getMessage(), $m)) {
            $column = $m[1];
        } elseif (preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $m)) {
            $column = $m[1];
        } elseif (preg_match('/no such column: (\w+)/', $e->getMessage(), $m)) {
            $column = $m[1];
        }

        return new self(
            "Column '{$column}' does not exist.",
            $sql,
            $e->getCode() ?: 'HY000',
            $e,
        );
    }
}
