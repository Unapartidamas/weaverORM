<?php

declare(strict_types=1);

namespace Weaver\ORM\Schema;

enum SchemaIssueType: string
{
    case MissingTable        = 'missing_table';
    case MissingColumn       = 'missing_column';
    case TypeMismatch        = 'type_mismatch';
    case NullabilityMismatch = 'nullability_mismatch';
}
