<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB\Exception;

final class DocumentNotFoundException extends \RuntimeException
{

    public static function forId(string $documentClass, string $id): self
    {
        return new self(
            sprintf(
                'No document of class "%s" found with id "%s".',
                $documentClass,
                $id,
            ),
        );
    }

    public static function noResults(string $documentClass): self
    {
        return new self(
            sprintf(
                'No document of class "%s" matched the given query.',
                $documentClass,
            ),
        );
    }
}
