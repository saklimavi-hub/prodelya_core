<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\GraphicApprovalRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\QuoteApprovalRequest;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\GraphicApprovalRequestService;
use App\Services\Notifications\NotificationEventService;
use App\Services\QuoteApprovalService;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PublicApprovalAndTrackingSecuritySmokeTest extends TestCase
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
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'public-smoke-guarded',
            'slug' => 'public-smoke-guarded',
        ])->save();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        CompanyContact::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->customer->id,
                'name' => 'Ayse Musteri',
            ],
            [
                'email' => 'ayse@example.test',
                'phone' => '05320000000',
                'mobile' => '05320000000',
                'is_primary' => true,
            ]
        );

        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'customer_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'whatsapp_is_active', true, 'boolean');

        $this->enableQuoteApproval();
        $this->enableGraphicApproval();
    }

    public function test_public_surfaces_respect_token_boundaries_feature_guards_and_attachment_visibility(): void
    {
        $trackingContext = $this->createTrackingContext('PASSMOKE-001');
        $quoteContext = $this->createQuoteApprovalContext('TK-SMOKE-001');
        $graphicContext = $this->createGraphicApprovalContext('PG-SMOKE-001');

        $trackingResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $trackingContext['workForm']->public_tracking_token));
        $this->assertSame(200, $trackingResponse->getStatusCode(), 'public tracking should stay open');
        $trackingResponse->assertSee($trackingContext['workForm']->work_form_number);
        $trackingResponse->assertDontSee($trackingContext['workForm']->public_tracking_token);

        $quoteShowResponse = $this->get(route('public.quotes.approval.show', ['token' => $quoteContext['request']->token]));
        $this->assertSame(200, $quoteShowResponse->getStatusCode(), 'public quote approval should stay open');
        $quoteShowResponse->assertSee($quoteContext['quote']->document_number);
        $quoteShowResponse->assertDontSee($quoteContext['request']->token);

        $graphicShowResponse = $this->get(route('public.graphics.approval.show', ['token' => $graphicContext['request']->token]));
        $this->assertSame(200, $graphicShowResponse->getStatusCode(), 'public graphic approval should stay open');
        $graphicShowResponse->assertSee($graphicContext['graphic']->orderItem->product_name);
        $graphicShowResponse->assertDontSee($graphicContext['request']->token);

        $this->get(route('public.graphics.approval.show', ['token' => $quoteContext['request']->token]))->assertNotFound();
        $this->get(route('public.quotes.approval.show', ['token' => $graphicContext['request']->token]))->assertNotFound();
        $this->get(route('public.quotes.approval.show', ['token' => $trackingContext['workForm']->public_tracking_token]))->assertNotFound();
        $this->get(route('public.graphics.approval.show', ['token' => $trackingContext['workForm']->public_tracking_token]))->assertNotFound();
        $this->get(route('public.quotes.approval.show', ['token' => 'gecersiz-token']))->assertNotFound();
        $this->get(route('public.graphics.approval.show', ['token' => 'gecersiz-token']))->assertNotFound();

        $this->assertTrue(
            OrderItemWorkFormAttachment::query()
                ->whereKey($trackingContext['customerVisibleAttachment']->id)
                ->where('work_form_id', $trackingContext['workForm']->id)
                ->where('visibility', 'customer_visible')
                ->exists()
        );
        $this->assertTrue(Storage::disk('public')->exists((string) $trackingContext['customerVisibleAttachment']->file_path));

        $trackingAttachmentResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $trackingContext['workForm']->public_tracking_token,
                'attachment' => $trackingContext['customerVisibleAttachment']->id,
            ]));
        $this->assertSame(200, $trackingAttachmentResponse->getStatusCode(), 'public tracking attachment should stay open');

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $trackingContext['workForm']->public_tracking_token,
                'attachment' => $trackingContext['internalAttachment']->id,
            ]))
            ->assertNotFound();

        $otherWorkForm = $this->createTrackingContext('PASSMOKE-002');
        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $otherWorkForm['workForm']->public_tracking_token,
                'attachment' => $trackingContext['customerVisibleAttachment']->id,
            ]))
            ->assertNotFound();

        app(QuoteApprovalService::class)->approve($quoteContext['request']->fresh(), 'Tamam');
        $this->followingRedirects()
            ->post(route('public.quotes.approval.approve', ['token' => $quoteContext['request']->token]), [
                'customer_note' => 'Tekrar',
            ])
            ->assertOk()
            ->assertSee('Bu teklif daha önce onaylanmış.');

        app(GraphicApprovalRequestService::class)->approve($graphicContext['request']->fresh(), []);
        $this->followingRedirects()
            ->post(route('public.graphics.approval.approve', ['token' => $graphicContext['request']->token]), [])
            ->assertOk()
            ->assertSee('Bu grafik daha önce onaylanmış.');

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'quote_customer_approval')
            ->delete();

        $this->get(route('public.quotes.approval.show', ['token' => $this->createQuoteApprovalContext('TK-SMOKE-002')['request']->token]))
            ->assertNotFound();

        $this->enableQuoteApproval();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'graphic_customer_approval')
            ->delete();

        $this->get(route('public.graphics.approval.show', ['token' => $this->createGraphicApprovalContext('PG-SMOKE-002')['request']->token]))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $trackingContext['workForm']->fresh()->public_tracking_token))
            ->assertOk();
    }

    public function test_public_surfaces_hide_forbidden_fields_and_notification_failures_do_not_break_actions(): void
    {
        $trackingContext = $this->createTrackingContext('PASSMOKE-003', true);
        $quoteContext = $this->createQuoteApprovalContext('TK-SMOKE-003', true);
        $graphicContext = $this->createGraphicApprovalContext('PG-SMOKE-003', true);

        $trackingResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $trackingContext['workForm']->public_tracking_token));

        $trackingResponse->assertOk();
        $trackingResponse->assertDontSee('unit_price', false);
        $trackingResponse->assertDontSee('grand_total', false);
        $trackingResponse->assertDontSee('balance_due', false);
        $trackingResponse->assertDontSee('current_account_transactions', false);
        $trackingResponse->assertDontSee('notification_logs', false);
        $trackingResponse->assertDontSee('group_code', false);
        $trackingResponse->assertDontSee('pdh_raw', false);
        $trackingResponse->assertDontSee('file_path', false);
        $trackingResponse->assertDontSee('physical_path', false);
        $trackingResponse->assertDontSee('supplier_cost', false);
        $trackingResponse->assertDontSee('subcontractor_cost', false);
        $trackingResponse->assertDontSee('purchase_total', false);
        $trackingResponse->assertDontSee('finance warning', false);

        $quoteResponse = $this->get(route('public.quotes.approval.show', ['token' => $quoteContext['request']->token]));

        $quoteResponse->assertOk();
        $quoteResponse->assertSee('1.200,00 TL');
        $quoteResponse->assertDontSee($quoteContext['request']->token);
        $quoteResponse->assertDontSee('purchase_total', false);
        $quoteResponse->assertDontSee('subcontractor_cost', false);
        $quoteResponse->assertDontSee('setup_cost', false);
        $quoteResponse->assertDontSee('balance_due', false);
        $quoteResponse->assertDontSee('current_account_transactions', false);
        $quoteResponse->assertDontSee('notification_logs', false);
        $quoteResponse->assertDontSee('group_code', false);
        $quoteResponse->assertDontSee('pdh_raw', false);
        $quoteResponse->assertDontSee('file_path', false);
        $quoteResponse->assertDontSee('physical_path', false);

        $graphicResponse = $this->get(route('public.graphics.approval.show', ['token' => $graphicContext['request']->token]));

        $graphicResponse->assertOk();
        $graphicResponse->assertSee($graphicContext['requestAttachment']->file_name);
        $graphicResponse->assertDontSee($graphicContext['latestAttachment']->file_name);
        $graphicResponse->assertDontSee($graphicContext['request']->token);
        $graphicResponse->assertDontSee('KDV', false);
        $graphicResponse->assertDontSee('Toplam', false);
        $graphicResponse->assertDontSee('purchase_total', false);
        $graphicResponse->assertDontSee('subcontractor_cost', false);
        $graphicResponse->assertDontSee('setup_cost', false);
        $graphicResponse->assertDontSee('balance_due', false);
        $graphicResponse->assertDontSee('notification_logs', false);
        $graphicResponse->assertDontSee('current_account_transactions', false);
        $graphicResponse->assertDontSee('group_code', false);
        $graphicResponse->assertDontSee('pdh_raw', false);
        $graphicResponse->assertDontSee('file_path', false);
        $graphicResponse->assertDontSee('physical_path', false);

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new RuntimeException('notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $quoteFailure = $this->createQuoteApprovalContext('TK-SMOKE-004');
        $this->followingRedirects()
            ->post(route('public.quotes.approval.approve', ['token' => $quoteFailure['request']->token]), [
                'customer_note' => 'Uygundur',
            ])
            ->assertOk()
            ->assertSee('Teklif onayınız alınmıştır.');

        $graphicFailure = $this->createGraphicApprovalContext('PG-SMOKE-004');
        $this->followingRedirects()
            ->post(route('public.graphics.approval.approve', ['token' => $graphicFailure['request']->token]), [])
            ->assertOk()
            ->assertSee('Grafik onayınız alınmıştır.');
    }

    private function enableQuoteApproval(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            ['is_enabled' => true]
        );
    }

    private function enableGraphicApproval(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => 'public_graphic_approval',
            ],
            ['is_enabled' => true]
        );
    }

    private function createTrackingContext(string $productCode, bool $injectForbidden = false): array
    {
        $workForm = $this->createConvertedWorkForm($productCode, true);

        $customerVisibleAttachment = app(WorkFormAttachmentService::class)->store(
            $workForm,
            UploadedFile::fake()->image('teslim-' . $productCode . '.jpg'),
            'delivery_photo',
            'Public delivery photo',
            'customer_visible',
            $this->adminUser
        );

        $internalAttachment = app(WorkFormAttachmentService::class)->store(
            $workForm->fresh(),
            UploadedFile::fake()->create('ic-' . $productCode . '.pdf', 120, 'application/pdf'),
            'delivery_document',
            'Internal delivery document',
            'internal',
            $this->adminUser
        );

        if ($injectForbidden) {
            $workForm->forceFill([
                'product_snapshot' => array_merge($workForm->product_snapshot ?? [], [
                    'image_url' => 'storage/private/products/secret-' . $productCode . '.jpg',
                ]),
                'print_snapshot' => [[
                    'print_type' => 'UV Baskı',
                    'print_option' => 'Tek taraf baskılı',
                    'production_type' => 'İç üretim',
                    'print_quantity' => 100,
                    'note' => 'setup_cost 500 / unit_price 25 / file_path C:\\private\\proof.pdf',
                ]],
                'procurement_snapshot' => array_merge($workForm->procurement_snapshot ?? [], [
                    'purchase_total' => 9999,
                    'supplier_cost' => 500,
                    'group_code' => 'PROC-GRP-' . $productCode,
                ]),
                'production_snapshot' => array_merge($workForm->production_snapshot ?? [], [
                    'subcontractor_cost' => 800,
                    'profit' => 1200,
                ]),
                'delivery_snapshot' => array_merge($workForm->delivery_snapshot ?? [], [
                    'balance_due' => 2300,
                    'financial_warning' => 'odeme_bekliyor',
                ]),
            ])->save();

            OrderItemWorkFormActivityLog::query()->create([
                'tenant_account_id' => $workForm->tenant_account_id,
                'work_form_id' => $workForm->id,
                'order_id' => $workForm->order_id,
                'order_item_id' => $workForm->order_item_id,
                'action_type' => 'delivery_document_added',
                'note' => 'notification_logs current_account_transactions api_key file_path physical_path',
                'visibility' => 'customer_visible',
                'created_by' => $this->adminUser->id,
            ]);
        }

        return [
            'workForm' => $workForm->fresh(),
            'customerVisibleAttachment' => $customerVisibleAttachment->fresh(),
            'internalAttachment' => $internalAttachment->fresh(),
        ];
    }

    private function createQuoteApprovalContext(string $documentNumber, bool $injectForbidden = false): array
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-18',
            'valid_until' => '2026-06-25',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'notes' => 'Customer safe teklif notu',
            'currency' => 'TL',
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'product_total' => 1200,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Public Quote Smoke Ürünü',
            'product_code' => 'PQS-' . $documentNumber,
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Müşteri için güvenli açıklama',
            'product_snapshot' => ['display_name' => 'Public Quote Smoke Ürünü'],
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                ],
            ],
            'stock_snapshot' => ['visible_stock_quantity' => 500],
            'list_price' => 12,
            'discount_rate' => 0,
            'unit_price' => 12,
            'line_total' => 1200,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        $request = app(QuoteApprovalService::class)->sendToCustomer($quote->fresh(), [
            'contact_email' => 'smoke-quote@example.test',
        ], $this->adminUser);

        if ($injectForbidden) {
            $item->forceFill([
                'product_name' => 'CANLI VERI DEGISMIS',
                'product_code' => 'LIVE-CHANGED',
                'description' => 'internal note live data',
                'product_snapshot' => [
                    'group_code' => 'PDH-HIDDEN',
                    'pdh_raw' => ['secret' => 'hidden'],
                    'file_path' => 'C:\\private\\quote.pdf',
                ],
                'price_snapshot' => [
                    'purchase_total' => 9999,
                    'subcontractor_cost' => 500,
                    'setup_cost' => 100,
                    'balance_due' => 2500,
                    'notification_logs' => ['id' => 1],
                    'current_account_transactions' => [['id' => 99]],
                ],
                'line_total' => 9999,
            ])->save();
        }

        return [
            'quote' => $quote->fresh(),
            'request' => $request->fresh(),
        ];
    }

    private function createGraphicApprovalContext(string $productCode, bool $injectForbidden = false): array
    {
        $workForm = $this->createConvertedWorkForm($productCode, true);
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->with(['orderItem', 'orderItemPrint', 'workForm', 'tenant'])
            ->orderBy('sequence_code')
            ->get()
            ->values();

        $graphic = $graphics->firstOrFail();
        $requestAttachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/request-' . strtolower($productCode) . '.png',
            'file_name' => 'request-' . $productCode . '.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => $injectForbidden ? 'setup_cost supplier_cost group_code file_path' : 'Müşteri onay görseli',
            'sort_order' => 1,
        ]);

        $latestAttachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/latest-' . strtolower($productCode) . '.png',
            'file_name' => 'latest-' . $productCode . '.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'Sonraki görsel',
            'sort_order' => 2,
        ]);

        $graphic->forceFill([
            'latest_attachment_id' => $requestAttachment->id,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $request = app(GraphicApprovalRequestService::class)->createRequest(
            $graphic->fresh(),
            $requestAttachment,
            [],
            $this->adminUser
        );

        $graphic->forceFill([
            'latest_attachment_id' => $latestAttachment->id,
        ])->save();

        return [
            'workForm' => $workForm->fresh(),
            'graphic' => $graphic->fresh(['orderItem']),
            'request' => $request->fresh(),
            'requestAttachment' => $requestAttachment->fresh(),
            'latestAttachment' => $latestAttachment->fresh(),
        ];
    }

    private function createConvertedWorkForm(string $productCode, bool $multiplePrints = false): OrderItemWorkForm
    {
        $prints = [[
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'production_type' => 'İç üretim',
            'print_quantity' => '100',
            'print_unit_price' => '10',
        ]];

        if ($multiplePrints) {
            $prints[] = [
                'print_type' => 'Serigrafi',
                'print_option' => 'Gövde',
                'production_type' => 'İç üretim',
                'print_quantity' => '100',
                'print_unit_price' => '12',
            ];
        }

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-18',
                'valid_until' => '2026-06-25',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Public approval and tracking smoke',
                'items' => [[
                    'product_name' => 'Public Smoke Ürünü ' . $productCode,
                    'product_code' => $productCode,
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => $prints,
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return OrderItemWorkForm::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->latest('id')
            ->firstOrFail();
    }
}
