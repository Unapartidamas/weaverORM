<?php

declare(strict_types=1);

namespace Weaver\ORM\Collection;

final class EntityCollection implements \Countable, \IteratorAggregate, \JsonSerializable
{
    public function __construct(private array $items = []) {}

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    public function first(): mixed
    {
        if ($this->items === []) {
            return null;
        }

        return reset($this->items);
    }

    public function last(): mixed
    {
        if ($this->items === []) {
            return null;
        }

        return end($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function jsonSerialize(): array
    {
        return array_values($this->items);
    }

    public function filter(\Closure $fn): static
    {
        return new self(array_values(array_filter($this->items, $fn)));
    }

    public function map(\Closure $fn): array
    {
        return array_map($fn, array_values($this->items));
    }

    public function each(\Closure $fn): static
    {
        foreach ($this->items as $item) {
            $fn($item);
        }

        return $this;
    }

    public function pluck(string $property, ?string $keyBy = null): array
    {
        $result = [];

        foreach ($this->items as $item) {
            $value = $item->$property;

            if ($keyBy !== null) {
                $result[$item->$keyBy] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    public function keyBy(string $property): array
    {
        $result = [];

        foreach ($this->items as $item) {
            $result[$item->$property] = $item;
        }

        return $result;
    }

    public function groupBy(string $property): array
    {
        $groups = [];

        foreach ($this->items as $item) {
            $key = $item->$property;
            $groups[$key][] = $item;
        }

        $result = [];

        foreach ($groups as $key => $group) {
            $result[$key] = new self($group);
        }

        return $result;
    }

    public function contains(object $entity): bool
    {
        return in_array($entity, $this->items, true);
    }

    public function containsWhere(string $property, mixed $value): bool
    {
        foreach ($this->items as $item) {
            if ($item->$property === $value) {
                return true;
            }
        }

        return false;
    }

    public function firstWhere(string $property, mixed $value): mixed
    {
        foreach ($this->items as $item) {
            if ($item->$property === $value) {
                return $item;
            }
        }

        return null;
    }

    public function add(object ...$entities): static
    {
        return new self(array_merge($this->items, array_values($entities)));
    }

    public function merge(self $other): static
    {
        return new self(array_merge($this->items, $other->items));
    }

    public function unique(string $property): static
    {
        $seen  = [];
        $items = [];

        foreach ($this->items as $item) {
            $key = $item->$property;

            if (!array_key_exists($key, $seen)) {
                $seen[$key]  = true;
                $items[]     = $item;
            }
        }

        return new self($items);
    }

    public function sortBy(string $property, string $direction = 'asc'): static
    {
        $items = $this->items;

        usort($items, static function (object $a, object $b) use ($property, $direction): int {
            $cmp = $a->$property <=> $b->$property;

            return $direction === 'desc' ? -$cmp : $cmp;
        });

        return new self($items);
    }

    public function chunk(int $size): array
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero.');
        }

        $chunks = [];

        foreach (array_chunk($this->items, $size) as $chunk) {
            $chunks[] = new self($chunk);
        }

        return $chunks;
    }

    public function slice(int $offset, ?int $length = null): static
    {
        return new self(array_values(array_slice($this->items, $offset, $length)));
    }

    public function sum(string $property): int|float
    {
        $total = 0;

        foreach ($this->items as $item) {
            $total += $item->$property;
        }

        return $total;
    }

    public function avg(string $property): float
    {
        if ($this->items === []) {
            return 0.0;
        }

        return $this->sum($property) / count($this->items);
    }

    public function min(string $property): mixed
    {
        if ($this->items === []) {
            return null;
        }

        $min = null;

        foreach ($this->items as $item) {
            $value = $item->$property;

            if ($min === null || $value < $min) {
                $min = $value;
            }
        }

        return $min;
    }

    public function max(string $property): mixed
    {
        if ($this->items === []) {
            return null;
        }

        $max = null;

        foreach ($this->items as $item) {
            $value = $item->$property;

            if ($max === null || $value > $max) {
                $max = $value;
            }
        }

        return $max;
    }

    public function ids(string $property = 'id'): array
    {
        return $this->pluck($property);
    }

    public function indexBy(string $property): array
    {
        return $this->keyBy($property);
    }
}
