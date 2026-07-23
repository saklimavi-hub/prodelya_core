<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\ExchangeRate;
use App\Models\SupplierProductRaw;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementDraftPriceRefreshTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';
    private const CANONICAL_RATE_DATE = '2026-07-14';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_edit_screen_binds_effective_final_unit_price_input_for_canonical_snapshot(): void
    {
        [$supplier, $source, $raw] = $this->createUsdFixture('PROC-LEGACY-BIND');

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-LEGACY-BIND-001', [
            'quantity' => 10,
            'price_snapshot' => [
                'unit_price' => 12,
                'line_total' => 120,
                'vat_total' => 24,
            ],
            'product_snapshot' => [
                'product_name' => 'PZ CH60SY Etiket',
                'product_code' => 'PZ-CH60SY',
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $raw->id,
            ],
        ]);

        $this->pinProcurementQuoteDate($procurement);

        $request = $this->createSupplierRequest($procurement);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $request));

        $response->assertOk();
        $response->assertSee('value="164.49"', false);
        $response->assertSee('data-calculated-unit-value="164.488100"', false);
    }

    public function test_edit_screen_does_not_silently_refresh_legacy_draft_item_on_get(): void
    {
        [$supplier, $source, $raw] = $this->createUsdFixture('PROC-LEGACY-GET');

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-LEGACY-GET-001', [
            'quantity' => 10,
            'price_snapshot' => [
                'unit_price' => 12,
                'line_total' => 120,
                'vat_total' => 24,
            ],
            'product_snapshot' => [
                'product_name' => 'PZ CH60SY Etiket',
                'product_code' => 'PZ-CH60SY',
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $raw->id,
            ],
        ]);

        $this->pinProcurementQuoteDate($procurement);

        $request = $this->createSupplierRequest($procurement);
        $item = $this->convertItemToLegacyDraftState($request->items()->firstOrFail(), '164.00', '1640.00');
        $orderItem = $procurement->orderItem()->firstOrFail();
        $salesBefore = [
            'unit_price' => (float) $orderItem->unit_price,
            'line_total' => (float) $orderItem->line_total,
            'price_snapshot' => $orderItem->price_snapshot,
        ];

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $request->fresh()));

        $response->assertOk();
        $response->assertSee('Tedarikçi Fiyatını Yenile');

        $item->refresh();

        $this->assertNull($item->purchase_source_amount);
        $this->assertNull($item->purchase_source_currency);
        $this->assertSame([], $item->purchase_price_snapshot);
        $this->assertSame(164.00, (float) $item->purchase_unit_price);
        $this->assertSame(1640.00, (float) $item->purchase_total);
    }

    public function test_refresh_prices_action_rebuilds_exact_variant_supplier_truth_for_legacy_draft_item(): void
    {
        [$supplier, $source, $raw] = $this->createUsdFixture('PROC-LEGACY-REFRESH');

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-LEGACY-REFRESH-001', [
            'quantity' => 10,
            'price_snapshot' => [
                'unit_price' => 12,
                'line_total' => 120,
                'vat_total' => 24,
            ],
            'product_snapshot' => [
                'product_name' => 'PZ CH60SY Etiket',
                'product_code' => 'PZ-CH60SY',
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $raw->id,
            ],
        ]);

        $this->pinProcurementQuoteDate($procurement);

        $request = $this->createSupplierRequest($procurement);
        $item = $this->convertItemToLegacyDraftState($request->items()->firstOrFail(), '164.00', '1640.00');
        $orderItem = $procurement->orderItem()->firstOrFail();
        $salesBefore = [
            'unit_price' => (float) $orderItem->unit_price,
            'line_total' => (float) $orderItem->line_total,
            'price_snapshot' => $orderItem->price_snapshot,
        ];

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.refresh-prices', $request->fresh()));

        $response->assertRedirect(route('admin.procurements.supplier-requests.edit', $request));
        $response->assertSessionHas('success');

        $item->refresh();

        $this->assertSame(3.5, (float) $item->purchase_source_amount);
        $this->assertSame('USD', $item->purchase_source_currency);
        $this->assertSame(46.9966, round((float) $item->purchase_fx_rate, 4));
        $this->assertStringStartsWith('2026-07-14', (string) $item->purchase_fx_rate_date);
        $this->assertSame(164.4881, round((float) $item->purchase_list_price_try, 4));
        $this->assertSame(164.49, (float) $item->purchase_unit_price);
        $this->assertSame(1644.88, (float) $item->purchase_total);
        $this->assertSame('resolved', data_get($item->purchase_price_snapshot, 'resolution_status'));
        $this->assertSame('PZ-CH60SY', data_get($item->purchase_price_snapshot, 'supplier_product_code'));

        $orderItem->refresh();
        $this->assertSame($salesBefore['unit_price'], (float) $orderItem->unit_price);
        $this->assertSame($salesBefore['line_total'], (float) $orderItem->line_total);
        $this->assertSame($salesBefore['price_snapshot'], $orderItem->price_snapshot);

        $transaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('1644.88', (string) $transaction->amount);
        $this->assertSame(CurrentAccountTransaction::STATUS_OPEN, $transaction->status);
    }

    public function test_refresh_prices_action_returns_exact_validation_error_when_supplier_truth_is_missing(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-LEGACY-MISSING');

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-LEGACY-MISSING-001', [
            'quantity' => 10,
            'price_snapshot' => [
                'unit_price' => 12,
                'line_total' => 120,
                'vat_total' => 24,
            ],
            'product_snapshot' => [
                'product_name' => 'PZ CH60SY Etiket',
                'product_code' => 'PZ-CH60SY',
                'supplier_name' => $supplier->name,
            ],
        ]);

        $this->pinProcurementQuoteDate($procurement);

        $request = $this->createSupplierRequest($procurement);
        $item = $this->convertItemToLegacyDraftState($request->items()->firstOrFail(), '164.00', '1640.00');
        $orderItem = $procurement->orderItem()->firstOrFail();
        $salesBefore = [
            'unit_price' => (float) $orderItem->unit_price,
            'line_total' => (float) $orderItem->line_total,
            'price_snapshot' => $orderItem->price_snapshot,
        ];

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.supplier-requests.edit', $request))
            ->post(route('admin.procurements.supplier-requests.refresh-prices', $request->fresh()));

        $response->assertRedirect(route('admin.procurements.supplier-requests.edit', $request));
        $response->assertSessionHasErrors([
            'request' => 'PROC-LEGACY-MISSING Ürün için tedarikçi fiyatı yenilenemedi: exact tedarikçi varyant fiyatı bulunamadı.',
        ]);

        $item->refresh();
        $this->assertSame([], $item->purchase_price_snapshot);
        $this->assertSame(164.00, (float) $item->purchase_unit_price);
    }

    private function pinProcurementQuoteDate($procurement): void
    {
        $procurement->order?->forceFill([
            'quote_date' => self::CANONICAL_RATE_DATE,
        ])->save();

        $procurement->unsetRelation('order');
    }

    private function createUsdFixture(string $code): array
    {
        [$supplier, $source] = $this->createSupplierWithAccess($code);
        $this->createRate('USD', '46.99660000', self::CANONICAL_RATE_DATE);

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'PZ CH60SY Raw',
            'supplier_product_code' => 'PZ-CH60SY',
            'product_name' => 'PZ CH60SY Etiket',
            'purchase_price' => '3.5000',
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        return [$supplier, $source, $raw];
    }

    private function createRate(string $sourceCurrency, string $rate, string $date): void
    {
        ExchangeRate::query()
            ->where('provider', 'tcmb')
            ->where('rate_type', 'forex_selling')
            ->where('source_currency', $sourceCurrency)
            ->where('target_currency', 'TRY')
            ->whereDate('rate_date', $date)
            ->delete();

        ExchangeRate::query()->create([
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
            'source_currency' => $sourceCurrency,
            'target_currency' => 'TRY',
            'rate_date' => $date,
            'source_unit' => 1,
            'rate' => $rate,
            'fetched_at' => now(),
            'payload_hash' => (string) Str::uuid(),
            'meta_json' => [],
        ]);
    }

    private function convertItemToLegacyDraftState($item, string $unitPrice, string $total)
    {
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
            'purchase_manual_override_reason' => null,
            'purchase_price_snapshot' => [],
            'purchase_price_snapshot_version' => 0,
            'purchase_list_price' => $unitPrice,
            'purchase_unit_price' => $unitPrice,
            'purchase_total' => $total,
        ])->save();

        $transaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $item->tenant_account_id)
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->latest('id')
            ->first();

        if ($transaction) {
            $transaction->forceFill([
                'amount' => $total,
                'status' => CurrentAccountTransaction::STATUS_OPEN,
            ])->save();
        }

        return $item->fresh();
    }
}
