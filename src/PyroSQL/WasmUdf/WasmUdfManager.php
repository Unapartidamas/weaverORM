<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\WasmUdf;

use Weaver\ORM\PyroSQL\PyroSqlDriver;

final readonly class WasmUdfManager
{
    public function __construct(
        private \Weaver\ORM\DBAL\Connection $connection,
        private PyroSqlDriver $driver,
    ) {}

    public function registerFromFile(
        string $name,
        string $wasmPath,
        string $returnType,
        array $args = [],
        bool $replace = false,
    ): void {
        $this->driver->assertSupports('wasm_udfs');

        if (!is_readable($wasmPath)) {
            throw new \RuntimeException("Cannot read WASM file: {$wasmPath}");
        }

        $binary = file_get_contents($wasmPath);

        if ($binary === false) {
            throw new \RuntimeException("Cannot read WASM file: {$wasmPath}");
        }

        $encoded = base64_encode($binary);

        $this->registerFromBase64($name, $encoded, $returnType, $args, $replace);
    }

    public function registerFromBase64(
        string $name,
        string $base64,
        string $returnType,
        array $args = [],
        bool $replace = false,
    ): void {
        $this->driver->assertSupports('wasm_udfs');

        $this->assertSqlType($returnType);
        foreach ($args as $arg) {
            $this->assertSqlType($arg);
        }

        $orReplace = $replace ? 'OR REPLACE ' : '';
        $argList   = implode(', ', $args);

        $sql = "CREATE {$orReplace}FUNCTION " . $this->connection->quoteIdentifier($name)
             . "({$argList}) RETURNS {$returnType} "
             . "LANGUAGE wasm AS " . $this->connection->quote($base64);

        $this->connection->executeStatement($sql);
    }

    public function list(): array
    {
        $this->driver->assertSupports('wasm_udfs');

        return $this->connection->fetchAllAssociative(
            "SELECT name, return_type, arg_types, created_at "
            . "FROM pyrosql_wasm_functions ORDER BY name ASC"
        );
    }

    public function exists(string $name): bool
    {
        $this->driver->assertSupports('wasm_udfs');

        $row = $this->connection->fetchAssociative(
            "SELECT 1 FROM pyrosql_wasm_functions WHERE name = ?",
            [$name],
        );

        return $row !== false;
    }

    public function drop(string $name): void
    {
        $this->driver->assertSupports('wasm_udfs');
        $this->connection->executeStatement("DROP FUNCTION " . $this->connection->quoteIdentifier($name));
    }

    public function dropIfExists(string $name): void
    {
        $this->driver->assertSupports('wasm_udfs');
        $this->connection->executeStatement("DROP FUNCTION IF EXISTS " . $this->connection->quoteIdentifier($name));
    }

    private function assertSqlType(string $type): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9\s,()]*$/', $type)) {
            throw new \InvalidArgumentException(
                "Invalid SQL type '{$type}': only alphanumeric characters, spaces, parentheses and commas are allowed."
            );
        }
    }
}
