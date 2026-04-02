<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

enum RelationType: string
{

    case HasOne = 'has_one';

    case HasMany = 'has_many';

    case BelongsTo = 'belongs_to';

    case BelongsToMany = 'belongs_to_many';

    case MorphOne = 'morph_one';

    case MorphMany = 'morph_many';

    case HasOneThrough = 'has_one_through';

    case HasManyThrough = 'has_many_through';
}
