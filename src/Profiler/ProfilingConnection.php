<?php

declare(strict_types=1);

namespace Weaver\ORM\Profiler;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\Platform;
use Weaver\ORM\DBAL\Result;
use Weaver\ORM\DBAL\Statement;

final class ProfilingConnection
{
    public function __construct(
        private readonly Connection $inner,
        private readonly QueryProfiler $profiler,
    ) {
    }

    public function query(string $sql): Result
    {
        $start = microtime(true);
        $result = $this->inner->query($sql);
        $this->profiler->record($sql, [], (microtime(true) - $start) * 1000);

        return $result;
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int
    {
        $start = microtime(true);
        $result = $this->inner->executeStatement($sql, $params, $types);
        $this->profiler->record($sql, $params, (microtime(true) - $start) * 1000);

        return $result;
    }

    public function prepare(string $sql): Statement
    {
        return $this->inner->prepare($sql);
    }

    public function fetchAssociative(string $sql, array $params = []): array|false
    {
        $start = microtime(true);
        $result = $this->inner->fetchAssociative($sql, $params);
        $this->profiler->record($sql, $params, (microtime(true) - $start) * 1000);

        return $result;
    }

    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        $start = microtime(true);
        $result = $this->inner->fetchAllAssociative($sql, $params);
        $this->profiler->record($sql, $params, (microtime(true) - $start) * 1000);

        return $result;
    }

    public function fetchOne(string $sql, array $params = []): mixed
    {
        $start = microtime(true);
        $result = $this->inner->fetchOne($sql, $params);
        $this->profiler->record($sql, $params, (microtime(true) - $start) * 1000);

        return $result;
    }

    public function fetchFirstColumn(string $sql, array $params = []): array
    {
        $start = microtime(true);
        $result = $this->inner->fetchFirstColumn($sql, $params);
        $this->profiler->record($sql, $params, (microtime(true) - $start) * 1000);

        return $result;
    }

    public function executeQuery(string $sql, array $params = []): Result
    {
        $start = microtime(true);
        $result = $this->inner->executeQuery($sql, $params);
        $this->profiler->record($sql, $params, (microtime(true) - $start) * 1000);

        return $result;
    }

    public function createQueryBuilder(): \Weaver\ORM\DBAL\QueryBuilder
    {
        return $this->inner->createQueryBuilder();
    }

    public function insert(string $table, array $data): int
    {
        $start = microtime(true);
        $result = $this->inner->insert($table, $data);
        $this->profiler->record('INSERT INTO ' . $table, $data, (microtime(true) - $start) * 1000);

        return $result;
    }

    public function update(string $table, array $data, array $criteria): int
    {
        $start = microtime(true);
        $result = $this->inner->update($table, $data, $criteria);
        $this->profiler->record('UPDATE ' . $table, array_merge($data, $criteria), (microtime(true) - $start) * 1000);

        return $result;
    }

    public function delete(string $table, array $criteria): int
    {
        $start = microtime(true);
        $result = $this->inner->delete($table, $criteria);
        $this->profiler->record('DELETE FROM ' . $table, $criteria, (microtime(true) - $start) * 1000);

        return $result;
    }

    public function lastInsertId(): string|int
    {
        return $this->inner->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    public function quote(string $value): string
    {
        return $this->inner->quote($value);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->inner->quoteIdentifier($identifier);
    }

    public function getDatabasePlatform(): Platform
    {
        return $this->inner->getDatabasePlatform();
    }

    public function getNativeConnection(): \PDO
    {
        return $this->inner->getNativeConnection();
    }

    public function isTransactionActive(): bool
    {
        return $this->inner->isTransactionActive();
    }

    public function createSavepoint(string $name): void
    {
        $this->inner->createSavepoint($name);
    }

    public function releaseSavepoint(string $name): void
    {
        $this->inner->releaseSavepoint($name);
    }

    public function rollbackSavepoint(string $name): void
    {
        $this->inner->rollbackSavepoint($name);
    }

    public function getInnerConnection(): Connection
    {
        return $this->inner;
    }
}
