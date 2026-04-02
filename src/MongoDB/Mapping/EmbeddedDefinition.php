<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB\Mapping;

final readonly class EmbeddedDefinition
{

    public function __construct(
        public readonly string $property,
        public readonly string $field,
        public readonly string $embeddedClass,
        public readonly string $embeddedMapper,
        public readonly bool $multiple = false,
    ) {}

    public function getProperty(): string
    {
        return $this->property;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getEmbeddedClass(): string
    {
        return $this->embeddedClass;
    }

    public function getEmbeddedMapper(): string
    {
        return $this->embeddedMapper;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }
}
