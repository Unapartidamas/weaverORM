<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB\Mapping;

enum FieldType: string
{
    case String    = 'string';
    case Int       = 'int';
    case Float     = 'float';
    case Bool      = 'bool';
    case ObjectId  = 'objectId';
    case Date      = 'date';
    case Array     = 'array';
    case Embedded  = 'embedded';
    case Mixed     = 'mixed';
    case Binary    = 'binary';
    case Decimal128 = 'decimal128';
}
