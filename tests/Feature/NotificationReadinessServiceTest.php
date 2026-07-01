<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\SuperAdmin\NotificationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
    }

    public function test_notification_readiness_service_builds_main_context_without_secret_leakage(): void
    {
        $tenant = $this->createTenant('smtp-hazir');
        TenantSetting::setValue($tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($tenant->id, 'smtp_host', 'smtp.example.test', 'string');
        TenantSetting::setValue($tenant->id, 'smtp_port', 587, 'integer');
        TenantSetting::setValue($tenant->id, 'smtp_username', 'notify@example.test', 'string');
        TenantSetting::setValue($tenant->id, 'smtp_password', 'enc::already-encrypted-placeholder', 'string');
        TenantSetting::setValue($tenant->id, 'smtp_from_email', 'notify@example.test', 'string');
        TenantSetting::setValue($tenant->id, 'smtp_from_name', 'Prodelya Bildirim', 'string');
        TenantSetting::setValue($tenant->id, 'whatsapp_is_active', true, 'boolean');
        TenantSetting::setValue($tenant->id, 'whatsapp_test_phone', '905551112233', 'string');

        NotificationLog::query()->create([
            'tenant_account_id' => $tenant->id,
            'notification_key' => 'smtp_test_mail',
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_INTERNAL,
            'recipient_type' => 'email',
            'recipient_email' => 'test@example.test',
            'subject' => 'SMTP test',
            'message_preview' => 'smtp_password token api_key secret-value',
            'status' => NotificationLog::STATUS_FAILED,
            'error_message' => 'smtp_password token api_key secret-value',
            'provider_response' => ['token' => 'abc', 'smtp_password' => 'hidden'],
            'meta_json' => ['group_code' => 'PDH-01', 'profit' => 12],
            'created_by' => $this->platformAdmin->id,
        ]);

        NotificationTemplate::query()->create([
            'tenant_account_id' => $tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'title' => 'Müşteri maili',
            'subject' => 'Teklifiniz hazır',
            'body' => 'Merhaba {{customer_name}}, {{supplier_cost}}',
            'is_active' => true,
            'variables_json' => [],
            'created_by' => $this->platformAdmin->id,
        ]);

        config([
            'app.env' => 'production',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.encryption' => 'tls',
            'mail.mailers.smtp.username' => 'notify@example.test',
            'mail.from.address' => 'notify@example.test',
            'mail.from.name' => 'Prodelya Bildirim',
            'queue.default' => 'database',
        ]);

        $context = app(NotificationReadinessService::class)->buildReadinessContext();
        $json = strtolower((string) json_encode($context, JSON_UNESCAPED_UNICODE));

        $this->assertArrayHasKey('mail_environment', $context);
        $this->assertArrayHasKey('tenant_smtp_summary', $context);
        $this->assertArrayHasKey('notification_templates', $context);
        $this->assertArrayHasKey('notification_logs', $context);
        $this->assertArrayHasKey('whatsapp_links', $context);
        $this->assertArrayHasKey('queue_dependency', $context);
        $this->assertArrayHasKey('warnings', $context);
        $this->assertArrayHasKey('checklist', $context);
        $this->assertStringNotContainsString('smtp_password', $json);
        $this->assertStringNotContainsString('api_key', $json);
        $this->assertStringNotContainsString('group_code', $json);
        $this->assertStringNotContainsString('profit', $json);
        $this->assertStringNotContainsString('token', $json);
    }

    public function test_mailer_log_and_missing_secure_fields_raise_warning(): void
    {
        config([
            'app.env' => 'production',
            'mail.default' => 'log',
            'mail.from.address' => null,
            'mail.from.name' => null,
        ]);

        $context = app(NotificationReadinessService::class)->buildReadinessContext();

        $this->assertContains($context['mail_environment']['status'], ['warning', 'critical']);
    }

    public function test_tenant_smtp_summary_builds_rows_and_keeps_password_safe(): void
    {
        $tenant = $this->createTenant('smtp-eksik');
        TenantSetting::setValue($tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($tenant->id, 'smtp_host', 'smtp.example.test', 'string');
        TenantSetting::setValue($tenant->id, 'smtp_password', 'enc::already-encrypted-placeholder', 'string');

        $summary = app(NotificationReadinessService::class)->buildReadinessContext()['tenant_smtp_summary'];
        $row = collect($summary['rows'])->firstWhere('tenant', $tenant->name);
        $json = strtolower((string) json_encode($summary, JSON_UNESCAPED_UNICODE));

        $this->assertNotNull($row);
        $this->assertTrue(array_key_exists('password_configured', $row));
        $this->assertStringNotContainsString('smtp_password', $json);
        $this->assertStringNotContainsString('already-encrypted-placeholder', $json);
    }

    public function test_notification_logs_summary_exposes_failed_counts_without_raw_payload(): void
    {
        $tenant = $this->createTenant('log-tenant');

        NotificationLog::query()->create([
            'tenant_account_id' => $tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'recipient_type' => 'email',
            'recipient_email' => 'musteri@example.test',
            'subject' => 'Mail',
            'message_preview' => 'raw payload smtp_password token',
            'status' => NotificationLog::STATUS_FAILED,
            'error_message' => 'smtp_password token secret',
            'provider_response' => ['smtp_password' => 'demo', 'token' => 'abc'],
            'meta_json' => ['file_path' => '/tmp/private', 'supplier_cost' => 200],
            'created_by' => $this->platformAdmin->id,
        ]);

        $summary = app(NotificationReadinessService::class)->buildReadinessContext()['notification_logs'];
        $json = strtolower((string) json_encode($summary, JSON_UNESCAPED_UNICODE));

        $this->assertSame(1, $summary['counts']['failed']);
        $this->assertStringNotContainsString('smtp_password', $json);
        $this->assertStringNotContainsString('token', $json);
        $this->assertStringNotContainsString('supplier_cost', $json);
        $this->assertStringNotContainsString('file_path', $json);
    }

    public function test_whatsapp_readiness_explains_link_mode_and_sanitizes_preview(): void
    {
        $summary = app(NotificationReadinessService::class)->buildReadinessContext()['whatsapp_links'];
        $preview = strtolower((string) ($summary['sanitized_preview'] ?? ''));

        $this->assertStringContainsString('link tabanlı', strtolower($summary['description']));
        $this->assertStringNotContainsString('token', $preview);
        $this->assertStringNotContainsString('api_key', $preview);
        $this->assertStringNotContainsString('group_code', $preview);
        $this->assertStringNotContainsString('supplier_cost', $preview);
        $this->assertStringNotContainsString('profit', $preview);
        $this->assertStringNotContainsString('file_path', $preview);
    }

    public function test_dashboard_renders_notification_readiness_warning_and_checklist_contains_smtp_notes(): void
    {
        config([
            'app.env' => 'production',
            'mail.default' => 'log',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('SMTP / Bildirim Hazırlığı');
        $response->assertSee('MAIL_MAILER log modunda');

        $content = file_get_contents(base_path('docs/production-go-live-checklist.md'));
        $this->assertIsString($content);
        $this->assertStringContainsString('`MAIL_MAILER` canlıda `smtp` mi?', $content);
        $this->assertStringContainsString('SPF / DKIM / DMARC', $content);
        $this->assertStringContainsString('WhatsApp link metinleri müşteri-facing güvenli mi?', $content);
    }

    private function createTenant(string $slug): TenantAccount
    {
        $tenant = TenantAccount::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'legal_name' => ucfirst(str_replace('-', ' ', $slug)) . ' Ltd.',
            'slug' => $slug,
            'panel_subdomain' => $slug,
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $user = User::query()->create([
            'name' => $tenant->name . ' Owner',
            'email' => $slug . '@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        return $tenant;
    }
}
