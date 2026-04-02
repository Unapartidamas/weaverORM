<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\CodeIgniter\Command;

use Weaver\ORM\Bridge\CodeIgniter\WeaverService;
use Weaver\ORM\Schema\SchemaGenerator;

final class SchemaCreateCommand
{
    protected string $group = 'Weaver';
    protected string $name = 'weaver:schema:create';
    protected string $description = 'Create database schema from entity mappers';

    public function run(array $params): void
    {
        $workspace = WeaverService::workspace();
        $connection = $workspace->getConnection();
        $platform = $connection->getDatabasePlatform();
        $generator = new SchemaGenerator($workspace->getMapperRegistry(), $platform);

        $schema = $generator->generate();
        $sqls = $schema->toSql($platform);

        if ($sqls === []) {
            $this->write('No SQL statements to execute — schema may already be up to date.');
            return;
        }

        foreach ($sqls as $sql) {
            $connection->executeStatement($sql);
            $this->write('Executed: ' . $sql);
        }

        $this->write('Schema created.');
    }

    private function write(string $message): void
    {
        if (class_exists('CodeIgniter\\CLI\\CLI')) {
            \CodeIgniter\CLI\CLI::write($message);
        }
    }
}
