<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $this->tenant->forceFill(['package_key' => 'starter'])->save();
        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();
    }

    public function test_whatsapp_settings_route_and_landing_link_follow_module_and_feature_access(): void
    {
        $this->enableNotificationCenterAccess();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.whatsapp'));

        $response->assertOk();
        $response->assertSee('WhatsApp Hazır Mesaj');

        $landing = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $landing->assertOk();
        $landing->assertSee(route('admin.settings.notifications.whatsapp'), false);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'whatsapp_links',
            ],
            ['is_enabled' => false]
        );

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.whatsapp'))
            ->assertForbidden();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->whereNull('feature_key')
            ->update(['is_enabled' => false]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.whatsapp'))
            ->assertForbidden();

        $hiddenLanding = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $hiddenLanding->assertOk();
        $hiddenLanding->assertDontSee(route('admin.settings.notifications.whatsapp'), false);
    }

    public function test_whatsapp_settings_are_saved_with_normalized_country_code_and_phone(): void
    {
        $this->enableNotificationCenterAccess();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.settings.notifications.whatsapp.update'), [
                'whatsapp_is_active' => '1',
                'whatsapp_default_country_code' => '+90',
                'whatsapp_sender_label' => 'Prodelya',
                'whatsapp_default_signature' => 'Saklımavi Ekibi',
                'whatsapp_test_phone' => '0532 000 00 00',
            ])
            ->assertRedirect(route('admin.settings.notifications.whatsapp'));

        $this->assertSame(
            '90',
            TenantSetting::getValue($this->tenant->id, 'whatsapp_default_country_code')
        );
        $this->assertSame(
            'Prodelya',
            TenantSetting::getValue($this->tenant->id, 'whatsapp_sender_label')
        );
        $this->assertSame(
            'Saklımavi Ekibi',
            TenantSetting::getValue($this->tenant->id, 'whatsapp_default_signature')
        );
        $this->assertSame(
            '905320000000',
            TenantSetting::getValue($this->tenant->id, 'whatsapp_test_phone')
        );
    }

    private function enableNotificationCenterAccess(): void
    {
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
}
