<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AdminMenuService;
use App\Services\DeliveryCreationService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSecurityHardeningTest extends TestCase
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
            'panel_subdomain' => 'notification-security-guarded',
            'slug' => 'notification-security-guarded',
        ])->save();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $this->enableFeature('smtp_settings');
        $this->enableFeature('whatsapp_links');
        $this->enableFeature('notification_templates');
        $this->enableFeature('notification_logs');
    }

    public function test_preview_log_scope_and_public_visibility_are_hardened(): void
    {
        TenantSetting::setValue($this->tenant->id, 'smtp_host', 'smtp.tenant-a.test');
        TenantSetting::setValue($this->tenant->id, 'whatsapp_sender_label', 'Tenant A Label');

        $template = NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'payment_received',
            'channel' => 'internal',
            'audience_type' => 'finance',
            'title' => 'Tenant A Template',
            'subject' => 'Finance {{payment_amount}}',
            'body' => 'Balance {{balance_due}}',
        ]);

        $log = NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'payment_received',
            'channel' => 'internal',
            'audience_type' => 'finance',
            'recipient_name' => 'Finance Team',
            'recipient_email' => 'finance@example.test',
            'subject' => 'smtp_password hidden api_key secret',
            'message_preview' => 'payment_amount 1500 file_path C:\\secret token hidden pdh_raw raw_json group_code test',
            'status' => NotificationLog::STATUS_FAILED,
            'error_message' => 'smtp_password very-secret group_code hidden',
            'provider_response' => ['api_key' => 'secret', 'safe' => 'ok'],
            'meta_json' => ['file_path' => 'C:\\secret.txt', 'safe' => 'ok'],
            'created_by' => $this->adminUser->id,
        ]);

        $customerPreview = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.notifications.templates.preview'), [
                'notification_key' => 'quote_sent_to_customer',
                'channel' => 'email',
                'audience_type' => 'customer',
                'title' => 'Customer',
                'subject' => 'Quote {{quote_number}}',
                'body' => 'Merhaba {{customer_name}} {{payment_amount}} {{balance_due}} {{cost}} {{profit}} {{file_path}} {{group_code}} {{pdh_raw}} {{product_summary}}',
                'sample_context' => json_encode([
                    'customer_name' => 'ABC Insaat',
                    'quote_number' => 'TK-2026-0042',
                    'payment_amount' => '1500',
                    'balance_due' => '700',
                    'cost' => '350',
                    'profit' => '120',
                    'file_path' => 'C:\\secret.pdf',
                    'group_code' => 'SECRET-GROUP',
                    'pdh_raw' => '<xml>hidden</xml>',
                    'product_summary' => 'Logo baskili urun',
                ], JSON_UNESCAPED_UNICODE),
            ]);

        $customerPreview->assertOk();
        $customerPreview->assertSee('ABC Insaat');
        $customerPreview->assertSee('Logo baskili urun');
        $customerPreview->assertDontSee('C:\\secret.pdf', false);
        $customerPreview->assertDontSee('SECRET-GROUP', false);

        $internalPreview = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.notifications.templates.preview'), [
                'notification_key' => 'production_completed',
                'channel' => 'internal',
                'audience_type' => 'internal',
                'title' => 'Internal',
                'subject' => 'Order {{order_number}}',
                'body' => 'Durum {{status_label}} {{payment_amount}} {{profit}} {{cost}} {{product_summary}}',
                'sample_context' => json_encode([
                    'order_number' => 'SP-2026-0042',
                    'status_label' => 'Tamamlandi',
                    'payment_amount' => '1500',
                    'profit' => '300',
                    'cost' => '900',
                    'product_summary' => 'Ic ekip urunu',
                ], JSON_UNESCAPED_UNICODE),
            ]);

        $internalPreview->assertOk();
        $internalPreview->assertSee('Ic ekip urunu');
        $internalPreview->assertSee('Tamamlandi');

        $financePreview = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.notifications.templates.preview'), [
                'notification_key' => 'payment_received',
                'channel' => 'internal',
                'audience_type' => 'finance',
                'title' => 'Finance',
                'subject' => 'Payment {{order_number}}',
                'body' => 'Tutar {{payment_amount}} {{payment_currency}} / {{paid_total}} / {{balance_due}} / {{file_path}}',
                'sample_context' => json_encode([
                    'order_number' => 'SP-2026-0042',
                    'payment_amount' => '1500',
                    'payment_currency' => 'TL',
                    'paid_total' => '4500',
                    'balance_due' => '700',
                    'file_path' => '/var/secret.txt',
                ], JSON_UNESCAPED_UNICODE),
            ]);

        $financePreview->assertOk();
        $financePreview->assertSee('1500');
        $financePreview->assertSee('4500');
        $financePreview->assertSee('700');
        $financePreview->assertDontSee('/var/secret.txt', false);

        $logDetail = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.logs.show', $log));

        $logDetail->assertOk();
        $logDetail->assertDontSee('smtp_password', false);
        $logDetail->assertDontSee('very-secret', false);
        $logDetail->assertDontSee('api_key', false);
        $logDetail->assertDontSee('file_path', false);
        $logDetail->assertDontSee('C:\\secret', false);
        $logDetail->assertDontSee('group_code', false);
        $logDetail->assertDontSee('pdh_raw', false);
        $logDetail->assertDontSee('raw_json', false);

        $otherTenant = $this->createOtherTenant();
        foreach (['smtp_settings', 'whatsapp_links', 'notification_templates', 'notification_logs'] as $featureKey) {
            TenantModule::query()->updateOrCreate(
                [
                    'tenant_account_id' => $otherTenant->id,
                    'module_key' => 'notification_center',
                    'feature_key' => $featureKey,
                ],
                ['is_enabled' => true]
            );
        }

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $otherTenant->id,
                'module_key' => 'notification_center',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($otherTenant->id, 'smtp_host', 'smtp.tenant-b.test');
        TenantSetting::setValue($otherTenant->id, 'whatsapp_sender_label', 'Tenant B Label');

        NotificationLog::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => 'internal',
            'audience_type' => 'internal',
            'recipient_name' => 'Tenant B Team',
            'status' => NotificationLog::STATUS_SENT,
            'created_by' => $this->adminUser->id,
        ]);

        NotificationTemplate::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => 'email',
            'audience_type' => 'customer',
            'title' => 'Tenant B Template',
            'subject' => 'Tenant B Subject',
            'body' => 'Tenant B Body',
        ]);

        $this->actingAs($this->adminUser)
            ->get('http://tenant-b.prodelya.test/admin/notifications/logs/' . $log->id)
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->get('http://tenant-b.prodelya.test/admin/notifications/templates/' . $template->id . '/edit')
            ->assertForbidden();

        $smtpPage = $this->actingAs($this->adminUser)
            ->get('http://tenant-b.prodelya.test/admin/settings/notifications/smtp');

        $smtpPage->assertForbidden();
        $smtpPage->assertDontSee('smtp.tenant-b.test');
        $smtpPage->assertDontSee('smtp.tenant-a.test');

        $whatsappPage = $this->actingAs($this->adminUser)
            ->get('http://tenant-b.prodelya.test/admin/settings/notifications/whatsapp');

        $whatsappPage->assertForbidden();
        $whatsappPage->assertDontSee('Tenant B Label');
        $whatsappPage->assertDontSee('Tenant A Label');

        $workForm = $this->createWorkForm();

        $public = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $public->assertOk();
        $public->assertDontSee(route('admin.notifications.logs.index'), false);
        $public->assertDontSee('Bildirim Gecmisi');
        $public->assertDontSee('payment_amount', false);
        $public->assertDontSee('Cari Hareket');
        $public->assertDontSee('Ödeme bekliyor');
        $public->assertDontSee('Bakiye var');
    }

    public function test_notification_feature_guards_and_menu_visibility_follow_access_rules(): void
    {
        $menuService = app(AdminMenuService::class);

        $initialMenuLabels = collect($menuService->tenantMenu($this->tenant, $this->adminUser))
            ->flatMap(function (array $group) {
                return collect($group['children'] ?? [])->pluck('label');
            })
            ->values()
            ->all();

        $this->assertContains('Sistem Ayarları', $initialMenuLabels);

        $settings = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $settings->assertOk();
        $settings->assertSee(route('admin.settings.notifications.smtp'), false);
        $settings->assertSee(route('admin.settings.notifications.whatsapp'), false);
        $settings->assertSee(route('admin.notifications.templates.index'), false);
        $settings->assertSee(route('admin.notifications.logs.index'), false);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'notification_templates',
            ],
            ['is_enabled' => false]
        );
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'notification_logs',
            ],
            ['is_enabled' => false]
        );
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'smtp_settings',
            ],
            ['is_enabled' => false]
        );
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
            ->get(route('admin.notifications.templates.index'))
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.logs.index'))
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.notifications.smtp'))
            ->assertForbidden();

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
            ->get(route('admin.notifications.index'))
            ->assertForbidden();

        $settingsAfter = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $settingsAfter->assertOk();
        $settingsAfter->assertDontSee(route('admin.settings.notifications.smtp'), false);
        $settingsAfter->assertDontSee(route('admin.settings.notifications.whatsapp'), false);
        $settingsAfter->assertDontSee(route('admin.notifications.templates.index'), false);
        $settingsAfter->assertDontSee(route('admin.notifications.logs.index'), false);

        $this->tenant->refresh()->unsetRelation('modules');
        $menuAfter = collect($menuService->tenantMenu($this->tenant, $this->adminUser))
            ->flatMap(function (array $group) {
                return collect($group['children'] ?? [])->pluck('label');
            })
            ->values()
            ->all();

        $this->assertContains('Sistem Ayarları', $menuAfter);
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

    private function createOtherTenant(): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Tenant B',
            'legal_name' => 'Tenant B Ltd.',
            'slug' => 'tenant-b',
            'panel_subdomain' => 'tenant-b',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createWorkForm()
    {
        $customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'NTF-SEC-001',
            'customer_company_id' => $customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fis',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Notification Security Ürünü',
            'product_code' => 'NTF-SEC-ITEM',
            'quantity' => 50,
            'unit' => 'Adet',
            'description' => 'Public tracking security kontrolu',
            'unit_price' => 10,
            'line_total' => 500,
            'has_print' => false,
            'status' => 'pending',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first()->fresh(['delivery']);

        if (!$workForm->delivery) {
            app(DeliveryCreationService::class)->createForWorkForm($workForm, $this->adminUser);
        }

        return $workForm->fresh();
    }
}
