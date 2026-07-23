<?php

namespace Tests\Feature\Concerns;

use App\Models\ExchangeRate;
use App\Models\Supplier;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\SupplierProcurementRequest;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Support\Str;

trait BuildsProcurementDiscountFixtures
{
    use InteractsWithProcurementFixtures;

    protected function createFxRate(string $sourceCurrency, string $rate, string $date = '2026-07-16'): void
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

    protected function createProcurementWithRawSource(
        string $code,
        string $productCode,
        string $productName,
        string $sourceAmount,
        string $sourceCurrency,
        string $fxRate,
        float $quantity
    ): array {
        [$supplier, $source] = $this->createSupplierWithAccess($code);

        if ($sourceCurrency !== 'TRY') {
            $this->createFxRate($sourceCurrency, $fxRate);
        }

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => $code . ' Ham Kaynak',
            'supplier_product_code' => $productCode,
            'product_name' => $productName,
            'purchase_price' => $sourceAmount,
            'currency' => $sourceCurrency,
            'sync_status' => 'processed',
        ]);

        $grossTry = $sourceCurrency === 'TRY'
            ? round((float) $sourceAmount, 6)
            : round((float) $sourceAmount * (float) $fxRate, 6);

        $procurement = $this->createProcurement($supplier, $source, 'SP-' . $code . '-001', [
            'quantity' => $quantity,
            'product_snapshot' => [
                'product_name' => $productName,
                'product_code' => $productCode,
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $raw->id,
            ],
            'price_snapshot' => [
                'unit_price' => $grossTry,
                'line_total' => round($grossTry * $quantity, 2),
                'vat_total' => 0,
            ],
            'list_price' => $grossTry,
            'unit_price' => $grossTry,
            'line_total' => round($grossTry * $quantity, 2),
        ]);

        return [$supplier, $source, $raw, $procurement];
    }

    protected function createDraftRequestForRawSource(
        string $code,
        string $productCode,
        string $productName,
        string $sourceAmount,
        string $sourceCurrency,
        string $fxRate,
        float $quantity
    ): array {
        [$supplier, $source, $raw, $procurement] = $this->createProcurementWithRawSource(
            $code,
            $productCode,
            $productName,
            $sourceAmount,
            $sourceCurrency,
            $fxRate,
            $quantity
        );

        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        return [$supplier, $source, $raw, $procurement, $request, $request->items()->firstOrFail()];
    }

    protected function markRequestCompleted(SupplierProcurementRequest $request): SupplierProcurementRequest
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-requested', $request));

        $request = $request->fresh();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-supplier-ordered', $request));

        $request = $request->fresh(['items']);
        $item = $request->items->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.mark-partially-received', $request), [
                'received_items' => [
                    $item->id => number_format((float) $item->requested_quantity, 2, '.', ''),
                ],
            ]);

        return $request->fresh(['items']);
    }
}
