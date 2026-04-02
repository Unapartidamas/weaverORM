<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB;

use Weaver\ORM\MongoDB\Mapping\AbstractDocumentMapper;

final class DocumentPersistence
{
    public function __construct(
        private readonly \MongoDB\Collection $collection,
        private readonly AbstractDocumentMapper $mapper,
    ) {}

    public function save(object $document): void
    {
        $id = $this->readId($document);

        if ($id === null || $id === '') {

            $doc    = $this->mapper->extract($document);
            $result = $this->collection->insertOne($doc);

            $insertedId = $result->getInsertedId();
            $idString   = $insertedId instanceof \MongoDB\BSON\ObjectId
                ? (string) $insertedId
                : (string) $insertedId;

            $this->mapper->setProperty($document, 'id', $idString);
        } else {

            $doc    = $this->mapper->extract($document);
            $filter = ['_id' => $this->buildObjectId($id)];

            unset($doc['_id']);

            $this->collection->replaceOne($filter, $doc, ['upsert' => true]);
        }
    }

    public function update(object $document, array $fields): void
    {
        $id = $this->readId($document);

        if ($id === null || $id === '') {
            throw new \LogicException(
                sprintf(
                    'Cannot partially update a document of class "%s" without an id.',
                    $document::class,
                ),
            );
        }

        $fullDoc = $this->mapper->extract($document);
        $setDoc  = [];

        foreach ($fields as $property) {

            $fieldDef = $this->mapper->getField($property);
            $fieldName = $fieldDef !== null ? $fieldDef->getField() : $property;

            if (array_key_exists($fieldName, $fullDoc)) {
                $setDoc[$fieldName] = $fullDoc[$fieldName];
            }
        }

        if ($setDoc === []) {
            return;
        }

        $filter = ['_id' => $this->buildObjectId($id)];
        $this->collection->updateOne($filter, ['$set' => $setDoc]);
    }

    public function delete(object $document): void
    {
        $id = $this->readId($document);

        if ($id === null || $id === '') {
            throw new \LogicException(
                sprintf(
                    'Cannot delete a document of class "%s" without an id.',
                    $document::class,
                ),
            );
        }

        $filter = ['_id' => $this->buildObjectId($id)];
        $this->collection->deleteOne($filter);
    }

    public function deleteWhere(array $filter): int
    {
        $result = $this->collection->deleteMany($filter);

        return $result->getDeletedCount() ?? 0;
    }

    public function bulkWrite(array $operations): void
    {
        if ($operations === []) {
            return;
        }

        $bulkOps = [];

        foreach ($operations as $operation) {
            $type = $operation['type'] ?? '';

            switch ($type) {
                case 'insert':
                    $doc        = $this->mapper->extract($operation['document']);
                    $bulkOps[]  = ['insertOne' => [$doc]];
                    break;

                case 'update':
                    $doc       = $this->mapper->extract($operation['document']);
                    $filter    = $operation['filter'] ?? ['_id' => $this->buildObjectId($this->readId($operation['document']) ?? '')];
                    unset($doc['_id']);
                    $bulkOps[] = ['updateOne' => [$filter, ['$set' => $doc]]];
                    break;

                case 'delete':
                    $filter    = $operation['filter'] ?? ['_id' => $this->buildObjectId($this->readId($operation['document']) ?? '')];
                    $bulkOps[] = ['deleteOne' => [$filter]];
                    break;

                default:
                    throw new \InvalidArgumentException(
                        sprintf('Unknown bulk operation type "%s". Expected insert, update, or delete.', $type),
                    );
            }
        }

        $this->collection->bulkWrite($bulkOps);
    }

    public function insertMany(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $docs = [];

        foreach ($documents as $document) {
            $docs[] = $this->mapper->extract($document);
        }

        $result     = $this->collection->insertMany($docs);
        $insertedIds = $result->getInsertedIds();

        foreach ($documents as $index => $document) {
            if (!isset($insertedIds[$index])) {
                continue;
            }

            $insertedId = $insertedIds[$index];
            $idString   = $insertedId instanceof \MongoDB\BSON\ObjectId
                ? (string) $insertedId
                : (string) $insertedId;

            $this->mapper->setProperty($document, 'id', $idString);
        }
    }

    private function readId(object $document): mixed
    {
        try {
            return $this->mapper->getProperty($document, 'id');
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildObjectId(string $id): mixed
    {
        if (strlen($id) === 24 && ctype_xdigit($id)) {
            return new \MongoDB\BSON\ObjectId($id);
        }

        return $id;
    }
}
