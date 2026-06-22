<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\DeliveryCreationService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationLogUiTest extends TestCase
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
            'panel_subdomain' => 'notification-log-guarded',
            'slug' => 'notification-log-guarded',
        ])->save();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();
    }

    public function test_notification_logs_list_filters_and_detail_are_safely_rendered(): void
    {
        $this->enableNotificationLogAccess();

        $failedLog = NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'payment_received',
            'channel' => 'internal',
            'audience_type' => 'finance',
            'recipient_name' => 'Finance Team',
            'subject' => 'api_key failed file_path',
            'message_preview' => 'payment_amount 1500 file_path C:\\secret pdh_raw raw_json',
            'status' => NotificationLog::STATUS_FAILED,
            'error_message' => 'SMTP baglantisi basarisiz smtp_password very-secret group_code FIN',
            'provider_response' => ['api_key' => 'secret', 'safe' => 'ok'],
            'meta_json' => ['file_path' => 'C:\\secret.txt', 'safe' => 'ok'],
            'created_by' => $this->adminUser->id,
        ]);

        $sentLog = NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => 'email',
            'audience_type' => 'customer',
            'recipient_email' => 'customer@example.test',
            'subject' => 'Teklif gitti',
            'message_preview' => 'Merhaba musterimiz',
            'status' => NotificationLog::STATUS_PREVIEW,
            'created_by' => $this->adminUser->id,
        ]);

        $list = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.logs.index', ['status' => NotificationLog::STATUS_FAILED]));

        $list->assertOk();
        $list->assertSee('Bildirim Gecmisi');
        $list->assertSee('Finance Team');
        $list->assertDontSee('customer@example.test');

        $detail = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.logs.show', $failedLog));

        $detail->assertOk();
        $detail->assertSee('Bildirim Detayi');
        $detail->assertSee('Ödeme Alındı');
        $detail->assertSee('Finance Team');
        $detail->assertSee('ok');
        $detail->assertDontSee('smtp_password', false);
        $detail->assertDontSee('very-secret', false);
        $detail->assertDontSee('api_key', false);
        $detail->assertDontSee('file_path', false);
        $detail->assertDontSee('C:\\secret', false);
        $detail->assertDontSee('group_code', false);
        $detail->assertDontSee('pdh_raw', false);
        $detail->assertDontSee('raw_json', false);

        $financeVisible = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.logs.show', $failedLog));

        $financeVisible->assertOk();

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'notification_logs',
            ],
            ['is_enabled' => false]
        );

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.notifications.logs.index'))
            ->assertForbidden();

        $workForm = $this->createWorkForm();

        $public = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $public->assertOk();
        $public->assertDontSee('Bildirim Gecmisi');
        $public->assertDontSee(route('admin.notifications.logs.index'), false);
        $this->assertNotNull($sentLog);
    }

    private function enableNotificationLogAccess(): void
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
                'feature_key' => 'notification_logs',
            ],
            ['is_enabled' => true]
        );
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
            'document_number' => 'LOG-UI-001',
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
            'product_name' => 'Log UI Urunu',
            'product_code' => 'LOG-001',
            'quantity' => 50,
            'unit' => 'Adet',
            'description' => 'Public tracking kontrolu',
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
