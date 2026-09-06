<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;

/**
 * Regression coverage for the category-scoped wildcard bug in
 * Role::normalizedPermissions(): a permissions array like
 * ['customers' => ['*']] must only expand to that category's own
 * permission keys, never leak into a bare global '*' that grants
 * every permission in every other category.
 */
class RoleCategoryWildcardPermissionTest extends TestCase
{
    public function test_tenant_owner_string_wildcard_still_grants_everything(): void
    {
        $role = new Role(['permissions' => '*']);

        $this->assertTrue($role->hasPermission('manage_system_settings'));
        $this->assertTrue($role->hasPermission('delete_customers'));
        $this->assertTrue($role->hasPermission('some_future_permission_not_yet_defined'));
        $this->assertTrue($role->hasAnyPermission(['manage_modules']));
        $this->assertTrue($role->hasAllPermissions(['manage_modules', 'delete_customers']));
    }

    public function test_finance_role_category_wildcards_stay_scoped_to_their_own_categories(): void
    {
        $permissions = config('prodelya_permissions.default_roles.finance.permissions');
        $role = new Role(['permissions' => $permissions]);

        // financial / costs / reports / accounting are wildcarded in config for
        // "finance" -> the role must gain every real key from those categories.
        $this->assertTrue($role->hasPermission('view_order_finance_summary'));
        $this->assertTrue($role->hasPermission('cancel_current_account_transactions'));
        $this->assertTrue($role->hasPermission('approve_actual_costs'));
        $this->assertTrue($role->hasPermission('view_advanced_reports'));
        $this->assertTrue($role->hasPermission('manage_accounting_export'));

        // "customers" is explicitly limited to view_customers only (no wildcard).
        $this->assertTrue($role->hasPermission('view_customers'));
        $this->assertFalse($role->hasPermission('create_customers'));
        $this->assertFalse($role->hasPermission('delete_customers'));

        // The bug under test: a wildcard in financial/costs/reports/accounting must
        // NOT leak into the completely separate "management" category.
        $this->assertFalse($role->hasPermission('manage_users'));
        $this->assertFalse($role->hasPermission('manage_roles'));
        $this->assertFalse($role->hasPermission('manage_tenant_settings'));
        $this->assertFalse($role->hasPermission('manage_modules'));
        $this->assertFalse($role->hasPermission('manage_system_settings'));
        $this->assertFalse($role->hasAnyPermission(['manage_users', 'manage_modules']));
    }

    public function test_admin_role_category_wildcards_stay_scoped_to_their_own_categories(): void
    {
        $permissions = config('prodelya_permissions.default_roles.admin.permissions');
        $role = new Role(['permissions' => $permissions]);

        // financial / orders / customers / products / suppliers / reports /
        // accounting / notifications are wildcarded for "admin" -> full access there.
        $this->assertTrue($role->hasPermission('manage_payments'));
        $this->assertTrue($role->hasPermission('create_customers'));
        $this->assertTrue($role->hasPermission('delete_customers'));
        $this->assertTrue($role->hasPermission('manage_advanced_catalog'));
        $this->assertTrue($role->hasPermission('manage_supplier_feeds'));
        $this->assertTrue($role->hasPermission('manage_notification_settings'));

        // "management" is explicitly limited to manage_users + manage_roles only.
        $this->assertTrue($role->hasPermission('manage_users'));
        $this->assertTrue($role->hasPermission('manage_roles'));
        $this->assertFalse($role->hasPermission('manage_tenant_settings'));
        $this->assertFalse($role->hasPermission('manage_modules'));

        // "system" is explicitly limited to view_audit_logs + view_system_status only.
        $this->assertTrue($role->hasPermission('view_audit_logs'));
        $this->assertTrue($role->hasPermission('view_system_status'));
        $this->assertFalse($role->hasPermission('manage_system_settings'));
        $this->assertFalse($role->hasAnyPermission(['manage_system_settings', 'manage_modules', 'manage_tenant_settings']));
    }

    public function test_non_wildcard_role_permissions_are_unaffected(): void
    {
        $permissions = config('prodelya_permissions.default_roles.sales.permissions');
        $role = new Role(['permissions' => $permissions]);

        $this->assertTrue($role->hasPermission('create_quotes'));
        $this->assertTrue($role->hasPermission('edit_quotes'));
        $this->assertTrue($role->hasPermission('view_customers'));
        $this->assertTrue($role->hasPermission('create_customers'));
        $this->assertTrue($role->hasPermission('view_products'));
        $this->assertTrue($role->hasPermission('view_basic_reports'));
        $this->assertTrue($role->hasPermission('export_reports'));

        $this->assertFalse($role->hasPermission('delete_customers'));
        $this->assertFalse($role->hasPermission('manage_users'));
        $this->assertFalse($role->hasPermission('delete_orders'));
        $this->assertFalse($role->hasPermission('manage_advanced_catalog'));
        $this->assertFalse($role->hasAnyPermission(['manage_users', 'delete_customers']));
    }

    public function test_flat_permission_list_without_category_keys_still_works(): void
    {
        // Mirrors PermissionCastDiagnosticTest's shape: a plain list of permission
        // strings with no category keys at all must keep working exactly as before.
        $role = new Role(['permissions' => ['manage_users', 'view_customers']]);

        $this->assertTrue($role->hasPermission('manage_users'));
        $this->assertTrue($role->hasPermission('view_customers'));
        $this->assertFalse($role->hasPermission('manage_modules'));
        $this->assertFalse($role->hasPermission('delete_customers'));
    }

    public function test_unknown_category_name_with_wildcard_grants_nothing_rather_than_leaking_global_access(): void
    {
        // A typo'd / unknown category key paired with a wildcard must fail closed:
        // it must never be interpreted as a global '*'.
        $role = new Role(['permissions' => ['not_a_real_category' => ['*']]]);

        $this->assertFalse($role->hasPermission('manage_users'));
        $this->assertFalse($role->hasPermission('delete_customers'));
        $this->assertFalse($role->hasPermission('anything_at_all'));
    }
}
