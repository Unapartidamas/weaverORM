<?php

declare(strict_types=1);

namespace Weaver\ORM\Manager;

use Weaver\ORM\Manager\Exception\ManagerNotFoundException;

final class ManagerRegistry
{

    private array $managers = [];
    private string $defaultName;

    public function __construct(array $managers, string $default = 'default')
    {
        foreach ($managers as $name => $manager) {
            $this->managers[$name] = $manager;
        }
        $this->defaultName = $default;
    }

    public function getManager(?string $name = null): EntityWorkspace
    {
        $name ??= $this->defaultName;
        return $this->managers[$name] ?? throw new ManagerNotFoundException($name);
    }

    public function getDefaultManager(): EntityWorkspace
    {
        return $this->getManager();
    }

    public function all(): array
    {
        return $this->managers;
    }

    public function hasManager(string $name): bool
    {
        return isset($this->managers[$name]);
    }

    public function getManagerNames(): array
    {
        return array_keys($this->managers);
    }
}
