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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCurrencyDetailConditionalUiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private Company $customer;
    private User $financeUser;
    private User $operatorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->enableMultiCurrency();
        $this->financeUser = $this->createTenantUser('quote-currency-finance', ['view_quote_totals']);
        $this->operatorUser = $this->createTenantUser('quote-currency-operator', []);
    }

    public function test_tl_quote_shows_only_currency_label()
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-CUR-TRY-001',
            'currency' => 'TRY',
            'status' => 'draft',
        ]);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get(route('admin.promotion-quotes.show', $quote))
            ->assertOk()
            ->assertSee('Para birimi: <strong>TL</strong>', false)
            ->assertDontSee('Kullanılan kur:')
            ->assertDontSee('Kur tarihi:')
            ->assertDontSee('Kuru Yenile')
            ->assertDontSee('Mevcut Kuru Koru');
    }

    public function test_usd_draft_quote_shows_currency_details_and_actions()
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-CUR-USD-001',
            'currency' => 'USD',
            'status' => 'draft',
            'currency_snapshot_summary' => [
                'rate' => '32,5000',
                'rate_date' => now()->subDay()->toDateString(),
                'overall_status' => 'converted',
            ],
        ]);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get(route('admin.promotion-quotes.show', $quote))
            ->assertOk()
            ->assertSee('Para birimi: <strong>USD</strong>', false)
            ->assertSee('Kullanılan kur:')
            ->assertSee('Kur tarihi:')
            ->assertSee('Kuru Yenile')
            ->assertSee('Mevcut Kuru Koru');
    }

    public function test_eur_draft_quote_shows_currency_details_and_actions()
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-CUR-EUR-001',
            'currency' => 'EUR',
            'status' => 'draft',
            'currency_snapshot_summary' => [
                'rate' => '35,2500',
                'rate_date' => now()->subDay()->toDateString(),
                'overall_status' => 'converted',
            ],
        ]);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get(route('admin.promotion-quotes.show', $quote))
            ->assertOk()
            ->assertSee('Para birimi: <strong>EUR</strong>', false)
            ->assertSee('Kullanılan kur:')
            ->assertSee('Kur tarihi:')
            ->assertSee('Kuru Yenile')
            ->assertSee('Mevcut Kuru Koru');
    }

    public function test_sent_foreign_quote_shows_readonly_currency_details()
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-CUR-SENT-001',
            'currency' => 'USD',
            'status' => 'sent',
            'currency_snapshot_locked_at' => now()->subHours(2),
            'currency_snapshot_summary' => [
                'rate' => '32,5000',
                'rate_date' => now()->subDay()->toDateString(),
                'locked_at' => now()->subHours(2)->toIso8601String(),
                'overall_status' => 'converted',
            ],
        ]);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get(route('admin.promotion-quotes.show', $quote))
            ->assertOk()
            ->assertSee('Para birimi: <strong>USD</strong>', false)
            ->assertSee('Kullanılan kur:')
            ->assertSee('Kur tarihi:');
    }

    public function test_locked_foreign_quote_shows_readonly_currency_details()
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-CUR-LOCK-001',
            'currency' => 'EUR',
            'status' => 'approved',
            'currency_snapshot_locked_at' => now()->subHours(2),
            'currency_snapshot_summary' => [
                'rate' => '35,2500',
                'rate_date' => now()->subDay()->toDateString(),
                'locked_at' => now()->subHours(2)->toIso8601String(),
                'overall_status' => 'converted',
            ],
        ]);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get(route('admin.promotion-quotes.show', $quote))
            ->assertOk()
            ->assertSee('Para birimi: <strong>EUR</strong>', false)
            ->assertSee('Kullanılan kur:')
            ->assertSee('Kur tarihi:');
    }

    public function test_operator_cannot_see_currency_actions_on_draft_foreign_quote()
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-CUR-OP-001',
            'currency' => 'USD',
            'status' => 'draft',
            'currency_snapshot_summary' => [
                'rate' => '32,5000',
                'rate_date' => now()->subDay()->toDateString(),
                'overall_status' => 'converted',
            ],
        ]);

        $this->actingAs($this->operatorUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get(route('admin.promotion-quotes.show', $quote))
            ->assertOk()
            ->assertSee('Para birimi: <strong>USD</strong>', false)
            ->assertDontSee('Kuru Yenile')
            ->assertDontSee('Mevcut Kuru Koru');
    }

    public function test_currency_refresh_post_is_blocked_for_locked_quotes()
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-CUR-POST-REFRESH-001',
            'currency' => 'USD',
            'status' => 'sent',
            'currency_snapshot_locked_at' => now()->subHours(2),
            'currency_snapshot_summary' => [
                'rate' => '32,5000',
                'rate_date' => now()->subDay()->toDateString(),
                'locked_at' => now()->subHours(2)->toIso8601String(),
                'overall_status' => 'converted',
            ],
        ]);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->post(route('admin.promotion-quotes.currency.refresh', $quote))
            ->assertRedirect();
    }

    public function test_currency_acknowledge_post_is_blocked_for_locked_quotes()
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-CUR-POST-ACK-001',
            'currency' => 'EUR',
            'status' => 'approved',
            'currency_snapshot_locked_at' => now()->subHours(2),
            'currency_snapshot_summary' => [
                'rate' => '35,2500',
                'rate_date' => now()->subDay()->toDateString(),
                'locked_at' => now()->subHours(2)->toIso8601String(),
                'overall_status' => 'converted',
            ],
        ]);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->post(route('admin.promotion-quotes.currency.acknowledge', $quote))
            ->assertRedirect();
    }

    private function createTenantUser(string $roleKey, array $permissions): User
    {
        $user = User::factory()->create();

        $role = Role::factory()->create([
            'tenant_account_id' => $this->tenant->id,
            'key' => $roleKey,
            'name' => ucfirst(str_replace('-', ' ', $roleKey)),
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        UserRole::factory()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function createQuote(array $attributes = []): Order
    {
        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-CUR-BASE-001',
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-07-11',
            'valid_until' => '2026-07-18',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TRY',
            'tenant_base_currency' => 'TRY',
            'currency_policy' => 'multi_currency_draft',
            'subtotal' => 1000,
            'vat_total' => 200,
            'grand_total' => 1200,
            'product_total' => 1000,
            'print_total' => 0,
            'created_by' => $this->financeUser->id,
        ], $attributes));

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Currency Detail Test Product',
            'product_code' => 'CUR-DETAIL-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'unit_price' => 100,
            'line_total' => 1000,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
            'product_snapshot' => ['product_name' => 'Currency Detail Test Product'],
            'price_snapshot' => [
                'document_currency' => $quote->currency,
                'actual_sales_unit_price_document' => 100,
                'product_line_total_document' => 1000,
            ],
        ]);

        return $quote->fresh();
    }

    private function enableMultiCurrency(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'multi_currency',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    private function tenantHost(): string
    {
        return $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }
}
