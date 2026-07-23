<?php

namespace Tests\Feature;

use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementPurchasePriceCurrencyIsolationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_missing_supplier_purchase_source_does_not_fallback_to_sales_snapshot(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-ISO');

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-ISO-001', [
            'product_snapshot' => [
                'product_name' => 'İzolasyon Ürün',
                'product_code' => 'PROC-ISO-001',
            ],
            'price_snapshot' => [
                'source_price' => 10.00,
                'source_currency' => 'USD',
                'list_price' => 10.00,
                'document_currency' => 'TRY',
            ],
            'list_price' => 10.00,
            'unit_price' => 10.00,
            'line_total' => 600.00,
        ]);

        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        $item = $request->items()->firstOrFail();

        $this->assertNull($item->purchase_source_amount);
        $this->assertNull($item->purchase_source_currency);
        $this->assertNull($item->purchase_list_price);
        $this->assertNull($item->purchase_total);
        $this->assertSame('unresolved', data_get($item->purchase_price_snapshot, 'resolution_status'));
        $this->assertSame('missing_supplier_purchase_source', data_get($item->purchase_price_snapshot, 'warning_code'));
    }

    public function test_legacy_scalar_row_stays_legacy_unknown_and_does_not_backfill_sales_currency(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-LEGACY');

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-LEGACY-001', [
            'price_snapshot' => [
                'source_price' => 11.00,
                'source_currency' => 'USD',
                'list_price' => 11.00,
            ],
            'list_price' => 11.00,
            'unit_price' => 11.00,
        ]);

        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        $item = $request->items()->firstOrFail();
        $item->forceFill([
            'purchase_source_amount' => null,
            'purchase_source_currency' => null,
            'purchase_fx_rate' => null,
            'purchase_fx_rate_date' => null,
            'purchase_fx_rate_source' => null,
            'purchase_list_price_try' => null,
            'purchase_calculated_unit_price' => null,
            'purchase_manual_unit_price' => null,
            'purchase_manual_override' => false,
            'purchase_settlement_currency' => 'TRY',
            'purchase_price_snapshot' => null,
            'purchase_price_snapshot_version' => 1,
            'purchase_list_price' => 18.00,
            'discount_rate' => 10.00,
            'purchase_unit_price' => 16.20,
            'purchase_total' => 972.00,
        ])->save();

        app(SupplierProcurementRequestService::class)->updateRequestItems(
            $request->fresh('items.procurement'),
            [[
                'id' => $item->id,
                'requested_quantity' => '60',
                'purchase_list_price' => '18,00',
                'discount_rate' => '10,00',
                'purchase_unit_price' => '',
            ]],
            $this->adminUser
        );

        $item->refresh();

        $this->assertNull($item->purchase_source_amount);
        $this->assertNull($item->purchase_source_currency);
        $this->assertSame('legacy_snapshot', data_get($item->purchase_price_snapshot, 'resolution_status'));
        $this->assertSame('legacy_unknown', data_get($item->purchase_price_snapshot, 'warning_code'));
    }
}
