<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderQuoteDraftCloneService
{
    private const SNAPSHOT_BLACKLIST = [
        'supplier_cost',
        'purchase_price',
        'profit',
        'margin',
        'group_code',
        'file_path',
        'raw',
        'projection',
        'payload',
        'tenant_id',
        'current_account_id',
        'transaction_id',
        'meta_json',
        'fason_maliyeti',
        'tedarikci_maliyeti',
        'ic_maliyet',
    ];

    public function __construct(
        protected NumberGenerationService $numberGenerationService,
    ) {}

    public function createRevisionDraft(Order $sourceOrder, ?User $actor = null): Order
    {
        return $this->cloneToQuoteDraft($sourceOrder, Order::COPY_TYPE_REVISION, $actor);
    }

    public function createRepeatOrderDraft(Order $sourceOrder, ?User $actor = null): Order
    {
        return $this->cloneToQuoteDraft($sourceOrder, Order::COPY_TYPE_REPEAT_ORDER, $actor);
    }

    private function cloneToQuoteDraft(Order $sourceOrder, string $copyType, ?User $actor = null): Order
    {
        $sourceOrder->loadMissing(['items.prints']);

        return DB::transaction(function () use ($sourceOrder, $copyType, $actor): Order {
            $revisionNumber = $copyType === Order::COPY_TYPE_REVISION
                ? $this->nextRevisionNumber($sourceOrder)
                : null;

            $quote = Order::create([
                'tenant_account_id' => $sourceOrder->tenant_account_id,
                'order_family' => $sourceOrder->order_family,
                'order_mode' => $sourceOrder->order_mode,
                'document_type' => 'quote',
                'document_number' => $this->numberGenerationService->generateNumber($sourceOrder->tenant_account_id, 'quote'),
                'source_order_id' => $sourceOrder->id,
                'copy_type' => $copyType,
                'revision_number' => $revisionNumber,
                'copied_by_user_id' => $actor?->id,
                'copied_at' => now(),
                'customer_company_id' => $sourceOrder->customer_company_id,
                'status' => 'draft',
                'workflow_status' => 'quote',
                'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
                'customer_approval_source' => null,
                'quote_date' => now()->toDateString(),
                'valid_until' => null,
                'invoice_status' => $sourceOrder->invoice_status,
                'delivery_type' => $sourceOrder->delivery_type,
                'delivery_type_id' => $sourceOrder->delivery_type_id,
                'show_print_price_details_to_customer' => $sourceOrder->shouldShowPrintPriceDetailsToCustomer(),
                'notes' => $sourceOrder->notes,
                'currency' => $sourceOrder->currency,
                'subtotal' => $sourceOrder->subtotal,
                'vat_total' => $sourceOrder->vat_total,
                'grand_total' => $sourceOrder->grand_total,
                'product_total' => $sourceOrder->product_total,
                'print_total' => $sourceOrder->print_total,
                'vat_breakdown_json' => $this->sanitizeSnapshot($sourceOrder->vat_breakdown_json),
                'created_by' => $actor?->id,
                'last_sent_at' => null,
                'approved_at' => null,
                'rejected_at' => null,
                'revision_requested_at' => null,
            ]);

            foreach ($sourceOrder->items as $sourceItem) {
                $item = OrderItem::create([
                    'tenant_account_id' => $quote->tenant_account_id,
                    'order_id' => $quote->id,
                    'tenant_catalog_product_id' => $sourceItem->tenant_catalog_product_id,
                    'tenant_catalog_product_variant_id' => $sourceItem->tenant_catalog_product_variant_id,
                    'standard_product_id' => $sourceItem->standard_product_id,
                    'standard_product_variant_id' => $sourceItem->standard_product_variant_id,
                    'item_type' => $sourceItem->item_type,
                    'product_source' => $sourceItem->product_source,
                    'product_name' => $sourceItem->product_name,
                    'product_code' => $sourceItem->product_code,
                    'supplier_id' => $sourceItem->supplier_id,
                    'supplier_source_id' => $sourceItem->supplier_source_id,
                    'quantity' => $sourceItem->quantity,
                    'unit' => $sourceItem->unit,
                    'description' => $sourceItem->description,
                    'product_snapshot' => $this->sanitizeSnapshot($sourceItem->product_snapshot),
                    'price_snapshot' => $this->sanitizeSnapshot($sourceItem->price_snapshot),
                    'stock_snapshot' => $this->sanitizeSnapshot($sourceItem->stock_snapshot),
                    'catalog_source' => $sourceItem->catalog_source,
                    'list_price' => $sourceItem->list_price,
                    'discount_rate' => $sourceItem->discount_rate,
                    'unit_price' => $sourceItem->unit_price,
                    'line_total' => $sourceItem->line_total,
                    'has_print' => $sourceItem->has_print,
                    'print_total' => $sourceItem->print_total,
                    'status' => 'draft',
                ]);

                foreach ($sourceItem->prints as $sourcePrint) {
                    OrderItemPrint::create([
                        'tenant_account_id' => $quote->tenant_account_id,
                        'order_id' => $quote->id,
                        'order_item_id' => $item->id,
                        'tenant_print_setting_id' => $sourcePrint->tenant_print_setting_id,
                        'standard_print_type_id' => $sourcePrint->standard_print_type_id,
                        'tenant_print_option_id' => $sourcePrint->tenant_print_option_id,
                        'print_type' => $sourcePrint->print_type,
                        'print_option' => $sourcePrint->print_option,
                        'print_location' => $sourcePrint->print_location,
                        'production_type' => $sourcePrint->production_type,
                        'subcontractor_company_id' => $sourcePrint->subcontractor_company_id,
                        'print_color' => $sourcePrint->print_color,
                        'print_size' => $sourcePrint->print_size,
                        'cliche_status' => $sourcePrint->cliche_status,
                        'setup_pricing_enabled' => $sourcePrint->setup_pricing_enabled,
                        'setup_type' => $sourcePrint->setup_type,
                        'setup_status' => $sourcePrint->setup_status,
                        'setup_total_amount' => $sourcePrint->setup_total_amount,
                        'setup_distribution_quantity' => $sourcePrint->setup_distribution_quantity,
                        'setup_unit_amount' => $sourcePrint->setup_unit_amount,
                        'base_print_unit_price' => $sourcePrint->base_print_unit_price,
                        'print_quantity' => $sourcePrint->print_quantity,
                        'print_unit_price' => $sourcePrint->print_unit_price,
                        'print_total' => $sourcePrint->print_total,
                        'note' => $sourcePrint->note,
                        'production_note' => null,
                        'status' => 'draft',
                    ]);
                }
            }

            return $quote->fresh(['sourceOrder', 'items.prints']);
        });
    }

    private function nextRevisionNumber(Order $sourceOrder): int
    {
        return Order::query()
            ->where('tenant_account_id', $sourceOrder->tenant_account_id)
            ->where('source_order_id', $sourceOrder->id)
            ->where('copy_type', Order::COPY_TYPE_REVISION)
            ->lockForUpdate()
            ->count() + 1;
    }

    private function sanitizeSnapshot(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::SNAPSHOT_BLACKLIST, true)) {
                continue;
            }

            $sanitized[$key] = is_array($item)
                ? $this->sanitizeSnapshot($item)
                : $item;
        }

        return $sanitized;
    }
}
