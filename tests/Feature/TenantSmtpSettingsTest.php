<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSmtpSettingsTest extends TestCase
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

    public function test_smtp_settings_route_and_landing_link_follow_module_and_feature_access(): void
    {
        $this->enableNotificationCenterAccess();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.smtp'));

        $response->assertOk();
        $response->assertSee('Mail Gönderimi');

        $landing = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $landing->assertOk();
        $landing->assertSee(route('admin.settings.notifications.smtp'), false);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'smtp_settings',
            ],
            ['is_enabled' => false]
        );

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.smtp'))
            ->assertForbidden();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->whereNull('feature_key')
            ->update(['is_enabled' => false]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.smtp'))
            ->assertForbidden();

        $hiddenLanding = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $hiddenLanding->assertOk();
        $hiddenLanding->assertDontSee(route('admin.settings.notifications.smtp'), false);
    }

    public function test_smtp_settings_are_saved_encrypted_and_password_is_never_rendered_plain(): void
    {
        $this->enableNotificationCenterAccess();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.settings.notifications.smtp.update'), [
                'smtp_is_active' => '1',
                'smtp_host' => 'smtp.example.test',
                'smtp_port' => '587',
                'smtp_username' => 'notify@example.test',
                'smtp_password' => 'super-secret-pass',
                'smtp_encryption' => 'tls',
                'smtp_from_name' => 'Prodelya Bildirim',
                'smtp_from_email' => 'notify@example.test',
                'smtp_reply_to_email' => 'reply@example.test',
                'smtp_test_email' => 'test@example.test',
            ])
            ->assertRedirect(route('admin.settings.notifications.smtp'));

        $passwordRow = TenantSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('key', 'smtp_password')
            ->firstOrFail();

        $this->assertNotSame('super-secret-pass', $passwordRow->value);
        $this->assertStringStartsWith('enc::', (string) $passwordRow->value);

        $view = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.smtp'));

        $view->assertOk();
        $view->assertSee('Şifre Tanımlı');
        $view->assertDontSee('super-secret-pass', false);
        $view->assertDontSee('value="super-secret-pass"', false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.settings.notifications.smtp.update'), [
                'smtp_is_active' => '1',
                'smtp_host' => 'smtp.changed.test',
                'smtp_port' => '2525',
                'smtp_username' => 'notify@example.test',
                'smtp_password' => '',
                'smtp_encryption' => 'none',
                'smtp_from_name' => 'Yeni Gonderen',
                'smtp_from_email' => 'notify@example.test',
                'smtp_reply_to_email' => 'reply@example.test',
                'smtp_test_email' => 'test@example.test',
            ])
            ->assertRedirect(route('admin.settings.notifications.smtp'));

        $preservedPasswordRow = TenantSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('key', 'smtp_password')
            ->firstOrFail();

        $this->assertSame($passwordRow->value, $preservedPasswordRow->value);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.settings.notifications.smtp.update'), [
                'smtp_is_active' => '1',
                'smtp_host' => 'smtp.changed.test',
                'smtp_port' => '2525',
                'smtp_username' => 'notify@example.test',
                'smtp_password' => 'brand-new-secret',
                'smtp_encryption' => 'ssl',
                'smtp_from_name' => 'Yeni Gonderen',
                'smtp_from_email' => 'notify@example.test',
                'smtp_reply_to_email' => 'reply@example.test',
                'smtp_test_email' => 'test@example.test',
            ])
            ->assertRedirect(route('admin.settings.notifications.smtp'));

        $rotatedPasswordRow = TenantSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('key', 'smtp_password')
            ->firstOrFail();

        $this->assertNotSame($passwordRow->value, $rotatedPasswordRow->value);
    }

    public function test_smtp_settings_validation_rejects_invalid_email_fields(): void
    {
        $this->enableNotificationCenterAccess();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.settings.notifications.smtp'))
            ->put(route('admin.settings.notifications.smtp.update'), [
                'smtp_is_active' => '1',
                'smtp_host' => 'smtp.example.test',
                'smtp_port' => '587',
                'smtp_from_email' => 'not-an-email',
                'smtp_test_email' => 'also-invalid',
            ]);

        $response->assertRedirect(route('admin.settings.notifications.smtp'));
        $response->assertSessionHasErrors(['smtp_from_email', 'smtp_test_email']);
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
                'feature_key' => 'smtp_settings',
            ],
            ['is_enabled' => true]
        );
    }
}
