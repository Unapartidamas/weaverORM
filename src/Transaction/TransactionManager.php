<?php

declare(strict_types=1);

namespace Weaver\ORM\Transaction;

use Weaver\ORM\DBAL\Connection;

final class TransactionManager
{

    private int $depth = 0;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function transactional(callable $fn): mixed
    {
        if (!$this->isActive()) {

            $this->begin();
            try {
                $result = $fn();
                $this->commit();
                return $result;
            } catch (\Throwable $e) {
                $this->rollback();
                throw $e;
            }
        }

        $savepointName = 'sp_' . $this->depth;
        $this->savepoint($savepointName);
        try {
            $result = $fn();
            $this->releaseSavepoint($savepointName);
            return $result;
        } catch (\Throwable $e) {
            $this->rollbackTo($savepointName);
            throw $e;
        }
    }

    public function withDeadlockRetry(callable $fn, int $maxRetries = 3): mixed
    {
        $lastException = null;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                return $this->transactional($fn);
            } catch (\RuntimeException $e) {
                if (!$this->isDeadlock($e)) {
                    throw $e;
                }

                $lastException = $e;

                usleep(100_000 * (2 ** $attempt));
            }
        }

        if ($lastException instanceof \RuntimeException) {
            throw $lastException;
        }

        throw new \RuntimeException('withDeadlockRetry: no exception recorded after retries exhausted.');
    }

    public function begin(): void
    {
        $this->connection->beginTransaction();
        $this->depth++;
    }

    public function commit(): void
    {
        $this->connection->commit();
        $this->depth--;
    }

    public function rollback(): void
    {
        $this->connection->rollBack();
        $this->depth = 0;
    }

    public function savepoint(string $name): void
    {
        $this->connection->createSavepoint($name);
    }

    public function rollbackTo(string $name): void
    {
        $this->connection->rollbackSavepoint($name);
    }

    public function releaseSavepoint(string $name): void
    {
        $this->connection->releaseSavepoint($name);
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    private function isDeadlock(\RuntimeException $e): bool
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'deadlock')) {
            return true;
        }

        $previous = $e->getPrevious();

        if ($previous instanceof \PDOException) {
            $errorInfo = $previous->errorInfo;

            if (is_array($errorInfo) && isset($errorInfo[1]) && (int) $errorInfo[1] === 1213) {
                return true;
            }

            if (is_array($errorInfo) && ($errorInfo[0] ?? '') === '40P01') {
                return true;
            }
        }

        return false;
    }
}
