<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantSignupRequest;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSignupRequestConversionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $existingTenant;
    private Role $tenantAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->existingTenant = TenantAccount::query()->firstOrFail();
        $this->tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_super_admin_can_open_prefilled_create_flow_from_signup_request(): void
    {
        $package = Package::query()->create([
            'key' => 'public-prefill',
            'name' => 'Public Prefill',
            'description' => 'Prefill package',
            'status' => 'active',
            'is_public' => true,
        ]);
        $requestItem = $this->createSignupRequest([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
            'requested_modules_json' => ['customer_portal', 'product_data_hub'],
            'expected_user_count' => 8,
            'city' => 'İstanbul',
            'sector' => 'Promosyon',
        ]);

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem));

        $show->assertOk();
        $show->assertSee('Abone Firmaya Dönüştür');
        $show->assertSee('Dönüşüm Hazırlığı');
        $show->assertSee('Veri Taşıma Haritası');
        $show->assertSee('Panel adresi önerisi');
        $show->assertSee('Bu modüller başvuru tercihi olarak taşınır');
        $show->assertSee(route('admin.super.signup-requests.conversion-preview', $requestItem), false);

        $preview = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-preview', $requestItem));

        $preview->assertOk();
        $preview->assertSee('Dönüşüm Önizleme');
        $preview->assertSee('Başvuru Özeti');
        $preview->assertSee('Oluşturulacak Abone Firma');
        $preview->assertSee('Panel Yetkilisi');
        $preview->assertSee('Risk ve Hazırlık');
        $preview->assertSee('Tenant Create Formuna Devam Et');
        $preview->assertSee('customer_portal');
        $preview->assertSee('product_data_hub');

        $create = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]));

        $create->assertOk();
        $create->assertSee('Bu form public başvurudan dolduruldu');
        $create->assertSee('İstanbul / Promosyon');
        $create->assertSee('Akasya Promosyon');
        $create->assertSee('Ayşe Kaya');
        $create->assertSee('ayse@example.test');
        $create->assertSee('customer_portal');
        $create->assertSee('product_data_hub');
        $create->assertSee('tenant modül override uygulanmaz');
        $create->assertSee((string) $requestItem->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'signup_request_conversion_preview_opened',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $requestItem->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'signup_request_conversion_prefill_opened',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $requestItem->id,
        ]);
    }

    public function test_demo_signup_request_prefill_keeps_demo_metadata_without_module_override(): void
    {
        $package = Package::query()->create([
            'key' => 'demo-prefill-pack',
            'name' => 'Demo Prefill Pack',
            'description' => 'Demo package',
            'status' => 'active',
            'is_public' => true,
        ]);

        $requestItem = $this->createSignupRequest([
            'request_type' => TenantSignupRequest::TYPE_DEMO,
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
            'requested_modules_json' => ['customer_portal'],
            'demo_topic' => 'Grafik + portal demo',
            'note' => 'Demo sırasında ürün arama akışı gösterilsin.',
        ]);

        $create = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]));

        $create->assertOk();
        $create->assertSee('Demo Prefill Pack');
        $create->assertSee('Grafik + portal demo');
        $create->assertSee('Demo başvurusu create akışında otomatik trial başlatmaz');
        $create->assertSee('customer_portal');
    }

    public function test_conversion_preview_shows_trial_demo_and_warning_states(): void
    {
        $trialPackage = Package::query()->create([
            'key' => 'preview-trial-pack',
            'name' => 'Preview Trial Pack',
            'description' => 'Preview trial package',
            'status' => 'active',
            'is_public' => true,
            'trial_days' => 21,
        ]);

        $trialRequest = $this->createSignupRequest([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'requested_package_id' => $trialPackage->id,
            'requested_package_key' => $trialPackage->key,
        ]);

        $trialPreview = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-preview', $trialRequest));

        $trialPreview->assertOk();
        $trialPreview->assertSee('Trial');
        $trialPreview->assertSee('Trial Gün Sayısı');
        $trialPreview->assertSee('21');
        $trialPreview->assertSee('Tenant Create Formuna Devam Et');

        User::query()->create([
            'name' => 'Loose Existing User',
            'email' => 'warning-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        $demoPackage = Package::query()->create([
            'key' => 'preview-demo-pack',
            'name' => 'Preview Demo Pack',
            'description' => 'Preview demo package',
            'status' => 'active',
            'is_public' => true,
        ]);

        $demoRequest = $this->createSignupRequest([
            'request_type' => TenantSignupRequest::TYPE_DEMO,
            'email' => 'warning-owner@example.test',
            'requested_package_id' => $demoPackage->id,
            'requested_package_key' => $demoPackage->key,
            'requested_modules_json' => ['customer_portal'],
            'demo_topic' => 'Portal turu',
        ]);

        $demoPreview = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-preview', $demoRequest));

        $demoPreview->assertOk();
        $demoPreview->assertSee('Portal turu');
        $demoPreview->assertSee('Demo başvurusu create akışında otomatik trial başlatmaz');
        $demoPreview->assertSee('Uyarılarla Devam Et');
        $demoPreview->assertSee('customer_portal');
    }

    public function test_successful_conversion_marks_signup_request_and_links_new_tenant(): void
    {
        $package = Package::query()->where('key', 'starter')->where('status', 'active')->firstOrFail();
        $requestItem = $this->createSignupRequest([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
            'requested_modules_json' => ['customer_portal', 'product_data_hub'],
            'expected_user_count' => 12,
            'city' => 'Bursa',
            'sector' => 'Baskı',
            'note' => 'Katalog ve portal akışını görmek istiyoruz.',
        ]);
        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.store'), [
                'signup_request_id' => $requestItem->id,
                'name' => 'Akasya Promosyon',
                'legal_name' => 'Akasya Promosyon Ltd. Şti.',
                'slug' => 'akasya-promosyon',
                'panel_subdomain' => 'akasyapanel',
                'status' => 'trial',
                'package_key' => $package->key,
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'owner_name' => 'Ayşe Kaya',
                'owner_email' => 'ayse-conversion@example.test',
                'owner_phone' => '05320000000',
                'owner_password' => '',
            ]);

        $tenant = TenantAccount::query()->where('slug', 'akasya-promosyon')->firstOrFail();

        $response->assertSessionHasNoErrors();
        $requestItem->refresh();

        $response->assertRedirect(route('admin.super.signup-requests.conversion-success', $requestItem));
        $response->assertSessionHas('success');
        $this->assertNotSame($this->existingTenant->id, $tenant->id);
        $this->assertSame('Akasya Promosyon', $tenant->name);
        $this->assertSame('akasyapanel', $tenant->panel_subdomain);

        $this->assertSame(TenantSignupRequest::STATUS_CONVERTED, $requestItem->status);
        $this->assertSame($tenant->id, $requestItem->converted_tenant_account_id);
        $this->assertSame($requestItem->id, TenantSetting::getValue($tenant->id, 'sales_lead_signup_request_id'));
        $this->assertSame('trial', TenantSetting::getValue($tenant->id, 'sales_lead_request_type'));
        $this->assertSame('Bursa', TenantSetting::getValue($tenant->id, 'company_city'));
        $this->assertSame(['customer_portal', 'product_data_hub'], TenantSetting::getValue($tenant->id, 'sales_lead_requested_modules'));
        $this->assertSame('Katalog ve portal akışını görmek istiyoruz.', TenantSetting::getValue($tenant->id, 'sales_lead_note'));
        $this->assertNotEmpty($requestItem->meta_json['converted_at'] ?? null);
        $this->assertSame($this->platformAdmin->id, $requestItem->meta_json['converted_by_user_id'] ?? null);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'signup_request_conversion_completed',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $requestItem->id,
        ]);

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem));

        $show->assertOk();
        $show->assertSee('Bu başvuru Abone Firma’ya dönüştürüldü');
        $show->assertSee('Abone Firma Detayını Aç');
        $show->assertDontSee('href="' . route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]) . '"', false);

        $this->assertSame(0, TenantModule::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('module_key', ['customer_portal', 'product_data_hub'])
            ->whereNull('feature_key')
            ->count());

        $show->assertSee('Abone Firma Aç');
        $show->assertSee('Dönüşüm Özeti');

        $preview = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-preview', $requestItem));

        $preview->assertOk();
        $preview->assertSee('Abone Firma Aç');

        $success = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-success', $requestItem));

        $success->assertOk();
        $success->assertSee('Abone Firma Oluşturuldu');
        $success->assertSee('Akasya Promosyon');
        $success->assertSee('akasyapanel');
        $success->assertSee('Ayşe Kaya');
        $success->assertSee('ayse-conversion@example.test');
        $success->assertSee('Abone Firma Detayına Git');
        $success->assertSee('Onboarding Hazırlığı');
        $success->assertSee('Bu modüller başvuru tercihi olarak taşındı. Otomatik module override oluşturulmadı.');
        $success->assertDontSee('Geçici owner şifresi');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'signup_request_conversion_success_viewed',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $requestItem->id,
        ]);
    }

    public function test_rejected_or_converted_requests_are_guarded_against_conversion_and_status_downgrade(): void
    {
        $package = Package::query()->where('status', 'active')->firstOrFail();
        $rejected = $this->createSignupRequest([
            'status' => TenantSignupRequest::STATUS_REJECTED,
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
        ]);

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $rejected));

        $show->assertOk();
        $show->assertSee('Dönüştürülemez');
        $show->assertSee('Dönüştürmeden önce durumu gözden geçirmeniz gerekir');

        $blockedPreview = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-preview', $rejected));

        $blockedPreview->assertOk();
        $blockedPreview->assertSee('Devam Edilemez');

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.create', ['signup_request_id' => $rejected->id]))
            ->assertRedirect(route('admin.super.signup-requests.show', $rejected))
            ->assertSessionHas('error');

        $this->actingAs($this->platformAdmin, 'web')
            ->from(route('admin.super.tenants.create', ['signup_request_id' => $rejected->id]))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.store'), [
                'signup_request_id' => $rejected->id,
                'name' => 'Rejected Tenant',
                'legal_name' => 'Rejected Tenant Ltd.',
                'slug' => 'rejected-tenant',
                'panel_subdomain' => 'rejected-tenant',
                'status' => 'active',
                'package_key' => $package->key,
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'owner_name' => 'Owner',
                'owner_email' => 'rejected-owner@example.test',
            ])
            ->assertRedirect(route('admin.super.tenants.create', ['signup_request_id' => $rejected->id]))
            ->assertSessionHasErrors('signup_request_id');

        $convertedTenant = TenantAccount::query()->create([
            'name' => 'Converted Tenant',
            'legal_name' => 'Converted Tenant Ltd.',
            'slug' => 'converted-tenant',
            'panel_subdomain' => 'converted-tenant',
            'status' => 'active',
            'package_key' => $package->key,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $converted = $this->createSignupRequest([
            'status' => TenantSignupRequest::STATUS_CONVERTED,
            'converted_tenant_account_id' => $convertedTenant->id,
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.create', ['signup_request_id' => $converted->id]))
            ->assertRedirect(route('admin.super.signup-requests.show', $converted))
            ->assertSessionHas('error');

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.super.signup-requests.status.update', $converted), [
                'status' => TenantSignupRequest::STATUS_REJECTED,
            ])
            ->assertRedirect(route('admin.super.signup-requests.show', $converted))
            ->assertSessionHas('error');

        $converted->refresh();
        $this->assertSame(TenantSignupRequest::STATUS_CONVERTED, $converted->status);
    }

    public function test_tenant_owner_cannot_open_conversion_preview(): void
    {
        $requestItem = $this->createSignupRequest();

        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Preview Admin',
            'email' => 'tenant-preview-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $this->existingTenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-preview', $requestItem))
            ->assertForbidden();
    }

    public function test_conversion_success_screen_is_guarded_for_non_converted_requests_and_tenant_owner(): void
    {
        $requestItem = $this->createSignupRequest();

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-success', $requestItem))
            ->assertRedirect(route('admin.super.signup-requests.show', $requestItem))
            ->assertSessionHas('error');

        $package = Package::query()->where('key', 'starter')->where('status', 'active')->firstOrFail();
        $convertedTenant = TenantAccount::query()->create([
            'name' => 'Success Guard Tenant',
            'legal_name' => 'Success Guard Tenant Ltd.',
            'slug' => 'success-guard-tenant',
            'panel_subdomain' => 'success-guard-tenant',
            'status' => 'active',
            'package_key' => $package->key,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $requestItem->update([
            'status' => TenantSignupRequest::STATUS_CONVERTED,
            'converted_tenant_account_id' => $convertedTenant->id,
            'meta_json' => ['converted_at' => now()->toDateTimeString()],
        ]);

        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Success Admin',
            'email' => 'tenant-success-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $this->existingTenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-success', $requestItem))
            ->assertForbidden();
    }

    public function test_demo_conversion_success_screen_shows_demo_topic_and_no_module_override(): void
    {
        $package = Package::query()->where('key', 'starter')->where('status', 'active')->firstOrFail();

        $requestItem = $this->createSignupRequest([
            'request_type' => TenantSignupRequest::TYPE_DEMO,
            'demo_topic' => 'Katalog ve teklif demo akışı',
            'requested_modules_json' => ['customer_portal'],
        ]);

        $tenant = TenantAccount::query()->create([
            'name' => 'Demo Success Tenant',
            'legal_name' => 'Demo Success Tenant Ltd.',
            'slug' => 'demo-success-tenant',
            'panel_subdomain' => 'demo-success-tenant',
            'status' => 'active',
            'package_key' => $package->key,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $requestItem->update([
            'status' => TenantSignupRequest::STATUS_CONVERTED,
            'converted_tenant_account_id' => $tenant->id,
            'meta_json' => ['converted_at' => now()->toDateTimeString()],
        ]);

        TenantSetting::setValue($tenant->id, 'sales_lead_requested_modules', ['customer_portal'], 'array');
        TenantSetting::setValue($tenant->id, 'sales_lead_demo_topic', 'Katalog ve teklif demo akışı', 'string');

        $success = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-success', $requestItem));

        $success->assertOk();
        $success->assertSee('Katalog ve teklif demo akışı');
        $success->assertSee('customer_portal');
        $success->assertSee('Otomatik module override oluşturulmadı.', false);
    }

    public function test_store_replay_guard_blocks_when_signup_request_changes_after_prefill_opened(): void
    {
        $package = Package::query()->where('key', 'starter')->where('status', 'active')->firstOrFail();
        $requestItem = $this->createSignupRequest([
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]))
            ->assertOk();

        $requestItem->update([
            'status' => TenantSignupRequest::STATUS_CONVERTED,
            'converted_tenant_account_id' => $this->existingTenant->id,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->from(route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.store'), [
                'signup_request_id' => $requestItem->id,
                'name' => 'Replay Block Tenant',
                'legal_name' => 'Replay Block Tenant Ltd.',
                'slug' => 'replay-block-tenant',
                'panel_subdomain' => 'replay-block-tenant',
                'status' => 'active',
                'package_key' => $package->key,
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'owner_name' => 'Replay Owner',
                'owner_email' => 'replay-owner@example.test',
            ]);

        $response
            ->assertRedirect(route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]))
            ->assertSessionHasErrors('signup_request_id');

        $this->assertDatabaseMissing('tenant_accounts', [
            'slug' => 'replay-block-tenant',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'signup_request_conversion_replay_blocked',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $requestItem->id,
        ]);
    }

    public function test_readiness_panel_surfaces_duplicate_subdomain_owner_email_and_company_name_risks(): void
    {
        $package = Package::query()->create([
            'key' => 'public-risk',
            'name' => 'Public Risk',
            'description' => 'Risk package',
            'status' => 'active',
            'is_public' => true,
        ]);

        TenantAccount::query()->create([
            'name' => 'AKASYA   PROMOSYON',
            'legal_name' => 'Akasya Duplicate Ltd.',
            'slug' => 'duplicate-akasya',
            'panel_subdomain' => 'akasya-promosyon',
            'status' => 'active',
            'package_key' => $package->key,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $duplicateUser = User::query()->create([
            'name' => 'Existing Tenant User',
            'email' => 'ayse@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $duplicateUser->id,
            'tenant_account_id' => $this->existingTenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $requestItem = $this->createSignupRequest([
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
        ]);

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem));

        $show->assertOk();
        $show->assertSee('Aynı panel adresi başka bir Abone Firma tarafından kullanılıyor.');
        $show->assertSee('Firma yetkilisi e-postası mevcut tenant kullanıcısıyla çakışıyor.');
        $show->assertSee('Benzer adla kayıtlı Abone Firmalar bulundu.');
        $show->assertSee('Dönüştürülemez');
    }

    public function test_inactive_or_missing_package_is_shown_as_blocker(): void
    {
        $inactivePackage = Package::query()->create([
            'key' => 'inactive-signup',
            'name' => 'Inactive Signup',
            'description' => 'Inactive',
            'status' => 'passive',
            'is_public' => true,
        ]);

        $requestItem = $this->createSignupRequest([
            'requested_package_id' => $inactivePackage->id,
            'requested_package_key' => $inactivePackage->key,
        ]);

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem));

        $show->assertOk();
        $show->assertSee('Seçilen paket aktif değil.');
        $show->assertSee('Dönüştürülemez');
    }

    public function test_super_admin_can_add_operator_note_and_xss_payload_is_escaped(): void
    {
        $requestItem = $this->createSignupRequest();

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.signup-requests.notes.store', $requestItem), [
                'note' => '<script>alert(1)</script> Telefonla görüşüldü.',
            ])
            ->assertRedirect(route('admin.super.signup-requests.show', $requestItem))
            ->assertSessionHas('success');

        $requestItem->refresh();

        $this->assertSame(
            '<script>alert(1)</script> Telefonla görüşüldü.',
            data_get($requestItem->meta_json, 'operator_notes.0.note')
        );

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem));

        $show->assertOk();
        $show->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; Telefonla görüşüldü.', false);
        $show->assertDontSee('<script>alert(1)</script> Telefonla görüşüldü.', false);
        $show->assertSee('Operasyon notu eklendi');
    }

    public function test_tenant_owner_cannot_post_operator_note(): void
    {
        $requestItem = $this->createSignupRequest();

        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-note-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $this->existingTenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.signup-requests.notes.store', $requestItem), [
                'note' => 'Yetkisiz not',
            ])
            ->assertForbidden();
    }

    public function test_status_updates_and_super_admin_guard_behave_correctly(): void
    {
        $requestItem = $this->createSignupRequest();

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.super.signup-requests.status.update', $requestItem), [
                'status' => TenantSignupRequest::STATUS_CONTACTED,
            ])
            ->assertRedirect(route('admin.super.signup-requests.show', $requestItem))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('tenant_signup_requests', [
            'id' => $requestItem->id,
            'status' => TenantSignupRequest::STATUS_CONTACTED,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'signup_request_status_updated',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $requestItem->id,
        ]);

        $timelineShow = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem));

        $timelineShow->assertOk();
        $timelineShow->assertSee('Dönüşüm Logu');
        $timelineShow->assertSee('Başvuru durumu güncellendi');

        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-conversion-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $this->existingTenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem))
            ->assertForbidden();
    }

    private function createSignupRequest(array $overrides = []): TenantSignupRequest
    {
        return TenantSignupRequest::query()->create(array_merge([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Akasya Promosyon',
            'contact_name' => 'Ayşe Kaya',
            'phone' => '05320000000',
            'email' => 'ayse@example.test',
            'city' => 'İstanbul',
            'sector' => 'Promosyon',
            'requested_package_id' => null,
            'requested_package_key' => null,
            'requested_modules_json' => [],
            'expected_user_count' => null,
            'demo_topic' => null,
            'note' => 'Başvuru notu',
            'status' => TenantSignupRequest::STATUS_NEW,
            'source' => 'public_landing',
            'meta_json' => [],
        ], $overrides));
    }
}
