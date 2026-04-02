<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB\Mapping;

abstract class AbstractDocumentMapper
{

    abstract public function getDocumentClass(): string;

    abstract public function getCollectionName(): string;

    abstract public function getFields(): array;

    public function getEmbedded(): array
    {
        return [];
    }

    public function getReferences(): array
    {
        return [];
    }

    public function getIndexes(): array
    {
        return [];
    }

    public function getDatabaseName(): ?string
    {
        return null;
    }

    public function getConnectionName(): string
    {
        return 'mongodb';
    }

    public function hydrate(array $document): object
    {
        $entity = $this->newInstance();

        foreach ($this->getFields() as $def) {
            if (!array_key_exists($def->getField(), $document)) {
                continue;
            }

            $value = $document[$def->getField()];
            $value = $this->convertFromMongo($value, $def);

            if ($value !== null && $def->getEnumClass() !== null) {

                $enumClass = $def->getEnumClass();
                $value = $enumClass::from($value);
            }

            $this->setProperty($entity, $def->getProperty(), $value);
        }

        if (isset($document['_id']) && !array_key_exists('id', $document)) {
            $id = $document['_id'];
            $this->setProperty(
                $entity,
                'id',
                $id instanceof \MongoDB\BSON\ObjectId ? (string) $id : $id,
            );
        }

        return $entity;
    }

    public function extract(object $entity): array
    {
        $doc = [];

        foreach ($this->getFields() as $def) {
            $value = $this->getProperty($entity, $def->getProperty());

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $doc[$def->getField()] = $this->convertToMongo($value, $def);
        }

        return $doc;
    }

    public function newInstance(): object
    {
        $class = $this->getDocumentClass();

        return new $class();
    }

    public function setProperty(object $entity, string $property, mixed $value): void
    {
        try {
            $entity->$property = $value;
        } catch (\TypeError $e) {
            if ($value instanceof \MongoDB\BSON\ObjectId) {
                $entity->$property = (string) $value;
            } else {
                throw $e;
            }
        }
    }

    public function getProperty(object $entity, string $property): mixed
    {
        return $entity->$property;
    }

    public function getField(string $property): ?FieldDefinition
    {
        foreach ($this->getFields() as $def) {
            if ($def->getProperty() === $property) {
                return $def;
            }
        }

        return null;
    }

    public function getFieldByName(string $field): ?FieldDefinition
    {
        foreach ($this->getFields() as $def) {
            if ($def->getField() === $field) {
                return $def;
            }
        }

        return null;
    }

    private function convertFromMongo(mixed $value, FieldDefinition $def): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($def->getType()) {
            FieldType::ObjectId => $value instanceof \MongoDB\BSON\ObjectId
                ? (string) $value
                : $value,

            FieldType::Date => $value instanceof \MongoDB\BSON\UTCDateTime
                ? \DateTimeImmutable::createFromMutable(
                    $value->toDateTime()->setTimezone(new \DateTimeZone('UTC')),
                )
                : ($value instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($value)
                    : null),

            FieldType::Bool => (bool) $value,

            FieldType::Int => (int) $value,

            FieldType::Float,
            FieldType::Decimal128 => (float) $value,

            default => $value,
        };
    }

    private function convertToMongo(mixed $value, FieldDefinition $def): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($def->getType()) {
            FieldType::ObjectId => is_string($value) && strlen($value) === 24
                ? new \MongoDB\BSON\ObjectId($value)
                : $value,

            FieldType::Date => $value instanceof \DateTimeInterface
                ? new \MongoDB\BSON\UTCDateTime($value->getTimestamp() * 1000)
                : $value,

            FieldType::Decimal128 => new \MongoDB\BSON\Decimal128((string) $value),

            default => $value,
        };
    }
}
