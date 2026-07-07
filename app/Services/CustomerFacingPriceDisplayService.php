<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use Illuminate\Support\Collection;

class CustomerFacingPriceDisplayService
{
    public function shouldShowPrintPriceDetails(?Order $document): bool
    {
        if ($document === null) {
            return true;
        }

        $value = $document->show_print_price_details_to_customer;

        return $value === null ? true : (bool) $value;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildItems(iterable $items, ?string $currency = null, ?bool $showPrintPriceDetails = null): array
    {
        return collect($items)
            ->map(fn (OrderItem $item) => $this->buildItem($item, $currency, $showPrintPriceDetails))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildItem(OrderItem $item, ?string $currency = null, ?bool $showPrintPriceDetails = null): array
    {
        $currency ??= $item->order?->currency ?: 'TL';
        $showPrintPriceDetails ??= $this->shouldShowPrintPriceDetails($item->order);

        $quantity = (float) ($item->quantity ?? 0);
        $productUnitPrice = round((float) ($item->unit_price ?? 0), 2);
        $productLineTotal = round((float) ($item->line_total ?? 0), 2);

        /** @var Collection<int,OrderItemPrint> $prints */
        $prints = $item->relationLoaded('prints')
            ? $item->prints
            : $item->prints()->get();

        $visiblePrints = $prints
            ->filter(function (OrderItemPrint $print): bool {
                return filled($print->print_type)
                    || filled($print->print_option)
                    || (float) $print->print_quantity > 0
                    || (float) $print->print_unit_price > 0
                    || (float) $print->print_total > 0
                    || filled($print->note);
            })
            ->values();

        $printTotal = round((float) $visiblePrints->sum(fn (OrderItemPrint $print) => (float) ($print->print_total ?? 0)), 2);
        $customerLineTotal = round($productLineTotal + $printTotal, 2);
        $customerUnitPrice = $quantity > 0
            ? round($customerLineTotal / $quantity, 2)
            : $productUnitPrice;

        return [
            'quantity' => $quantity,
            'currency' => $currency,
            'product_unit_price' => $productUnitPrice,
            'product_line_total' => $productLineTotal,
            'print_total' => $printTotal,
            'customer_unit_price' => $customerUnitPrice,
            'customer_line_total' => $customerLineTotal,
            'show_print_price_details' => $showPrintPriceDetails,
            'prints' => $visiblePrints
                ->map(function (OrderItemPrint $print) use ($currency, $showPrintPriceDetails): array {
                    return [
                        'print_type' => $print->print_type,
                        'print_option' => $print->print_option,
                        'print_quantity' => (float) ($print->print_quantity ?? 0),
                        'print_unit_price' => round((float) ($print->print_unit_price ?? 0), 2),
                        'print_total' => round((float) ($print->print_total ?? 0), 2),
                        'note' => $print->note,
                        'show_price_details' => $showPrintPriceDetails,
                        'quantity_label' => $this->formatQuantity((float) ($print->print_quantity ?? 0), $print->orderItem?->unit),
                        'unit_price_label' => $this->formatMoney((float) ($print->print_unit_price ?? 0), $currency),
                        'total_label' => $this->formatMoney((float) ($print->print_total ?? 0), $currency),
                    ];
                })
                ->all(),
            'customer_unit_price_label' => $this->formatMoney($customerUnitPrice, $currency),
            'customer_line_total_label' => $this->formatMoney($customerLineTotal, $currency),
            'product_unit_price_label' => $this->formatMoney($productUnitPrice, $currency),
            'product_line_total_label' => $this->formatMoney($productLineTotal, $currency),
            'print_total_label' => $this->formatMoney($printTotal, $currency),
        ];
    }

    public function formatMoney(mixed $amount, ?string $currency): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2, ',', '.') . ' ' . ($currency ?: 'TL');
    }

    public function formatQuantity(mixed $quantity, ?string $unit): string
    {
        $formatted = number_format((float) $quantity, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }
}
