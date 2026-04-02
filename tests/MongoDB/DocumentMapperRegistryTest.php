<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\MongoDB;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\MongoDB\DocumentMapperRegistry;
use Weaver\ORM\MongoDB\Exception\DocumentMapperNotFoundException;
use Weaver\ORM\Tests\Fixture\MongoDB\Article;
use Weaver\ORM\Tests\Fixture\MongoDB\ArticleMapper;

final class DocumentMapperRegistryTest extends TestCase
{
    private DocumentMapperRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new DocumentMapperRegistry();
    }

    public function test_has_returns_false_when_not_registered(): void
    {
        $this->assertFalse($this->registry->has(Article::class));
    }

    public function test_has_returns_true_after_register(): void
    {
        $this->registry->register(new ArticleMapper());

        $this->assertTrue($this->registry->has(Article::class));
    }

    public function test_get_returns_correct_mapper(): void
    {
        $mapper = new ArticleMapper();
        $this->registry->register($mapper);

        $this->assertInstanceOf(ArticleMapper::class, $this->registry->get(Article::class));
    }

    public function test_get_throws_document_mapper_not_found_exception(): void
    {
        $this->expectException(DocumentMapperNotFoundException::class);

        $this->registry->get(Article::class);
    }

    public function test_exception_message_contains_class_name(): void
    {
        try {
            $this->registry->get(Article::class);
            $this->fail('Expected DocumentMapperNotFoundException');
        } catch (DocumentMapperNotFoundException $e) {
            $this->assertStringContainsString(Article::class, $e->getMessage());
        }
    }

    public function test_all_returns_empty_when_nothing_registered(): void
    {
        $this->assertSame([], $this->registry->all());
    }

    public function test_all_returns_all_mappers(): void
    {
        $this->registry->register(new ArticleMapper());

        $all = $this->registry->all();

        $this->assertCount(1, $all);
        $this->assertArrayHasKey(Article::class, $all);
    }

    public function test_register_overwrites_existing_mapper(): void
    {
        $this->registry->register(new ArticleMapper());
        $replacement = new ArticleMapper();
        $this->registry->register($replacement);

        $this->assertSame($replacement, $this->registry->get(Article::class));
    }

    public function test_get_by_collection_returns_mapper(): void
    {
        $this->registry->register(new ArticleMapper());

        $mapper = $this->registry->getByCollection('articles');

        $this->assertInstanceOf(ArticleMapper::class, $mapper);
    }

    public function test_get_by_collection_throws_when_not_found(): void
    {
        $this->expectException(DocumentMapperNotFoundException::class);

        $this->registry->getByCollection('articles');
    }

    public function test_exception_message_contains_collection_name(): void
    {
        try {
            $this->registry->getByCollection('articles');
            $this->fail('Expected DocumentMapperNotFoundException');
        } catch (DocumentMapperNotFoundException $e) {
            $this->assertStringContainsString('articles', $e->getMessage());
        }
    }
}
