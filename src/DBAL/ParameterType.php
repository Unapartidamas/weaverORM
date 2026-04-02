<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL;

use PDO;

enum ParameterType: int
{
    case STRING = PDO::PARAM_STR;
    case INTEGER = PDO::PARAM_INT;
    case BOOLEAN = PDO::PARAM_BOOL;
    case NULL = PDO::PARAM_NULL;
    case BINARY = PDO::PARAM_LOB;
}
