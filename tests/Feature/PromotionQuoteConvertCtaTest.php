<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemWorkForm;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteConvertCtaTest extends TestCase
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

    public function test_convertible_quote_show_displays_cta_modal_copy_and_convert_form(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-2101',
            'status' => 'approved',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
            'invoice_status' => 'fatura',
            'grand_total' => 2400,
        ], 2, 3);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('Siparişe Çevir ve Süreci Başlat');
        $response->assertSee('data-testid="quote-convert-cta"', false);
        $response->assertSee('data-testid="quote-convert-form"', false);
        $response->assertSee(route('admin.orders.convert.from.quote', $quote), false);
        $response->assertSee('Ürün &amp; Baskı Kalemleri', false);
        $response->assertSee('Baskı Toplamı');
        $response->assertSee('Birim Fiyat');
        $response->assertSee('Baskı Satırı');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('price_snapshot', false);
    }

    public function test_converted_quote_show_hides_cta_and_shows_process_started_card_with_order_link(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-2102',
            'status' => 'approved',
            'workflow_status' => 'quote_converted',
            'invoice_status' => 'fatura',
            'grand_total' => 3850,
        ], 3, 5);

        $order = Order::query()->create([
            'tenant_account_id' => 1,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-2026-0015',
            'source_quote_id' => $quote->id,
            'source_quote_number' => $quote->document_number,
            'customer_company_id' => $customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 3208.33,
            'vat_total' => 641.67,
            'grand_total' => 3850,
            'product_total' => 1850,
            'print_total' => 2000,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('Siparişe Dönüştü');
        $response->assertSee('data-testid="quote-open-order-button"', false);
        $response->assertSee(route('admin.orders.show', $order), false);
        $response->assertDontSee('data-testid="quote-convert-cta"', false);
        $response->assertDontSee('data-testid="quote-convert-form"', false);
    }

    public function test_conversion_from_quote_show_keeps_redirect_and_duplicate_guard_intact(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-2103',
            'status' => 'approved',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
            'invoice_status' => 'fatura',
            'grand_total' => 2600,
        ], 1, 2);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote));

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $response->assertRedirect(route('admin.orders.show', $order));

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote->fresh()));

        $showResponse->assertOk();
        $showResponse->assertSee('Siparişe Dönüştü');
        $showResponse->assertDontSee('data-testid="quote-convert-cta"', false);

        $duplicateResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote));

        $duplicateResponse->assertRedirect(route('admin.orders.show', $order));
        $duplicateResponse->assertSessionHas('info', 'Bu teklif daha önce siparişe dönüştürüldü.');
    }

    public function test_graphic_role_user_does_not_see_quote_prices_and_public_tracking_stays_safe(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-2104',
            'status' => 'pending',
            'workflow_status' => 'quote',
            'invoice_status' => 'fatura',
            'grand_total' => 1250,
        ], 1, 1);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $workForm = OrderItemWorkForm::query()->where('source_quote_id', $quote->id)->latest('id')->firstOrFail();

        $graphicRole = Role::query()->where('key', 'graphic')->firstOrFail();
        $graphicUser = User::factory()->create([
            'name' => 'Graphic Decision User',
            'email' => 'graphic.convert@prodelya.local',
            'password' => 'password',
        ]);

        $graphicUser->userRoles()->create([
            'role_id' => $graphicRole->id,
            'tenant_account_id' => 1,
        ]);

        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote->fresh()));

        if ($response->getStatusCode() === 403) {
            $response->assertForbidden();
        } else {
            $response->assertOk();
            $response->assertSee('Gizli');
            $response->assertDontSee('1.250,00 TL');
        }

        $publicResponse = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $publicResponse->assertOk();
        $publicResponse->assertDontSee('grand_total');
        $publicResponse->assertDontSee('balance_due');
        $publicResponse->assertDontSee('Tahsilat');
    }

    public function test_tenant_outside_quote_show_returns_forbidden(): void
    {
        $foreignQuote = $this->createForeignTenantQuote();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $foreignQuote))
            ->assertForbidden();
    }

    private function createPromotionQuote(Company $customer, array $overrides = [], int $itemCount = 1, int $printCount = 0): Order
    {
        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-2026-'.str_pad((string) random_int(3000, 9999), 4, '0', STR_PAD_LEFT),
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'subtotal' => 1000,
            'vat_total' => 0,
            'grand_total' => 1000,
            'product_total' => 750,
            'print_total' => 250,
            'created_by' => $this->adminUser->id,
        ], $overrides));

        $remainingPrints = $printCount;

        for ($itemIndex = 1; $itemIndex <= $itemCount; $itemIndex++) {
            $printsForItem = $itemIndex === $itemCount ? $remainingPrints : min(1, $remainingPrints);
            $remainingPrints -= $printsForItem;

            $item = OrderItem::query()->create([
                'tenant_account_id' => $quote->tenant_account_id,
                'order_id' => $quote->id,
                'item_type' => 'product',
                'product_source' => 'manual',
                'product_name' => 'CTA Ürünü '.$itemIndex,
                'product_code' => 'CTA-00'.$itemIndex,
                'quantity' => 100,
                'unit' => 'Adet',
                'line_total' => 250,
                'unit_price' => 2.5,
                'has_print' => $printsForItem > 0,
                'print_total' => $printsForItem * 50,
                'status' => 'pending',
                'product_snapshot' => ['warning_badges' => []],
                'price_snapshot' => ['vat_mode' => 'taxable', 'vat_rate' => 20, 'product_line_total' => 250],
                'stock_snapshot' => ['supplier_stock_quantity' => 500],
            ]);

            for ($printIndex = 1; $printIndex <= $printsForItem; $printIndex++) {
                OrderItemPrint::query()->create([
                    'tenant_account_id' => $quote->tenant_account_id,
                    'order_id' => $quote->id,
                    'order_item_id' => $item->id,
                    'print_type' => 'UV Baskı '.$printIndex,
                    'print_option' => 'Tek taraf baskı',
                    'production_type' => 'Dış üretim / Fason',
                    'print_quantity' => 100,
                    'print_unit_price' => 0.5,
                    'print_total' => 50,
                    'note' => 'test baskı notu',
                    'status' => 'draft',
                ]);
            }
        }

        return $quote;
    }

    private function createForeignTenantQuote(): Order
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Dış Tenant CTA',
            'legal_name' => 'Dış Tenant CTA Ltd. Şti.',
            'slug' => 'dis-tenant-cta',
            'panel_subdomain' => 'distenantcta',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Tenant Dışı CTA Müşteri',
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'role_key' => 'customer',
        ]);

        return $this->createPromotionQuote($company, [
            'document_number' => 'TK-2026-9901',
            'grand_total' => 900,
        ], 1, 0);
    }
}
