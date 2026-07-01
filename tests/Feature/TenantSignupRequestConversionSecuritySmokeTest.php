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
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantSignupRequestConversionSecuritySmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $existingTenant;
    private Role $tenantAdminRole;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->existingTenant = TenantAccount::query()->firstOrFail();
        $this->tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
    }

    public function test_trial_signup_full_conversion_smoke_flow_is_safe_and_consistent(): void
    {
        $package = Package::query()->create([
            'key' => 'trial-c5-pack',
            'name' => 'Trial C5 Pack',
            'description' => 'Trial smoke package',
            'status' => 'active',
            'is_public' => true,
            'trial_days' => 30,
        ]);

        $publicNote = '<script>alert("xss")</script> Trial notu';
        $operatorNote = '<script>alert("xss")</script> Telefonla gorusuldu.';

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('marketing.register-interest.store'), [
                'company_name' => 'C5 Trial Promosyon',
                'contact_name' => 'Trial Yetkili',
                'phone' => '05321111111',
                'email' => 'c5-trial@example.test',
                'city' => 'Istanbul',
                'business_type' => 'Promosyon',
                'requested_package_id' => $package->id,
                'expected_user_count' => 14,
                'selected_modules' => ['customer_portal', 'product_data_hub'],
                'message' => $publicNote,
            ])
            ->assertRedirect(route('marketing.register-interest'))
            ->assertSessionHas('success');

        $requestItem = TenantSignupRequest::query()->where('email', 'c5-trial@example.test')->firstOrFail();

        $index = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.index'));

        $index->assertOk();
        $index->assertSee('C5 Trial Promosyon');
        $index->assertSee(route('admin.super.signup-requests.conversion-preview', $requestItem), false);

        $detail = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem));

        $detail->assertOk();
        $detail->assertSee('Dönüşüm Hazırlığı');
        $detail->assertSee(e($publicNote), false);
        $detail->assertDontSee($publicNote, false);
        $detail->assertSee('Abone Firmaya Dönüştür');
        $detail->assertSee(route('admin.super.signup-requests.conversion-preview', $requestItem), false);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.signup-requests.notes.store', $requestItem), [
                'note' => $operatorNote,
            ])
            ->assertRedirect(route('admin.super.signup-requests.show', $requestItem))
            ->assertSessionHas('success');

        $requestItem->refresh();
        $this->assertSame($operatorNote, data_get($requestItem->meta_json, 'operator_notes.0.note'));

        $detail = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem));

        $detail->assertOk();
        $detail->assertSee(e($operatorNote), false);
        $detail->assertDontSee($operatorNote, false);
        $detail->assertSee('Operasyon notu eklendi');

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
        $preview->assertSee(e($publicNote), false);
        $preview->assertDontSee($publicNote, false);

        $create = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]));

        $create->assertOk();
        $create->assertSee('Bu form public başvurudan dolduruldu');
        $create->assertSee('Abone Firma, panel yetkilisi ve başlangıç ayarlarını tek akışta hazırlayın.');
        $create->assertSee('Panel Yetkilisi Adı');
        $create->assertSee('Panel Adresi');
        $create->assertSee('customer_portal');
        $create->assertSee('product_data_hub');
        $create->assertSee('tenant modül override uygulanmaz');
        $create->assertDontSee($publicNote, false);
        $create->assertDontSee('owner_temporary_password', false);

        $store = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.store'), [
                'signup_request_id' => $requestItem->id,
                'name' => 'C5 Trial Promosyon',
                'legal_name' => 'C5 Trial Promosyon Ltd. Sti.',
                'slug' => 'c5-trial-promosyon',
                'panel_subdomain' => 'c5trialpanel',
                'status' => 'trial',
                'package_key' => $package->key,
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'owner_name' => 'Trial Yetkili',
                'owner_email' => 'c5-trial-owner@example.test',
                'owner_phone' => '05321111111',
                'owner_password' => '',
            ]);

        $store->assertRedirect(route('admin.super.signup-requests.conversion-success', $requestItem));

        $tenant = TenantAccount::query()->where('slug', 'c5-trial-promosyon')->firstOrFail();
        $requestItem->refresh();

        $this->assertSame(TenantSignupRequest::STATUS_CONVERTED, $requestItem->status);
        $this->assertSame($tenant->id, $requestItem->converted_tenant_account_id);
        $this->assertSame(['customer_portal', 'product_data_hub'], TenantSetting::getValue($tenant->id, 'sales_lead_requested_modules'));
        $this->assertSame($requestItem->id, TenantSetting::getValue($tenant->id, 'sales_lead_signup_request_id'));
        $this->assertSame('trial', TenantSetting::getValue($tenant->id, 'sales_lead_request_type'));
        $this->assertSame(0, TenantModule::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('module_key', ['customer_portal', 'product_data_hub'])
            ->whereNull('feature_key')
            ->count());

        $this->assertDatabaseHas('users', ['email' => 'c5-trial-owner@example.test']);
        $owner = User::query()->where('email', 'c5-trial-owner@example.test')->firstOrFail();
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $owner->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $success = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-success', $requestItem));

        $success->assertOk();
        $success->assertSee('Abone Firma Oluşturuldu');
        $success->assertSee('Onboarding Hazırlığı');
        $success->assertSee('Abone Firma Detayına Git');
        $success->assertSee('Abone Firma Panelini Aç');
        $success->assertSee('Panel Yetkilisini Gör');
        $success->assertSee('Paket / Limit Ayarlarını Aç');
        $success->assertSee('Hoş Geldiniz E-postası Hazırla');
        $success->assertSee('yakında');
        $success->assertSee('Abone Firma hesabı');
        $success->assertSee('Bu modüller başvuru tercihi olarak taşındı. Otomatik module override oluşturulmadı.');
        $success->assertDontSee('Geçici owner şifresi');
        $success->assertDontSee('owner_temporary_password', false);
        $success->assertDontSee($publicNote, false);
        $success->assertDontSee('<script>alert("xss")</script>', false);

        $convertedDetail = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem));

        $convertedDetail->assertOk();
        $convertedDetail->assertSee('Bu başvuru Abone Firma’ya dönüştürüldü');
        $convertedDetail->assertSee('Abone Firma Aç');
        $convertedDetail->assertSee('Dönüşüm Özeti');
        $convertedDetail->assertDontSee('href="' . route('admin.super.signup-requests.conversion-preview', $requestItem) . '"', false);

        $auditActions = AuditLog::query()
            ->where('entity_type', 'tenant_signup_request')
            ->where('entity_id', $requestItem->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertContains('signup_request_note_added', $auditActions);
        $this->assertContains('signup_request_conversion_preview_opened', $auditActions);
        $this->assertContains('signup_request_conversion_prefill_opened', $auditActions);
        $this->assertContains('signup_request_conversion_completed', $auditActions);
        $this->assertContains('signup_request_conversion_success_viewed', $auditActions);

        $auditPayload = AuditLog::query()
            ->where('entity_type', 'tenant_signup_request')
            ->where('entity_id', $requestItem->id)
            ->get()
            ->map(fn (AuditLog $log) => json_encode($log->new_values, JSON_UNESCAPED_UNICODE))
            ->implode("\n");

        $this->assertStringNotContainsString('owner_password', $auditPayload);
        $this->assertStringNotContainsString('smtp_password', $auditPayload);
        $this->assertStringNotContainsString('<script>', $auditPayload);
        $this->assertStringNotContainsString('temporary password', Str::lower($success->getContent()));
    }

    public function test_demo_signup_full_conversion_smoke_keeps_demo_metadata_and_does_not_auto_trial(): void
    {
        $package = Package::query()->create([
            'key' => 'demo-c5-pack',
            'name' => 'Demo C5 Pack',
            'description' => 'Demo smoke package',
            'status' => 'active',
            'is_public' => true,
        ]);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('marketing.demo-request.store'), [
                'company_name' => 'C5 Demo Baski',
                'contact_name' => 'Demo Yetkili',
                'phone' => '05322222222',
                'email' => 'c5-demo@example.test',
                'demo_topic' => 'Katalog ve teklif demo akisi',
                'message' => 'Demo basvurusu notu',
            ])
            ->assertRedirect(route('marketing.demo-request'))
            ->assertSessionHas('success');

        $requestItem = TenantSignupRequest::query()->where('email', 'c5-demo@example.test')->firstOrFail();
        $requestItem->update([
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
            'requested_modules_json' => ['customer_portal'],
        ]);

        $preview = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-preview', $requestItem));

        $preview->assertOk();
        $preview->assertSee('Katalog ve teklif demo akisi');
        $preview->assertSee('Demo başvurusu create akışında otomatik trial başlatmaz');
        $preview->assertSee('customer_portal');

        $create = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]));

        $create->assertOk();
        $create->assertSee('Katalog ve teklif demo akisi');
        $create->assertSee('Demo başvurusu create akışında otomatik trial başlatmaz');
        $create->assertSee('customer_portal');

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.store'), [
                'signup_request_id' => $requestItem->id,
                'name' => 'C5 Demo Baski',
                'legal_name' => 'C5 Demo Baski Ltd. Sti.',
                'slug' => 'c5-demo-baski',
                'panel_subdomain' => 'c5demopanel',
                'status' => 'active',
                'package_key' => $package->key,
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'owner_name' => 'Demo Yetkili',
                'owner_email' => 'c5-demo-owner@example.test',
                'owner_phone' => '05322222222',
                'owner_password' => '',
            ])
            ->assertRedirect(route('admin.super.signup-requests.conversion-success', $requestItem));

        $tenant = TenantAccount::query()->where('slug', 'c5-demo-baski')->firstOrFail();

        $success = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-success', $requestItem->fresh()));

        $success->assertOk();
        $success->assertSee('Katalog ve teklif demo akisi');
        $success->assertSee('customer_portal');
        $success->assertSee('Otomatik module override oluşturulmadı.', false);
        $this->assertSame('Katalog ve teklif demo akisi', TenantSetting::getValue($tenant->id, 'sales_lead_demo_topic'));
        $this->assertSame(['customer_portal'], TenantSetting::getValue($tenant->id, 'sales_lead_requested_modules'));
    }

    public function test_security_regression_keeps_signup_conversion_surfaces_super_admin_only_and_tenant_host_blocked(): void
    {
        $package = Package::query()->where('status', 'active')->firstOrFail();
        $tenant = TenantAccount::query()->create([
            'name' => 'C5 Security Tenant',
            'legal_name' => 'C5 Security Tenant Ltd.',
            'slug' => 'c5-security-tenant',
            'panel_subdomain' => 'c5-security-tenant',
            'status' => 'active',
            'package_key' => $package->key,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $requestItem = TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Security Request',
            'contact_name' => 'Security Contact',
            'phone' => '05323333333',
            'email' => 'security-request@example.test',
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
            'status' => TenantSignupRequest::STATUS_CONVERTED,
            'converted_tenant_account_id' => $tenant->id,
            'meta_json' => ['converted_at' => now()->toDateTimeString()],
        ]);

        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Security Admin',
            'email' => 'tenant-security-admin@example.test',
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
            ->get(route('admin.super.signup-requests.index'))
            ->assertForbidden();

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem))
            ->assertForbidden();

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-preview', $requestItem))
            ->assertForbidden();

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-success', $requestItem))
            ->assertForbidden();

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.signup-requests.notes.store', $requestItem), [
                'note' => 'Yetkisiz not',
            ])
            ->assertForbidden();

        auth('web')->logout();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.index'))
            ->assertRedirect(route('login'));

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.show', $requestItem))
            ->assertRedirect(route('login'));

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-preview', $requestItem))
            ->assertRedirect(route('login'));

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.signup-requests.conversion-success', $requestItem))
            ->assertRedirect(route('login'));

        $tenantHost = $this->tenantHost($this->existingTenant);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $tenantHost])
            ->get('http://' . $tenantHost . '/admin/super-admin/signup-requests')
            ->assertForbidden();

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $tenantHost])
            ->get('http://' . $tenantHost . '/admin/super-admin/signup-requests/' . $requestItem->id)
            ->assertForbidden();

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $tenantHost])
            ->get('http://' . $tenantHost . '/admin/super-admin/signup-requests/' . $requestItem->id . '/conversion-preview')
            ->assertForbidden();

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $tenantHost])
            ->get('http://' . $tenantHost . '/admin/super-admin/signup-requests/' . $requestItem->id . '/conversion-success')
            ->assertForbidden();
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }
}
