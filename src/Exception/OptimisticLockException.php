<?php

declare(strict_types=1);

namespace Weaver\ORM\Exception;

final class OptimisticLockException extends \RuntimeException
{

    public static function lockFailed(string $entityClass, mixed $id, int $expectedVersion): self
    {
        $idStr = is_scalar($id) ? (string) $id : '[non-scalar]';

        return new self("Optimistic lock failed for {$entityClass}#{$idStr}: expected version {$expectedVersion}.");
    }
}
