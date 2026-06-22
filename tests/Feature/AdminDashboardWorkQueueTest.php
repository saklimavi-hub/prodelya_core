<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\GraphicApprovalRequest;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\GraphicApprovalRequestService;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminDashboardWorkQueueTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        CompanyContact::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->customer->id,
                'name' => 'Dashboard Müşteri Yetkilisi',
            ],
            [
                'email' => 'dashboard@example.test',
                'phone' => '05320000001',
                'mobile' => '05320000001',
                'is_primary' => true,
            ]
        );

        TenantSetting::setValue($this->tenant->id, 'portal_enabled', true, 'boolean');
        $this->customer->forceFill(['portal_enabled' => true])->save();

        $this->enableModuleFeature('notification_center');
        $this->enableModuleFeature('notification_center', 'notification_logs');
        $this->enableModuleFeature('graphic_customer_approval');
        $this->enableModuleFeature('graphic_customer_approval', 'public_graphic_approval');
    }

    public function test_dashboard_shows_real_work_queue_cards_queue_items_and_keeps_sensitive_fields_hidden(): void
    {
        $waitingQuote = $this->createQuote(
            'TK-DASH-WAIT-001',
            Order::CUSTOMER_APPROVAL_WAITING,
            'pending'
        );
        $convertibleQuote = $this->createQuote(
            'TK-DASH-APP-001',
            Order::CUSTOMER_APPROVAL_APPROVED,
            'approved'
        );

        $graphicWaitingWorkForm = $this->createConvertedWorkForm('DASH-GRAPHIC-001');
        $graphicApprovalWorkForm = $this->createConvertedWorkForm('DASH-GAPP-001');
        $readyProductionWorkForm = $this->createConvertedWorkForm('DASH-READY-001');

        $graphicApprovalRequest = $this->createGraphicApprovalRequest($graphicApprovalWorkForm);
        $this->prepareReadyProduction($readyProductionWorkForm);

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'graphic_customer_approval_requested',
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'audience_type' => 'customer',
            'recipient_type' => 'email',
            'recipient_name' => 'Dashboard User',
            'recipient_email' => 'dashboard@example.test',
            'subject' => 'Dashboard Failure',
            'message_preview' => 'Gönderim başarısız oldu.',
            'status' => NotificationLog::STATUS_FAILED,
            'attempt_count' => 1,
            'error_message' => 'Provider timeout',
            'created_by' => $this->adminUser->id,
        ]);

        $foreignTenant = $this->createOtherTenant();
        $foreignCustomer = Company::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'legal_name' => 'Yabancı Tenant Müşterisi',
            'short_name' => 'Yabancı',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
        Order::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-FOREIGN-0001',
            'customer_company_id' => $foreignCustomer->id,
            'status' => 'approved',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_WAITING,
            'quote_date' => '2026-06-19',
            'valid_until' => '2026-06-26',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Yönetim Paneli');
        $response->assertSee('İş Kuyruğu');
        $response->assertSee('Genel Özet');
        $response->assertSee('Süreç Akış Şeridi');
        $response->assertSee('Öncelikli Aksiyonlar');
        $response->assertSee('Kontrol Paneli');
        $response->assertSee('Portal ve Public Linkler');
        $response->assertSee('Modül Kısayolları');
        $response->assertSee('Onay Bekleyen Teklifler');
        $response->assertSee('Siparişe Çevrilebilir Teklifler');
        $response->assertSee('Grafik Bekleyen İşler');
        $response->assertSee('Müşteri Onayı');
        $response->assertSee('Tedarik Bekleyen İşler');
        $response->assertSee('Bloklu Üretimler');
        $response->assertSee('Teslimat Bekleyen İşler');
        $response->assertSee('Başarısız Bildirimler');
        $response->assertSee('Sıradaki İşler');
        $response->assertSee('Baskı Ayarları');
        $response->assertSee(route('admin.settings.print-settings.index'), false);
        $response->assertSee($waitingQuote->document_number);
        $response->assertSee($convertibleQuote->document_number);
        $response->assertSee('Müşteri onayı bekliyor');
        $response->assertSee('Siparişe çevrilmeyi bekliyor');
        $response->assertSee('Grafik görseli bekleniyor');
        $response->assertSee('Müşteri onayı bekleniyor');
        $response->assertSee('Talep hazırlanmalı');
        $response->assertSee('Bu baskı için grafik üretime hazır değil.');
        $response->assertSee('Teslimat bekliyor');
        $response->assertSee(route('admin.promotion-quotes.show', $waitingQuote), false);
        $response->assertSee(route('admin.promotion-quotes.show', $convertibleQuote), false);
        $response->assertSee(route('admin.graphics.show', $graphicWaitingWorkForm), false);
        $response->assertSee(route('admin.procurements.show', $graphicWaitingWorkForm->procurement), false);
        $response->assertSee(route('admin.productions.show', $graphicWaitingWorkForm->printProductions->first()), false);
        $response->assertSee('/admin/deliveries/', false);
        $response->assertSee(route('admin.notifications.logs.index', ['status' => NotificationLog::STATUS_FAILED]), false);
        $response->assertDontSee('TK-FOREIGN-0001');
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('pdh_raw', false);
        $response->assertDontSee('purchase_total', false);
        $response->assertDontSee('supplier_cost', false);
        $response->assertDontSee('subcontractor_cost', false);
        $response->assertDontSee('current_account_transactions', false);
        $response->assertDontSee($graphicApprovalRequest->token);

        $response->assertViewHas('dashboard', function (array $dashboard) {
            $cards = collect($dashboard['cards'] ?? [])->keyBy('title');
            $queueItems = collect($dashboard['queue_items'] ?? []);

            return ($cards['Onay Bekleyen Teklifler']['count'] ?? null) === 1
                && ($cards['Siparişe Çevrilebilir Teklifler']['count'] ?? null) === 1
                && ($cards['Grafik Bekleyen İşler']['count'] ?? null) === 1
                && ($cards['Müşteri Grafik Onayı Bekleyenler']['count'] ?? null) === 1
                && ($cards['Tedarik Bekleyen İşler']['count'] ?? null) === 2
                && ($cards['Üretime Hazır / Bloklu Üretimler']['count'] ?? null) === 2
                && ($cards['Teslimat Bekleyen İşler']['count'] ?? null) === 3
                && ($cards['Başarısız Bildirimler']['count'] ?? null) === 1
                && $queueItems->contains(fn (array $item) => ($item['kind'] ?? null) === 'Teklif' && ($item['document_number'] ?? null) === 'TK-DASH-WAIT-001')
                && $queueItems->contains(fn (array $item) => ($item['kind'] ?? null) === 'Grafik Onayı' && str_contains((string) ($item['summary'] ?? ''), 'Müşteri onayı'))
                && $queueItems->contains(fn (array $item) => ($item['kind'] ?? null) === 'Üretim' && str_contains((string) ($item['summary'] ?? ''), 'üretime hazır değil'));
        });
    }

    public function test_dashboard_hides_module_gated_cards_and_customer_portal_guard_does_not_count_as_admin(): void
    {
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'dashboard-guarded',
            'slug' => 'dashboard-guarded',
        ])->save();

        $this->createQuote('TK-DASH-WAIT-002', Order::CUSTOMER_APPROVAL_WAITING, 'pending');
        $workForm = $this->createConvertedWorkForm('DASH-HIDE-001');
        $this->createGraphicApprovalRequest($workForm);

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'graphic_customer_approval_requested',
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'audience_type' => 'customer',
            'recipient_type' => 'email',
            'recipient_name' => 'Dashboard User',
            'recipient_email' => 'dashboard@example.test',
            'subject' => 'Dashboard Failure',
            'message_preview' => 'Gönderim başarısız oldu.',
            'status' => NotificationLog::STATUS_FAILED,
            'attempt_count' => 1,
            'error_message' => 'Provider timeout',
            'created_by' => $this->adminUser->id,
        ]);

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();
        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'graphic_customer_approval')
            ->delete();

        $response = $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('Başarısız Bildirimler');
        $response->assertDontSee('Müşteri Grafik Onayı Bekleyenler');

        $portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->customer->id,
            'company_contact_id' => CompanyContact::query()
                ->where('tenant_account_id', $this->tenant->id)
                ->where('company_id', $this->customer->id)
                ->value('id'),
            'name' => 'Portal Dashboard User',
            'email' => 'portal-dashboard@example.test',
            'password' => 'password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        auth('web')->logout();

        $this->actingAs($portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    private function createQuote(string $documentNumber, string $approvalStatus, string $status): Order
    {
        return Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => $status,
            'workflow_status' => 'quote',
            'customer_approval_status' => $approvalStatus,
            'quote_date' => '2026-06-19',
            'valid_until' => '2026-06-26',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function createConvertedWorkForm(string $productCode): OrderItemWorkForm
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-19',
                'valid_until' => '2026-06-26',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Dashboard queue test payload',
                'items' => [[
                    'product_name' => 'Dashboard Ürünü ' . $productCode,
                    'product_code' => $productCode,
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'UV Baskı',
                        'print_option' => 'Tek taraf baskılı',
                        'production_type' => 'İç üretim',
                        'print_quantity' => '100',
                        'print_unit_price' => '10',
                    ]],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        /** @var OrderItemWorkForm $workForm */
        $workForm = OrderItemWorkForm::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->with([
                'order.customer',
                'procurement',
                'printGraphics.orderItemPrint',
                'printProductions.orderItemPrint',
                'delivery',
                'attachments',
            ])
            ->latest('id')
            ->firstOrFail();

        $workForm->order->forceFill([
            'document_number' => 'SP-' . $productCode,
        ])->save();

        $workForm->forceFill([
            'work_form_number' => 'IF-' . $productCode,
        ])->save();

        return $workForm->fresh([
            'order.customer',
            'procurement',
            'printGraphics.orderItemPrint',
            'printProductions.orderItemPrint',
            'delivery',
            'attachments',
        ]);
    }

    private function createGraphicApprovalRequest(OrderItemWorkForm $workForm): GraphicApprovalRequest
    {
        $graphic = $workForm->printGraphics->firstOrFail()->fresh([
            'workForm',
            'order.customer.contacts',
            'orderItem',
            'orderItemPrint',
            'tenant',
        ]);

        $attachment = app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image('dashboard-gapproval-' . $graphic->id . '.png'),
            [
                'visibility' => 'customer_visible',
                'note' => 'Dashboard müşteri onayı görseli',
            ],
            $this->adminUser
        );

        return app(GraphicApprovalRequestService::class)->createRequest(
            $graphic->fresh([
                'tenant',
                'order.customer.contacts',
                'orderItem',
                'orderItemPrint',
                'workForm',
            ]),
            $attachment->fresh(),
            [],
            $this->adminUser
        );
    }

    private function prepareReadyProduction(OrderItemWorkForm $workForm): void
    {
        $graphic = $workForm->printGraphics->firstOrFail()->fresh([
            'workForm',
            'orderItemPrint',
            'orderItem',
            'order.customer.contacts',
            'tenant',
        ]);

        $attachment = app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image('dashboard-ready-' . $graphic->id . '.png'),
            ['note' => 'Üretime hazır final görsel'],
            $this->adminUser
        );

        $graphic->forceFill([
            'status' => OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
            'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED,
            'latest_attachment_id' => $attachment->id,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $workForm->procurement->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'updated_by' => $this->adminUser->id,
        ])->save();
    }

    private function enableModuleFeature(string $moduleKey, ?string $featureKey = null): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => $moduleKey,
                'feature_key' => $featureKey,
            ],
            ['is_enabled' => true]
        );
    }

    private function createOtherTenant(): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Dashboard Other Tenant',
            'legal_name' => 'Dashboard Other Tenant Ltd.',
            'slug' => 'dashboard-other-tenant',
            'panel_subdomain' => 'dashboard-other-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }
}
