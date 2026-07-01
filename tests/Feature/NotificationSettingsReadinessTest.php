<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Notifications\NotificationTemplateService;
use App\Services\Notifications\TenantNotificationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $otherTenant;
    private User $platformAdmin;
    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill([
            'panel_subdomain' => $this->tenant->panel_subdomain ?: 'bildirim-tenant',
            'package_key' => 'starter',
        ])->save();

        $this->otherTenant = TenantAccount::query()->create([
            'name' => 'Diger Bildirim Tenant',
            'legal_name' => 'Diger Bildirim Tenant Ltd.',
            'slug' => 'diger-bildirim-tenant',
            'panel_subdomain' => 'diger-bildirim-tenant',
            'status' => 'active',
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantAdmin = $this->createTenantUser($this->tenant, 'admin', 'tenant-bildirim-admin@example.test', 'Tenant Bildirim Admin');

        $this->enableNotificationCenterAccess($this->tenant);
        $this->enableNotificationCenterAccess($this->otherTenant);
    }

    public function test_tenant_settings_landing_shows_notification_readiness_and_masks_credentials(): void
    {
        app(TenantNotificationSettingsService::class)->updateSmtpSettings($this->tenant, [
            'smtp_is_active' => true,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'mailer-account@example.test',
            'smtp_password' => 'super-secret-password',
            'smtp_from_name' => 'Prodelya Bildirim',
            'smtp_from_email' => 'notify@example.test',
            'smtp_reply_to_email' => 'reply@example.test',
            'smtp_test_email' => 'test@example.test',
        ], $this->platformAdmin);

        app(TenantNotificationSettingsService::class)->updateChannelSettings($this->tenant, [
            'whatsapp_is_active' => true,
            'whatsapp_default_country_code' => '90',
            'whatsapp_sender_label' => 'Prodelya',
            'whatsapp_default_signature' => 'Saklimavi Ekibi',
            'whatsapp_test_phone' => '905320000000',
        ], $this->platformAdmin);

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'smtp_test_mail',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_INTERNAL,
            'recipient_type' => 'email',
            'recipient_email' => 'test@example.test',
            'subject' => 'SMTP test',
            'message_preview' => 'Test gonderimi yapildi.',
            'status' => NotificationLog::STATUS_SENT,
            'dispatch_mode' => 'test',
        ]);

        $maskedUsername = app(TenantNotificationSettingsService::class)->readinessSummary($this->tenant)['smtp']['username_masked'];

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $response->assertOk();
        $response->assertSee('Bildirimler');
        $response->assertSee('Bildirim Durumu');
        $response->assertSee('Prodelya Bildirim / notify@example.test');
        $response->assertSee($maskedUsername);
        $response->assertSee('Tanımlı');
        $response->assertSee('WhatsApp telefonu');
        $response->assertSee('Son SMTP testi');
        $response->assertSee('Gönderildi');
        $response->assertDontSee('super-secret-password', false);
        $response->assertDontSee('mailer-account@example.test', false);
    }

    public function test_super_admin_tenant_show_displays_notification_readiness_summary(): void
    {
        app(TenantNotificationSettingsService::class)->updateSmtpSettings($this->tenant, [
            'smtp_is_active' => true,
            'smtp_host' => 'smtp.show.test',
            'smtp_from_email' => 'tenant@example.test',
            'smtp_username' => 'show-user@example.test',
            'smtp_password' => 'show-secret-password',
        ], $this->platformAdmin);

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'whatsapp_manual_link',
            'channel' => NotificationTemplate::CHANNEL_WHATSAPP_LINK,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'recipient_type' => 'phone',
            'recipient_phone' => '905320000000',
            'subject' => 'WhatsApp Hazir Mesaj',
            'message_preview' => 'Mesaj hazirlandi.',
            'status' => NotificationLog::STATUS_LINK_CREATED,
            'dispatch_mode' => 'link',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $this->tenant));

        $response->assertOk();
        $response->assertSee('Bildirim Hazırlığı');
        $response->assertSee('SMTP Host');
        $response->assertSee('smtp.show.test');
        $response->assertSee('Başarısız Kayıt');
    }

    public function test_tenant_admin_cannot_view_other_tenant_notification_log(): void
    {
        $foreignLog = NotificationLog::query()->create([
            'tenant_account_id' => $this->otherTenant->id,
            'notification_key' => 'smtp_test_mail',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_INTERNAL,
            'recipient_type' => 'email',
            'recipient_email' => 'other@example.test',
            'subject' => 'Diger tenant logu',
            'message_preview' => 'Yalniz diger tenant gorebilir.',
            'status' => NotificationLog::STATUS_SENT,
            'dispatch_mode' => 'test',
        ]);

        $this->actingAs($this->tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get('http://' . $this->tenantHost($this->tenant) . '/admin/notifications/logs/' . $foreignLog->id)
            ->assertNotFound();
    }

    public function test_customer_and_finance_template_variable_safety_is_preserved(): void
    {
        $service = app(NotificationTemplateService::class);

        $customerTemplate = new NotificationTemplate([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_customer_approval_requested',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'body' => 'Merhaba {{customer_name}} {{payment_amount}} {{group_code}} {{file_path}}',
        ]);

        $customerValidation = $service->validateTemplateVariables($customerTemplate);
        $customerRendered = $service->render($customerTemplate, [
            'customer_name' => 'ABC Insaat',
            'payment_amount' => '1500',
            'group_code' => 'SECRET-GROUP',
            'file_path' => 'C:\\secret\\proof.pdf',
        ], NotificationTemplate::AUDIENCE_CUSTOMER);

        $this->assertContains('payment_amount', $customerValidation['blocked_variables']);
        $this->assertContains('group_code', $customerValidation['blocked_variables']);
        $this->assertContains('file_path', $customerValidation['blocked_variables']);
        $this->assertStringContainsString('ABC Insaat', $customerRendered['body']);
        $this->assertStringNotContainsString('1500', $customerRendered['body']);
        $this->assertStringNotContainsString('SECRET-GROUP', $customerRendered['body']);
        $this->assertStringNotContainsString('proof.pdf', $customerRendered['body']);

        $financeTemplate = new NotificationTemplate([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'payment_received',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_FINANCE,
            'body' => 'Tahsilat {{payment_amount}} {{payment_currency}} {{group_code}}',
        ]);

        $financeValidation = $service->validateTemplateVariables($financeTemplate);
        $financeRendered = $service->render($financeTemplate, [
            'payment_amount' => '1500',
            'payment_currency' => 'TL',
            'group_code' => 'SECRET-GROUP',
        ], NotificationTemplate::AUDIENCE_FINANCE);

        $this->assertContains('group_code', $financeValidation['blocked_variables']);
        $this->assertStringContainsString('1500', $financeRendered['body']);
        $this->assertStringContainsString('TL', $financeRendered['body']);
        $this->assertStringNotContainsString('SECRET-GROUP', $financeRendered['body']);
    }

    private function enableNotificationCenterAccess(TenantAccount $tenant): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        foreach (['smtp_settings', 'whatsapp_links', 'notification_templates', 'notification_logs'] as $featureKey) {
            TenantModule::query()->updateOrCreate(
                [
                    'tenant_account_id' => $tenant->id,
                    'module_key' => 'notification_center',
                    'feature_key' => $featureKey,
                ],
                ['is_enabled' => true]
            );
        }
    }

    private function createTenantUser(TenantAccount $tenant, string $roleKey, string $email, string $name): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }
}
