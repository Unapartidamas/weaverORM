<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\CodeIgniter\Command;

use Weaver\ORM\Bridge\CodeIgniter\WeaverService;
use Weaver\ORM\Schema\SchemaGenerator;

final class SchemaUpdateCommand
{
    protected string $group = 'Weaver';
    protected string $name = 'weaver:schema:update';
    protected string $description = 'Sync the database schema to match registered mappers';

    public function run(array $params): void
    {
        $dryRun = in_array('--dry-run', $params, true)
            || array_key_exists('dry-run', $params);

        $workspace = WeaverService::workspace();
        $connection = $workspace->getConnection();
        $platform = $connection->getDatabasePlatform();
        $generator = new SchemaGenerator($workspace->getMapperRegistry(), $platform);

        $sqls = $generator->generateSql();

        if ($sqls === []) {
            $this->write('Schema is already up to date.');
            return;
        }

        if ($dryRun) {
            $this->write('SQL to be executed:');
            foreach ($sqls as $sql) {
                $this->write('  ' . $sql);
            }
            return;
        }

        foreach ($sqls as $sql) {
            $connection->executeStatement($sql);
            $this->write('Executed: ' . $sql);
        }

        $this->write('Schema updated.');
    }

    private function write(string $message): void
    {
        if (class_exists('CodeIgniter\\CLI\\CLI')) {
            \CodeIgniter\CLI\CLI::write($message);
        }
    }
}
