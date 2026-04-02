<?php

declare(strict_types=1);

namespace Weaver\ORM\Connection;

use Weaver\ORM\DBAL\Connection;

final class ConnectionRegistry
{
    private array $configs;
    private array $connections = [];
    private string $defaultName;
    private ConnectionFactory $factory;

    public function __construct(array $connections, string $defaultName = 'default')
    {
        $this->configs = $connections;
        $this->defaultName = $defaultName;
        $this->factory = new ConnectionFactory();
    }

    public function getConnection(string $name = 'default'): Connection
    {
        if (!$this->hasConnection($name)) {
            throw new \InvalidArgumentException("Connection '{$name}' is not configured.");
        }

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->factory->createConnection($this->configs[$name]);
        }

        return $this->connections[$name];
    }

    public function getDefaultConnection(): Connection
    {
        return $this->getConnection($this->defaultName);
    }

    public function getConnectionNames(): array
    {
        return array_keys($this->configs);
    }

    public function hasConnection(string $name): bool
    {
        return isset($this->configs[$name]);
    }

    public function close(string $name): void
    {
        unset($this->connections[$name]);
    }

    public function closeAll(): void
    {
        foreach (array_keys($this->connections) as $name) {
            $this->close($name);
        }
    }
}
