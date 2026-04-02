<?php

declare(strict_types=1);

namespace Weaver\ORM\Manager;

use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Mapping\Attribute\Connection;
use Weaver\ORM\Manager\Exception\ManagerNotFoundException;

final class WorkspaceRegistry
{
    private array $workspaces = [];
    private array $entityConnectionMap = [];
    private string $defaultName;

    public function __construct(
        private readonly ConnectionRegistry $connectionRegistry,
        private readonly \Closure $workspaceFactory,
        string $defaultName = 'default',
    ) {
        $this->defaultName = $defaultName;
    }

    public function getWorkspace(string $connectionName = 'default'): EntityWorkspace
    {
        if (!$this->connectionRegistry->hasConnection($connectionName)) {
            throw new ManagerNotFoundException($connectionName);
        }

        if (!isset($this->workspaces[$connectionName])) {
            $connection = $this->connectionRegistry->getConnection($connectionName);
            $this->workspaces[$connectionName] = ($this->workspaceFactory)($connectionName, $connection);
        }

        return $this->workspaces[$connectionName];
    }

    public function getDefaultWorkspace(): EntityWorkspace
    {
        return $this->getWorkspace($this->defaultName);
    }

    public function getWorkspaceForEntity(string $entityClass): EntityWorkspace
    {
        if (isset($this->entityConnectionMap[$entityClass])) {
            return $this->getWorkspace($this->entityConnectionMap[$entityClass]);
        }

        $attributes = (new \ReflectionClass($entityClass))->getAttributes(Connection::class);

        if ($attributes !== []) {
            $connectionName = $attributes[0]->newInstance()->name;
            $this->entityConnectionMap[$entityClass] = $connectionName;

            return $this->getWorkspace($connectionName);
        }

        $this->entityConnectionMap[$entityClass] = $this->defaultName;

        return $this->getDefaultWorkspace();
    }

    public function getWorkspaceNames(): array
    {
        return $this->connectionRegistry->getConnectionNames();
    }

    public function resetAll(): void
    {
        $this->workspaces = [];
        $this->entityConnectionMap = [];
    }
}
