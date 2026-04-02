<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\EmbeddedDefinition;

final class EmbeddedDefinitionTest extends TestCase
{
    public function test_stores_property_and_class(): void
    {
        $def = new EmbeddedDefinition(
            property:        'price',
            embeddableClass: 'Weaver\\ORM\\Tests\\Fixture\\Embeddable\\Money',
            prefix:          'price_',
            columns:         [],
        );

        self::assertSame('price', $def->property);
        self::assertSame('Weaver\\ORM\\Tests\\Fixture\\Embeddable\\Money', $def->embeddableClass);
        self::assertSame('price_', $def->prefix);
    }

    public function test_columns_have_prefixed_names(): void
    {
        $amountCol   = new ColumnDefinition('price_amount',   'amount',   'integer');
        $currencyCol = new ColumnDefinition('price_currency', 'currency', 'string', length: 3);

        $def = new EmbeddedDefinition(
            property:        'price',
            embeddableClass: 'Weaver\\ORM\\Tests\\Fixture\\Embeddable\\Money',
            prefix:          'price_',
            columns:         [$amountCol, $currencyCol],
        );

        self::assertCount(2, $def->columns);
        self::assertSame('price_amount', $def->columns[0]->getColumn());
        self::assertSame('price_currency', $def->columns[1]->getColumn());
        self::assertSame('amount', $def->columns[0]->getProperty());
        self::assertSame('currency', $def->columns[1]->getProperty());
    }
}
