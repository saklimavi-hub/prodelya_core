<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromotionQuoteManualApprovalSchemaTest extends TestCase
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

    public function test_orders_table_has_customer_approval_source_column(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'customer_approval_source'));
    }

    public function test_mark_approved_route_updates_manual_approval_fields_without_schema_error(): void
    {
        $quote = $this->createQuote('TK-2026-9201');

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.mark-approved', $quote));

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $response->assertSessionHas('success', 'Teklif onaylandı olarak işaretlendi.');

        $quote->refresh();
        $this->assertSame('approved', $quote->status);
        $this->assertSame(Order::CUSTOMER_APPROVAL_APPROVED, $quote->customer_approval_status);
        $this->assertSame(Order::CUSTOMER_APPROVAL_SOURCE_INTERNAL_MANUAL, $quote->customer_approval_source);
        $this->assertNotNull($quote->approved_at);
    }

    public function test_converted_quote_cannot_be_marked_approved(): void
    {
        $quote = $this->createQuote('TK-2026-9202', [
            'status' => 'approved',
            'workflow_status' => 'quote_converted',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.mark-approved', $quote));

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $response->assertSessionHasErrors('error');
    }

    public function test_foreign_tenant_quote_is_forbidden_for_mark_approved(): void
    {
        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Tenant',
            'legal_name' => 'Foreign Tenant Ltd. Sti.',
            'slug' => 'foreign-approval',
            'panel_subdomain' => 'foreign-approval',
            'status' => 'active',
        ]);

        $foreignCustomer = Company::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'company_type' => 'customer',
            'legal_name' => 'Foreign Customer',
            'company_code' => 'FRG-001',
            'status' => 'active',
        ]);

        $foreignQuote = Order::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-FOREIGN-9203',
            'customer_company_id' => $foreignCustomer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-15',
            'valid_until' => '2026-06-22',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1000,
            'vat_total' => 200,
            'grand_total' => 1200,
            'product_total' => 1000,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        $foreignQuote->items()->create([
            'tenant_account_id' => $foreignTenant->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Foreign Quote Item',
            'product_code' => 'FRG-ITEM-001',
            'quantity' => 5,
            'unit' => 'Adet',
            'list_price' => 200,
            'discount_rate' => 0,
            'unit_price' => 200,
            'line_total' => 1000,
            'print_total' => 0,
            'vat_rate' => 20,
            'vat_amount' => 200,
            'total_amount' => 1200,
            'sequence_no' => 1,
            'has_print' => false,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.mark-approved', $foreignQuote))
            ->assertForbidden();
    }

    private function createQuote(string $documentNumber, array $overrides = []): Order
    {
        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-15',
            'valid_until' => '2026-06-22',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'product_total' => 1200,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ], $overrides));

        $quote->items()->create([
            'tenant_account_id' => $this->tenant->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Approval Schema Test Ürünü',
            'product_code' => 'APPROVAL-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'list_price' => 120,
            'discount_rate' => 0,
            'unit_price' => 120,
            'line_total' => 1200,
            'print_total' => 0,
            'vat_rate' => 20,
            'vat_amount' => 240,
            'total_amount' => 1440,
            'sequence_no' => 1,
            'has_print' => false,
        ]);

        return $quote;
    }
}
