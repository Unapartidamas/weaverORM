<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class NotNullViolation extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        $column = 'unknown';
        if (preg_match('/column "(\w+)"/', $e->getMessage(), $m)) {
            $column = $m[1];
        } elseif (preg_match('/NOT NULL constraint failed: (\S+)/', $e->getMessage(), $m)) {
            $column = $m[1];
        }

        return new self(
            "Column '{$column}' cannot be null.",
            $sql,
            $e->getCode() ?: 'HY000',
            $e,
        );
    }
}
