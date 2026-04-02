<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\AttributeEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\CompositeKey;

final class CompositePkEdgeCaseTest extends TestCase
{






    private function attributeMapperWith(array $columns): AttributeEntityMapper
    {
        return new AttributeEntityMapper(
            entityClass: \stdClass::class,
            tableName:   'test',
            columns:     $columns,
            relations:   [],
        );
    }



    private function abstractMapperWith(array $columns): AbstractEntityMapper
    {
        return new class($columns) extends AbstractEntityMapper {

            public function __construct(private readonly array $cols) {}

            public function getEntityClass(): string { return \stdClass::class; }
            public function getTableName(): string   { return 'test'; }


            public function getColumns(): array { return $this->cols; }
        };
    }





    public function test_three_pk_columns_isComposite_returns_true(): void
    {
        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('tenant_id', 'tenantId', 'integer', primary: true),
            new ColumnDefinition('org_id',    'orgId',    'integer', primary: true),
            new ColumnDefinition('user_id',   'userId',   'integer', primary: true),
            new ColumnDefinition('name',      'name',     'string'),
        ]);

        $this->assertTrue($mapper->isComposite());
    }

    public function test_three_pk_columns_getPrimaryKeyColumns_returns_all_three(): void
    {
        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('tenant_id', 'tenantId', 'integer', primary: true),
            new ColumnDefinition('org_id',    'orgId',    'integer', primary: true),
            new ColumnDefinition('user_id',   'userId',   'integer', primary: true),
            new ColumnDefinition('name',      'name',     'string'),
        ]);

        $columns = $mapper->getPrimaryKeyColumns();

        $this->assertCount(3, $columns);
        $this->assertContains('tenant_id', $columns);
        $this->assertContains('org_id', $columns);
        $this->assertContains('user_id', $columns);
    }

    public function test_three_pk_extractCompositeKey_captures_all_pk_values(): void
    {
        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('tenant_id', 'tenantId', 'integer', primary: true),
            new ColumnDefinition('org_id',    'orgId',    'integer', primary: true),
            new ColumnDefinition('user_id',   'userId',   'integer', primary: true),
        ]);

        $entity           = new \stdClass();
        $entity->tenantId = 10;
        $entity->orgId    = 20;
        $entity->userId   = 30;

        $key = $mapper->extractCompositeKey($entity);

        $this->assertSame(['tenant_id' => 10, 'org_id' => 20, 'user_id' => 30], $key->toArray());
    }





    public function test_compositeKey_stores_int_and_string_values(): void
    {
        $key = new CompositeKey(['tenant' => 42, 'code' => 'XYZ']);

        $this->assertSame(42,    $key['tenant']);
        $this->assertSame('XYZ', $key['code']);
    }

    public function test_compositeKey_toArray_preserves_mixed_types(): void
    {
        $key = new CompositeKey(['id' => 7, 'locale' => 'en_US']);

        $array = $key->toArray();

        $this->assertSame(7,       $array['id']);
        $this->assertSame('en_US', $array['locale']);
    }

    public function test_compositeKey_equals_same_types_and_values(): void
    {
        $a = new CompositeKey(['tenant' => 1, 'code' => 'A']);
        $b = new CompositeKey(['tenant' => 1, 'code' => 'A']);

        $this->assertTrue($a->equals($b));
    }

    public function test_compositeKey_not_equal_when_int_vs_string_value(): void
    {

        $a = new CompositeKey(['id' => 1]);
        $b = new CompositeKey(['id' => '1']);

        $this->assertFalse($a->equals($b));
    }







    public function test_compositeKey_equals_is_order_sensitive(): void
    {
        $ab = new CompositeKey(['a' => 1, 'b' => 2]);
        $ba = new CompositeKey(['b' => 2, 'a' => 1]);


        $this->assertFalse(
            $ab->equals($ba),
            'equals() must return false when key order differs (strict PHP array ===)',
        );
    }



    public function test_compositeKey_equals_true_when_order_and_values_match(): void
    {
        $x = new CompositeKey(['a' => 1, 'b' => 2]);
        $y = new CompositeKey(['a' => 1, 'b' => 2]);

        $this->assertTrue($x->equals($y));
    }





    public function test_extractCompositeKey_with_zero_integer_pk(): void
    {
        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('part_a', 'partA', 'integer', primary: true),
            new ColumnDefinition('part_b', 'partB', 'integer', primary: true),
        ]);

        $entity        = new \stdClass();
        $entity->partA = 0;
        $entity->partB = 0;

        $key = $mapper->extractCompositeKey($entity);

        $this->assertSame(0, $key['part_a']);
        $this->assertSame(0, $key['part_b']);
    }

    public function test_extractCompositeKey_with_null_pk_value(): void
    {
        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('fk_a', 'fkA', 'integer', primary: true),
            new ColumnDefinition('fk_b', 'fkB', 'integer', primary: true),
        ]);

        $entity      = new \stdClass();
        $entity->fkA = null;
        $entity->fkB = 5;

        $key = $mapper->extractCompositeKey($entity);


        $this->assertNull($key['fk_a']);
        $this->assertSame(5, $key['fk_b']);
    }

    public function test_extractCompositeKey_with_empty_string_pk(): void
    {
        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('slug', 'slug', 'string', primary: true),
            new ColumnDefinition('lang', 'lang', 'string', primary: true),
        ]);

        $entity       = new \stdClass();
        $entity->slug = '';
        $entity->lang = 'fr';

        $key = $mapper->extractCompositeKey($entity);

        $this->assertSame('',   $key['slug']);
        $this->assertSame('fr', $key['lang']);
    }






    public function test_getPrimaryKeyColumns_preserves_declaration_order(): void
    {

        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('c_col', 'cCol', 'integer', primary: true),
            new ColumnDefinition('a_col', 'aCol', 'integer', primary: true),
            new ColumnDefinition('b_col', 'bCol', 'integer', primary: true),
        ]);

        $columns = $mapper->getPrimaryKeyColumns();

        $this->assertSame(['c_col', 'a_col', 'b_col'], $columns);
    }

    public function test_getPrimaryKeyColumns_not_alphabetical(): void
    {
        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('z_id', 'zId', 'integer', primary: true),
            new ColumnDefinition('a_id', 'aId', 'integer', primary: true),
        ]);

        $columns = $mapper->getPrimaryKeyColumns();


        $this->assertSame('z_id', $columns[0]);
        $this->assertSame('a_id', $columns[1]);
    }





    public function test_single_pk_isComposite_returns_false_attribute_mapper(): void
    {
        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string'),
        ]);

        $this->assertFalse($mapper->isComposite());
    }

    public function test_single_pk_getPrimaryKeyColumns_returns_one_element_array_attribute_mapper(): void
    {
        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string'),
        ]);

        $columns = $mapper->getPrimaryKeyColumns();

        $this->assertCount(1, $columns);
        $this->assertSame('id', $columns[0]);
    }

    public function test_no_pk_marked_falls_back_to_id_in_array_attribute_mapper(): void
    {

        $mapper = $this->attributeMapperWith([
            new ColumnDefinition('name', 'name', 'string'),
        ]);

        $columns = $mapper->getPrimaryKeyColumns();

        $this->assertSame(['id'], $columns);
        $this->assertFalse($mapper->isComposite());
    }






    public function test_abstract_mapper_single_pk_isComposite_returns_false(): void
    {
        $mapper = $this->abstractMapperWith([
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string'),
        ]);

        $this->assertFalse($mapper->isComposite());
    }

    public function test_abstract_mapper_getPrimaryKeyColumns_returns_one_element(): void
    {
        $mapper = $this->abstractMapperWith([
            new ColumnDefinition('my_pk', 'myPk', 'integer', primary: true),
        ]);

        $columns = $mapper->getPrimaryKeyColumns();

        $this->assertCount(1, $columns);
        $this->assertSame('my_pk', $columns[0]);
    }



    public function test_abstract_mapper_base_does_not_scan_multiple_pk_columns(): void
    {
        $mapper = $this->abstractMapperWith([
            new ColumnDefinition('fk_a', 'fkA', 'integer', primary: true),
            new ColumnDefinition('fk_b', 'fkB', 'integer', primary: true),
        ]);


        $columns = $mapper->getPrimaryKeyColumns();


        $this->assertCount(1, $columns);
        $this->assertFalse($mapper->isComposite());
    }





    public function test_compositeKey_offsetSet_throws_LogicException(): void
    {
        $key = new CompositeKey(['a' => 1, 'b' => 2]);

        $this->expectException(\LogicException::class);

        $key['a'] = 99;
    }

    public function test_compositeKey_offsetUnset_throws_LogicException(): void
    {
        $key = new CompositeKey(['a' => 1, 'b' => 2]);

        $this->expectException(\LogicException::class);

        unset($key['a']);
    }

    public function test_compositeKey_offsetSet_new_key_throws_LogicException(): void
    {
        $key = new CompositeKey(['a' => 1]);

        $this->expectException(\LogicException::class);

        $key['new_key'] = 'value';
    }





    public function test_compositeKey_toArray_returns_exact_constructor_map(): void
    {
        $input = ['tenant_id' => 5, 'product_code' => 'WIDGET-42', 'locale' => 'de'];
        $key   = new CompositeKey($input);

        $this->assertSame($input, $key->toArray());
    }

    public function test_compositeKey_toArray_is_independent_copy(): void
    {
        $input = ['x' => 1, 'y' => 2];
        $key   = new CompositeKey($input);


        $result      = $key->toArray();
        $result['x'] = 999;

        $this->assertSame(1, $key->toArray()['x']);
    }

    public function test_compositeKey_toArray_on_empty_key(): void
    {
        $key = new CompositeKey([]);

        $this->assertSame([], $key->toArray());
    }





    public function test_compositeKey_offsetExists_true_for_existing_key(): void
    {
        $key = new CompositeKey(['a' => 1]);

        $this->assertTrue(isset($key['a']));
    }

    public function test_compositeKey_offsetExists_false_for_missing_key(): void
    {
        $key = new CompositeKey(['a' => 1]);

        $this->assertFalse(isset($key['z']));
    }

    public function test_compositeKey_offsetGet_returns_null_for_missing_key(): void
    {
        $key = new CompositeKey(['a' => 1]);

        $this->assertNull($key['missing']);
    }





    public function test_compositeKey_toString_format(): void
    {
        $key = new CompositeKey(['tenant_id' => 3, 'order_id' => 7]);

        $this->assertSame('tenant_id=3,order_id=7', (string) $key);
    }

    public function test_compositeKey_toString_empty_produces_empty_string(): void
    {
        $key = new CompositeKey([]);

        $this->assertSame('', (string) $key);
    }
}
