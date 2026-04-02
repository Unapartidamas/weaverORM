<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

readonly class ColumnDefinition
{

    public function __construct(
        private string $column,
        private string $property,
        private string $type,
        private bool $primary = false,
        private bool $autoIncrement = false,
        private bool $nullable = false,
        private mixed $default = null,
        private ?int $length = null,
        private ?int $precision = null,
        private ?int $scale = null,
        private bool $unsigned = false,
        private bool $generated = false,
        private bool $virtual = false,
        private ?string $enumClass = null,
        private bool $version = false,
        private ?string $charset = null,
        private ?string $collation = null,
        private ?string $comment = null,
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
