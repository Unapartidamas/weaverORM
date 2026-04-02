<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\Security\RowLevelSecurity;

final class RowLevelSecurityTest extends TestCase
{
    public function test_protect_generates_protect_sql(): void
    {
        self::assertSame(
            "PROTECT orders WHERE tenant_id = current_tenant()",
            RowLevelSecurity::protect('orders', 'tenant_id = current_tenant()'),
        );
    }

    public function test_enableRls_generates_alter_table(): void
    {
        self::assertSame(
            'ALTER TABLE documents ENABLE ROW LEVEL SECURITY',
            RowLevelSecurity::enableRls('documents'),
        );
    }

    public function test_disableRls_generates_alter_table(): void
    {
        self::assertSame(
            'ALTER TABLE documents DISABLE ROW LEVEL SECURITY',
            RowLevelSecurity::disableRls('documents'),
        );
    }

    public function test_createPolicy_generates_create_policy(): void
    {
        $sql = RowLevelSecurity::createPolicy(
            'tenant_isolation',
            'documents',
            "tenant_id = current_setting('app.tenant')::INT",
        );

        self::assertSame(
            "CREATE POLICY tenant_isolation ON documents USING (tenant_id = current_setting('app.tenant')::INT)",
            $sql,
        );
    }

    public function test_createPolicy_with_check_clause(): void
    {
        $sql = RowLevelSecurity::createPolicy(
            'write_own',
            'documents',
            'owner_id = current_user_id()',
            'owner_id = current_user_id()',
        );

        self::assertStringContainsString('WITH CHECK (owner_id = current_user_id())', $sql);
    }

    public function test_dropPolicy_generates_drop_policy(): void
    {
        self::assertSame(
            'DROP POLICY tenant_isolation ON documents',
            RowLevelSecurity::dropPolicy('tenant_isolation', 'documents'),
        );
    }
}
