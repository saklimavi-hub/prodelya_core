<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\PromotionQuotePdfService;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuotePdfOutputTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_detail_pdf_button_points_to_real_pdf_route(): void
    {
        $quote = $this->createQuote('TK-PDF-8001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('PDF Teklif');
        $response->assertSee(route('admin.promotion-quotes.pdf', $quote), false);
    }

    public function test_pdf_route_returns_real_pdf_response(): void
    {
        $quote = $this->createQuote('TK-PDF-8002');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.pdf', $quote));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('TK-PDF-8002', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_other_tenant_quote_pdf_cannot_be_exported(): void
    {
        $quote = $this->createQuote('TK-PDF-8003');

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant-quote-pdf',
            'panel_subdomain' => 'other-tenant-quote-pdf',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $quote->forceFill([
            'tenant_account_id' => $otherTenant->id,
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.pdf', $quote))
            ->assertForbidden();
    }

    public function test_pdf_service_renders_customer_safe_html_with_totals_and_optional_approval_link(): void
    {
        $this->enableQuoteApprovalFeatures();

        $quote = $this->createQuote('TK-PDF-8004');
        $approvalRequest = app(QuoteApprovalService::class)->sendToCustomer($quote, [
            'contact_name' => 'Ayşe Müşteri',
            'contact_email' => 'ayse@example.test',
            'contact_phone' => '05320000000',
            'sent_channel' => 'manual',
        ], $this->adminUser);

        $html = app(PromotionQuotePdfService::class)->renderHtml($quote->fresh());

        $this->assertStringContainsString('Promosyon Teklifi', $html);
        $this->assertStringContainsString('Ürün ve Baskı Kalemleri', $html);
        $this->assertStringContainsString('TK-PDF-8004', $html);
        $this->assertStringContainsString('ABC İnşaat A.Ş.', $html);
        $this->assertStringContainsString('PDF Test Ürünü', $html);
        $this->assertStringContainsString('UV Baskı', $html);
        $this->assertStringContainsString('Ara Toplam', $html);
        $this->assertStringContainsString('KDV Toplamı', $html);
        $this->assertStringContainsString('Genel Toplam', $html);
        $this->assertStringContainsString('Teklifinizi online inceleyip yanıtlamak için', $html);
        $this->assertStringContainsString($approvalRequest->token, $html);
        $this->assertStringContainsString('Hazırlayan', $html);
        $this->assertStringContainsString('Müşteri Onayı', $html);
        $this->assertStringContainsString('QR kod bu dokümanda yer almaz.', $html);
        $this->assertStringNotContainsString('PDF-001', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('data:image/svg+xml', $html);
        $this->assertStringNotContainsString('purchase_total', $html);
        $this->assertStringNotContainsString('purchase_unit_price', $html);
        $this->assertStringNotContainsString('supplier_cost', $html);
        $this->assertStringNotContainsString('subcontractor_cost', $html);
        $this->assertStringNotContainsString('setup_cost', $html);
        $this->assertStringNotContainsString('profit', $html);
        $this->assertStringNotContainsString('margin_rate', $html);
        $this->assertStringNotContainsString('profit_total', $html);
        $this->assertStringNotContainsString('current_account_transactions', $html);
        $this->assertStringNotContainsString('payment_logs', $html);
        $this->assertStringNotContainsString('pdh_raw', $html);
        $this->assertStringNotContainsString('raw', $html);
        $this->assertStringNotContainsString('projection', $html);
        $this->assertStringNotContainsString('group_code', $html);
        $this->assertStringNotContainsString('file_path', $html);
        $this->assertStringNotContainsString('physical_path', $html);
        $this->assertStringNotContainsString('secret', $html);
        $this->assertStringNotContainsString('internal_note', $html);
        $this->assertStringNotContainsString('notification_logs', $html);
        $this->assertStringNotContainsString('data-approval-token', $html);
    }

    private function enableQuoteApprovalFeatures(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            ['is_enabled' => true]
        );
    }

    private function createQuote(string $documentNumber): Order
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
            'quote_date' => '2026-06-19',
            'valid_until' => '2026-06-26',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1380,
            'vat_total' => 276,
            'grand_total' => 1656,
            'product_total' => 1200,
            'print_total' => 180,
            'created_by' => $this->adminUser->id,
            'notes' => 'Müşteriye gösterilebilir PDF teklif notu',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'PDF Test Ürünü',
            'product_code' => 'PDF-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Müşteri teklif çıktısı için test kalemi',
            'product_snapshot' => ['display_name' => 'PDF Test Ürünü'],
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                    ['rate' => 20, 'total' => 36, 'scope' => 'print'],
                ],
                'group_code' => 'SECRET-GROUP',
                'file_path' => '/hidden/quote.pdf',
                'purchase_total' => 300,
                'purchase_unit_price' => 3,
                'supplier_cost' => 400,
                'subcontractor_cost' => 500,
                'setup_cost' => 600,
                'profit' => 700,
                'margin' => 45,
            ],
            'stock_snapshot' => ['visible_stock_quantity' => 500],
            'list_price' => 12,
            'discount_rate' => 0,
            'unit_price' => 12,
            'line_total' => 1200,
            'has_print' => true,
            'print_total' => 180,
            'status' => 'pending',
        ]);

        $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 100,
            'print_unit_price' => 1.8,
            'print_total' => 180,
            'note' => 'Kurumsal logo baskısı',
        ]);

        return $quote->fresh();
    }
}
