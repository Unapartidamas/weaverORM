<?php

declare(strict_types=1);

namespace Weaver\ORM\Exception;

final class RelationNotFoundException extends \RuntimeException
{

    public function __construct(string $entityClass, string $relation)
    {
        parent::__construct(
            sprintf(
                "Relation '%s' not found on '%s'. "
                . "Make sure the relation is declared in the mapper's getRelations() method.",
                $relation,
                $entityClass,
            )
        );
    }
}
