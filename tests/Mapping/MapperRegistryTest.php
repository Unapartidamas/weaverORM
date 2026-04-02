<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Exception\MapperNotFoundException;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Tests\Fixture\Entity\Post;
use Weaver\ORM\Tests\Fixture\Entity\User;
use Weaver\ORM\Tests\Fixture\Mapper\PostMapper;
use Weaver\ORM\Tests\Fixture\Mapper\UserMapper;

final class MapperRegistryTest extends TestCase
{
    private MapperRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new MapperRegistry();
        $this->registry->register(new UserMapper());
    }





    public function test_has_returns_true_for_registered_mapper(): void
    {
        $this->assertTrue($this->registry->has(User::class));
    }

    public function test_has_returns_false_for_unknown(): void
    {
        $this->assertFalse($this->registry->has(\stdClass::class));
    }





    public function test_get_returns_correct_mapper(): void
    {
        $mapper = $this->registry->get(User::class);

        $this->assertInstanceOf(UserMapper::class, $mapper);
    }

    public function test_get_throws_for_unknown_entity(): void
    {
        $this->expectException(MapperNotFoundException::class);

        $this->registry->get(\stdClass::class);
    }

    public function test_get_exception_message_contains_entity_class(): void
    {
        try {
            $this->registry->get(\stdClass::class);
            $this->fail('Expected MapperNotFoundException');
        } catch (MapperNotFoundException $e) {
            $this->assertStringContainsString(\stdClass::class, $e->getMessage());
        }
    }





    public function test_get_by_table_name(): void
    {
        $mapper = $this->registry->getByTableName('users');

        $this->assertInstanceOf(UserMapper::class, $mapper);
    }

    public function test_get_by_table_throws_for_unknown(): void
    {
        $this->expectException(MapperNotFoundException::class);

        $this->registry->getByTableName('nonexistent');
    }

    public function test_get_by_table_exception_message_contains_table_name(): void
    {
        try {
            $this->registry->getByTableName('nonexistent');
            $this->fail('Expected MapperNotFoundException');
        } catch (MapperNotFoundException $e) {
            $this->assertStringContainsString('nonexistent', $e->getMessage());
        }
    }





    public function test_all_returns_all_mappers(): void
    {
        $all = $this->registry->all();

        $this->assertCount(1, $all);
        $this->assertInstanceOf(UserMapper::class, reset($all));
    }

    public function test_all_returns_empty_array_when_no_mappers_registered(): void
    {
        $empty = new MapperRegistry();

        $this->assertSame([], $empty->all());
    }

    public function test_all_returns_all_after_multiple_registrations(): void
    {
        $this->registry->register(new PostMapper());

        $all = $this->registry->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey(User::class, $all);
        $this->assertArrayHasKey(Post::class, $all);
    }





    public function test_register_overwrites_existing_mapper(): void
    {

        $replacement = new UserMapper();
        $this->registry->register($replacement);

        $this->assertSame($replacement, $this->registry->get(User::class));
    }





    public function test_mapper_not_found_for_entity_is_runtime_exception(): void
    {
        $e = MapperNotFoundException::forEntity(\stdClass::class);

        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertStringContainsString(\stdClass::class, $e->getMessage());
    }

    public function test_mapper_not_found_for_table_is_runtime_exception(): void
    {
        $e = MapperNotFoundException::forTable('missing_table');

        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertStringContainsString('missing_table', $e->getMessage());
    }
}
