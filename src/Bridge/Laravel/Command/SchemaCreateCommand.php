<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Schema\SchemaGenerator;

final class SchemaCreateCommand extends Command
{
    protected $signature = 'weaver:schema:create {--connection=default}';

    protected $description = 'Create all tables from registered mappers';

    public function handle(ConnectionRegistry $connectionRegistry, MapperRegistry $mapperRegistry): int
    {
        $connectionName = $this->option('connection');
        $connection = $connectionRegistry->getConnection($connectionName);
        $platform = $connection->getDatabasePlatform();

        $generator = new SchemaGenerator($mapperRegistry, $platform);
        $schema = $generator->generate();
        $sqls = $schema->toSql($platform);

        if ($sqls === []) {
            $this->info('No SQL statements to execute — schema may already be up to date.');

            return self::SUCCESS;
        }

        foreach ($sqls as $sql) {
            $connection->executeStatement($sql);
            $this->line("<info>Executed:</info> {$sql}");
        }

        $this->info('Schema created.');

        return self::SUCCESS;
    }
}
