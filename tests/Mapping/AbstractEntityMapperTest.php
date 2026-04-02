<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\RelationType;
use Weaver\ORM\Tests\Fixture\Entity\Post;
use Weaver\ORM\Tests\Fixture\Entity\Profile;
use Weaver\ORM\Tests\Fixture\Entity\User;
use Weaver\ORM\Tests\Fixture\Mapper\PostMapper;
use Weaver\ORM\Tests\Fixture\Mapper\UserMapper;

final class AbstractEntityMapperTest extends TestCase
{
    private UserMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new UserMapper();
    }





    public function test_get_entity_class(): void
    {
        $this->assertSame(User::class, $this->mapper->getEntityClass());
    }

    public function test_get_table_name(): void
    {
        $this->assertSame('users', $this->mapper->getTableName());
    }





    public function test_get_primary_key_detects_pk_column(): void
    {
        $this->assertSame('id', $this->mapper->getPrimaryKey());
    }

    public function test_get_primary_key_falls_back_to_id_when_none_marked(): void
    {


        $mapper = new class extends \Weaver\ORM\Mapping\AbstractEntityMapper {
            public function getEntityClass(): string { return User::class; }
            public function getTableName(): string   { return 'x'; }
            public function getColumns(): array      { return []; }
        };

        $this->assertSame('id', $mapper->getPrimaryKey());
    }





    public function test_get_column_finds_by_property_name(): void
    {
        $col = $this->mapper->getColumn('email');

        $this->assertNotNull($col);
        $this->assertSame('email', $col->getColumn());
    }

    public function test_get_column_returns_null_for_unknown(): void
    {
        $this->assertNull($this->mapper->getColumn('nonexistent'));
    }

    public function test_get_column_finds_camel_case_property(): void
    {
        $col = $this->mapper->getColumn('createdAt');

        $this->assertNotNull($col);
        $this->assertSame('created_at', $col->getColumn());
    }





    public function test_get_column_by_name_finds_by_db_column(): void
    {
        $col = $this->mapper->getColumnByName('created_at');

        $this->assertNotNull($col);
        $this->assertSame('createdAt', $col->getProperty());
    }

    public function test_get_column_by_name_returns_null_for_unknown(): void
    {
        $this->assertNull($this->mapper->getColumnByName('nonexistent_col'));
    }

    public function test_get_column_by_name_finds_simple_column(): void
    {
        $col = $this->mapper->getColumnByName('email');

        $this->assertNotNull($col);
        $this->assertSame('email', $col->getProperty());
    }





    public function test_get_relation_finds_by_property(): void
    {
        $rel = $this->mapper->getRelation('posts');

        $this->assertNotNull($rel);
    }

    public function test_get_relation_returns_null_for_unknown(): void
    {
        $this->assertNull($this->mapper->getRelation('nonexistent'));
    }

    public function test_get_relation_type_for_posts_is_has_many(): void
    {
        $rel = $this->mapper->getRelation('posts');

        $this->assertSame(RelationType::HasMany, $rel->getType());
    }

    public function test_get_relation_type_for_profile_is_has_one(): void
    {
        $rel = $this->mapper->getRelation('profile');

        $this->assertSame(RelationType::HasOne, $rel->getType());
    }

    public function test_get_relation_related_entity_class(): void
    {
        $rel = $this->mapper->getRelation('posts');

        $this->assertSame(Post::class, $rel->getRelatedEntity());
    }

    public function test_get_relation_foreign_key(): void
    {
        $rel = $this->mapper->getRelation('posts');

        $this->assertSame('user_id', $rel->getForeignKey());
    }





    public function test_get_writable_columns_excludes_generated(): void
    {
        $cols = $this->mapper->getWritableColumns();

        foreach ($cols as $col) {
            $this->assertFalse($col->isGenerated());
            $this->assertFalse($col->isVirtual());
        }
    }

    public function test_get_writable_columns_includes_all_non_generated_non_virtual(): void
    {

        $cols = $this->mapper->getWritableColumns();

        $this->assertCount(6, $cols);
    }

    public function test_get_writable_columns_excludes_virtual_columns(): void
    {
        $mapper = new class extends \Weaver\ORM\Mapping\AbstractEntityMapper {
            public function getEntityClass(): string { return User::class; }
            public function getTableName(): string   { return 'x'; }
            public function getColumns(): array
            {
                return [
                    new \Weaver\ORM\Mapping\ColumnDefinition('id',      'id',      'integer', primary: true),
                    new \Weaver\ORM\Mapping\ColumnDefinition('virtual', 'virtual', 'string',  virtual: true),
                ];
            }
        };

        $writable = $mapper->getWritableColumns();

        $this->assertCount(1, $writable);
        $this->assertSame('id', $writable[0]->getColumn());
    }





    public function test_get_persistable_columns_excludes_auto_increment_pk(): void
    {
        $cols = $this->mapper->getPersistableColumns();

        foreach ($cols as $col) {
            $this->assertFalse($col->isPrimary() && $col->isAutoIncrement());
        }
    }

    public function test_get_persistable_columns_count(): void
    {

        $cols = $this->mapper->getPersistableColumns();

        $this->assertCount(5, $cols);
    }





    public function test_new_instance_creates_correct_entity(): void
    {
        $entity = $this->mapper->newInstance();

        $this->assertInstanceOf(User::class, $entity);
    }

    public function test_new_instance_creates_fresh_entity_each_time(): void
    {
        $a = $this->mapper->newInstance();
        $b = $this->mapper->newInstance();

        $this->assertNotSame($a, $b);
    }





    public function test_set_and_get_property(): void
    {
        $user = new User();

        $this->mapper->setProperty($user, 'name', 'Alice');

        $this->assertSame('Alice', $this->mapper->getProperty($user, 'name'));
    }

    public function test_set_property_overwrites_existing_value(): void
    {
        $user       = new User();
        $user->name = 'Bob';

        $this->mapper->setProperty($user, 'name', 'Alice');

        $this->assertSame('Alice', $user->name);
    }

    public function test_get_property_reads_current_value(): void
    {
        $user       = new User();
        $user->role = 'admin';

        $this->assertSame('admin', $this->mapper->getProperty($user, 'role'));
    }

    public function test_set_property_with_null_on_nullable(): void
    {
        $user = new User();

        $this->mapper->setProperty($user, 'createdAt', null);

        $this->assertNull($this->mapper->getProperty($user, 'createdAt'));
    }





    public function test_get_relations_returns_two_relations_for_user_mapper(): void
    {
        $rels = $this->mapper->getRelations();

        $this->assertCount(2, $rels);
    }

    public function test_post_mapper_belongs_to_user(): void
    {
        $post   = new PostMapper();
        $rel    = $post->getRelation('user');

        $this->assertNotNull($rel);
        $this->assertSame(RelationType::BelongsTo, $rel->getType());
        $this->assertSame(User::class, $rel->getRelatedEntity());
    }





    public function test_get_schema_returns_null_by_default(): void
    {
        $this->assertNull($this->mapper->getSchema());
    }





    public function test_get_indexes_returns_empty_by_default(): void
    {
        $this->assertSame([], $this->mapper->getIndexes());
    }





    public function test_get_column_returns_same_instance_on_repeated_calls(): void
    {
        $first  = $this->mapper->getColumn('email');
        $second = $this->mapper->getColumn('email');

        $this->assertSame($first, $second);
    }

    public function test_get_writable_columns_is_cached(): void
    {
        $first  = $this->mapper->getWritableColumns();
        $second = $this->mapper->getWritableColumns();

        $this->assertSame($first, $second);
    }

    public function test_get_persistable_columns_is_cached(): void
    {
        $first  = $this->mapper->getPersistableColumns();
        $second = $this->mapper->getPersistableColumns();

        $this->assertSame($first, $second);
    }
}
