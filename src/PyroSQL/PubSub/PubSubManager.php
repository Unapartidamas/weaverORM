<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\PubSub;

use Weaver\ORM\DBAL\Connection;

final class PubSubManager
{
    private array $subscribedChannels = [];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function publish(string $channel, string $payload): void
    {
        $this->connection->executeStatement(
            'NOTIFY ' . $this->connection->quoteIdentifier($channel) . ', ' . $this->connection->quote($payload)
        );
    }

    public function subscribe(string $channel): void
    {
        $this->connection->executeStatement('LISTEN ' . $this->connection->quoteIdentifier($channel));
        $this->subscribedChannels[$channel] = true;
    }

    public function unsubscribe(string $channel): void
    {
        $this->connection->executeStatement('UNLISTEN ' . $this->connection->quoteIdentifier($channel));
        unset($this->subscribedChannels[$channel]);
    }

    public function unsubscribeAll(): void
    {
        $this->connection->executeStatement('UNLISTEN *');
        $this->subscribedChannels = [];
    }

    public function poll(float $timeoutSeconds = 1.0): ?array
    {
        $pdo = $this->connection->getNativeConnection();
        $result = $pdo->pgsqlGetNotify(\PDO::FETCH_ASSOC, (int) ($timeoutSeconds * 1000));

        if ($result === false) {
            return null;
        }

        return [
            'channel' => $result['message'] ?? '',
            'payload' => $result['payload'] ?? '',
        ];
    }

    public function getSubscribedChannels(): array
    {
        return array_keys($this->subscribedChannels);
    }
}
