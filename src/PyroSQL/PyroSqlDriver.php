<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL;

use Weaver\ORM\PyroSQL\Exception\UnsupportedDriverFeatureException;

final class PyroSqlDriver
{

    private bool $detected = false;

    private ?bool $isPyroSql = null;

    private ?string $version = null;

    public function __construct(
        private readonly \Weaver\ORM\DBAL\Connection $connection,
    ) {}

    public function isPyroSql(): bool
    {
        if ($this->detected) {
            return $this->isPyroSql ?? false;
        }

        $this->detected = true;

        try {
            $row = $this->connection->fetchAssociative(
                "SELECT current_setting('pyrosql.version', true) AS v"
            );

            if ($row !== false && isset($row['v']) && $row['v'] !== null && $row['v'] !== '') {
                $this->isPyroSql = true;
                $this->version      = is_scalar($row['v']) ? (string) $row['v'] : null;
            } else {
                $this->isPyroSql = false;
            }
        } catch (\Throwable) {
            $this->isPyroSql = false;
        }

        return $this->isPyroSql;
    }

    public function getVersion(): ?string
    {
        $this->isPyroSql();

        return $this->version;
    }

    public function supportsTimeTravel(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsBranching(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsVectors(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsApproximate(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsAutoIndexing(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsCdc(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsWasmUdfs(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsFullTextSearch(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsGeo(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsRls(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsPartitioning(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsPubSub(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsAuditLog(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsAutoIndex(): bool
    {
        return $this->isPyroSql();
    }

    public function supportsQueryCache(): bool
    {
        return $this->isPyroSql();
    }

    public function assertSupports(string $feature): void
    {
        if (!$this->isPyroSql()) {
            throw UnsupportedDriverFeatureException::forFeature($feature);
        }
    }
}
