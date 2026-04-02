<?php
declare(strict_types=1);
namespace Weaver\ORM\Testing;

trait RefreshDatabase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        $this->createSchema();
    }

    private function dropAllTables(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        foreach (array_reverse($schemaManager->listTableNames()) as $table) {
            $this->connection->executeStatement("DROP TABLE IF EXISTS \"{$table}\"");
        }
    }
}
