<?php
declare(strict_types=1);
namespace Weaver\ORM\Bridge\Symfony\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

final class WeaverDataCollector extends DataCollector
{

    private array $queries = [];
    private float $totalTimeMs = 0.0;

    private array $n1Warnings = [];

    public function recordQuery(string $sql, array $params, float $durationMs, string $entity = ''): void
    {
        $this->queries[]    = ['sql' => $sql, 'params' => $params, 'duration_ms' => $durationMs, 'entity' => $entity];
        $this->totalTimeMs += $durationMs;
    }

    public function recordN1Warning(string $entity, string $relation, int $count): void
    {
        $this->n1Warnings[] = ['entity' => $entity, 'relation' => $relation, 'count' => $count];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'queries'     => $this->queries,
            'total_ms'    => $this->totalTimeMs,
            'count'       => count($this->queries),
            'n1_warnings' => $this->n1Warnings,
        ];
    }

    public function reset(): void
    {
        $this->data        = [];
        $this->queries     = [];
        $this->totalTimeMs = 0.0;
        $this->n1Warnings  = [];
    }

    public function getName(): string         { return 'weaver'; }
    public function getQueries(): array       { return $this->data['queries']     ?? []; }
    public function getQueryCount(): int      { return $this->data['count']       ?? 0; }
    public function getTotalTimeMs(): float   { return $this->data['total_ms']    ?? 0.0; }
    public function getN1Warnings(): array    { return $this->data['n1_warnings'] ?? []; }
    public function hasN1Warnings(): bool     { return !empty($this->data['n1_warnings']); }
}
