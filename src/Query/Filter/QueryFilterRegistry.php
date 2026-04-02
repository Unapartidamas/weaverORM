<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Filter;

final class QueryFilterRegistry
{

    private array $filters = [];

    private array $enabled = [];

    public function add(QueryFilterInterface $filter): void
    {
        $this->filters[] = $filter;
    }

    public function register(string $name, QueryFilterInterface $filter): void
    {
        $this->filters[$name] = $filter;
        $this->enabled[$name] = true;
    }

    public function enable(string $filterName): void
    {
        if (!array_key_exists($filterName, $this->filters)) {
            throw new \InvalidArgumentException(sprintf('Filter "%s" is not registered.', $filterName));
        }

        $this->enabled[$filterName] = true;
    }

    public function disable(string $filterName): void
    {
        if (!array_key_exists($filterName, $this->filters)) {
            throw new \InvalidArgumentException(sprintf('Filter "%s" is not registered.', $filterName));
        }

        $this->enabled[$filterName] = false;
    }

    public function isEnabled(string $filterName): bool
    {
        return $this->enabled[$filterName] ?? false;
    }

    public function getEnabledFilters(): array
    {
        $result = [];

        foreach ($this->filters as $name => $filter) {
            if (is_string($name) && ($this->enabled[$name] ?? false)) {
                $result[$name] = $filter;
            }
        }

        return $result;
    }

    public function remove(QueryFilterInterface $filter): void
    {
        $this->filters = array_values(
            array_filter($this->filters, static fn ($f) => $f !== $filter)
        );
    }

    public function getFiltersFor(string $entityClass): array
    {
        $active = [];

        foreach ($this->filters as $name => $filter) {
            if (is_string($name) && !($this->enabled[$name] ?? false)) {
                continue;
            }

            if ($filter->supports($entityClass)) {
                $active[] = $filter;
            }
        }

        return $active;
    }

    public function hasFilters(string $entityClass): bool
    {
        return $this->getFiltersFor($entityClass) !== [];
    }
}
