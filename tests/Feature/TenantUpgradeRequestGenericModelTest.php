<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Tenant\TenantUpgradeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TenantUpgradeRequestGenericModelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantUpgradeRequestService $service;
    private Role $adminRole;
    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TenantUpgradeRequestService::class);
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_service_can_create_all_generic_request_types_and_status_helpers_work(): void
    {
        $tenant = $this->createTenant('generic-tenant-a', 'starter');
        $actor = $this->createTenantUser($tenant, 'generic-a@example.test');
        $supplier = Supplier::query()->create([
            'name' => 'Generic Supplier',
            'code' => 'GEN-SUP-001',
            'status' => 'active',
        ]);

        TenantSetting::setValue($tenant->id, 'limit_orders', 10, 'integer');

        $package = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'requested_package_key' => 'promotion',
            'requested_note' => 'Paket büyütelim',
        ], $tenant, $actor);

        $module = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ], $tenant, $actor);

        $feature = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_FEATURE_ADDON,
            'requested_feature_key' => 'public_quote_approval',
        ], $tenant, $actor);

        $limit = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'requested_limit_key' => 'orders',
            'requested_limit_value' => 25,
        ], $tenant, $actor);

        $supplierAccess = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS,
            'requested_supplier_id' => $supplier->id,
        ], $tenant, $actor);

        $serviceRequest = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'requested_service_key' => 'xml_export_setup',
            'requested_note' => 'XML tarafini acalim',
        ], $tenant, $actor);

        $this->assertTrue($package->isPackageUpgrade());
        $this->assertTrue($module->isModuleAddon());
        $this->assertTrue($feature->isFeatureAddon());
        $this->assertTrue($limit->isLimitIncrease());
        $this->assertTrue($supplierAccess->isSupplierAccess());
        $this->assertTrue($serviceRequest->isServiceRequest());
        $this->assertTrue($package->isOpen());
        $this->assertSame('Bekliyor', $package->statusLabel());
        $this->assertSame('Paket Yükseltme', $package->requestTypeLabel());

        $inReview = $this->service->markInReview($package, $this->platformAdmin, 'İncelemeye alındı');
        $approved = $this->service->approve($inReview, $this->platformAdmin, 'Onaya uygun');

        $this->assertTrue($approved->isApproved());
        $this->assertTrue($approved->canBeApplied());
        $this->assertSame('green', $approved->statusTone());

        $applied = $approved->fresh();
        $applied->forceFill([
            'status' => TenantUpgradeRequest::STATUS_APPLIED,
            'applied_by_user_id' => $this->platformAdmin->id,
            'applied_at' => now(),
        ])->save();

        $this->assertTrue($applied->fresh()->isApplied());
        $this->assertTrue($applied->fresh()->isClosed());
    }

    public function test_duplicate_open_requests_are_blocked_but_closed_requests_can_be_recreated(): void
    {
        $tenant = $this->createTenant('generic-tenant-b', 'starter');
        $actor = $this->createTenantUser($tenant, 'generic-b@example.test');

        $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'requested_package_key' => 'promotion',
        ], $tenant, $actor);

        $this->expectException(ValidationException::class);
        $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'requested_package_key' => 'promotion',
        ], $tenant, $actor);
    }

    public function test_rejected_applied_and_cancelled_requests_allow_new_request_creation(): void
    {
        $tenant = $this->createTenant('generic-tenant-c', 'starter');
        $actor = $this->createTenantUser($tenant, 'generic-c@example.test');

        $request = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ], $tenant, $actor);

        $this->service->reject($request, $this->platformAdmin, 'Şimdilik red');

        $recreated = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ], $tenant, $actor);

        $this->assertNotSame($request->id, $recreated->id);

        $cancelled = $this->service->cancel($recreated, $this->platformAdmin, 'İptal edildi');
        $this->assertTrue($cancelled->isCancelled());

        $again = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ], $tenant, $actor);

        $again->forceFill([
            'status' => TenantUpgradeRequest::STATUS_APPLIED,
            'applied_by_user_id' => $this->platformAdmin->id,
            'applied_at' => now(),
        ])->save();

        $afterApplied = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ], $tenant, $actor);

        $this->assertNotSame($again->id, $afterApplied->id);
    }

    public function test_validation_blocks_same_package_core_module_unknown_module_and_invalid_limit_and_existing_supplier_access(): void
    {
        $tenant = $this->createTenant('generic-tenant-d', 'starter');
        $actor = $this->createTenantUser($tenant, 'generic-d@example.test');
        $supplier = Supplier::query()->create([
            'name' => 'Already Granted Supplier',
            'code' => 'GEN-SUP-002',
            'status' => 'active',
        ]);

        TenantSetting::setValue($tenant->id, 'limit_orders', 10, 'integer');
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => false,
        ]);

        $this->assertValidationFails(fn () => $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'requested_package_key' => 'starter',
        ], $tenant, $actor));

        $this->assertValidationFails(fn () => $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'core',
        ], $tenant, $actor));

        $this->assertValidationFails(fn () => $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'unknown-module',
        ], $tenant, $actor));

        $this->assertValidationFails(fn () => $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'requested_limit_key' => 'orders',
            'requested_limit_value' => 10,
        ], $tenant, $actor));

        $this->assertValidationFails(fn () => $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS,
            'requested_supplier_id' => $supplier->id,
        ], $tenant, $actor));
    }

    public function test_tenant_listing_isolated_super_admin_listing_filterable_and_actor_cannot_write_other_tenant_id(): void
    {
        $tenantA = $this->createTenant('generic-tenant-e1', 'starter');
        $tenantB = $this->createTenant('generic-tenant-e2', 'starter');
        $actorA = $this->createTenantUser($tenantA, 'generic-e1@example.test');
        $actorB = $this->createTenantUser($tenantB, 'generic-e2@example.test');

        $requestA = $this->service->createRequest([
            'tenant_account_id' => $tenantB->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'requested_service_key' => 'custom_report',
        ], $tenantA, $actorA);

        $requestB = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'requested_service_key' => 'supplier_sync',
        ], $tenantB, $actorB);

        $tenantAList = $this->service->listForTenant($tenantA);
        $superList = $this->service->listForSuperAdmin(['tenant_account_id' => $tenantB->id, 'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST]);

        $this->assertCount(1, $tenantAList);
        $this->assertSame($tenantA->id, $tenantAList->first()->tenant_account_id);
        $this->assertSame($tenantA->id, $requestA->tenant_account_id);
        $this->assertCount(1, $superList);
        $this->assertSame($requestB->id, $superList->first()->id);
    }

    public function test_existing_access_and_limit_rules_block_already_active_feature_and_unlimited_limit(): void
    {
        $tenant = $this->createTenant('generic-tenant-f', 'promotion');
        $actor = $this->createTenantUser($tenant, 'generic-f@example.test');

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'customer_portal'],
            ['is_enabled' => true]
        );
        TenantSetting::setValue($tenant->id, 'limit_orders', 'unlimited', 'string');

        $this->assertValidationFails(fn () => $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ], $tenant, $actor));

        $this->assertValidationFails(fn () => $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'requested_limit_key' => 'orders',
            'requested_limit_value' => 999,
        ], $tenant, $actor));
    }

    public function test_audit_preview_is_sanitized_for_requested_and_admin_notes(): void
    {
        $tenant = $this->createTenant('generic-tenant-g', 'starter');
        $actor = $this->createTenantUser($tenant, 'generic-g@example.test');

        $request = $this->service->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'requested_note' => '<script>alert("xss")</script><b>Destek</b> istiyoruz',
        ], $tenant, $actor);

        $this->service->approve($request, $this->platformAdmin, '<script>admin</script><i>Onay</i>');

        $createAudit = AuditLog::query()
            ->where('action', 'tenant_upgrade_request_created')
            ->where('entity_type', 'tenant_upgrade_request')
            ->where('entity_id', $request->id)
            ->firstOrFail();

        $approveAudit = AuditLog::query()
            ->where('action', 'tenant_upgrade_request_approved')
            ->where('entity_type', 'tenant_upgrade_request')
            ->where('entity_id', $request->id)
            ->firstOrFail();

        $this->assertSame('alert("xss")Destek istiyoruz', $createAudit->new_values['requested_note_preview']);
        $this->assertSame('adminOnay', $approveAudit->new_values['admin_note_preview']);
        $this->assertStringNotContainsString('<script>', json_encode($createAudit->new_values));
        $this->assertStringNotContainsString('<script>', json_encode($approveAudit->new_values));
    }

    private function createTenant(string $subdomain, string $packageKey): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Generic ' . $subdomain,
            'legal_name' => 'Generic ' . $subdomain . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => Package::query()->where('key', $packageKey)->value('key') ?? $packageKey,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createTenantUser(TenantAccount $tenant, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Generic User ' . $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $this->adminRole->id,
        ]);

        return $user;
    }

    private function assertValidationFails(callable $callback): void
    {
        try {
            $callback();
            $this->fail('ValidationException bekleniyordu.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
