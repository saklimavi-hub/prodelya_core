<?php

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\Notifications\NotificationTemplateDefaultSeederService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationDefaultTemplatesAndTemplateUxTest extends TestCase
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
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'notification-default-template-ux',
            'slug' => 'notification-default-template-ux',
        ])->save();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();
    }

    public function test_default_templates_sync_and_template_ux_work_safely(): void
    {
        $this->enableTemplateAccess();

        $seeded = NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => 'email',
            'audience_type' => 'customer',
            'title' => 'Özel tenant şablonu',
            'subject' => 'Özel {{quote_number}}',
            'body' => 'Merhaba {{customer_name}}',
            'is_active' => false,
            'created_by' => $this->adminUser->id,
        ]);

        $service = app(NotificationTemplateDefaultSeederService::class);
        $firstSync = $service->syncTenantDefaultTemplates($this->tenant);
        $secondSync = $service->syncTenantDefaultTemplates($this->tenant);

        $this->assertGreaterThan(0, $firstSync['created_count']);
        $this->assertSame(0, $secondSync['created_count']);

        $seeded->refresh();
        $this->assertSame('Özel tenant şablonu', $seeded->title);
        $this->assertFalse($seeded->is_active);

        $this->assertSame(
            1,
            NotificationTemplate::query()
                ->where('tenant_account_id', $this->tenant->id)
                ->where('notification_key', 'quote_sent_to_customer')
                ->where('channel', 'email')
                ->where('audience_type', 'customer')
                ->count()
        );

        $quoteTemplate = NotificationTemplate::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'quote_sent_to_customer')
            ->where('channel', 'email')
            ->where('audience_type', 'customer')
            ->firstOrFail();

        $graphicWhatsapp = NotificationTemplate::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'graphic_customer_approval_requested')
            ->where('channel', 'whatsapp_link')
            ->where('audience_type', 'customer')
            ->firstOrFail();

        $deliveryEmail = NotificationTemplate::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'delivery_completed')
            ->where('channel', 'email')
            ->where('audience_type', 'customer')
            ->firstOrFail();

        $deliveryWhatsapp = NotificationTemplate::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'delivery_completed')
            ->where('channel', 'whatsapp_link')
            ->where('audience_type', 'customer')
            ->firstOrFail();

        $paymentTemplate = NotificationTemplate::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'payment_received')
            ->where('audience_type', 'finance')
            ->firstOrFail();

        $this->assertNotNull($quoteTemplate);
        $this->assertNotNull($graphicWhatsapp);
        $this->assertNotNull($deliveryEmail);
        $this->assertNotNull($deliveryWhatsapp);
        $this->assertNotNull($paymentTemplate);

        $this->assertStringNotContainsString('payment_amount', (string) $deliveryEmail->body);
        $this->assertStringNotContainsString('balance_due', (string) $quoteTemplate->body);
        $this->assertStringNotContainsString('file_path', (string) $quoteTemplate->body);
        $this->assertStringNotContainsString('group_code', (string) $quoteTemplate->body);
        $this->assertStringNotContainsString('pdh_raw', (string) $quoteTemplate->body);

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.templates.index'));

        $index->assertOk();
        $index->assertSee('Olay Grubu');
        $index->assertSee('Olay Adı');
        $index->assertSee('Kime');
        $index->assertSee('Kaynak');
        $index->assertSee('Teklif');
        $index->assertSee('Grafik');
        $index->assertSee('Tedarik');
        $index->assertSee('Üretim');
        $index->assertSee('Teslimat');
        $index->assertSee('Finans');
        $index->assertSee('Varsayılanları Oluştur / Eksikleri Tamamla');

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.templates.create'));

        $create->assertOk();
        $create->assertSee('Olay');
        $create->assertSee('Kanal');
        $create->assertSee('Kime Gönderilecek');
        $create->assertSee('Başlık');
        $create->assertSee('Mesaj İçeriği');
        $create->assertSee('Kullanılabilir Değişkenler');
        $create->assertSee('{{customer_name}}');
        $create->assertSee('{{public_quote_approval_url}}');
        $create->assertDontSee('smtp_password', false);
        $create->assertDontSee('api_key', false);
        $create->assertDontSee('raw token', false);

        $preview = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.notifications.templates.preview'), [
                'notification_key' => 'quote_sent_to_customer',
                'channel' => 'email',
                'audience_type' => 'customer',
                'title' => 'Önizleme',
                'subject' => 'Teklif {{quote_number}}',
                'body' => 'Merhaba {{customer_name}} {{payment_amount}} {{file_path}} {{public_quote_approval_url}}',
                'sample_context' => json_encode([
                    'customer_name' => 'ABC İnşaat',
                    'quote_number' => 'TK-2026-0042',
                    'payment_amount' => '1500',
                    'file_path' => 'C:\\secret.pdf',
                    'public_quote_approval_url' => 'https://prodelya.test/teklif/onay/abc123',
                ], JSON_UNESCAPED_UNICODE),
            ]);

        $preview->assertOk();
        $preview->assertSee('Şablon Önizleme');
        $preview->assertSee('ABC İnşaat');
        $preview->assertSee('https://prodelya.test/teklif/onay/abc123');
        $preview->assertDontSee('1500', false);
        $preview->assertDontSee('C:\\secret.pdf', false);

        NotificationTemplate::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('created_by', null)
            ->delete();

        $syncAction = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.notifications.templates.sync-defaults'));

        $syncAction->assertRedirect(route('admin.notifications.templates.index'));
        $syncAction->assertSessionHas('success');

        $this->assertTrue(
            NotificationTemplate::query()
                ->where('tenant_account_id', $this->tenant->id)
                ->where('notification_key', 'delivery_completed')
                ->where('channel', 'whatsapp_link')
                ->exists()
        );
    }

    private function enableTemplateAccess(): void
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
                'feature_key' => 'notification_templates',
            ],
            ['is_enabled' => true]
        );
    }
}
