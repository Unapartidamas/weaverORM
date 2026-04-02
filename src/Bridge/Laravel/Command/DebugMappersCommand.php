<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Weaver\ORM\Mapping\MapperRegistry;

final class DebugMappersCommand extends Command
{
    protected $signature = 'weaver:debug:mappers';

    protected $description = 'List all registered entity mappers';

    public function handle(MapperRegistry $mapperRegistry): int
    {
        $mappers = $mapperRegistry->all();

        if ($mappers === []) {
            $this->info('No mappers registered.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($mappers as $mapper) {
            $rows[] = [
                $mapper->getEntityClass(),
                $mapper->getTableName(),
                count($mapper->getColumns()),
            ];
        }

        $this->table(['Entity Class', 'Table Name', 'Columns'], $rows);

        return self::SUCCESS;
    }
}
