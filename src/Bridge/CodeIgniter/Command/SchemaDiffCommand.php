<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\CodeIgniter\Command;

use Weaver\ORM\Bridge\CodeIgniter\WeaverService;
use Weaver\ORM\Schema\SchemaDiffer;

final class SchemaDiffCommand
{
    protected string $group = 'Weaver';
    protected string $name = 'weaver:schema:diff';
    protected string $description = 'Show diff between entity mappings and actual database schema';

    public function run(array $params): void
    {
        $workspace = WeaverService::workspace();
        $connection = $workspace->getConnection();
        $differ = new SchemaDiffer($connection, $workspace->getMapperRegistry());

        $diff = $differ->diff();

        if ($diff->isEmpty()) {
            $this->write('Schema is in sync. No differences found.');
            return;
        }

        $this->write($diff->toText());
    }

    private function write(string $message): void
    {
        if (class_exists('CodeIgniter\\CLI\\CLI')) {
            \CodeIgniter\CLI\CLI::write($message);
        }
    }
}
