<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\MongoDB;

use Weaver\ORM\MongoDB\Mapping\AbstractDocumentMapper;
use Weaver\ORM\MongoDB\Mapping\FieldDefinition;
use Weaver\ORM\MongoDB\Mapping\FieldType;

final class ArticleMapper extends AbstractDocumentMapper
{
    public function getDocumentClass(): string
    {
        return Article::class;
    }

    public function getCollectionName(): string
    {
        return 'articles';
    }

    public function getFields(): array
    {
        return [
            new FieldDefinition('_id',      'id',        FieldType::String),
            new FieldDefinition('title',     'title',     FieldType::String),
            new FieldDefinition('status',    'status',    FieldType::String),
            new FieldDefinition('views',     'views',     FieldType::Int),
            new FieldDefinition('score',     'score',     FieldType::Float),
            new FieldDefinition('published', 'published', FieldType::Bool),
            new FieldDefinition('author_id', 'authorId',  FieldType::String, nullable: true),
            new FieldDefinition('tags',      'tags',      FieldType::Array),
        ];
    }
}
