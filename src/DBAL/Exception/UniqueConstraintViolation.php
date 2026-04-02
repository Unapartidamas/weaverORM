<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class UniqueConstraintViolation extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        $detail = $e->getMessage();
        $field = 'unknown';

        if (preg_match('/Key \((\w+)\)=\((.+?)\) already exists/', $detail, $m)) {
            $field = $m[1];
            $value = $m[2];
            $message = "Duplicate value for '{$field}': '{$value}' already exists.";
        } elseif (preg_match('/UNIQUE constraint failed: (\S+)/', $detail, $m)) {
            $field = $m[1];
            $message = "Duplicate value for '{$field}'.";
        } elseif (preg_match('/Duplicate entry \'(.+?)\' for key \'(.+?)\'/', $detail, $m)) {
            $value = $m[1];
            $field = $m[2];
            $message = "Duplicate value for '{$field}': '{$value}' already exists.";
        } else {
            $message = "A record with the same unique value already exists.";
        }

        return new self($message, $sql, $e->getCode() ?: 'HY000', $e);
    }
}
