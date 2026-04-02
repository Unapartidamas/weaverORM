<?php

declare(strict_types=1);

namespace Weaver\ORM\Schema;

final readonly class SchemaDiff
{
    public function __construct(

        public readonly array $missingTables,

        public readonly array $extraTables,

        public readonly array $missingColumns,

        public readonly array $extraColumns,

        public readonly array $typeMismatches,
    ) {}

    public function isEmpty(): bool
    {
        return $this->missingTables === []
            && $this->extraTables === []
            && $this->missingColumns === []
            && $this->extraColumns === []
            && $this->typeMismatches === [];
    }

    public function toArray(): array
    {
        return [
            'missingTables'  => $this->missingTables,
            'extraTables'    => $this->extraTables,
            'missingColumns' => $this->missingColumns,
            'extraColumns'   => $this->extraColumns,
            'typeMismatches' => $this->typeMismatches,
        ];
    }

    public function toText(): string
    {
        if ($this->isEmpty()) {
            return 'Schema is in sync. No differences found.';
        }

        $lines = ['Schema diff found the following issues:'];
        $lines[] = '';

        if ($this->missingTables !== []) {
            $lines[] = 'Missing tables (in mapper but not in DB):';
            foreach ($this->missingTables as $table) {
                $lines[] = "  - {$table}";
            }
            $lines[] = '';
        }

        if ($this->extraTables !== []) {
            $lines[] = 'Extra tables (in DB but not in any mapper):';
            foreach ($this->extraTables as $table) {
                $lines[] = "  + {$table}";
            }
            $lines[] = '';
        }

        if ($this->missingColumns !== []) {
            $lines[] = 'Missing columns (in mapper but not in DB):';
            foreach ($this->missingColumns as $table => $columns) {
                foreach ($columns as $column) {
                    $lines[] = "  - {$table}.{$column}";
                }
            }
            $lines[] = '';
        }

        if ($this->extraColumns !== []) {
            $lines[] = 'Extra columns (in DB but not in mapper):';
            foreach ($this->extraColumns as $table => $columns) {
                foreach ($columns as $column) {
                    $lines[] = "  + {$table}.{$column}";
                }
            }
            $lines[] = '';
        }

        if ($this->typeMismatches !== []) {
            $lines[] = 'Type mismatches:';
            foreach ($this->typeMismatches as $table => $columns) {
                foreach ($columns as $column => $types) {
                    $lines[] = "  ~ {$table}.{$column}: mapped={$types['mapped']}, actual={$types['actual']}";
                }
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }
}
