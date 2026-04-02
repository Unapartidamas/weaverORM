<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Schema\SchemaDiffer;

final class SchemaDiffCommand extends Command
{
    protected $signature = 'weaver:schema:diff {--connection=default}';

    protected $description = 'Show diff between entity mappings and actual database schema';

    public function handle(ConnectionRegistry $connectionRegistry, MapperRegistry $mapperRegistry): int
    {
        $connectionName = $this->option('connection');
        $connection = $connectionRegistry->getConnection($connectionName);

        $differ = new SchemaDiffer($connection, $mapperRegistry);
        $diff = $differ->diff();

        if ($diff->isEmpty()) {
            $this->info('Schema is in sync. No differences found.');

            return self::SUCCESS;
        }

        $this->error($diff->toText());

        if ($diff->missingTables !== []) {
            $this->line('');
            $this->info('Missing tables:');
            foreach ($diff->missingTables as $table) {
                $this->line("  - {$table}");
            }
        }

        if ($diff->extraTables !== []) {
            $this->line('');
            $this->info('Extra tables (in DB but not mapped):');
            foreach ($diff->extraTables as $table) {
                $this->line("  - {$table}");
            }
        }

        return self::FAILURE;
    }
}
