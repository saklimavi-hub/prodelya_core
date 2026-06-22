<?php

namespace Tests\Feature;

use App\Mail\TenantSmtpTestMail;
use App\Models\NotificationLog;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TenantSmtpTestMailTest extends TestCase
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

        $this->enableNotificationCenterAccess();
    }

    public function test_test_mail_requires_active_smtp_and_target_email(): void
    {
        $inactive = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.settings.notifications.smtp'))
            ->post(route('admin.settings.notifications.smtp.test'), []);

        $inactive->assertRedirect(route('admin.settings.notifications.smtp'));
        $inactive->assertSessionHasErrors(['smtp_is_active']);

        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');

        $missingEmail = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.settings.notifications.smtp'))
            ->post(route('admin.settings.notifications.smtp.test'), []);

        $missingEmail->assertRedirect(route('admin.settings.notifications.smtp'));
        $missingEmail->assertSessionHasErrors(['smtp_test_email']);
    }

    public function test_successful_test_mail_is_sent_and_logged(): void
    {
        $this->seedSmtpSettings();
        Mail::fake();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.notifications.smtp.test'), []);

        $response->assertRedirect(route('admin.settings.notifications.smtp'));
        $response->assertSessionHas('success');

        Mail::assertSent(TenantSmtpTestMail::class, function (TenantSmtpTestMail $mail): bool {
            return $mail->hasTo('test@example.test');
        });

        $log = NotificationLog::query()->latest('id')->firstOrFail();
        $this->assertSame('smtp_test_mail', $log->notification_key);
        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertSame('test@example.test', $log->recipient_email);
        $this->assertSame('Prodelya SMTP Test Maili', $log->subject);
    }

    public function test_failed_test_mail_is_logged_with_sanitized_error_message(): void
    {
        $this->seedSmtpSettings();

        Mail::shouldReceive('forgetMailers')->twice();
        Mail::shouldReceive('mailer')->once()->with('tenant_smtp_runtime')->andReturnSelf();
        Mail::shouldReceive('to')->once()->with('test@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP auth failed with smtp_password super-secret token abc'));

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.notifications.smtp.test'), []);

        $response->assertRedirect(route('admin.settings.notifications.smtp'));
        $response->assertSessionHas('error');

        $log = NotificationLog::query()->latest('id')->firstOrFail();
        $this->assertSame('smtp_test_mail', $log->notification_key);
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertSame('SMTP kimlik doğrulaması başarısız oldu.', $log->error_message);
        $this->assertStringNotContainsString('super-secret', (string) $log->error_message);
        $this->assertStringNotContainsString('smtp_password', (string) $log->error_message);
        $this->assertStringNotContainsString('token', (string) $log->error_message);
    }

    public function test_public_tracking_surface_does_not_expose_smtp_test_logs(): void
    {
        $this->seedSmtpSettings();
        Mail::fake();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.notifications.smtp.test'), []);

        $this->get(route('public.work-forms.track', ['token' => 'missing-token']))
            ->assertNotFound();

        $log = NotificationLog::query()->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('smtp_password', (string) $log->message_preview);
    }

    private function seedSmtpSettings(): void
    {
        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'smtp_host', 'smtp.example.test', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_port', 587, 'integer');
        TenantSetting::setValue($this->tenant->id, 'smtp_username', 'notify@example.test', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_password', 'enc::already-encrypted-placeholder', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_encryption', 'tls', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_from_name', 'Prodelya Bildirim', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_from_email', 'notify@example.test', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_reply_to_email', 'reply@example.test', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_test_email', 'test@example.test', 'string');
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
