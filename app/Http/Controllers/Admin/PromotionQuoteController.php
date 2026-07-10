<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyContact;
use App\Models\CompanyRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\NotificationLog;
use App\Models\TenantAccount;
use App\Models\TenantPrintOption;
use App\Models\TenantPrintSetting;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Services\ModuleFeatureCatalogService;
use App\Services\CurrentAccountSyncService;
use App\Services\OrderRevisionApplyService;
use App\Services\OrderRevisionComparisonService;
use App\Services\OrderRevisionRecordService;
use App\Services\ProductDataHub\ProductHubSellableTruthService;
use App\Services\ProductDataHub\SupplierWarningLabelService;
use App\Services\PromotionQuotePdfService;
use App\Services\Notifications\TenantNotificationSettingsService;
use App\Services\Notifications\TenantWhatsappLinkService;
use App\Services\TenantAccessService;
use App\Services\TenantDeliveryTypeService;
use App\Services\TenantResolver;
use App\Services\NumberGenerationService;
use App\Services\PrintSetupUnitDistributionService;
use App\Services\QuoteApprovalService;
use App\Services\TenantPrintOptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\UsageLimitGuardService;
use DomainException;
use Symfony\Component\HttpFoundation\Response;

class PromotionQuoteController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected NumberGenerationService $numberGenerationService,
        protected QuoteApprovalService $quoteApprovalService,
        protected ModuleFeatureCatalogService $moduleFeatureCatalog,
        protected UsageLimitGuardService $usageLimitGuardService,
        protected CurrentAccountSyncService $currentAccountSyncService,
        protected TenantWhatsappLinkService $tenantWhatsappLinkService,
        protected TenantNotificationSettingsService $tenantNotificationSettingsService,
        protected TenantAccessService $tenantAccessService,
        protected TenantDeliveryTypeService $tenantDeliveryTypeService,
        protected SupplierWarningLabelService $supplierWarningLabelService,
        protected ProductHubSellableTruthService $sellableTruthService,
        protected OrderRevisionComparisonService $orderRevisionComparisonService,
        protected PrintSetupUnitDistributionService $printSetupUnitDistributionService,
        protected TenantPrintOptionService $tenantPrintOptionService,
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

    private function resolveShowPrintPriceDetailsToCustomer(array $validated, ?Order $quote = null): bool
    {
        if (array_key_exists('show_print_price_details_to_customer', $validated)) {
            return filter_var(
                $validated['show_print_price_details_to_customer'],
                FILTER_VALIDATE_BOOL
            );
        }

        return $quote?->shouldShowPrintPriceDetailsToCustomer() ?? true;
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

    private function buildSourceOrderContext(Order $quote, TenantAccount $tenant): array
    {
        $sourceOrder = $quote->sourceOrder;
        $label = $quote->copyTypeLabel();

        if (! $sourceOrder || ! $label) {
            return [
                'visible' => false,
                'badge' => null,
                'source_label' => null,
                'warning' => null,
                'general_warning' => null,
                'url' => null,
            ];
        }

        return [
            'visible' => true,
            'badge' => $label,
            'source_label' => 'Kaynak Sipariş: ' . ($sourceOrder->document_number ?: '-'),
            'warning' => $quote->copyTypeWarning(),
            'general_warning' => 'Bu kayıt eski siparişten kopyalanmıştır. Fiyat, stok ve baskı bilgilerini kontrol ederek devam edin.',
            'url' => (int) $sourceOrder->tenant_account_id === (int) $tenant->id
                ? route('admin.orders.show', $sourceOrder)
                : null,
        ];
    }

    private function canAccessRevisionCompare(int $tenantId): bool
    {
        return Auth::user()?->hasAnyPermissionInTenant([
            'create_quotes',
            'edit_quotes',
            'approve_quotes',
        ], $tenantId) ?? false;
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

    private function buildNotificationLogRows(Order $quote): array
    {
        return NotificationLog::query()
            ->where('tenant_account_id', $quote->tenant_account_id)
            ->where('related_type', $quote->getMorphClass())
            ->where('related_id', $quote->id)
            ->whereIn('notification_key', ['quote_sent_to_customer', 'whatsapp_manual_link'])
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(function (NotificationLog $log): array {
                $recipient = $log->recipient_name
                    ?: $log->recipient_email
                    ?: $log->recipient_phone
                    ?: $log->safeAudienceLabel();

                if (filled($log->recipient_email)) {
                    $recipient = $this->maskEmail($log->recipient_email);
                } elseif (filled($log->recipient_phone)) {
                    $recipient = $this->maskPhone($log->recipient_phone);
                }

                $detail = match ($log->status) {
                    NotificationLog::STATUS_PREVIEW => 'Bu ortamda güvenli e-posta önizlemesi oluşturuldu.',
                    NotificationLog::STATUS_LINK_CREATED => 'Hazır bağlantı oluşturuldu.',
                    NotificationLog::STATUS_SKIPPED => $log->safeDisplayError() ?: 'Alıcı bilgisi veya kanal ayarı eksik olduğu için gönderim atlandı.',
                    NotificationLog::STATUS_SENT => $log->safeDisplayPreview() ?: 'Gönderim kaydı başarıyla oluşturuldu.',
                    NotificationLog::STATUS_FAILED => $log->safeDisplayError() ?: 'Gönderim sırasında kısa süreli bir hata oluştu.',
                    default => $log->safeDisplayPreview() ?: $log->safeDisplayError() ?: 'Kayıt oluşturuldu.',
                };

                return [
                    'date' => optional($log->created_at)->format('d.m.Y H:i'),
                    'channel' => $log->safeChannelLabel(),
                    'status' => $log->safeStatusLabel(),
                    'recipient' => $recipient ?: '-',
                    'detail' => Str::limit((string) $detail, 180),
                ];
            })
            ->all();
    }

    private function buildSendNotificationSummary(Order $quote): array
    {
        $logs = NotificationLog::query()
            ->where('tenant_account_id', $quote->tenant_account_id)
            ->where('related_type', $quote->getMorphClass())
            ->where('related_id', $quote->id)
            ->whereIn('notification_key', ['quote_sent_to_customer', 'whatsapp_manual_link'])
            ->latest('id')
            ->get();

        $emailLog = $logs
            ->first(fn (NotificationLog $log) => $log->notification_key === 'quote_sent_to_customer' && $log->channel === NotificationLog::CHANNEL_EMAIL);
        $whatsappLog = $logs
            ->first(fn (NotificationLog $log) => in_array($log->notification_key, ['quote_sent_to_customer', 'whatsapp_manual_link'], true) && $log->channel === NotificationLog::CHANNEL_WHATSAPP_LINK);
        $internalLog = $logs
            ->first(fn (NotificationLog $log) => $log->notification_key === 'quote_sent_to_customer' && $log->channel === NotificationLog::CHANNEL_INTERNAL);

        return [
            'email' => $this->mapNotificationSummaryRow($emailLog, 'Henüz oluşturulmadı'),
            'whatsapp' => $this->mapNotificationSummaryRow($whatsappLog, 'Henüz oluşturulmadı'),
            'internal' => $this->mapNotificationSummaryRow($internalLog, 'Henüz oluşturulmadı'),
        ];
    }

    private function mapNotificationSummaryRow(?NotificationLog $log, string $missingLabel): array
    {
        if (! $log) {
            return [
                'status' => $missingLabel,
                'helper' => null,
                'created_at' => null,
                'status_code' => null,
            ];
        }

        $helper = match ($log->status) {
            NotificationLog::STATUS_PREVIEW => 'Bu ortamda dış e-posta yerine güvenli önizleme kaydı tutulur.',
            NotificationLog::STATUS_LINK_CREATED => 'Hazır mesaj veya onay bağlantısı güvenli link olarak oluşturuldu.',
            NotificationLog::STATUS_SKIPPED => $log->safeDisplayError() ?: 'Bu kanal için alıcı bilgisi veya ayar eksik olduğu için kayıt atlandı.',
            NotificationLog::STATUS_SENT => 'Operasyon kaydı oluşturuldu.',
            NotificationLog::STATUS_FAILED => $log->safeDisplayError() ?: 'Gönderim denenirken hata oluştu.',
            default => $log->safeDisplayError(),
        };

        return [
            'status' => $log->safeStatusLabel(),
            'helper' => $helper,
            'created_at' => $log->created_at,
            'status_code' => $log->status,
        ];
    }

    private function buildSendSuccessMessage(Order $quote, ?string $sentChannel = null): string
    {
        if ($sentChannel === 'email') {
            return 'E-posta önizlemesi oluşturuldu. Bu işlem müşteriye mail göndermez.';
        }

        if ($sentChannel === 'whatsapp_link') {
            return 'WhatsApp mesaj linki oluşturuldu. Public onay linki hazır.';
        }

        $summary = $this->buildSendNotificationSummary($quote);
        $segments = ['Gönderim kaydı oluşturuldu.'];

        if (($summary['email']['status_code'] ?? null) === NotificationLog::STATUS_SENT) {
            $segments = ['Teklif müşteriye e-posta olarak gönderildi.'];
        } elseif (($summary['email']['status_code'] ?? null) === NotificationLog::STATUS_PREVIEW) {
            $segments[] = 'E-posta bu ortamda önizleme olarak kaydedildi.';
        } elseif (($summary['email']['status_code'] ?? null) === NotificationLog::STATUS_SKIPPED) {
            $segments[] = 'E-posta kaydı alıcı bilgisi veya ayar eksikliği nedeniyle atlandı.';
        }

        if ($quote->latestQuoteApprovalRequest && ! $quote->latestQuoteApprovalRequest->isCancelled()) {
            $segments[] = 'Public onay linki hazır.';
        }

        if (($summary['whatsapp']['status_code'] ?? null) === NotificationLog::STATUS_LINK_CREATED) {
            $segments[] = 'WhatsApp hazır mesaj linki üretildi.';
        }

        return implode(' ', $segments);
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

        $primaryContact = $quote->customer?->getPrimaryContact();
        $resolvedEmail = $validated['contact_email'] ?? ($primaryContact?->email ?: ($quote->customer?->email ?: null));
        $resolvedPhone = $validated['contact_phone'] ?? ($primaryContact?->mobile ?: ($primaryContact?->phone ?: ($quote->customer?->mobile ?: ($quote->customer?->phone ?: null))));
        $resolvedName = $validated['contact_name'] ?? ($primaryContact?->name ?: ($quote->customer?->legal_name ?: null));

        return [
            'contact_name' => $resolvedName,
            'contact_email' => $resolvedEmail,
            'contact_phone' => $resolvedPhone,
            'expires_in_days' => $validated['expires_in_days'] ?? 7,
            'sent_channel' => $validated['sent_channel'] ?? 'manual',
            'sent_to_name' => $resolvedName,
            'sent_to_email' => $resolvedEmail,
            'sent_to_phone' => $resolvedPhone,
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

    private function normalizeQuoteItemInput(array $itemData, string $invoiceStatus): array
    {
        $normalized = $itemData;
        $normalized['invoice_status'] = $this->resolveInvoiceStatus($itemData['invoice_status'] ?? $invoiceStatus);
        $normalized['quantity'] = $this->normalizeDecimal($itemData['quantity'] ?? 0);
        $normalized['has_print'] = filter_var($itemData['has_print'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $normalized['vat_rate'] = $this->normalizeDecimal($itemData['vat_rate'] ?? 0);
        $normalized['unit'] = $itemData['unit'] ?? 'Adet';
        $normalized['product_name'] = trim((string) ($itemData['product_name'] ?? ''));
        $normalized['product_code'] = filled($itemData['product_code'] ?? null) ? trim((string) $itemData['product_code']) : null;
        $normalized['prints'] = is_array($itemData['prints'] ?? null) ? array_values($itemData['prints']) : [];

        return $normalized;
    }

    private function selectedCatalogIdentity(array $itemData): array
    {
        return $this->decodeJsonField($itemData['selected_catalog_identity'] ?? null) ?? [];
    }

    private function validationMessageKey(?int $itemIndex, string $field = 'items'): string
    {
        if ($itemIndex === null) {
            return $field;
        }

        return $field === 'items'
            ? sprintf('items.%d', $itemIndex)
            : sprintf('items.%d.%s', $itemIndex, $field);
    }

    private function throwItemValidation(?int $itemIndex, string $field, string $message): never
    {
        throw ValidationException::withMessages([
            'error' => 'Teklif kaydedilemedi. Hatalı satırları kontrol edip tekrar deneyin.',
            $this->validationMessageKey($itemIndex, $field) => $message,
        ]);
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

    private function buildTenantPrintSettingsPayload(
        int $tenantId,
        bool $canViewFinancialData,
        array $includeSettingIds = [],
        array $includeOptionIds = []
    ): array
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

        $settings->each(fn (TenantPrintSetting $setting) => $this->tenantPrintOptionService->ensureDefaultsForSetting($setting));

        $optionsBySetting = TenantPrintOption::query()
            ->where('tenant_account_id', $tenantId)
            ->whereIn('tenant_print_setting_id', $settings->pluck('id'))
            ->where(function ($query) use ($includeOptionIds): void {
                $query->where('is_active', true);

                if (!empty($includeOptionIds)) {
                    $query->orWhereIn('id', collect($includeOptionIds)->map(fn ($id) => (int) $id)->all());
                }
            })
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('tenant_print_setting_id');

        return $settings->map(function (TenantPrintSetting $setting) use ($canViewFinancialData, $optionsBySetting): array {
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
                'options' => ($optionsBySetting->get($setting->id) ?? collect())->map(function (TenantPrintOption $option) use ($canViewFinancialData): array {
                    $row = [
                        'id' => $option->id,
                        'name' => $option->displayName(),
                        'code' => $option->code,
                        'description' => $option->description,
                        'is_active' => (bool) $option->is_active,
                        'sort_order' => (int) $option->sort_order,
                        'is_default' => (bool) $option->is_default,
                        'requires_setup' => (bool) $option->requires_setup,
                        'setup_type' => $option->setup_type,
                        'setup_status_default' => $option->setup_status_default,
                    ];

                    if ($canViewFinancialData) {
                        $row['default_unit_price'] = $option->default_unit_price;
                    }

                    return $row;
                })->values()->all(),
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

    private function findTenantPrintOptionForSave(
        int $tenantId,
        ?TenantPrintSetting $setting,
        ?int $optionId,
        ?string $legacyLabel = null,
        array $allowedInactiveIds = []
    ): ?TenantPrintOption {
        if (!$optionId) {
            return null;
        }

        if (!$setting) {
            throw ValidationException::withMessages([
                'items' => 'Seçilen baskı seçeneği bu baskı türüne ait değil.',
            ]);
        }

        $option = TenantPrintOption::query()
            ->where('tenant_account_id', $tenantId)
            ->where('tenant_print_setting_id', $setting->id)
            ->find($optionId);

        if (!$option) {
            throw ValidationException::withMessages([
                'items' => 'Seçilen baskı seçeneği geçerli değil.',
            ]);
        }

        $inactiveAllowed = in_array($option->id, array_map('intval', $allowedInactiveIds), true);
        if (!$option->is_active && !$inactiveAllowed) {
            throw ValidationException::withMessages([
                'items' => 'Pasif baskı seçeneği yeni teklif satırında kullanılamaz.',
            ]);
        }

        if (filled($legacyLabel) && trim((string) $legacyLabel) !== $option->displayName()) {
            throw ValidationException::withMessages([
                'items' => 'Seçilen baskı seçeneği bu baskı türüne ait değil.',
            ]);
        }

        return $option;
    }

    private function resolveDeliveryTypePayload(
        int $tenantId,
        array $validated,
        ?int $allowedInactiveId = null
    ): array {
        return $this->tenantDeliveryTypeService->resolveForPersistence(
            $tenantId,
            isset($validated['delivery_type_id']) && $validated['delivery_type_id'] !== ''
                ? (int) $validated['delivery_type_id']
                : null,
            $validated['delivery_type'] ?? null,
            $allowedInactiveId
        );
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

    private function resolveSetupType(?TenantPrintSetting $setting, ?TenantPrintOption $option, array $printData): ?string
    {
        $setupType = trim((string) ($printData['setup_type'] ?? ''));

        if ($setupType !== '') {
            return $setupType;
        }

        if (filled($option?->setup_type)) {
            return (string) $option->setup_type;
        }

        $setupTypes = $setting?->effectiveSetupTypes() ?? [];

        return filled($setupTypes[0] ?? null)
            ? (string) $setupTypes[0]
            : null;
    }

    private function normalizePrintSetupPricing(
        array $printData,
        ?TenantPrintSetting $selectedSetting,
        ?TenantPrintOption $selectedOption = null,
        ?int $itemIndex = null
    ): array {
        $printQuantity = $this->normalizeDecimal($printData['print_quantity'] ?? 0);
        $setupStatus = $this->printSetupUnitDistributionService->normalizeStatus(
            $printData['setup_status']
                ?? $printData['cliche_status']
                ?? $selectedOption?->setup_status_default
                ?? null
        );
        $setupType = $this->resolveSetupType($selectedSetting, $selectedOption, $printData);
        $setupTotalAmount = $this->normalizeDecimal($printData['setup_total_amount'] ?? 0);
        $basePrintUnitPrice = array_key_exists('base_print_unit_price', $printData)
            && $printData['base_print_unit_price'] !== null
            && $printData['base_print_unit_price'] !== ''
            ? $this->normalizeDecimal($printData['base_print_unit_price'])
            : $this->normalizeDecimal($printData['print_unit_price'] ?? 0);

        if ($setupTotalAmount < 0) {
            $this->throwItemValidation($itemIndex, 'prints', 'Ara eleman toplam tutarı negatif olamaz.');
        }

        $statusRequiresSetupAmount = $this->printSetupUnitDistributionService->statusRequiresSetupAmount($setupStatus);
        $setupPricingEnabled = filter_var($printData['setup_pricing_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || $statusRequiresSetupAmount
            || $setupTotalAmount > 0;

        if ($setupPricingEnabled && $setupTotalAmount > 0 && $printQuantity <= 0) {
            $this->throwItemValidation($itemIndex, 'prints', 'Ara eleman tutarı girildi ancak baskı miktarı sıfır. Lütfen baskı miktarını kontrol edin.');
        }

        if (! $statusRequiresSetupAmount) {
            $setupPricingEnabled = false;
            $setupTotalAmount = 0.0;
        }

        $distribution = $this->printSetupUnitDistributionService->calculate(
            $basePrintUnitPrice,
            $setupPricingEnabled ? $setupTotalAmount : 0.0,
            $printQuantity
        );

        return [
            'setup_pricing_enabled' => $setupPricingEnabled,
            'setup_type' => $setupType,
            'setup_status' => $setupStatus,
            'setup_total_amount' => $setupPricingEnabled ? $distribution['setup_total_amount'] : null,
            'setup_distribution_quantity' => $setupPricingEnabled ? $distribution['setup_distribution_quantity'] : null,
            'setup_unit_amount' => $setupPricingEnabled ? $distribution['setup_unit_amount'] : null,
            'base_print_unit_price' => $distribution['base_print_unit_price'],
            'print_quantity' => $printQuantity,
            'print_unit_price' => $distribution['final_print_unit_price'],
            'print_total' => $distribution['final_print_total'],
            'cliche_status' => $setupStatus ?? ($printData['cliche_status'] ?? null),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $moduleEnabled = $this->customerQuoteApprovalModuleEnabled($tenant->id);
        $canApproveQuotes = $this->canManageQuoteApprovals($tenant->id);
        $requestedView = (string) $request->query('filter', $request->query('view', 'active'));
        $view = $requestedView;

        if (!in_array($view, ['active', 'converted', 'archived', 'all'], true)) {
            $view = 'active';
        }

        $baseQuery = Order::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('order_family', 'promotion')
            ->where('document_type', 'quote');

        $query = (clone $baseQuery);

        $query = match ($view) {
            'converted' => $query->convertedQuotes(),
            'archived' => $query->archivedQuotes(),
            'all' => $query->quotes(),
            default => $query->activeQuotes(),
        };

        $query->with([
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
                    $innerQuery->convertedQuotes();

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
        $activeStatsQuery = (clone $baseQuery)->activeQuotes();

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $activeStatsQuery)->count(),
            'prepared' => (clone $activeStatsQuery)
                ->where(function ($query) {
                    $query->whereNull('customer_approval_status')
                        ->orWhere('customer_approval_status', Order::CUSTOMER_APPROVAL_NOT_SENT);
                })
                ->count(),
            'waiting' => (clone $activeStatsQuery)
                ->where('customer_approval_status', Order::CUSTOMER_APPROVAL_WAITING)
                ->count(),
            'revision_requested' => (clone $activeStatsQuery)
                ->where('customer_approval_status', Order::CUSTOMER_APPROVAL_REVISION_REQUESTED)
                ->count(),
            'approved' => (clone $activeStatsQuery)
                ->where(function ($query) {
                    $query->where('customer_approval_status', Order::CUSTOMER_APPROVAL_APPROVED)
                        ->orWhere('status', 'approved');
                })
                ->count(),
            'converted' => (clone $baseQuery)
                ->convertedQuotes()
                ->count(),
            'archived' => (clone $baseQuery)
                ->archivedQuotes()
                ->count(),
        ];

        // Get customers for filter dropdown
        $customers = $this->promotionQuoteCustomerQuery($tenant->id)
            ->orderBy('legal_name')
            ->get();

        return view('admin.promotion-quotes.index', [
            'quotes' => $quotes,
            'stats' => $stats,
            'customers' => $customers,
            'canViewFinancialData' => Auth::user()?->canViewFinancialData($tenant->id) ?? false,
            'customerQuoteApprovalEnabled' => $moduleEnabled,
            'canApproveQuotes' => $canApproveQuotes,
            'activeView' => $view,
            'filters' => [
                'view' => $view,
                'filter' => $view,
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
        $deliveryTypeState = $this->tenantDeliveryTypeService->selectionState($tenant->id);
        
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

        $selectedCustomer = $this->resolveSelectedQuoteCustomer(
            $tenant->id,
            filled(old('customer_company_id')) ? (int) old('customer_company_id') : null
        );

        return view('admin.promotion-quotes.create', [
            'customers' => $customers,
            'partnerCompanies' => $partnerCompanies,
            'nextQuoteNumber' => $this->numberGenerationService->getNextNumber($tenant->id, 'quote'),
            'catalogSearchUrl' => route('admin.catalog.search'),
            'customerSearchUrl' => route('admin.promotion-quotes.customer-search'),
            'quickCustomerStoreUrl' => route('admin.promotion-quotes.quick-customer.store'),
            'customerLookup' => $this->buildQuoteCustomerLookup($customers),
            'selectedCustomer' => $selectedCustomer ? $this->formatQuoteCustomerSummary($selectedCustomer) : null,
            'canViewFinancialData' => $canViewFinancialData,
            'tenantPrintSettings' => $this->buildTenantPrintSettingsPayload($tenant->id, $canViewFinancialData),
            'deliveryTypeOptions' => $deliveryTypeState['types'],
            'selectedDeliveryTypeId' => old('delivery_type_id', $deliveryTypeState['selected_id']),
            'legacyDeliveryTypeLabel' => null,
        ]);
    }

    public function customerSearch(Request $request): JsonResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $query = trim((string) $request->string('q'));

        if (mb_strlen($query) < 3) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'query' => $query,
                    'minimum_length' => 3,
                    'message' => 'Müşteri aramak için en az 3 karakter yazın.',
                ],
            ]);
        }

        $customers = $this->promotionQuoteCustomerQuery($tenant->id)
            ->with(['contacts' => fn ($builder) => $builder->orderByDesc('is_primary')->orderBy('name')])
            ->where(function ($builder) use ($query): void {
                foreach ($this->promotionQuoteCustomerSearchVariants($query) as $variant) {
                    $like = '%' . $variant . '%';

                    $builder->orWhere('legal_name', 'like', $like)
                        ->orWhere('short_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('mobile', 'like', $like)
                        ->orWhere('tax_number', 'like', $like)
                        ->orWhereHas('contacts', function ($contactQuery) use ($like): void {
                            $contactQuery
                                ->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like)
                                ->orWhere('phone', 'like', $like)
                                ->orWhere('mobile', 'like', $like);
                        });
                }
            })
            ->orderBy('legal_name')
            ->limit(12)
            ->get();

        return response()->json([
            'data' => $customers->map(fn (Company $company) => $this->formatQuoteCustomerSummary($company))->values()->all(),
            'meta' => [
                'query' => $query,
                'minimum_length' => 3,
                'message' => $customers->isEmpty()
                    ? 'Müşteri bulunamadı. Hızlı müşteri ekleyebilirsiniz.'
                    : null,
            ],
        ]);
    }

    public function quickStoreCustomer(Request $request): JsonResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $validator = Validator::make($request->all(), [
            'legal_name' => ['required', 'string', 'max:255'],
            'tax_number' => ['nullable', 'regex:/^\d{10,11}$/'],
            'identity_type' => ['required', 'in:company,person'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'address_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'legal_name.required' => 'Firma / müşteri adı zorunludur.',
            'tax_number.regex' => 'Vergi No / TC No 10 veya 11 hane olmalıdır.',
            'email.email' => 'Geçerli bir e-posta adresi girin.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Müşteri kaydedilemedi. Alanları kontrol edip tekrar deneyin.',
                'errors' => $validator->errors()->messages(),
            ], 422);
        }

        $validated = $validator->validated();

        $duplicate = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('status', 'active')
            ->where(function ($builder) use ($validated): void {
                $builder->whereRaw('LOWER(legal_name) = ?', [mb_strtolower(trim((string) $validated['legal_name']))]);

                if (filled($validated['tax_number'] ?? null)) {
                    $builder->orWhere('tax_number', trim((string) $validated['tax_number']));
                }
            })
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'Müşteri kaydedilemedi. Alanları kontrol edip tekrar deneyin.',
                'errors' => [
                    'legal_name' => ['Bu müşteri zaten kayıtlı olabilir. Listeden seçebilirsiniz.'],
                ],
            ], 422);
        }

        try {
            $company = DB::transaction(function () use ($tenant, $validated): Company {
                $notes = collect([
                    filled($validated['city'] ?? null) ? 'Şehir: ' . trim((string) $validated['city']) : null,
                    filled($validated['address_note'] ?? null) ? trim((string) $validated['address_note']) : null,
                ])->filter()->implode(' | ');

                $company = Company::query()->create([
                    'tenant_account_id' => $tenant->id,
                    'legal_name' => trim((string) $validated['legal_name']),
                    'short_name' => null,
                    'tax_number' => $this->cleanNullableString($validated['tax_number'] ?? null),
                    'email' => $this->cleanNullableString($validated['email'] ?? null),
                    'phone' => $this->cleanNullableString($validated['phone'] ?? null),
                    'mobile' => $this->cleanNullableString($validated['phone'] ?? null),
                    'status' => 'active',
                    'portal_enabled' => false,
                    'notes' => $this->cleanNullableString($notes),
                ]);

                CompanyRole::query()->create([
                    'tenant_account_id' => $tenant->id,
                    'company_id' => $company->id,
                    'role_key' => 'customer',
                ]);

                if (filled($validated['contact_name'] ?? null) || filled($validated['email'] ?? null) || filled($validated['phone'] ?? null)) {
                    CompanyContact::query()->create([
                        'tenant_account_id' => $tenant->id,
                        'company_id' => $company->id,
                        'name' => trim((string) ($validated['contact_name'] ?? $validated['legal_name'])),
                        'email' => $this->cleanNullableString($validated['email'] ?? null),
                        'phone' => $this->cleanNullableString($validated['phone'] ?? null),
                        'mobile' => $this->cleanNullableString($validated['phone'] ?? null),
                        'is_primary' => true,
                    ]);
                }

                $this->currentAccountSyncService->ensureForCompany($company->fresh('companyRoles'));

                return $company->fresh(['contacts']);
            });
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Müşteri kaydedilemedi. Alanları kontrol edip tekrar deneyin.',
                'errors' => [
                    'general' => ['Müşteri kaydedilemedi. Alanları kontrol edip tekrar deneyin.'],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Müşteri kaydedildi ve teklif formuna seçildi.',
            'data' => $this->formatQuoteCustomerSummary($company),
        ], 201);
    }

    private function promotionQuoteCustomerQuery(int $tenantId)
    {
        return Company::query()
            ->where('tenant_account_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('companyRoles', fn ($query) => $query->where('role_key', 'customer'));
    }

    private function promotionQuoteCustomerSearchVariants(string $query): array
    {
        $normalized = trim($query);
        $variants = [
            $normalized,
            mb_strtolower($normalized),
            Str::ascii($normalized),
            mb_strtolower(Str::ascii($normalized)),
        ];

        return array_values(array_unique(array_filter($variants, fn ($value) => $value !== '')));
    }

    private function buildQuoteCustomerLookup(iterable $customers): array
    {
        $lookup = [];

        foreach ($customers as $customer) {
            if (! $customer instanceof Company) {
                continue;
            }

            $lookup[$customer->id] = $this->formatQuoteCustomerSummary($customer);
        }

        return $lookup;
    }

    private function formatQuoteCustomerSummary(Company $company): array
    {
        $company->loadMissing([
            'contacts' => fn ($builder) => $builder->orderByDesc('is_primary')->orderBy('name'),
        ]);

        $primaryContact = $company->contacts->first();
        $currentAccountId = CurrentAccountLink::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->value('current_account_id');
        $displayName = trim((string) ($company->short_name ?: $company->legal_name));
        $email = $company->email ?: $primaryContact?->email;
        $phone = $company->phone ?: $company->mobile ?: $primaryContact?->phone ?: $primaryContact?->mobile;
        $contactName = $primaryContact?->name;
        $summaryParts = array_values(array_filter([
            $company->legal_name,
            $contactName ? 'Yetkili: ' . $contactName : null,
            $phone ? 'Tel: ' . $phone : null,
            $email ? 'E-posta: ' . $email : null,
            $company->tax_number ? 'VKN/TCKN: ' . $company->tax_number : null,
        ]));

        return [
            'id' => $company->id,
            'display_name' => $displayName,
            'legal_name' => $company->legal_name,
            'email' => $email,
            'phone' => $phone,
            'tax_number' => $company->tax_number,
            'contact_name' => $contactName,
            'current_account_id' => $currentAccountId,
            'label' => $displayName,
            'summary' => implode(' · ', $summaryParts),
        ];
    }

    private function resolveSelectedQuoteCustomer(int $tenantId, ?int $companyId): ?Company
    {
        if (! $companyId) {
            return null;
        }

        return $this->promotionQuoteCustomerQuery($tenantId)
            ->with([
                'contacts' => fn ($builder) => $builder->orderByDesc('is_primary')->orderBy('name'),
            ])
            ->whereKey($companyId)
            ->first();
    }

    private function cleanNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim((string) $value);

        return $clean === '' ? null : $clean;
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
            'items.*.selected_catalog_identity' => 'nullable',
            'items.*.prints' => 'nullable|array',
            'items.*.prints.*.tenant_print_setting_id' => 'nullable|integer',
            'items.*.prints.*.standard_print_type_id' => 'nullable|integer|exists:standard_print_types,id',
            'items.*.prints.*.tenant_print_option_id' => 'nullable|integer',
            'items.*.prints.*.print_type' => 'nullable|string|max:255',
            'items.*.prints.*.print_option' => 'nullable|string|max:255',
            'items.*.prints.*.print_location' => 'nullable|string|max:255',
            'items.*.prints.*.production_type' => 'nullable|string|max:100',
            'items.*.prints.*.subcontractor_company_id' => 'nullable|exists:companies,id',
            'items.*.prints.*.print_color' => 'nullable|string|max:255',
            'items.*.prints.*.print_size' => 'nullable|string|max:255',
            'items.*.prints.*.cliche_status' => 'nullable|string|max:255',
            'items.*.prints.*.setup_pricing_enabled' => 'nullable|boolean',
            'items.*.prints.*.setup_type' => 'nullable|string|max:100',
            'items.*.prints.*.setup_status' => 'nullable|string|max:100',
            'items.*.prints.*.setup_total_amount' => 'nullable|numeric|min:0',
            'items.*.prints.*.setup_distribution_quantity' => 'nullable|numeric|min:0',
            'items.*.prints.*.setup_unit_amount' => 'nullable|numeric|min:0',
            'items.*.prints.*.base_print_unit_price' => 'nullable|numeric|min:0',
            'items.*.prints.*.print_quantity' => 'nullable|numeric|min:0',
            'items.*.prints.*.print_unit_price' => 'nullable|numeric|min:0',
            'items.*.prints.*.note' => 'nullable|string',
            'items.*.prints.*.production_note' => 'nullable|string',
            'delivery_type_id' => 'nullable|integer',
            'delivery_type' => 'nullable|string|max:100',
            'show_print_price_details_to_customer' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);
        $selectedCustomer = $this->resolveSelectedQuoteCustomer($tenant->id, (int) $validated['customer_company_id']);

        if (! $selectedCustomer) {
            throw ValidationException::withMessages([
                'customer_company_id' => ['Seçilen müşteri geçerli değil.'],
            ]);
        }

        $deliveryTypePayload = $this->resolveDeliveryTypePayload($tenant->id, $validated);
        $showPrintPriceDetailsToCustomer = $this->resolveShowPrintPriceDetailsToCustomer($validated);

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
                'delivery_type' => $deliveryTypePayload['delivery_type'],
                'delivery_type_id' => $deliveryTypePayload['delivery_type_id'],
                'show_print_price_details_to_customer' => $showPrintPriceDetailsToCustomer,
                'notes' => $validated['notes'] ?? null,
                'currency' => $validated['currency'],
                'created_by' => Auth::id(),
            ]);

            // Create order items
            $netSubtotal = 0;
            $vatTotal = 0;
            $grossTotal = 0;

            foreach ($validated['items'] as $itemIndex => $itemData) {
                $itemData = $this->normalizeQuoteItemInput($itemData, $invoiceStatus);
                $unitPricePayload = $this->resolveUnitPricePayload($itemData);
                $itemData['list_price'] = $unitPricePayload['list_price'];
                $itemData['discount_rate'] = $unitPricePayload['discount_rate'];
                $itemData['unit_price'] = $unitPricePayload['unit_price'];
                $unitPrice = $unitPricePayload['unit_price'];

                $catalogPayload = $this->resolveCatalogItemPayload($tenant->id, $itemData, $itemIndex);
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
                        $selectedSetting = $this->findTenantPrintSettingForSave(
                            $tenant->id,
                            !empty($printData['tenant_print_setting_id']) ? (int) $printData['tenant_print_setting_id'] : null
                        );
                        $selectedOption = $this->findTenantPrintOptionForSave(
                            $tenant->id,
                            $selectedSetting,
                            !empty($printData['tenant_print_option_id']) ? (int) $printData['tenant_print_option_id'] : null,
                            $printData['print_option'] ?? null
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

                        $printPricing = $this->normalizePrintSetupPricing($printData, $selectedSetting, $selectedOption, $itemIndex);
                        $currentPrintLineTotal = $printPricing['print_total'];

                        OrderItemPrint::create([
                            'tenant_account_id' => $tenant->id,
                            'order_id' => $quote->id,
                            'order_item_id' => $orderItem->id,
                            'tenant_print_setting_id' => $selectedSetting?->id,
                            'standard_print_type_id' => $selectedSetting?->standard_print_type_id ?? ($printData['standard_print_type_id'] ?? null),
                            'tenant_print_option_id' => $selectedOption?->id,
                            'print_type' => $selectedSetting ? $this->normalizePrintTypeForSetting($selectedSetting) : ($printData['print_type'] ?? null),
                            'print_option' => $selectedOption?->displayName() ?? ($printData['print_option'] ?? null),
                            'print_location' => $printData['print_location'] ?? null,
                            'production_type' => $this->normalizeLegacyProductionType($printData['production_type'] ?? null, $selectedSetting?->production_mode),
                            'subcontractor_company_id' => $resolvedSubcontractorId,
                            'print_color' => $printData['print_color'] ?? null,
                            'print_size' => $printData['print_size'] ?? null,
                            'cliche_status' => $printPricing['cliche_status'],
                            'setup_pricing_enabled' => $printPricing['setup_pricing_enabled'],
                            'setup_type' => $printPricing['setup_type'],
                            'setup_status' => $printPricing['setup_status'],
                            'setup_total_amount' => $printPricing['setup_total_amount'],
                            'setup_distribution_quantity' => $printPricing['setup_distribution_quantity'],
                            'setup_unit_amount' => $printPricing['setup_unit_amount'],
                            'base_print_unit_price' => $printPricing['base_print_unit_price'],
                            'print_quantity' => $printPricing['print_quantity'] ?? null,
                            'print_unit_price' => $printPricing['print_unit_price'] ?? null,
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

        } catch (ValidationException $e) {
            DB::rollback();
            throw $e;
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
            'sourceOrder:id,tenant_account_id,document_number',
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
        $normalizedRecipientPhone = $recipientPhone
            ? $this->tenantWhatsappLinkService->toWhatsappDialString($recipientPhone)
            : null;
        $sendNotificationSummary = $this->buildSendNotificationSummary($quote);
        $whatsappFeatureEnabled = $this->whatsappLinksFeatureEnabled($tenant->id)
            && $this->tenantNotificationSettingsService->isWhatsappEnabled($tenant);
        $whatsappAvailable = $whatsappFeatureEnabled && $publicQuoteApprovalEnabled;
        $whatsappReady = $whatsappAvailable && filled($normalizedRecipientPhone) && filled($approvalHelperUrl);
        $quotePdfAvailable = true;
        $notificationLogRows = $this->buildNotificationLogRows($quote);
        $sourceOrderContext = $this->buildSourceOrderContext($quote, $tenant);
        $revisionCompareUrl = $quote->isRevisionDraft()
            && $quote->source_order_id
            && $this->canAccessRevisionCompare($tenant->id)
                ? route('admin.promotion-quotes.revision-compare', $quote)
                : null;

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
            'recipientPhoneDisplay' => $this->tenantWhatsappLinkService->formatTurkishPhoneForDisplay($recipientPhone),
            'sendNotificationSummary' => $sendNotificationSummary,
            'notificationLogRows' => $notificationLogRows,
            'whatsappAvailable' => $whatsappAvailable,
            'whatsappReady' => $whatsappReady,
            'quotePdfAvailable' => $quotePdfAvailable,
            'sourceOrderContext' => $sourceOrderContext,
            'revisionCompareUrl' => $revisionCompareUrl,
        ]);
    }

    public function revisionCompare(Request $request, Order $quote)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if ($quote->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        if (! $quote->isPromotion() || ! $quote->isQuote()) {
            abort(404);
        }

        if (! $quote->isRevisionDraft() || ! $quote->source_order_id) {
            abort(404);
        }

        if (! $this->canAccessRevisionCompare($tenant->id)) {
            abort(403, 'Revizyon karşılaştırma ekranını açma yetkiniz yok.');
        }

        $relations = [
            'customer',
            'items.prints',
            'sourceOrder.customer',
            'sourceOrder.items.procurement',
            'sourceOrder.items.delivery',
            'sourceOrder.items.prints.production',
            'sourceOrder.procurements',
            'sourceOrder.printProductions',
            'sourceOrder.deliveries',
            'sourceOrder.payments',
        ];

        if ($this->revisionApplyInfrastructureReady()) {
            $relations[] = 'orderRevision';
        }

        $quote->load($relations);

        $comparison = $this->orderRevisionComparisonService->build($quote);
        $applySummary = $this->buildRevisionApplySummary($quote, $comparison);

        return view('admin.promotion-quotes.revision-compare', array_merge($comparison, [
            'quote' => $quote,
            'sourceOrderContext' => $this->buildSourceOrderContext($quote, $tenant),
            'applySummary' => $applySummary,
        ]));
    }

    public function applyRevision(
        Request $request,
        Order $quote,
        OrderRevisionRecordService $orderRevisionRecordService,
        OrderRevisionApplyService $orderRevisionApplyService,
    ): RedirectResponse {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || (int) $quote->tenant_account_id !== (int) $tenant->id) {
            abort(403, 'Bu teklife erişim yetkiniz yok.');
        }

        if (! $quote->isPromotion() || ! $quote->isQuote() || ! $quote->isRevisionDraft() || ! $quote->source_order_id) {
            abort(404);
        }

        if (! $this->canAccessRevisionCompare($tenant->id)) {
            abort(403, 'Revizyon uygulama yetkiniz yok.');
        }

        if (! $this->revisionApplyInfrastructureReady()) {
            return redirect()
                ->route('admin.promotion-quotes.revision-compare', $quote)
                ->with('error', 'Revizyon apply altyapı tabloları bu ortamda hazır değil. Migration çalıştırılmadan apply smoke tamamlanamaz.');
        }

        $quote->load([
            'customer',
            'items.prints',
            'orderRevision',
            'sourceOrder.customer',
            'sourceOrder.items.procurement',
            'sourceOrder.items.delivery',
            'sourceOrder.items.prints.production',
            'sourceOrder.procurements',
            'sourceOrder.printProductions',
            'sourceOrder.deliveries',
            'sourceOrder.payments',
        ]);

        try {
            $comparison = $this->orderRevisionComparisonService->build($quote);
            $revision = $orderRevisionRecordService->createOrUpdateFromComparison(
                $quote->sourceOrder,
                $quote,
                $comparison,
                $request->user()
            );

            $appliedRevision = $orderRevisionApplyService->apply($revision, $request->user());

            $successMessage = $appliedRevision->status === \App\Models\OrderRevision::STATUS_PARTIALLY_APPLIED
                ? 'Revizyon kısmi uygulandı. Kilitli alanlar atlandı, manuel kontrol gereken satırlar korundu.'
                : 'Revizyon uygulandı. Güvenli ticari alanlar siparişe işlendi.';

            return redirect()
                ->route('admin.orders.show', $quote->sourceOrder)
                ->with('success', $successMessage);
        } catch (DomainException $exception) {
            return redirect()
                ->route('admin.promotion-quotes.revision-compare', $quote)
                ->with('error', $exception->getMessage());
        }
    }

    private function buildRevisionApplySummary(Order $quote, array $comparison): array
    {
        if (! $this->revisionApplyInfrastructureReady()) {
            return [
                'applicable_count' => 0,
                'locked_count' => 0,
                'manual_count' => 0,
                'no_change_count' => 0,
                'has_finance_note' => false,
                'finance_note' => null,
                'already_applied' => false,
                'button_enabled' => false,
                'button_disabled_reason' => 'Revizyon apply altyapı tabloları bu ortamda hazır değil. Migration çalıştırılmadan apply smoke tamamlanamaz.',
            ];
        }

        $record = $quote->orderRevision;
        $decisionRows = collect($comparison['decisionMatrix'] ?? []);
        $applicableRows = $decisionRows->filter(fn (array $row) => in_array($row['decision'] ?? null, [
            'Uygulanabilir',
            'Kontrollü Uygulanabilir',
        ], true));
        $financeRow = $decisionRows->first(fn (array $row) => ($row['label'] ?? null) === 'Fiyat');
        $alreadyApplied = (bool) ($record?->applied_at)
            || in_array($record?->status, [
                \App\Models\OrderRevision::STATUS_APPLIED,
                \App\Models\OrderRevision::STATUS_PARTIALLY_APPLIED,
            ], true);

        return [
            'applicable_count' => $applicableRows->count(),
            'locked_count' => $decisionRows->where('decision', 'Kilitli')->count(),
            'manual_count' => $decisionRows->where('decision', 'Manuel Kontrol Gerekli')->count(),
            'no_change_count' => $decisionRows->where('decision', 'Değişiklik Yok')->count(),
            'has_finance_note' => ($financeRow['decision'] ?? null) === 'Kontrollü Uygulanabilir',
            'finance_note' => ($financeRow['decision'] ?? null) === 'Kontrollü Uygulanabilir'
                ? 'Finans kontrolü gerekiyor. Fiyat farkı cari hareket veya tahsilat üretmeden yalnız ticari görünüme işlenir.'
                : null,
            'already_applied' => $alreadyApplied,
            'button_enabled' => ! $alreadyApplied && $applicableRows->isNotEmpty(),
            'button_disabled_reason' => $alreadyApplied
                ? 'Bu revizyon daha önce uygulanmış.'
                : ($applicableRows->isEmpty()
                    ? 'Bu revizyonda otomatik uygulanabilir bir alan bulunamadı.'
                    : null),
        ];
    }

    private function revisionApplyInfrastructureReady(): bool
    {
        return Schema::hasTable('order_revisions') && Schema::hasTable('order_revision_changes');
    }

    private function maskEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        if ($email === '' || ! str_contains($email, '@')) {
            return $email !== '' ? $email : null;
        }

        [$local, $domain] = explode('@', $email, 2);
        $local = Str::of($local)->substr(0, 2)->append('***')->value();

        return $local . '@' . $domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($phone === '') {
            return null;
        }

        if (strlen($phone) <= 4) {
            return '***' . $phone;
        }

        return substr($phone, 0, 3) . ' *** ** ' . substr($phone, -2);
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

        if (! $this->tenantWhatsappLinkService->toWhatsappDialString($recipientPhone)) {
            return back()->withErrors(['error' => 'WhatsApp linki oluşturulamadı. Müşteri WhatsApp/telefon numarası bulunamadı.']);
        }

        $publicUrl = route('public.quotes.approval.show', ['token' => $latestApprovalRequest->token]);
        $customerName = $latestApprovalRequest->contact_name ?: ($quote->customer?->legal_name ?: 'Müşterimiz');

        try {
            $result = $this->tenantWhatsappLinkService->createManualLink($tenant, [
                'customer_name' => $customerName,
                'recipient_phone' => $recipientPhone,
                'message_type' => TenantWhatsappLinkService::TYPE_QUOTE_LINK,
                'public_link' => $publicUrl,
                'quote_number' => (string) ($quote->document_number ?: ''),
                'related_type' => $quote->getMorphClass(),
                'related_id' => $quote->id,
            ], $request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }

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

        $sentChannel = 'manual';
        $result = null;

        try {
            $recipientData = $this->normalizeSendRecipientData($request, $quote->loadMissing('customer'));
            $sentChannel = (string) ($recipientData['sent_channel'] ?? 'manual');

            if ($sentChannel === 'manual' && ! filled($recipientData['contact_email'] ?? null)) {
                return back()->withErrors(['error' => 'Müşteri e-posta adresi olmadığı için teklif maili gönderilemedi.']);
            }

            if ($sentChannel === 'email') {
                $this->quoteApprovalService->sendToCustomer($quote, array_merge($recipientData, [
                    'force_email_preview' => true,
                ]), Auth::user());
            } elseif ($sentChannel === 'whatsapp_link') {
                if (! filled($recipientData['contact_phone'] ?? null)) {
                    return back()->withErrors(['error' => 'WhatsApp linki oluşturulamadı. Müşteri WhatsApp/telefon numarası bulunamadı.']);
                }

                if (! $this->tenantWhatsappLinkService->toWhatsappDialString($recipientData['contact_phone'])) {
                    return back()->withErrors(['error' => 'WhatsApp linki oluşturulamadı. Müşteri WhatsApp/telefon numarası bulunamadı.']);
                }

                $approvalRequest = $this->quoteApprovalService->sendToCustomer($quote, array_merge($recipientData, [
                    'skip_email_send' => true,
                    'skip_whatsapp_dispatch' => true,
                ]), Auth::user());

                $result = $this->tenantWhatsappLinkService->createManualLink($tenant, [
                    'customer_name' => $recipientData['contact_name'] ?? ($quote->customer?->legal_name ?: 'Müşterimiz'),
                    'recipient_phone' => $recipientData['contact_phone'],
                    'message_type' => TenantWhatsappLinkService::TYPE_QUOTE_LINK,
                    'public_link' => route('public.quotes.approval.show', ['token' => $approvalRequest->token]),
                    'quote_number' => (string) ($quote->document_number ?: ''),
                    'related_type' => $quote->getMorphClass(),
                    'related_id' => $quote->id,
                ], $request->user());
            } else {
                $this->quoteApprovalService->sendToCustomer($quote, $recipientData, Auth::user());
            }
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }

        $quote = $quote->fresh(['latestQuoteApprovalRequest']);

        $redirect = redirect()
            ->route('admin.promotion-quotes.show', $quote)
            ->with('success', $this->buildSendSuccessMessage($quote, $sentChannel));

        if ($sentChannel === 'whatsapp_link' && is_array($result)) {
            $redirect->with('whatsapp_result', [
                'url' => $result['url'],
                'phone' => $result['phone'],
            ]);
        }

        return $redirect;
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

        $quote->load(['customer', 'sourceOrder:id,tenant_account_id,document_number', 'items.prints.tenantPrintSetting.standardPrintType']);

        // Get active customers
        $customers = $this->promotionQuoteCustomerQuery($tenant->id)
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
        $linkedOptionIds = $quote->items
            ->flatMap(fn ($item) => $item->prints->pluck('tenant_print_option_id'))
            ->filter()
            ->values()
            ->all();
        $deliveryTypeState = $this->tenantDeliveryTypeService->selectionState(
            $tenant->id,
            $quote->delivery_type_id,
            $quote->delivery_type
        );
        $sourceOrderContext = $this->buildSourceOrderContext($quote, $tenant);
        $revisionCompareUrl = $quote->isRevisionDraft()
            && $quote->source_order_id
            && $this->canAccessRevisionCompare($tenant->id)
                ? route('admin.promotion-quotes.revision-compare', $quote)
                : null;

        return view('admin.promotion-quotes.edit', [
            'quote' => $quote,
            'customers' => $customers,
            'partnerCompanies' => $partnerCompanies,
            'catalogSearchUrl' => route('admin.catalog.search'),
            'customerSearchUrl' => route('admin.promotion-quotes.customer-search'),
            'quickCustomerStoreUrl' => route('admin.promotion-quotes.quick-customer.store'),
            'customerLookup' => $this->buildQuoteCustomerLookup($customers),
            'selectedCustomer' => $quote->customer ? $this->formatQuoteCustomerSummary($quote->customer) : null,
            'canViewFinancialData' => $canViewFinancialData,
            'tenantPrintSettings' => $this->buildTenantPrintSettingsPayload($tenant->id, $canViewFinancialData, $linkedSettingIds, $linkedOptionIds),
            'deliveryTypeOptions' => $deliveryTypeState['types'],
            'selectedDeliveryTypeId' => old('delivery_type_id', $deliveryTypeState['selected_id']),
            'legacyDeliveryTypeLabel' => $deliveryTypeState['legacy_label'],
            'sourceOrderContext' => $sourceOrderContext,
            'revisionCompareUrl' => $revisionCompareUrl,
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
        $allowedInactiveOptionIds = $quote->items
            ->flatMap(fn ($item) => $item->prints->pluck('tenant_print_option_id'))
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
            'items.*.selected_catalog_identity' => 'nullable',
            'items.*.prints' => 'nullable|array',
            'items.*.prints.*.tenant_print_setting_id' => 'nullable|integer',
            'items.*.prints.*.standard_print_type_id' => 'nullable|integer|exists:standard_print_types,id',
            'items.*.prints.*.tenant_print_option_id' => 'nullable|integer',
            'items.*.prints.*.print_type' => 'nullable|string|max:255',
            'items.*.prints.*.print_option' => 'nullable|string|max:255',
            'items.*.prints.*.print_location' => 'nullable|string|max:255',
            'items.*.prints.*.production_type' => 'nullable|string|max:100',
            'items.*.prints.*.subcontractor_company_id' => 'nullable|exists:companies,id',
            'items.*.prints.*.print_color' => 'nullable|string|max:255',
            'items.*.prints.*.print_size' => 'nullable|string|max:255',
            'items.*.prints.*.cliche_status' => 'nullable|string|max:255',
            'items.*.prints.*.setup_pricing_enabled' => 'nullable|boolean',
            'items.*.prints.*.setup_type' => 'nullable|string|max:100',
            'items.*.prints.*.setup_status' => 'nullable|string|max:100',
            'items.*.prints.*.setup_total_amount' => 'nullable|numeric|min:0',
            'items.*.prints.*.setup_distribution_quantity' => 'nullable|numeric|min:0',
            'items.*.prints.*.setup_unit_amount' => 'nullable|numeric|min:0',
            'items.*.prints.*.base_print_unit_price' => 'nullable|numeric|min:0',
            'items.*.prints.*.print_quantity' => 'nullable|numeric|min:0',
            'items.*.prints.*.print_unit_price' => 'nullable|numeric|min:0',
            'items.*.prints.*.note' => 'nullable|string',
            'items.*.prints.*.production_note' => 'nullable|string',
            'delivery_type_id' => 'nullable|integer',
            'delivery_type' => 'nullable|string|max:100',
            'show_print_price_details_to_customer' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);
        $selectedCustomer = $this->resolveSelectedQuoteCustomer($tenant->id, (int) $validated['customer_company_id']);

        if (! $selectedCustomer) {
            throw ValidationException::withMessages([
                'customer_company_id' => ['Seçilen müşteri geçerli değil.'],
            ]);
        }

        $deliveryTypePayload = $this->resolveDeliveryTypePayload(
            $tenant->id,
            $validated,
            $quote->delivery_type_id
        );
        $showPrintPriceDetailsToCustomer = $this->resolveShowPrintPriceDetailsToCustomer($validated, $quote);

        DB::beginTransaction();
        try {
            $invoiceStatus = $this->resolveInvoiceStatus($validated['invoice_status'] ?? null);

            // Update quote
            $quote->update([
                'customer_company_id' => $validated['customer_company_id'],
                'quote_date' => $validated['quote_date'],
                'valid_until' => $validated['valid_until'] ?? null,
                'invoice_status' => $invoiceStatus,
                'delivery_type' => $deliveryTypePayload['delivery_type'],
                'delivery_type_id' => $deliveryTypePayload['delivery_type_id'],
                'show_print_price_details_to_customer' => $showPrintPriceDetailsToCustomer,
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

            foreach ($validated['items'] as $itemIndex => $itemData) {
                $itemData = $this->normalizeQuoteItemInput($itemData, $invoiceStatus);
                $unitPricePayload = $this->resolveUnitPricePayload($itemData);
                $itemData['list_price'] = $unitPricePayload['list_price'];
                $itemData['discount_rate'] = $unitPricePayload['discount_rate'];
                $itemData['unit_price'] = $unitPricePayload['unit_price'];
                $unitPrice = $unitPricePayload['unit_price'];

                $catalogPayload = $this->resolveCatalogItemPayload($tenant->id, $itemData, $itemIndex);
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
                        $selectedSetting = $this->findTenantPrintSettingForSave(
                            $tenant->id,
                            !empty($printData['tenant_print_setting_id']) ? (int) $printData['tenant_print_setting_id'] : null,
                            $allowedInactiveSettingIds
                        );
                        $selectedOption = $this->findTenantPrintOptionForSave(
                            $tenant->id,
                            $selectedSetting,
                            !empty($printData['tenant_print_option_id']) ? (int) $printData['tenant_print_option_id'] : null,
                            $printData['print_option'] ?? null,
                            $allowedInactiveOptionIds
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

                        $printPricing = $this->normalizePrintSetupPricing($printData, $selectedSetting, $selectedOption, $itemIndex);
                        $currentPrintLineTotal = $printPricing['print_total'];

                        OrderItemPrint::create([
                            'tenant_account_id' => $tenant->id,
                            'order_id' => $quote->id,
                            'order_item_id' => $orderItem->id,
                            'tenant_print_setting_id' => $selectedSetting?->id,
                            'standard_print_type_id' => $selectedSetting?->standard_print_type_id ?? ($printData['standard_print_type_id'] ?? null),
                            'tenant_print_option_id' => $selectedOption?->id,
                            'print_type' => $selectedSetting ? $this->normalizePrintTypeForSetting($selectedSetting) : ($printData['print_type'] ?? null),
                            'print_option' => $selectedOption?->displayName() ?? ($printData['print_option'] ?? null),
                            'print_location' => $printData['print_location'] ?? null,
                            'production_type' => $this->normalizeLegacyProductionType($printData['production_type'] ?? null, $selectedSetting?->production_mode),
                            'subcontractor_company_id' => $resolvedSubcontractorId,
                            'print_color' => $printData['print_color'] ?? null,
                            'print_size' => $printData['print_size'] ?? null,
                            'cliche_status' => $printPricing['cliche_status'],
                            'setup_pricing_enabled' => $printPricing['setup_pricing_enabled'],
                            'setup_type' => $printPricing['setup_type'],
                            'setup_status' => $printPricing['setup_status'],
                            'setup_total_amount' => $printPricing['setup_total_amount'],
                            'setup_distribution_quantity' => $printPricing['setup_distribution_quantity'],
                            'setup_unit_amount' => $printPricing['setup_unit_amount'],
                            'base_print_unit_price' => $printPricing['base_print_unit_price'],
                            'print_quantity' => $printPricing['print_quantity'] ?? null,
                            'print_unit_price' => $printPricing['print_unit_price'] ?? null,
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

        } catch (ValidationException $e) {
            DB::rollback();
            throw $e;
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

    private function resolveCatalogItemPayload(int $tenantId, array $itemData, ?int $itemIndex = null): array
    {
        $catalogProduct = null;
        $catalogVariant = null;
        $selectedCatalogIdentity = $this->selectedCatalogIdentity($itemData);
        $productSnapshot = $this->decodeJsonField($itemData['product_snapshot'] ?? null);
        $priceSnapshot = $this->decodeJsonField($itemData['price_snapshot'] ?? null) ?? [];
        $stockSnapshot = $this->decodeJsonField($itemData['stock_snapshot'] ?? null);
        $hasCatalogIdentity = filled($itemData['tenant_catalog_product_id'] ?? null)
            || filled($itemData['tenant_catalog_product_variant_id'] ?? null)
            || filled($itemData['standard_product_id'] ?? null)
            || filled(data_get($selectedCatalogIdentity, 'tenant_catalog_product_id'))
            || filled(data_get($selectedCatalogIdentity, 'tenant_catalog_product_variant_id'));

        if (($itemData['product_snapshot'] ?? null) !== null && $productSnapshot === null) {
            $this->throwItemValidation($itemIndex, 'product_snapshot', 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.');
        }

        if (($itemData['price_snapshot'] ?? null) !== null && $priceSnapshot === null) {
            $this->throwItemValidation($itemIndex, 'price_snapshot', 'Ürün fiyat özeti okunamadı. Satırı yeniden seçip tekrar deneyin.');
        }

        if (($itemData['stock_snapshot'] ?? null) !== null && $stockSnapshot === null) {
            $this->throwItemValidation($itemIndex, 'stock_snapshot', 'Uyarılı ürün seçildi ancak teklif satırı eksik veri taşıyor. Lütfen satırı yeniden seçin veya manuel ürün olarak kaydedin.');
        }

        $priceSnapshot['vat_mode'] = $this->resolveQuoteVatMode($itemData['invoice_status'] ?? data_get($priceSnapshot, 'invoice_status'));
        $priceSnapshot['invoice_status'] = $this->resolveInvoiceStatus($itemData['invoice_status'] ?? data_get($priceSnapshot, 'invoice_status'));

        $catalogProductId = $itemData['tenant_catalog_product_id'] ?? data_get($selectedCatalogIdentity, 'tenant_catalog_product_id') ?? data_get($productSnapshot, 'tenant_catalog_product_id');
        if (!empty($catalogProductId)) {
            $catalogProduct = TenantCatalogProduct::query()
                ->where('tenant_account_id', $tenantId)
                ->find($catalogProductId);

            if (!$catalogProduct) {
                abort(403, 'Seçilen katalog ürünü bu tenant için geçerli değil.');
            }
        }

        $catalogVariantId = $itemData['tenant_catalog_product_variant_id']
            ?? data_get($selectedCatalogIdentity, 'tenant_catalog_product_variant_id')
            ?? data_get($productSnapshot, 'tenant_catalog_product_variant_id');

        if (!empty($catalogVariantId)) {
            $catalogVariant = TenantCatalogProductVariant::query()
                ->where('tenant_account_id', $tenantId)
                ->find($catalogVariantId);

            if (!$catalogVariant) {
                abort(403, 'Seçilen katalog varyasyonu bu tenant için geçerli değil.');
            }

            if ($catalogProduct && $catalogVariant->tenant_catalog_product_id !== $catalogProduct->id) {
                $this->throwItemValidation($itemIndex, 'tenant_catalog_product_variant_id', 'Seçilen varyasyon ürün ile eşleşmiyor. Lütfen satırı yeniden seçin.');
            }

            $catalogProduct ??= $catalogVariant->catalogProduct;
        }

        if ($hasCatalogIdentity && !$catalogProduct && !$catalogVariant) {
            $this->throwItemValidation($itemIndex, 'product_snapshot', 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.');
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
        $sellableTruth = $this->sellableTruthService->resolve($catalogProduct, $catalogVariant);

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
            'visible_stock_quantity' => (float) ($sellableTruth['effective_stock'] ?? 0),
            'is_warning_sellable' => count($this->resolveWarningBadges($catalogProduct, $catalogVariant)) > 0,
            'warning_tone' => in_array('Kırmızı Ürün', $this->resolveWarningBadges($catalogProduct, $catalogVariant), true) ? 'red' : 'amber',
            'warning_summary' => implode(' • ', array_slice($this->resolveWarningBadges($catalogProduct, $catalogVariant), 0, 3)),
            'source_summary' => $catalogVariant?->source_summary ?: $catalogProduct->source_summary,
        ];

        $priceSnapshot ??= [
            'display_price' => (float) ($sellableTruth['effective_price'] ?? 0),
            'list_price' => (float) (data_get($catalogVariant?->meta, 'price_snapshot.list_price') ?? data_get($catalogProduct->meta, 'price_snapshot.list_price') ?? $catalogVariant?->display_price ?? $catalogProduct->display_price ?? 0),
            'currency' => $sellableTruth['effective_currency'] ?? $catalogVariant?->currency ?? $catalogProduct->currency ?? 'TL',
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
            'visible_stock_quantity' => (float) ($sellableTruth['effective_stock'] ?? 0),
            'safe_stock_quantity' => (int) ($catalogVariant?->safe_stock_quantity ?? $catalogProduct->safe_stock_quantity ?? 0),
            'local_stock_priority' => (bool) ($catalogProduct->local_stock_priority ?? true),
            'stock_status' => (float) ($sellableTruth['effective_stock'] ?? 0) > 0 ? 'available' : 'out_of_stock',
            'warning_flag' => (bool) ($catalogProduct->standardProduct?->warning_flag ?? false),
        ];

        if ($hasCatalogIdentity && blank($productSnapshot['product_name'] ?? null)) {
            $this->throwItemValidation($itemIndex, 'product_snapshot', 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.');
        }

        if ($hasCatalogIdentity && !array_key_exists('list_price', $priceSnapshot) && !array_key_exists('display_price', $priceSnapshot)) {
            $this->throwItemValidation($itemIndex, 'price_snapshot', 'Ürün fiyat özeti okunamadı. Satırı yeniden seçip tekrar deneyin.');
        }

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
        $supplierName = data_get($catalogVariant?->source_summary, 'supplier_name')
            ?: data_get($catalogProduct->source_summary, '0.supplier_name');
        $effectiveStock = $this->resolveEffectiveStock(
            (float) ($catalogVariant?->local_stock_quantity ?? $catalogProduct->local_stock_quantity ?? 0),
            (float) ($catalogVariant?->supplier_stock_quantity ?? $catalogProduct->supplier_stock_quantity ?? 0),
            (float) ($catalogVariant?->stock_quantity ?? $catalogProduct->total_stock_quantity ?? 0),
            (bool) ($catalogProduct->local_stock_priority ?? true)
        );

        $snapshot = [
            'net_price_warning' => (bool) (data_get($variantMeta, 'net_price_warning') ?? data_get($productMeta, 'net_price_warning', false)),
            'pricing_policy_type' => data_get($variantMeta, 'pricing_policy_type') ?? data_get($productMeta, 'pricing_policy_type'),
            'supplier_warning_flag' => (bool) (data_get($variantMeta, 'supplier_warning_flag') ?? data_get($productMeta, 'supplier_warning_flag', false)),
            'supplier_warning_type' => data_get($variantMeta, 'supplier_warning_type') ?? data_get($productMeta, 'supplier_warning_type'),
        ];
        $badges = array_merge($badges, $this->supplierWarningLabelService->supplierSpecificBadges($supplierName, $snapshot));
        $messages = array_merge($messages, $this->supplierWarningLabelService->supplierSpecificMessages($supplierName, $snapshot));

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
            $badges[] = 'Kategori eÅŸleÅŸmemiÅŸ';
            $badges[] = 'Kategori uyarÄ±sÄ±';
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

