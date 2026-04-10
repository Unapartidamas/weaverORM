<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

readonly class ColumnDefinition
{

    public function __construct(
        public string $column,
        public string $property,
        public string $type,
        public bool $primary = false,
        public bool $autoIncrement = false,
        public bool $nullable = false,
        public mixed $default = null,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $unsigned = false,
        public bool $generated = false,
        public bool $virtual = false,
        public ?string $enumClass = null,
        public bool $version = false,
        public ?string $charset = null,
        public ?string $collation = null,
        public ?string $comment = null,
    ) {}

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getProperty(): string
    {
        return $this->property;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function getPrecision(): ?int
    {
        return $this->precision;
    }

    public function getScale(): ?int
    {
        return $this->scale;
    }

    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    public function isGenerated(): bool
    {
        return $this->generated;
    }

    public function isVirtual(): bool
    {
        return $this->virtual;
    }

    public function getEnumClass(): ?string
    {

        $enumClass = $this->enumClass;

        return $enumClass;
    }

    public function isVersion(): bool
    {
        return $this->version;
    }

    public function getCharset(): ?string
    {
        return $this->charset;
    }

    public function getCollation(): ?string
    {
        return $this->collation;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }
}
