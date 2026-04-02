<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Schema\SchemaGenerator;

final class SchemaDropCommand extends Command
{
    protected $signature = 'weaver:schema:drop {--connection=default} {--force}';

    protected $description = 'Drop all tables managed by registered mappers';

    public function handle(ConnectionRegistry $connectionRegistry, MapperRegistry $mapperRegistry): int
    {
        $connectionName = $this->option('connection');
        $connection = $connectionRegistry->getConnection($connectionName);
        $platform = $connection->getDatabasePlatform();

        if (!$this->option('force') && !$this->confirm('This will drop ALL tables managed by Weaver ORM. Are you sure?', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $generator = new SchemaGenerator($mapperRegistry, $platform);
        $schema = $generator->generate();
        $sqls = $schema->toDropSql($platform);

        if ($sqls === []) {
            $this->info('No tables to drop.');

            return self::SUCCESS;
        }

        foreach ($sqls as $sql) {
            $connection->executeStatement($sql);
            $this->line("<info>Executed:</info> {$sql}");
        }

        $this->info('Schema dropped.');

        return self::SUCCESS;
    }
}
