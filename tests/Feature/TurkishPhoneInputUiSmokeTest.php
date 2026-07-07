<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurkishPhoneInputUiSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->owner = User::query()->create([
            'name' => 'Turkish Phone UI Owner',
            'email' => 'turkish-phone-ui-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'whatsapp_links',
            ],
            ['is_enabled' => true]
        );
    }

    public function test_admin_forms_show_turkey_prefix_and_expected_placeholders_without_asset_errors(): void
    {
        $companyCreate = $this->actingAs($this->owner, 'web')
            ->get('http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . '/admin/companies/create');

        $companyCreate->assertOk()
            ->assertSee('🇹🇷 +90')
            ->assertSee('WhatsApp Cep Telefonu')
            ->assertSee('5xx xxx xx xx');

        $whatsappSettings = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.whatsapp'));

        $whatsappSettings->assertOk()
            ->assertSee('🇹🇷 +90')
            ->assertSee('WhatsApp Cep Telefonu')
            ->assertDontSee('intl-tel-input');
    }
}
