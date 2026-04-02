<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB;

use Weaver\ORM\MongoDB\Exception\DocumentMapperNotFoundException;
use Weaver\ORM\MongoDB\Mapping\AbstractDocumentMapper;

final class DocumentMapperRegistry
{

    private array $mappers = [];

    public function register(AbstractDocumentMapper $mapper): void
    {
        $this->mappers[$mapper->getDocumentClass()] = $mapper;
    }

    public function get(string $documentClass): AbstractDocumentMapper
    {
        if (!isset($this->mappers[$documentClass])) {
            throw DocumentMapperNotFoundException::forDocument($documentClass);
        }

        return $this->mappers[$documentClass];
    }

    public function has(string $documentClass): bool
    {
        return isset($this->mappers[$documentClass]);
    }

    public function all(): array
    {
        return $this->mappers;
    }

    public function getByCollection(string $collection): AbstractDocumentMapper
    {
        foreach ($this->mappers as $mapper) {
            if ($mapper->getCollectionName() === $collection) {
                return $mapper;
            }
        }

        throw DocumentMapperNotFoundException::forCollection($collection);
    }
}
