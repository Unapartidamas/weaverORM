<?php

declare(strict_types=1);

namespace Weaver\ORM\Exception;

use Weaver\ORM\Schema\SchemaIssue;

final class SchemaValidationException extends \RuntimeException
{

    public function __construct(
        string $message,
        public readonly array $issues,
    ) {
        parent::__construct($message);
    }

    public static function fromIssues(array $issues): self
    {
        $lines = array_map(
            fn(SchemaIssue $i) => "  [{$i->type->value}] {$i->table}: {$i->message}",
            $issues,
        );

        return new self("Schema validation failed:\n" . implode("\n", $lines), $issues);
    }

    public function getIssues(): array
    {
        return $this->issues;
    }
}
