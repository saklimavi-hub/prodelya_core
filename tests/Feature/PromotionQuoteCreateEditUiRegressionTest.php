<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCreateEditUiRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_create_screen_uses_clean_quote_language_and_compact_workspace_layout(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('Müşteri ve teklif bilgileri');
        $response->assertSee('Ürün kalemleri');
        $response->assertSee('Teklif Özeti');
        $response->assertDontSee('Taslak');
        $response->assertSee('Teklif');
        $response->assertSeeInOrder([
            'Müşteri',
            'Teklif tarihi',
            'Teslim tarihi',
            'Teklif Durumu',
        ]);
        $response->assertSeeInOrder([
            'Belge Türü',
            'Teslimat Tipi',
            'Para birimi',
            'Baskı fiyatı gösterimi',
            'Sipariş Notu',
        ]);
        $response->assertSee('Baskı fiyatı gösterilsin');
        $response->assertSee('Baskı fiyatı gizlensin');
        $response->assertDontSee('Müşteri çıktılarında baskı fiyatını göster');
        $response->assertSee('No');
        $response->assertSee('Ürün');
        $response->assertSee('Liste');
        $response->assertSee('İskonto %');
        $response->assertSee('Birim Fiyat');
        $response->assertSee('Toplam');
        $response->assertSee('Baskı');
        $response->assertSee('Ürün Ekle');
        $response->assertSee('Baskı Ekle');
        $response->assertSee('Baskı türü');
        $response->assertSee('Baskı seçeneği');
        $response->assertSee('Baskı miktarı');
        $response->assertSee('Birim baskı fiyatı');
        $response->assertSee('Baskı toplamı');
        $response->assertSee('Baskı adı');
        $response->assertSee('Ürün Toplamı');
        $response->assertSee('Baskı Toplamı');
        $response->assertSee('Ara Toplam');
        $response->assertSee('Genel toplam');
        $response->assertSee('class="pd-summary-stack-row pd-summary-stack-row-vat hidden" id="summary-vat-total-row"', false);
        $response->assertSee('id="summary-vat-breakdown" class="space-y-2 hidden"', false);
        $response->assertSee('pd-section-heading pd-section-heading-split', false);
        $response->assertSee('pd-section-heading-actions', false);
        $response->assertSee('pd-quote-item-group', false);
        $response->assertSee('pd-catalog-search', false);
        $response->assertSee('pd-catalog-results hidden', false);
        $response->assertSee('Kaydet');
        $response->assertDontSee('Siparişe Çevir ve Süreci Başlat');
        $response->assertDontSee('Baskı firması');
        $response->assertDontSee('Firma: İç Üretim');
        $response->assertDontSee(route('admin.orders.convert.from.quote', ['quote' => 1]), false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee("localStockPresentation.note || ''", false);
        $response->assertSee('buildCompactProductMetaLine(item, payload)', false);
        $response->assertSee('buildCompactProductMetaLine(entry, entry, { includePrice: true })', false);
        $response->assertSee('class="pd-product-live-info__meta-line"', false);
        $response->assertDontSee('pd-product-live-info__meta-row', false);
        $response->assertDontSee('pd-product-live-info__meta-bit', false);
        $response->assertDontSee('Katalog stok bilgisi', false);
        $response->assertDontSee('Siparişe dönüşümde yerel stok yeniden kontrol edilir.', false);
        $response->assertDontSee('Yerel stok doğrulanamadı', false);
        $response->assertDontSee('Katalog stok:', false);
        $response->assertDontSee('Satış liste:', false);
        $response->assertSee('Güncel fiyat:', false);
        $response->assertSee('Local stok:', false);
        $response->assertSee('Tedarikçi stok:', false);

        $css = file_get_contents(public_path('css/prodelya-admin.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('.pd-catalog-results {', $css);
        $this->assertStringContainsString('z-index: 1205;', $css);
        $this->assertStringContainsString('.pd-quote-workspace .pd-card {', $css);
        $this->assertStringContainsString('.pd-quote-meta-grid {', $css);
        $this->assertStringContainsString('.pd-quote-add-product-button {', $css);
        $this->assertStringContainsString('.pd-print-add-button {', $css);
        $this->assertStringContainsString('.pd-summary-stack-row {', $css);
        $this->assertStringContainsString('.pd-quote-item-group {', $css);
        $this->assertStringContainsString('.pd-section-heading {', $css);
    }

    public function test_edit_screen_keeps_same_workspace_language_and_layout(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-3101',
            'status' => 'draft',
            'workflow_status' => 'quote',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $response->assertOk();
        $response->assertSee('Müşteri ve teklif bilgileri');
        $response->assertSee('Ürün kalemleri');
        $response->assertDontSee('Taslak');
        $response->assertSee('Teklif');
        $response->assertSeeInOrder([
            'Müşteri',
            'Teklif tarihi',
            'Teslim tarihi',
            'Teklif Durumu',
        ]);
        $response->assertSeeInOrder([
            'Belge Türü',
            'Teslimat Tipi',
            'Para birimi',
            'Baskı fiyatı gösterimi',
            'Sipariş Notu',
        ]);
        $response->assertSee('Baskı fiyatı gösterilsin');
        $response->assertSee('Baskı fiyatı gizlensin');
        $response->assertDontSee('Müşteri çıktılarında baskı fiyatını göster');
        $response->assertSee('Ürün Ekle');
        $response->assertSee('Baskı Ekle');
        $response->assertSee('Ürün Toplamı');
        $response->assertSee('Baskı Toplamı');
        $response->assertSee('Ara Toplam');
        $response->assertSee('Genel toplam');
        $response->assertSee('class="pd-summary-stack-row pd-summary-stack-row-vat hidden" id="summary-vat-total-row"', false);
        $response->assertSee('id="summary-vat-breakdown" class="space-y-2 hidden"', false);
        $response->assertSee('pd-quote-item-group', false);
        $response->assertSee('1a');
        $response->assertSee('Baskı adı');
        $response->assertSee('pd-catalog-search', false);
        $response->assertSee('pd-catalog-results hidden', false);
        $response->assertDontSee('Baskı firması');
        $response->assertDontSee('Firma: İç Üretim');
        $response->assertSee('Teklifi Kontrol Et / Siparişe Çevir');
        $response->assertSee(route('admin.promotion-quotes.show', $quote), false);
        $response->assertDontSee('Siparişe Çevir ve Süreci Başlat');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee("localStockPresentation.note || ''", false);
        $response->assertSee('buildCompactProductMetaLine(item, payload)', false);
        $response->assertDontSee('pd-product-live-info__meta-row', false);
        $response->assertDontSee('pd-product-live-info__meta-bit', false);
        $response->assertDontSee('Siparişe dönüşümde yerel stok yeniden kontrol edilir.', false);
        $response->assertDontSee('Yerel stok doğrulanamadı', false);
        $response->assertDontSee('Katalog stok:', false);
        $response->assertDontSee('Satış liste:', false);
    }

    public function test_show_screen_displays_mark_approved_action_for_preparing_quote(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-3102',
            'status' => 'draft',
            'workflow_status' => 'quote',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('Onaylandı İşaretle');
        $response->assertSee('data-testid="quote-mark-approved-button"', false);
        $response->assertDontSee('data-testid="quote-convert-cta"', false);
        $response->assertDontSee('data-testid="quote-convert-form"', false);
        $response->assertDontSee('Baskı firması');
    }

    public function test_show_screen_displays_convert_cta_for_approved_quote(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-3104',
            'status' => 'approved',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('Siparişe Çevir ve Süreci Başlat');
        $response->assertSee('data-testid="quote-convert-cta"', false);
        $response->assertSee(route('admin.orders.convert.from.quote', $quote), false);
        $response->assertDontSee('data-testid="quote-mark-approved-button"', false);
    }

    public function test_show_screen_displays_disabled_convert_state_when_quote_has_no_items(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = Order::query()->create([
            'tenant_account_id' => 1,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-2026-3103',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fis',
            'delivery_type' => 'Ofis Teslim',
            'delivery_type_id' => 1,
            'currency' => 'TRY',
            'subtotal' => 0,
            'vat_total' => 0,
            'grand_total' => 0,
            'product_total' => 0,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('Siparişe çevirmek için en az bir ürün kalemi gerekli.');
        $response->assertDontSee(route('admin.orders.convert.from.quote', $quote), false);
        $response->assertDontSee('data-testid="quote-convert-form"', false);
    }

    private function createPromotionQuote(Company $customer, array $attributes = []): Order
    {
        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => 1,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-2026-3001',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'invoice_status' => 'fis',
            'delivery_type' => 'Ofis Teslim',
            'delivery_type_id' => 1,
            'currency' => 'TRY',
            'subtotal' => 1200,
            'vat_total' => 0,
            'grand_total' => 1200,
            'product_total' => 1200,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ], $attributes));

        OrderItem::query()->create([
            'tenant_account_id' => 1,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Kalem Seti',
            'product_code' => 'KLM-001',
            'quantity' => 1,
            'unit' => 'adet',
            'list_price' => 1200,
            'discount_rate' => 0,
            'unit_price' => 1200,
            'line_total' => 1200,
            'has_print' => true,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        return $quote->fresh();
    }
}
