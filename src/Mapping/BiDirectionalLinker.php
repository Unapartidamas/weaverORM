<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

final class BiDirectionalLinker
{

    public static function linkCollection(object $owner, iterable $related, string $backProperty): void
    {
        foreach ($related as $item) {
            if (property_exists($item, $backProperty)) {
                $item->$backProperty = $owner;
            }
        }
    }

    public static function linkSingle(object $owner, ?object $related, string $backProperty): void
    {
        if ($related === null) {
            return;
        }
        if (!property_exists($related, $backProperty)) {
            return;
        }
        $current = $related->$backProperty;
        if (is_array($current)) {

            foreach ($current as $item) {
                if ($item === $owner) {
                    return;
                }
            }
            $related->$backProperty = [...$current, $owner];
        } else {

            $related->$backProperty = $owner;
        }
    }
}
