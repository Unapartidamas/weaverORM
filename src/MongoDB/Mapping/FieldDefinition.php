<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB\Mapping;

final readonly class FieldDefinition
{

    public function __construct(
        private string $field,
        private string $property,
        private FieldType $type,
        private bool $nullable = false,
        private mixed $default = null,
        private ?string $enumClass = null,
    ) {}

    public function getField(): string
    {
        return $this->field;
    }

    public function getProperty(): string
    {
        return $this->property;
    }

    public function getType(): FieldType
    {
        return $this->type;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function getEnumClass(): ?string
    {
        return $this->enumClass;
    }
}
