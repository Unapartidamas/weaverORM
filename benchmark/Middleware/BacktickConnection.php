<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Middleware;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/**
 * Connection wrapper that makes Weaver's SQLite-oriented SQL compatible with
 * PostgreSQL transparently, so that benchmark scenarios can run unmodified.
 *
 * Two rewrites are applied to every SQL string before it is forwarded to the
 * underlying driver:
 *
 * 1. Backtick → double-quote identifier quoting.
 *    Weaver's UnitOfWork emits MySQL/SQLite-style `column` quoting.
 *    PostgreSQL requires the ANSI standard "column" quoting.
 *
 * 2. SQLite CREATE TABLE DDL → PostgreSQL DDL.
 *    The benchmark scenario setup() methods contain SQLite-specific DDL
 *    (INTEGER PRIMARY KEY AUTOINCREMENT, TEXT columns). These are rewritten
 *    to PostgreSQL-compatible equivalents (SERIAL PRIMARY KEY, VARCHAR/TEXT).
 */
final class BacktickConnection extends AbstractConnectionMiddleware
{
    /**
     * Applies all necessary SQL rewrites for PostgreSQL compatibility.
     */
    private static function rewrite(string $sql): string
    {
        // 1. Backtick-quoted identifiers → double-quoted identifiers.
        $sql = preg_replace('/`([^`]+)`/', '"$1"', $sql) ?? $sql;

        // 2. Rewrite bench_users CREATE TABLE (SQLite → PostgreSQL DDL).
        //    Matches the full CREATE TABLE IF NOT EXISTS bench_users block that
        //    the scenario setup() methods emit.
        $sql = preg_replace(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+bench_users\s*\(.*?\)/si',
            "CREATE TABLE IF NOT EXISTS bench_users (\n"
            . "    id     SERIAL       PRIMARY KEY,\n"
            . "    name   VARCHAR(255) NOT NULL DEFAULT '',\n"
            . "    email  VARCHAR(255) NOT NULL DEFAULT '',\n"
            . "    age    INTEGER      NOT NULL DEFAULT 0,\n"
            . "    status VARCHAR(50)  NOT NULL DEFAULT 'active'\n"
            . ')',
            $sql,
        ) ?? $sql;

        // 3. Rewrite bench_posts CREATE TABLE (SQLite → PostgreSQL DDL).
        $sql = preg_replace(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+bench_posts\s*\(.*?\)/si',
            "CREATE TABLE IF NOT EXISTS bench_posts (\n"
            . "    id      SERIAL       PRIMARY KEY,\n"
            . "    user_id INTEGER      NOT NULL DEFAULT 0,\n"
            . "    title   VARCHAR(255) NOT NULL DEFAULT '',\n"
            . "    body    TEXT         NOT NULL DEFAULT ''\n"
            . ')',
            $sql,
        ) ?? $sql;

        return $sql;
    }

    public function prepare(string $sql): Statement
    {
        return parent::prepare(self::rewrite($sql));
    }

    public function query(string $sql): Result
    {
        return parent::query(self::rewrite($sql));
    }

    public function exec(string $sql): int|string
    {
        return parent::exec(self::rewrite($sql));
    }
}
