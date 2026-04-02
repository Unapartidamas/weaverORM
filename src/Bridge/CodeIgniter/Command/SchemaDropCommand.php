<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\CodeIgniter\Command;

use Weaver\ORM\Bridge\CodeIgniter\WeaverService;
use Weaver\ORM\Schema\SchemaGenerator;

final class SchemaDropCommand
{
    protected string $group = 'Weaver';
    protected string $name = 'weaver:schema:drop';
    protected string $description = 'Drop all tables managed by registered mappers';

    public function run(array $params): void
    {
        $force = in_array('--force', $params, true)
            || array_key_exists('force', $params);

        if (!$force) {
            $this->write('Use --force to confirm dropping all tables.');
            return;
        }

        $workspace = WeaverService::workspace();
        $connection = $workspace->getConnection();
        $platform = $connection->getDatabasePlatform();
        $generator = new SchemaGenerator($workspace->getMapperRegistry(), $platform);

        $schema = $generator->generate();
        $sqls = $schema->toDropSql($platform);

        if ($sqls === []) {
            $this->write('No tables to drop.');
            return;
        }

        foreach ($sqls as $sql) {
            $connection->executeStatement($sql);
            $this->write('Executed: ' . $sql);
        }

        $this->write('Schema dropped.');
    }

    private function write(string $message): void
    {
        if (class_exists('CodeIgniter\\CLI\\CLI')) {
            \CodeIgniter\CLI\CLI::write($message);
        }
    }
}
