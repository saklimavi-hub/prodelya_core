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
        $currency ??= $this->displayCurrency($item->order?->currency ?: 'TRY');
        $showPrintPriceDetails ??= $this->shouldShowPrintPriceDetails($item->order);
        $priceSnapshot = is_array($item->price_snapshot) ? $item->price_snapshot : [];

        $quantity = (float) ($item->quantity ?? 0);
        $productUnitPrice = round((float) data_get($priceSnapshot, 'actual_sales_unit_price_document', $item->unit_price ?? 0), 2);
        $productLineTotal = round((float) data_get($priceSnapshot, 'product_line_total_document', $item->line_total ?? 0), 2);

        /** @var Collection<int, OrderItemPrint> $prints */
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

        $printRows = $visiblePrints
            ->map(function (OrderItemPrint $print) use ($currency, $showPrintPriceDetails): array {
                $printUnitPrice = round((float) data_get($print->pricing_snapshot, 'document_unit_price', $print->print_unit_price ?? 0), 2);
                $printTotal = round((float) data_get($print->pricing_snapshot, 'document_total', $print->print_total ?? 0), 2);

                return [
                    'print_type' => $print->print_type,
                    'print_option' => $print->print_option,
                    'print_quantity' => (float) ($print->print_quantity ?? 0),
                    'print_unit_price' => $printUnitPrice,
                    'print_total' => $printTotal,
                    'note' => $print->note,
                    'show_price_details' => $showPrintPriceDetails,
                    'quantity_label' => $this->formatQuantity((float) ($print->print_quantity ?? 0), $print->orderItem?->unit),
                    'unit_price_label' => $this->formatMoney($printUnitPrice, $currency),
                    'total_label' => $this->formatMoney($printTotal, $currency),
                    'unit_price_title' => 'Baskı Birim Fiyatı',
                    'total_title' => 'Baskı Toplamı',
                ];
            })
            ->all();

        $hasPrints = $printRows !== [];
        $printTotal = round((float) collect($printRows)->sum('print_total'), 2);
        $commercialLineTotal = round($productLineTotal + $printTotal, 2);
        $combinedUnitPrice = $quantity > 0
            ? round($commercialLineTotal / $quantity, 2)
            : $productUnitPrice;

        if (! $hasPrints) {
            $priceMode = 'standard';
            $mainUnitLabel = 'Birim Fiyat';
            $mainTotalLabel = 'Satır Toplamı';
            $customerMainUnitPrice = $productUnitPrice;
            $customerMainTotal = $productLineTotal;
        } elseif ($showPrintPriceDetails) {
            $priceMode = 'product_only';
            $mainUnitLabel = 'Ürün Birim Fiyatı';
            $mainTotalLabel = 'Ürün Toplamı';
            $customerMainUnitPrice = $productUnitPrice;
            $customerMainTotal = $productLineTotal;
        } else {
            $priceMode = 'combined';
            $mainUnitLabel = 'Baskı Dahil Birim Fiyat';
            $mainTotalLabel = 'Baskı Dahil Satır Toplamı';
            $customerMainUnitPrice = $combinedUnitPrice;
            $customerMainTotal = $commercialLineTotal;
        }

        return [
            'quantity' => $quantity,
            'currency' => $currency,
            'has_prints' => $hasPrints,
            'price_mode' => $priceMode,
            'product_unit_price' => $productUnitPrice,
            'product_line_total' => $productLineTotal,
            'print_total' => $printTotal,
            'combined_unit_price' => $combinedUnitPrice,
            'combined_line_total' => $commercialLineTotal,
            'commercial_line_total' => $commercialLineTotal,
            'customer_main_unit_price' => $customerMainUnitPrice,
            'customer_main_total' => $customerMainTotal,
            'customer_unit_price' => $customerMainUnitPrice,
            'customer_line_total' => $customerMainTotal,
            'show_print_price_details' => $showPrintPriceDetails,
            'main_unit_label' => $mainUnitLabel,
            'main_total_label' => $mainTotalLabel,
            'commercial_total_label' => 'Ürün + Baskı Toplamı',
            'show_commercial_total' => $hasPrints && $showPrintPriceDetails,
            'prints' => $printRows,
            'customer_main_unit_price_label' => $this->formatMoney($customerMainUnitPrice, $currency),
            'customer_main_total_label' => $this->formatMoney($customerMainTotal, $currency),
            'customer_unit_price_label' => $this->formatMoney($customerMainUnitPrice, $currency),
            'customer_line_total_label' => $this->formatMoney($customerMainTotal, $currency),
            'product_unit_price_label' => $this->formatMoney($productUnitPrice, $currency),
            'product_line_total_label' => $this->formatMoney($productLineTotal, $currency),
            'combined_unit_price_label' => $this->formatMoney($combinedUnitPrice, $currency),
            'combined_line_total_label' => $this->formatMoney($commercialLineTotal, $currency),
            'commercial_line_total_label' => $this->formatMoney($commercialLineTotal, $currency),
            'print_total_label' => $this->formatMoney($printTotal, $currency),
        ];
    }

    public function formatMoney(mixed $amount, ?string $currency): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2, ',', '.') . ' ' . $this->displayCurrency($currency ?: 'TRY');
    }

    public function formatQuantity(mixed $quantity, ?string $unit): string
    {
        $formatted = number_format((float) $quantity, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }

    public function displayCurrency(?string $currency): string
    {
        return strtoupper((string) $currency) === 'TRY' ? 'TL' : (string) ($currency ?: 'TL');
    }
}
