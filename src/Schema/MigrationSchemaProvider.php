<?php

declare(strict_types=1);

namespace Weaver\ORM\Schema;

final readonly class MigrationSchemaProvider
{
    public function __construct(
        private SchemaGenerator $schemaGenerator,
    ) {}

    public function generateSql(): array
    {
        return $this->schemaGenerator->generateSql();
    }
}
