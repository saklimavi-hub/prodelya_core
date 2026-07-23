<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\QuoteSendSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPrintPriceDisplayContractV2Test extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_send_snapshot_freezes_separate_and_combined_customer_price_contract_fields(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $customer = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $visibleQuote = $this->createQuote($tenant->id, $customer->id, $adminUser->id, 'TK-SNAPSHOT-V2-01', true);
        $visibleSnapshot = app(QuoteSendSnapshotBuilder::class)->build($visibleQuote->fresh(['customer', 'items.prints']));

        $this->assertTrue(data_get($visibleSnapshot, 'show_print_price_details_to_customer'));
        $this->assertSame(10.0, data_get($visibleSnapshot, 'items.0.customer_main_unit_price'));
        $this->assertSame(1000.0, data_get($visibleSnapshot, 'items.0.customer_main_total'));
        $this->assertSame(11.0, data_get($visibleSnapshot, 'items.0.combined_unit_price'));
        $this->assertSame(1100.0, data_get($visibleSnapshot, 'items.0.commercial_line_total'));
        $this->assertSame('Ürün Birim Fiyatı', data_get($visibleSnapshot, 'items.0.main_unit_label'));
        $this->assertTrue(data_get($visibleSnapshot, 'items.0.print_lines.0.show_price_details'));

        $hiddenQuote = $this->createQuote($tenant->id, $customer->id, $adminUser->id, 'TK-SNAPSHOT-V2-02', false);
        $hiddenSnapshot = app(QuoteSendSnapshotBuilder::class)->build($hiddenQuote->fresh(['customer', 'items.prints']));

        $this->assertFalse(data_get($hiddenSnapshot, 'show_print_price_details_to_customer'));
        $this->assertSame(11.0, data_get($hiddenSnapshot, 'items.0.customer_main_unit_price'));
        $this->assertSame(1100.0, data_get($hiddenSnapshot, 'items.0.customer_main_total'));
        $this->assertSame('Baskı Dahil Birim Fiyat', data_get($hiddenSnapshot, 'items.0.main_unit_label'));
        $this->assertFalse(data_get($hiddenSnapshot, 'items.0.print_lines.0.show_price_details'));
    }

    private function createQuote(int $tenantId, int $customerId, int $adminUserId, string $documentNumber, bool $showPrintPriceDetails): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $tenantId,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $customerId,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-07-11',
            'valid_until' => '2026-07-18',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1100,
            'vat_total' => 220,
            'grand_total' => 1320,
            'product_total' => 1000,
            'print_total' => 100,
            'show_print_price_details_to_customer' => $showPrintPriceDetails,
            'created_by' => $adminUserId,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenantId,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Snapshot V2 Ürünü',
            'product_code' => 'SNAP-V2-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'unit_price' => 10,
            'line_total' => 1000,
            'has_print' => true,
            'print_total' => 100,
            'status' => 'pending',
            'price_snapshot' => [
                'product_total' => 1000,
                'vat_rate' => 20,
            ],
        ]);

        $item->prints()->create([
            'tenant_account_id' => $tenantId,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Farklı adet',
            'print_quantity' => 50,
            'print_unit_price' => 2,
            'print_total' => 100,
            'status' => 'draft',
        ]);

        return $quote;
    }
}
