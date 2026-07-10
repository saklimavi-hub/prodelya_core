<?php

namespace App\Http\Controllers;

use App\Models\QuoteApprovalRequest;
use App\Services\QuoteApprovalService;
use App\Services\TenantAccessService;
use App\Services\TenantCompanyProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PublicQuoteApprovalController extends Controller
{
    private const FORBIDDEN_PATTERNS = [
        '/\bpurchase_total\b/i',
        '/\bpurchase_unit_price\b/i',
        '/\bsupplier_cost\b/i',
        '/\bsubcontractor_cost\b/i',
        '/\bsetup_cost\b/i',
        '/\bbalance_due\b/i',
        '/\bbalance\b/i',
        '/\bpaid_total\b/i',
        '/\bpayment_amount\b/i',
        '/\bcurrent_account_transactions\b/i',
        '/\bnotification_logs\b/i',
        '/\bgroup_code\b/i',
        '/\bpdh_raw\b/i',
        '/\braw xml\b/i',
        '/\braw json\b/i',
        '/\bfile_path\b/i',
        '/\bphysical_path\b/i',
        '/\binternal note\b/i',
        '/(^|[\s"\'])storage[\/\\\\]/i',
        '/(^|[\s"\'])work-forms[\/\\\\]/i',
        '/[A-Z]:\\\\/i',
    ];

    public function __construct(
        protected QuoteApprovalService $quoteApprovalService,
        protected TenantAccessService $tenantAccessService,
        protected TenantCompanyProfileService $tenantCompanyProfileService,
    ) {
    }

    public function show(string $token): View
    {
        $approvalRequest = $this->resolvePublicApprovalRequest($token);

        if ($this->shouldHideRequest($approvalRequest)) {
            abort(404);
        }

        $approvalRequest = $this->markExpiredIfNeeded($approvalRequest);

        if ($this->canRespond($approvalRequest)) {
            try {
                $approvalRequest = $this->quoteApprovalService->markViewed($approvalRequest);
            } catch (RuntimeException) {
                $approvalRequest = $approvalRequest->fresh(['tenant', 'quote.customer', 'sendSnapshot']);
            }
        }

        return view('public.quotes.approval.show', $this->buildViewPayload($approvalRequest));
    }

    public function approve(Request $request, string $token): RedirectResponse
    {
        $approvalRequest = $this->resolvePublicApprovalRequest($token);

        if ($this->shouldHideRequest($approvalRequest)) {
            abort(404);
        }

        $approvalRequest = $this->markExpiredIfNeeded($approvalRequest);

        if (! $this->canRespond($approvalRequest)) {
            return $this->redirectWithResolvedStatusMessage($approvalRequest, $token);
        }

        try {
            $this->quoteApprovalService->approve(
                $approvalRequest,
                $this->sanitizePublicText($request->input('customer_note'))
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('public.quotes.approval.show', ['token' => $token])
                ->with('error', $this->humanizePublicError($exception));
        }

        return redirect()
            ->route('public.quotes.approval.show', ['token' => $token])
            ->with('success', 'Teklif onayınız alınmıştır.');
    }

    public function requestRevision(Request $request, string $token): RedirectResponse
    {
        $approvalRequest = $this->resolvePublicApprovalRequest($token);

        if ($this->shouldHideRequest($approvalRequest)) {
            abort(404);
        }

        $approvalRequest = $this->markExpiredIfNeeded($approvalRequest);

        if (! $this->canRespond($approvalRequest)) {
            return $this->redirectWithResolvedStatusMessage($approvalRequest, $token);
        }

        $validated = $request->validate([
            'customer_note' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'customer_note.required' => 'Revize notu gerekli.',
            'customer_note.min' => 'Revize notu en az 3 karakter olmalı.',
        ]);

        try {
            $this->quoteApprovalService->requestRevision(
                $approvalRequest,
                trim((string) $validated['customer_note'])
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('public.quotes.approval.show', ['token' => $token])
                ->with('error', $this->humanizePublicError($exception))
                ->withInput();
        }

        return redirect()
            ->route('public.quotes.approval.show', ['token' => $token])
            ->with('success', 'Revize talebiniz iletilmiştir.');
    }

    public function reject(Request $request, string $token): RedirectResponse
    {
        $approvalRequest = $this->resolvePublicApprovalRequest($token);

        if ($this->shouldHideRequest($approvalRequest)) {
            abort(404);
        }

        $approvalRequest = $this->markExpiredIfNeeded($approvalRequest);

        if (! $this->canRespond($approvalRequest)) {
            return $this->redirectWithResolvedStatusMessage($approvalRequest, $token);
        }

        $validated = $request->validate([
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->quoteApprovalService->reject(
                $approvalRequest,
                $this->sanitizePublicText($validated['customer_note'] ?? null)
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('public.quotes.approval.show', ['token' => $token])
                ->with('error', $this->humanizePublicError($exception))
                ->withInput();
        }

        return redirect()
            ->route('public.quotes.approval.show', ['token' => $token])
            ->with('success', 'Teklif reddi kaydedilmiştir.');
    }

    private function resolvePublicApprovalRequest(string $token): QuoteApprovalRequest
    {
        $approvalRequest = QuoteApprovalRequest::query()
            ->with([
                'tenant',
                'quote.customer',
                'sendSnapshot',
            ])
            ->where('token', $token)
            ->firstOrFail();

        if (! $approvalRequest->tenant || ! $approvalRequest->sendSnapshot) {
            abort(404);
        }

        if (! $this->tenantAccessService->canAccessFeature(
            $approvalRequest->tenant,
            'public_quote_approval',
            'quote_customer_approval'
        )) {
            abort(404);
        }

        return $approvalRequest;
    }

    private function shouldHideRequest(QuoteApprovalRequest $approvalRequest): bool
    {
        return $approvalRequest->isCancelled();
    }

    private function canRespond(QuoteApprovalRequest $approvalRequest): bool
    {
        return in_array($approvalRequest->status, [
            QuoteApprovalRequest::STATUS_WAITING,
            QuoteApprovalRequest::STATUS_VIEWED,
        ], true) && ! $approvalRequest->isExpired();
    }

    private function markExpiredIfNeeded(QuoteApprovalRequest $approvalRequest): QuoteApprovalRequest
    {
        if ($approvalRequest->status !== QuoteApprovalRequest::STATUS_CANCELLED
            && $approvalRequest->status !== QuoteApprovalRequest::STATUS_EXPIRED
            && $approvalRequest->isExpired()) {
            $approvalRequest->forceFill([
                'status' => QuoteApprovalRequest::STATUS_EXPIRED,
            ])->save();
        }

        return $approvalRequest->fresh(['tenant', 'quote.customer', 'sendSnapshot']);
    }

    private function buildViewPayload(QuoteApprovalRequest $approvalRequest): array
    {
        $snapshot = (array) ($approvalRequest->sendSnapshot?->snapshot_json ?? []);
        $totals = (array) data_get($snapshot, 'totals', []);
        $snapshotCurrency = data_get($snapshot, 'currency', $approvalRequest->quote?->currency ?: 'TL');
        $items = collect(data_get($snapshot, 'items', []))
            ->map(function (array $item) use ($snapshotCurrency, $snapshot): array {
                $currency = data_get($item, 'currency', $snapshotCurrency);

                return [
                    'product_name' => $item['product_name'] ?? '-',
                    'product_code' => $item['product_code'] ?? null,
                    'quantity' => $this->formatQuantity($item['quantity'] ?? 0, $item['unit'] ?? null),
                    'unit_price' => $this->formatMoney($item['customer_unit_price'] ?? null, $currency),
                    'line_total' => $this->formatMoney($item['customer_line_total'] ?? ($item['line_total'] ?? null), $currency),
                    'print_lines' => collect($item['print_lines'] ?? [])
                        ->map(function (array $print) use ($item, $currency, $snapshot): array {
                            $showPriceDetails = array_key_exists('show_price_details', $print)
                                ? (bool) $print['show_price_details']
                                : (bool) ($item['show_print_price_details'] ?? data_get($snapshot, 'show_print_price_details_to_customer', true));

                            return [
                                'print_type' => $print['print_type'] ?? '-',
                                'print_option' => $print['print_option'] ?? null,
                                'print_quantity' => $this->formatQuantity($print['print_quantity'] ?? 0, $item['unit'] ?? null),
                                'print_note' => $this->sanitizePublicText($print['print_note'] ?? null),
                                'print_unit_price' => $showPriceDetails
                                    ? $this->formatMoney($print['print_unit_price'] ?? null, $currency)
                                    : null,
                                'print_total' => $showPriceDetails
                                    ? $this->formatMoney($print['print_total'] ?? null, $currency)
                                    : null,
                                'show_price_details' => $showPriceDetails,
                            ];
                        })
                        ->all(),
                ];
            })
            ->all();

        $status = $approvalRequest->isCancelled()
            ? 'cancelled'
            : ($approvalRequest->isExpired() ? QuoteApprovalRequest::STATUS_EXPIRED : $approvalRequest->status);

        return [
            'tenantName' => $approvalRequest->tenant
                ? $this->tenantCompanyProfileService->getProfile($approvalRequest->tenant)['display_name']
                : 'Prodelya',
            'request' => $approvalRequest,
            'pageStatus' => $status,
            'pageStatusLabel' => $this->publicStatusLabel($approvalRequest, $status),
            'pageMessage' => $this->publicStatusMessage($approvalRequest, $status),
            'canRespond' => $this->canRespond($approvalRequest),
            'quote' => [
                'number' => data_get($snapshot, 'quote_number', $approvalRequest->quote?->document_number ?: 'Teklif'),
                'customer_name' => data_get($snapshot, 'customer.name', $approvalRequest->contact_name ?: 'Müşteri'),
                'quote_date' => $this->formatDate(data_get($snapshot, 'quote_date')),
                'valid_until' => $this->formatDate(data_get($snapshot, 'valid_until')),
                'currency' => $snapshotCurrency,
                'invoice_status' => data_get($snapshot, 'invoice_status', $approvalRequest->quote?->invoice_status),
                'item_count' => count($items),
            ],
            'items' => $items,
            'totals' => [
                'product_total' => $this->formatMoney($totals['product_total'] ?? null, $snapshotCurrency),
                'print_total' => $this->formatMoney($totals['print_total'] ?? null, $snapshotCurrency),
                'subtotal' => $this->formatMoney(
                    $totals['subtotal'] ?? (($totals['product_total'] ?? 0) + ($totals['print_total'] ?? 0)),
                    $snapshotCurrency
                ),
                'vat_total' => $this->formatMoney($totals['vat_total'] ?? 0, $snapshotCurrency),
                'grand_total' => $this->formatMoney($totals['grand_total'] ?? null, $snapshotCurrency),
                'vat_breakdown' => $this->buildVatSummaryRows(
                    collect($totals['vat_breakdown'] ?? [])->all(),
                    (float) ($totals['vat_total'] ?? 0),
                    $snapshotCurrency
                ),
            ],
        ];
    }

    private function buildVatSummaryRows(array $rows, float $vatTotal, string $currency): array
    {
        $grouped = collect($rows)
            ->filter(fn ($row) => is_array($row) && isset($row['rate'], $row['total']))
            ->groupBy(fn (array $row) => number_format((float) $row['rate'], 2, '.', ''))
            ->map(function ($group, string $rate) use ($currency): array {
                $numericRate = (float) $rate;

                return [
                    'label' => 'KDV %' . rtrim(rtrim(number_format($numericRate, 2, ',', '.'), '0'), ','),
                    'total' => $this->formatMoney($group->sum(fn (array $row) => (float) ($row['total'] ?? 0)), $currency),
                ];
            })
            ->values()
            ->all();

        if ($grouped !== []) {
            return $grouped;
        }

        return [[
            'label' => 'KDV',
            'total' => $this->formatMoney($vatTotal, $currency),
        ]];
    }

    private function publicStatusLabel(QuoteApprovalRequest $approvalRequest, string $status): string
    {
        return match ($status) {
            QuoteApprovalRequest::STATUS_WAITING => 'Yanıt Bekleniyor',
            QuoteApprovalRequest::STATUS_VIEWED => 'İnceleniyor',
            QuoteApprovalRequest::STATUS_APPROVED => 'Onaylandı',
            QuoteApprovalRequest::STATUS_REVISION_REQUESTED => 'Revize İstendi',
            QuoteApprovalRequest::STATUS_REJECTED => 'Reddedildi',
            QuoteApprovalRequest::STATUS_EXPIRED => 'Süresi Doldu',
            default => $approvalRequest->safeStatusLabel(),
        };
    }

    private function publicStatusMessage(QuoteApprovalRequest $approvalRequest, string $status): string
    {
        return match ($status) {
            QuoteApprovalRequest::STATUS_WAITING,
            QuoteApprovalRequest::STATUS_VIEWED => 'Teklifinizi inceleyip aşağıdaki aksiyonlardan birini seçebilirsiniz.',
            QuoteApprovalRequest::STATUS_APPROVED => 'Bu teklif daha önce onaylanmış.',
            QuoteApprovalRequest::STATUS_REVISION_REQUESTED => 'Bu teklif için revize talebi iletilmiş.',
            QuoteApprovalRequest::STATUS_REJECTED => 'Bu teklif daha önce reddedilmiş.',
            QuoteApprovalRequest::STATUS_EXPIRED => 'Bu teklif bağlantısının süresi dolmuş.',
            default => 'Bu teklif bağlantısı artık geçerli değil.',
        };
    }

    private function humanizePublicError(RuntimeException $exception): string
    {
        return match ($exception->getMessage()) {
            'Süresi dolan istek görüntülenemez.',
            'Süresi dolan istek yanıtlanamaz.' => 'Bu teklif bağlantısının süresi dolmuş.',
            'İptal edilen istek görüntülenemez.',
            'İptal edilen istek yanıtlanamaz.' => 'Bu teklif bağlantısı artık geçerli değil.',
            'Bu istek artık yanıtlanamaz.' => 'Bu teklif için işlem daha önce tamamlanmış.',
            default => 'Bu teklif için işlem daha önce tamamlanmış.',
        };
    }

    private function redirectWithResolvedStatusMessage(QuoteApprovalRequest $approvalRequest, string $token): RedirectResponse
    {
        $status = $approvalRequest->isExpired()
            ? QuoteApprovalRequest::STATUS_EXPIRED
            : $approvalRequest->status;

        return redirect()
            ->route('public.quotes.approval.show', ['token' => $token])
            ->with('error', $this->publicStatusMessage($approvalRequest, $status));
    }

    private function sanitizePublicText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return null;
            }
        }

        return $text;
    }

    private function formatQuantity(mixed $quantity, ?string $unit): string
    {
        $formatted = number_format((float) $quantity, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }

    private function formatMoney(mixed $amount, ?string $currency): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2, ',', '.') . ' ' . ($currency ?: 'TL');
    }

    private function formatDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($date)->format('d.m.Y');
        } catch (\Throwable) {
            return null;
        }
    }
}
