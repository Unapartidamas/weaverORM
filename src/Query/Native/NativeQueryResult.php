<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Native;

final class NativeQueryResult
{

    public function __construct(private readonly array $results) {}

    public function getResults(): array { return $this->results; }

    public function getFirstResult(): ?object { return $this->results[0] ?? null; }

    public function count(): int { return count($this->results); }
    public function isEmpty(): bool { return $this->results === []; }
}
