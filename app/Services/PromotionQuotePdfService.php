<?php

namespace App\Services;

use App\Models\Order;
use App\Models\QuoteApprovalRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PromotionQuotePdfService
{
    public function __construct(
        private readonly TenantCompanyProfileService $tenantCompanyProfileService
    ) {
    }

    public function buildViewData(Order $quote): array
    {
        $quote->loadMissing([
            'tenant',
            'customer',
            'items.prints',
            'latestQuoteApprovalRequest',
        ]);

        $approvalRequest = $quote->latestQuoteApprovalRequest;
        $approvalUrl = $this->resolveApprovalUrl($approvalRequest);
        $visibleItems = $quote->items->values()->map(function ($item, int $index): array {
            $prints = $item->prints
                ->filter(function ($print) {
                    return filled($print->print_type)
                        || filled($print->print_option)
                        || (float) $print->print_quantity > 0
                        || (float) $print->print_unit_price > 0
                        || (float) $print->print_total > 0
                        || filled($print->note);
                })
                ->values()
                ->map(function ($print, int $printIndex): array {
                    return [
                        'label' => chr(97 + $printIndex),
                        'title' => trim(collect([$print->print_type, $print->print_option])->filter()->implode(' ')),
                        'quantity' => (float) $print->print_quantity,
                        'unit_price' => (float) $print->print_unit_price,
                        'total' => (float) $print->print_total,
                        'note' => $print->note,
                    ];
                })
                ->all();

            return [
                'index' => $index + 1,
                'product_name' => $item->product_name ?: '-',
                'product_code' => $item->product_code ?: '-',
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit ?: 'Adet',
                'description' => $item->description,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'print_total' => (float) $item->print_total,
                'prints' => $prints,
            ];
        })->all();

        return [
            'quote' => $quote,
            'tenantName' => $quote->tenant
                ? $this->tenantCompanyProfileService->getProfile($quote->tenant)['display_name']
                : 'Prodelya',
            'customerName' => $quote->customer?->legal_name ?: '-',
            'customerEmail' => $quote->customer?->email,
            'customerPhone' => $quote->customer?->mobile ?: $quote->customer?->phone,
            'approvalUrl' => $approvalUrl,
            'approvalStatusLabel' => $approvalRequest?->safeStatusLabel(),
            'items' => $visibleItems,
            'vatRows' => $this->buildVatRows($quote),
            'currency' => $quote->currency ?: 'TL',
            'quoteDate' => optional($quote->quote_date)->format('d.m.Y'),
            'validUntil' => optional($quote->valid_until)->format('d.m.Y'),
            'notes' => $quote->notes,
        ];
    }

    public function renderHtml(Order $quote): string
    {
        return view('admin.promotion-quotes.pdf', $this->buildViewData($quote))->render();
    }

    public function downloadResponse(Order $quote): Response
    {
        $pdf = Pdf::loadHTML($this->renderHtml($quote))
            ->setPaper('a4');

        return $pdf->download($this->fileName($quote));
    }

    public function fileName(Order $quote): string
    {
        $quoteNumber = $this->sanitizeSegment($quote->document_number ?: 'teklif');
        $customerName = $this->sanitizeSegment($quote->customer?->legal_name ?: 'musteri');

        return sprintf('%s_%s.pdf', $quoteNumber, $customerName);
    }

    private function buildVatRows(Order $quote): array
    {
        if ($quote->invoice_status !== 'fatura' || (float) $quote->vat_total <= 0) {
            return [];
        }

        $rows = [];

        foreach ($quote->items as $item) {
            $priceSnapshot = is_array($item->price_snapshot) ? $item->price_snapshot : [];
            $breakdown = collect($priceSnapshot['vat_breakdown'] ?? [])
                ->filter(fn ($row) => is_array($row) && isset($row['rate'], $row['total']));

            foreach ($breakdown as $row) {
                $rateKey = (string) $row['rate'];
                $rows[$rateKey] = ($rows[$rateKey] ?? 0) + (float) $row['total'];
            }
        }

        return collect($rows)
            ->filter(fn ($total) => (float) $total > 0)
            ->sortKeysDesc(SORT_NUMERIC)
            ->map(fn ($total, $rate) => [
                'label' => 'KDV %' . Str::replace('.', ',', (string) $rate),
                'amount' => (float) $total,
            ])
            ->values()
            ->all();
    }

    private function resolveApprovalUrl(?QuoteApprovalRequest $approvalRequest): ?string
    {
        if (! $approvalRequest || $approvalRequest->isCancelled() || $approvalRequest->isExpired()) {
            return null;
        }

        return route('public.quotes.approval.show', ['token' => $approvalRequest->token]);
    }

    private function sanitizeSegment(string $value): string
    {
        $ascii = Str::ascii($value);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ascii ?? '') ?: 'dosya';

        return trim($ascii, '-');
    }
}
