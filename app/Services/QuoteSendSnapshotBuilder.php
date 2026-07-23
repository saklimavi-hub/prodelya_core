<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;

class QuoteSendSnapshotBuilder
{
    public function __construct(
        private readonly CustomerFacingPriceDisplayService $customerFacingPriceDisplayService,
    ) {
    }

    public function build(Order $quote): array
    {
        $quote->loadMissing([
            'customer:id,legal_name,short_name,email,phone,mobile',
            'items.prints',
        ]);

        $showPrintPriceDetails = $this->customerFacingPriceDisplayService->shouldShowPrintPriceDetails($quote);

        $items = $quote->items->map(function (OrderItem $item) use ($quote): array {
            $priceSnapshot = is_array($item->price_snapshot) ? $item->price_snapshot : [];
            $customerFacing = $this->customerFacingPriceDisplayService->buildItem(
                $item,
                $quote->currency ?: 'TRY'
            );

            return [
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'currency' => $quote->currency ?: 'TRY',
                'vat_rate' => (float) data_get($priceSnapshot, 'vat_rate', 0),
                'line_total' => round((float) data_get($priceSnapshot, 'product_line_total_document', $item->line_total ?? 0), 2),
                'product_unit_price' => round((float) $customerFacing['product_unit_price'], 2),
                'product_line_total' => round((float) $customerFacing['product_line_total'], 2),
                'combined_unit_price' => round((float) $customerFacing['combined_unit_price'], 2),
                'combined_line_total' => round((float) $customerFacing['combined_line_total'], 2),
                'commercial_line_total' => round((float) $customerFacing['commercial_line_total'], 2),
                'customer_main_unit_price' => round((float) $customerFacing['customer_main_unit_price'], 2),
                'customer_main_total' => round((float) $customerFacing['customer_main_total'], 2),
                'customer_unit_price' => round((float) $customerFacing['customer_unit_price'], 2),
                'customer_line_total' => round((float) $customerFacing['customer_line_total'], 2),
                'price_mode' => $customerFacing['price_mode'],
                'has_prints' => (bool) $customerFacing['has_prints'],
                'main_unit_label' => $customerFacing['main_unit_label'],
                'main_total_label' => $customerFacing['main_total_label'],
                'commercial_total_label' => $customerFacing['commercial_total_label'],
                'show_commercial_total' => (bool) $customerFacing['show_commercial_total'],
                'currency_snapshot' => [
                    'document_currency' => data_get($priceSnapshot, 'document_currency', $quote->currency ?: 'TRY'),
                    'tenant_base_currency' => data_get($priceSnapshot, 'tenant_base_currency'),
                    'conversion_status' => data_get($priceSnapshot, 'document_conversion_status'),
                    'rate_date' => data_get($priceSnapshot, 'rate_date'),
                    'rate_source' => data_get($priceSnapshot, 'rate_source'),
                    'rate_type' => data_get($priceSnapshot, 'rate_type'),
                    'fallback_used' => (bool) data_get($priceSnapshot, 'fallback_used', false),
                    'stale' => (bool) data_get($priceSnapshot, 'stale', false),
                ],
                'show_print_price_details' => (bool) $customerFacing['show_print_price_details'],
                'print_lines' => collect($customerFacing['prints'])->map(function (array $print): array {
                    return [
                        'print_type' => $print['print_type'],
                        'print_option' => $print['print_option'],
                        'print_quantity' => (float) ($print['print_quantity'] ?? 0),
                        'print_note' => $print['note'] ?? null,
                        'print_unit_price' => round((float) ($print['print_unit_price'] ?? 0), 2),
                        'print_total' => round((float) ($print['print_total'] ?? 0), 2),
                        'show_price_details' => (bool) ($print['show_price_details'] ?? false),
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        $totals = [
            'product_total' => round((float) ($quote->product_total ?? $quote->items->sum('line_total')), 2),
            'print_total' => round((float) ($quote->print_total ?? $quote->items->sum(fn (OrderItem $item) => $item->prints->sum('print_total'))), 2),
            'subtotal' => round((float) ($quote->subtotal ?? 0), 2),
            'vat_total' => round((float) ($quote->vat_total ?? 0), 2),
            'grand_total' => round((float) ($quote->grand_total ?? 0), 2),
            'vat_breakdown' => $this->normalizeVatBreakdown($quote),
        ];

        return [
            'quote_number' => $quote->document_number,
            'customer' => [
                'id' => $quote->customer?->id,
                'name' => $quote->customer?->legal_name,
                'short_name' => $quote->customer?->short_name,
            ],
            'document_type' => $quote->document_type,
            'invoice_status' => $quote->invoice_status,
            'currency' => $quote->currency ?: 'TRY',
            'currency_snapshot_summary' => is_array($quote->currency_snapshot_summary) ? $quote->currency_snapshot_summary : [],
            'show_print_price_details_to_customer' => $showPrintPriceDetails,
            'quote_date' => optional($quote->quote_date)->toDateString(),
            'valid_until' => optional($quote->valid_until)->toDateString(),
            'items' => $items,
            'totals' => $totals,
        ];
    }

    private function normalizeVatBreakdown(Order $quote): array
    {
        if (is_array($quote->vat_breakdown_json)) {
            return array_values(array_map(static function (array $slice): array {
                return [
                    'rate' => round((float) data_get($slice, 'rate', 0), 2),
                    'total' => round((float) data_get($slice, 'total', 0), 2),
                    'scope' => (string) data_get($slice, 'scope', 'general'),
                ];
            }, $quote->vat_breakdown_json));
        }

        $breakdown = [];

        foreach ($quote->items as $item) {
            foreach ((array) data_get($item->price_snapshot, 'vat_breakdown', []) as $slice) {
                $key = round((float) data_get($slice, 'rate', 0), 2) . '|' . (string) data_get($slice, 'scope', 'general');
                if (! isset($breakdown[$key])) {
                    $breakdown[$key] = [
                        'rate' => round((float) data_get($slice, 'rate', 0), 2),
                        'total' => 0.0,
                        'scope' => (string) data_get($slice, 'scope', 'general'),
                    ];
                }

                $breakdown[$key]['total'] += (float) data_get($slice, 'total', 0);
            }
        }

        return array_values(array_map(static function (array $slice): array {
            $slice['total'] = round((float) $slice['total'], 2);

            return $slice;
        }, $breakdown));
    }
}
