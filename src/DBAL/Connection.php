<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL;

use PDO;
use Weaver\ORM\PyroSQL\PyroSqlSyntax;
use Weaver\ORM\PyroSQL\Query\ProfileResult;
use Weaver\ORM\PyroSQL\Query\DryRunResult;
use Weaver\ORM\PyroSQL\Query\TraceResult;

class Connection
{
    private const MAX_CACHED_STMTS = 64;

    private int $transactionDepth = 0;

    private array $stmtCache = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Platform $platform,
    ) {
    }

    public function query(string $sql): Result
    {
        return new Result($this->pdo->query($sql));
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int
    {
        if (empty($params)) {
            return (int) $this->pdo->exec($sql);
        }

        // Resolve object values before interpolation
        $resolved = [];
        foreach ($params as $key => $value) {
            if (is_object($value) && !$value instanceof \BackedEnum && !$value instanceof ParameterType) {
                if (method_exists($value, 'getId')) {
                    $value = $value->getId();
                } elseif (property_exists($value, 'id')) {
                    $value = $value->id;
                } elseif ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                } else {
                    throw new \InvalidArgumentException(
                        sprintf('Cannot bind object of class "%s" as SQL parameter.', get_class($value))
                    );
                }
            }
            $resolved[$key] = $value;
        }

        $resolved = $this->normalizeParams($sql, $resolved);
        $sql = $this->interpolateParams($sql, $resolved);

        try {
            return (int) $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }
    }

    public function prepare(string $sql): Statement
    {
        return new Statement($this->pdo->prepare($sql));
    }

    public function fetchAssociative(string $sql, array $params = []): array|false
    {
        $params = $this->normalizeParams($sql, $params);
        $sql = !empty($params) ? $this->interpolateParams($sql, $params) : $sql;
        try {
            $stmt = $this->pdo->query($sql);
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        $params = $this->normalizeParams($sql, $params);
        $sql = !empty($params) ? $this->interpolateParams($sql, $params) : $sql;
        try {
            $stmt = $this->pdo->query($sql);
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchOne(string $sql, array $params = []): mixed
    {
        $params = $this->normalizeParams($sql, $params);
        $sql = !empty($params) ? $this->interpolateParams($sql, $params) : $sql;
        try {
            $stmt = $this->pdo->query($sql);
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }

        return $stmt->fetchColumn();
    }

    public function fetchFirstColumn(string $sql, array $params = []): array
    {
        $params = $this->normalizeParams($sql, $params);
        $sql = !empty($params) ? $this->interpolateParams($sql, $params) : $sql;
        try {
            $stmt = $this->pdo->query($sql);
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function executeQuery(string $sql, array $params = []): Result
    {
        $params = $this->normalizeParams($sql, $params);
        $sql = !empty($params) ? $this->interpolateParams($sql, $params) : $sql;
        try {
            $stmt = $this->pdo->query($sql);
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }

        return new Result($stmt);
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return (new QueryBuilder())->setConnection($this);
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colList = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $sql = 'INSERT INTO ' . $this->quoteIdentifier($table) . ' (' . $colList . ') VALUES (' . $placeholders . ')';

        return $this->executeStatement($sql, array_values($data));
    }

    public function update(string $table, array $data, array $criteria): int
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = $this->quoteIdentifier($col) . ' = ?';
        }
        $conditions = [];
        foreach (array_keys($criteria) as $col) {
            $conditions[] = $this->quoteIdentifier($col) . ' = ?';
        }
        $sql = 'UPDATE ' . $this->quoteIdentifier($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $conditions);

        return $this->executeStatement($sql, [...array_values($data), ...array_values($criteria)]);
    }

    public function delete(string $table, array $criteria): int
    {
        $conditions = [];
        foreach (array_keys($criteria) as $col) {
            $conditions[] = $this->quoteIdentifier($col) . ' = ?';
        }
        $sql = 'DELETE FROM ' . $this->quoteIdentifier($table) . ' WHERE ' . implode(' AND ', $conditions);

        return $this->executeStatement($sql, array_values($criteria));
    }

    public function lastInsertId(): string|int
    {
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        } elseif ($this->platform->supportsSavepoints()) {
            $this->createSavepoint('weaver_savepoint_' . $this->transactionDepth);
        }

        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            throw new \LogicException('No active transaction to commit.');
        }

        $this->transactionDepth--;

        if ($this->transactionDepth === 0) {
            $this->pdo->commit();
        } elseif ($this->platform->supportsSavepoints()) {
            $this->releaseSavepoint('weaver_savepoint_' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            throw new \LogicException('No active transaction to roll back.');
        }

        $this->transactionDepth--;

        if ($this->transactionDepth === 0) {
            $this->pdo->rollBack();
        } elseif ($this->platform->supportsSavepoints()) {
            $this->rollbackSavepoint('weaver_savepoint_' . $this->transactionDepth);
        }
    }

    public function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->platform->quoteIdentifier($identifier);
    }

    // --- PyroSQL Diagnostic Queries ---

    public function profile(string $sql, array $params = []): ProfileResult
    {
        $rows = $this->fetchAllAssociative(PyroSqlSyntax::profile($sql), $params);
        return ProfileResult::fromRows($rows);
    }

    public function dryRun(string $sql, array $params = []): DryRunResult
    {
        $rows = $this->fetchAllAssociative(PyroSqlSyntax::dryRun($sql), $params);
        return DryRunResult::fromRows($rows);
    }

    public function trace(string $sql, array $params = []): TraceResult
    {
        $rows = $this->fetchAllAssociative(PyroSqlSyntax::trace($sql), $params);
        return TraceResult::fromRows($rows);
    }

    public function getDatabasePlatform(): Platform
    {
        return $this->platform;
    }

    public function getDriver(): Platform
    {
        return $this->platform;
    }

    public function getNativeConnection(): PDO
    {
        return $this->pdo;
    }

    public function isTransactionActive(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function createSavepoint(string $name): void
    {
        $this->pdo->exec('SAVEPOINT ' . $name);
    }

    public function releaseSavepoint(string $name): void
    {
        $this->pdo->exec('RELEASE SAVEPOINT ' . $name);
    }

    public function rollbackSavepoint(string $name): void
    {
        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
    }

    private function cachedPrepare(string $sql): \PDOStatement
    {
        if (isset($this->stmtCache[$sql])) {
            return $this->stmtCache[$sql];
        }

        if (count($this->stmtCache) >= self::MAX_CACHED_STMTS) {
            array_shift($this->stmtCache);
        }

        return $this->stmtCache[$sql] = $this->pdo->prepare($sql);
    }

    private function normalizeParams(string &$sql, array $params): array
    {
        if (empty($params) || array_is_list($params)) {
            return $params;
        }

        $positional = [];
        $sql = preg_replace_callback('/:(\w+)\b/', function (array $m) use ($params, &$positional): string {
            $positional[] = $params[$m[1]] ?? null;
            return '?';
        }, $sql);

        return $positional;
    }

    private function interpolateParams(string $sql, array $params): string
    {
        $i = 0;
        return preg_replace_callback('/\?/', function () use ($params, &$i): string {
            $val = $params[$i++] ?? null;
            if ($val === null) return 'NULL';
            if (is_bool($val)) return $val ? 'true' : 'false';
            if (is_int($val) || is_float($val)) return (string) $val;
            if (is_object($val)) {
                if (method_exists($val, 'getId')) return (string) $val->getId();
                if ($val instanceof \DateTimeInterface) return "'" . $val->format('Y-m-d H:i:s') . "'";
            }
            // Numeric strings should not be quoted (PyroSQL does not coerce '2' to int)
            if (is_string($val) && is_numeric($val)) return $val;
            return "'" . str_replace("'", "''", (string) $val) . "'";
        }, $sql);
    }
}
