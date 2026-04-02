<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Cdc;

final readonly class CdcEvent
{

    public function __construct(
        public readonly string $operation,
        public readonly string $table,
        public readonly array $before,
        public readonly array $after,
        public readonly int $lsn,
        public readonly string $transactionId,
        public readonly \DateTimeImmutable $timestamp,
    ) {}

    public function isInsert(): bool
    {
        return $this->operation === 'INSERT';
    }

    public function isUpdate(): bool
    {
        return $this->operation === 'UPDATE';
    }

    public function isDelete(): bool
    {
        return $this->operation === 'DELETE';
    }

    public function getChangedFields(): array
    {
        if ($this->isInsert()) {
            return array_keys($this->after);
        }

        if ($this->isDelete()) {
            return array_keys($this->before);
        }

        $changed = [];

        $allKeys = array_unique(
            array_merge(array_keys($this->before), array_keys($this->after))
        );

        foreach ($allKeys as $field) {

            if (($this->before[$field] ?? null) != ($this->after[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }
}
