<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantPackageUpgradeRequest;
use App\Models\TenantSignupRequest;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminRequestHubNavigationTest extends TestCase
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

    public function test_signup_requests_screen_shows_request_hub_tabs(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.index'));

        $response->assertOk();
        $response->assertSee('Başvurular');
        $response->assertSee('Kontrol Paneli');
        $response->assertSee('Public Başvurular');
        $response->assertSee('Paket Talepleri');
        $response->assertSee('Abone Firma Talepleri');
        $response->assertSee('Başvuru Karar Paneli');
    }

    public function test_package_requests_screen_stays_under_request_hub_language(): void
    {
        $request = TenantPackageUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'current_package_key' => $this->tenant->package_key,
            'requested_package_key' => $this->tenant->package_key ?: 'starter',
            'status' => TenantPackageUpgradeRequest::STATUS_NEW,
            'request_note' => 'UI navigation test',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.package-requests.index'));

        $response->assertOk();
        $response->assertSee('Başvurular / Paket Talepleri');
        $response->assertSee('Kontrol Paneli');
        $response->assertSee('Public Başvurular');
        $response->assertSee('Paket Talepleri');
        $response->assertSee('Abone Firma Talepleri');
        $response->assertSee('Talep Karar Paneli');

        $detail = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.package-requests.show', $request));

        $detail->assertOk();
        $detail->assertSee('2. Paket Talebi Detayı / Uygulama Kararı');
        $detail->assertSee('Talep Zaman Çizgisi');
        $detail->assertSee('Public Başvurular');
        $detail->assertSee('Paket Talepleri');
        $detail->assertSee('Abone Firma Talepleri');
        $detail->assertSee('Paket Karar Paneli');
    }

    public function test_generic_upgrade_request_screens_join_request_hub_family(): void
    {
        $request = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_service_key' => 'custom_integration',
        ]);

        $index = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.upgrade-requests.index'));

        $index->assertOk();
        $index->assertSee('Abone Firma Talepleri');
        $index->assertSee('Public Başvurular');
        $index->assertSee('Paket Talepleri');

        $detail = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.upgrade-requests.show', $request));

        $detail->assertOk();
        $detail->assertSee('Talep Özeti');
        $detail->assertSee('Karar Paneli');
        $detail->assertSee('Abone Firma Talepleri');
    }

    public function test_signup_request_detail_stays_in_same_family_language(): void
    {
        $package = \App\Models\Package::query()->create([
            'key' => 'nav-preview-pack',
            'name' => 'Nav Preview Pack',
            'description' => 'Navigation preview package',
            'status' => 'active',
            'is_public' => true,
        ]);

        $request = TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Referans Basvuru',
            'contact_name' => 'Test Yetkili',
            'phone' => '05551112233',
            'email' => 'lead@example.test',
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
            'status' => TenantSignupRequest::STATUS_NEW,
            'source' => 'public_landing',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $request));

        $response->assertOk();
        $response->assertSee('2. Başvuru Detayı / Dönüştürmeye Hazırlık');
        $response->assertSee('Dönüşüm Hazırlığı');
        $response->assertSee('Public Başvurular');
        $response->assertSee('Paket Talepleri');

        $preview = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-preview', $request));

        $preview->assertOk();
        $preview->assertSee('Dönüşüm Önizleme');
        $preview->assertSee('Tenant Create Formuna Devam Et');
        $preview->assertSee('Public Başvurular');
        $preview->assertSee('Paket Talepleri');
    }
}
