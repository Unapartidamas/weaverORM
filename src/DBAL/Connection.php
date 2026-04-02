<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL;

use PDO;

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
            return $this->pdo->exec($sql);
        }

        $stmt = $this->pdo->prepare($sql);
        $index = 1;

        foreach ($params as $key => $value) {
            $type = $types[$key] ?? PDO::PARAM_STR;
            if ($type instanceof ParameterType) {
                $type = $type->value;
            } elseif (is_string($type)) {
                $type = match ($type) {
                    'integer', 'smallint', 'bigint', 'int' => PDO::PARAM_INT,
                    'boolean', 'bool' => PDO::PARAM_BOOL,
                    'binary', 'blob' => PDO::PARAM_LOB,
                    'null' => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                };
            }
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
            $stmt->bindValue($index++, $value, $type);
        }

        try {
            $stmt->execute();
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }

        return $stmt->rowCount();
    }

    public function prepare(string $sql): Statement
    {
        return new Statement($this->pdo->prepare($sql));
    }

    public function fetchAssociative(string $sql, array $params = []): array|false
    {
        if (!empty($params) && !array_is_list($params)) {
            [$sql, $params] = $this->resolveNamedParameters($sql, $params);
        }
        $stmt = $this->cachedPrepare($sql);
        try {
            $stmt->execute($params ?: null);
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row;
    }

    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        if (!empty($params) && !array_is_list($params)) {
            [$sql, $params] = $this->resolveNamedParameters($sql, $params);
        }
        $stmt = $this->cachedPrepare($sql);
        try {
            $stmt->execute($params ?: null);
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $rows;
    }

    public function fetchOne(string $sql, array $params = []): mixed
    {
        if (!empty($params) && !array_is_list($params)) {
            [$sql, $params] = $this->resolveNamedParameters($sql, $params);
        }
        $stmt = $this->cachedPrepare($sql);
        try {
            $stmt->execute($params ?: null);
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }
        $val = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $val;
    }

    public function fetchFirstColumn(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute($params ?: null);
        } catch (\PDOException $e) {
            throw Exception\ExceptionConverter::convert($e, $sql);
        }

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function executeQuery(string $sql, array $params = []): Result
    {
        if (!empty($params) && !array_is_list($params)) {
            [$sql, $params] = $this->resolveNamedParameters($sql, $params);
        }

        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute($params ?: null);
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

    private function resolveNamedParameters(string $sql, array $params): array
    {
        $positional = [];
        $resolved = preg_replace_callback('/:(\w+)\b/', function (array $m) use ($params, &$positional): string {
            $positional[] = $params[$m[1]] ?? null;
            return '?';
        }, $sql);

        return [$resolved, $positional];
    }
}
