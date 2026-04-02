<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\Vector\VectorIndex;

final class VectorIndexTest extends TestCase
{




    public function test_hnsw_creates_index_with_hnsw_type_and_default_params(): void
    {
        $index = VectorIndex::hnsw('embedding');

        self::assertSame('hnsw', $index->getType());
        self::assertSame('embedding', $index->getColumn());
        self::assertSame('cosine', $index->getDistanceOp());
    }

    public function test_hnsw_accepts_custom_m_and_ef_construction(): void
    {
        $index = VectorIndex::hnsw('embedding', 'l2', m: 32, efConstruction: 128);

        self::assertSame('hnsw', $index->getType());
        self::assertSame('l2', $index->getDistanceOp());

        $sql = $index->toSQL('articles', 'idx_test');

        self::assertStringContainsString('m=32', $sql);
        self::assertStringContainsString('ef_construction=128', $sql);
    }





    public function test_ivfflat_creates_index_with_ivfflat_type_and_correct_lists(): void
    {
        $index = VectorIndex::ivfflat('embedding', 'cosine', lists: 200);

        self::assertSame('ivfflat', $index->getType());
        self::assertSame('embedding', $index->getColumn());
        self::assertSame('cosine', $index->getDistanceOp());

        $sql = $index->toSQL('articles', 'idx_test');

        self::assertStringContainsString('USING ivfflat', $sql);
        self::assertStringContainsString('lists=200', $sql);
    }





    public function test_to_sql_generates_create_index_using_hnsw_correctly(): void
    {
        $index = VectorIndex::hnsw('embedding', 'cosine', m: 16, efConstruction: 64);

        $sql = $index->toSQL('articles', 'idx_articles_embedding_hnsw');

        self::assertSame(
            'CREATE INDEX idx_articles_embedding_hnsw ON articles USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64)',
            $sql
        );
    }

    public function test_to_sql_with_null_index_name_generates_automatic_name(): void
    {
        $index = VectorIndex::hnsw('embedding', 'cosine');

        $sql = $index->toSQL('articles');

        self::assertStringStartsWith('CREATE INDEX idx_articles_embedding_hnsw ON articles', $sql);
    }

    public function test_to_sql_includes_with_params_m_and_ef_construction(): void
    {
        $index = VectorIndex::hnsw('embedding', 'cosine', m: 24, efConstruction: 96);

        $sql = $index->toSQL('documents', 'idx_documents_embedding_hnsw');

        self::assertStringContainsString('WITH (m=24, ef_construction=96)', $sql);
    }

    public function test_to_sql_ivfflat_full_statement(): void
    {
        $index = VectorIndex::ivfflat('embedding', 'l2', lists: 100);

        $sql = $index->toSQL('items', 'idx_items_embedding_ivfflat');

        self::assertSame(
            'CREATE INDEX idx_items_embedding_ivfflat ON items USING ivfflat (embedding vector_l2_ops) WITH (lists=100)',
            $sql
        );
    }





    public function test_unknown_distance_op_in_hnsw_falls_back_to_cosine_ops(): void
    {


        $index = VectorIndex::hnsw('embedding', 'jaccard');

        $sql = $index->toSQL('articles', 'idx_test');

        self::assertStringContainsString('vector_cosine_ops', $sql);
    }

    public function test_hnsw_dot_distance_op_maps_to_vector_ip_ops(): void
    {
        $index = VectorIndex::hnsw('embedding', 'dot');

        $sql = $index->toSQL('articles', 'idx_test');

        self::assertStringContainsString('vector_ip_ops', $sql);
    }

    public function test_hnsw_l2_distance_op_maps_to_vector_l2_ops(): void
    {
        $index = VectorIndex::hnsw('embedding', 'l2');

        $sql = $index->toSQL('articles', 'idx_test');

        self::assertStringContainsString('vector_l2_ops', $sql);
    }





    public function test_create_hnsw_executes_correct_create_index_sql(): void
    {


        $index = VectorIndex::hnsw('embedding', 'cosine');

        $sql = $index->toSQL('documents', 'idx_documents_embedding_hnsw');

        self::assertStringStartsWith('CREATE INDEX', $sql);
        self::assertStringContainsString('USING hnsw', $sql);
        self::assertStringContainsString('embedding', $sql);
        self::assertStringContainsString('vector_cosine_ops', $sql);
    }

    public function test_create_ivfflat_executes_correct_create_index_sql(): void
    {
        $index = VectorIndex::ivfflat('embedding', 'cosine');

        $sql = $index->toSQL('documents', 'idx_documents_embedding_ivfflat');

        self::assertStringStartsWith('CREATE INDEX', $sql);
        self::assertStringContainsString('USING ivfflat', $sql);
        self::assertStringContainsString('vector_cosine_ops', $sql);
    }







    public function test_drop_index_sql_uses_same_name_as_create(): void
    {
        $indexName = 'idx_articles_embedding_hnsw';
        $index     = VectorIndex::hnsw('embedding');
        $createSql = $index->toSQL('articles', $indexName);


        self::assertStringContainsString($indexName, $createSql);

        $dropSql = 'DROP INDEX ' . $indexName;
        self::assertSame('DROP INDEX idx_articles_embedding_hnsw', $dropSql);
    }

    public function test_drop_if_exists_index_sql_format(): void
    {
        $indexName  = 'idx_articles_embedding_hnsw';
        $dropIfExists = 'DROP INDEX IF EXISTS ' . $indexName;

        self::assertSame('DROP INDEX IF EXISTS idx_articles_embedding_hnsw', $dropIfExists);
    }





    public function test_get_type_and_column_serve_as_index_identity_for_list(): void
    {
        $index = VectorIndex::hnsw('embedding', 'cosine');


        self::assertSame('hnsw', $index->getType());
        self::assertSame('embedding', $index->getColumn());
    }

    public function test_get_distance_op_enables_exists_check(): void
    {
        $index = VectorIndex::ivfflat('embedding', 'l2');


        self::assertSame('l2', $index->getDistanceOp());
    }
}
