<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

final class ExceptionConverter
{
    public static function convert(\PDOException $e, string $sql): DatabaseException
    {
        $code = is_string($e->getCode()) ? $e->getCode() : (string) $e->getCode();
        $msg = $e->getMessage();

        // 23505 - unique_violation (PostgreSQL/PyroSQL)
        if (str_contains($code, '23505') || str_contains($msg, '23505')
            || str_contains($msg, 'Duplicate entry') || str_contains($msg, 'UNIQUE constraint failed')) {
            return UniqueConstraintViolation::fromPdoException($e, $sql);
        }

        // 23503 - foreign_key_violation
        if (str_contains($code, '23503') || str_contains($msg, '23503')
            || str_contains($msg, 'FOREIGN KEY constraint failed')) {
            return ForeignKeyViolation::fromPdoException($e, $sql);
        }

        // 23502 - not_null_violation
        if (str_contains($code, '23502') || str_contains($msg, '23502')
            || str_contains($msg, 'NOT NULL constraint failed')) {
            return NotNullViolation::fromPdoException($e, $sql);
        }

        // 23514 - check_violation
        if (str_contains($code, '23514') || str_contains($msg, '23514')
            || str_contains($msg, 'CHECK constraint failed')) {
            return CheckViolation::fromPdoException($e, $sql);
        }

        // 42P01 - undefined_table / 42S02 (MySQL)
        if (str_contains($code, '42P01') || str_contains($code, '42S02')
            || str_contains($msg, 'does not exist') || str_contains($msg, "doesn't exist")
            || str_contains($msg, 'no such table')) {
            return TableNotFoundException::fromPdoException($e, $sql);
        }

        // 42703 - undefined_column / 42S22 (MySQL)
        if (str_contains($code, '42703') || str_contains($code, '42S22')
            || str_contains($msg, 'Unknown column') || str_contains($msg, 'no such column')) {
            return ColumnNotFoundException::fromPdoException($e, $sql);
        }

        // 42601, 42000 - syntax_error
        if (str_starts_with($code, '42') || str_contains($msg, 'syntax error')) {
            return SyntaxError::fromPdoException($e, $sql);
        }

        // 40P01 - deadlock_detected / 40001 (MySQL)
        if (str_contains($code, '40P01') || str_contains($code, '40001')
            || str_contains($msg, 'deadlock') || str_contains($msg, 'Deadlock')) {
            return DeadlockException::fromPdoException($e, $sql);
        }

        // Lock wait timeout (MySQL 1205)
        if (str_contains($msg, 'Lock wait timeout') || str_contains($msg, 'lock timeout')) {
            return LockWaitTimeoutException::fromPdoException($e, $sql);
        }

        // 25006 - read_only_sql_transaction
        if (str_contains($code, '25006') || str_contains($msg, 'read-only')
            || str_contains($msg, 'READ ONLY')) {
            return ReadOnlyViolation::fromPdoException($e, $sql);
        }

        // 22001 - string_data_right_truncation (value too long)
        if (str_contains($code, '22001') || str_contains($msg, 'value too long')
            || str_contains($msg, 'Data too long')) {
            return ValueTooLongException::fromPdoException($e, $sql);
        }

        // 08xxx - connection_exception
        if (str_starts_with($code, '08') || str_contains($msg, 'Connection refused')
            || str_contains($msg, 'server has gone away') || str_contains($msg, 'Lost connection')) {
            return ConnectionException::fromPdoException($e, $sql);
        }

        return new DatabaseException(
            'Database error: ' . $msg,
            $sql,
            $code,
            $e,
        );
    }
}
