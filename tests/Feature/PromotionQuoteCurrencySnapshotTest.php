<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PromotionQuote\QuoteCurrencyAccessService;
use App\Services\PromotionQuote\QuoteCurrencyPricingService;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PromotionQuoteCurrencySnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private User $financeUser;
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
        $this->financeUser = $this->createFinanceUser();
    }

    public function test_pricing_service_forces_try_when_multi_currency_module_is_closed(): void
    {
        $service = app(QuoteCurrencyPricingService::class);

        $this->assertSame(
            'TRY',
            $service->normalizeDocumentCurrency($this->tenant, ['multi_currency_enabled' => false], 'USD')
        );
    }

    public function test_pricing_service_builds_document_snapshot_with_manual_sale_protection(): void
    {
        $service = app(QuoteCurrencyPricingService::class);
        $snapshot = $service->buildItemPricing(
            $this->tenant,
            'USD',
            [
                'source_price' => 4.0,
                'source_currency' => 'USD',
                'base_price' => 140.0,
                'base_currency' => 'TRY',
                'conversion_status' => 'converted',
                'applied_rate' => 35.0,
                'rate_source' => 'tcmb',
                'rate_type' => 'forex_selling',
                'rate_date' => '2026-07-10',
            ],
            [
                'unit_price' => 5.0,
                'calculated_unit_price' => 4.0,
                'manual_unit_price' => true,
            ],
            '2026-07-10'
        );

        $this->assertSame('USD', $snapshot['document_currency']);
        $this->assertSame('USD', $snapshot['source_currency']);
        $this->assertSame(4.0, (float) $snapshot['source_price']);
        $this->assertSame(5.0, (float) $snapshot['actual_sales_unit_price_document']);
        $this->assertSame(4.0, (float) $snapshot['suggested_sales_unit_price_document']);
        $this->assertTrue((bool) $snapshot['manual_sales_price_override']);
    }


    public function test_manual_document_price_conversion_preserves_override_semantics(): void
    {
        $service = app(QuoteCurrencyPricingService::class);
        $snapshot = $service->convertManualDocumentPrice(
            $this->tenant,
            600,
            'TRY',
            'USD',
            '2026-07-10'
        );

        $this->assertSame('TRY', $snapshot['source_document_currency']);
        $this->assertSame('USD', $snapshot['document_currency']);
        $this->assertContains($snapshot['conversion_status'], ['converted', 'missing_rate', 'stale_rate']);

        if (in_array($snapshot['conversion_status'], ['converted', 'stale_rate'], true)) {
            $this->assertNotNull($snapshot['converted_amount']);
            $this->assertNotSame(600.0, (float) $snapshot['converted_amount']);
        } else {
            $this->assertNull($snapshot['converted_amount']);
        }
    }
    public function test_send_to_customer_locks_currency_snapshot_metadata(): void

    {
        $service = app(QuoteApprovalService::class);
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-CUR-SEND-001',
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => '2026-07-10',
            'invoice_status' => 'fis',
            'currency' => 'TRY',
            'tenant_base_currency' => 'TRY',
            'currency_policy' => 'multi_currency_draft',
            'currency_snapshot_summary' => ['overall_status' => 'converted'],
            'subtotal' => 1200,
            'vat_total' => 0,
            'grand_total' => 1200,
            'product_total' => 1200,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Snapshot Kilitli Ürün',
            'product_code' => 'CUR-SEND-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'product_snapshot' => ['product_name' => 'Snapshot Kilitli Ürün'],
            'price_snapshot' => [
                'document_currency' => 'TRY',
                'actual_sales_unit_price_document' => 120,
                'product_line_total_document' => 1200,
                'vat_breakdown' => [],
            ],
            'list_price' => 120,
            'discount_rate' => 0,
            'unit_price' => 120,
            'line_total' => 1200,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        $service->sendToCustomer($quote, ['contact_email' => 'customer@example.test'], $this->adminUser);

        $this->assertNotNull($quote->fresh()->currency_snapshot_locked_at);
    }

    public function test_refresh_currency_snapshot_preserves_manual_sales_price_for_draft_quote(): void
    {
        $this->setMultiCurrencyEnabled(true);
        $mock = Mockery::mock(QuoteCurrencyAccessService::class);
        $mock->shouldReceive('build')->andReturn([
            'multi_currency_enabled' => true,
            'can_view_currency_details' => true,
            'can_use_foreign_document_currency' => true,
            'can_refresh_rates' => true,
            'can_acknowledge_current_rates' => true,
            'can_use_manual_rate' => false,
        ]);
        $this->app->instance(QuoteCurrencyAccessService::class, $mock);

        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-CUR-REFRESH-001',
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => '2026-07-10',
            'invoice_status' => 'fis',
            'currency' => 'TRY',
            'tenant_base_currency' => 'TRY',
            'currency_policy' => 'multi_currency_draft',
            'subtotal' => 1600,
            'vat_total' => 0,
            'grand_total' => 1600,
            'product_total' => 1600,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Manual Kur Korumalı Ürün',
            'product_code' => 'CUR-REF-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'product_snapshot' => ['product_name' => 'Manual Kur Korumalı Ürün'],
            'price_snapshot' => [
                'source_price' => 4.0,
                'source_currency' => 'USD',
                'base_price' => 140.0,
                'base_cost' => 140.0,
                'base_currency' => 'TRY',
                'conversion_status' => 'converted',
                'document_currency' => 'TRY',
                'suggested_sales_unit_price_document' => 140.0,
                'actual_sales_unit_price_document' => 160.0,
                'manual_sales_price_override' => true,
                'manual_unit_price' => true,
                'vat_mode' => 'none',
                'vat_rate' => 0,
                'product_line_total' => 1600.0,
            ],
            'list_price' => 160,
            'discount_rate' => 0,
            'unit_price' => 160,
            'line_total' => 1600,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.currency.refresh', $quote));

        $response->assertRedirect();

        $item->refresh();
        $quote->refresh();

        $this->assertSame(160.0, (float) $item->unit_price);
        $this->assertTrue((bool) data_get($item->price_snapshot, 'manual_sales_price_override'));
        $this->assertSame(160.0, (float) data_get($item->price_snapshot, 'actual_sales_unit_price_document'));
        $this->assertNotNull($quote->rates_refreshed_at);
        $this->assertSame('not_required', data_get($quote->currency_snapshot_summary, 'overall_status'));
    }

    private function setMultiCurrencyEnabled(bool $enabled): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'multi_currency',
                'feature_key' => null,
            ],
            [
                'is_enabled' => $enabled,
            ]
        );
    }

    private function createFinanceUser(): User
    {
        $user = User::query()->create([
            'name' => 'Finance Quote User',
            'email' => 'finance-quote-' . uniqid() . '@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', 'finance')->firstOrFail()->id,
        ]);

        return $user;
    }
}
