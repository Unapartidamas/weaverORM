<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Exception;

final class HistoryExpiredException extends \RuntimeException
{
    public static function forTimestamp(\DateTimeImmutable $ts): self
    {
        return new self(
            "Time-travel query for timestamp {$ts->format('Y-m-d H:i:s')} failed: "
            . "history has been garbage-collected. Increase history_retention_days in pyrosql.toml."
        );
    }
}
