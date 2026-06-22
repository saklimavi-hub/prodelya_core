<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TenantSmtpDiagnosticsTest extends TestCase
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

        $this->enableFeature('smtp_settings');
        $this->enableFeature('notification_logs');
    }

    public function test_failed_smtp_test_mail_shows_safe_diagnostic_and_creates_sanitized_log(): void
    {
        $this->seedSmtpSettings([
            'smtp_username' => 'proderp@yandex.com',
            'smtp_from_email' => 'proderp@yandex.com',
        ]);

        Mail::shouldReceive('forgetMailers')->twice();
        Mail::shouldReceive('mailer')->once()->with('tenant_smtp_runtime')->andReturnSelf();
        Mail::shouldReceive('to')->once()->with('test@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(
            new \RuntimeException('Expected response code 535 but got code "535", with message "535 5.7.8 Authentication failed". smtp_password secret-value token abc123')
        );

        $response = $this->from(route('admin.settings.notifications.smtp'))
            ->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.notifications.smtp.test'), []);

        $response->assertRedirect(route('admin.settings.notifications.smtp'));
        $response->assertSessionHas('error', 'Test mail gönderimi başarısız oldu: SMTP kimlik doğrulaması başarısız oldu.');

        $log = NotificationLog::query()->latest('id')->firstOrFail();

        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertSame(NotificationLog::CHANNEL_EMAIL, $log->channel);
        $this->assertSame('smtp_test_mail', $log->notification_key);
        $this->assertSame('SMTP kimlik doğrulaması başarısız oldu.', $log->error_message);
        $this->assertSame('535', $log->response_code);
        $this->assertSame('authentication_failed', $log->meta_json['diagnostic_category'] ?? null);
        $this->assertSame('authentication_failed', $log->provider_response['diagnostic_category'] ?? null);
        $this->assertSame('SMTP kimlik doğrulaması başarısız oldu.', $log->provider_response['summary'] ?? null);
        $this->assertSame('535', $log->provider_response['response_code'] ?? null);
        $this->assertStringNotContainsString('secret-value', json_encode($log->provider_response, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('abc123', json_encode($log->provider_response, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('smtp_password', json_encode($log->provider_response, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('token', json_encode($log->provider_response, JSON_UNESCAPED_UNICODE));

        $detail = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.logs.show', $log));

        $detail->assertOk();
        $detail->assertSee('SMTP kimlik doğrulaması başarısız oldu.');
        $detail->assertDontSee('secret-value', false);
        $detail->assertDontSee('abc123', false);
        $detail->assertDontSee('smtp_password', false);
        $detail->assertDontSee('RuntimeException', false);
    }

    public function test_smtp_settings_page_shows_yandex_guidance_and_username_warning(): void
    {
        $this->seedSmtpSettings([
            'smtp_host' => 'smtp.yandex.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'proderp@yandex.com',
            'smtp_from_email' => 'another-sender@yandex.com',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.smtp'));

        $response->assertOk();
        $response->assertSee('Yandex Mail için önerilen ayarlar');
        $response->assertSee('smtp.yandex.com');
        $response->assertSee('465 / ssl');
        $response->assertSee('587 / tls');
        $response->assertSee('Yandex uygulama parolası');
        $response->assertSee('Bazı sağlayıcılar From Email ile SMTP kullanıcı adının aynı olmasını ister.');
        $response->assertSee('587 portu genelde tls ile kullanılır.');
    }

    private function enableFeature(string $featureKey): void
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
                'feature_key' => $featureKey,
            ],
            ['is_enabled' => true]
        );
    }

    private function seedSmtpSettings(array $overrides = []): void
    {
        $settings = array_merge([
            'smtp_is_active' => true,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'notify@example.test',
            'smtp_password' => 'enc::already-encrypted-placeholder',
            'smtp_encryption' => 'tls',
            'smtp_from_name' => 'Prodelya Bildirim',
            'smtp_from_email' => 'notify@example.test',
            'smtp_reply_to_email' => 'reply@example.test',
            'smtp_test_email' => 'test@example.test',
        ], $overrides);

        foreach ($settings as $key => $value) {
            $type = match ($key) {
                'smtp_is_active' => 'boolean',
                'smtp_port' => 'integer',
                default => 'string',
            };

            TenantSetting::setValue($this->tenant->id, $key, $value, $type);
        }
    }
}
