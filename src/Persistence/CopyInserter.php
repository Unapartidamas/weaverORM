<?php

declare(strict_types=1);

namespace Weaver\ORM\Persistence;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\Mapping\AbstractEntityMapper;

final class CopyInserter
{

    public const int MIN_ROWS = 10;

    public function supports(Connection $conn, array $entities): bool
    {
        if (count($entities) < self::MIN_ROWS) {
            return false;
        }

        $platformName = $conn->getDatabasePlatform()->getName();
        $isPgsql = $platformName === 'postgresql' || $platformName === 'pyrosql';

        if (!$isPgsql) {
            return false;
        }

        return true;
    }

    public function insert(
        Connection $conn,
        AbstractEntityMapper $mapper,
        array $rows,
        array $entities,
    ): void {

        $pkColumn = $mapper->getPrimaryKey();
        foreach ($rows as $oid => $data) {
            if (array_key_exists($pkColumn, $data) && $data[$pkColumn] === null) {
                throw new \RuntimeException(
                    sprintf(
                        'CopyInserter: entity %s has a null primary key (%s). '
                        . 'Pre-assign PKs before using COPY FROM STDIN.',
                        $mapper->getEntityClass(),
                        $pkColumn,
                    )
                );
            }
        }

        $table   = $mapper->getTableName();
        $columns = array_keys(reset($rows));

        $tsvRows = [];
        foreach ($rows as $data) {
            $fields = [];
            foreach ($columns as $col) {
                $fields[] = $this->escapeTsvValue($data[$col] ?? null);
            }
            $tsvRows[] = implode("\t", $fields);
        }

        $quotedColumns = implode(', ', array_map(
            static fn (string $c): string => '"' . $c . '"',
            $columns,
        ));

        $native = $conn->getNativeConnection();

        if ($native instanceof \PDO) {
            $this->copyViaPdo($native, $table, $quotedColumns, $tsvRows);
        } elseif ($native instanceof \PgSql\Connection) {
            $this->copyViaPgSql($native, $table, $quotedColumns, $tsvRows);
        } else {
            throw new \RuntimeException(
                'CopyInserter: unsupported native connection type '
                . get_class($native)
                . '. Expected PDO or PgSql\Connection.'
            );
        }
    }

    private function escapeTsvValue(mixed $value): string
    {
        if ($value === null) {
            return '\\N';
        }

        $str = match (true) {
            is_bool($value)             => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_object($value)           => (string) $value,
            default                     => (string) $value,
        };

        $str = str_replace('\\', '\\\\', $str);
        $str = str_replace("\t", '\\t', $str);
        $str = str_replace("\n", '\\n', $str);
        $str = str_replace("\r", '\\r', $str);

        return $str;
    }

    private function copyViaPdo(\PDO $pdo, string $table, string $quotedColumns, array $tsvRows): void
    {
        if (!method_exists($pdo, 'pgsqlCopyFromArray')) {
            throw new \RuntimeException(
                'CopyInserter: PDO connection does not have pgsqlCopyFromArray(). '
                . 'Ensure the pdo_pgsql extension is loaded.'
            );
        }

        $result = $pdo->pgsqlCopyFromArray(
            sprintf('"%s" (%s)', $table, $quotedColumns),
            $tsvRows,
            "\t",
            '\\N',
        );

        if ($result === false) {
            throw new \RuntimeException(
                sprintf('CopyInserter: pgsqlCopyFromArray() failed for table "%s".', $table)
            );
        }
    }

    private function copyViaPgSql(\PgSql\Connection $pgConn, string $table, string $quotedColumns, array $tsvRows): void
    {
        if (!function_exists('pg_copy_from')) {
            throw new \RuntimeException(
                'CopyInserter: pg_copy_from() is not available. '
                . 'Ensure the pgsql extension is loaded.'
            );
        }

        $result = pg_copy_from(
            $pgConn,
            sprintf('"%s" (%s)', $table, $quotedColumns),
            $tsvRows,
            "\t",
            '\\N',
        );

        if ($result === false) {
            throw new \RuntimeException(
                sprintf('CopyInserter: pg_copy_from() failed for table "%s".', $table)
            );
        }
    }
}
