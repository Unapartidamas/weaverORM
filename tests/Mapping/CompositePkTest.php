<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\Id;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\CompositeKey;

#[Entity(table: 'order_items')]
class OrderItem
{
    #[Id(autoIncrement: false)]
    public int $orderId = 0;

    #[Id(autoIncrement: false)]
    public int $productId = 0;

    #[Column]
    public int $quantity = 0;
}

final class OrderItemMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return OrderItem::class;
    }

    public function getTableName(): string
    {
        return 'order_items';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('order_id',   'orderId',   'integer', primary: true,  autoIncrement: false),
            new ColumnDefinition('product_id',  'productId', 'integer', primary: true,  autoIncrement: false),
            new ColumnDefinition('quantity',    'quantity',  'integer'),
        ];
    }



    public function getPrimaryKeyColumns(): array
    {
        return ['order_id', 'product_id'];
    }
}

final class CompositePkTest extends TestCase
{
    private AttributeMapperFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AttributeMapperFactory();
    }





    public function test_is_composite_returns_true_for_composite_mapper(): void
    {
        $mapper = new OrderItemMapper();

        self::assertTrue($mapper->isComposite());
    }

    public function test_is_composite_returns_false_for_single_pk_mapper(): void
    {
        $mapper = $this->factory->build(\Weaver\ORM\Tests\Fixture\Entity\Article::class);

        self::assertFalse($mapper->isComposite());
    }





    public function test_get_primary_key_columns_returns_both_columns(): void
    {
        $mapper = new OrderItemMapper();

        self::assertSame(['order_id', 'product_id'], $mapper->getPrimaryKeyColumns());
    }

    public function test_attribute_mapper_get_primary_key_columns_returns_both_columns(): void
    {
        $mapper = $this->factory->build(OrderItem::class);

        self::assertSame(['order_id', 'product_id'], $mapper->getPrimaryKeyColumns());
    }

    public function test_attribute_mapper_is_composite_true_for_order_item(): void
    {
        $mapper = $this->factory->build(OrderItem::class);

        self::assertTrue($mapper->isComposite());
    }





    public function test_extract_composite_key_returns_correct_composite_key(): void
    {
        $mapper = new OrderItemMapper();

        $entity           = new OrderItem();
        $entity->orderId  = 7;
        $entity->productId = 42;
        $entity->quantity = 3;

        $key = $mapper->extractCompositeKey($entity);

        self::assertInstanceOf(CompositeKey::class, $key);
        self::assertSame(['order_id' => 7, 'product_id' => 42], $key->toArray());
    }





    public function test_composite_key_equals_same_values(): void
    {
        $a = new CompositeKey(['order_id' => 1, 'product_id' => 2]);
        $b = new CompositeKey(['order_id' => 1, 'product_id' => 2]);

        self::assertTrue($a->equals($b));
    }

    public function test_composite_key_not_equals_different_values(): void
    {
        $a = new CompositeKey(['order_id' => 1, 'product_id' => 2]);
        $b = new CompositeKey(['order_id' => 1, 'product_id' => 99]);

        self::assertFalse($a->equals($b));
    }

    public function test_composite_key_to_string(): void
    {
        $key = new CompositeKey(['order_id' => 1, 'product_id' => 2]);

        self::assertSame('order_id=1,product_id=2', (string) $key);
    }

    public function test_composite_key_offset_get(): void
    {
        $key = new CompositeKey(['order_id' => 1, 'product_id' => 2]);

        self::assertSame(1, $key['order_id']);
        self::assertSame(2, $key['product_id']);
        self::assertNull($key['missing']);
    }

    public function test_composite_key_offset_exists(): void
    {
        $key = new CompositeKey(['order_id' => 1]);

        self::assertTrue(isset($key['order_id']));
        self::assertFalse(isset($key['product_id']));
    }

    public function test_composite_key_offset_set_throws(): void
    {
        $this->expectException(\LogicException::class);

        $key = new CompositeKey(['order_id' => 1]);
        $key['order_id'] = 99;
    }

    public function test_composite_key_offset_unset_throws(): void
    {
        $this->expectException(\LogicException::class);

        $key = new CompositeKey(['order_id' => 1]);
        unset($key['order_id']);
    }
}
