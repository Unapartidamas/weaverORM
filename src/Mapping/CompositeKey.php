<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

final class CompositeKey implements \ArrayAccess, \Stringable
{

    public function __construct(private readonly array $values) {}

    public function toArray(): array { return $this->values; }

    public function offsetExists(mixed $offset): bool { return isset($this->values[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->values[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { throw new \LogicException('CompositeKey is immutable.'); }
    public function offsetUnset(mixed $offset): void { throw new \LogicException('CompositeKey is immutable.'); }

    public function __toString(): string
    {
        return implode(',', array_map(
            static fn($k, $v) => "{$k}={$v}",
            array_keys($this->values),
            $this->values,
        ));
    }

    public function equals(self $other): bool
    {
        return $this->values === $other->values;
    }
}
