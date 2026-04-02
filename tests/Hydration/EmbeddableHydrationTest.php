<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Hydration;

use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Schema\SchemaGenerator;
use Weaver\ORM\Tests\Fixture\Embeddable\Money;
use Weaver\ORM\Tests\Fixture\Embeddable\ProductOrder;
use Weaver\ORM\Tests\Fixture\WeaverIntegrationTestCase;

final class EmbeddableHydrationTest extends WeaverIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();


        $productOrderMapper = (new AttributeMapperFactory())->build(ProductOrder::class);
        $this->registry->register($productOrderMapper);


        $generator = new SchemaGenerator($this->registry, $this->connection->getDatabasePlatform());
        $tableSql  = $generator->generateForMapper($productOrderMapper);
        $this->connection->executeStatement($tableSql);


        $this->connection->executeStatement(
            "INSERT INTO product_orders (id, name, price_amount, price_currency, tax_amount, tax_currency)
             VALUES (1, 'Widget', 1000, 'EUR', 200, 'EUR')"
        );
    }

    public function test_hydrate_creates_embedded_money_object(): void
    {
        $row    = $this->connection->fetchAssociative('SELECT * FROM product_orders WHERE id = 1');
        self::assertIsArray($row);

        $order = $this->hydrator->hydrate(ProductOrder::class, $row);

        self::assertInstanceOf(ProductOrder::class, $order);
        self::assertInstanceOf(Money::class, $order->price);
        self::assertInstanceOf(Money::class, $order->tax);
    }

    public function test_embedded_object_has_correct_amount_and_currency(): void
    {
        $row   = $this->connection->fetchAssociative('SELECT * FROM product_orders WHERE id = 1');
        self::assertIsArray($row);

        $order = $this->hydrator->hydrate(ProductOrder::class, $row);
        self::assertInstanceOf(ProductOrder::class, $order);

        self::assertSame(1000, $order->price->amount);
        self::assertSame('EUR', $order->price->currency);
        self::assertSame(200, $order->tax->amount);
        self::assertSame('EUR', $order->tax->currency);
    }

    public function test_two_embeddings_of_same_class_are_independent(): void
    {
        $row   = $this->connection->fetchAssociative('SELECT * FROM product_orders WHERE id = 1');
        self::assertIsArray($row);

        $order = $this->hydrator->hydrate(ProductOrder::class, $row);
        self::assertInstanceOf(ProductOrder::class, $order);

        self::assertNotSame($order->price, $order->tax);
        self::assertSame(1000, $order->price->amount);
        self::assertSame(200, $order->tax->amount);
    }

    public function test_persist_embedded_values_via_unit_of_work(): void
    {
        $order        = new ProductOrder();
        $order->id    = 0;
        $order->name  = 'Gadget';
        $order->price = new Money(2500, 'USD');
        $order->tax   = new Money(500, 'USD');

        $this->unitOfWork->add($order);
        $this->unitOfWork->push();

        $row = $this->connection->fetchAssociative(
            "SELECT * FROM product_orders WHERE name = 'Gadget'"
        );
        self::assertIsArray($row);
        self::assertSame(2500, (int) $row['price_amount']);
        self::assertSame('USD', $row['price_currency']);
        self::assertSame(500, (int) $row['tax_amount']);
        self::assertSame('USD', $row['tax_currency']);


        $reloaded = $this->hydrator->hydrate(ProductOrder::class, $row);
        self::assertInstanceOf(ProductOrder::class, $reloaded);
        self::assertSame(2500, $reloaded->price->amount);
        self::assertSame('USD', $reloaded->price->currency);
    }

    public function test_schema_includes_prefixed_columns(): void
    {
        $productOrderMapper = $this->registry->get(ProductOrder::class);
        self::assertInstanceOf(\Weaver\ORM\Mapping\AbstractEntityMapper::class, $productOrderMapper);

        $generator = new SchemaGenerator($this->registry, $this->connection->getDatabasePlatform());
        $sqls      = $generator->generateSql();
        $combined  = implode(' ', $sqls);

        self::assertStringContainsString('price_amount', $combined);
        self::assertStringContainsString('price_currency', $combined);
        self::assertStringContainsString('tax_amount', $combined);
        self::assertStringContainsString('tax_currency', $combined);
    }
}
