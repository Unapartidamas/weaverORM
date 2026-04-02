<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB;

use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\MongoDB\Exception\DocumentNotFoundException;
use Weaver\ORM\MongoDB\Mapping\AbstractDocumentMapper;

abstract class AbstractDocumentRepository
{

    protected string $documentClass;

    public function __construct(
        private readonly \MongoDB\Collection $collection,
        private readonly AbstractDocumentMapper $mapper,
        private readonly DocumentPersistence $persistence,
    ) {}

    public function find(string $id): ?object
    {
        $filter = ['_id' => $this->buildObjectId($id)];
        $doc    = $this->collection->findOne($filter);

        if ($doc === null) {
            return null;
        }

        return $this->mapper->hydrate((array) $doc);
    }

    public function findOrFail(string $id): object
    {
        $document = $this->find($id);

        if ($document === null) {
            throw DocumentNotFoundException::forId($this->documentClass, $id);
        }

        return $document;
    }

    public function findBy(array $criteria, ?int $limit = null): EntityCollection
    {
        $options = [];

        if ($limit !== null) {
            $options['limit'] = $limit;
        }

        $cursor   = $this->collection->find($criteria, $options);
        $entities = [];

        foreach ($cursor as $doc) {
            $entities[] = $this->mapper->hydrate((array) $doc);
        }

        return new EntityCollection($entities);
    }

    public function findOneBy(array $criteria): ?object
    {
        $doc = $this->collection->findOne($criteria);

        if ($doc === null) {
            return null;
        }

        return $this->mapper->hydrate((array) $doc);
    }

    public function save(object $document): void
    {
        $this->persistence->save($document);
    }

    public function delete(object $document): void
    {
        $this->persistence->delete($document);
    }

    public function query(): DocumentQueryBuilder
    {
        return new DocumentQueryBuilder($this->collection, $this->mapper);
    }

    public function count(array $criteria = []): int
    {
        return (int) $this->collection->countDocuments($criteria);
    }

    public function exists(array $criteria): bool
    {
        return $this->count($criteria) > 0;
    }

    protected function getCollection(): \MongoDB\Collection
    {
        return $this->collection;
    }

    protected function getMapper(): AbstractDocumentMapper
    {
        return $this->mapper;
    }

    private function buildObjectId(string $id): mixed
    {
        if (strlen($id) === 24 && ctype_xdigit($id)) {
            return new \MongoDB\BSON\ObjectId($id);
        }

        return $id;
    }
}
