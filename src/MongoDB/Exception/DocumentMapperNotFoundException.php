<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB\Exception;

final class DocumentMapperNotFoundException extends \RuntimeException
{

    public static function forDocument(string $documentClass): self
    {
        return new self(
            sprintf(
                'No document mapper registered for class "%s". '
                . 'Make sure the mapper extends AbstractDocumentMapper and is registered in the DocumentMapperRegistry.',
                $documentClass,
            ),
        );
    }

    public static function forCollection(string $collection): self
    {
        return new self(
            sprintf(
                'No document mapper registered for collection "%s". '
                . 'Make sure a mapper with getCollectionName() === "%s" is registered in the DocumentMapperRegistry.',
                $collection,
                $collection,
            ),
        );
    }
}
