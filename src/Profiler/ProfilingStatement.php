<?php

declare(strict_types=1);

namespace Weaver\ORM\Profiler;

use Weaver\ORM\DBAL\Result;
use Weaver\ORM\DBAL\Statement;

final class ProfilingStatement
{
    private array $params = [];

    public function __construct(
        private readonly Statement $inner,
        private readonly QueryProfiler $profiler,
        private readonly string $sql,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): void
    {
        $this->params[$param] = $value;
        $this->inner->bindValue($param, $value, $type);
    }

    public function execute(array $params = []): Result
    {
        $start = microtime(true);
        $result = $this->inner->execute($params);
        $this->profiler->record($this->sql, $this->params ?: $params, (microtime(true) - $start) * 1000);

        return $result;
    }

    public function executeStatement(array $params = []): int
    {
        $start = microtime(true);
        $result = $this->inner->executeStatement($params);
        $this->profiler->record($this->sql, $this->params ?: $params, (microtime(true) - $start) * 1000);

        return $result;
    }
}
