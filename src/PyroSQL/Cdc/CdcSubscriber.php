<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Cdc;

use Weaver\ORM\PyroSQL\PyroSqlDriver;

final readonly class CdcSubscriber
{
    public function __construct(
        private \Weaver\ORM\DBAL\Connection $connection,
        private PyroSqlDriver $driver,
    ) {}

    public function subscribe(string $table, ?string $from = 'latest'): \Generator
    {
        $this->driver->assertSupports('cdc');

        $sql = "SUBSCRIBE TO CHANGES ON " . $this->connection->quoteIdentifier($table);

        if ($from !== null) {
            $sql .= " FROM " . ($from === 'latest' ? 'latest' : (string) (int) $from);
        }

        $result = $this->connection->query($sql);

        while ($row = $result->fetchAssociative()) {
            yield $this->hydrateEvent($row);
        }
    }

    public function subscribeMany(array $tables, ?string $from = 'latest'): \Generator
    {
        $this->driver->assertSupports('cdc');

        $tableList = implode(', ', array_map(
            fn (string $t): string => $this->connection->quoteIdentifier($t),
            $tables,
        ));
        $sql = "SUBSCRIBE TO CHANGES ON {$tableList}";

        if ($from !== null) {
            $sql .= " FROM " . ($from === 'latest' ? 'latest' : (string) (int) $from);
        }

        $result = $this->connection->query($sql);

        while ($row = $result->fetchAssociative()) {
            yield $this->hydrateEvent($row);
        }
    }

    private function hydrateEvent(array $row): CdcEvent
    {
        $before = [];
        $after  = [];

        if (isset($row['before']) && $row['before'] !== null) {
            $decoded = json_decode((string) $row['before'], true, 512, \JSON_THROW_ON_ERROR);
            $before  = is_array($decoded) ? $decoded : [];
        }

        if (isset($row['after']) && $row['after'] !== null) {
            $decoded = json_decode((string) $row['after'], true, 512, \JSON_THROW_ON_ERROR);
            $after   = is_array($decoded) ? $decoded : [];
        }

        $committedAt = isset($row['committed_at'])
            ? new \DateTimeImmutable((string) $row['committed_at'])
            : new \DateTimeImmutable();

        return new CdcEvent(
            operation:     strtoupper((string) ($row['operation']      ?? 'INSERT')),
            table:         (string) ($row['table_name']                ?? ''),
            before:        $before,
            after:         $after,
            lsn:           (int)    ($row['lsn']                       ?? 0),
            transactionId: (string) ($row['transaction_id']            ?? ''),
            timestamp:     $committedAt,
        );
    }
}
