<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB;

final class MongoConnectionFactory
{

    public static function create(array $config): \MongoDB\Database
    {
        if (empty($config['uri'])) {
            throw new \InvalidArgumentException(
                'MongoConnectionFactory::create() requires a non-empty "uri" key in the config array.',
            );
        }

        if (empty($config['database'])) {
            throw new \InvalidArgumentException(
                'MongoConnectionFactory::create() requires a non-empty "database" key in the config array.',
            );
        }

        $uri           = (string) $config['uri'];
        $database      = (string) $config['database'];
        $options       = (array) ($config['options'] ?? []);
        $driverOptions = (array) ($config['driverOptions'] ?? []);

        $client = new \MongoDB\Client($uri, $options, $driverOptions);

        return $client->selectDatabase($database);
    }

    public static function collection(\MongoDB\Database $db, string $name): \MongoDB\Collection
    {
        return $db->selectCollection($name);
    }
}
