<?php

declare(strict_types=1);

namespace Weaver\ORM\Profiler;

final class N1Detector
{
    public function __construct(private readonly QueryProfiler $profiler) {}

    public function detect(int $threshold = 3): array
    {
        $templateCounts = [];

        foreach ($this->profiler->getRecords() as $record) {
            $template = $this->normalizeToTemplate($record->sql);
            $templateCounts[$template] = ($templateCounts[$template] ?? 0) + 1;
        }

        $warnings = [];

        foreach ($templateCounts as $template => $count) {
            if ($count >= $threshold) {
                $warnings[] = new N1Warning(
                    sqlTemplate: $template,
                    occurrences: $count,
                    suggestion: 'Consider eager loading with with()',
                );
            }
        }

        return $warnings;
    }

    public function hasNPlusOneIssues(int $threshold = 3): bool
    {
        return count($this->detect($threshold)) > 0;
    }

    private function normalizeToTemplate(string $sql): string
    {

        $normalized = preg_replace('/:[a-zA-Z_][a-zA-Z0-9_]*/', '?', $sql);

        $normalized = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $normalized);

        $normalized = preg_replace('/\b\d+\b/', '?', $normalized);

        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim((string) $normalized);
    }
}
