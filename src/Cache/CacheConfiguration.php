<?php

declare(strict_types=1);

namespace Weaver\ORM\Cache;

use Psr\SimpleCache\CacheInterface;

final class CacheConfiguration
{
    private array $regions = [];

    public function __construct(
        private bool $enabled = false,
        private ?CacheInterface $adapter = null,
        private int $defaultTtl = 3600,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getAdapter(): ?CacheInterface
    {
        return $this->adapter;
    }

    public function setAdapter(?CacheInterface $adapter): self
    {
        $this->adapter = $adapter;
        return $this;
    }

    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    public function setDefaultTtl(int $defaultTtl): self
    {
        $this->defaultTtl = $defaultTtl;
        return $this;
    }

    public function getRegions(): array
    {
        return $this->regions;
    }

    public function addRegion(string $name, int $ttl): self
    {
        $this->regions[$name] = $ttl;
        return $this;
    }

    public function getRegionTtl(string $name): int
    {
        return $this->regions[$name] ?? $this->defaultTtl;
    }
}
