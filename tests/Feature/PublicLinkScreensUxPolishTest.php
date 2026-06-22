<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\GraphicApprovalRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\QuoteApprovalRequest;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\GraphicApprovalRequestService;
use App\Services\QuoteApprovalService;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicLinkScreensUxPolishTest extends TestCase
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
            'panel_subdomain' => 'public-ux-polish',
            'slug' => 'public-ux-polish',
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

        $this->enableQuoteApproval();
        $this->enableGraphicApproval();
    }

    public function test_public_link_screens_use_customer_facing_copy_and_keep_security_boundaries(): void
    {
        $trackingContext = $this->createTrackingContext('PUBLIC-UX-001');
        $quoteContext = $this->createQuoteApprovalContext('TK-PUBLIC-UX-001');
        $graphicContext = $this->createGraphicApprovalContext('PG-PUBLIC-UX-001');

        $trackingResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $trackingContext['workForm']->public_tracking_token));

        $trackingResponse->assertOk();
        $trackingResponse->assertSee('Sipariş Takibi');
        $trackingResponse->assertSee('Müşteri Takip Ekranı');
        $trackingResponse->assertSee('Siparişiniz şu aşamada');
        $trackingResponse->assertSee('Müşteri Dosyaları');
        $trackingResponse->assertDontSee('Public Tracking');
        $trackingResponse->assertDontSee($trackingContext['workForm']->public_tracking_token);
        $trackingResponse->assertDontSee('file_path', false);
        $trackingResponse->assertDontSee('physical_path', false);
        $trackingResponse->assertDontSee('group_code', false);
        $trackingResponse->assertDontSee('pdh_raw', false);
        $trackingResponse->assertDontSee('balance_due', false);
        $trackingResponse->assertDontSee('supplier_cost', false);

        $quoteResponse = $this->get(route('public.quotes.approval.show', ['token' => $quoteContext['request']->token]));

        $quoteResponse->assertOk();
        $quoteResponse->assertSee('Teklifinizi İnceleyin');
        $quoteResponse->assertSee('Teklifi Onayla');
        $quoteResponse->assertSee('Revize İste');
        $quoteResponse->assertSee('Teklifi Reddet');
        $quoteResponse->assertDontSee('Approval request');
        $quoteResponse->assertDontSee($quoteContext['request']->token);
        $quoteResponse->assertDontSee('file_path', false);
        $quoteResponse->assertDontSee('physical_path', false);
        $quoteResponse->assertDontSee('group_code', false);
        $quoteResponse->assertDontSee('pdh_raw', false);
        $quoteResponse->assertDontSee('purchase_total', false);
        $quoteResponse->assertDontSee('current_account_transactions', false);

        $graphicResponse = $this->get(route('public.graphics.approval.show', ['token' => $graphicContext['request']->token]));

        $graphicResponse->assertOk();
        $graphicResponse->assertSee('Grafik Tasarımınızı İnceleyin');
        $graphicResponse->assertSee('Grafiği Onayla');
        $graphicResponse->assertSee('Revize İste');
        $graphicResponse->assertDontSee('Teklifi Reddet');
        $graphicResponse->assertDontSee($graphicContext['request']->token);
        $graphicResponse->assertDontSee('file_path', false);
        $graphicResponse->assertDontSee('physical_path', false);
        $graphicResponse->assertDontSee('group_code', false);
        $graphicResponse->assertDontSee('pdh_raw', false);
        $graphicResponse->assertDontSee('purchase_total', false);

        $this->post(route('public.quotes.approval.approve', ['token' => $this->createQuoteApprovalContext('TK-PUBLIC-UX-002')['request']->token]), [
            'customer_note' => 'Uygundur.',
        ])->assertRedirect();

        $this->post(route('public.quotes.approval.revision', ['token' => $this->createQuoteApprovalContext('TK-PUBLIC-UX-003')['request']->token]), [
            'customer_note' => 'Teslim tarihi güncellensin.',
        ])->assertRedirect();

        $this->post(route('public.quotes.approval.reject', ['token' => $this->createQuoteApprovalContext('TK-PUBLIC-UX-004')['request']->token]), [
            'customer_note' => 'Bu hali uygun değil.',
        ])->assertRedirect();

        $this->post(route('public.graphics.approval.approve', ['token' => $this->createGraphicApprovalContext('PG-PUBLIC-UX-002')['request']->token]), [
            'customer_note' => 'Uygun',
        ])->assertRedirect();

        $this->post(route('public.graphics.approval.revision', ['token' => $this->createGraphicApprovalContext('PG-PUBLIC-UX-003')['request']->token]), [
            'customer_note' => 'Logo yukarı alınsın.',
        ])->assertRedirect();
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

    private function createTrackingContext(string $productCode): array
    {
        $workForm = $this->createConvertedWorkForm($productCode);

        $customerVisibleAttachment = app(WorkFormAttachmentService::class)->store(
            $workForm,
            UploadedFile::fake()->image('teslim-' . $productCode . '.jpg'),
            'delivery_photo',
            'Public delivery photo',
            'customer_visible',
            $this->adminUser
        );

        return [
            'workForm' => $workForm->fresh(),
            'attachment' => $customerVisibleAttachment->fresh(),
        ];
    }

    private function createQuoteApprovalContext(string $documentNumber): array
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

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Public Quote UX Ürünü',
            'product_code' => 'PQUX-' . $documentNumber,
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Müşteri için güvenli açıklama',
            'product_snapshot' => ['display_name' => 'Public Quote UX Ürünü'],
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
            'contact_email' => 'public-ux@example.test',
        ], $this->adminUser);

        return [
            'quote' => $quote->fresh(),
            'request' => $request->fresh(),
        ];
    }

    private function createGraphicApprovalContext(string $productCode): array
    {
        $workForm = $this->createConvertedWorkForm($productCode);
        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->with(['orderItem', 'orderItemPrint', 'workForm', 'tenant'])
            ->orderBy('sequence_code')
            ->firstOrFail();

        $attachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/graphic-' . strtolower($productCode) . '.png',
            'file_name' => 'graphic-' . $productCode . '.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'Müşteri onay görseli',
            'sort_order' => 1,
        ]);

        $graphic->forceFill([
            'latest_attachment_id' => $attachment->id,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $request = app(GraphicApprovalRequestService::class)->createRequest(
            $graphic->fresh(),
            $attachment,
            [],
            $this->adminUser
        );

        return [
            'workForm' => $workForm->fresh(),
            'graphic' => $graphic->fresh(['orderItem']),
            'request' => $request->fresh(),
        ];
    }

    private function createConvertedWorkForm(string $productCode): OrderItemWorkForm
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-18',
                'valid_until' => '2026-06-25',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Public link screens UX polish',
                'items' => [[
                    'product_name' => 'Public UX Ürünü ' . $productCode,
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
                        'print_option' => 'Tek taraf',
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

        return OrderItemWorkForm::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->latest('id')
            ->firstOrFail();
    }
}
