<?php

declare(strict_types=1);

namespace Weaver\ORM\Schema;

final readonly class SchemaIssue
{
    public function __construct(
        public readonly SchemaIssueType $type,
        public readonly string $table,
        public readonly string $message,
        public readonly ?string $column = null,
    ) {}
}
