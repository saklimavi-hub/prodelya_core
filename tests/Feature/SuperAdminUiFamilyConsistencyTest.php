<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantPackageUpgradeRequest;
use App\Models\TenantSignupRequest;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminUiFamilyConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_dashboard_and_core_super_admin_screens_render_shared_ui_family_blocks(): void
    {
        $signupRequest = TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Ui Family Lead',
            'contact_name' => 'Ui Family Yetkili',
            'phone' => '05550001122',
            'email' => 'ui-family-lead@example.test',
            'status' => TenantSignupRequest::STATUS_NEW,
            'source' => 'public_landing',
        ]);

        $convertedTenant = TenantAccount::query()->create([
            'name' => 'Ui Family Converted Tenant',
            'legal_name' => 'Ui Family Converted Tenant Ltd.',
            'slug' => 'ui-family-converted-tenant',
            'panel_subdomain' => 'ui-family-converted-tenant',
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $convertedSignupRequest = TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Ui Family Converted Lead',
            'contact_name' => 'Ui Family Converted Yetkili',
            'phone' => '05550001123',
            'email' => 'ui-family-converted@example.test',
            'status' => TenantSignupRequest::STATUS_CONVERTED,
            'source' => 'public_landing',
            'converted_tenant_account_id' => $convertedTenant->id,
            'meta_json' => ['converted_at' => now()->toDateTimeString()],
        ]);

        $packageRequest = TenantPackageUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'current_package_key' => $this->tenant->package_key,
            'requested_package_key' => $this->tenant->package_key ?: 'starter',
            'status' => TenantPackageUpgradeRequest::STATUS_NEW,
            'request_note' => 'UI family test',
        ]);

        $upgradeRequest = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_service_key' => 'custom_integration',
            'requested_note' => 'UI family generic request',
        ]);

        $screens = [
            route('admin.super.dashboard') => ['Operasyon Özeti', 'Canlıya Hazırlık', 'pd-kpi-strip'],
            route('admin.super.tenants.index') => ['Abone Firma Listesi', 'Filtreler', 'pd-mini-kpi-strip'],
            route('admin.super.tenants.show', $this->tenant) => ['SaaS Cari Özet', 'Hızlı İşlemler', 'pd-summary'],
            route('admin.super.tenants.edit', $this->tenant) => ['Cari / Fatura Bilgileri', 'Abonelik Durumu', 'pd-tenant-edit-nav'],
            route('admin.super.signup-requests.index') => ['Kontrol Paneli', 'Başvuru Karar Paneli', 'pd-mini-kpi-card'],
            route('admin.super.signup-requests.show', $signupRequest) => ['Dönüşüm Hazırlığı', 'pd-summary', 'pd-section-card'],
            route('admin.super.signup-requests.conversion-preview', $signupRequest) => ['Dönüşüm Önizleme', 'Karar Özeti', 'pd-section-card'],
            route('admin.super.signup-requests.conversion-success', $convertedSignupRequest) => ['Abone Firma Oluşturuldu', 'Sonraki Adımlar', 'pd-section-card'],
            route('admin.super.package-requests.index') => ['Kontrol Paneli', 'Talep Karar Paneli', 'pd-mini-kpi-card'],
            route('admin.super.package-requests.show', $packageRequest) => ['Paket Karar Paneli', 'Talep Zaman Çizgisi', 'pd-summary'],
            route('admin.super.upgrade-requests.index') => ['Kontrol Paneli', 'Talep Karar Paneli', 'pd-mini-kpi-card'],
            route('admin.super.upgrade-requests.show', $upgradeRequest) => ['Karar Paneli', 'Talep Zaman Çizgisi', 'pd-section-card'],
        ];

        foreach ($screens as $route => $assertions) {
            $response = $this->actingAs($this->platformAdmin, 'web')
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->get($route);

            $response->assertOk();

            foreach ($assertions as $assertion) {
                $response->assertSee($assertion, false);
            }
        }
    }
}
