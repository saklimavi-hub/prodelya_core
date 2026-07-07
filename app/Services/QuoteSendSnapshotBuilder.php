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

        $items = $quote->items->map(function (OrderItem $item) use ($quote, $showPrintPriceDetails): array {
            $priceSnapshot = is_array($item->price_snapshot) ? $item->price_snapshot : [];
            $customerFacing = $this->customerFacingPriceDisplayService->buildItem(
                $item,
                $quote->currency ?: 'TL'
            );

            return [
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'currency' => $quote->currency ?: 'TL',
                'vat_rate' => (float) data_get($priceSnapshot, 'vat_rate', 0),
                'line_total' => round((float) ($item->line_total ?? 0), 2),
                'customer_unit_price' => round((float) $customerFacing['customer_unit_price'], 2),
                'customer_line_total' => round((float) $customerFacing['customer_line_total'], 2),
                'show_print_price_details' => (bool) $customerFacing['show_print_price_details'],
                'print_lines' => $item->prints->map(function (OrderItemPrint $print) use ($showPrintPriceDetails): array {
                    return [
                        'print_type' => $print->print_type,
                        'print_option' => $print->print_option,
                        'print_quantity' => (float) ($print->print_quantity ?? 0),
                        'print_note' => $print->note,
                        'print_unit_price' => round((float) ($print->print_unit_price ?? 0), 2),
                        'print_total' => round((float) ($print->print_total ?? 0), 2),
                        'show_price_details' => $showPrintPriceDetails,
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
            'currency' => $quote->currency,
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
