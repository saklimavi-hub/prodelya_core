<?php

namespace Tests\Feature\Concerns;

use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;

trait BuildsOrderRevisionCompareFixtures
{
    use BuildsOrderRevisionDraftFixtures;

    protected function setUpOrderRevisionCompareFixtures(): void
    {
        $this->setUpOrderRevisionDraftFixtures();
    }

    protected function createComparableRevisionDraft(array $sourceOverrides = []): array
    {
        $sourceOrder = $this->createSourceOrder($sourceOverrides);
        $revisionDraft = $this->createRevisionDraft($sourceOrder);

        return [$sourceOrder->fresh(['items.prints', 'procurements', 'printProductions', 'deliveries', 'payments']), $revisionDraft->fresh(['items.prints', 'sourceOrder'])];
    }

    protected function revisionCompareRoute(Order $quote): string
    {
        return route('admin.promotion-quotes.revision-compare', $quote);
    }

    protected function revisionApplyRoute(Order $quote): string
    {
        return route('admin.promotion-quotes.revision-apply', $quote);
    }

    protected function setProcurementStatus(Order $order, string $status): void
    {
        $order->procurements()->update(['procurement_status' => $status]);
    }

    protected function setProductionStatus(Order $order, string $status): void
    {
        $order->printProductions()->update(['production_status' => $status]);
    }

    protected function setDeliveryStatus(Order $order, string $status): void
    {
        $order->deliveries()->update(['delivery_status' => $status]);
    }

    protected function mutateRevisionProduct(Order $draft, string $code, string $name): void
    {
        $draft->items()->firstOrFail()->update([
            'product_code' => $code,
            'product_name' => $name,
        ]);
    }

    protected function mutateRevisionQuantity(Order $draft, float $quantity): void
    {
        $item = $draft->items()->firstOrFail();
        $item->update([
            'quantity' => $quantity,
            'line_total' => round((float) $item->unit_price * $quantity, 2),
        ]);
    }

    protected function mutateRevisionPrice(Order $draft, float $unitPrice): void
    {
        $item = $draft->items()->firstOrFail();
        $item->update([
            'unit_price' => $unitPrice,
            'line_total' => round($unitPrice * (float) $item->quantity, 2),
        ]);

        $draft->update([
            'subtotal' => $item->fresh()->line_total + (float) $draft->print_total,
            'grand_total' => $item->fresh()->line_total + (float) $draft->print_total + (float) $draft->vat_total,
        ]);
    }

    protected function mutateRevisionPrintType(Order $draft, string $printType, string $printOption = 'Çift taraf'): void
    {
        $draft->items()->firstOrFail()->prints()->firstOrFail()->update([
            'print_type' => $printType,
            'print_option' => $printOption,
        ]);
    }

    protected function mutateRevisionPrintNote(Order $draft, string $note): void
    {
        $draft->items()->firstOrFail()->prints()->firstOrFail()->update([
            'note' => $note,
        ]);
    }

    protected function mutateRevisionDeliveryType(Order $draft, string $deliveryType): void
    {
        $draft->update(['delivery_type' => $deliveryType]);
    }

    protected function addRevisionItem(Order $draft): void
    {
        $draft->items()->create([
            'tenant_account_id' => $draft->tenant_account_id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Ek Revizyon Ürünü',
            'product_code' => 'REV-NEW',
            'quantity' => 25,
            'unit' => 'Adet',
            'description' => 'Yeni kalem',
            'product_snapshot' => ['product_name' => 'Ek Revizyon Ürünü'],
            'price_snapshot' => ['product_total' => 1250],
            'stock_snapshot' => ['visible_stock_quantity' => 25],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 55,
            'discount_rate' => 0,
            'unit_price' => 50,
            'line_total' => 1250,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'draft',
        ]);
    }
}
