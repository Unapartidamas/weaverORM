<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL;

abstract class Platform
{
    abstract public function getName(): string;

    abstract public function quoteIdentifier(string $identifier): string;

    abstract public function getDateTimeFormat(): string;

    abstract public function supportsReturning(): bool;

    abstract public function supportsSavepoints(): bool;
}
