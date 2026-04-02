<?php

declare(strict_types=1);

namespace Weaver\ORM\Relation;

final class EagerLoadPlan
{

    private array $children = [];

    private ?\Closure $constraint = null;

    public function __construct(private readonly string $relation) {}

    public static function parse(array $withs): array
    {

        $plans = [];

        foreach ($withs as $key => $value) {
            if ($value instanceof \Closure) {

                $relationPath = (string) $key;
                $constraint   = $value;
            } else {

                $relationPath = (string) $value;
                $constraint   = null;
            }

            $segments = explode('.', $relationPath);

            $topSegment = $segments[0];

            if (!isset($plans[$topSegment])) {
                $plans[$topSegment] = new self($topSegment);
            }

            $currentPlan = $plans[$topSegment];

            for ($i = 1, $depth = count($segments); $i < $depth; $i++) {
                $segment = $segments[$i];

                if (!isset($currentPlan->children[$segment])) {
                    $currentPlan->children[$segment] = new self($segment);
                }

                $currentPlan = $currentPlan->children[$segment];
            }

            if ($constraint instanceof \Closure) {
                $currentPlan->constraint = $constraint;
            }
        }

        return $plans;
    }

    public function getRelation(): string
    {
        return $this->relation;
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    public function getConstraint(): ?\Closure
    {
        return $this->constraint;
    }

    public function setConstraint(\Closure $fn): void
    {
        $this->constraint = $fn;
    }

    public function getChildrenAsWithArray(): array
    {
        $withs = [];

        foreach ($this->children as $name => $child) {
            if ($child->getConstraint() !== null) {

                $withs[$name] = $child->getConstraint();
            } else {
                $withs[] = $name;
            }

            foreach ($child->flattenPaths($name) as $path => $closure) {
                if ($closure !== null) {
                    $withs[$path] = $closure;
                } else {
                    $withs[] = $path;
                }
            }
        }

        return $withs;
    }

    private function flattenPaths(string $prefix): array
    {
        $paths = [];

        foreach ($this->children as $name => $child) {
            $path         = $prefix . '.' . $name;
            $paths[$path] = $child->getConstraint();

            foreach ($child->flattenPaths($path) as $nested => $closure) {
                $paths[$nested] = $closure;
            }
        }

        return $paths;
    }
}
