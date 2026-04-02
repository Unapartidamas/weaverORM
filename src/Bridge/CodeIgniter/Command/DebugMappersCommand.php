<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\CodeIgniter\Command;

use Weaver\ORM\Bridge\CodeIgniter\WeaverService;

final class DebugMappersCommand
{
    protected string $group = 'Weaver';
    protected string $name = 'weaver:debug:mappers';
    protected string $description = 'List all registered entity mappers';

    public function run(array $params): void
    {
        $registry = WeaverService::mapperRegistry();
        $mappers = $registry->all();

        if ($mappers === []) {
            $this->write('No mappers registered.');
            return;
        }

        $this->write('Registered mappers:');

        foreach ($mappers as $entityClass => $mapper) {
            $this->write(sprintf(
                '  %s -> %s (PK: %s)',
                $entityClass,
                $mapper->getTableName(),
                $mapper->getPrimaryKey(),
            ));
        }

        $this->write(sprintf('Total: %d mapper(s)', count($mappers)));
    }

    private function write(string $message): void
    {
        if (class_exists('CodeIgniter\\CLI\\CLI')) {
            \CodeIgniter\CLI\CLI::write($message);
        }
    }
}
