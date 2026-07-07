<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TenantAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CustomerPortalQuoteDataBuilder
{
    public function __construct(
        private readonly TenantCompanyProfileService $tenantCompanyProfileService,
        private readonly CustomerFacingPriceDisplayService $customerFacingPriceDisplayService,
    ) {
    }

    public function buildListRow(Order $quote): array
    {
        return [
            'id' => $quote->id,
            'document_number' => $quote->document_number,
            'quote_date' => optional($quote->quote_date)->format('d.m.Y') ?: '-',
            'valid_until' => optional($quote->valid_until)->format('d.m.Y') ?: '-',
            'status_label' => $quote->quoteDisplayStatusLabel(),
            'approval_status_label' => $quote->safeCustomerApprovalStatusLabel(),
            'product_summary' => $this->productSummary($quote),
            'grand_total' => $this->formatMoney($quote->grand_total, $quote->currency),
            'currency' => $quote->currency ?: 'TL',
        ];
    }

    public function buildDetail(Order $quote, TenantAccount $tenant, bool $approvalHelperEnabled, ?string $approvalHelperUrl = null): array
    {
        $latestApprovalRequest = $quote->latestQuoteApprovalRequest;

        return [
            'header' => [
                'document_number' => $quote->document_number,
                'quote_date' => optional($quote->quote_date)->format('d.m.Y') ?: '-',
                'valid_until' => optional($quote->valid_until)->format('d.m.Y') ?: '-',
                'status_label' => $quote->quoteDisplayStatusLabel(),
                'company_name' => $quote->customer?->legal_name ?: '-',
                'currency' => $quote->currency ?: 'TL',
                'tenant_name' => $this->tenantCompanyProfileService->getProfile($tenant)['display_name'],
                'note' => $this->sanitizeVisibleNote($quote->notes),
            ],
            'items' => $quote->items->map(function ($item): array {
                $customerFacing = $this->customerFacingPriceDisplayService->buildItem(
                    $item,
                    $item->order?->currency ?: 'TL'
                );

                return [
                    'product_name' => $item->product_name ?: '-',
                    'product_code' => $item->product_code ?: null,
                    'quantity' => $this->formatQuantity($item->quantity, $item->unit),
                    'unit_price' => $customerFacing['customer_unit_price_label'],
                    'line_total' => $customerFacing['customer_line_total_label'],
                    'prints' => collect($customerFacing['prints'])->map(function (array $print): array {
                        return [
                            'label' => trim(collect([$print['print_type'] ?? null, $print['print_option'] ?? null])->filter()->implode(' ')),
                            'quantity' => $print['quantity_label'] ?? '-',
                            'unit_price' => $print['unit_price_label'] ?? null,
                            'line_total' => $print['total_label'] ?? null,
                            'show_price_details' => (bool) ($print['show_price_details'] ?? false),
                            'note' => $this->sanitizeVisibleNote($print['note'] ?? null),
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'totals' => [
                'subtotal' => $this->formatMoney($quote->subtotal, $quote->currency),
                'vat_total' => $this->formatMoney($quote->vat_total, $quote->currency),
                'grand_total' => $this->formatMoney($quote->grand_total, $quote->currency),
            ],
            'approval_helper' => $approvalHelperEnabled && $approvalHelperUrl && $latestApprovalRequest && ! $latestApprovalRequest->isCancelled()
                ? [
                    'label' => $latestApprovalRequest->isActive() ? 'Teklifi İncele' : 'Teklifi İncele',
                    'title' => 'Bu teklif için onay bağlantısı hazır.',
                    'description' => 'Teklifinizi inceleyip onaylayabilir veya revize isteyebilirsiniz.',
                    'status_label' => $latestApprovalRequest->safeStatusLabel(),
                    'url' => $approvalHelperUrl,
                ]
                : null,
        ];
    }

    private function productSummary(Order $quote): string
    {
        $firstProductName = trim((string) $quote->items->first()?->product_name);

        if ($firstProductName === '') {
            return 'Ürün özeti paylaşılacak.';
        }

        $remainingCount = max(0, $quote->items->count() - 1);

        if ($remainingCount === 0) {
            return $firstProductName;
        }

        return $firstProductName . ' +' . $remainingCount . ' kalem';
    }

    private function formatMoney(mixed $amount, ?string $currency): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2, ',', '.') . ' ' . ($currency ?: 'TL');
    }

    private function formatQuantity(mixed $quantity, ?string $unit): string
    {
        $formatted = number_format((float) $quantity, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }

    private function sanitizeVisibleNote(?string $note): ?string
    {
        $text = trim((string) $note);

        if ($text === '') {
            return null;
        }

        foreach ([
            'purchase_total',
            'purchase_unit_price',
            'supplier_cost',
            'subcontractor_cost',
            'setup_cost',
            'profit',
            'margin',
            'group_code',
            'file_path',
            'physical_path',
            'internal_note',
            'notification_logs',
            'current_account_transactions',
            'smtp_password',
            'api_key',
            'pdh_raw',
        ] as $forbidden) {
            if (Str::contains(Str::lower($text), Str::lower($forbidden))) {
                return null;
            }
        }

        return $text;
    }
}
