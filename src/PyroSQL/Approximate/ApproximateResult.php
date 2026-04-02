<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Approximate;

final readonly class ApproximateResult implements \Stringable
{
    public function __construct(
        public readonly mixed $value,
        public readonly float $errorMargin,
        public readonly float $confidence,
        public readonly int $sampledRows,
        public readonly int $totalRows,
        public readonly bool $isApproximate,
    ) {}

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function toFloat(): float
    {
        return is_numeric($this->value) ? (float) $this->value : 0.0;
    }

    public function toInt(): int
    {
        return is_numeric($this->value) ? (int) $this->value : 0;
    }

    public function __toString(): string
    {
        $marker = $this->isApproximate ? '≈' : '=';
        $valueStr = is_scalar($this->value) ? (string) $this->value : '';

        return "{$marker}{$valueStr} (±{$this->errorMargin}% @{$this->confidence}% confidence)";
    }
}
