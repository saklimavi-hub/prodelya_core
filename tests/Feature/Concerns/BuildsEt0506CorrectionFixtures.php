<?php

namespace Tests\Feature\Concerns;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantStockReservation;

trait BuildsEt0506CorrectionFixtures
{
    use BuildsLocalProductSourceFixtures;

    protected function createEt0506LegacyFixture(): array
    {
        $supplier = $this->makeSupplierWithAccess('ET0506');
        $product = $this->makeCatalogProduct([
            'product_code' => 'ET-0506',
            'product_name' => 'ET-0506 Plastik Kalem',
            'local_stock_quantity' => 2000,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
            ]],
        ]);

        $blue = $this->makeCatalogVariant($product, [
            'variant_code' => 'ET-0506-MV',
            'variant_name' => 'ET-0506-MV Plastik Kalem Mavi',
            'variant_color' => 'Mavi',
            'local_stock_quantity' => 1000,
        ]);

        $red = $this->makeCatalogVariant($product, [
            'variant_code' => 'ET-0506-K',
            'variant_name' => 'ET-0506-K Plastik Kalem Kırmızı',
            'variant_color' => 'Kırmızı',
            'local_stock_quantity' => 1000,
        ]);

        $legacy = $this->makeLegacyUnassignedOperationalStock($product, 2000);
        $legacy->update(['legacy_assignment_status' => null]);

        return compact('supplier', 'product', 'blue', 'red', 'legacy');
    }

    protected function correctionCommandPayload(TenantCatalogProduct $product, TenantCatalogProductVariant $blue, TenantCatalogProductVariant $red, TenantLocalStock $legacy): array
    {
        return [
            '--tenant' => $product->tenant_account_id,
            '--product' => $product->id,
            '--legacy-stock' => $legacy->id,
            '--map' => [
                $blue->id . ':1000',
                $red->id . ':1000',
            ],
        ];
    }

    protected function createActiveLegacyReservation(TenantLocalStock $legacy): TenantStockReservation
    {
        $order = Order::query()->create([
            'tenant_account_id' => $legacy->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'ET0506-RES-' . uniqid(),
            'customer_company_id' => null,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $legacy->tenant_account_id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Reservation Fixture Item',
            'product_code' => 'ET0506-RES',
            'quantity' => 1,
            'unit' => 'Adet',
            'status' => 'pending',
        ]);

        return TenantStockReservation::query()->create([
            'tenant_account_id' => $legacy->tenant_account_id,
            'tenant_local_stock_id' => $legacy->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'quantity' => 50,
            'status' => TenantStockReservation::STATUS_ACTIVE,
            'reserved_at' => now(),
            'meta_json' => ['fixture' => true],
            'created_by' => $this->adminUser->id,
        ]);
    }
}
