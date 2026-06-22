<?php

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTemplateUiTest extends TestCase
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
            'panel_subdomain' => 'notification-template-guarded',
            'slug' => 'notification-template-guarded',
        ])->save();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();
    }

    public function test_notification_template_list_create_edit_duplicate_preview_and_guard_behaviour(): void
    {
        $this->enableTemplateAccess();

        $list = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.templates.index'));

        $list->assertOk();
        $list->assertSee('Bildirim Şablonları');
        $list->assertSee('Varsayılanları Oluştur / Eksikleri Tamamla');
        $list->assertSee('Teklif Müşteriye Gönderildi');

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.templates.create'));

        $create->assertOk();
        $create->assertSee('Kullanılabilir Değişkenler');
        $create->assertSee('SMS (Pasif)');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.notifications.templates.store'), [
                'notification_key' => 'quote_sent_email',
                'channel' => 'email',
                'audience_type' => 'customer',
                'title' => 'Musteri teklif sablonu',
                'subject' => 'Teklifiniz hazir {{quote_number}}',
                'body' => 'Merhaba {{customer_name}} {{payment_amount}} {{file_path}} {{product_summary}}',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.notifications.templates.index'));

        $template = NotificationTemplate::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'quote_sent_to_customer')
            ->where('channel', 'email')
            ->where('audience_type', 'customer')
            ->firstOrFail();

        $this->assertSame('Musteri teklif sablonu', $template->title);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.notifications.templates.store'), [
                'notification_key' => 'quote_sent_to_customer',
                'channel' => 'email',
                'audience_type' => 'customer',
                'title' => 'Guncellenen override',
                'subject' => 'Yeni subject {{quote_number}}',
                'body' => 'Merhaba {{customer_name}} {{product_summary}}',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.notifications.templates.index'));

        $this->assertSame(
            1,
            NotificationTemplate::query()
                ->where('tenant_account_id', $this->tenant->id)
                ->where('notification_key', 'quote_sent_to_customer')
                ->where('channel', 'email')
                ->where('audience_type', 'customer')
                ->count()
        );

        $template->refresh();
        $this->assertSame('Guncellenen override', $template->title);

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.templates.edit', $template));

        $edit->assertOk();
        $edit->assertSee('Bildirim Şablonunu Düzenle');
        $edit->assertSee('Guncellenen override');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.notifications.templates.update', $template), [
                'notification_key' => 'quote_sent_to_customer',
                'channel' => 'internal',
                'audience_type' => 'internal',
                'title' => 'Ic ekip override',
                'subject' => 'Ic ekip {{order_number}}',
                'body' => 'Durum {{status_label}} {{payment_amount}}',
                'is_active' => '0',
            ])
            ->assertRedirect(route('admin.notifications.templates.index'));

        $template->refresh();
        $this->assertSame('internal', $template->channel);
        $this->assertSame('internal', $template->audience_type);
        $this->assertFalse($template->is_active);

        $invalid = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.notifications.templates.create'))
            ->post(route('admin.notifications.templates.store'), [
                'notification_key' => 'quote_created',
                'channel' => 'whatsapp_link',
                'audience_type' => 'customer',
                'title' => 'Gecersiz',
                'subject' => 'Test',
                'body' => 'Test',
            ]);

        $invalid->assertRedirect(route('admin.notifications.templates.create'));
        $invalid->assertSessionHasErrors(['channel']);

        $smsInvalid = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.notifications.templates.create'))
            ->post(route('admin.notifications.templates.store'), [
                'notification_key' => 'quote_sent_to_customer',
                'channel' => 'sms',
                'audience_type' => 'customer',
                'title' => 'SMS',
                'subject' => 'Test',
                'body' => 'Test',
            ]);

        $smsInvalid->assertRedirect(route('admin.notifications.templates.create'));
        $smsInvalid->assertSessionHasErrors(['channel']);

        $customerPreview = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.notifications.templates.preview'), [
                'notification_key' => 'quote_sent_to_customer',
                'channel' => 'email',
                'audience_type' => 'customer',
                'title' => 'Preview',
                'subject' => 'Musteri {{quote_number}}',
                'body' => 'Merhaba {{customer_name}} {{payment_amount}} {{file_path}} {{group_code}} {{product_summary}}',
                'sample_context' => json_encode([
                    'customer_name' => 'ABC Insaat',
                    'quote_number' => 'TK-2026-0042',
                    'payment_amount' => '1500',
                    'file_path' => 'C:\\secret.pdf',
                    'group_code' => 'SECRET-GROUP',
                    'pdh_raw' => '<xml>hidden</xml>',
                    'product_summary' => 'Logo baskili urun',
                ], JSON_UNESCAPED_UNICODE),
            ]);

        $customerPreview->assertOk();
        $customerPreview->assertSee('Şablon Önizleme');
        $customerPreview->assertSee('ABC Insaat');
        $customerPreview->assertSee('Logo baskili urun');
        $customerPreview->assertDontSee('1500', false);
        $customerPreview->assertDontSee('C:\\secret.pdf', false);
        $customerPreview->assertDontSee('SECRET-GROUP', false);
        $customerPreview->assertDontSee('pdh_raw', false);

        $financePreview = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.notifications.templates.preview'), [
                'notification_key' => 'payment_received',
                'channel' => 'internal',
                'audience_type' => 'finance',
                'title' => 'Preview',
                'subject' => 'Odeme {{order_number}}',
                'body' => 'Tutar {{payment_amount}} {{payment_currency}} / Bakiye {{balance_due}} / {{file_path}}',
                'sample_context' => json_encode([
                    'order_number' => 'SP-2026-0042',
                    'payment_amount' => '1500',
                    'payment_currency' => 'TL',
                    'balance_due' => '750',
                    'file_path' => '/var/secret.txt',
                ], JSON_UNESCAPED_UNICODE),
            ]);

        $financePreview->assertOk();
        $financePreview->assertSee('1500');
        $financePreview->assertSee('750');
        $financePreview->assertDontSee('/var/secret.txt', false);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'notification_templates',
            ],
            ['is_enabled' => false]
        );

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.templates.index'))
            ->assertForbidden();
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
