<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB\Mapping;

final readonly class ReferenceDefinition
{

    public function __construct(
        public readonly string $property,
        public readonly string $field,
        public readonly string $relatedClass,
        public readonly string $relatedMapper,
        public readonly bool $multiple = false,
        public readonly string $localField = '_id',
        public readonly string $foreignField = '_id',
    ) {}

    public function getProperty(): string
    {
        return $this->property;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getRelatedClass(): string
    {
        return $this->relatedClass;
    }

    public function getRelatedMapper(): string
    {
        return $this->relatedMapper;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function getLocalField(): string
    {
        return $this->localField;
    }

    public function getForeignField(): string
    {
        return $this->foreignField;
    }
}
