<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\FullText\FullTextSearch;
use Weaver\ORM\PyroSQL\FullText\TrigramSearch;

final class FullTextSearchTest extends TestCase
{
    public function test_search_generates_correct_sql(): void
    {
        self::assertSame(
            "SEARCH 'database performance' IN articles(body)",
            FullTextSearch::search('articles', 'body', 'database performance'),
        );
    }

    public function test_searchWithRank_generates_ranked_query(): void
    {
        $sql = FullTextSearch::searchWithRank('articles', 'body', 'database');

        self::assertStringContainsString('ts_rank_bm25', $sql);
        self::assertStringContainsString('ORDER BY rank DESC', $sql);
        self::assertStringContainsString("to_tsquery('database')", $sql);
        self::assertStringContainsString('FROM articles', $sql);
    }

    public function test_createIndex_generates_gin_index(): void
    {
        $sql = FullTextSearch::createIndex('articles', 'body', 'english');

        self::assertSame(
            "CREATE INDEX idx_articles_body_fts ON articles USING GIN(to_tsvector('english', body))",
            $sql,
        );
    }

    public function test_createIndex_with_multiple_columns(): void
    {
        $sql = FullTextSearch::createIndex('articles', ['title', 'body'], 'english');

        self::assertSame(
            "CREATE INDEX idx_articles_title_body_fts ON articles USING GIN(to_tsvector('english', title), to_tsvector('english', body))",
            $sql,
        );
    }

    public function test_dropIndex_generates_correct_sql(): void
    {
        self::assertSame(
            'DROP INDEX idx_articles_body_fts',
            FullTextSearch::dropIndex('idx_articles_body_fts'),
        );
    }

    public function test_trigram_similar_generates_similarity_condition(): void
    {
        self::assertSame(
            "similarity(name, 'hello') > 0.3",
            TrigramSearch::similar('name', 'hello'),
        );
    }

    public function test_trigram_similar_with_custom_threshold(): void
    {
        self::assertSame(
            "similarity(name, 'hello') > 0.5",
            TrigramSearch::similar('name', 'hello', 0.5),
        );
    }

    public function test_createTrigramIndex_generates_gin_trgm_index(): void
    {
        self::assertSame(
            'CREATE INDEX idx_users_name_trgm ON users USING GIN(name gin_trgm_ops)',
            TrigramSearch::createTrigramIndex('users', 'name'),
        );
    }
}
