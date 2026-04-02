<?php

declare(strict_types=1);

namespace Weaver\ORM\Testing;

final class Faker
{
    private static array $words = [
        'apple', 'banana', 'cherry', 'delta', 'echo', 'foxtrot', 'golf',
        'hotel', 'india', 'juliet', 'kilo', 'lima', 'mango', 'november',
        'oscar', 'papa', 'quebec', 'romeo', 'sierra', 'tango', 'uniform',
        'victor', 'whiskey', 'xray', 'yankee', 'zulu', 'amber', 'bronze',
        'coral', 'dawn', 'ember', 'fawn', 'grace', 'haze', 'ivory', 'jade',
    ];

    public static function name(): string
    {
        return 'User_' . substr(bin2hex(random_bytes(4)), 0, 6);
    }

    public static function email(): string
    {
        return 'user_' . substr(bin2hex(random_bytes(4)), 0, 6) . '@example.com';
    }

    public static function word(): string
    {
        return self::$words[array_rand(self::$words)];
    }

    public static function sentence(): string
    {
        $count = random_int(5, 10);
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = self::word();
        }
        return ucfirst(implode(' ', $words)) . '.';
    }

    public static function integer(int $min = 0, int $max = 100): int
    {
        return random_int($min, $max);
    }

    public static function boolean(): bool
    {
        return (bool) random_int(0, 1);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);

        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function date(): string
    {
        $timestamp = random_int(strtotime('2000-01-01'), strtotime('2030-12-31'));
        return date('Y-m-d', $timestamp);
    }

    public static function datetime(): string
    {
        $timestamp = random_int(strtotime('2000-01-01'), strtotime('2030-12-31'));
        return date('Y-m-d H:i:s', $timestamp);
    }

    public static function oneOf(array $values): mixed
    {
        return $values[array_rand($values)];
    }
}
