<?php

namespace Tests\Feature\ProcessDepth;

use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ProcessDepth\TenantProcessDepthResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSettingsProcessDepthUiTest extends TestCase
{
    use RefreshDatabase;
    protected bool $seed = true;



    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private TenantAccount $tenant;
    private TenantAccount $otherTenant;
    private User $owner;
    private User $otherOwner;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();

        $this->tenant = $this->createTenant('process-depth-main');
        $this->otherTenant = $this->createTenant('process-depth-other');
        $this->owner = $this->createOwner($this->tenant, 'pd-owner@example.test');
        $this->otherOwner = $this->createOwner($this->otherTenant, 'pd-owner-other@example.test');
        $this->operator = $this->createOperator($this->tenant, 'pd-operator@example.test');

        $this->enableTenantSettings($this->tenant);
        $this->enableTenantSettings($this->otherTenant);
    }

    public function test_settings_landing_shows_process_depth_card_for_authorized_user_and_hides_it_from_operator(): void
    {
        $ownerResponse = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/settings'));

        $ownerResponse->assertOk()
            ->assertSee('Süreç Derinliği')
            ->assertSee('Etkin çalışma şekli')
            ->assertSee('Paket varsayılanı')
            ->assertSee('Ayarı Aç')
            ->assertSee('Bu ayar modül erişimini veya kullanıcı yetkilerini değiştirmez.');

        $operatorResponse = $this->actingAs($this->operator, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/settings'));

        $operatorResponse->assertOk()
            ->assertDontSee('Ayarı Aç')
            ->assertDontSee('Süreç Derinliği');
    }

    public function test_owner_can_open_process_depth_settings_page_with_correct_turkish_text_and_put_contract(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/settings/process-depth'));

        $response->assertOk()
            ->assertSee('Süreç Derinliği')
            ->assertSee('Seçilen çalışma şeklinin teklif, sipariş ve operasyon ekranlarındaki ayrıntı seviyesine etkisini belirler.')
            ->assertSee('Paket varsayılanını kullan')
            ->assertSee('Hızlı Akış')
            ->assertSee('Standart Akış')
            ->assertSee('Kontrollü Akış')
            ->assertSee('Bu ayar modül erişimini veya kullanıcı yetkilerini değiştirmez.')
            ->assertSee('action="' . route('admin.settings.process-depth.update') . '"', false)
            ->assertSee('name="_method" value="PUT"', false);

        $this->assertBrokenTurkishPatternsAreAbsent($response->getContent());
    }

    public function test_owner_can_save_each_process_depth_and_repeated_save_does_not_create_duplicates(): void
    {
        $beforeModuleCount = TenantModule::query()->where('tenant_account_id', $this->tenant->id)->count();
        $beforeManagePermission = $this->owner->hasPermissionInTenant('manage_users', $this->tenant->id);
        $beforeFinancePermission = $this->owner->canViewFinancialData($this->tenant->id);

        foreach (['fast', 'standard', 'controlled'] as $depth) {
            $this->actingAs($this->owner, 'web')
                ->put($this->tenantUrl($this->tenant, '/admin/settings/process-depth'), [
                    'process_depth_selection' => $depth,
                ])
                ->assertRedirect(route('admin.settings.process-depth'));

            $this->assertSame($depth, TenantSetting::getValue($this->tenant->id, 'process_depth'));
            $this->assertSame(1, TenantSetting::query()->where('tenant_account_id', $this->tenant->id)->where('key', 'process_depth')->count());
            $this->assertSame($beforeModuleCount, TenantModule::query()->where('tenant_account_id', $this->tenant->id)->count());
            $this->assertSame($beforeManagePermission, $this->owner->hasPermissionInTenant('manage_users', $this->tenant->id));
            $this->assertSame($beforeFinancePermission, $this->owner->canViewFinancialData($this->tenant->id));
        }

        $reload = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/settings/process-depth'));

        $reload->assertOk()
            ->assertSee('Kontrollü Akış')
            ->assertSee('Abone Firma tercihi');
    }

    public function test_inherit_removes_override_and_effective_value_returns_to_package_default(): void
    {
        TenantSetting::setValue($this->tenant->id, 'process_depth', 'fast', 'string');

        $this->actingAs($this->owner, 'web')
            ->put($this->tenantUrl($this->tenant, '/admin/settings/process-depth'), [
                'process_depth_selection' => 'inherit',
            ])
            ->assertRedirect(route('admin.settings.process-depth'));

        $this->assertNull(TenantSetting::getValue($this->tenant->id, 'process_depth'));
        $this->assertSame(0, TenantSetting::query()->where('tenant_account_id', $this->tenant->id)->where('key', 'process_depth')->count());

        $resolved = app(TenantProcessDepthResolver::class)->resolve($this->tenant->fresh());
        $this->assertSame('standard', $resolved['key']);
        $this->assertSame('Paket varsayılanı', $resolved['source_label']);

        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/settings/process-depth'));

        $response->assertOk()
            ->assertSee('Paket varsayılanı')
            ->assertSee('Standart Akış');
    }

    public function test_invalid_input_preserves_existing_value_and_post_update_is_not_allowed(): void
    {
        TenantSetting::setValue($this->tenant->id, 'process_depth', 'fast', 'string');

        $this->actingAs($this->owner, 'web')
            ->from($this->tenantUrl($this->tenant, '/admin/settings/process-depth'))
            ->put($this->tenantUrl($this->tenant, '/admin/settings/process-depth'), [
                'process_depth_selection' => 'bad-value',
            ])
            ->assertRedirect($this->tenantUrl($this->tenant, '/admin/settings/process-depth'))
            ->assertSessionHasErrors(['process_depth_selection']);

        $this->assertSame('fast', TenantSetting::getValue($this->tenant->id, 'process_depth'));

        $this->actingAs($this->owner, 'web')
            ->post($this->tenantUrl($this->tenant, '/admin/settings/process-depth'), [
                'process_depth_selection' => 'controlled',
            ])
            ->assertMethodNotAllowed();
    }

    public function test_operator_platform_admin_and_foreign_tenant_owner_cannot_access_process_depth_settings(): void
    {
        $this->actingAs($this->operator, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/settings/process-depth'))
            ->assertForbidden();

        $this->actingAs($this->platformAdmin, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/settings/process-depth'))
            ->assertForbidden();

        $this->actingAs($this->otherOwner, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/settings/process-depth'))
            ->assertForbidden();
    }

    private function createTenant(string $subdomain): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Process Depth ' . $subdomain,
            'legal_name' => 'Process Depth ' . $subdomain . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createOwner(TenantAccount $tenant, string $email): User
    {
        $owner = User::query()->create([
            'name' => 'Tenant Owner',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        return $owner;
    }

    private function createOperator(TenantAccount $tenant, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Tenant Operator',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        $role = Role::query()->create([
            'tenant_account_id' => $tenant->id,
            'key' => 'process-depth-operator',
            'name' => 'Process Depth Operator',
            'permissions' => [],
            'is_active' => true,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function enableTenantSettings(TenantAccount $tenant): void
    {
        TenantModule::query()->updateOrCreate([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'tenant_settings',
            'feature_key' => null,
        ], [
            'is_enabled' => true,
        ]);
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }

    private function assertBrokenTurkishPatternsAreAbsent(string $html): void
    {
        foreach (['SüreÃ§', 'AyarlarÄ±', 'VarsayÄ±lan', 'Abone Firma tercihi', '�', 'Ã'] as $pattern) {
            if (in_array($pattern, ['Abone Firma tercihi'], true)) {
                continue;
            }

            $this->assertStringNotContainsString($pattern, $html);
        }
    }
}
