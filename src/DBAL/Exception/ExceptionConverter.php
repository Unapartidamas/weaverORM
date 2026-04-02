<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

final class ExceptionConverter
{
    public static function convert(\PDOException $e, string $sql): DatabaseException
    {
        $code = is_string($e->getCode()) ? $e->getCode() : (string) $e->getCode();
        $msg = $e->getMessage();
        $combined = $code . ' ' . $msg;

        if (self::matches($combined, ['23505', 'Duplicate entry', 'UNIQUE constraint failed', 'unique_violation'])) {
            return UniqueConstraintViolation::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['23503', 'FOREIGN KEY constraint failed', 'foreign_key_violation'])) {
            return ForeignKeyViolation::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['23502', 'NOT NULL constraint failed', 'not_null_violation'])) {
            return NotNullViolation::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['23514', 'CHECK constraint failed', 'check_violation'])) {
            return CheckViolation::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['42P01', '42S02', 'does not exist', "doesn't exist", 'no such table', 'undefined_table'])) {
            return TableNotFoundException::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['42703', '42S22', 'Unknown column', 'no such column', 'undefined_column'])) {
            return ColumnNotFoundException::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['42601', '42000', 'syntax error', 'syntax_error'])) {
            return SyntaxError::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['40P01', '40001', 'deadlock', 'Deadlock'])) {
            return DeadlockException::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['Lock wait timeout', 'lock timeout'])) {
            return LockWaitTimeoutException::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['25006', 'read-only', 'READ ONLY', 'read_only'])) {
            return ReadOnlyViolation::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['22001', 'value too long', 'Data too long', 'string_data_right_truncation'])) {
            return ValueTooLongException::fromPdoException($e, $sql);
        }

        if (self::matches($combined, ['08', 'Connection refused', 'server has gone away', 'Lost connection', 'connection_exception'])) {
            return ConnectionException::fromPdoException($e, $sql);
        }

        return new DatabaseException(
            'Database error: ' . $msg,
            $sql,
            $code,
            $e,
        );
    }

    private static function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
