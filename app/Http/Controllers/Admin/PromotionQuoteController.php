<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\Company;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Services\ModuleFeatureCatalogService;
use App\Services\PromotionQuotePdfService;
use App\Services\Notifications\TenantNotificationSettingsService;
use App\Services\Notifications\TenantWhatsappLinkService;
use App\Services\TenantAccessService;
use App\Services\TenantResolver;
use App\Services\NumberGenerationService;
use App\Services\QuoteApprovalService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\UsageLimitGuardService;
use Symfony\Component\HttpFoundation\Response;

class PromotionQuoteController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected NumberGenerationService $numberGenerationService,
        protected QuoteApprovalService $quoteApprovalService,
        protected ModuleFeatureCatalogService $moduleFeatureCatalog,
        protected UsageLimitGuardService $usageLimitGuardService,
        protected TenantWhatsappLinkService $tenantWhatsappLinkService,
        protected TenantNotificationSettingsService $tenantNotificationSettingsService,
        protected TenantAccessService $tenantAccessService,
    ) {}

    /**
     * Normalize Turkish decimal format
     */
    private function normalizeDecimal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $value = trim((string) $value);

        // Türkçe format: 1.234,56
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
            return (float) $value;
        }

        // Türkçe basit format: 9,20
        if (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
            return (float) $value;
        }

        // Standart decimal format: 9.20
        return (float) $value;
    }

    private function resolveVatMode(?string $mode): string
    {
        return match ($mode) {
            'none', 'vat_none', 'no_vat' => 'none',
            'exclusive', 'inclusive', 'taxable', 'vat' => 'taxable',
            default => 'none',
        };
    }

    private function resolveInvoiceStatus(?string $status): string
    {
        return match ($status) {
            'fatura', 'faturali' => 'fatura',
            default => 'fis',
        };
    }

    private function resolveQuoteVatMode(?string $invoiceStatus): string
    {
        return $this->resolveInvoiceStatus($invoiceStatus) === 'fatura'
            ? 'taxable'
            : 'none';
    }

    private function isConvertedQuote(Order $quote): bool
    {
        $hasLoadedConvertedOrder = $quote->relationLoaded('convertedOrders')
            ? (bool) $quote->convertedOrders->first()
            : false;

        return $quote->workflow_status === 'quote_converted'
            || $hasLoadedConvertedOrder;
    }

    private function quoteStatusLabel(Order $quote, bool $isConverted): string
    {
        return $quote->displayQuoteStatusLabel();
    }

    private function quoteStatusBadgeClass(Order $quote, bool $isConverted): string
    {
        if ($isConverted) {
            return 'badge-green';
        }

        return match ($quote->customer_approval_status ?: Order::CUSTOMER_APPROVAL_NOT_SENT) {
            Order::CUSTOMER_APPROVAL_WAITING => 'badge-blue',
            Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'badge-amber',
            Order::CUSTOMER_APPROVAL_APPROVED => 'badge-green',
            Order::CUSTOMER_APPROVAL_REJECTED => 'badge-red',
            default => 'badge-gray',
        };
    }

    private function processStatusLabel(Order $quote, bool $isConverted): string
    {
        if ($isConverted) {
            return 'Siparişi Aç';
        }

        return match ($quote->customer_approval_status ?: Order::CUSTOMER_APPROVAL_NOT_SENT) {
            Order::CUSTOMER_APPROVAL_WAITING => 'Yanıt Bekleniyor',
            Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'Düzenle ve Tekrar Gönder',
            Order::CUSTOMER_APPROVAL_APPROVED => 'Siparişe Çevir',
            Order::CUSTOMER_APPROVAL_REJECTED => 'Yeniden Değerlendir',
            default => 'Onayla veya Gönder',
        };
    }

    private function processStatusBadgeClass(Order $quote, bool $isConverted): string
    {
        if ($isConverted) {
            return 'badge-blue';
        }

        return match ($quote->customer_approval_status ?: Order::CUSTOMER_APPROVAL_NOT_SENT) {
            Order::CUSTOMER_APPROVAL_WAITING => 'badge-blue',
            Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'badge-amber',
            Order::CUSTOMER_APPROVAL_APPROVED => 'badge-green',
            Order::CUSTOMER_APPROVAL_REJECTED => 'badge-red',
            default => 'badge-gray',
        };
    }

    private function canConvertFromIndex(Order $quote, bool $isConverted): bool
    {
        if ($isConverted) {
            return false;
        }

        return ($quote->customer_approval_status === Order::CUSTOMER_APPROVAL_APPROVED)
            || $quote->status === 'approved';
    }

    private function convertEligibilityIssues(Order $quote, bool $isConverted): array
    {
        if ($isConverted) {
            return ['Bu teklif daha önce siparişe dönüştürüldü.'];
        }

        $issues = [];

        if ($quote->status === 'cancelled') {
            $issues[] = 'İptal edilen teklifler siparişe çevrilemez.';
        }

        if (!$quote->customer_company_id && !$quote->relationLoaded('customer')) {
            $issues[] = 'Siparişe çevirmek için müşteri seçilmelidir.';
        } elseif (!$quote->customer_company_id && !$quote->customer) {
            $issues[] = 'Siparişe çevirmek için müşteri seçilmelidir.';
        }

        if (blank($quote->document_number)) {
            $issues[] = 'Siparişe çevirmek için teklif numarası gerekli.';
        }

        $itemCount = $quote->relationLoaded('items')
            ? $quote->items->count()
            : $quote->items()->count();

        if ($itemCount < 1) {
            $issues[] = 'Siparişe çevirmek için en az bir ürün kalemi gerekli.';
        }

        if (
            $quote->customer_approval_status !== Order::CUSTOMER_APPROVAL_APPROVED
            && $quote->status !== 'approved'
        ) {
            $issues[] = 'Siparişe çevirmek için teklif önce onaylanmalıdır.';
        }

        return $issues;
    }

    private function canConvertFromShow(Order $quote, bool $isConverted): bool
    {
        return count($this->convertEligibilityIssues($quote, $isConverted)) === 0;
    }

    private function canEditFromIndex(Order $quote, bool $isConverted): bool
    {
        if ($isConverted) {
            return false;
        }

        return $quote->canBeEdited();
    }

    private function customerQuoteApprovalModuleEnabled(int $tenantId): bool
    {
        $tenant = $this->resolveTenant($tenantId);

        return $tenant
            ? $this->tenantAccessService->canAccessModule($tenant, 'quote_customer_approval')
            : false;
    }

    private function publicQuoteApprovalFeatureEnabled(int $tenantId): bool
    {
        $tenant = $this->resolveTenant($tenantId);

        return $tenant
            ? $this->tenantAccessService->canAccessFeature($tenant, 'public_quote_approval', 'quote_customer_approval')
            : false;
    }

    private function whatsappLinksFeatureEnabled(int $tenantId): bool
    {
        $tenant = $this->resolveTenant($tenantId);

        return $tenant
            ? $this->tenantAccessService->canAccessFeature($tenant, 'whatsapp_links', 'notification_center')
            : false;
    }

    private function resolveTenant(int $tenantId): ?TenantAccount
    {
        return TenantAccount::query()->find($tenantId);
    }

    private function canManageQuoteApprovals(int $tenantId): bool
    {
        return Auth::user()?->hasPermissionInTenant('approve_quotes', $tenantId) ?? false;
    }

    private function latestApprovalResponseSummary(Order $quote): string
    {
        $latestRequest = $quote->latestQuoteApprovalRequest;

        if (
            $quote->customer_approval_status === Order::CUSTOMER_APPROVAL_APPROVED
            && $quote->customer_approval_source === Order::CUSTOMER_APPROVAL_SOURCE_INTERNAL_MANUAL
        ) {
            return 'Manuel onay verildi';
        }

        if (!$quote->last_sent_at) {
            return 'Henüz gönderilmedi';
        }

        if (!$latestRequest) {
            return 'Müşteri yanıtı bekleniyor';
        }

        return match ($latestRequest->status) {
            'approved' => 'Müşteri onayladı',
            'revision_requested' => 'Müşteri revize istedi' . ($latestRequest->customer_note ? ': ' . Str::limit($latestRequest->customer_note, 60) : ''),
            'rejected' => 'Müşteri reddetti',
            'viewed', 'waiting' => 'Müşteri yanıtı bekleniyor',
            'expired' => 'Bağlantı süresi doldu',
            'cancelled' => 'Eski gönderim kapatıldı',
            default => 'Müşteri yanıtı bekleniyor',
        };
    }

    private function lastActionLabel(Order $quote): string
    {
        if ($quote->workflow_status === 'quote_converted') {
            return 'Siparişe dönüştürüldü';
        }

        if ($quote->customer_approval_status === Order::CUSTOMER_APPROVAL_APPROVED) {
            return $quote->customer_approval_source === Order::CUSTOMER_APPROVAL_SOURCE_INTERNAL_MANUAL
                ? 'Manuel onay verildi'
                : 'Müşteri onay verdi';
        }

        if ($quote->customer_approval_status === Order::CUSTOMER_APPROVAL_REVISION_REQUESTED) {
            return 'Müşteri revize istedi';
        }

        if ($quote->customer_approval_status === Order::CUSTOMER_APPROVAL_REJECTED) {
            return 'Müşteri reddetti';
        }

        if ($quote->last_sent_at) {
            return 'Müşteriye gönderildi';
        }

        return 'Teklif kaydedildi';
    }

    private function nextActionLabel(Order $quote, bool $isConverted, bool $moduleEnabled): string
    {
        if ($isConverted) {
            return 'Siparişi Aç';
        }

        return match ($quote->customer_approval_status ?: Order::CUSTOMER_APPROVAL_NOT_SENT) {
            Order::CUSTOMER_APPROVAL_WAITING => $moduleEnabled ? 'Tekrar Gönder veya Onayla' : 'Onaylandı İşaretle',
            Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => $moduleEnabled ? 'Düzenle ve Tekrar Gönder' : 'Düzenle ve Onayla',
            Order::CUSTOMER_APPROVAL_APPROVED => 'Siparişe Çevir',
            Order::CUSTOMER_APPROVAL_REJECTED => $moduleEnabled ? 'Gönder veya Onayla' : 'Onaylandı İşaretle',
            default => $moduleEnabled ? 'Onayla veya Müşteriye Gönder' : 'Onaylandı İşaretle',
        };
    }

    private function buildSendHistoryRows(Order $quote): array
    {
        return $quote->quoteApprovalRequests
            ->sortByDesc('created_at')
            ->values()
            ->map(function ($request): array {
                return [
                    'date' => optional($request->created_at)->format('d.m.Y H:i'),
                    'channel' => $request->sendSnapshot?->safeSendLabel() ?: '-',
                    'recipient' => $request->contact_name ?: $request->contact_email ?: $request->contact_phone ?: ($request->quote?->customer?->legal_name ?: '-'),
                    'status' => $request->safeStatusLabel(),
                ];
            })
            ->all();
    }

    private function buildQuoteVatSummaryRows(Order $quote): array
    {
        if ($quote->invoice_status !== 'fatura' || (float) $quote->vat_total <= 0) {
            return [];
        }

        $rows = [];

        foreach ($quote->items as $item) {
            $priceSnapshot = is_array($item->price_snapshot) ? $item->price_snapshot : [];
            $breakdown = collect($priceSnapshot['vat_breakdown'] ?? [])
                ->filter(fn ($row) => is_array($row) && isset($row['rate'], $row['total']));

            if ($breakdown->isNotEmpty()) {
                foreach ($breakdown as $row) {
                    $rateKey = (string) $row['rate'];
                    $rows[$rateKey] = ($rows[$rateKey] ?? 0) + (float) $row['total'];
                }

                continue;
            }

            $productVatRate = (float) ($priceSnapshot['vat_rate'] ?? 0);
            $productVatTotal = (float) ($priceSnapshot['product_vat_total'] ?? 0);
            if ($productVatRate > 0 && $productVatTotal > 0) {
                $rateKey = (string) $productVatRate;
                $rows[$rateKey] = ($rows[$rateKey] ?? 0) + $productVatTotal;
            }

            $printVatRate = (float) ($priceSnapshot['print_vat_rate'] ?? $this->defaultPrintVatRate());
            $printVatTotal = (float) ($priceSnapshot['print_vat_total'] ?? 0);
            if ($printVatRate > 0 && $printVatTotal > 0) {
                $rateKey = (string) $printVatRate;
                $rows[$rateKey] = ($rows[$rateKey] ?? 0) + $printVatTotal;
            }
        }

        return collect($rows)
            ->filter(fn ($total) => (float) $total > 0)
            ->sortKeysDesc(SORT_NUMERIC)
            ->map(fn ($total, $rate) => [
                'label' => 'KDV %'.Str::replace('.', ',', (string) $rate),
                'amount' => (float) $total,
            ])
            ->values()
            ->all();
    }

    private function normalizeSendRecipientData(Request $request, Order $quote): array
    {
        $validated = $request->validate([
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'expires_in_days' => 'nullable|integer|min:1|max:30',
            'sent_channel' => 'nullable|in:manual,email,whatsapp_link',
        ]);

        return [
            'contact_name' => $validated['contact_name'] ?? ($quote->customer?->legal_name ?: null),
            'contact_email' => $validated['contact_email'] ?? ($quote->customer?->email ?: null),
            'contact_phone' => $validated['contact_phone'] ?? ($quote->customer?->mobile ?: ($quote->customer?->phone ?: null)),
            'expires_in_days' => $validated['expires_in_days'] ?? 7,
            'sent_channel' => $validated['sent_channel'] ?? 'manual',
            'sent_to_name' => $validated['contact_name'] ?? ($quote->customer?->legal_name ?: null),
            'sent_to_email' => $validated['contact_email'] ?? ($quote->customer?->email ?: null),
            'sent_to_phone' => $validated['contact_phone'] ?? ($quote->customer?->mobile ?: ($quote->customer?->phone ?: null)),
        ];
    }

    private function orderStatusLabel(?Order $order): string
    {
        if (!$order) {
            return '-';
        }

        return match ($order->status) {
            'pending' => 'Hazırlanıyor',
            'approved' => 'Onaylandı',
            'completed' => 'Tamamlandı',
            'cancelled' => 'İptal',
            default => ucfirst((string) $order->status),
        };
    }

    private function calculateVatBreakdown(float $taxableTotal, float $vatRate, string $vatMode): array
    {
        $vatMode = $this->resolveVatMode($vatMode);
        $vatRate = max(0, $vatRate);

        if ($vatMode === 'none' || $vatRate <= 0) {
            return [
                'net_total' => $taxableTotal,
                'vat_total' => 0.0,
                'gross_total' => $taxableTotal,
            ];
        }

        $vatTotal = $taxableTotal * ($vatRate / 100);

        return [
            'net_total' => $taxableTotal,
            'vat_total' => $vatTotal,
            'gross_total' => $taxableTotal + $vatTotal,
        ];
    }

    private function defaultPrintVatRate(): float
    {
        return 20.0;
    }

    private function humanizeQuoteException(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'no column named quote_date')) {
            return 'Teklif kaydedilemedi. Veritabani semasi guncel degil; orders.quote_date alani eksik. Migration calistirilmali.';
        }

        if (str_contains($message, 'Undefined array key "has_print"')) {
            return 'Teklif kaydedilemedi. Baski secimi eksik veya tutarsiz gonderildi.';
        }

        if ($exception instanceof QueryException && str_contains($message, 'CHECK constraint failed: status')) {
            return 'Teklif kaydedilemedi. Siparis kalemi durumu veritabani kurallariyla uyusmuyor.';
        }

        return 'Teklif kaydedilirken beklenmeyen bir sistem hatasi olustu.';
    }

    private function resolveUnitPricePayload(array $itemData): array
    {
        $listPrice = $this->normalizeDecimal($itemData['list_price'] ?? 0);
        $discountRate = $this->normalizeDecimal($itemData['discount_rate'] ?? 0);
        $submittedUnitPrice = $this->normalizeDecimal($itemData['unit_price'] ?? 0);
        $calculatedUnitPrice = round($listPrice * (1 - ($discountRate / 100)), 4);
        $hasExplicitManualFlag = array_key_exists('manual_unit_price', $itemData);
        $manualUnitPrice = filter_var($itemData['manual_unit_price'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$hasExplicitManualFlag && abs($submittedUnitPrice - $calculatedUnitPrice) > 0.0001) {
            $manualUnitPrice = true;
        }

        return [
            'list_price' => $listPrice,
            'discount_rate' => $discountRate,
            'unit_price' => $manualUnitPrice ? $submittedUnitPrice : $calculatedUnitPrice,
            'calculated_unit_price' => $calculatedUnitPrice,
            'manual_unit_price' => $manualUnitPrice,
        ];
    }

    private function quotePrintTypeFallbackOptions(): array
    {
        return ['UV Baskı', 'Serigrafi', 'Tampon Baskı', 'Lazer', 'DTF', 'Sublimasyon', 'Dijital Baskı', 'Transfer Baskı', 'Nakış', 'Etiket / Sticker', 'Sıcak Baskı', 'Diğer'];
    }

    private function buildTenantPrintSettingsPayload(int $tenantId, bool $canViewFinancialData, array $includeSettingIds = []): array
    {
        $includeSettingIds = collect($includeSettingIds)
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $settings = TenantPrintSetting::query()
            ->with(['standardPrintType:id,name,status,sort_order', 'defaultSubcontractorCompany:id,legal_name'])
            ->where('tenant_account_id', $tenantId)
            ->where(function ($query) use ($includeSettingIds): void {
                $query->where(function ($activeQuery): void {
                    $activeQuery
                        ->where('is_active', true)
                        ->whereHas('standardPrintType', fn ($standardQuery) => $standardQuery->where('status', 'active'));
                });

                if (!empty($includeSettingIds)) {
                    $query->orWhereIn('id', $includeSettingIds);
                }
            })
            ->get()
            ->sortBy([
                fn (TenantPrintSetting $setting) => (int) ($setting->standardPrintType?->sort_order ?? 9999),
                fn (TenantPrintSetting $setting) => mb_strtolower($setting->displayName()),
            ])
            ->values();

        return $settings->map(function (TenantPrintSetting $setting) use ($canViewFinancialData): array {
            $payload = [
                'id' => $setting->id,
                'display_name' => $setting->displayName(),
                'standard_print_type_id' => $setting->standard_print_type_id,
                'standard_name' => $setting->standardPrintType?->name,
                'production_mode' => $setting->production_mode,
                'requires_graphic' => (bool) $setting->requires_graphic,
                'requires_production' => (bool) $setting->requires_production,
                'requires_setup' => (bool) $setting->requires_setup,
                'setup_types' => $setting->effectiveSetupTypes(),
                'default_subcontractor_company_id' => $setting->default_subcontractor_company_id,
                'default_subcontractor_company_name' => $setting->defaultSubcontractorCompany?->legal_name,
                'is_active' => (bool) $setting->is_active,
                'standard_status' => $setting->standardPrintType?->status,
            ];

            if ($canViewFinancialData) {
                $payload['default_currency'] = $setting->default_currency ?: 'TRY';
                $payload['default_unit_price'] = $setting->default_unit_price;
                $payload['default_setup_cost'] = $setting->default_setup_cost;
            }

            return $payload;
        })->all();
    }

    private function findTenantPrintSettingForSave(
        int $tenantId,
        ?int $settingId,
        array $allowedInactiveIds = []
    ): ?TenantPrintSetting {
        if (!$settingId) {
            return null;
        }

        $setting = TenantPrintSetting::query()
            ->with('standardPrintType:id,name,status')
            ->where('tenant_account_id', $tenantId)
            ->find($settingId);

        if (!$setting) {
            throw ValidationException::withMessages([
                'items' => 'Seçilen baskı ayarı bu tenant için geçerli değil.',
            ]);
        }

        $inactiveAllowed = in_array($setting->id, array_map('intval', $allowedInactiveIds), true);
        $isSelectable = $setting->is_active && ($setting->standardPrintType?->status === 'active');

        if (!$isSelectable && !$inactiveAllowed) {
            throw ValidationException::withMessages([
                'items' => 'Pasif baskı ayarı yeni baskı satırında kullanılamaz.',
            ]);
        }

        return $setting;
    }

    private function normalizePrintTypeForSetting(TenantPrintSetting $setting): string
    {
        return trim((string) $setting->displayName());
    }

    private function normalizeLegacyProductionType(?string $value, ?string $settingMode): ?string
    {
        if (filled($value)) {
            return trim((string) $value);
        }

        return match ($settingMode) {
            'internal' => 'İç üretim',
            'outsourced' => 'Dış üretim / Fason',
            default => null,
        };
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $moduleEnabled = $this->customerQuoteApprovalModuleEnabled($tenant->id);
        $canApproveQuotes = $this->canManageQuoteApprovals($tenant->id);

        $baseQuery = Order::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('order_family', 'promotion')
            ->where('document_type', 'quote');

        $query = (clone $baseQuery)->with([
            'customer:id,legal_name',
            'items:id,order_id,has_print',
            'latestQuoteApprovalRequest.sendSnapshot',
            'convertedOrders' => function ($relationQuery) {
                $relationQuery
                    ->select('id', 'source_quote_id', 'document_number', 'document_type', 'created_at')
                    ->where('document_type', 'order')
                    ->latest('id');
            },
        ]);

        // Apply filters
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('document_number', 'like', "%{$request->search}%")
                  ->orWhereHas('customer', function ($cq) use ($request) {
                      $cq->where('legal_name', 'like', "%{$request->search}%");
                  });
            });
        }

        if ($request->filled('status')) {
            $status = $request->status;

            $query->where(function ($innerQuery) use ($status) {
                if ($status === 'quote_converted') {
                    $innerQuery->where('workflow_status', 'quote_converted');

                    return;
                }

                if ($status === 'not_sent') {
                    $innerQuery->where(function ($pendingQuery) {
                        $pendingQuery->whereNull('customer_approval_status')
                            ->orWhere('customer_approval_status', Order::CUSTOMER_APPROVAL_NOT_SENT);
                    });

                    return;
                }

                $innerQuery->where('customer_approval_status', $status);
            });
        }

        if ($request->filled('customer')) {
            $query->where('customer_company_id', $request->customer);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $quotes = $query
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $quotes->getCollection()->transform(function (Order $quote) use ($moduleEnabled) {
            $connectedOrder = $quote->convertedOrders->first();
            $isConverted = $this->isConvertedQuote($quote);

            $quote->setRelation('connectedOrder', $connectedOrder);
            $quote->setAttribute('is_converted', $isConverted);
            $quote->setAttribute('is_editable_for_index', $this->canEditFromIndex($quote, $isConverted));
            $quote->setAttribute('can_convert_from_index', $this->canConvertFromIndex($quote, $isConverted));
            $quote->setAttribute('display_status_label', $this->quoteStatusLabel($quote, $isConverted));
            $quote->setAttribute('display_status_badge_class', $this->quoteStatusBadgeClass($quote, $isConverted));
            $quote->setAttribute('process_status_label', $this->processStatusLabel($quote, $isConverted));
            $quote->setAttribute('process_status_badge_class', $this->processStatusBadgeClass($quote, $isConverted));
            $quote->setAttribute('last_action_label', $this->lastActionLabel($quote));
            $quote->setAttribute('next_action_label', $this->nextActionLabel($quote, $isConverted, $moduleEnabled));
            $quote->setAttribute('customer_response_summary', $this->latestApprovalResponseSummary($quote));

            return $quote;
        });

        // Statistics
        $stats = [
            'total' => (clone $baseQuery)->count(),
            'prepared' => (clone $baseQuery)
                ->where('workflow_status', '!=', 'quote_converted')
                ->where(function ($query) {
                    $query->whereNull('customer_approval_status')
                        ->orWhere('customer_approval_status', Order::CUSTOMER_APPROVAL_NOT_SENT);
                })
                ->count(),
            'waiting' => (clone $baseQuery)
                ->where('customer_approval_status', Order::CUSTOMER_APPROVAL_WAITING)
                ->count(),
            'revision_requested' => (clone $baseQuery)
                ->where('customer_approval_status', Order::CUSTOMER_APPROVAL_REVISION_REQUESTED)
                ->count(),
            'approved' => (clone $baseQuery)
                ->where('workflow_status', '!=', 'quote_converted')
                ->where(function ($query) {
                    $query->where('customer_approval_status', Order::CUSTOMER_APPROVAL_APPROVED)
                        ->orWhere('status', 'approved');
                })
                ->count(),
            'converted' => (clone $baseQuery)
                ->where('workflow_status', 'quote_converted')
                ->count(),
        ];

        // Get customers for filter dropdown
        $customers = Company::where('tenant_account_id', $tenant->id)
            ->where('status', 'active')
            ->whereHas('companyRoles', function ($q) {
                $q->where('role_key', 'customer');
            })
            ->orderBy('legal_name')
            ->get();

        return view('admin.promotion-quotes.index', [
            'quotes' => $quotes,
            'stats' => $stats,
            'customers' => $customers,
            'canViewFinancialData' => Auth::user()?->canViewFinancialData($tenant->id) ?? false,
            'customerQuoteApprovalEnabled' => $moduleEnabled,
            'canApproveQuotes' => $canApproveQuotes,
            'filters' => [
                'search' => $request->get('search', ''),
                'status' => $request->get('status', ''),
                'customer' => $request->get('customer', ''),
                'date_from' => $request->get('date_from', ''),
                'date_to' => $request->get('date_to', ''),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $tenant = $this->tenantResolver->getCurrentTenant(request());
        $canViewFinancialData = Auth::user()?->canViewFinancialData($tenant->id) ?? false;
        
        // Get active customers
        $customers = Company::where('tenant_account_id', $tenant->id)
            ->where('status', 'active')
            ->whereHas('companyRoles', function ($q) {
                $q->where('role_key', 'customer');
            })
            ->orderBy('legal_name')
            ->get();

        $partnerCompanies = Company::where('tenant_account_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('legal_name')
            ->get(['id', 'legal_name']);

        return view('admin.promotion-quotes.create', [
            'customers' => $customers,
            'partnerCompanies' => $partnerCompanies,
            'nextQuoteNumber' => $this->numberGenerationService->getNextNumber($tenant->id, 'quote'),
            'catalogSearchUrl' => route('admin.catalog.search'),
            'canViewFinancialData' => $canViewFinancialData,
            'tenantPrintSettings' => $this->buildTenantPrintSettingsPayload($tenant->id, $canViewFinancialData),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->usageLimitGuardService->assertCanCreate($tenant, 'orders');
        $canViewFinancialData = $request->user()?->canViewFinancialData($tenant->id) ?? false;

        $validated = $request->validate([
            'customer_company_id' => 'required|exists:companies,id',
            'quote_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:quote_date',
            'invoice_status' => 'required|in:fis,fatura',
            'currency' => 'required|in:TL,USD,EUR',
            'items' => 'required|array|min:1',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.product_code' => 'nullable|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string|max:50',
            'items.*.list_price' => 'nullable|numeric|min:0',
            'items.*.discount_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.manual_unit_price' => 'nullable|boolean',
            'items.*.calculated_unit_price' => 'nullable|numeric|min:0',
            'items.*.vat_mode' => 'nullable|in:taxable,none,exclusive,inclusive,vat,vat_none,no_vat',
            'items.*.vat_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.has_print' => 'nullable|boolean',
            'items.*.tenant_catalog_product_id' => 'nullable|exists:tenant_catalog_products,id',
            'items.*.tenant_catalog_product_variant_id' => 'nullable|exists:tenant_catalog_product_variants,id',
            'items.*.standard_product_id' => 'nullable|exists:standard_products,id',
            'items.*.standard_product_variant_id' => 'nullable|exists:standard_product_variants,id',
            'items.*.supplier_id' => 'nullable|integer',
            'items.*.supplier_source_id' => 'nullable|exists:supplier_sources,id',
            'items.*.product_snapshot' => 'nullable',
            'items.*.price_snapshot' => 'nullable',
            'items.*.stock_snapshot' => 'nullable',
            'items.*.catalog_source' => 'nullable|string|max:100',
            'items.*.prints' => 'nullable|array',
            'items.*.prints.*.tenant_print_setting_id' => 'nullable|integer',
            'items.*.prints.*.standard_print_type_id' => 'nullable|integer|exists:standard_print_types,id',
            'items.*.prints.*.print_type' => 'nullable|string|max:255',
            'items.*.prints.*.print_option' => 'nullable|string|max:255',
            'items.*.prints.*.print_location' => 'nullable|string|max:255',
            'items.*.prints.*.production_type' => 'nullable|string|max:100',
            'items.*.prints.*.subcontractor_company_id' => 'nullable|exists:companies,id',
            'items.*.prints.*.print_color' => 'nullable|string|max:255',
            'items.*.prints.*.print_size' => 'nullable|string|max:255',
            'items.*.prints.*.cliche_status' => 'nullable|string|max:255',
            'items.*.prints.*.print_quantity' => 'nullable|numeric|min:0',
            'items.*.prints.*.print_unit_price' => 'nullable|numeric|min:0',
            'items.*.prints.*.note' => 'nullable|string',
            'items.*.prints.*.production_note' => 'nullable|string',
            'delivery_type' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Generate document number
            $documentNumber = $this->numberGenerationService->generateNumber($tenant->id, 'quote');
            $invoiceStatus = $this->resolveInvoiceStatus($validated['invoice_status'] ?? null);

            // Create quote
            $quote = Order::create([
                'tenant_account_id' => $tenant->id,
                'order_family' => 'promotion',
                'order_mode' => 'product_sale_print',
                'document_type' => 'quote',
                'document_number' => $documentNumber,
                'customer_company_id' => $validated['customer_company_id'],
                'status' => 'draft',
                'workflow_status' => 'quote',
                'quote_date' => $validated['quote_date'],
                'valid_until' => $validated['valid_until'] ?? null,
                'invoice_status' => $invoiceStatus,
                'delivery_type' => $validated['delivery_type'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'currency' => $validated['currency'],
                'created_by' => Auth::id(),
            ]);

            // Create order items
            $netSubtotal = 0;
            $vatTotal = 0;
            $grossTotal = 0;

            foreach ($validated['items'] as $itemData) {
                $itemData['quantity'] = $this->normalizeDecimal($itemData['quantity']);
                $unitPricePayload = $this->resolveUnitPricePayload($itemData);
                $itemData['list_price'] = $unitPricePayload['list_price'];
                $itemData['discount_rate'] = $unitPricePayload['discount_rate'];
                $itemData['unit_price'] = $unitPricePayload['unit_price'];
                $unitPrice = $unitPricePayload['unit_price'];

                $catalogPayload = $this->resolveCatalogItemPayload($tenant->id, $itemData);
                $vatMode = $this->resolveQuoteVatMode($invoiceStatus);
                $vatRate = $vatMode === 'taxable'
                    ? (float) ($itemData['vat_rate'] ?? data_get($catalogPayload['price_snapshot'], 'vat_rate', 20) ?: 20)
                    : 0.0;
                $printVatRate = $vatMode === 'taxable' ? $this->defaultPrintVatRate() : 0.0;
                $lineBaseTotal = $unitPrice * $itemData['quantity'];
                $printLineTotal = 0;

                $orderItem = OrderItem::create([
                    'tenant_account_id' => $tenant->id,
                    'order_id' => $quote->id,
                    'item_type' => 'product',
                    'product_source' => $catalogPayload['product_source'],
                    'catalog_source' => $catalogPayload['catalog_source'],
                    'tenant_catalog_product_id' => $catalogPayload['tenant_catalog_product_id'],
                    'tenant_catalog_product_variant_id' => $catalogPayload['tenant_catalog_product_variant_id'],
                    'standard_product_id' => $catalogPayload['standard_product_id'],
                    'standard_product_variant_id' => $catalogPayload['standard_product_variant_id'],
                    'supplier_id' => $catalogPayload['supplier_id'],
                    'supplier_source_id' => $catalogPayload['supplier_source_id'],
                    'product_name' => $catalogPayload['product_name'] ?? $itemData['product_name'],
                    'product_code' => $catalogPayload['product_code'] ?? ($itemData['product_code'] ?? null),
                    'quantity' => $itemData['quantity'],
                    'unit' => $itemData['unit'],
                    'description' => $itemData['description'] ?? null,
                    'product_snapshot' => $catalogPayload['product_snapshot'],
                    'price_snapshot' => $catalogPayload['price_snapshot'],
                    'stock_snapshot' => $catalogPayload['stock_snapshot'],
                    'list_price' => $itemData['list_price'] ?? null,
                    'discount_rate' => $itemData['discount_rate'] ?? 0,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineBaseTotal,
                    'has_print' => $itemData['has_print'] ?? false,
                    'status' => 'pending',
                ]);

                // Create print details if provided
                if (($itemData['has_print'] ?? false) && isset($itemData['prints'])) {
                    foreach ($itemData['prints'] as $printData) {
                        // Normalize print decimal values
                        $printData['print_quantity'] = $this->normalizeDecimal($printData['print_quantity'] ?? 0);
                        $printData['print_unit_price'] = $this->normalizeDecimal($printData['print_unit_price'] ?? 0);
                        $selectedSetting = $this->findTenantPrintSettingForSave(
                            $tenant->id,
                            !empty($printData['tenant_print_setting_id']) ? (int) $printData['tenant_print_setting_id'] : null
                        );
                        $resolvedSubcontractorId = $printData['subcontractor_company_id'] ?? null;

                        if ($selectedSetting && blank($resolvedSubcontractorId) && filled($selectedSetting->default_subcontractor_company_id)) {
                            $resolvedSubcontractorId = $selectedSetting->default_subcontractor_company_id;
                        }

                        if ($resolvedSubcontractorId) {
                            $resolvedSubcontractorId = Company::query()
                                ->where('tenant_account_id', $tenant->id)
                                ->whereKey($resolvedSubcontractorId)
                                ->value('id');
                        }

                        if (!$canViewFinancialData) {
                            $printData['print_unit_price'] = $this->normalizeDecimal($printData['print_unit_price'] ?? 0);
                        }

                        $currentPrintLineTotal = ($printData['print_unit_price'] ?? 0) * ($printData['print_quantity'] ?? 0);

                        OrderItemPrint::create([
                            'tenant_account_id' => $tenant->id,
                            'order_id' => $quote->id,
                            'order_item_id' => $orderItem->id,
                            'tenant_print_setting_id' => $selectedSetting?->id,
                            'standard_print_type_id' => $selectedSetting?->standard_print_type_id ?? ($printData['standard_print_type_id'] ?? null),
                            'print_type' => $selectedSetting ? $this->normalizePrintTypeForSetting($selectedSetting) : ($printData['print_type'] ?? null),
                            'print_option' => $printData['print_option'] ?? null,
                            'print_location' => $printData['print_location'] ?? null,
                            'production_type' => $this->normalizeLegacyProductionType($printData['production_type'] ?? null, $selectedSetting?->production_mode),
                            'subcontractor_company_id' => $resolvedSubcontractorId,
                            'print_color' => $printData['print_color'] ?? null,
                            'print_size' => $printData['print_size'] ?? null,
                            'cliche_status' => $printData['cliche_status'] ?? null,
                            'print_quantity' => $printData['print_quantity'] ?? null,
                            'print_unit_price' => $printData['print_unit_price'] ?? null,
                            'print_total' => $currentPrintLineTotal,
                            'note' => $printData['note'] ?? null,
                            'production_note' => $printData['production_note'] ?? null,
                            'status' => 'draft',
                        ]);

                        $printLineTotal += $currentPrintLineTotal;
                    }

                    // Update order item print total
                    $orderItem->print_total = $orderItem->prints()->sum('print_total');
                    $orderItem->save();
                }

                $productTaxBreakdown = $this->calculateVatBreakdown($lineBaseTotal, $vatRate, $vatMode);
                $printTaxBreakdown = $this->calculateVatBreakdown($printLineTotal, $printVatRate, $vatMode);
                $taxBreakdown = [
                    'net_total' => $productTaxBreakdown['net_total'] + $printTaxBreakdown['net_total'],
                    'vat_total' => $productTaxBreakdown['vat_total'] + $printTaxBreakdown['vat_total'],
                    'gross_total' => $productTaxBreakdown['gross_total'] + $printTaxBreakdown['gross_total'],
                ];
                $priceSnapshot = $orderItem->price_snapshot ?? [];
                $priceSnapshot['invoice_status'] = $invoiceStatus;
                $priceSnapshot['vat_mode'] = $vatMode;
                $priceSnapshot['vat_rate'] = $vatRate;
                $priceSnapshot['print_vat_rate'] = $printVatRate;
                $priceSnapshot['calculated_unit_price'] = $unitPricePayload['calculated_unit_price'];
                $priceSnapshot['manual_unit_price'] = $unitPricePayload['manual_unit_price'];
                $priceSnapshot['product_line_total'] = round($lineBaseTotal, 2);
                $priceSnapshot['print_line_total'] = round($printLineTotal, 2);
                $priceSnapshot['product_total'] = round($lineBaseTotal, 2);
                $priceSnapshot['print_total'] = round($printLineTotal, 2);
                $priceSnapshot['subtotal'] = round($taxBreakdown['net_total'], 2);
                $priceSnapshot['product_vat_total'] = round($productTaxBreakdown['vat_total'], 2);
                $priceSnapshot['print_vat_total'] = round($printTaxBreakdown['vat_total'], 2);
                $priceSnapshot['vat_breakdown'] = array_values(array_filter([
                    $vatMode === 'taxable' && $vatRate > 0 ? ['rate' => $vatRate, 'total' => round($productTaxBreakdown['vat_total'], 2), 'scope' => 'product'] : null,
                    $vatMode === 'taxable' && $printVatRate > 0 && $printLineTotal > 0 ? ['rate' => $printVatRate, 'total' => round($printTaxBreakdown['vat_total'], 2), 'scope' => 'print'] : null,
                ]));
                $priceSnapshot['vat_total'] = round($taxBreakdown['vat_total'], 2);
                $priceSnapshot['line_vat_total'] = round($taxBreakdown['vat_total'], 2);
                $priceSnapshot['line_net_total'] = round($taxBreakdown['net_total'], 2);
                $priceSnapshot['grand_total'] = round($taxBreakdown['gross_total'], 2);
                $priceSnapshot['line_gross_total'] = round($taxBreakdown['gross_total'], 2);
                $orderItem->price_snapshot = $priceSnapshot;
                $orderItem->save();

                $netSubtotal += $taxBreakdown['net_total'];
                $vatTotal += $taxBreakdown['vat_total'];
                $grossTotal += $taxBreakdown['gross_total'];
            }

            $quote->update([
                'subtotal' => $netSubtotal,
                'vat_total' => $vatTotal,
                'grand_total' => $grossTotal,
            ]);

            // TODO: Log audit trail
            // AuditLog::logQuoteCreated($tenant->id, $quote->id, Auth::id());

            DB::commit();

            return redirect()
                ->route('admin.promotion-quotes.show', $quote)
                ->with('success', 'Promosyon teklifi başarıyla oluşturuldu.');

        } catch (\Exception $e) {
            DB::rollback();
            
            // Log the error for debugging
            \Log::error('Quote creation failed: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'validated_data' => $validated ?? null,
                'exception' => $e
            ]);
            
            return back()
                ->withInput()
                ->withErrors(['error' => $this->humanizeQuoteException($e)]);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Order $quote)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $moduleEnabled = $this->customerQuoteApprovalModuleEnabled($tenant->id);
        $canApproveQuotes = $this->canManageQuoteApprovals($tenant->id);
        
        // Tenant isolation check
        if ($quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        // Verify this is a promotion quote
        if (!$quote->isPromotion() || !$quote->isQuote()) {
            abort(404);
        }

        $quote->load([
            'customer',
            'items.prints.subcontractorCompany',
            'creator',
            'quoteApprovalRequests.sendSnapshot',
            'latestQuoteApprovalRequest.sendSnapshot',
            'convertedOrders' => function ($query) {
                $query
                    ->select('id', 'source_quote_id', 'document_number', 'status', 'workflow_status', 'created_at')
                    ->where('document_type', 'order')
                    ->latest('id');
            },
        ]);

        $linkedOrder = $quote->convertedOrders->first();
        $isConverted = $this->isConvertedQuote($quote);
        $quote->setAttribute('display_status_badge_class', $this->quoteStatusBadgeClass($quote, $isConverted));
        $itemCount = $quote->items->count();
        $printCount = $quote->items->sum(fn ($item) => $item->prints->count());
        $canConvert = $this->canConvertFromShow($quote, $isConverted);
        $convertIssues = $this->convertEligibilityIssues($quote, $isConverted);
        $latestApprovalRequest = $quote->latestQuoteApprovalRequest;
        $sendHistoryRows = $this->buildSendHistoryRows($quote);
        $customerResponseSummary = $this->latestApprovalResponseSummary($quote);
        $summaryVatRows = $this->buildQuoteVatSummaryRows($quote);
        $hasVatSummary = ! empty($summaryVatRows) && (float) $quote->vat_total > 0;
        $publicQuoteApprovalEnabled = $moduleEnabled && $this->publicQuoteApprovalFeatureEnabled($tenant->id);
        $showSendAction = $publicQuoteApprovalEnabled
            && $canApproveQuotes
            && ! $isConverted
            && in_array($quote->customer_approval_status ?: 'not_sent', ['not_sent', 'waiting', 'revision_requested', 'rejected'], true);
        $sendActionLabel = in_array($quote->customer_approval_status, ['waiting', 'revision_requested'], true) ? 'Tekrar Gönder' : 'Müşteriye Gönder';
        $decisionNote = $isConverted
            ? 'Bu teklif siparişe dönüştü. İşlemler sipariş ekranı ve operasyon modüllerinden takip edilir.'
            : 'Bu kayıt teklif aşamasındadır. Onaylandıktan sonra siparişe çevrilir.';
        $approvalHelperUrl = $publicQuoteApprovalEnabled && $latestApprovalRequest && ! $latestApprovalRequest->isCancelled()
            ? route('admin.promotion-quotes.customer-approval.open', $quote)
            : null;
        $recipientPhone = $latestApprovalRequest?->contact_phone ?: ($quote->customer?->mobile ?: $quote->customer?->phone);
        $whatsappFeatureEnabled = $this->whatsappLinksFeatureEnabled($tenant->id)
            && $this->tenantNotificationSettingsService->isWhatsappEnabled($tenant);
        $whatsappAvailable = $whatsappFeatureEnabled && $publicQuoteApprovalEnabled;
        $whatsappReady = $whatsappAvailable && filled($recipientPhone) && filled($approvalHelperUrl);
        $quotePdfAvailable = true;

        return view('admin.promotion-quotes.show', [
            'quote' => $quote,
            'canViewFinancialData' => Auth::user()->canViewFinancialData($tenant->id),
            'itemCount' => $itemCount,
            'printCount' => $printCount,
            'isConverted' => $isConverted,
            'linkedOrder' => $linkedOrder,
            'linkedOrderStatusLabel' => $this->orderStatusLabel($linkedOrder),
            'canConvert' => $canConvert,
            'convertIssues' => $convertIssues,
            'displayStatusLabel' => $this->quoteStatusLabel($quote, $isConverted),
            'processStatusLabel' => $this->processStatusLabel($quote, $isConverted),
            'customerQuoteApprovalEnabled' => $moduleEnabled,
            'canApproveQuotes' => $canApproveQuotes,
            'latestApprovalRequest' => $latestApprovalRequest,
            'customerResponseSummary' => $customerResponseSummary,
            'sendHistoryRows' => $sendHistoryRows,
            'summaryVatRows' => $summaryVatRows,
            'hasVatSummary' => $hasVatSummary,
            'publicQuoteApprovalEnabled' => $publicQuoteApprovalEnabled,
            'showSendAction' => $showSendAction,
            'sendActionLabel' => $sendActionLabel,
            'decisionNote' => $decisionNote,
            'approvalHelperUrl' => $approvalHelperUrl,
            'recipientPhone' => $recipientPhone,
            'whatsappAvailable' => $whatsappAvailable,
            'whatsappReady' => $whatsappReady,
            'quotePdfAvailable' => $quotePdfAvailable,
        ]);
    }

    public function openCustomerApproval(Request $request, Order $quote): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || $quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        if (! $quote->isPromotion() || ! $quote->isQuote()) {
            abort(404);
        }

        if (! $this->publicQuoteApprovalFeatureEnabled($tenant->id)) {
            abort(404);
        }

        $quote->loadMissing('latestQuoteApprovalRequest');

        $latestApprovalRequest = $quote->latestQuoteApprovalRequest;

        if (! $latestApprovalRequest || $latestApprovalRequest->isCancelled()) {
            abort(404);
        }

        return redirect()->route('public.quotes.approval.show', ['token' => $latestApprovalRequest->token]);
    }

    public function openWhatsappLink(Request $request, Order $quote): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || $quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        if (! $this->canManageQuoteApprovals($tenant->id)) {
            abort(403);
        }

        if (! $quote->isPromotion() || ! $quote->isQuote()) {
            abort(404);
        }

        if (! $this->publicQuoteApprovalFeatureEnabled($tenant->id) || ! $this->whatsappLinksFeatureEnabled($tenant->id)) {
            abort(404);
        }

        if (! $this->tenantNotificationSettingsService->isWhatsappEnabled($tenant)) {
            return back()->withErrors(['error' => 'WhatsApp hazır mesaj ayarı aktif değil.']);
        }

        $quote->loadMissing('customer', 'latestQuoteApprovalRequest');
        $latestApprovalRequest = $quote->latestQuoteApprovalRequest;

        if (! $latestApprovalRequest || $latestApprovalRequest->isCancelled()) {
            return back()->withErrors(['error' => 'Önce müşteriye gönderim oluşturun.']);
        }

        $recipientPhone = $latestApprovalRequest->contact_phone ?: ($quote->customer?->mobile ?: $quote->customer?->phone);

        if (! filled($recipientPhone)) {
            return back()->withErrors(['error' => 'Müşteri telefon bilgisi bulunmuyor.']);
        }

        $publicUrl = route('public.quotes.approval.show', ['token' => $latestApprovalRequest->token]);
        $quoteNumber = $quote->document_number ?: 'teklifiniz';
        $customerName = $latestApprovalRequest->contact_name ?: ($quote->customer?->legal_name ?: 'Müşterimiz');

        $result = $this->tenantWhatsappLinkService->createManualLink($tenant, [
            'customer_name' => $customerName,
            'recipient_phone' => $recipientPhone,
            'message_type' => TenantWhatsappLinkService::TYPE_GENERAL,
            'message' => "{$quoteNumber} numaralı teklifinizi inceleyip onaylayabilirsiniz: {$publicUrl}",
            'public_link' => $publicUrl,
        ], $request->user());

        return redirect()->away($result['url']);
    }

    public function pdf(Request $request, Order $quote): Response
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || $quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        if (! $quote->isPromotion() || ! $quote->isQuote()) {
            abort(404);
        }

        return app(PromotionQuotePdfService::class)->downloadResponse($quote);
    }

    public function markApproved(Request $request, Order $quote): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if ($quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        if (! $this->canManageQuoteApprovals($tenant->id)) {
            abort(403);
        }

        if (!$quote->isPromotion() || !$quote->isQuote()) {
            abort(404);
        }

        if ($this->isConvertedQuote($quote)) {
            return back()->withErrors(['error' => 'Siparişe dönüşen teklif tekrar onaylanamaz.']);
        }

        if (!$quote->customer_company_id) {
            return back()->withErrors(['error' => 'Teklifi onaylamak için müşteri seçilmelidir.']);
        }

        if ($quote->items()->count() < 1) {
            return back()->withErrors(['error' => 'Teklifi onaylamak için en az bir ürün kalemi gerekli.']);
        }

        DB::transaction(function () use ($quote) {
            $this->quoteApprovalService->cancelOpenRequests($quote, 'manual_approved');

            $quote->forceFill([
                'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
                'customer_approval_source' => Order::CUSTOMER_APPROVAL_SOURCE_INTERNAL_MANUAL,
                'status' => 'approved',
                'approved_at' => now(),
                'rejected_at' => null,
                'revision_requested_at' => null,
            ])->save();
        });

        return back()->with('success', 'Teklif onaylandı olarak işaretlendi.');
    }

    public function sendToCustomer(Request $request, Order $quote): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if ($quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        if (! $this->canManageQuoteApprovals($tenant->id)) {
            abort(403);
        }

        if (!$this->customerQuoteApprovalModuleEnabled($tenant->id)) {
            abort(403, 'Müşteri onay modülü aktif değil.');
        }

        if (!$quote->isPromotion() || !$quote->isQuote()) {
            abort(404);
        }

        try {
            $recipientData = $this->normalizeSendRecipientData($request, $quote->loadMissing('customer'));
            $this->quoteApprovalService->sendToCustomer($quote, $recipientData, Auth::user());
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }

        return back()->with('success', 'Teklif müşteriye gönderime hazırlandı.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Order $quote)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        // Tenant isolation check
        if ($quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        // Verify this is a promotion quote
        if (!$quote->isPromotion() || !$quote->isQuote()) {
            abort(404);
        }

        // Check if quote can be edited
        if (!$quote->canBeEdited()) {
            abort(403, 'Bu teklif artık düzenlenemez.');
        }

        $quote->load(['customer', 'items.prints.tenantPrintSetting.standardPrintType']);

        // Get active customers
        $customers = Company::where('tenant_account_id', $tenant->id)
            ->where('status', 'active')
            ->whereHas('companyRoles', function ($q) {
                $q->where('role_key', 'customer');
            })
            ->orderBy('legal_name')
            ->get();

        $partnerCompanies = Company::where('tenant_account_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('legal_name')
            ->get(['id', 'legal_name']);

        $canViewFinancialData = Auth::user()?->canViewFinancialData($tenant->id) ?? false;
        $linkedSettingIds = $quote->items
            ->flatMap(fn ($item) => $item->prints->pluck('tenant_print_setting_id'))
            ->filter()
            ->values()
            ->all();

        return view('admin.promotion-quotes.edit', [
            'quote' => $quote,
            'customers' => $customers,
            'partnerCompanies' => $partnerCompanies,
            'catalogSearchUrl' => route('admin.catalog.search'),
            'canViewFinancialData' => $canViewFinancialData,
            'tenantPrintSettings' => $this->buildTenantPrintSettingsPayload($tenant->id, $canViewFinancialData, $linkedSettingIds),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Order $quote)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $canViewFinancialData = $request->user()?->canViewFinancialData($tenant->id) ?? false;
        
        // Tenant isolation check
        if ($quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        // Verify this is a promotion quote
        if (!$quote->isPromotion() || !$quote->isQuote()) {
            abort(404);
        }

        // Check if quote can be edited
        if (!$quote->canBeEdited()) {
            abort(403, 'Bu teklif artık düzenlenemez.');
        }

        $quote->loadMissing('items.prints');
        $allowedInactiveSettingIds = $quote->items
            ->flatMap(fn ($item) => $item->prints->pluck('tenant_print_setting_id'))
            ->filter()
            ->values()
            ->all();

        $validated = $request->validate([
            'customer_company_id' => 'required|exists:companies,id',
            'quote_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:quote_date',
            'invoice_status' => 'required|in:fis,fatura',
            'currency' => 'required|in:TL,USD,EUR',
            'items' => 'required|array|min:1',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.product_code' => 'nullable|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string|max:50',
            'items.*.list_price' => 'nullable|numeric|min:0',
            'items.*.discount_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.manual_unit_price' => 'nullable|boolean',
            'items.*.calculated_unit_price' => 'nullable|numeric|min:0',
            'items.*.vat_mode' => 'nullable|in:taxable,none,exclusive,inclusive,vat,vat_none,no_vat',
            'items.*.vat_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.has_print' => 'nullable|boolean',
            'items.*.tenant_catalog_product_id' => 'nullable|exists:tenant_catalog_products,id',
            'items.*.tenant_catalog_product_variant_id' => 'nullable|exists:tenant_catalog_product_variants,id',
            'items.*.standard_product_id' => 'nullable|exists:standard_products,id',
            'items.*.standard_product_variant_id' => 'nullable|exists:standard_product_variants,id',
            'items.*.supplier_id' => 'nullable|integer',
            'items.*.supplier_source_id' => 'nullable|exists:supplier_sources,id',
            'items.*.product_snapshot' => 'nullable',
            'items.*.price_snapshot' => 'nullable',
            'items.*.stock_snapshot' => 'nullable',
            'items.*.catalog_source' => 'nullable|string|max:100',
            'items.*.prints' => 'nullable|array',
            'items.*.prints.*.tenant_print_setting_id' => 'nullable|integer',
            'items.*.prints.*.standard_print_type_id' => 'nullable|integer|exists:standard_print_types,id',
            'items.*.prints.*.print_type' => 'nullable|string|max:255',
            'items.*.prints.*.print_option' => 'nullable|string|max:255',
            'items.*.prints.*.print_location' => 'nullable|string|max:255',
            'items.*.prints.*.production_type' => 'nullable|string|max:100',
            'items.*.prints.*.subcontractor_company_id' => 'nullable|exists:companies,id',
            'items.*.prints.*.print_color' => 'nullable|string|max:255',
            'items.*.prints.*.print_size' => 'nullable|string|max:255',
            'items.*.prints.*.cliche_status' => 'nullable|string|max:255',
            'items.*.prints.*.print_quantity' => 'nullable|numeric|min:0',
            'items.*.prints.*.print_unit_price' => 'nullable|numeric|min:0',
            'items.*.prints.*.note' => 'nullable|string',
            'items.*.prints.*.production_note' => 'nullable|string',
            'delivery_type' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $invoiceStatus = $this->resolveInvoiceStatus($validated['invoice_status'] ?? null);

            // Update quote
            $quote->update([
                'customer_company_id' => $validated['customer_company_id'],
                'quote_date' => $validated['quote_date'],
                'valid_until' => $validated['valid_until'] ?? null,
                'invoice_status' => $invoiceStatus,
                'delivery_type' => $validated['delivery_type'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'currency' => $validated['currency'],
            ]);

            // TODO: Simple approach - delete existing items and recreate
            // In a future version, implement diff/version tracking
            $quote->items()->delete();

            // Create new order items
            $netSubtotal = 0;
            $vatTotal = 0;
            $grossTotal = 0;

            foreach ($validated['items'] as $itemData) {
                $itemData['quantity'] = $this->normalizeDecimal($itemData['quantity']);
                $unitPricePayload = $this->resolveUnitPricePayload($itemData);
                $itemData['list_price'] = $unitPricePayload['list_price'];
                $itemData['discount_rate'] = $unitPricePayload['discount_rate'];
                $itemData['unit_price'] = $unitPricePayload['unit_price'];
                $unitPrice = $unitPricePayload['unit_price'];

                $catalogPayload = $this->resolveCatalogItemPayload($tenant->id, $itemData);
                $vatMode = $this->resolveQuoteVatMode($invoiceStatus);
                $vatRate = $vatMode === 'taxable'
                    ? (float) ($itemData['vat_rate'] ?? data_get($catalogPayload['price_snapshot'], 'vat_rate', 20) ?: 20)
                    : 0.0;
                $printVatRate = $vatMode === 'taxable' ? $this->defaultPrintVatRate() : 0.0;
                $lineBaseTotal = $unitPrice * $itemData['quantity'];
                $printLineTotal = 0;

                $orderItem = OrderItem::create([
                    'tenant_account_id' => $tenant->id,
                    'order_id' => $quote->id,
                    'item_type' => 'product',
                    'product_source' => $catalogPayload['product_source'],
                    'catalog_source' => $catalogPayload['catalog_source'],
                    'tenant_catalog_product_id' => $catalogPayload['tenant_catalog_product_id'],
                    'tenant_catalog_product_variant_id' => $catalogPayload['tenant_catalog_product_variant_id'],
                    'standard_product_id' => $catalogPayload['standard_product_id'],
                    'standard_product_variant_id' => $catalogPayload['standard_product_variant_id'],
                    'supplier_id' => $catalogPayload['supplier_id'],
                    'supplier_source_id' => $catalogPayload['supplier_source_id'],
                    'product_name' => $catalogPayload['product_name'] ?? $itemData['product_name'],
                    'product_code' => $catalogPayload['product_code'] ?? ($itemData['product_code'] ?? null),
                    'quantity' => $itemData['quantity'],
                    'unit' => $itemData['unit'],
                    'description' => $itemData['description'] ?? null,
                    'product_snapshot' => $catalogPayload['product_snapshot'],
                    'price_snapshot' => $catalogPayload['price_snapshot'],
                    'stock_snapshot' => $catalogPayload['stock_snapshot'],
                    'list_price' => $itemData['list_price'] ?? null,
                    'discount_rate' => $itemData['discount_rate'] ?? 0,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineBaseTotal,
                    'has_print' => $itemData['has_print'] ?? false,
                    'status' => 'pending',
                ]);

                // Create print details if provided
                if (($itemData['has_print'] ?? false) && isset($itemData['prints'])) {
                    foreach ($itemData['prints'] as $printData) {
                        // Normalize print decimal values
                        $printData['print_quantity'] = $this->normalizeDecimal($printData['print_quantity'] ?? 0);
                        $printData['print_unit_price'] = $this->normalizeDecimal($printData['print_unit_price'] ?? 0);
                        $selectedSetting = $this->findTenantPrintSettingForSave(
                            $tenant->id,
                            !empty($printData['tenant_print_setting_id']) ? (int) $printData['tenant_print_setting_id'] : null,
                            $allowedInactiveSettingIds
                        );
                        $resolvedSubcontractorId = $printData['subcontractor_company_id'] ?? null;

                        if ($selectedSetting && blank($resolvedSubcontractorId) && filled($selectedSetting->default_subcontractor_company_id)) {
                            $resolvedSubcontractorId = $selectedSetting->default_subcontractor_company_id;
                        }

                        if ($resolvedSubcontractorId) {
                            $resolvedSubcontractorId = Company::query()
                                ->where('tenant_account_id', $tenant->id)
                                ->whereKey($resolvedSubcontractorId)
                                ->value('id');
                        }

                        if (!$canViewFinancialData) {
                            $printData['print_unit_price'] = $this->normalizeDecimal($printData['print_unit_price'] ?? 0);
                        }

                        $currentPrintLineTotal = ($printData['print_unit_price'] ?? 0) * ($printData['print_quantity'] ?? 0);

                        OrderItemPrint::create([
                            'tenant_account_id' => $tenant->id,
                            'order_id' => $quote->id,
                            'order_item_id' => $orderItem->id,
                            'tenant_print_setting_id' => $selectedSetting?->id,
                            'standard_print_type_id' => $selectedSetting?->standard_print_type_id ?? ($printData['standard_print_type_id'] ?? null),
                            'print_type' => $selectedSetting ? $this->normalizePrintTypeForSetting($selectedSetting) : ($printData['print_type'] ?? null),
                            'print_option' => $printData['print_option'] ?? null,
                            'print_location' => $printData['print_location'] ?? null,
                            'production_type' => $this->normalizeLegacyProductionType($printData['production_type'] ?? null, $selectedSetting?->production_mode),
                            'subcontractor_company_id' => $resolvedSubcontractorId,
                            'print_color' => $printData['print_color'] ?? null,
                            'print_size' => $printData['print_size'] ?? null,
                            'cliche_status' => $printData['cliche_status'] ?? null,
                            'print_quantity' => $printData['print_quantity'] ?? null,
                            'print_unit_price' => $printData['print_unit_price'] ?? null,
                            'print_total' => $currentPrintLineTotal,
                            'note' => $printData['note'] ?? null,
                            'production_note' => $printData['production_note'] ?? null,
                            'status' => 'draft',
                        ]);

                        $printLineTotal += $currentPrintLineTotal;
                    }

                    // Update order item print total
                    $orderItem->print_total = $orderItem->prints()->sum('print_total');
                    $orderItem->save();
                }

                $productTaxBreakdown = $this->calculateVatBreakdown($lineBaseTotal, $vatRate, $vatMode);
                $printTaxBreakdown = $this->calculateVatBreakdown($printLineTotal, $printVatRate, $vatMode);
                $taxBreakdown = [
                    'net_total' => $productTaxBreakdown['net_total'] + $printTaxBreakdown['net_total'],
                    'vat_total' => $productTaxBreakdown['vat_total'] + $printTaxBreakdown['vat_total'],
                    'gross_total' => $productTaxBreakdown['gross_total'] + $printTaxBreakdown['gross_total'],
                ];
                $priceSnapshot = $orderItem->price_snapshot ?? [];
                $priceSnapshot['invoice_status'] = $invoiceStatus;
                $priceSnapshot['vat_mode'] = $vatMode;
                $priceSnapshot['vat_rate'] = $vatRate;
                $priceSnapshot['print_vat_rate'] = $printVatRate;
                $priceSnapshot['calculated_unit_price'] = $unitPricePayload['calculated_unit_price'];
                $priceSnapshot['manual_unit_price'] = $unitPricePayload['manual_unit_price'];
                $priceSnapshot['product_line_total'] = round($lineBaseTotal, 2);
                $priceSnapshot['print_line_total'] = round($printLineTotal, 2);
                $priceSnapshot['product_total'] = round($lineBaseTotal, 2);
                $priceSnapshot['print_total'] = round($printLineTotal, 2);
                $priceSnapshot['subtotal'] = round($taxBreakdown['net_total'], 2);
                $priceSnapshot['product_vat_total'] = round($productTaxBreakdown['vat_total'], 2);
                $priceSnapshot['print_vat_total'] = round($printTaxBreakdown['vat_total'], 2);
                $priceSnapshot['vat_breakdown'] = array_values(array_filter([
                    $vatMode === 'taxable' && $vatRate > 0 ? ['rate' => $vatRate, 'total' => round($productTaxBreakdown['vat_total'], 2), 'scope' => 'product'] : null,
                    $vatMode === 'taxable' && $printVatRate > 0 && $printLineTotal > 0 ? ['rate' => $printVatRate, 'total' => round($printTaxBreakdown['vat_total'], 2), 'scope' => 'print'] : null,
                ]));
                $priceSnapshot['vat_total'] = round($taxBreakdown['vat_total'], 2);
                $priceSnapshot['line_vat_total'] = round($taxBreakdown['vat_total'], 2);
                $priceSnapshot['line_net_total'] = round($taxBreakdown['net_total'], 2);
                $priceSnapshot['grand_total'] = round($taxBreakdown['gross_total'], 2);
                $priceSnapshot['line_gross_total'] = round($taxBreakdown['gross_total'], 2);
                $orderItem->price_snapshot = $priceSnapshot;
                $orderItem->save();

                $netSubtotal += $taxBreakdown['net_total'];
                $vatTotal += $taxBreakdown['vat_total'];
                $grossTotal += $taxBreakdown['gross_total'];
            }

            $quote->update([
                'subtotal' => $netSubtotal,
                'vat_total' => $vatTotal,
                'grand_total' => $grossTotal,
            ]);

            // TODO: Log audit trail
            // AuditLog::logQuoteUpdated($tenant->id, $quote->id, Auth::id());

            DB::commit();

            return redirect()
                ->route('admin.promotion-quotes.show', $quote)
                ->with('success', 'Promosyon teklifi başarıyla güncellendi.');

        } catch (\Exception $e) {
            DB::rollback();
            
            return back()
                ->withInput()
                ->withErrors(['error' => $this->humanizeQuoteException($e)]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Order $quote)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        // Tenant isolation check
        if ($quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        // Verify this is a promotion quote
        if (!$quote->isPromotion() || !$quote->isQuote()) {
            abort(404);
        }

        try {
            $quote->update(['status' => 'cancelled']);

            // TODO: Log audit trail
            // AuditLog::logQuoteCancelled($tenant->id, $quote->id, Auth::id());

            return redirect()
                ->route('admin.promotion-quotes.index')
                ->with('success', 'Promosyon teklifi iptal edildi.');

        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Teklif iptal edilemedi: ' . $e->getMessage()]);
        }
    }

    private function resolveCatalogItemPayload(int $tenantId, array $itemData): array
    {
        $catalogProduct = null;
        $catalogVariant = null;
        $productSnapshot = $this->decodeJsonField($itemData['product_snapshot'] ?? null);
        $priceSnapshot = $this->decodeJsonField($itemData['price_snapshot'] ?? null) ?? [];
        $stockSnapshot = $this->decodeJsonField($itemData['stock_snapshot'] ?? null);
        $priceSnapshot['vat_mode'] = $this->resolveQuoteVatMode($itemData['invoice_status'] ?? data_get($priceSnapshot, 'invoice_status'));
        $priceSnapshot['invoice_status'] = $this->resolveInvoiceStatus($itemData['invoice_status'] ?? data_get($priceSnapshot, 'invoice_status'));

        if (!empty($itemData['tenant_catalog_product_id'])) {
            $catalogProduct = TenantCatalogProduct::query()
                ->where('tenant_account_id', $tenantId)
                ->find($itemData['tenant_catalog_product_id']);

            if (!$catalogProduct) {
                abort(403, 'Seçilen katalog ürünü bu tenant için geçerli değil.');
            }
        }

        $catalogVariantId = $itemData['tenant_catalog_product_variant_id'] ?? data_get($productSnapshot, 'tenant_catalog_product_variant_id');

        if (!empty($catalogVariantId)) {
            $catalogVariant = TenantCatalogProductVariant::query()
                ->where('tenant_account_id', $tenantId)
                ->find($catalogVariantId);

            if (!$catalogVariant) {
                abort(403, 'Seçilen katalog varyasyonu bu tenant için geçerli değil.');
            }

            if ($catalogProduct && $catalogVariant->tenant_catalog_product_id !== $catalogProduct->id) {
                abort(403, 'Seçilen katalog varyasyonu ürün ile eşleşmiyor.');
            }

            $catalogProduct ??= $catalogVariant->catalogProduct;
        }

        if (!$catalogProduct) {
            return [
                'product_source' => 'manual',
                'catalog_source' => null,
                'tenant_catalog_product_id' => null,
                'tenant_catalog_product_variant_id' => null,
                'standard_product_id' => !empty($itemData['standard_product_id']) ? (int) $itemData['standard_product_id'] : null,
                'standard_product_variant_id' => !empty($itemData['standard_product_variant_id']) ? (int) $itemData['standard_product_variant_id'] : null,
                'supplier_id' => null,
                'supplier_source_id' => !empty($itemData['supplier_source_id']) ? (int) $itemData['supplier_source_id'] : null,
                'product_name' => $itemData['product_name'],
                'product_code' => $itemData['product_code'] ?? null,
                'product_snapshot' => $productSnapshot,
                'price_snapshot' => $priceSnapshot,
                'stock_snapshot' => $stockSnapshot,
            ];
        }

        $sourceSummary = collect($catalogProduct->source_summary ?? []);
        $primarySource = $sourceSummary->first() ?? [];
        $supplierSourceId = $catalogVariant?->source_summary['supplier_source_id'] ?? data_get($primarySource, 'supplier_source_id');
        $supplierId = $catalogVariant?->source_summary['supplier_id'] ?? data_get($primarySource, 'supplier_id');

        if ($supplierSourceId && !SupplierSource::query()->whereKey($supplierSourceId)->exists()) {
            $supplierSourceId = null;
        }

        if ($supplierId && (!Supplier::query()->whereKey($supplierId)->exists() || !Company::query()->whereKey($supplierId)->exists())) {
            $supplierId = null;
        }

        $productSnapshot ??= [
            'tenant_catalog_product_id' => $catalogProduct->id,
            'tenant_catalog_product_variant_id' => $catalogVariant?->id,
            'standard_product_id' => $catalogProduct->standard_product_id,
            'standard_product_variant_id' => $catalogVariant?->standard_product_variant_id,
            'product_code' => $catalogVariant?->variant_code ?: $catalogProduct->display_code,
            'product_name' => $catalogVariant?->display_name ?: $catalogProduct->display_name,
            'image_url' => $catalogVariant?->image_url ?: $catalogProduct->image_url,
            'category_name' => $catalogProduct->category_display_name,
            'supplier_name' => $catalogProduct->source_summary[0]['supplier_name'] ?? null,
            'catalog_source' => $catalogProduct->catalog_source,
            'catalog_source_label' => $this->resolveCatalogSourceLabel($catalogProduct),
            'local_stock_priority' => (bool) ($catalogProduct->local_stock_priority ?? true),
            'local_stock_quantity' => (float) ($catalogVariant?->local_stock_quantity ?? $catalogProduct->local_stock_quantity ?? 0),
            'supplier_stock_quantity' => (float) ($catalogVariant?->supplier_stock_quantity ?? $catalogProduct->supplier_stock_quantity ?? 0),
            'visible_stock_quantity' => $this->resolveEffectiveStock(
                (float) ($catalogVariant?->local_stock_quantity ?? $catalogProduct->local_stock_quantity ?? 0),
                (float) ($catalogVariant?->supplier_stock_quantity ?? $catalogProduct->supplier_stock_quantity ?? 0),
                (float) ($catalogVariant?->stock_quantity ?? $catalogProduct->total_stock_quantity ?? 0),
                (bool) ($catalogProduct->local_stock_priority ?? true)
            ),
            'warning_badges' => $this->resolveWarningBadges($catalogProduct, $catalogVariant),
            'warning_messages' => $this->resolveWarningMessages($catalogProduct, $catalogVariant),
            'source_summary' => $catalogVariant?->source_summary ?: $catalogProduct->source_summary,
        ];

        $priceSnapshot ??= [
            'display_price' => (float) ($catalogVariant?->display_price ?? $catalogProduct->display_price ?? 0),
            'list_price' => (float) (data_get($catalogVariant?->meta, 'price_snapshot.list_price') ?? data_get($catalogProduct->meta, 'price_snapshot.list_price') ?? $catalogVariant?->display_price ?? $catalogProduct->display_price ?? 0),
            'currency' => $catalogVariant?->currency ?? $catalogProduct->currency ?? 'TL',
            'price_multiplier' => (float) ($catalogProduct->price_multiplier ?? 1),
            'vat_rate' => (float) (data_get($catalogVariant?->source_summary, 'vat_rate') ?? data_get($catalogProduct->source_summary, '0.vat_rate') ?? 20),
            'vat_mode' => $this->resolveQuoteVatMode($itemData['invoice_status'] ?? 'fis'),
            'invoice_status' => $this->resolveInvoiceStatus($itemData['invoice_status'] ?? 'fis'),
            'net_price_warning' => (bool) (data_get($catalogVariant?->meta, 'net_price_warning') ?? data_get($catalogProduct->meta, 'net_price_warning', false)),
            'price_policy_warning' => (bool) (data_get($catalogVariant?->meta, 'price_policy_warning') ?? data_get($catalogProduct->meta, 'price_policy_warning', false)),
            'pricing_policy_type' => data_get($catalogVariant?->meta, 'pricing_policy_type') ?? data_get($catalogProduct->meta, 'pricing_policy_type'),
            'supplier_warning_flag' => (bool) (data_get($catalogVariant?->meta, 'supplier_warning_flag') ?? data_get($catalogProduct->meta, 'supplier_warning_flag', false)),
            'supplier_warning_type' => data_get($catalogVariant?->meta, 'supplier_warning_type') ?? data_get($catalogProduct->meta, 'supplier_warning_type'),
            'warning_badges' => $this->resolveWarningBadges($catalogProduct, $catalogVariant),
            'warning_messages' => $this->resolveWarningMessages($catalogProduct, $catalogVariant),
        ];

        $stockSnapshot ??= [
            'total_stock_quantity' => (float) ($catalogProduct->total_stock_quantity ?? 0),
            'local_stock_quantity' => (float) ($catalogVariant?->local_stock_quantity ?? $catalogProduct->local_stock_quantity ?? 0),
            'supplier_stock_quantity' => (float) ($catalogVariant?->supplier_stock_quantity ?? $catalogProduct->supplier_stock_quantity ?? 0),
            'visible_stock_quantity' => $this->resolveEffectiveStock(
                (float) ($catalogVariant?->local_stock_quantity ?? $catalogProduct->local_stock_quantity ?? 0),
                (float) ($catalogVariant?->supplier_stock_quantity ?? $catalogProduct->supplier_stock_quantity ?? 0),
                (float) ($catalogVariant?->stock_quantity ?? $catalogProduct->total_stock_quantity ?? 0),
                (bool) ($catalogProduct->local_stock_priority ?? true)
            ),
            'safe_stock_quantity' => (int) ($catalogVariant?->safe_stock_quantity ?? $catalogProduct->safe_stock_quantity ?? 0),
            'local_stock_priority' => (bool) ($catalogProduct->local_stock_priority ?? true),
            'stock_status' => $this->resolveEffectiveStock(
                (float) ($catalogVariant?->local_stock_quantity ?? $catalogProduct->local_stock_quantity ?? 0),
                (float) ($catalogVariant?->supplier_stock_quantity ?? $catalogProduct->supplier_stock_quantity ?? 0),
                (float) ($catalogVariant?->stock_quantity ?? $catalogProduct->total_stock_quantity ?? 0),
                (bool) ($catalogProduct->local_stock_priority ?? true)
            ) > 0 ? 'available' : 'out_of_stock',
            'warning_flag' => (bool) ($catalogProduct->standardProduct?->warning_flag ?? false),
        ];

        return [
            'product_source' => 'tenant_catalog',
            'catalog_source' => $itemData['catalog_source'] ?? 'tenant_catalog',
            'tenant_catalog_product_id' => $catalogProduct->id,
            'tenant_catalog_product_variant_id' => $catalogVariant?->id,
            'standard_product_id' => $catalogProduct->standard_product_id ?: (!empty($itemData['standard_product_id']) ? (int) $itemData['standard_product_id'] : null),
            'standard_product_variant_id' => $catalogVariant?->standard_product_variant_id ?: (!empty($itemData['standard_product_variant_id']) ? (int) $itemData['standard_product_variant_id'] : null),
            'supplier_id' => $supplierId,
            'supplier_source_id' => $supplierSourceId ?: (!empty($itemData['supplier_source_id']) ? (int) $itemData['supplier_source_id'] : null),
            'product_name' => $productSnapshot['product_name'] ?? $itemData['product_name'],
            'product_code' => $productSnapshot['product_code'] ?? ($itemData['product_code'] ?? null),
            'product_snapshot' => $productSnapshot,
            'price_snapshot' => $priceSnapshot,
            'stock_snapshot' => $stockSnapshot,
        ];
    }

    private function decodeJsonField(mixed $value): ?array
    {
        if (blank($value)) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : null;
    }

    private function resolveWarningBadges(TenantCatalogProduct $catalogProduct, ?TenantCatalogProductVariant $catalogVariant = null): array
    {
        return $this->buildWarningPayload($catalogProduct, $catalogVariant)['badges'];
    }

    private function resolveWarningMessages(TenantCatalogProduct $catalogProduct, ?TenantCatalogProductVariant $catalogVariant = null): array
    {
        return $this->buildWarningPayload($catalogProduct, $catalogVariant)['messages'];
    }

    private function buildWarningPayload(TenantCatalogProduct $catalogProduct, ?TenantCatalogProductVariant $catalogVariant = null): array
    {
        $badges = [];
        $messages = [];
        $variantMeta = $catalogVariant?->meta ?? [];
        $productMeta = $catalogProduct->meta ?? [];
        $effectiveStock = $this->resolveEffectiveStock(
            (float) ($catalogVariant?->local_stock_quantity ?? $catalogProduct->local_stock_quantity ?? 0),
            (float) ($catalogVariant?->supplier_stock_quantity ?? $catalogProduct->supplier_stock_quantity ?? 0),
            (float) ($catalogVariant?->stock_quantity ?? $catalogProduct->total_stock_quantity ?? 0),
            (bool) ($catalogProduct->local_stock_priority ?? true)
        );

        if ((bool) (data_get($variantMeta, 'net_price_warning') ?? data_get($productMeta, 'net_price_warning', false))
            || ((data_get($variantMeta, 'pricing_policy_type') ?? data_get($productMeta, 'pricing_policy_type')) === 'net_price')) {
            $badges[] = 'Net fiyat uyarısı';
            $messages[] = 'Bu ürün net fiyatlı olabilir. Teklif/sipariş sırasında standart iskonto uygulanmamalı; gerekirse birim satış fiyatı artırılarak çalışılmalıdır.';
        }

        if ((bool) (data_get($variantMeta, 'supplier_warning_flag') ?? data_get($productMeta, 'supplier_warning_flag', false))
            || filled(data_get($variantMeta, 'supplier_warning_type') ?? data_get($productMeta, 'supplier_warning_type'))) {
            $badges[] = 'Özel fiyat uyarısı';
            $messages[] = 'Bu ürün tedarikçi tarafından özel fiyat/iskonto uyarılı işaretlenmiş. Standart indirim uygulanmadan önce kontrol edilmelidir.';
        }

        $warningList = array_values(array_filter(array_merge(
            (array) data_get($variantMeta, 'warnings', []),
            (array) data_get($productMeta, 'supplier_warnings', []),
            (array) data_get($productMeta, 'warnings', [])
        )));

        if ((bool) (data_get($variantMeta, 'price_policy_warning') ?? data_get($productMeta, 'price_policy_warning', false))) {
            $badges[] = 'Fiyat kontrolü gerekli';
            $messages = array_merge($messages, $warningList);
            if (empty($warningList)) {
                $messages[] = 'Bu ürünün fiyat politikası kontrol edilmelidir.';
            }
        }

        if (is_null(data_get($variantMeta, 'price_snapshot.list_price')) && is_null(data_get($productMeta, 'price_snapshot.list_price')) && is_null($catalogVariant?->display_price) && is_null($catalogProduct->display_price)) {
            $badges[] = 'Fiyat eksik';
            $messages[] = 'Bu üründe liste fiyatı bulunamadı. Teklif oluşturmadan önce fiyat manuel kontrol edilmelidir.';
        }

        if (blank($catalogVariant?->image_url ?: $catalogProduct->image_url)) {
            $badges[] = 'Görsel eksik';
            $messages[] = 'Bu ürün için katalog görseli eksik.';
        }

        if (blank($catalogProduct->standard_category_id)
            || (bool) data_get($productMeta, 'category_missing_warning', false)
            || data_get($productMeta, 'fallback_category_code') === 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN') {
            $badges[] = 'Kategori Bekliyor';
            $badges[] = 'Kategori eksik';
            $messages[] = 'Bu ürünün standart kategori eşlemesi bekliyor; ürün yine teklif aramasında kullanılabilir.';
        }

        if ($effectiveStock <= 0) {
            $badges[] = 'Stok yok';
            $messages[] = 'Bu ürün için stok bilgisi yok veya stok sıfır.';
        }

        if (in_array((string) ($catalogProduct->catalog_status ?? ''), ['missing_from_feed', 'inactive_candidate'], true)
            || ((string) data_get($productMeta, 'projection_status') === 'missing_from_feed')) {
            $badges[] = 'XML’den çıkan ürün';
            $messages[] = 'Bu ürün tedarikçi kaynağında artık görünmüyor olabilir. Teklif öncesi tekrar kontrol edilmelidir.';
        }

        if ((string) ($catalogProduct->catalog_status ?? '') === 'category_conflict'
            || ((string) data_get($productMeta, 'projection_status') === 'category_conflict')) {
            $badges[] = 'Kategori conflict';
            $messages[] = 'Bu ürün için kategori kararı çakışmalı. Katalog eşlemesi kontrol edilmelidir.';
        }

        return [
            'badges' => array_values(array_unique(array_filter($badges))),
            'messages' => array_values(array_unique(array_filter($messages))),
        ];
    }

    private function resolveEffectiveStock(float $localStock, float $supplierStock, float $fallbackStock, bool $localStockPriority = true): float
    {
        if ($localStockPriority && $localStock > 0) {
            return $localStock;
        }

        if ($supplierStock > 0) {
            return $supplierStock;
        }

        if ($localStock > 0) {
            return $localStock;
        }

        return max(0, $fallbackStock);
    }

    private function resolveCatalogSourceLabel(TenantCatalogProduct $catalogProduct): string
    {
        return $catalogProduct->catalog_source === 'local_product'
            ? 'Local Ürün'
            : 'Tedarikçi Ürünü';
    }
}
