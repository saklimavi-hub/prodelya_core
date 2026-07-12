<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantSettingsLandingTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'settings-landing-guarded',
            'slug' => 'settings-landing-guarded',
        ])->save();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereIn('module_key', ['product_data_hub', 'customer_portal', 'api_access', 'production_qc'])
            ->delete();

        TenantSupplierAccess::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->update(['is_active' => false]);
    }

    public function test_settings_landing_renders_service_driven_summary_and_safe_cards(): void
    {
        TenantSetting::setValue($this->tenant->id, 'limit_orders', 1, 'integer');
        TenantSetting::setValue($this->tenant->id, 'limit_storage_mb', 300, 'integer');
        TenantSetting::setValue($this->tenant->id, 'storage_used_mb', 256, 'integer');

        Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SETTINGS-LANDING-001',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fis',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SETTINGS-LANDING-002',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fis',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $response->assertOk();
        $response->assertSee('Kurulum Merkezi');
        $response->assertSee('Firma Profili');
        $response->assertSee('Panel ve Portal');
        $response->assertSee('Bildirimler');
        $response->assertSee('Paket ve Limitler');
        $response->assertSee('Kullanıcılar ve Roller');
        $response->assertSee('Katalog ve Product Hub');
        $response->assertSee('Dosya ve Depolama');
        $response->assertSee('Talep Merkezi');
        $response->assertSee('Panel Yetkilisi');
        $response->assertSee('Kurulum Özeti');
        $response->assertSee('Çalışma klasörü kök adı');
        $response->assertSee('Yerel sunucu');
        $response->assertSee('Amazon S3');
        $response->assertSee('Limit aşıldı');
        $response->assertSee('Limit dolmak üzere');
        $response->assertSee('Sonraki Faz');
        $response->assertSee('Abone Firma');
        $response->assertSee('Portal ve Paylaşım');
        $response->assertSee('data-settings-tab-trigger="company-profile"', false);
        $response->assertSee('data-settings-tab-panel="company-profile"', false);
        $response->assertDontSee(route('admin.product-data-hub.index'), false);
        $response->assertDontSee('smtp_password', false);
        $response->assertDontSee('API key', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('bakiye', false);
        $response->assertDontSee('cari hareket', false);
        $response->assertDontSee('product_data_hub', false);
        $response->assertDontSee('Super Admin Yönetir');
        $response->assertDontSee('Owner');
        $response->assertDontSee('Public Tracking');
        $response->assertDontSee('Portal ve Public Linkler');
    }

    public function test_settings_landing_shows_trial_and_expired_status_messages(): void
    {
        DB::statement('PRAGMA ignore_check_constraints = ON');
        $this->tenant->forceFill([
            'status' => 'trial',
            'package_key' => 'demo',
        ])->save();
        DB::statement('PRAGMA ignore_check_constraints = OFF');

        $trialResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $trialResponse->assertOk();
        $trialResponse->assertSee('Deneme');

        DB::statement('PRAGMA ignore_check_constraints = ON');
        $this->tenant->forceFill([
            'status' => 'expired',
        ])->save();
        DB::statement('PRAGMA ignore_check_constraints = OFF');

        $expiredResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $expiredResponse->assertOk();
        $expiredResponse->assertSee('Suresi Dolmus');
        $expiredResponse->assertSee('Paket süresi dolmuş. İşlem yapmak için paket yenilenmeli.');
    }

    public function test_settings_landing_shows_product_data_hub_link_only_when_accessible(): void
    {
        $hidden = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $hidden->assertOk();
        $hidden->assertDontSee(route('admin.product-data-hub.index'), false);

        TenantModule::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'product_data_hub',
            'feature_key' => 'tenant_catalog_projection',
            'is_enabled' => true,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Landing PDH Supplier',
            'code' => 'LANDING-PDH-001',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
        ]);

        $visible = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $visible->assertOk();
        $visible->assertDontSee(route('admin.product-data-hub.index'), false);
    }
}
