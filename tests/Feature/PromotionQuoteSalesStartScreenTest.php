<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSalesStartScreenTest extends TestCase
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

    public function test_sales_start_screen_shows_real_stats_state_based_actions_and_connected_order_info(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $preparedQuote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-1001',
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fis',
            'grand_total' => 1250,
        ], 1, 1);

        $approvedQuote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-1002',
            'status' => 'approved',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
            'invoice_status' => 'fatura',
            'grand_total' => 2400,
        ], 2, 3);

        $convertedQuote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-1003',
            'status' => 'approved',
            'workflow_status' => 'quote_converted',
            'invoice_status' => 'fatura',
            'grand_total' => 3850,
        ], 3, 5);

        $connectedOrder = Order::query()->create([
            'tenant_account_id' => 1,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-2026-0008',
            'source_quote_id' => $convertedQuote->id,
            'source_quote_number' => $convertedQuote->document_number,
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

        $this->createForeignTenantQuote();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $response->assertOk();
        $response->assertSee('Promosyon Teklifleri');
        $response->assertSee('Yeni Promosyon Teklifi');
        $response->assertSeeInOrder(['Açık Teklifler', '2']);
        $response->assertSeeInOrder(['Onaylananlar', '1']);
        $response->assertSeeInOrder(['Siparişe Dönüşenler', '1']);
        $response->assertDontSee('Taslak');

        $response->assertSee($preparedQuote->document_number);
        $response->assertSee($approvedQuote->document_number);
        $response->assertDontSee($convertedQuote->document_number);
        $response->assertDontSee('TK-2026-9001');

        $response->assertSee('Teklif');
        $response->assertSee('Onaylandı');

        $response->assertSee('data-testid="quote-'.$preparedQuote->id.'-action-show"', false);
        $response->assertSee('data-testid="quote-'.$preparedQuote->id.'-action-edit"', false);
        $response->assertSee('data-testid="quote-'.$preparedQuote->id.'-action-mark-approved"', false);
        $response->assertDontSee('data-testid="quote-'.$preparedQuote->id.'-action-open-order"', false);

        $response->assertSee('data-testid="quote-'.$approvedQuote->id.'-action-convert"', false);
        $response->assertSee(route('admin.promotion-quotes.show', $approvedQuote), false);

        $convertedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index', ['view' => 'converted']));

        $convertedResponse->assertOk();
        $convertedResponse->assertSee($convertedQuote->document_number);
        $convertedResponse->assertSee('Siparişe Dönüştü');
        $convertedResponse->assertSee('data-testid="quote-'.$convertedQuote->id.'-connected-order"', false);
        $convertedResponse->assertSee($connectedOrder->document_number);
        $convertedResponse->assertSee('data-testid="quote-'.$convertedQuote->id.'-action-open-order"', false);
        $convertedResponse->assertSee(route('admin.orders.show', $connectedOrder), false);
        $convertedResponse->assertDontSee('data-testid="quote-'.$convertedQuote->id.'-action-convert"', false);

        $response->assertDontSee('group_code', false);
        $response->assertDontSee('price_snapshot', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
    }

    public function test_graphic_role_user_does_not_see_quote_totals_on_sales_start_screen(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-1101',
            'status' => 'draft',
            'workflow_status' => 'quote',
            'grand_total' => 1250,
        ], 1, 1);

        $graphicRole = Role::query()->where('key', 'graphic')->firstOrFail();
        $graphicUser = User::factory()->create([
            'name' => 'Graphic User',
            'email' => 'graphic.user@prodelya.local',
            'password' => 'password',
        ]);

        $graphicUser->userRoles()->create([
            'role_id' => $graphicRole->id,
            'tenant_account_id' => 1,
        ]);

        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        if ($response->getStatusCode() === 403) {
            $response->assertForbidden();

            return;
        }

        $response->assertOk();
        $response->assertDontSee('1.250,00 TL');
        $response->assertSee('Promosyon Teklifleri');
    }

    private function createPromotionQuote(Company $customer, array $overrides = [], int $itemCount = 1, int $printCount = 0): Order
    {
        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-2026-'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT),
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
                'product_name' => 'Satış Başlangıç Ürünü '.$itemIndex,
                'product_code' => 'SS-00'.$itemIndex,
                'quantity' => 100,
                'unit' => 'Adet',
                'line_total' => 250,
                'unit_price' => 2.5,
                'has_print' => $printsForItem > 0,
                'print_total' => $printsForItem * 50,
                'status' => 'pending',
            ]);

            for ($printIndex = 1; $printIndex <= $printsForItem; $printIndex++) {
                OrderItemPrint::query()->create([
                    'tenant_account_id' => $quote->tenant_account_id,
                    'order_id' => $quote->id,
                    'order_item_id' => $item->id,
                    'print_type' => 'UV Baskı '.$printIndex,
                    'print_option' => 'Tek taraf',
                    'production_type' => 'İç üretim',
                    'print_quantity' => 100,
                    'print_unit_price' => 0.5,
                    'print_total' => 50,
                    'status' => 'draft',
                ]);
            }
        }

        return $quote;
    }

    private function createForeignTenantQuote(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Dış Tenant',
            'legal_name' => 'Dış Tenant Ltd. Şti.',
            'slug' => 'dis-tenant',
            'panel_subdomain' => 'distenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Tenant Dışı Müşteri',
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'role_key' => 'customer',
        ]);

        $this->createPromotionQuote($company, [
            'document_number' => 'TK-2026-9001',
            'grand_total' => 900,
        ], 1, 0);
    }
}
