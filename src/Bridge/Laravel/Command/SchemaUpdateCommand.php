<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Schema\SchemaGenerator;

final class SchemaUpdateCommand extends Command
{
    protected $signature = 'weaver:schema:update {--connection=default} {--dry-run}';

    protected $description = 'Sync the database schema to match registered mappers';

    public function handle(ConnectionRegistry $connectionRegistry, MapperRegistry $mapperRegistry): int
    {
        $connectionName = $this->option('connection');
        $connection = $connectionRegistry->getConnection($connectionName);
        $platform = $connection->getDatabasePlatform();

        $generator = new SchemaGenerator($mapperRegistry, $platform);
        $sqls = $generator->generateSql();

        if ($sqls === []) {
            $this->info('Schema is already up to date — nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('SQL to be executed:');

            foreach ($sqls as $sql) {
                $this->line($sql);
            }

            return self::SUCCESS;
        }

        foreach ($sqls as $sql) {
            $connection->executeStatement($sql);
            $this->line("<info>Executed:</info> {$sql}");
        }

        $this->info('Schema updated.');

        return self::SUCCESS;
    }
}
