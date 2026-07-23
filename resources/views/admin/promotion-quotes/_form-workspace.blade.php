@php
    $formMethod = $formMethod ?? 'POST';
    $formAction = $formAction ?? '#';
    $submitLabel = $submitLabel ?? 'Kaydet';
    $cancelUrl = $cancelUrl ?? route('admin.promotion-quotes.index');
    $canonicalQuoteFormId = 'quote-form';
    $currencyRefreshFormId = isset($quote) ? 'quote-currency-refresh-form' : null;
    $currencyAcknowledgeFormId = isset($quote) ? 'quote-currency-acknowledge-form' : null;
    $quoteCurrency = $quoteCurrency ?? [
        'access' => ['multi_currency_enabled' => false, 'can_view_currency_details' => false],
        'options' => [['value' => 'TRY', 'label' => 'TL']],
        'document_currency' => 'TRY',
        'document_currency_label' => 'TL',
        'status_label' => 'Kur gerekmiyor',
        'status_visible' => false,
    ];
    $currency = old('currency', $quoteCurrency['document_currency'] ?? ($quote->currency ?? 'TRY'));
    $currencyDisplay = $currency === 'TRY' ? 'TL' : $currency;
    $quoteDateValue = old('quote_date', isset($quote) ? optional($quote->quote_date ?? $quote->created_at)->format('Y-m-d') : now()->format('Y-m-d'));
    $deliveryDateValue = old('valid_until', isset($quote) ? optional($quote->valid_until)->format('Y-m-d') : now()->addDays(7)->format('Y-m-d'));
    $invoiceStatusValue = old('invoice_status', $quote->invoice_status ?? 'fis');
    $generalError = null;
    $fieldErrors = collect();
    $rowErrorSummary = [];
    $itemRowErrors = [];
    $printRowErrors = [];

    if ($errors->any()) {
        $generalError = $errors->first('error');

        foreach ($errors->getMessages() as $path => $messages) {
            if ($path === 'error') {
                continue;
            }

            $message = collect($messages)->filter()->first();
            if (! filled($message)) {
                continue;
            }

            if (preg_match('/^items\.(\d+)\.prints\.(\d+)\./', (string) $path, $matches)) {
                $itemIndex = (int) $matches[1];
                $printIndex = (int) $matches[2];
                $printLabel = sprintf('Ürün %d / Baskı %d%s', $itemIndex + 1, $itemIndex + 1, chr(97 + $printIndex));
                $printRowErrors["{$itemIndex}.{$printIndex}"] = ['path' => $path, 'message' => $message];
                $rowErrorSummary[] = ['path' => $path, 'label' => $printLabel, 'message' => $message];
                continue;
            }

            if (preg_match('/^items\.(\d+)(?:\.|$)/', (string) $path, $matches)) {
                $itemIndex = (int) $matches[1];
                $itemLabel = sprintf('Ürün %d', $itemIndex + 1);
                $itemRowErrors[$itemIndex] = ['path' => $path, 'message' => $message];
                $rowErrorSummary[] = ['path' => $path, 'label' => $itemLabel, 'message' => $message];
                continue;
            }

            $fieldErrors->push($message);
        }

        $fieldErrors = $fieldErrors->values();
    }

    $initialItems = collect($initialItems ?? [[]])->map(function ($item, $itemIndex) use ($itemRowErrors, $printRowErrors) {
        $item['_row_error'] = $itemRowErrors[$itemIndex]['message'] ?? ($item['_row_error'] ?? '');
        $item['_error_path'] = $itemRowErrors[$itemIndex]['path'] ?? ($item['_error_path'] ?? null);
        $item['prints'] = collect($item['prints'] ?? [])->map(function ($print, $printIndex) use ($itemIndex, $printRowErrors) {
            $printError = $printRowErrors["{$itemIndex}.{$printIndex}"] ?? null;
            $print['_row_error'] = $printError['message'] ?? ($print['_row_error'] ?? '');
            $print['_error_path'] = $printError['path'] ?? ($print['_error_path'] ?? null);

            return $print;
        })->values()->all();

        return $item;
    })->values()->all();
    $quoteStatusLabel = old('quote_status_label', isset($quote) ? $quote->quoteDisplayStatusLabel() : 'Teklif');
    $showInitialVatSummary = $invoiceStatusValue === 'fatura';
    $quotePrintDebug = config('app.debug') && request()->boolean('quote_print_debug');
    $legacyPrintTypeOptions = ['UV Baskı', 'Serigrafi', 'Tampon Baskı', 'Lazer', 'DTF', 'Sublimasyon', 'Dijital Baskı', 'Transfer Baskı', 'Nakış', 'Etiket / Sticker', 'Sıcak Baskı', 'Diğer'];
    $printOptionMap = [
        'UV Baskı' => ['Tek taraf baskılı', 'Çift taraf baskılı', 'Tam yüzey UV', 'Logo UV', 'Çok renk UV'],
        'Lazer' => ['Logo lazer', 'İsim lazer', 'Seri numara lazer', 'Metal lazer', 'Ahşap lazer'],
        'Serigrafi' => ['Tek renk', 'Çift renk', 'Çok renk', 'Tek yüz serigrafi', 'Çift yüz serigrafi'],
        'Tampon Baskı' => ['Tek renk', 'Çok renk', 'Küçük alan baskı'],
        'Sıcak Baskı' => ['Klişeli sıcak baskı', 'Varaklı sıcak baskı', 'Gofre', 'Yaldız', 'Logo sıcak baskı'],
        'DTF' => ['Tek taraf DTF', 'Çok renk DTF', 'Tekstil DTF'],
        'Sublimasyon' => ['Kupa sublimasyon', 'Tekstil sublimasyon', 'Tam yüzey sublimasyon'],
        'Nakış' => ['Logo nakış', 'İsim nakış', 'Patch nakış'],
        'Etiket / Sticker' => ['Tek etiket', 'Çoklu etiket', 'Rulo etiket', 'Özel kesim etiket'],
        'Dijital Baskı' => ['Tek taraf dijital', 'Çift taraf dijital', 'Tam yüzey dijital'],
        'Transfer Baskı' => ['Logo transfer', 'Çok renk transfer', 'Tekstil transfer'],
        'Diğer' => ['Diğer'],
    ];
    $clicheOptions = ['Yok', 'Var', 'Yeni üretilecek', 'Mevcut kullanılacak'];
    $clicheRequiredTypes = ['Sıcak Baskı'];
@endphp

<style>
    .pd-customer-picker {
        display: grid;
        gap: 12px;
        grid-template-columns: 1fr;
    }

    .pd-customer-select-hidden {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    .pd-customer-search-box {
        display: grid;
        gap: 10px;
        padding: 12px;
        border: 1px solid #dbe3f0;
        border-radius: 10px;
        background: #f8fbff;
        position: relative;
    }

    .pd-customer-search-box.is-selected {
        background: #ffffff;
    }

    .pd-customer-search-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: end;
    }

    .pd-customer-search-status {
        min-height: 20px;
        font-size: 13px;
        color: #64748b;
    }

    .pd-customer-search-status.is-warning {
        color: #92400e;
    }

    .pd-customer-search-status.is-danger {
        color: #b91c1c;
    }

    .pd-customer-search-stack {
        position: relative;
        min-width: 0;
    }

    .pd-customer-search-dropdown {
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        right: 0;
        z-index: 120;
        display: grid;
        gap: 8px;
        padding: 6px;
        border: 1px solid #dbe3f0;
        border-radius: 10px;
        background: #ffffff;
        box-shadow: 0 16px 28px rgba(15, 23, 42, 0.12);
    }

    .pd-customer-search-results {
        display: grid;
        gap: 8px;
        max-height: 220px;
        overflow-y: auto;
        padding-right: 2px;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }

    .pd-customer-search-results::-webkit-scrollbar {
        width: 8px;
    }

    .pd-customer-search-results::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 999px;
    }

    .pd-customer-search-results::-webkit-scrollbar-track {
        background: transparent;
    }

    .pd-customer-search-result {
        width: 100%;
        text-align: left;
        border: 1px solid #dbe3f0;
        border-radius: 8px;
        padding: 10px 12px;
        background: #fff;
        transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease;
    }

    .pd-customer-search-result:hover,
    .pd-customer-search-result:focus {
        border-color: #93c5fd;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.10);
        outline: none;
    }

    .pd-customer-search-result-title {
        font-size: 14px;
        font-weight: 600;
        color: #0f172a;
    }

    .pd-customer-search-result-meta {
        margin-top: 4px;
        font-size: 12px;
        color: #64748b;
    }

    .pd-customer-empty-card,
    .pd-customer-selected-card {
        border: 1px solid #dbe3f0;
        border-radius: 10px;
        background: #fff;
        padding: 12px 14px;
    }

    .pd-customer-empty-card {
        background: #fffaf0;
        border-color: #fcd34d;
    }

    .pd-customer-selected-card {
        display: grid;
        gap: 8px;
        padding: 10px 12px;
        background: #f8fafc;
    }

    .pd-customer-selected-card.hidden {
        display: none;
    }

    .pd-customer-picker-note {
        grid-column: 1 / -1;
        margin-top: -4px;
    }

    .pd-customer-selected-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        font-size: 14px;
        font-weight: 600;
        color: #0f172a;
    }

    .pd-customer-selected-meta,
    .pd-customer-empty-meta {
        margin-top: 6px;
        font-size: 12px;
        line-height: 1.5;
        color: #64748b;
    }

    .pd-customer-selected-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.5fr) minmax(180px, 0.9fr);
        gap: 10px;
        align-items: start;
    }

    .pd-customer-selected-primary {
        min-width: 0;
    }

    .pd-customer-selected-secondary {
        border-left: 1px solid #e2e8f0;
        padding-left: 10px;
        display: grid;
        gap: 4px;
    }

    .pd-customer-selected-name {
        font-size: 14px;
        font-weight: 600;
        color: #0f172a;
    }

    .pd-customer-selected-list {
        display: grid;
        gap: 4px;
        font-size: 12px;
        color: #64748b;
    }

    .pd-customer-selected-list strong {
        color: #334155;
        font-weight: 600;
    }

    .pd-customer-mini-row {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        font-size: 12px;
        color: #64748b;
    }

    .pd-customer-mini-row strong {
        color: #334155;
        font-weight: 600;
    }

    .pd-quote-item-group {
        border: 1px solid #dbe3f0;
        border-radius: 10px;
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
        padding: 12px;
    }

    .pd-quote-item-group + .pd-quote-item-group {
        margin-top: 12px;
    }

    .pd-quote-line-row {
        align-items: start;
        gap: 12px;
    }

    .pd-quote-line-product {
        padding-right: 6px;
    }

    .pd-quote-line-product-meta {
        margin-top: 10px;
        padding: 10px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #ffffff;
    }

    .pd-product-live-info {
        margin-top: 6px;
        display: grid;
        gap: 6px;
        font-family: Arial, Helvetica, sans-serif;
    }
    .pd-product-live-info__chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }

    .pd-product-live-info__grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .pd-product-live-info__card {
        padding: 10px 12px;
        border: 1px solid #dbe3f0;
        border-radius: 10px;
        background: #ffffff;
    }

    .pd-product-live-info__label {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        color: #64748b;
    }

    .pd-product-live-info__value {
        margin-top: 4px;
        font-size: 13px;
        font-weight: 700;
        line-height: 1.45;
        color: #0f172a;
    }

    .pd-product-live-info__note {
        margin-top: 2px;
        font-size: 11px;
        line-height: 1.45;
        color: #64748b;
    }

    .pd-product-live-info__summary {
        display: grid;
        gap: 6px;
    }

    .pd-product-live-info__summary-row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 10px;
    }

    .pd-product-live-info__summary-row strong {
        font-size: 13px;
        color: #0f172a;
    }

.pd-product-live-info__meta-line {
font-size: 12px;
line-height: 1.5;
color: #64748b;
}

    .pd-product-live-info__message,
    .pd-product-live-info__empty {
        font-size: 12px;
        line-height: 1.45;
        color: #64748b;
    }

    .pd-product-live-info__message.is-warning {
        color: #92400e;
    }

    .pd-product-live-info__message.is-danger {
        color: #b91c1c;
    }


    .pd-quote-line-subtitle-rich {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .pd-print-operation {
        margin-top: 10px;
        padding: 12px;
        border: 1px solid #dbe3f0;
        border-radius: 8px;
        background: #f8fbff;
    }

    .pd-print-operation + .pd-print-operation {
        margin-top: 8px;
    }

    .pd-print-operation-grid-flat {
        gap: 10px;
        align-items: start;
    }

    .pd-setup-inline-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
        margin-top: 8px;
    }

    .pd-setup-inline-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 8px;
        border: 1px solid #dbe3f0;
        border-radius: 999px;
        background: #fff;
        font-size: 11px;
        line-height: 1.35;
        color: #475569;
        white-space: nowrap;
    }

    .pd-setup-inline-chip strong {
        color: #334155;
        font-weight: 600;
    }

    .pd-setup-inline-chip.is-required {
        border-color: #fcd34d;
        background: #fff7ed;
        color: #92400e;
    }

    .pd-setup-inline-chip.is-ready {
        border-color: #bfdbfe;
        background: #eff6ff;
        color: #1d4ed8;
    }

    .pd-setup-inline-chip.is-missing {
        border-color: #e2e8f0;
        background: #f8fafc;
        color: #64748b;
    }

    .pd-quote-line-actions {
        display: grid;
        gap: 8px;
        align-content: start;
    }

    .pd-customer-modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 1800;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(15, 23, 42, 0.38);
    }

    .pd-customer-modal-overlay.is-open {
        display: flex;
    }

    .pd-customer-modal-panel {
        width: min(720px, calc(100vw - 32px));
        max-height: calc(100vh - 36px);
        overflow-y: auto;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.22);
    }

    .pd-customer-modal-header {
        padding: 18px 20px;
        border-bottom: 1px solid #e2e8f0;
    }

    .pd-customer-modal-body {
        padding: 18px 20px;
        display: grid;
        gap: 14px;
    }

    .pd-customer-modal-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .pd-customer-modal-grid .full {
        grid-column: 1 / -1;
    }

    .pd-customer-modal-field {
        display: grid;
        gap: 6px;
    }

    .pd-customer-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 14px 20px;
        border-top: 1px solid #e2e8f0;
        background: #fff;
    }

    .pd-customer-modal-note {
        padding: 10px 12px;
        border-radius: 8px;
        background: #f8fafc;
        font-size: 12px;
        line-height: 1.5;
        color: #475569;
    }

    .pd-customer-form-errors {
        display: grid;
        gap: 6px;
        padding: 10px 12px;
        border: 1px solid #fecaca;
        border-radius: 8px;
        background: #fef2f2;
        font-size: 12px;
        color: #b91c1c;
    }

    .pd-quote-meta-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 12px;
        align-items: start;
    }

    .pd-quote-meta-row-customer,
    .pd-quote-meta-row-note {
        grid-column: 1 / -1;
    }

    .pd-quote-meta-row-toggle {
        grid-column: span 3;
    }

    .pd-quote-meta-row-note .pd-label {
        margin-bottom: 6px;
        display: inline-flex;
    }

    .pd-quote-line-head {
        margin-bottom: 12px;
    }

    .pd-quote-print-debug {
        margin-top: 14px;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        background: #f8fafc;
        padding: 12px;
    }

    .pd-quote-print-debug.hidden {
        display: none;
    }

    .pd-quote-print-debug-title {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
    }

    .pd-quote-print-debug-note {
        margin-top: 4px;
        font-size: 12px;
        color: #64748b;
    }

    .pd-quote-print-debug-list {
        display: grid;
        gap: 8px;
        margin-top: 10px;
    }

    .pd-quote-print-debug-item {
        border: 1px solid #dbe3f0;
        border-radius: 8px;
        background: #fff;
        padding: 10px;
    }

    .pd-quote-print-debug-metrics {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
        margin-top: 8px;
    }

    .pd-quote-print-debug-metric {
        border: 1px solid #e2e8f0;
        border-radius: 7px;
        background: #f8fafc;
        padding: 8px;
        font-size: 11px;
        line-height: 1.45;
        color: #475569;
    }

    .pd-quote-print-debug-metric strong {
        display: block;
        margin-bottom: 4px;
        font-size: 11px;
        color: #0f172a;
    }

    .pd-quote-print-debug-row-list {
        display: grid;
        gap: 6px;
        margin-top: 8px;
    }

    .pd-quote-print-debug-row {
        border: 1px solid #e2e8f0;
        border-radius: 7px;
        background: #fff;
        padding: 8px;
        font-size: 11px;
        line-height: 1.45;
        color: #475569;
    }

    @media (max-width: 720px) {
        .pd-customer-search-grid,
        .pd-customer-modal-grid,
        .pd-customer-picker,
        .pd-quote-meta-grid {
            grid-template-columns: 1fr;
        }

        .pd-customer-selected-grid {
            grid-template-columns: 1fr;
        }

        .pd-product-live-info__grid {
            grid-template-columns: 1fr;
        }

        .pd-customer-selected-secondary {
            border-left: 0;
            border-top: 1px solid #e2e8f0;
            padding-left: 0;
            padding-top: 8px;
        }

        .pd-customer-modal-header,
        .pd-customer-modal-body,
        .pd-customer-modal-footer {
            padding-left: 16px;
            padding-right: 16px;
        }

        .pd-quote-meta-row-toggle,
        .pd-quote-meta-row-customer,
        .pd-quote-meta-row-note,
        .pd-customer-picker-note {
            grid-column: 1 / -1;
        }
    }
</style>

<form method="POST" action="{{ $formAction }}" id="{{ $canonicalQuoteFormId }}" data-promotion-quote-form class="space-y-5">
    @csrf
    @if ($formMethod !== 'POST')
        @method($formMethod)
    @endif
    @if ($errors->any())
        <div class="pd-card border-red-200 bg-red-50">
            <div class="pd-card-body">
                @if ($generalError)
                    <div class="text-sm font-semibold text-red-800">{{ $generalError }}</div>
                @endif
                @if (!empty($rowErrorSummary))
                    <div class="text-sm font-semibold text-red-800 {{ $generalError ? 'mt-3' : '' }}">Satır bazında düzeltme gerekiyor.</div>
                    <div class="mt-2 flex flex-col gap-2">
                        @foreach ($rowErrorSummary as $summary)
                            <button type="button" class="text-left text-sm font-medium text-red-700 underline-offset-2 hover:underline" data-error-target="{{ $summary['path'] }}">
                                {{ $summary['label'] }} — {{ $summary['message'] }}
                            </button>
                        @endforeach
                    </div>
                @endif
                @if ($fieldErrors->isNotEmpty())
                    <div class="text-sm font-semibold text-red-800 {{ ($generalError || !empty($rowErrorSummary)) ? 'mt-3' : '' }}">Formda düzeltilmesi gereken alanlar var.</div>
                    <ul class="mt-2 space-y-1 text-sm text-red-700 list-disc list-inside">
                        @foreach ($fieldErrors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    <div id="quote-client-error" class="pd-card border-red-200 bg-red-50 hidden">
        <div class="pd-card-body">
            <div class="text-sm font-semibold text-red-800" data-client-error-message></div>
            <div class="mt-2 hidden" data-client-error-list></div>
        </div>
    </div>

    <div class="pd-quote-workspace">
        <div class="space-y-5">
            <div class="pd-card">
                <div class="pd-card-body">
                    <div class="pd-section-heading">
                        <div>
                            <h3 class="pd-section-title">Müşteri ve teklif bilgileri</h3>
                        </div>
                        <div class="pd-chip-row">
                            <span class="pd-badge pd-badge-blue">{{ $quoteNumberLabel ?? 'Yeni Teklif' }}</span>
                        </div>
                    </div>

                    <div class="pd-quote-meta-grid">
                        <div class="pd-quote-meta-row pd-quote-meta-row-customer">
                            <label class="pd-label">Müşteri</label>
                            <div class="pd-customer-picker" data-customer-picker>
                                <div class="pd-customer-search-box">
                                    <select name="customer_company_id" id="customer-select" required class="pd-select pd-customer-select-hidden" aria-hidden="true" tabindex="-1">
                                        <option value="">Müşteri seçin</option>
                                        @foreach ($customers as $customer)
                                            <option value="{{ $customer->id }}" @selected((string) old('customer_company_id', $quote->customer_company_id ?? '') === (string) $customer->id)>
                                                {{ $customer->legal_name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="pd-customer-search-grid">
                                        <div class="pd-customer-search-stack">
                                            <label for="quote-customer-search" class="pd-label">Müşteri Ara</label>
                                            <input
                                                type="search"
                                                id="quote-customer-search"
                                                class="pd-input"
                                                autocomplete="off"
                                                placeholder="Firma adı, telefon, e-posta veya VKN/TCKN ile arayın"
                                            >
                                            <div id="quote-customer-search-status" class="pd-customer-search-status">Müşteri aramak için en az 3 karakter yazın.</div>
                                            <div id="quote-customer-search-dropdown" class="pd-customer-search-dropdown hidden">
                                                <div id="quote-customer-search-results" class="pd-customer-search-results hidden"></div>
                                                <div id="quote-customer-empty-state" class="pd-customer-empty-card hidden">
                                                    <div class="pd-customer-selected-title">Müşteri bulunamadı</div>
                                                    <div class="pd-customer-empty-meta">Tekliften çıkmadan hızlı müşteri ekleyebilirsiniz.</div>
                                                    <div class="mt-3">
                                                        <button type="button" class="pd-btn pd-btn-primary pd-btn-sm" id="quick-customer-empty-button">+ Hızlı Müşteri Ekle</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="pd-inline-actions">
                                            <button type="button" class="pd-btn pd-btn-light pd-btn-sm" id="quick-customer-open-button">+ Hızlı Müşteri Ekle</button>
                                        </div>
                                    </div>
                                    <div id="quote-customer-selected-card" class="pd-customer-selected-card hidden"></div>
                                </div>
                            </div>
                        </div>
                        <div class="pd-quote-meta-row">
                            <label class="pd-label">Teklif tarihi</label>
                            <input type="date" name="quote_date" id="quote-date-input" value="{{ $quoteDateValue }}" required class="pd-compact-input">
                        </div>
                        <div class="pd-quote-meta-row">
                            <label class="pd-label">Teslim tarihi</label>
                            <input type="date" name="valid_until" id="delivery-date-input" value="{{ $deliveryDateValue }}" class="pd-compact-input">
                        </div>
                        <div class="pd-quote-meta-row">
                            <label class="pd-label">Teklif Durumu</label>
                            <input type="text" value="{{ $quoteStatusLabel }}" class="pd-compact-input" readonly>
                        </div>
                        <div class="pd-quote-meta-row">
                            <label class="pd-label">Belge Türü</label>
                            <select name="invoice_status" id="invoice-status-select" class="pd-compact-select">
                                <option value="fis" @selected($invoiceStatusValue !== 'fatura')>Fiş</option>
                                <option value="fatura" @selected($invoiceStatusValue === 'fatura')>Fatura</option>
                            </select>
                        </div>
                        <div class="pd-quote-meta-row">
                            <label class="pd-label">Teslimat Tipi</label>
                            <select name="delivery_type_id" class="pd-compact-select">
                                <option value="">Seçiniz</option>
                                @foreach(($deliveryTypeOptions ?? collect()) as $deliveryType)
                                    <option value="{{ $deliveryType->id }}" @selected((string) old('delivery_type_id', $selectedDeliveryTypeId ?? '') === (string) $deliveryType->id)>
                                        {{ $deliveryType->name }}{{ $deliveryType->is_default ? ' · Varsayılan' : '' }}{{ !$deliveryType->is_active ? ' · Pasif' : '' }}
                                    </option>
                                @endforeach
                                @if(filled($legacyDeliveryTypeLabel ?? null))
                                    <option value="" selected>Mevcut değer: {{ $legacyDeliveryTypeLabel }}</option>
                                @endif
                            </select>
                            <input type="hidden" name="delivery_type" value="{{ old('delivery_type', $legacyDeliveryTypeLabel ?? ($quote->delivery_type ?? '')) }}">
                        </div>
                        <div class="pd-quote-meta-row">
                            <label class="pd-label">Para birimi</label>
                            <select name="currency" class="pd-compact-select">
                                @foreach (($quoteCurrency['options'] ?? []) as $option)
                                    <option value="{{ $option['value'] }}" @selected($currency === $option['value'])>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                            @if(($quoteCurrency['status_visible'] ?? false) && filled($quoteCurrency['status_label'] ?? null))
                                <p class="mt-2 text-xs leading-5 text-slate-600">
                                    {{ $quoteCurrency['status_label'] }}
                                    @if(($quoteCurrency['last_refreshed_at'] ?? null))
                                        · Son kur güncelleme: {{ optional($quoteCurrency['last_refreshed_at'])->format('d.m.Y H:i') }}
                                    @endif
                                </p>
                            @endif
                            @if(isset($quote) && (($quoteCurrency['can_refresh'] ?? false) || ($quoteCurrency['can_acknowledge'] ?? false)))
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @if($quoteCurrency['can_refresh'] ?? false)
                                        <button type="submit" form="{{ $currencyRefreshFormId }}" class="pd-btn pd-btn-light pd-btn-sm">Kuru Yenile</button>
                                    @endif
                                    @if($quoteCurrency['can_acknowledge'] ?? false)
                                        <button type="submit" form="{{ $currencyAcknowledgeFormId }}" class="pd-btn pd-btn-light pd-btn-sm">Mevcut Kuru Koru</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="pd-quote-meta-row pd-quote-meta-row-toggle">
                            <label class="pd-label" for="show-print-price-details-select">Baskı fiyatı gösterimi</label>
                            <select
                                name="show_print_price_details_to_customer"
                                id="show-print-price-details-select"
                                class="pd-compact-select"
                            >
                                <option value="1" @selected((string) old('show_print_price_details_to_customer', isset($quote) ? ((int) $quote->shouldShowPrintPriceDetailsToCustomer()) : '1') === '1')>Baskı fiyatı gösterilsin</option>
                                <option value="0" @selected((string) old('show_print_price_details_to_customer', isset($quote) ? ((int) $quote->shouldShowPrintPriceDetailsToCustomer()) : '1') === '0')>Baskı fiyatı gizlensin</option>
                            </select>
                            <p class="mt-2 text-xs leading-5 text-slate-600">
                                Ana ürün fiyatı müşteriye baskı dahil görünür. Bu seçenek yalnız ürün altındaki baskı bilgi satırında
                                <strong>Baskı Birim</strong> ve <strong>Baskı Toplam</strong> bilgisini kontrol eder.
                            </p>
                        </div>
                        <div class="pd-quote-meta-row pd-quote-meta-row-note">
                            <label class="pd-label">Sipariş Notu</label>
                            <textarea name="notes" rows="1" class="pd-textarea pd-textarea-compact pd-quote-note-input" placeholder="Kısa teklif notu...">{{ old('notes', $quote->notes ?? '') }}</textarea>
                        </div>
                    </div>

                </div>
            </div>

            <div class="pd-card">
                <div class="pd-card-body">
                    <div class="pd-section-heading pd-section-heading-split">
                        <div>
                            <h3 class="pd-section-title">Ürün kalemleri</h3>
                            <p class="pd-section-subtitle">Ürünü tek satırda girin; baskı gerekiyorsa hemen altında 1a, 1b satırlarıyla yönetin.</p>
                        </div>
                        <div class="pd-section-heading-actions">
                            <button type="button" id="add-product-item" class="pd-btn pd-btn-primary pd-btn-sm pd-quote-add-product-button">Ürün Ekle</button>
                        </div>
                    </div>

                    <div class="pd-quote-line-head">
                        <span>No</span>
                        <span>Ürün</span>
                        <span>Miktar</span>
                        <span>Satış Liste</span>
                        <span>İskonto %</span>
                        <span>Satış Birim Fiyatı</span>
                        <span>Toplam</span>
                        <span>Baskı</span>
                        <span>Sil</span>
                    </div>
                    <div id="product-items-container" class="space-y-3"></div>
                    @if ($quotePrintDebug)
                        <div id="quote-print-debug-panel" class="pd-quote-print-debug hidden">
                            <div class="pd-quote-print-debug-title">Baskı Debug</div>
                            <div class="pd-quote-print-debug-note">Bu panel yalnız debug modunda görünür. State, DOM ve add/mount akışı burada izlenir.</div>
                            <div id="quote-print-debug-body" class="pd-quote-print-debug-list"></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <aside class="pd-quote-summary-column space-y-4">
            <div class="pd-card pd-sticky-card">
                <div class="pd-card-body">
                    <div class="pd-summary-title">Teklif Özeti</div>
                    <p class="pd-section-subtitle mt-1">Toplamlar teklif kalemleri değiştikçe anında güncellenir.</p>

                    @if($canViewFinancialData ?? false)
                        <div class="pd-summary-section pd-summary-total-stack mt-4" id="financial-summary">
                            <div class="pd-summary-stack">
                                <div class="pd-summary-stack-row">
                                    <span>Ürün Toplamı</span>
                                    <strong id="summary-product-total">0,00 {{ $currencyDisplay }}</strong>
                                </div>
                                <div class="pd-summary-stack-row pd-summary-stack-row-print">
                                    <span>Baskı Toplamı</span>
                                    <strong id="summary-print-total">0,00 {{ $currencyDisplay }}</strong>
                                </div>
                                <div class="pd-summary-stack-row">
                                    <span>Ara Toplam</span>
                                    <strong id="summary-subtotal">0,00 {{ $currencyDisplay }}</strong>
                                </div>
                            </div>
                            <div id="summary-vat-breakdown" class="space-y-2 {{ $showInitialVatSummary ? '' : 'hidden' }}"></div>
                            <div class="pd-summary-stack-row pd-summary-stack-row-vat {{ $showInitialVatSummary ? '' : 'hidden' }}" id="summary-vat-total-row">
                                <span id="summary-vat-label">KDV Toplamı</span>
                                <strong id="summary-vat">0,00 {{ $currencyDisplay }}</strong>
                            </div>
                            <div class="pd-summary-total-box">
                                <div class="pd-summary-total-label">Genel toplam</div>
                                <strong id="summary-grand-total">0,00 {{ $currencyDisplay }}</strong>
                            </div>
                            <div class="pd-summary-section mt-4">
                                <div class="pd-summary-section-title">Hızlı Aksiyon</div>
                                <div class="pd-summary-action-list">
                                    <div class="pd-summary-action"><span>Kaydet</span><strong>{{ $submitLabel }}</strong></div>
                                    <div class="pd-summary-action"><span>Ürün satırı</span><strong id="summary-item-count">0 kalem</strong></div>
                                    <div class="pd-summary-action"><span>Baskı satırı</span><strong id="summary-print-count">0 işlem</strong></div>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="pd-note pd-note-slate mt-4" id="financial-summary-hidden">Finansal bilgiler yetkiniz dışında gizlendi.</div>
                    @endif
                </div>
            </div>
        </aside>
    </div>
</form>
@if(isset($quote) && ($quoteCurrency['can_refresh'] ?? false))
    <form method="POST" action="{{ route('admin.promotion-quotes.currency.refresh', $quote) }}" id="{{ $currencyRefreshFormId }}">
        @csrf
    </form>
@endif

@if(isset($quote) && ($quoteCurrency['can_acknowledge'] ?? false))
    <form method="POST" action="{{ route('admin.promotion-quotes.currency.acknowledge', $quote) }}" id="{{ $currencyAcknowledgeFormId }}">
        @csrf
    </form>
@endif

<div id="quick-customer-modal" class="pd-customer-modal-overlay hidden" aria-hidden="true">
    <div class="pd-customer-modal-panel" role="dialog" aria-modal="true" aria-labelledby="quick-customer-modal-title">
        <div class="pd-customer-modal-header">
            <h3 id="quick-customer-modal-title" class="pd-section-title">Hızlı Müşteri Ekle</h3>
            <p class="pd-section-subtitle mt-2">Tekliften çıkmadan cari/firma kaydı oluşturun. Kaydedince müşteri otomatik seçilir.</p>
        </div>
        <div class="pd-customer-modal-body">
            <div id="quick-customer-form-errors" class="pd-customer-form-errors hidden"></div>
            <div class="pd-customer-modal-grid">
                <div class="pd-customer-modal-field full">
                    <label for="quick-customer-legal-name" class="pd-label">Firma / Müşteri Adı</label>
                    <input type="text" id="quick-customer-legal-name" class="pd-input" autocomplete="organization">
                </div>
                <div class="pd-customer-modal-field">
                    <label for="quick-customer-tax-number" class="pd-label">Vergi No / TC No</label>
                    <input type="text" id="quick-customer-tax-number" class="pd-input" inputmode="numeric">
                </div>
                <div class="pd-customer-modal-field">
                    <label for="quick-customer-identity-type" class="pd-label">Firma Tipi</label>
                    <select id="quick-customer-identity-type" class="pd-select">
                        <option value="company">Tüzel Kişi</option>
                        <option value="person">Åahıs / Bireysel</option>
                    </select>
                </div>
                <div class="pd-customer-modal-field">
                    <label for="quick-customer-email" class="pd-label">E-posta</label>
                    <input type="email" id="quick-customer-email" class="pd-input" autocomplete="email">
                </div>
                <div class="pd-customer-modal-field">
                    <label for="quick-customer-phone" class="pd-label">WhatsApp / Telefon</label>
                    <div style="display:flex; align-items:center; border:1px solid #d0d5dd; border-radius:10px; overflow:hidden; background:#fff;">
                        <span style="display:inline-flex; align-items:center; gap:8px; padding:0 12px; min-height:42px; background:#f8fafc; border-right:1px solid #e4e7ec; color:#344054; font-size:13px; white-space:nowrap;">+90</span>
                        <input type="text" id="quick-customer-phone" class="pd-input" autocomplete="tel" placeholder="5xx xxx xx xx" style="border:0; border-radius:0; box-shadow:none;">
                    </div>
                </div>
                <div class="pd-customer-modal-field">
                    <label for="quick-customer-contact-name" class="pd-label">Yetkili Adı</label>
                    <input type="text" id="quick-customer-contact-name" class="pd-input" autocomplete="name">
                </div>
                <div class="pd-customer-modal-field">
                    <label for="quick-customer-city" class="pd-label">Åehir</label>
                    <input type="text" id="quick-customer-city" class="pd-input" autocomplete="address-level2">
                </div>
                <div class="pd-customer-modal-field full">
                    <label for="quick-customer-address-note" class="pd-label">Kısa Adres / Not</label>
                    <textarea id="quick-customer-address-note" rows="3" class="pd-textarea"></textarea>
                </div>
            </div>
            <div class="pd-customer-modal-note">
                Minimum kayıt için firma adı yeterli olabilir; e-posta ve WhatsApp cep telefonu varsa teklif gönderiminde otomatik kullanılabilir.
                Detaylı adres ve cari bilgiler daha sonra Müşteriler / Cari Kartlar ekranından tamamlanır.
            </div>
        </div>
        <div class="pd-customer-modal-footer">
            <button type="button" class="pd-btn pd-btn-light" id="quick-customer-cancel-button">Vazgeç / Kapat</button>
            <button type="button" class="pd-btn pd-btn-primary" id="quick-customer-save-button">Kaydet ve Seç</button>
        </div>
    </div>
</div>

@php
    $workspacePayload = [
        'searchUrl' => $catalogSearchUrl,
        'customerSearchUrl' => $customerSearchUrl ?? null,
        'quickCustomerStoreUrl' => $quickCustomerStoreUrl ?? null,
        'customerLookup' => $customerLookup ?? [],
        'selectedCustomer' => $selectedCustomer ?? null,
        'currency' => $currency,
        'currencyDisplay' => $currencyDisplay,
        'quoteCurrency' => $quoteCurrency,
        'canViewFinancialData' => (bool) ($canViewFinancialData ?? false),
        'items' => $initialItems,
        'tenantPrintSettings' => $tenantPrintSettings ?? [],
        'legacyPrintTypeOptions' => $legacyPrintTypeOptions,
        'printOptionMap' => $printOptionMap,
        'clicheOptions' => $clicheOptions,
        'clicheRequiredTypes' => $clicheRequiredTypes,
        'invoiceStatus' => $invoiceStatusValue,
        'defaultPrintVatRate' => 20,
        'printDebugEnabled' => $quotePrintDebug,
        'canonicalFormId' => $canonicalQuoteFormId,
        'liveProductInfoUrl' => route('admin.product-hub.live-product-info'),
        'intermediateElementEnabled' => app(\App\Services\PromotionIntermediateElementPolicy::class)->shouldRender(),
    ];
@endphp

<script>
const quoteWorkspace = @json($workspacePayload, JSON_UNESCAPED_UNICODE);
let productItemCount = 0;
let activeItemIndex = 0;
let expandAllItems = false;
const catalogSearchTimers = new Map();
const catalogEntryStore = new Map();
const liveProductInfoState = new Map();
let catalogEntrySequence = 0;
const tenantPrintSettings = Array.isArray(quoteWorkspace.tenantPrintSettings) ? quoteWorkspace.tenantPrintSettings : [];
const tenantPrintSettingsById = new Map(tenantPrintSettings.map((setting) => [String(setting.id), setting]));
const legacyPrintTypeOptions = Array.isArray(quoteWorkspace.legacyPrintTypeOptions) ? quoteWorkspace.legacyPrintTypeOptions : [];
const printOptionMap = quoteWorkspace.printOptionMap || {};
const clicheRequiredTypes = quoteWorkspace.clicheRequiredTypes || ['Sıcak Baskı'];
const canonicalQuoteFormId = quoteWorkspace.canonicalFormId || 'quote-form';
const customerLookup = new Map(
    Object.values(quoteWorkspace.customerLookup || {}).map((customer) => [String(customer.id), customer])
);
let customerSearchTimer = null;
let quickCustomerModalSnapshot = null;
const quotePrintDebugEnabled = !!quoteWorkspace.printDebugEnabled;
const quotePrintDebugState = {
    lastActionAt: '',
    lastActionReason: '',
    items: new Map(),
    lastError: '',
};

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function displayCurrencyLabel(currency) {
    return String(currency || '').toUpperCase() === 'TRY' ? 'TL' : (currency || 'TL');
}

function formatMoney(value, currency = document.querySelector('select[name="currency"]')?.value || quoteWorkspace.currency || 'TRY') {
    const number = Number(value ?? 0);
    return `${number.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${displayCurrencyLabel(currency)}`;
}

function formatInputNumber(value, digits = 2) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '';
    }

    return number.toFixed(digits);
}

function formatStock(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);
    return Number.isFinite(number)
        ? number.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : '—';
}

function formatLiveInfoStock(value, fallbackLabel = null) {
    if (fallbackLabel) {
        return fallbackLabel;
    }

    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);
    return Number.isFinite(number)
        ? number.toLocaleString('tr-TR', {
            minimumFractionDigits: Number.isInteger(number) ? 0 : 2,
            maximumFractionDigits: Number.isInteger(number) ? 0 : 2,
        })
        : '—';
}

function formatLiveInfoTimestamp(value) {
    if (!value) {
        return '—';
    }

    const [datePart = '', timePart = ''] = String(value).split(' ');
    const today = new Date();
    const todayKey = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

    if (datePart === todayKey) {
        return timePart ? `Bugün ${timePart}` : 'Bugün';
    }

    const [year = '', month = '', day = ''] = datePart.split('-');
    if (!year || !month || !day) {
        return value;
    }

    return `${day}.${month}.${year}${timePart ? ` ${timePart}` : ''}`;
}

function formatSalesInfoDate(value) {
    if (!value) {
        return '—';
    }

    const [year = '', month = '', day = ''] = String(value).split('-');
    if (!year || !month || !day) {
        return String(value);
    }

    return `${day}.${month}.${year}`;
}

function formatSalesMetricAmount(value, currency = 'TRY', digits = 2) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '—';
    }

    return `${number.toLocaleString('tr-TR', { minimumFractionDigits: digits, maximumFractionDigits: digits })} ${displayCurrencyLabel(currency)}`;
}

function formatSalesDiscountPercent(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '—';
    }

    return `%${number.toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
}
function resolveStockTruth(item = {}, payload = {}) {
    const stockSnapshot = safeObject(item.stock_snapshot);
    const local = finiteNumber(firstFilledValue([
        payload.local_stock_quantity,
        stockSnapshot.local_stock_quantity,
    ], 0), 0);
    const supplier = finiteNumber(firstFilledValue([
        payload.supplier_stock_quantity,
        stockSnapshot.supplier_stock_quantity,
    ], 0), 0);
    const fallback = finiteNumber(firstFilledValue([
        payload.fallback_stock_quantity,
        stockSnapshot.total_stock_quantity,
        stockSnapshot.stock_quantity,
    ], 0), 0);
    const visible = finiteNumber(firstFilledValue([
        payload.current_stock,
        payload.visible_stock_quantity,
        stockSnapshot.visible_stock_quantity,
        stockSnapshot.total_stock_quantity,
        fallback,
    ], 0), 0);

    return { local, supplier, fallback, visible };
}

function resolveLocalStockPresentation(item = {}, payload = {}) {
    const stockSnapshot = safeObject(item.stock_snapshot);
    const rawValue = firstFilledValue([
        payload.local_stock_quantity,
        stockSnapshot.local_stock_quantity,
    ], null);
    const rawProjectionValue = firstFilledValue([
        payload.local_stock_projection_quantity,
        stockSnapshot.local_stock_projection_quantity,
    ], rawValue);
    const normalizedValue = Number(rawValue);
    const normalizedProjectionValue = Number(rawProjectionValue);

    return {
        label: firstFilledValue([payload.local_stock_label, stockSnapshot.local_stock_label], ''),
        note: firstFilledValue([payload.local_stock_note, stockSnapshot.local_stock_note], ''),
        source: firstFilledValue([payload.local_stock_source, stockSnapshot.local_stock_source], ''),
        scope: firstFilledValue([payload.local_stock_scope, stockSnapshot.local_stock_scope], ''),
        reasonCode: firstFilledValue([payload.local_stock_reason_code, stockSnapshot.local_stock_reason_code], ''),
        operational: !!firstFilledValue([payload.local_stock_operational, stockSnapshot.local_stock_operational], false),
        value: Number.isFinite(normalizedValue) ? normalizedValue : null,
        projectionValue: Number.isFinite(normalizedProjectionValue) ? normalizedProjectionValue : null,
    };
}

function resolveStockSourceLabel(localStockPresentation = {}, localStock = 0, supplierStock = 0) {
    if (localStockPresentation?.label) {
        return localStockPresentation.label;
    }

    if (localStock > 0 && supplierStock > 0) {
        return 'Yerel kullanılabilir stok + Tedarikçi stok';
    }

    if (localStock > 0) {
        return 'Yerel kullanılabilir stok';
    }

    if (supplierStock > 0) {
        return 'Tedarikçi stok';
    }

    return 'Stok yok';
}

function resolveCompactLocalStockDisplay(stockTruth = {}, localStockPresentation = {}) {
    const localStock = finiteNumber(stockTruth.local, 0);
    const visibleStock = finiteNumber(stockTruth.visible, 0);
    const operationalValue = finiteNumber(localStockPresentation.value, localStock);
    const projectionValue = finiteNumber(localStockPresentation.projectionValue, visibleStock);

    if (localStockPresentation?.operational && operationalValue > 0) {
        return operationalValue;
    }

    if (operationalValue > 0) {
        return operationalValue;
    }

    if (projectionValue > 0) {
        return projectionValue;
    }

    return null;
}

function resolveCompactStockMetric(stockTruth = {}, localStockPresentation = {}) {
    const supplierStock = finiteNumber(stockTruth.supplier, 0);
    const localDisplay = resolveCompactLocalStockDisplay(stockTruth, localStockPresentation);

    if (localDisplay !== null) {
        return `Local stok: ${formatLiveInfoStock(localDisplay)}`;
    }

    if (supplierStock > 0) {
        return `Tedarikçi stok: ${formatLiveInfoStock(supplierStock)}`;
    }

    return 'Stok yok';
}

function resolveCompactStockSummary(stockTruth = {}, localStockPresentation = {}) {
    const supplierStock = finiteNumber(stockTruth.supplier, 0);
    const localDisplay = resolveCompactLocalStockDisplay(stockTruth, localStockPresentation);
    const parts = [];

    if (localDisplay !== null) {
        parts.push(`Local stok: ${formatLiveInfoStock(localDisplay)}`);
    }

    if (supplierStock > 0) {
        parts.push(`Tedarikçi stok: ${formatLiveInfoStock(supplierStock)}`);
    }

    if (!parts.length) {
        parts.push('Stok yok');
    }

    return parts.join(' · ');
}

function resolveOperationalListPriceValue(source = {}, fallbackSnapshot = {}) {
    const snapshot = {
        ...safeObject(fallbackSnapshot),
        ...safeObject(source),
    };
    const quoteCurrency = resolveQuoteCurrencyCode();
    const sourceCurrency = String(firstFilledValue([
        snapshot.source_currency,
        source.source_currency,
    ], '')).toUpperCase();

    if (quoteCurrency === 'TRY') {
        return finiteNumber(firstFilledValue([
            snapshot.base_price,
            snapshot.sales_presentation?.sales_list_try,
            source.base_price,
            source.quote_price_value,
        ], 0), 0);
    }

    if (sourceCurrency && quoteCurrency === sourceCurrency) {
        return finiteNumber(firstFilledValue([
            snapshot.source_price,
            source.source_price,
            source.quote_price_value,
        ], 0), 0);
    }

    return finiteNumber(firstFilledValue([
        source.quote_price_value,
        snapshot.quote_price_value,
        snapshot.base_price,
        source.base_price,
        snapshot.display_price,
        snapshot.list_price,
    ], 0), 0);
}

function resolveSalesPresentation(item = {}, payload = {}) {
    const itemSnapshot = safeObject(item.price_snapshot);
    const liveSnapshot = safeObject(payload.quote_price_snapshot);
    const mergedSnapshot = {
        ...itemSnapshot,
        ...liveSnapshot,
    };
    const savedPresentation = safeObject(mergedSnapshot.sales_presentation);
    const sourceCurrency = firstFilledValue([
        savedPresentation.sales_source_currency,
        mergedSnapshot.source_currency,
        payload.source_currency,
    ], 'TRY');
    const documentCurrency = firstFilledValue([
        savedPresentation.sales_document_currency,
        mergedSnapshot.document_currency,
        payload.quote_currency,
        currentQuoteCurrency(),
    ], currentQuoteCurrency());
    const baseCurrency = firstFilledValue([
        mergedSnapshot.base_currency,
        payload.base_currency,
        'TRY',
    ], 'TRY');
    let sourceToBaseRate = firstFilledValue([
        savedPresentation.sales_rate,
        mergedSnapshot.source_to_base_rate,
        payload.source_to_base_rate,
        itemSnapshot.source_to_base_rate,
    ], null);
    let sourceToBaseRateDate = firstFilledValue([
        savedPresentation.sales_rate_date,
        mergedSnapshot.source_to_base_rate_date,
        payload.source_to_base_rate_date,
        itemSnapshot.source_to_base_rate_date,
    ], '');
    let sourceToBaseRateSource = firstFilledValue([
        savedPresentation.sales_rate_source,
        mergedSnapshot.source_to_base_rate_source,
        payload.source_to_base_rate_source,
        itemSnapshot.source_to_base_rate_source,
    ], '');
    const sourceAmount = firstFilledValue([
        savedPresentation.sales_source_amount,
        mergedSnapshot.source_price,
        payload.source_price,
        mergedSnapshot.source_list_price,
    ], null);
    const listTry = firstFilledValue([
        savedPresentation.sales_list_try,
        mergedSnapshot.base_price,
        payload.base_price,
        mergedSnapshot.base_cost,
    ], null);

    if (String(sourceCurrency).toUpperCase() === String(baseCurrency).toUpperCase()) {
        sourceToBaseRate = null;
        sourceToBaseRateDate = '';
        sourceToBaseRateSource = '';
    } else if ((sourceToBaseRate === null || sourceToBaseRate === '') && Number(sourceAmount || 0) > 0 && Number(listTry || 0) > 0) {
        sourceToBaseRate = Number(listTry) / Number(sourceAmount);
        sourceToBaseRateSource = sourceToBaseRateSource || 'derived';
    }

    return {
        sourceAmount,
        sourceCurrency,
        rate: sourceToBaseRate,
        rateDate: sourceToBaseRateDate,
        rateSource: sourceToBaseRateSource,
        listTry,
        discountPercent: firstFilledValue([
            savedPresentation.sales_discount_percent,
            item.discount_rate,
            mergedSnapshot.discount_rate,
        ], null),
        calculatedUnit: firstFilledValue([
            savedPresentation.sales_calculated_unit_try,
            mergedSnapshot.suggested_sales_unit_price_document,
            item.calculated_unit_price,
        ], null),
        finalUnit: firstFilledValue([
            savedPresentation.sales_final_unit_try,
            mergedSnapshot.actual_sales_unit_price_document,
            item.unit_price,
        ], null),
        manualOverride: savedPresentation.sales_manual_override === true
            || mergedSnapshot.manual_sales_price_override === true
            || item.manual_unit_price === true
            || item.manual_unit_price === 1
            || item.manual_unit_price === '1',
        conversionStatus: firstFilledValue([
            savedPresentation.conversion_status,
            mergedSnapshot.document_conversion_status,
            payload.quote_price_status,
        ], ''),
        fallbackUsed: savedPresentation.fallback_used === true || mergedSnapshot.fallback_used === true,
        stale: savedPresentation.stale === true || mergedSnapshot.stale === true,
        documentCurrency,
        baseCurrency,
    };
}

function resolveCompactMetaSupplierLabel(item = {}, payload = {}) {
    const itemSnapshot = safeObject(item.product_snapshot);
    const payloadSnapshot = safeObject(payload.product_snapshot);

    return String(firstFilledValue([
        payload.supplier_name,
        payloadSnapshot.supplier_name,
        item.supplier_name,
        itemSnapshot.supplier_name,
        item.selected_catalog_identity?.supplier_name,
    ], '')).trim();
}

function resolveCompactMetaSku(item = {}, payload = {}) {
    const itemSnapshot = safeObject(item.product_snapshot);
    const payloadSnapshot = safeObject(payload.product_snapshot);

    return String(firstFilledValue([
        payload.product_code,
        payload.sku,
        payload.supplier_product_code,
        payloadSnapshot.product_code,
        item.product_code,
        itemSnapshot.product_code,
    ], '')).trim();
}

function buildCompactProductMetaBits(item = {}, payload = {}, options = {}) {
    const stockTruth = resolveStockTruth(item, payload);
    const localStockPresentation = resolveLocalStockPresentation(item, payload);
    const includePrice = options.includePrice === true;
    const includeUpdated = options.includeUpdated !== false;
    const supplier = resolveCompactMetaSupplierLabel(item, payload);
    const code = resolveCompactMetaSku(item, payload);
    const bits = [
        supplier,
        code ? `SKU: ${code}` : '',
        resolveCompactStockSummary(stockTruth, localStockPresentation),
    ];

    if (includePrice) {
        const sourceCurrency = String(firstFilledValue([payload.source_currency, payload.currency], '')).toUpperCase();
        const sourcePrice = firstFilledValue([payload.source_price, payload.list_price, payload.display_price], null);

        if (sourcePrice !== null && sourcePrice !== '') {
            bits.push(`Güncel fiyat: ${formatSalesMetricAmount(sourcePrice, sourceCurrency || 'TRY', 2)}`);
        }
    }

    if (includeUpdated && payload.last_synced_at) {
        bits.push(`Güncellendi: ${formatLiveInfoTimestamp(payload.last_synced_at)}`);
    }

    return bits
        .map((bit) => String(bit || '').trim())
        .filter(Boolean)
        .filter((bit, index, values) => values.indexOf(bit) === index);
}

function buildCompactProductMetaLine(item = {}, payload = {}, options = {}) {
    return buildCompactProductMetaBits(item, payload, options).join(' · ');
}

function formatRateSourceLabel(rateSource = '') {
    const normalized = String(rateSource || '').trim().toLowerCase();

    if (!normalized) {
        return '';
    }

    const labels = {
        tcmb: 'TCMB',
        ecb: 'ECB',
        fixer: 'Fixer',
        manual: 'Manuel kur',
        derived: 'Hesaplanan',
        fallback: 'Yedek kaynak',
        identity: '',
    };

    return labels[normalized] ?? String(rateSource || '').toUpperCase();
}

function renderSalesPresentationPanel(item = {}, payload = {}) {
    return '';
}
function parseJsonValue(value) {
    if (!value) return null;
    if (typeof value === 'object') return value;
    try {
        return JSON.parse(value);
    } catch (error) {
        return null;
    }
}

function isPlainObject(value) {
    return !!value && typeof value === 'object' && !Array.isArray(value);
}

function safeObject(value) {
    if (isPlainObject(value)) {
        return value;
    }

    const parsed = parseJsonValue(value);
    return isPlainObject(parsed) ? parsed : {};
}

function safeArray(value) {
    if (Array.isArray(value)) {
        return value;
    }

    if (value === null || value === undefined || value === '') {
        return [];
    }

    return [value];
}

function itemHasLiveProductSelection(item = {}) {
    return !!(
        item.tenant_catalog_product_id
        || item.tenant_catalog_product_variant_id
        || item.selected_catalog_identity?.tenant_catalog_product_id
        || item.selected_catalog_identity?.tenant_catalog_product_variant_id
    );
}

function resolveLiveProductSnapshotPrice(item = {}) {
    const priceSnapshot = safeObject(item.price_snapshot);
    const productSnapshot = safeObject(item.product_snapshot);

    return firstFilledValue([
        priceSnapshot.list_price,
        priceSnapshot.display_price,
        productSnapshot.list_price,
        item.list_price,
        item.unit_price,
    ], '');
}

function resolveLiveProductSnapshotStock(item = {}) {
    const stockSnapshot = safeObject(item.stock_snapshot);
    const productSnapshot = safeObject(item.product_snapshot);

    return firstFilledValue([
        stockSnapshot.visible_stock_quantity,
        stockSnapshot.stock_quantity,
        stockSnapshot.total_stock_quantity,
        productSnapshot.visible_stock_quantity,
    ], '');
}

function buildLiveProductInfoUrl(item = {}) {
    const productId = firstFilledValue([
        item.tenant_catalog_product_id,
        item.selected_catalog_identity?.tenant_catalog_product_id,
        item.product_snapshot?.tenant_catalog_product_id,
    ], '');
    const variantId = firstFilledValue([
        item.tenant_catalog_product_variant_id,
        item.selected_catalog_identity?.tenant_catalog_product_variant_id,
        item.product_snapshot?.tenant_catalog_product_variant_id,
    ], '');

    if (!productId && !variantId) {
        return null;
    }

    const params = new URLSearchParams();
    const priceSnapshot = safeObject(item.price_snapshot);
    const manualUnitPrice = item.manual_unit_price === true
        || item.manual_unit_price === 1
        || item.manual_unit_price === '1'
        || priceSnapshot.manual_unit_price === true;

    if (productId) {
        params.set('tenant_catalog_product_id', productId);
    }

    if (variantId) {
        params.set('tenant_catalog_product_variant_id', variantId);
    }

    if (item.quote_item_id) {
        params.set('quote_item_id', item.quote_item_id);
    }

    params.set('currency', currentQuoteCurrency());
    params.set('quote_date', currentQuoteDate());

    const snapshotPrice = resolveLiveProductSnapshotPrice(item);
    const snapshotStock = resolveLiveProductSnapshotStock(item);

    if (snapshotPrice !== '') {
        params.set('snapshot_price', snapshotPrice);
    }

    if (snapshotStock !== '') {
        params.set('snapshot_stock', snapshotStock);
    }

    if (manualUnitPrice) {
        const manualPriceValue = firstFilledValue([
            priceSnapshot.manual_entry_amount,
            priceSnapshot.actual_sales_unit_price_document,
            item.unit_price,
        ], '');
        const manualPriceCurrency = firstFilledValue([
            priceSnapshot.manual_entry_currency,
            priceSnapshot.document_currency,
            currentQuoteCurrency(),
        ], currentQuoteCurrency());

        if (manualPriceValue !== '') {
            params.set('manual_unit_price', manualPriceValue);
            params.set('manual_unit_price_currency', manualPriceCurrency);
        }
    }

    return `${quoteWorkspace.liveProductInfoUrl}?${params.toString()}`;
}

function liveProductInfoRequestKey(item = {}) {
    return buildLiveProductInfoUrl(item) || '';
}

function findItemElementByStableKey(stableKey = '') {
    return Array.from(document.querySelectorAll('.pd-quote-item'))
        .find((element) => String(element.dataset.stableKey || '') === String(stableKey || '')) || null;
}

function findCollectedItemByStableKey(stableKey = '') {
    return collectItems().find((item) => String(item._stable_key || '') === String(stableKey || '')) || null;
}

function setLiveProductInfoState(stableKey, state) {
    if (!stableKey) {
        return;
    }

    liveProductInfoState.set(String(stableKey), state);
}

function getLiveProductInfoState(stableKey) {
    return liveProductInfoState.get(String(stableKey || '')) || null;
}

function isCategoryWarningMessage(message = '') {
    return String(message || '').toLocaleLowerCase('tr-TR').includes('kategori');
}

function liveProductInfoWarningLabel(message = '', payload = {}) {
    const normalized = String(message || '').toLocaleLowerCase('tr-TR');

    if (normalized.includes('kur')) {
        return 'Kur bilgisi bulunamadı';
    }

    if (normalized.includes('aktif değil') || normalized.includes('pasif')) {
        return 'Ürün pasif';
    }

    if (
        normalized.includes('uygun değil')
        || normalized.includes('kullanıma kapalı')
        || normalized.includes('erişemiyor')
        || normalized.includes('doğrulanamadı')
    ) {
        return 'Teklifte kullanılamaz';
    }

    if (normalized.includes('stok')) {
        return payload.ok ? '' : 'Teklifte kullanılamaz';
    }

    return payload.ok ? '' : 'Teklifte kullanılamaz';
}

function buildLiveProductInfoWarnings(payload = {}) {
    const explicitWarnings = Array.isArray(payload.warnings) ? payload.warnings.filter(Boolean) : [];
    const otherWarnings = explicitWarnings.filter((warning) => !isCategoryWarningMessage(warning));
    const badges = [];

    if (payload.quote_price_status && !isReadyQuotePriceStatus(payload.quote_price_status)) {
        badges.push({
            label: 'Kur bilgisi bulunamadı',
            tone: 'red',
            title: quoteCurrencyWarningMessage(),
        });
    }

    const statusWarnings = [
        payload.stock_warning || '',
        payload.product_inactive_warning || '',
        ...otherWarnings,
    ].filter(Boolean).filter((warning, index, values) => values.indexOf(warning) === index);

    statusWarnings.forEach((warning) => {
        const label = liveProductInfoWarningLabel(warning, payload);
        if (!label) {
            return;
        }

        badges.push({
            label,
            tone: payload.ok ? 'amber' : 'red',
            title: warning,
        });
    });

    if (!payload.ok && !statusWarnings.length && payload.public_safe_message) {
        const label = liveProductInfoWarningLabel(payload.public_safe_message, payload);
        if (!label) {
            return badges;
        }

        badges.push({
            label,
            tone: 'red',
            title: payload.public_safe_message,
        });
    }

    return badges.filter((badge, index, values) => values.findIndex((item) => item.label === badge.label && item.tone === badge.tone) === index);
}

function liveProductInfoTone(payload = {}, state = null) {
    if (state?.status === 'error') {
        return 'pd-product-live-info--error';
    }

    if (state?.status === 'loading') {
        return '';
    }

    if (payload.ok) {
        return 'pd-product-live-info--ok';
    }

    return 'pd-product-live-info--warning';
}

function renderLiveProductInfoPanel(item = {}) {
    const stableKey = item._stable_key || '';
    const state = getLiveProductInfoState(stableKey);
    const hasSelection = itemHasLiveProductSelection(item);
    const safeState = state?.requestKey === liveProductInfoRequestKey(item) ? state : null;
    const payload = safeObject(safeState?.payload);
    const warnings = buildLiveProductInfoWarnings(payload);
    const toneClass = liveProductInfoTone(payload, safeState);
    const productId = firstFilledValue([
        item.tenant_catalog_product_id,
        item.selected_catalog_identity?.tenant_catalog_product_id,
    ], '');
    const variantId = firstFilledValue([
        item.tenant_catalog_product_variant_id,
        item.selected_catalog_identity?.tenant_catalog_product_variant_id,
    ], '');
    const quoteItemId = item.quote_item_id || '';
    const snapshotPrice = resolveLiveProductSnapshotPrice(item);
    const snapshotStock = resolveLiveProductSnapshotStock(item);
    const metaLine = buildCompactProductMetaLine(item, payload);
    const salesPanelHtml = renderSalesPresentationPanel(item, payload);
    let messageHtml = '';

    if (!hasSelection) {
        messageHtml = '<div class="pd-product-live-info__empty">Ürün seçildiğinde güncel stok ve fiyat bilgisi burada görünür.</div>';
    } else if (safeState?.status === 'error') {
        messageHtml = '<div class="pd-product-live-info__message is-danger">Canlı ürün bilgisi şu anda alınamadı.</div>';
    } else if (safeState?.status === 'success' && Object.keys(payload).length) {
        if (!payload.ok && payload.public_safe_message) {
            const tone = warnings.some((warning) => warning.tone === 'red') ? 'danger' : 'warning';
            messageHtml = `<div class="pd-product-live-info__message is-${tone}">${escapeHtml(payload.public_safe_message)}</div>`;
        }
    } else if (hasSelection) {
        messageHtml = '<div class="pd-product-live-info__message">Canlı ürün bilgisi güncelleniyor...</div>';
    }

    return `
    <div
        class="pd-product-live-info ${toneClass}"
        data-live-product-info-box
        data-live-product-info-endpoint="${escapeHtml(quoteWorkspace.liveProductInfoUrl || '')}"
        data-tenant-catalog-product-id="${escapeHtml(productId)}"
        data-tenant-catalog-product-variant-id="${escapeHtml(variantId)}"
        data-quote-item-id="${escapeHtml(quoteItemId)}"
        data-snapshot-price="${escapeHtml(snapshotPrice)}"
        data-snapshot-stock="${escapeHtml(snapshotStock)}"
    >
        ${metaLine ? `
        <div class="pd-product-live-info__meta-line">${escapeHtml(metaLine)}</div>
        ` : ''}
        ${salesPanelHtml}
        ${warnings.length ? `
        <div class="pd-product-live-info__chips">
            ${warnings.map((warning) => `<span class="pd-badge pd-badge-${escapeHtml(warning.tone || 'slate')}" title="${escapeHtml(warning.title || warning.label)}">${escapeHtml(warning.label)}</span>`).join('')}
        </div>
        ` : ''}
        ${messageHtml}
    </div>
    `;
}

function refreshLiveProductInfoPanel(stableKey = '') {
    const itemElement = findItemElementByStableKey(stableKey);
    const item = findCollectedItemByStableKey(stableKey);
    const currentPanel = itemElement?.querySelector('[data-live-product-info-box]');

    if (!itemElement || !item || !currentPanel) {
        return;
    }

    currentPanel.outerHTML = renderLiveProductInfoPanel(item);
}

function applyLiveProductQuotePricing(itemElement, payload = {}) {
    const itemIndex = Number(itemElement?.dataset.itemIndex ?? -1);
    const items = collectItems();
    const target = items[itemIndex];

    if (!target) {
        return false;
    }

    const priceSnapshot = safeObject(target.price_snapshot);
    const isManualUnitPrice = target.manual_unit_price === true
        || target.manual_unit_price === 1
        || target.manual_unit_price === '1'
        || priceSnapshot.manual_unit_price === true;
    const quoteValue = isManualUnitPrice ? payload.manual_quote_price_value : payload.quote_price_value;
    const quoteStatus = isManualUnitPrice ? payload.manual_quote_price_status : payload.quote_price_status;
    const quoteCurrency = isManualUnitPrice
        ? firstFilledValue([payload.manual_quote_currency, payload.quote_currency, currentQuoteCurrency()], currentQuoteCurrency())
        : firstFilledValue([payload.quote_currency, currentQuoteCurrency()], currentQuoteCurrency());

    target.quantity = formatInputNumber(firstFilledValue([target.quantity, 1], 1));
    target.price_snapshot = {
        ...priceSnapshot,
        ...safeObject(payload.quote_price_snapshot),
        quote_price_value: payload.quote_price_value ?? null,
        quote_currency: payload.quote_currency || quoteCurrency,
        quote_price_status: payload.quote_price_status || null,
        quote_price_reason_code: payload.quote_price_reason_code || null,
        quote_price_message: payload.quote_price_message || null,
        quote_price_snapshot: safeObject(payload.quote_price_snapshot),
        document_currency: quoteCurrency,
        currency: quoteCurrency,
    };

    if (payload.manual_quote_price_snapshot) {
        target.price_snapshot.manual_quote_price_snapshot = safeObject(payload.manual_quote_price_snapshot);
    }

    const operationalListPrice = Number(resolveOperationalListPriceValue(payload, target.price_snapshot) || 0);
    const hasSafeFallbackPrice = Number.isFinite(operationalListPrice) && operationalListPrice > 0;
    const needsBlocking = (!isReadyQuotePriceStatus(quoteStatus) && !hasSafeFallbackPrice)
        || (!hasSafeFallbackPrice && (quoteValue === null || quoteValue === undefined || quoteValue === ''));

    if (needsBlocking) {
        target._row_error = payload.quote_price_reason_code === 'canonical_quote_price_unavailable'
            ? quotePriceUnavailableMessage(payload)
            : quoteCurrencyWarningMessage();
        target._error_path = `items.${itemIndex}.price_snapshot`;
        target.price_snapshot.quote_price_status = quoteStatus || 'missing_rate';
        target.price_snapshot.manual_unit_price = isManualUnitPrice;
        target.price_snapshot.manual_sales_price_override = isManualUnitPrice;
        items[itemIndex] = target;
        setClientFormError('Teklif kaydedilemedi. 1 ürün/baskı satırında düzeltme gerekiyor.', [
            {
                path: `items.${itemIndex}.price_snapshot`,
                label: buildValidationSummaryLabel('item', itemIndex),
                message: target._row_error,
            },
        ]);
        mountItems(items);
        return true;
    }

    const quantity = Number(target.quantity || 0);
    const normalizedListPrice = operationalListPrice.toFixed(2);

    target.list_price = normalizedListPrice;
    target.discount_rate = target.discount_rate || '0';
    target.calculated_unit_price = Number(calculateItemUnitPrice({
        list_price: operationalListPrice,
        discount_rate: target.discount_rate,
    }) || 0).toFixed(2);
    const calculatedUnitPrice = Number(target.calculated_unit_price || 0);
    const finalUnitPrice = isManualUnitPrice
        ? Number(quoteValue || 0)
        : calculatedUnitPrice;

    target.unit_price = finalUnitPrice.toFixed(2);
    target.manual_unit_price = isManualUnitPrice;
    target.line_total = (finalUnitPrice * quantity).toFixed(2);
    target._row_error = payload.quote_price_reason_code === 'canonical_quote_price_unavailable'
        ? quotePriceUnavailableMessage(payload)
        : '';
    target._error_path = payload.quote_price_reason_code === 'canonical_quote_price_unavailable'
        ? `items.${itemIndex}.price_snapshot`
        : '';
    target.price_snapshot.list_price = operationalListPrice;
    target.price_snapshot.display_price = operationalListPrice;
    target.price_snapshot.calculated_unit_price = calculatedUnitPrice;
    target.price_snapshot.document_currency = quoteCurrency;
    target.price_snapshot.currency = quoteCurrency;
    target.price_snapshot.actual_sales_unit_price_document = Number(finalUnitPrice || 0);
    target.price_snapshot.manual_unit_price = isManualUnitPrice;
    target.price_snapshot.manual_sales_price_override = isManualUnitPrice;

    if (isManualUnitPrice) {
        target.price_snapshot.manual_entry_currency = quoteCurrency;
        target.price_snapshot.manual_entry_amount = Number(finalUnitPrice || 0);
    } else {
        target.price_snapshot.manual_entry_currency = null;
        target.price_snapshot.manual_entry_amount = null;
        target.price_snapshot.suggested_sales_unit_price_document = calculatedUnitPrice;
    }

    items[itemIndex] = target;
    if (!target._row_error) {
        clearClientFormError();
    }
    mountItems(items);
    return true;
}

async function repriceAllQuoteItems() {
    const items = collectItems().filter((item) => itemHasLiveProductSelection(item));

    for (const item of items) {
        await ensureLiveProductInfo(item);
    }
}

async function ensureLiveProductInfo(item = {}) {
    const stableKey = item._stable_key || '';
    const requestKey = liveProductInfoRequestKey(item);

    if (!stableKey) {
        return;
    }

    if (!requestKey) {
        setLiveProductInfoState(stableKey, {
            status: 'idle',
            requestKey: '',
            payload: null,
            error: null,
        });
        refreshLiveProductInfoPanel(stableKey);
        return;
    }

    const currentState = getLiveProductInfoState(stableKey);
    if (currentState?.requestKey === requestKey && ['loading', 'success', 'error'].includes(currentState.status)) {
        refreshLiveProductInfoPanel(stableKey);
        return;
    }

    setLiveProductInfoState(stableKey, {
        status: 'loading',
        requestKey,
        payload: null,
        error: null,
    });
    refreshLiveProductInfoPanel(stableKey);

    try {
        const response = await fetch(requestKey, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('live-product-info-failed');
        }

        const payload = await response.json();
        const latestState = getLiveProductInfoState(stableKey);

        if (!latestState || latestState.requestKey !== requestKey) {
            return;
        }

        setLiveProductInfoState(stableKey, {
            status: 'success',
            requestKey,
            payload,
            error: null,
        });

        const itemElement = findItemElementByStableKey(stableKey);
        if (itemElement) {
            applyLiveProductQuotePricing(itemElement, payload);
        }
    } catch (error) {
        const latestState = getLiveProductInfoState(stableKey);

        if (!latestState || latestState.requestKey !== requestKey) {
            return;
        }

        setLiveProductInfoState(stableKey, {
            status: 'error',
            requestKey,
            payload: null,
            error: error?.message || 'unknown-error',
        });
    }

    refreshLiveProductInfoPanel(stableKey);
}

function firstFilledValue(values, fallback = '') {
    for (const value of values) {
        if (value !== null && value !== undefined && String(value) !== '') {
            return value;
        }
    }

    return fallback;
}

function finiteNumber(value, fallback = 0) {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
}

function cloneJsonSafe(value) {
    try {
        return JSON.parse(JSON.stringify(value ?? null));
    } catch (error) {
        return null;
    }
}

function rememberCatalogEntry(entry) {
    const entryKey = `catalog-entry-${++catalogEntrySequence}`;
    catalogEntryStore.set(entryKey, cloneJsonSafe(entry) ?? entry);
    return entryKey;
}

function getCatalogEntry(entryKey) {
    if (!entryKey || !catalogEntryStore.has(entryKey)) {
        return null;
    }

    return cloneJsonSafe(catalogEntryStore.get(entryKey)) ?? catalogEntryStore.get(entryKey);
}

function setClientFormError(message = '', entries = []) {
    const box = document.getElementById('quote-client-error');
    const label = box?.querySelector('[data-client-error-message]');
    const list = box?.querySelector('[data-client-error-list]');

    if (!box || !label || !list) {
        return;
    }

    if (!message) {
        label.textContent = '';
        list.innerHTML = '';
        list.classList.add('hidden');
        box.classList.add('hidden');
        return;
    }

    label.textContent = message;
    const safeEntries = Array.isArray(entries) ? entries.filter((entry) => entry && entry.message) : [];

    if (safeEntries.length) {
        list.innerHTML = safeEntries.map((entry) => `
            <button type="button" class="block w-full text-left text-sm font-medium text-red-700 underline-offset-2 hover:underline" data-error-target="${escapeHtml(entry.path || '')}">
                ${escapeHtml(entry.label || 'Hatalı satır')} — ${escapeHtml(entry.message || '')}
            </button>
        `).join('');
        list.classList.remove('hidden');
    } else {
        list.innerHTML = '';
        list.classList.add('hidden');
    }

    box.classList.remove('hidden');
}

function clearClientFormError() {
    setClientFormError('');
}

function resolveValidationTarget(path = '') {
    if (!path) {
        return null;
    }

    return Array.from(document.querySelectorAll('[data-error-path]')).find((element) => (element.dataset.errorPath || '') === path) || null;
}

function focusValidationTarget(path = '') {
    const target = resolveValidationTarget(path);
    if (!target) {
        return false;
    }

    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const focusable = target.querySelector('input:not([type="hidden"]), select, textarea, button');
    if (typeof focusable?.focus === 'function') {
        focusable.focus({ preventScroll: true });
    }

    return true;
}

function buildValidationSummaryLabel(type, itemIndex, printIndex = null) {
    if (type === 'print' && printIndex !== null) {
        return `Ürün ${itemIndex + 1} / Baskı ${itemIndex + 1}${String.fromCharCode(97 + printIndex)}`;
    }

    return `Ürün ${itemIndex + 1}`;
}

function printSetupSelectionProvided(printRow = {}) {
    const setupStatus = String(printRow.setup_status || printRow.cliche_status || '').trim();
    if (setupStatus !== '') {
        return true;
    }

    if (String(printRow.setup_pricing_enabled || '') === '1') {
        return true;
    }

    return Number(printRow.setup_total_amount || 0) > 0 || Number(printRow.setup_unit_amount || 0) > 0;
}
function normalizeCatalogSelectionEntry(entry = {}, currentItem = {}) {
    const normalizedEntry = safeObject(entry);
    const currentProductSnapshot = safeObject(currentItem.product_snapshot);
    const currentPriceSnapshot = safeObject(currentItem.price_snapshot);
    const currentStockSnapshot = safeObject(currentItem.stock_snapshot);
    const entryProductSnapshot = safeObject(normalizedEntry.product_snapshot);
    const entryPriceSnapshot = safeObject(normalizedEntry.price_snapshot);
    const entryStockSnapshot = safeObject(normalizedEntry.stock_snapshot);
    const sourceSummary = Array.isArray(normalizedEntry.source_summary)
        ? normalizedEntry.source_summary
        : (Array.isArray(entryProductSnapshot.source_summary) ? entryProductSnapshot.source_summary : safeArray(normalizedEntry.source_summary));
    const fallbackListPrice = finiteNumber(firstFilledValue([
        currentItem.list_price,
        currentPriceSnapshot.list_price,
        currentPriceSnapshot.display_price,
        currentItem.unit_price,
        currentPriceSnapshot.calculated_unit_price,
    ], 0), 0);
    const selectedListPrice = finiteNumber(firstFilledValue([
        resolveOperationalListPriceValue(normalizedEntry, entryPriceSnapshot),
        normalizedEntry.list_price,
        normalizedEntry.display_price,
        normalizedEntry.sale_price,
        normalizedEntry.unit_price,
        normalizedEntry.price_value,
        entryPriceSnapshot.list_price,
        entryPriceSnapshot.display_price,
        entryPriceSnapshot.sale_price,
        entryPriceSnapshot.unit_price,
    ], fallbackListPrice), fallbackListPrice);
    const selectedCatalogIdentity = {
        catalog_source: firstFilledValue([
            normalizedEntry.catalog_source,
            currentItem.catalog_source,
            currentProductSnapshot.catalog_source,
            'tenant_catalog',
        ], 'tenant_catalog'),
        tenant_catalog_product_id: firstFilledValue([
            normalizedEntry.tenant_catalog_product_id,
            normalizedEntry.product_id,
            normalizedEntry.id,
            currentItem.tenant_catalog_product_id,
            currentProductSnapshot.tenant_catalog_product_id,
        ], ''),
        tenant_catalog_product_variant_id: firstFilledValue([
            normalizedEntry.tenant_catalog_product_variant_id,
            normalizedEntry.product_variant_id,
            normalizedEntry.variant_id,
            currentItem.tenant_catalog_product_variant_id,
            currentProductSnapshot.tenant_catalog_product_variant_id,
        ], ''),
        standard_product_id: firstFilledValue([
            entryProductSnapshot.standard_product_id,
            normalizedEntry.standard_product_id,
            currentItem.standard_product_id,
            currentProductSnapshot.standard_product_id,
        ], ''),
        standard_product_variant_id: firstFilledValue([
            entryProductSnapshot.standard_product_variant_id,
            normalizedEntry.standard_product_variant_id,
            currentItem.standard_product_variant_id,
            currentProductSnapshot.standard_product_variant_id,
        ], ''),
        product_code: firstFilledValue([
            normalizedEntry.product_code,
            entryProductSnapshot.product_code,
            currentItem.product_code,
            currentProductSnapshot.product_code,
        ], ''),
        product_name: firstFilledValue([
            normalizedEntry.product_name,
            entryProductSnapshot.product_name,
            currentItem.product_name,
            currentProductSnapshot.product_name,
        ], ''),
        is_warning_sellable: !!firstFilledValue([
            normalizedEntry.is_warning_sellable,
            entryProductSnapshot.is_warning_sellable,
            currentProductSnapshot.is_warning_sellable,
            false,
        ], false),
        warning_tone: firstFilledValue([
            normalizedEntry.warning_tone,
            entryProductSnapshot.warning_tone,
            currentProductSnapshot.warning_tone,
        ], ''),
        warning_summary: firstFilledValue([
            normalizedEntry.warning_summary,
            entryProductSnapshot.warning_summary,
            currentProductSnapshot.warning_summary,
        ], ''),
    };

    return {
        entry: normalizedEntry,
        sourceSummary,
        tenant_catalog_product_id: firstFilledValue([
            normalizedEntry.tenant_catalog_product_id,
            normalizedEntry.product_id,
            normalizedEntry.id,
            currentItem.tenant_catalog_product_id,
        ], ''),
        tenant_catalog_product_variant_id: firstFilledValue([
            normalizedEntry.tenant_catalog_product_variant_id,
            normalizedEntry.product_variant_id,
            normalizedEntry.variant_id,
            currentItem.tenant_catalog_product_variant_id,
        ], ''),
        standard_product_id: firstFilledValue([
            entryProductSnapshot.standard_product_id,
            normalizedEntry.standard_product_id,
            currentItem.standard_product_id,
            currentProductSnapshot.standard_product_id,
        ], ''),
        standard_product_variant_id: firstFilledValue([
            entryProductSnapshot.standard_product_variant_id,
            normalizedEntry.standard_product_variant_id,
            currentItem.standard_product_variant_id,
            currentProductSnapshot.standard_product_variant_id,
        ], ''),
        product_code: firstFilledValue([
            normalizedEntry.product_code,
            normalizedEntry.code,
            normalizedEntry.urun_kodu,
            normalizedEntry.supplier_product_code,
            entryProductSnapshot.product_code,
            currentItem.product_code,
            currentProductSnapshot.product_code,
        ], ''),
        product_name: firstFilledValue([
            normalizedEntry.product_name,
            normalizedEntry.name,
            normalizedEntry.urun_adi,
            entryProductSnapshot.product_name,
            currentItem.product_name,
            currentProductSnapshot.product_name,
        ], ''),
        list_price: selectedListPrice,
        visible_stock_quantity: finiteNumber(firstFilledValue([
            normalizedEntry.visible_stock_quantity,
            entryStockSnapshot.visible_stock_quantity,
            currentStockSnapshot.visible_stock_quantity,
        ], 0), 0),
        local_stock_quantity: finiteNumber(firstFilledValue([
            normalizedEntry.local_stock_quantity,
            entryStockSnapshot.local_stock_quantity,
            currentStockSnapshot.local_stock_quantity,
        ], 0), 0),
        supplier_stock_quantity: finiteNumber(firstFilledValue([
            normalizedEntry.supplier_stock_quantity,
            entryStockSnapshot.supplier_stock_quantity,
            currentStockSnapshot.supplier_stock_quantity,
        ], 0), 0),
        total_stock_quantity: finiteNumber(firstFilledValue([
            normalizedEntry.total_stock_quantity,
            entryStockSnapshot.total_stock_quantity,
            currentStockSnapshot.total_stock_quantity,
        ], 0), 0),
        safe_stock_quantity: finiteNumber(firstFilledValue([
            normalizedEntry.safe_stock_quantity,
            entryStockSnapshot.safe_stock_quantity,
            currentStockSnapshot.safe_stock_quantity,
        ], 0), 0),
        vat_rate: firstFilledValue([
            normalizedEntry.vat_rate,
            entryPriceSnapshot.vat_rate,
            currentPriceSnapshot.vat_rate,
            currentItem.vat_rate,
            20,
        ], 20),
        currency: firstFilledValue([
            normalizedEntry.quote_currency,
            normalizedEntry.currency,
            entryPriceSnapshot.quote_currency,
            entryPriceSnapshot.currency,
            currentPriceSnapshot.document_currency,
            currentPriceSnapshot.currency,
            quoteWorkspace.currency,
            'TRY',
        ], 'TRY'),
        catalog_source: normalizedEntry.catalog_source === 'local_product' ? 'local_product' : (currentItem.catalog_source || 'tenant_catalog'),
        product_snapshot: entryProductSnapshot,
        price_snapshot: entryPriceSnapshot,
        stock_snapshot: entryStockSnapshot,
        warning_badges: resolveWarningBadges(normalizedEntry),
        warning_messages: resolveWarningMessages(normalizedEntry),
        local_stock_priority: !!firstFilledValue([
            normalizedEntry.local_stock_priority,
            entryStockSnapshot.local_stock_priority,
            currentStockSnapshot.local_stock_priority,
            false,
        ], false),
        supplier_name: firstFilledValue([
            normalizedEntry.supplier_name,
            entryProductSnapshot.supplier_name,
            currentProductSnapshot.supplier_name,
        ], null),
        category_name: firstFilledValue([
            normalizedEntry.category_name,
            entryProductSnapshot.category_name,
            currentProductSnapshot.category_name,
        ], null),
        visible_in_catalog: firstFilledValue([
            entryProductSnapshot.visible_in_catalog,
            currentProductSnapshot.visible_in_catalog,
            true,
        ], true),
        visible_in_quote: firstFilledValue([
            normalizedEntry.visible_in_quote,
            entryProductSnapshot.visible_in_quote,
            currentProductSnapshot.visible_in_quote,
            true,
        ], true),
        image_url: firstFilledValue([
            normalizedEntry.image_url,
            entryProductSnapshot.image_url,
            currentProductSnapshot.image_url,
        ], null),
        net_price_warning: !!firstFilledValue([
            normalizedEntry.net_price_warning,
            entryPriceSnapshot.net_price_warning,
            currentPriceSnapshot.net_price_warning,
            false,
        ], false),
        price_policy_warning: !!firstFilledValue([
            normalizedEntry.price_policy_warning,
            entryPriceSnapshot.price_policy_warning,
            currentPriceSnapshot.price_policy_warning,
            false,
        ], false),
        pricing_policy_type: firstFilledValue([
            normalizedEntry.pricing_policy_type,
            entryPriceSnapshot.pricing_policy_type,
            currentPriceSnapshot.pricing_policy_type,
        ], null),
        supplier_warning_flag: !!firstFilledValue([
            normalizedEntry.supplier_warning_flag,
            entryPriceSnapshot.supplier_warning_flag,
            currentPriceSnapshot.supplier_warning_flag,
            false,
        ], false),
        supplier_warning_type: firstFilledValue([
            normalizedEntry.supplier_warning_type,
            entryPriceSnapshot.supplier_warning_type,
            currentPriceSnapshot.supplier_warning_type,
        ], null),
        quote_price_value: firstFilledValue([
            normalizedEntry.quote_price_value,
            entryPriceSnapshot.quote_price_value,
            selectedListPrice,
        ], null),
        quote_currency: firstFilledValue([
            normalizedEntry.quote_currency,
            entryPriceSnapshot.quote_currency,
            normalizedEntry.currency,
            entryPriceSnapshot.currency,
            currentQuoteCurrency(),
        ], currentQuoteCurrency()),
        quote_price_status: firstFilledValue([
            normalizedEntry.quote_price_status,
            entryPriceSnapshot.quote_price_status,
            currentPriceSnapshot.quote_price_status,
            'not_required',
        ], 'not_required'),
        quote_price_snapshot: safeObject(firstFilledValue([
            normalizedEntry.quote_price_snapshot,
            entryPriceSnapshot.quote_price_snapshot,
            currentPriceSnapshot.quote_price_snapshot,
            {},
        ], {})),
        is_warning_sellable: selectedCatalogIdentity.is_warning_sellable,
        warning_tone: selectedCatalogIdentity.warning_tone,
        warning_summary: selectedCatalogIdentity.warning_summary,
        selected_catalog_identity: selectedCatalogIdentity,
    };
}

function badgeHtml(text, tone = 'slate') {
    return `<span class="pd-badge pd-badge-${tone}">${escapeHtml(text)}</span>`;
}

function optionsHtml(options, currentValue, placeholder = 'Seçiniz') {
    return [`<option value="">${escapeHtml(placeholder)}</option>`]
        .concat(options.map((option) => {
            const value = typeof option === 'object' ? option.id : option;
            const label = typeof option === 'object' ? option.name : option;
            return `<option value="${escapeHtml(value)}" ${String(currentValue ?? '') === String(value) ? 'selected' : ''}>${escapeHtml(label)}</option>`;
        }))
        .join('');
}

function printSelectorValue(printRow = {}) {
    if (printRow.tenant_print_setting_id) {
        return `setting:${printRow.tenant_print_setting_id}`;
    }

    return printRow.print_type ? `legacy:${printRow.print_type}` : '';
}

function resolveTenantPrintSetting(printRow = {}) {
    if (!printRow.tenant_print_setting_id) {
        return null;
    }

    return tenantPrintSettingsById.get(String(printRow.tenant_print_setting_id)) || null;
}

function currentPrintSettingOrLegacyName(printRow = {}) {
    const setting = resolveTenantPrintSetting(printRow);
    return setting?.standard_name || printRow.print_type || '';
}

function resolveSettingPrintOptions(setting = null) {
    return Array.isArray(setting?.options) ? setting.options : [];
}

function resolveSelectedPrintOption(setting = null, printRow = {}, optionLabel = null) {
    const options = resolveSettingPrintOptions(setting);
    const optionId = String(printRow.tenant_print_option_id || '');
    const targetLabel = String((optionLabel ?? printRow.print_option) || '').trim();

    if (optionId) {
        const byId = options.find((option) => String(option.id) === optionId);
        if (byId) {
            return byId;
        }
    }

    if (targetLabel !== '') {
        return options.find((option) => String(option.name || '').trim() === targetLabel) || null;
    }

    return null;
}

function buildPrintOptionChoices(setting = null, printRow = {}) {
    const dbOptions = resolveSettingPrintOptions(setting);
    const choices = dbOptions.map((option) => option.name);
    const currentLabel = String(printRow.print_option || '').trim();

    if (currentLabel && !choices.includes(currentLabel)) {
        choices.push(currentLabel);
    }

    if (choices.length) {
        return choices;
    }

    return printOptionsForType(setting?.standard_name || printRow.print_type || '');
}

function buildPrintTypeOptions(printRow = {}) {
    const options = tenantPrintSettings.map((setting) => ({
        id: `setting:${setting.id}`,
        name: setting.display_name,
    }));
    const currentSelectorValue = printSelectorValue(printRow);
    const hasCurrentValue = options.some((option) => option.id === currentSelectorValue);

    if (!hasCurrentValue && printRow.print_type) {
        options.push({
            id: `legacy:${printRow.print_type}`,
            name: `${printRow.print_type} (Eski Kayıt)`,
        });
    }

    if (!options.length) {
        return legacyPrintTypeOptions.map((option) => ({
            id: `legacy:${option}`,
            name: option,
        }));
    }

    return options;
}

function normalizePrint(printRow = {}, index = 0) {
    const resolvedType = printRow.print_type || '';
    const resolvedSettingId = printRow.tenant_print_setting_id || '';
    const resolvedStandardId = printRow.standard_print_type_id || '';
    const setting = resolvedSettingId ? tenantPrintSettingsById.get(String(resolvedSettingId)) : null;
    const selectedOption = resolveSelectedPrintOption(setting, printRow);
    const optionRequiresSetup = !!selectedOption?.requires_setup;
    const optionSetupType = selectedOption?.setup_type || '';
    const resolvedSetupTypes = Array.isArray(printRow.setup_types)
        ? printRow.setup_types
        : (optionSetupType ? [optionSetupType] : (setting?.setup_types || []));
    const resolvedSetupStatus = quoteWorkspace.intermediateElementEnabled ? (printRow.setup_status || printRow.cliche_status || selectedOption?.setup_status_default || '') : '';
    const resolvedSetupType = quoteWorkspace.intermediateElementEnabled ? (printRow.setup_type || optionSetupType || resolvedSetupTypes[0] || '') : '';
    const resolvedBasePrintUnitPrice = printRow.base_print_unit_price ?? printRow.print_unit_price ?? '';
    const resolvedSetupTotalAmount = quoteWorkspace.intermediateElementEnabled ? (printRow.setup_total_amount ?? '') : '';
    const resolvedSetupDistributionQuantity = quoteWorkspace.intermediateElementEnabled ? (printRow.setup_distribution_quantity ?? printRow.print_quantity ?? '') : '';
    const resolvedSetupUnitAmount = quoteWorkspace.intermediateElementEnabled ? (printRow.setup_unit_amount ?? '') : '';
    const resolvedSetupPricingEnabled = quoteWorkspace.intermediateElementEnabled && (printRow.setup_pricing_enabled === true
        || printRow.setup_pricing_enabled === 1
        || printRow.setup_pricing_enabled === '1'
        || resolvedSetupStatus === 'Yeni üretilecek'
        || Number(resolvedSetupTotalAmount || 0) > 0);

    return {
        _stable_key: printRow._stable_key || printRow.stable_key || printRow.print_key || `print-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`,
        tenant_print_setting_id: resolvedSettingId,
        standard_print_type_id: resolvedStandardId || setting?.standard_print_type_id || '',
        tenant_print_option_id: printRow.tenant_print_option_id || selectedOption?.id || '',
        print_type: resolvedType,
        print_option: printRow.print_option || selectedOption?.name || '',
        production_type: printRow.production_type || '',
        subcontractor_company_id: printRow.subcontractor_company_id || '',
        cliche_status: quoteWorkspace.intermediateElementEnabled ? (printRow.cliche_status || '') : '',
        setup_pricing_enabled: resolvedSetupPricingEnabled,
        setup_type: resolvedSetupType,
        setup_status: resolvedSetupStatus,
        setup_total_amount: formatInputNumber(resolvedSetupTotalAmount),
        setup_distribution_quantity: formatInputNumber(resolvedSetupDistributionQuantity),
        setup_unit_amount: formatInputNumber(resolvedSetupUnitAmount),
        base_print_unit_price: formatInputNumber(resolvedBasePrintUnitPrice),
        print_quantity: formatInputNumber(printRow.print_quantity ?? ''),
        print_unit_price: formatInputNumber(printRow.print_unit_price ?? ''),
        print_total: formatInputNumber(printRow.print_total ?? ''),
        note: printRow.note || '',
        print_vat_rate: printRow.print_vat_rate ?? quoteWorkspace.defaultPrintVatRate ?? 20,
        requires_setup: quoteWorkspace.intermediateElementEnabled && (printRow.requires_setup === true || printRow.requires_setup === 1 || printRow.requires_setup === '1' || optionRequiresSetup || !!setting?.requires_setup),
        setup_types: quoteWorkspace.intermediateElementEnabled ? resolvedSetupTypes : [],
        option_requires_setup: optionRequiresSetup,
        option_setup_type: optionSetupType,
        option_setup_status_default: selectedOption?.setup_status_default || '',
        option_default_unit_price: selectedOption?.default_unit_price ?? '',
        _manual_quantity: !!printRow._manual_quantity,
        _price_suggested: !!printRow._price_suggested,
        _row_error: printRow._row_error || '',
        _error_path: printRow._error_path || '',
        _index: index,
    };
}

function defaultItem() {
    return {
        _stable_key: `item-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`,
        product_name: '',
        product_code: '',
        quantity: '',
        unit: 'Adet',
        list_price: '',
        discount_rate: '0.00',
        unit_price: '',
        line_total: '',
        description: '',
        has_print: false,
        prints: [],
        manual_unit_price: false,
        calculated_unit_price: '',
        tenant_catalog_product_id: '',
        tenant_catalog_product_variant_id: '',
        standard_product_id: '',
        standard_product_variant_id: '',
        supplier_id: '',
        supplier_source_id: '',
        catalog_source: 'tenant_catalog',
        quote_item_id: '',
        product_snapshot: null,
        price_snapshot: null,
        stock_snapshot: null,
        selected_catalog_identity: null,
        print_vat_rate: quoteWorkspace.defaultPrintVatRate || 20,
        _row_error: '',
        _error_path: '',
    };
}

function normalizeItem(item = {}, index = 0) {
    const productSnapshot = parseJsonValue(item.product_snapshot);
    const priceSnapshot = parseJsonValue(item.price_snapshot);
    const stockSnapshot = parseJsonValue(item.stock_snapshot);
    const selectedCatalogIdentity = safeObject(item.selected_catalog_identity);
    const warningBadges = [
        ...(productSnapshot?.warning_badges || []),
        ...(priceSnapshot?.warning_badges || []),
    ].filter(Boolean);
    const warningMessages = [
        ...(productSnapshot?.warning_messages || []),
        ...(priceSnapshot?.warning_messages || []),
    ].filter(Boolean);
    const hasPrint = item.has_print === true || item.has_print === 1 || item.has_print === '1';
    const prints = Array.isArray(item.prints) && item.prints.length
        ? item.prints.map((printRow, printIndex) => normalizePrint(printRow, printIndex))
        : (hasPrint ? [createDefaultPrintForItem(item, 0)] : []);

    return {
        ...defaultItem(),
        ...item,
        _index: index,
        _stable_key: item._stable_key || item.stable_key || defaultItem()._stable_key,
        has_print: hasPrint,
        prints,
        product_snapshot: productSnapshot,
        price_snapshot: priceSnapshot,
        stock_snapshot: stockSnapshot,
        quantity: formatInputNumber(item.quantity ?? ''),
        list_price: formatInputNumber(item.list_price ?? ''),
        discount_rate: formatInputNumber(item.discount_rate ?? 0),
        unit_price: formatInputNumber(item.unit_price ?? ''),
        line_total: formatInputNumber(item.line_total ?? ''),
        quote_item_id: item.quote_item_id || '',
        manual_unit_price: item.manual_unit_price === true || item.manual_unit_price === 1 || item.manual_unit_price === '1' || !!priceSnapshot?.manual_unit_price,
        calculated_unit_price: formatInputNumber(item.calculated_unit_price ?? priceSnapshot?.calculated_unit_price ?? ''),
        warning_badges: [...new Set(warningBadges)],
        warning_messages: [...new Set(warningMessages)],
        selected_catalog_identity: Object.keys(selectedCatalogIdentity).length ? selectedCatalogIdentity : {
            catalog_source: item.catalog_source || 'tenant_catalog',
            tenant_catalog_product_id: item.tenant_catalog_product_id || productSnapshot?.tenant_catalog_product_id || '',
            tenant_catalog_product_variant_id: item.tenant_catalog_product_variant_id || productSnapshot?.tenant_catalog_product_variant_id || '',
            standard_product_id: item.standard_product_id || productSnapshot?.standard_product_id || '',
            standard_product_variant_id: item.standard_product_variant_id || productSnapshot?.standard_product_variant_id || '',
            product_code: item.product_code || productSnapshot?.product_code || '',
            product_name: item.product_name || productSnapshot?.product_name || '',
            is_warning_sellable: !!(productSnapshot?.is_warning_sellable),
            warning_tone: productSnapshot?.warning_tone || '',
            warning_summary: productSnapshot?.warning_summary || '',
        },
        _row_error: item._row_error || '',
        _error_path: item._error_path || '',
    };
}

function invoiceStatusValue() {
    return document.getElementById('invoice-status-select')?.value || quoteWorkspace.invoiceStatus || 'fis';
}

function invoiceStatusLabel(status) {
    return status === 'fatura' ? 'Fatura' : 'Fiş';
}

function vatModeLabel(mode) {
    return mode === 'taxable' ? 'KDV hesaplanır' : 'KDV yok';
}

function quoteLineWarningBadges(item) {
    const rawBadges = item.warning_badges || [];

    return rawBadges.includes('Kırmızı Ürün')
        ? [{ text: 'Kırmızı Ürün', tone: 'red' }]
        : [];
}

function quoteWarningMeta(item) {
    const rawBadges = item.warning_badges || [];
    return {
        hasSupplierWarning: rawBadges.includes('Kırmızı Ürün'),
        hasNetWarning: rawBadges.includes('Net fiyat uyarısı'),
        hasStockWarning: rawBadges.includes('Stok yok'),
    };
}

function printRowCode(itemIndex, printIndex) {
    let offset = Number(printIndex) || 0;
    let suffix = '';

    do {
        suffix = String.fromCharCode(97 + (offset % 26)) + suffix;
        offset = Math.floor(offset / 26) - 1;
    } while (offset >= 0);

    return `${itemIndex + 1}${suffix}`;
}

function debugNow() {
    return new Date().toLocaleTimeString('tr-TR', { hour12: false });
}

function ensureQuotePrintDebugItem(itemIndex, stableKey = '') {
    const key = String(itemIndex);
    if (!quotePrintDebugState.items.has(key)) {
        quotePrintDebugState.items.set(key, {
            itemIndex,
            stableKey,
            lastClickAt: '',
            lastClickReason: '',
            lastAddBefore: null,
            lastAddAfter: null,
            lastMountCount: null,
            lastCollectCount: null,
            lastDomCount: null,
            lastError: '',
        });
    }

    const state = quotePrintDebugState.items.get(key);
    if (stableKey) {
        state.stableKey = stableKey;
    }

    return state;
}

function countDomPrintRows(itemIndex) {
    const itemElement = document.querySelector(`.pd-quote-item[data-item-index="${itemIndex}"]`);
    if (!itemElement) {
        return 0;
    }

    return itemElement.querySelectorAll('[data-print-list] .pd-print-operation').length;
}

function quotePrintDebugLog(step, payload = {}) {
    if (!quotePrintDebugEnabled) {
        return;
    }

    console.info(`[quote-print-debug] ${step}`, payload);
}

function collectQuotePrintDebugSnapshot(reason = 'snapshot') {
    const items = collectItems();

    return items.map((item, itemIndex) => {
        const debugItem = ensureQuotePrintDebugItem(itemIndex, item._stable_key || '');
        const itemElement = document.querySelector(`.pd-quote-item[data-item-index="${itemIndex}"]`);
        const domRows = itemElement ? Array.from(itemElement.querySelectorAll('[data-print-list] .pd-print-operation')) : [];

        return {
            itemIndex,
            stableKey: item._stable_key || '',
            hasPrint: !!item.has_print,
            printsLength: Array.isArray(item.prints) ? item.prints.length : 0,
            domRowCount: domRows.length,
            lastClickAt: debugItem.lastClickAt || '',
            lastClickReason: debugItem.lastClickReason || '',
            lastAddBefore: debugItem.lastAddBefore,
            lastAddAfter: debugItem.lastAddAfter,
            lastMountCount: debugItem.lastMountCount,
            lastCollectCount: debugItem.lastCollectCount,
            lastDomCount: debugItem.lastDomCount,
            lastError: debugItem.lastError || '',
            reason,
            rows: (Array.isArray(item.prints) ? item.prints : []).map((printRow, printIndex) => {
                const rowElement = domRows[printIndex] || null;
                const rowStyle = rowElement ? window.getComputedStyle(rowElement) : null;
                return {
                    printIndex,
                    label: printRowCode(itemIndex, printIndex),
                    stableKey: printRow._stable_key || rowElement?.dataset.printKey || '',
                    tenantPrintSettingId: printRow.tenant_print_setting_id || '',
                    tenantPrintOptionId: printRow.tenant_print_option_id || '',
                    printOption: printRow.print_option || '',
                    setupPricingEnabled: !!printRow.setup_pricing_enabled,
                    setupType: printRow.setup_type || '',
                    setupStatus: printRow.setup_status || printRow.cliche_status || '',
                    domPresent: !!rowElement,
                    domVisible: !!(rowElement && rowStyle && rowStyle.display !== 'none' && rowStyle.visibility !== 'hidden'),
                };
            }),
        };
    });
}

function renderQuotePrintDebugPanel(reason = 'refresh') {
    if (!quotePrintDebugEnabled) {
        return;
    }

    const panel = document.getElementById('quote-print-debug-panel');
    const body = document.getElementById('quote-print-debug-body');
    if (!panel || !body) {
        return;
    }

    const snapshot = collectQuotePrintDebugSnapshot(reason);
    panel.classList.remove('hidden');
    body.innerHTML = snapshot.map((item) => `
        <div class="pd-quote-print-debug-item">
            <div class="text-xs font-semibold text-slate-900">Item ${item.itemIndex} · ${escapeHtml(item.stableKey || '-')}</div>
            <div class="pd-quote-print-debug-metrics">
                <div class="pd-quote-print-debug-metric"><strong>Durum</strong>has_print: ${escapeHtml(String(item.hasPrint))}<br>prints.length: ${escapeHtml(String(item.printsLength))}<br>DOM rows: ${escapeHtml(String(item.domRowCount))}</div>
                <div class="pd-quote-print-debug-metric"><strong>Son Add</strong>saat: ${escapeHtml(item.lastClickAt || '-')}<br>önce: ${escapeHtml(String(item.lastAddBefore ?? '-'))}<br>sonra: ${escapeHtml(String(item.lastAddAfter ?? '-'))}</div>
                <div class="pd-quote-print-debug-metric"><strong>Mount / Collect</strong>mount: ${escapeHtml(String(item.lastMountCount ?? '-'))}<br>collect: ${escapeHtml(String(item.lastCollectCount ?? '-'))}<br>DOM sayım: ${escapeHtml(String(item.lastDomCount ?? '-'))}</div>
            </div>
            ${item.lastError ? `<div class="mt-2 text-xs font-medium text-red-700">${escapeHtml(item.lastError)}</div>` : ''}
            <div class="pd-quote-print-debug-row-list">
                ${item.rows.map((row) => `
                    <div class="pd-quote-print-debug-row">
                        <strong>${escapeHtml(row.label)}</strong><br>
                        key: ${escapeHtml(row.stableKey || '-')} · setting: ${escapeHtml(String(row.tenantPrintSettingId || '-'))} · optionId: ${escapeHtml(String(row.tenantPrintOptionId || '-'))}<br>
                        option: ${escapeHtml(row.printOption || '-')} · setup: ${escapeHtml(String(row.setupPricingEnabled))} · type: ${escapeHtml(row.setupType || '-')} · status: ${escapeHtml(row.setupStatus || '-')}<br>
                        dom: ${escapeHtml(String(row.domPresent))} · visible: ${escapeHtml(String(row.domVisible))}
                    </div>
                `).join('')}
            </div>
        </div>
    `).join('');
}

function printRequiresCliche(printType, requiresSetup = false, setupTypes = []) {
    if (requiresSetup && Array.isArray(setupTypes) && setupTypes.includes('cliche')) {
        return true;
    }

    return clicheRequiredTypes.includes(printType || '');
}

function setupStatusRequiresAmount(status = '') {
    return String(status || '') === 'Yeni üretilecek';
}

function setupTypeLabel(printRow = {}) {
    return printRow.setup_type || (Array.isArray(printRow.setup_types) && printRow.setup_types.length ? printRow.setup_types[0] : 'Ara Eleman');
}

function setupTypeTitle(printRow = {}) {
    const type = String(setupTypeLabel(printRow) || '').trim();

    if (!type) {
        return 'Ara Eleman';
    }

    const normalized = type
        .replaceAll('_', ' ')
        .toLowerCase()
        .trim();

    const explicitLabels = {
        cliche: 'Klişe',
        'klişe': 'Klişe',
        mold: 'Kalıp',
        kalip: 'Kalıp',
        'kalıp': 'Kalıp',
        film: 'Film',
        apparatus: 'Aparat',
        aparat: 'Aparat',
        bicak: 'Bıçak',
        sablon: 'Åablon',
        'varak kalibi': 'Varak kalıbı',
        'lazer sablonu': 'Lazer şablonu',
    };

    if (explicitLabels[normalized]) {
        return explicitLabels[normalized];
    }

    return 'Ara Eleman';
}

function shouldShowSetupPricingBox(printRow = {}) {
    return setupStatusRequiresAmount(printRow.setup_status || printRow.cliche_status || '')
        || Number(printRow.setup_total_amount || 0) > 0
        || Number(printRow.setup_unit_amount || 0) > 0;
}

function printRowHasMeaningfulData(printRow = {}) {
    if (!printRow || typeof printRow !== 'object') {
        return false;
    }

    return [
        printRow.tenant_print_setting_id,
        printRow.tenant_print_option_id,
        printRow.print_type,
        printRow.print_option,
        printRow.print_quantity,
        printRow.print_unit_price,
        printRow.print_total,
        printRow.note,
        printRow.cliche_status,
        printRow.setup_status,
        printRow.setup_total_amount,
        printRow.setup_unit_amount,
        printRow.base_print_unit_price,
    ].some((value) => String(value ?? '').trim() !== '' && String(value ?? '').trim() !== '0' && String(value ?? '').trim() !== '0.00');
}

function itemHasMeaningfulPrintData(item = {}) {
    return Array.isArray(item?.prints) && item.prints.some((printRow) => printRowHasMeaningfulData(printRow));
}

function defaultPrintQuantityForItem(item = {}) {
    return item?.quantity || '';
}

function createDefaultPrintForItem(item = {}, index = 0, printRow = {}) {
    return normalizePrint({
        print_quantity: defaultPrintQuantityForItem(item),
        ...printRow,
    }, index);
}

function ensureItemHasFirstPrintRow(item = {}) {
    if (!item) {
        return item;
    }

    item.prints = Array.isArray(item.prints) ? item.prints : [];
    item.has_print = true;

    if (!item.prints.length) {
        item.prints.push(createDefaultPrintForItem(item, 0));
    }

    return item;
}

function setupSummaryAmountLabel(printRow = {}) {
    if (!shouldShowSetupPricingBox(printRow)) {
        return '';
    }

    return formatMoney(Number(printRow.setup_total_amount || 0), currentQuoteCurrency()) || '';
}

function calculateSetupDistribution(basePrintUnitPrice, setupTotalAmount, printQuantity) {
    const safeBase = Number(basePrintUnitPrice || 0);
    const safeSetupTotal = Number(setupTotalAmount || 0);
    const safeQuantity = Number(printQuantity || 0);
    const setupUnitAmount = safeQuantity > 0
        ? Number((safeSetupTotal / safeQuantity).toFixed(4))
        : 0;
    const finalPrintUnitPrice = Number((safeBase + setupUnitAmount).toFixed(4));
    const finalPrintTotal = Number((finalPrintUnitPrice * safeQuantity).toFixed(4));

    return {
        setupUnitAmount,
        finalPrintUnitPrice,
        finalPrintTotal,
    };
}

function setSetupModalDisplayValue(elements, value) {
    Array.from(elements || []).forEach((element) => {
        if (!element) {
            return;
        }

        if (['INPUT', 'SELECT', 'TEXTAREA'].includes(element.tagName)) {
            element.value = value;
            return;
        }

        element.textContent = value;
    });
}

function printOptionsForType(printType) {
    return printOptionMap[printType] || ['Diğer'];
}

function calculateItemUnitPrice(item) {
    const listPrice = Number(item.list_price || 0);
    const discountRate = Number(item.discount_rate || 0);
    return Number((listPrice * (1 - (discountRate / 100))).toFixed(2));
}

function currentQuoteCurrency() {
    return document.querySelector('select[name="currency"]')?.value || quoteWorkspace.currency || 'TRY';
}

function currentQuoteDate() {
    return document.getElementById('quote-date-input')?.value || quoteWorkspace.quoteDate || new Date().toISOString().slice(0, 10);
}

function isReadyQuotePriceStatus(status = '') {
    return ['converted', 'stale_rate', 'ready', 'not_required'].includes(String(status || ''));
}

function quoteCurrencyWarningMessage() {
    return 'Güncel kur bulunamadı. Kur bilgilerini kontrol edin.';
}

function quotePriceUnavailableMessage(payload = {}) {
    return String(payload.quote_price_message || 'Ürün satış fiyatı teklif için hazırlanamadı.');
}

function resolvePrintSettingCurrency(setting = null) {
    if (!setting || setting.default_currency === undefined || setting.default_currency === null || setting.default_currency === '') {
        return '';
    }

    return String(setting.default_currency).toUpperCase();
}

function resolveQuoteCurrencyCode() {
    const currency = currentQuoteCurrency();

    if (currency === 'TL') {
        return 'TRY';
    }

    return String(currency || '').toUpperCase();
}

function printCurrencyWarningMessage(setting = null) {
    if (!quoteWorkspace.canViewFinancialData || !setting) {
        return '';
    }

    const settingCurrency = resolvePrintSettingCurrency(setting);
    const quoteCurrency = resolveQuoteCurrencyCode();

    if (!settingCurrency || !quoteCurrency || settingCurrency === quoteCurrency) {
        return '';
    }

    return 'Baski fiyati varsayilan para birimi farkli olabilir.';
}

function refreshPrintCurrencyWarning(printOperation, setting = null) {
    const warningElement = printOperation?.querySelector('[data-print-price-currency-warning]');
    if (!warningElement) {
        return;
    }

    const warningMessage = printCurrencyWarningMessage(setting);
    warningElement.textContent = warningMessage;
    warningElement.classList.toggle('hidden', !warningMessage);
}

function refreshAllPrintCurrencyWarnings() {
    document.querySelectorAll('.pd-print-operation').forEach((printOperation) => {
        const settingId = printOperation.querySelector('[data-print-setting-id]')?.value || '';
        const setting = settingId ? tenantPrintSettingsById.get(String(settingId)) : null;
        refreshPrintCurrencyWarning(printOperation, setting || null);
    });
}

function applyTenantPrintSettingPriceSuggestion(printOperation, setting = null) {
    if (!quoteWorkspace.canViewFinancialData || !printOperation || !setting) {
        return;
    }

    const unitPriceInput = printOperation.querySelector('input[name*="[print_unit_price]"]');
    const basePriceInput = printOperation.querySelector('input[name*="[base_print_unit_price]"]');
    if (!unitPriceInput) {
        return;
    }

    const hasSuggestedPrice = unitPriceInput.dataset.priceSuggested === '1';
    const isEmpty = !unitPriceInput.value;
    const hasDefaultPrice = setting.default_unit_price !== undefined
        && setting.default_unit_price !== null
        && setting.default_unit_price !== '';

    if (!hasDefaultPrice) {
        if (hasSuggestedPrice) {
            unitPriceInput.value = '';
        }
        unitPriceInput.dataset.priceSuggested = '0';
        return;
    }

    if (!isEmpty && !hasSuggestedPrice) {
        return;
    }

    const defaultValue = Number(setting.default_unit_price).toFixed(2);

    if (basePriceInput) {
        const hasSuggestedBase = basePriceInput.dataset.priceSuggested === '1';
        const baseIsEmpty = !basePriceInput.value;

        if (baseIsEmpty || hasSuggestedBase) {
            basePriceInput.value = defaultValue;
            basePriceInput.dataset.priceSuggested = '1';
        }
    }

    unitPriceInput.value = defaultValue;
    unitPriceInput.dataset.priceSuggested = '1';
}

function applyTenantPrintOptionPriceSuggestion(printOperation, option = null) {
    if (!quoteWorkspace.canViewFinancialData || !printOperation || !option) {
        return;
    }

    const hasDefaultPrice = option.default_unit_price !== undefined
        && option.default_unit_price !== null
        && option.default_unit_price !== '';

    if (!hasDefaultPrice) {
        return;
    }

    const unitPriceInput = printOperation.querySelector('input[name*="[print_unit_price]"]');
    const basePriceInput = printOperation.querySelector('input[name*="[base_print_unit_price]"]');
    const defaultValue = Number(option.default_unit_price).toFixed(2);

    if (basePriceInput) {
        const hasSuggestedBase = basePriceInput.dataset.priceSuggested === '1';
        const baseIsEmpty = !basePriceInput.value;

        if (baseIsEmpty || hasSuggestedBase) {
            basePriceInput.value = defaultValue;
            basePriceInput.dataset.priceSuggested = '1';
        }
    }

    if (unitPriceInput) {
        const hasSuggestedPrice = unitPriceInput.dataset.priceSuggested === '1';
        const isEmpty = !unitPriceInput.value;

        if (isEmpty || hasSuggestedPrice) {
            unitPriceInput.value = defaultValue;
            unitPriceInput.dataset.priceSuggested = '1';
        }
    }
}

function syncPrintOptionState(printOperation, setting = null, preferDefault = false) {
    if (!printOperation) {
        return;
    }

    const optionSelect = printOperation.querySelector('.print-option-select');
    const optionIdInput = printOperation.querySelector('[data-print-option-id]');
    const printTypeInput = printOperation.querySelector('[data-print-type-input]');
    const clicheWrap = printOperation.querySelector('[data-cliche-wrap]');
    const clicheSelect = printOperation.querySelector('select[name*="[cliche_status]"]');
    const setupTypeInput = printOperation.querySelector('input[name*="[setup_type]"]');
    const setupStatusInput = printOperation.querySelector('input[name*="[setup_status]"]');
    const setupTotalInput = printOperation.querySelector('input[name*="[setup_total_amount]"]');

    if (!optionSelect) {
        return;
    }

    const effectiveSetting = setting || resolveTenantPrintSetting({
        tenant_print_setting_id: printOperation.querySelector('[data-print-setting-id]')?.value || '',
    });
    const currentOptionLabel = optionSelect.value || '';
    const optionChoices = buildPrintOptionChoices(effectiveSetting, {
        tenant_print_option_id: optionIdInput?.value || '',
        print_option: currentOptionLabel,
        print_type: printTypeInput?.value || '',
    });
    const dbOptions = resolveSettingPrintOptions(effectiveSetting);
    const defaultOption = dbOptions.find((option) => option.is_default) || null;
    const hasCurrentLabel = optionChoices.includes(currentOptionLabel);
    const nextValue = hasCurrentLabel
        ? currentOptionLabel
        : (preferDefault && defaultOption ? defaultOption.name : '');

    optionSelect.innerHTML = optionsHtml(optionChoices, nextValue, 'Baskı seçeneği seç');

    const selectedOption = resolveSelectedPrintOption(effectiveSetting, {
        tenant_print_option_id: optionIdInput?.value || '',
        print_option: optionSelect.value || nextValue,
    }, optionSelect.value || nextValue);

    if (optionIdInput) {
        optionIdInput.value = selectedOption ? String(selectedOption.id) : '';
    }

    if (selectedOption) {
        if (setupTypeInput && selectedOption.setup_type) {
            setupTypeInput.value = selectedOption.setup_type;
        }

        if (clicheSelect && !clicheSelect.value && selectedOption.setup_status_default) {
            clicheSelect.value = selectedOption.setup_status_default;
        }

        applyTenantPrintOptionPriceSuggestion(printOperation, selectedOption);
    }

    const requiresSetup = !!(selectedOption?.requires_setup || effectiveSetting?.requires_setup);
    const setupTypes = selectedOption?.setup_type
        ? [selectedOption.setup_type]
        : (Array.isArray(effectiveSetting?.setup_types) ? effectiveSetting.setup_types : []);
    const shouldShowCliche = printRequiresCliche(printTypeInput?.value || effectiveSetting?.display_name || '', requiresSetup, setupTypes);

    clicheWrap?.classList.toggle('hidden', !shouldShowCliche);
    if (clicheWrap) {
        clicheWrap.dataset.visible = shouldShowCliche ? '1' : '0';
    }

    if (!shouldShowCliche) {
        if (clicheSelect) {
            clicheSelect.value = '';
        }
        if (setupStatusInput) {
            setupStatusInput.value = '';
        }
        if (setupTotalInput) {
            setupTotalInput.value = '';
        }
    } else if (setupStatusInput && clicheSelect?.value) {
        setupStatusInput.value = clicheSelect.value;
    }
}

function refreshPrintSetupPricing(printOperation) {
    if (!printOperation) {
        return;
    }

    const modal = resolveSetupModalElement(printOperation);
    const clicheWrap = printOperation.querySelector('[data-cliche-wrap]');
    const clicheStatusSelect = modal?.querySelector('select[name*="[cliche_status]"]') || null;
    const setupStatusInput = printOperation.querySelector('input[name*="[setup_status]"]');
    const setupPricingEnabledInput = printOperation.querySelector('input[name*="[setup_pricing_enabled]"]');
    const setupTotalAmountInput = modal?.querySelector('input[name*="[setup_total_amount]"]') || null;
    const setupDistributionQuantityInput = printOperation.querySelector('input[name*="[setup_distribution_quantity]"]');
    const setupUnitAmountInput = printOperation.querySelector('input[name*="[setup_unit_amount]"]');
    const basePrintUnitPriceInput = modal?.querySelector('input[name*="[base_print_unit_price]"]') || null;
    const finalPrintUnitPriceInput = printOperation.querySelector('input[name*="[print_unit_price]"]');
    const printQuantityInput = printOperation.querySelector('input[name*="[print_quantity]"]');
    const printTotalInput = printOperation.querySelector('input[name*="[print_total]"]');
    const setupSummaryCard = printOperation.querySelector('[data-setup-summary-card]');
    const setupSummaryMetrics = printOperation.querySelector('[data-setup-summary-metrics]');
    const setupSummaryAmount = printOperation.querySelector('[data-setup-summary-amount]');
    const setupSummaryTitle = printOperation.querySelector('[data-setup-summary-title]');
    const setupSummaryAction = printOperation.querySelector('[data-setup-summary-action]');
    const setupStatusLabels = printOperation.querySelectorAll('[data-setup-status-label]');
    const setupUnitEffectLabels = printOperation.querySelectorAll('[data-setup-unit-effect]');
    const finalUnitPriceLabels = printOperation.querySelectorAll('[data-final-print-unit-price]');
    const modalQuantityInputs = modal?.querySelectorAll('[data-setup-modal-quantity]') || [];
    const modalUnitEffectInputs = modal?.querySelectorAll('[data-setup-modal-unit-effect]') || [];
    const modalFinalUnitInputs = modal?.querySelectorAll('[data-setup-modal-final-unit]') || [];
    const modalFinalTotalInputs = modal?.querySelectorAll('[data-setup-modal-final-total]') || [];
    const statusValue = clicheStatusSelect?.value || '';
    const quantityValue = Number(printQuantityInput?.value || 0);
    const setupTotalValue = Number(setupTotalAmountInput?.value || 0);
    const baseUnitPriceValue = Number(basePrintUnitPriceInput?.value || finalPrintUnitPriceInput?.value || 0);
    const usesSetupPricing = setupStatusRequiresAmount(statusValue);
    const setupSummaryAmountValue = formatMoney(setupTotalValue, currentQuoteCurrency());
    const setupTypeDisplay = setupTypeTitle({
        setup_type: printOperation.querySelector('input[name*="[setup_type]"]')?.value || '',
    });

    if (setupStatusInput) {
        setupStatusInput.value = statusValue;
    }

    if (setupPricingEnabledInput) {
        setupPricingEnabledInput.value = usesSetupPricing ? '1' : '0';
    }

    setupStatusLabels.forEach((label) => {
        label.textContent = statusValue || 'Seçilmedi';
    });
    if (setupSummaryTitle) {
        setupSummaryTitle.textContent = setupTypeDisplay;
    }

    if (clicheWrap) {
        clicheWrap.classList.toggle('hidden', clicheWrap.dataset.visible !== '1');
    }

    if (setupSummaryCard) {
        setupSummaryCard.classList.toggle('hidden', clicheWrap?.dataset.visible !== '1');
    }

    if (setupSummaryAction) {
        setupSummaryAction.textContent = usesSetupPricing ? 'Düzenle' : 'Ara Eleman Ayarla';
    }

    if (!usesSetupPricing) {
        if (setupDistributionQuantityInput) {
            setupDistributionQuantityInput.value = quantityValue ? quantityValue.toFixed(2) : '';
        }
        if (setupUnitAmountInput) {
            setupUnitAmountInput.value = '';
        }
        setupUnitEffectLabels.forEach((label) => {
            label.textContent = formatMoney(0, currentQuoteCurrency());
        });
        finalUnitPriceLabels.forEach((label) => {
            label.textContent = formatMoney(baseUnitPriceValue, currentQuoteCurrency());
        });
        if (setupSummaryMetrics) {
            setupSummaryMetrics.classList.add('hidden');
        }
        if (setupSummaryAmount) {
            setupSummaryAmount.textContent = '';
            setupSummaryAmount.classList.add('hidden');
        }
        if (finalPrintUnitPriceInput) {
            finalPrintUnitPriceInput.readOnly = !quoteWorkspace.canViewFinancialData;
            if (basePrintUnitPriceInput?.value) {
                finalPrintUnitPriceInput.value = basePrintUnitPriceInput.value;
            }
        }
        if (setupTotalAmountInput) {
            setupTotalAmountInput.value = '';
        }
        setSetupModalDisplayValue(modalQuantityInputs, quantityValue ? quantityValue.toFixed(2) : '');
        setSetupModalDisplayValue(modalUnitEffectInputs, formatMoney(0, currentQuoteCurrency()) || '');
        setSetupModalDisplayValue(modalFinalUnitInputs, formatMoney(baseUnitPriceValue, currentQuoteCurrency()) || '');
        setSetupModalDisplayValue(modalFinalTotalInputs, formatMoney(Number(printTotalInput?.value || 0), currentQuoteCurrency()) || '');
        return;
    }

    if (basePrintUnitPriceInput && !basePrintUnitPriceInput.value && finalPrintUnitPriceInput?.value) {
        basePrintUnitPriceInput.value = finalPrintUnitPriceInput.value;
    }

    const distribution = calculateSetupDistribution(baseUnitPriceValue, setupTotalValue, quantityValue);

    if (setupDistributionQuantityInput) {
        setupDistributionQuantityInput.value = quantityValue ? quantityValue.toFixed(2) : '';
    }
    if (setupUnitAmountInput) {
        setupUnitAmountInput.value = distribution.setupUnitAmount ? distribution.setupUnitAmount.toFixed(4) : '0.0000';
    }
    setupUnitEffectLabels.forEach((label) => {
        label.textContent = formatMoney(distribution.setupUnitAmount, currentQuoteCurrency());
    });
    finalUnitPriceLabels.forEach((label) => {
        label.textContent = formatMoney(distribution.finalPrintUnitPrice, currentQuoteCurrency());
    });
    if (setupSummaryMetrics) {
        setupSummaryMetrics.classList.remove('hidden');
    }
    if (setupSummaryAmount) {
        setupSummaryAmount.textContent = setupSummaryAmountValue || '';
        setupSummaryAmount.classList.toggle('hidden', !setupSummaryAmountValue);
    }
    if (finalPrintUnitPriceInput) {
        finalPrintUnitPriceInput.readOnly = true;
        finalPrintUnitPriceInput.value = distribution.finalPrintUnitPrice.toFixed(2);
    }
    if (printTotalInput) {
        printTotalInput.value = distribution.finalPrintTotal.toFixed(2);
    }
    setSetupModalDisplayValue(modalQuantityInputs, quantityValue ? quantityValue.toFixed(2) : '');
    setSetupModalDisplayValue(modalUnitEffectInputs, formatMoney(distribution.setupUnitAmount, currentQuoteCurrency()) || '');
    setSetupModalDisplayValue(modalFinalUnitInputs, formatMoney(distribution.finalPrintUnitPrice, currentQuoteCurrency()) || '');
    setSetupModalDisplayValue(modalFinalTotalInputs, formatMoney(distribution.finalPrintTotal, currentQuoteCurrency()) || '');
}

function captureSetupModalSnapshot(printOperation) {
    if (!printOperation) {
        return null;
    }

    const modal = resolveSetupModalElement(printOperation);

    return JSON.stringify({
        cliche_status: modal?.querySelector('select[name*="[cliche_status]"]')?.value || '',
        setup_total_amount: modal?.querySelector('input[name*="[setup_total_amount]"]')?.value || '',
        base_print_unit_price: modal?.querySelector('input[name*="[base_print_unit_price]"]')?.value || '',
        print_unit_price: printOperation.querySelector('input[name*="[print_unit_price]"]')?.value || '',
        print_total: printOperation.querySelector('input[name*="[print_total]"]')?.value || '',
        setup_pricing_enabled: printOperation.querySelector('input[name*="[setup_pricing_enabled]"]')?.value || '0',
        setup_status: printOperation.querySelector('input[name*="[setup_status]"]')?.value || '',
        setup_distribution_quantity: printOperation.querySelector('input[name*="[setup_distribution_quantity]"]')?.value || '',
        setup_unit_amount: printOperation.querySelector('input[name*="[setup_unit_amount]"]')?.value || '',
    });
}

function restoreSetupModalSnapshot(printOperation, snapshotJson) {
    if (!printOperation || !snapshotJson) {
        return;
    }

    const modal = resolveSetupModalElement(printOperation);
    let snapshot = null;

    try {
        snapshot = JSON.parse(snapshotJson);
    } catch (error) {
        snapshot = null;
    }

    if (!snapshot) {
        return;
    }

    const setters = {
        cliche_status: modal?.querySelector('select[name*="[cliche_status]"]') || null,
        setup_total_amount: modal?.querySelector('input[name*="[setup_total_amount]"]') || null,
        base_print_unit_price: modal?.querySelector('input[name*="[base_print_unit_price]"]') || null,
        print_unit_price: 'input[name*="[print_unit_price]"]',
        print_total: 'input[name*="[print_total]"]',
        setup_pricing_enabled: 'input[name*="[setup_pricing_enabled]"]',
        setup_status: 'input[name*="[setup_status]"]',
        setup_distribution_quantity: 'input[name*="[setup_distribution_quantity]"]',
        setup_unit_amount: 'input[name*="[setup_unit_amount]"]',
    };

    Object.entries(setters).forEach(([key, selectorOrElement]) => {
        const element = typeof selectorOrElement === 'string'
            ? printOperation.querySelector(selectorOrElement)
            : selectorOrElement;
        if (element) {
            element.value = snapshot[key] ?? '';
        }
    });
}

function resolveSetupModalElement(printOperation) {
    if (!printOperation) {
        return null;
    }

    const itemElement = printOperation.closest('.pd-quote-item');
    const printKey = printOperation.dataset.printKey || '';
    if (!itemElement || !printKey) {
        return null;
    }

    return itemElement.querySelector(`[data-setup-modal-for="${printKey}"]`);
}

function resolvePrintOperationForModal(modal) {
    if (!modal) {
        return null;
    }

    const itemElement = modal.closest('.pd-quote-item');
    const printKey = modal.dataset.setupModalFor || '';
    if (!itemElement || !printKey) {
        return null;
    }

    return itemElement.querySelector(`.pd-print-operation[data-print-key="${printKey}"]`);
}

function setSetupModalBodyLock(locked) {
    if (!document?.body) {
        return;
    }

    if (locked) {
        document.body.dataset.setupModalScrollLock = document.body.style.overflow || '';
        document.body.style.overflow = 'hidden';
        return;
    }

    const previousOverflow = document.body.dataset.setupModalScrollLock ?? '';
    document.body.style.overflow = previousOverflow;
    delete document.body.dataset.setupModalScrollLock;
}

function toggleSetupModal(printOperation, show, { revert = false } = {}) {
    if (!printOperation) {
        return;
    }

    const modal = resolveSetupModalElement(printOperation);
    if (!modal) {
        return;
    }

    if (show) {
        quotePrintDebugLog('setup-modal-open', {
            itemIndex: printOperation.closest('.pd-quote-item')?.dataset.itemIndex || '',
            printIndex: printOperation.dataset.printIndex || '',
            printKey: printOperation.dataset.printKey || '',
        });
        modal.dataset.snapshot = captureSetupModalSnapshot(printOperation) || '';
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        setSetupModalBodyLock(true);
        return;
    }

    if (revert && modal.dataset.snapshot) {
        restoreSetupModalSnapshot(printOperation, modal.dataset.snapshot);
        refreshPrintSetupPricing(printOperation);
        recalculateTotals();
    }

    modal.classList.add('hidden');
    modal.style.display = 'none';
    setSetupModalBodyLock(false);
    renderQuotePrintDebugPanel(revert ? 'setup-modal-close-revert' : 'setup-modal-close');
}

function applySetupModal(printOperation) {
    if (!printOperation) {
        return;
    }

    quotePrintDebugLog('setup-modal-save', {
        itemIndex: printOperation.closest('.pd-quote-item')?.dataset.itemIndex || '',
        printIndex: printOperation.dataset.printIndex || '',
        printKey: printOperation.dataset.printKey || '',
    });
    refreshPrintSetupPricing(printOperation);
    recalculateTotals();
    toggleSetupModal(printOperation, false, { revert: false });
}

function renderPrintRows(item) {
    return item.prints.map((printRow, printIndex) => {
        const printSetting = resolveTenantPrintSetting(printRow);
        const printType = currentPrintSettingOrLegacyName(printRow);
        const printSelectorOptions = buildPrintTypeOptions(printRow);
        const selectedOption = resolveSelectedPrintOption(printSetting, printRow);
        const printOptionOptions = buildPrintOptionChoices(printSetting, printRow);
        const showCliche = printRequiresCliche(
            printType,
            !!(selectedOption?.requires_setup || printRow.requires_setup),
            selectedOption?.setup_type ? [selectedOption.setup_type] : printRow.setup_types
        );
        const selectorValue = printSelectorValue(printRow);
        const showSetupBadge = quoteWorkspace.intermediateElementEnabled && !!(selectedOption?.requires_setup || printSetting?.requires_setup || printRow.requires_setup);
        const currencyWarning = printCurrencyWarningMessage(printSetting);
        const setupPricingVisible = shouldShowSetupPricingBox(printRow);
        const setupTypeText = setupTypeTitle(printRow);
        return `
            <div class="pd-print-operation" data-print-index="${printIndex}" data-print-key="${escapeHtml(printRow._stable_key || '')}" data-row-error-message="${escapeHtml(printRow._row_error || '')}" data-error-path="${escapeHtml(printRow._error_path || '')}">
                <div class="pd-print-operation-grid pd-print-operation-grid-flat">
                    <div class="pd-print-operation-index">${escapeHtml(printRowCode(item._index, printIndex))}</div>
                    <div>
                        <select class="pd-compact-select print-type-select" data-print-setting-select>
                            ${optionsHtml(printSelectorOptions, selectorValue, tenantPrintSettings.length ? 'Baskı tipi seç' : 'Baskı ayarı bulunamadı')}
                        </select>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][tenant_print_setting_id]" value="${escapeHtml(printRow.tenant_print_setting_id || '')}" data-print-setting-id>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][standard_print_type_id]" value="${escapeHtml(printRow.standard_print_type_id || '')}" data-standard-print-type-id>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][tenant_print_option_id]" value="${escapeHtml(printRow.tenant_print_option_id || '')}" data-print-option-id>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][print_type]" value="${escapeHtml(printType)}" data-print-type-input>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][production_type]" value="${escapeHtml(printRow.production_type || '')}" data-production-type-input>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][subcontractor_company_id]" value="${escapeHtml(printRow.subcontractor_company_id || '')}" data-subcontractor-company-id>
                        ${showSetupBadge ? `<div class="pd-print-inline-meta mt-1"><span class="pd-badge pd-badge-amber">Ara eleman gerekir</span></div>` : ''}
                    </div>
                    <div>
                        <select name="items[${item._index}][prints][${printIndex}][print_option]" class="pd-compact-select print-option-select">
                            ${optionsHtml(printOptionOptions, printRow.print_option, 'Baskı seçeneği seç')}
                        </select>
                        ${showCliche ? `<div class="pd-print-inline-meta mt-1"><span>${escapeHtml(setupTypeText)}:</span> ${escapeHtml(printRow.cliche_status || 'Seçilmedi')}</div>` : ''}
                    </div>
                    <div>
                        <input type="number" name="items[${item._index}][prints][${printIndex}][print_quantity]" value="${escapeHtml(printRow.print_quantity)}" step="0.01" min="0" class="pd-compact-input print-quantity-input" data-manual-quantity="${printRow._manual_quantity ? '1' : '0'}" placeholder="0">
                    </div>
                    <div>
                        <input type="number" name="items[${item._index}][prints][${printIndex}][print_unit_price]" value="${escapeHtml(printRow.print_unit_price)}" step="0.01" min="0" class="pd-compact-input" placeholder="${quoteWorkspace.canViewFinancialData ? '0.00' : 'Gizli'}" data-price-suggested="${printRow._price_suggested ? '1' : '0'}" ${quoteWorkspace.canViewFinancialData && !setupPricingVisible ? '' : 'readonly'}>
                        <div class="pd-print-inline-meta mt-1 ${currencyWarning ? '' : 'hidden'}" data-print-price-currency-warning>${escapeHtml(currencyWarning)}</div>
                    </div>
                    <div>
                        <input type="number" name="items[${item._index}][prints][${printIndex}][print_total]" value="${escapeHtml(printRow.print_total)}" step="0.01" min="0" class="pd-compact-input" placeholder="${quoteWorkspace.canViewFinancialData ? '0.00' : 'Gizli'}" readonly>
                    </div>
                    <div>
                        <input type="text" name="items[${item._index}][prints][${printIndex}][note]" value="${escapeHtml(printRow.note || '')}" class="pd-compact-input" placeholder="Baskı adı">
                        ${showCliche ? `
                        <div data-cliche-wrap data-visible="1">
                            <div class="pd-setup-inline-summary" data-setup-summary-card>
                                <span class="pd-setup-inline-chip is-required">Ara Eleman Gerekli</span>
                                <span class="pd-setup-inline-chip">
                                    <strong data-setup-summary-title>${escapeHtml(setupTypeText)}</strong>:
                                    <span data-setup-status-label>${escapeHtml(printRow.cliche_status || 'Seçilmedi')}</span>
                                </span>
                                <span class="pd-setup-inline-chip ${setupPricingVisible ? 'is-ready' : 'is-missing'}">
                                    ${setupPricingVisible ? 'Ayarlandı' : 'Eksik'}
                                </span>
                                <span class="pd-setup-inline-chip ${setupPricingVisible ? 'is-ready' : 'hidden'}" data-setup-summary-metrics>
                                    Birim etki:
                                    <strong data-setup-unit-effect>${escapeHtml(formatMoney(Number(printRow.setup_unit_amount || 0), currentQuoteCurrency()))}</strong>
                                </span>
                                <span class="pd-setup-inline-chip ${setupSummaryAmountLabel(printRow) ? 'is-ready' : 'hidden'}" data-setup-summary-amount>
                                    ${escapeHtml(setupSummaryAmountLabel(printRow))}
                                </span>
                                <button type="button" class="pd-btn pd-btn-light pd-btn-xs" data-action="open-setup-modal" data-setup-summary-action>${setupPricingVisible ? 'Düzenle' : 'Ara Eleman Ayarla'}</button>
                                <span class="hidden" data-final-print-unit-price>${escapeHtml(formatMoney(Number(printRow.print_unit_price || 0), currentQuoteCurrency()))}</span>
                            </div>
                            <input type="hidden" name="items[${item._index}][prints][${printIndex}][setup_status]" value="${escapeHtml(printRow.setup_status || printRow.cliche_status || '')}">
                            <input type="hidden" name="items[${item._index}][prints][${printIndex}][setup_pricing_enabled]" value="${printRow.setup_pricing_enabled ? '1' : '0'}">
                            <input type="hidden" name="items[${item._index}][prints][${printIndex}][setup_type]" value="${escapeHtml(printRow.setup_type || '')}">
                            <input type="hidden" name="items[${item._index}][prints][${printIndex}][setup_distribution_quantity]" value="${escapeHtml(printRow.setup_distribution_quantity || '')}">
                            <input type="hidden" name="items[${item._index}][prints][${printIndex}][setup_unit_amount]" value="${escapeHtml(printRow.setup_unit_amount || '')}">
                        </div>
                        ` : ''}
                    </div>
                    <div class="pd-print-row-actions">
                        ${printRow._row_error ? `<div class="mt-2 text-xs font-medium text-red-700" data-print-row-error>${escapeHtml(printRow._row_error)}</div>` : ''}
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][print_vat_rate]" value="${escapeHtml(printRow.print_vat_rate ?? quoteWorkspace.defaultPrintVatRate ?? 20)}">
                        <button type="button" class="pd-btn pd-btn-danger-soft pd-btn-xs" data-action="remove-print">Sil</button>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function renderPrintSetupModals(item) {
    return item.prints.map((printRow, printIndex) => {
        const printSetting = resolveTenantPrintSetting(printRow);
        const selectedOption = resolveSelectedPrintOption(printSetting, printRow);
        const showCliche = printRequiresCliche(
            currentPrintSettingOrLegacyName(printRow),
            !!(selectedOption?.requires_setup || printRow.requires_setup),
            selectedOption?.setup_type ? [selectedOption.setup_type] : printRow.setup_types
        );

        if (!showCliche) {
            return '';
        }

        return `
            <div class="hidden" data-setup-modal data-setup-modal-for="${escapeHtml(printRow._stable_key || '')}" style="display:none; position:fixed; inset:0; z-index:1300; align-items:center; justify-content:center; padding:16px; background:rgba(15, 23, 42, 0.28);">
                <div data-action="close-setup-modal" style="position:absolute; inset:0;"></div>
                <div style="position:relative; z-index:1; width:min(700px, calc(100vw - 32px)); max-height:calc(100vh - 48px); overflow-y:auto; background:#fff; border:1px solid #e2e8f0; border-radius:18px; box-shadow:0 24px 60px rgba(15, 23, 42, 0.24);">
                    <div class="border-b border-slate-200 px-6 py-5">
                        <div class="text-base font-semibold text-slate-900">Ara Eleman Hesaplama</div>
                        <div class="mt-1 text-sm text-slate-600">Ara eleman toplam tutarı baskı miktarına dağıtılır ve baskı birim fiyatına eklenir. Müşteriye ayrı ara eleman satırı gösterilmez.</div>
                    </div>
                    <div class="setup-modal-body px-6 py-5" style="display:grid; gap:18px;">
                        <div class="setup-modal-field full" style="display:grid; gap:6px;">
                            <label class="pd-label text-xs">Klişe / Kalıp durumu</label>
                            <select name="items[${item._index}][prints][${printIndex}][cliche_status]" class="pd-compact-select mt-1">
                                ${optionsHtml(quoteWorkspace.clicheOptions, printRow.cliche_status, 'Klişe / kalıp seç')}
                            </select>
                        </div>
                        <div class="setup-modal-grid" style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px;">
                            <label class="setup-modal-field" style="display:grid; gap:6px;">
                                <span class="pd-label text-xs">Ara eleman toplam tutarı</span>
                                <input type="number" name="items[${item._index}][prints][${printIndex}][setup_total_amount]" value="${escapeHtml(printRow.setup_total_amount || '')}" step="0.01" min="0" class="pd-compact-input" placeholder="${quoteWorkspace.canViewFinancialData ? '0.00' : 'Gizli'}" ${quoteWorkspace.canViewFinancialData ? '' : 'readonly'} style="width:100%;">
                            </label>
                            <label class="setup-modal-field" style="display:grid; gap:6px;">
                                <span class="pd-label text-xs">Baz baskı birim fiyatı</span>
                                <input type="number" name="items[${item._index}][prints][${printIndex}][base_print_unit_price]" value="${escapeHtml(printRow.base_print_unit_price || printRow.print_unit_price || '')}" step="0.01" min="0" class="pd-compact-input" placeholder="${quoteWorkspace.canViewFinancialData ? '0.00' : 'Gizli'}" ${quoteWorkspace.canViewFinancialData ? '' : 'readonly'} style="width:100%;">
                            </label>
                            <label class="setup-modal-field" style="display:grid; gap:6px;">
                                <span class="pd-label text-xs">Baskı miktarı</span>
                                <input type="number" value="${escapeHtml(printRow.print_quantity)}" step="0.01" min="0" class="pd-compact-input bg-slate-50" data-setup-modal-quantity readonly style="width:100%; color:#334155; background:#f8fafc;">
                            </label>
                            <label class="setup-modal-field" style="display:grid; gap:6px;">
                                <span class="pd-label text-xs">Birim etki</span>
                                <input type="text" value="${escapeHtml(formatMoney(Number(printRow.setup_unit_amount || 0), currentQuoteCurrency()) || '0,00 ' + displayCurrencyLabel(currentQuoteCurrency()))}" class="pd-compact-input bg-slate-50" data-setup-modal-unit-effect readonly style="width:100%; color:#334155; background:#f8fafc;">
                            </label>
                        </div>
                        <div class="setup-modal-result-wrap" style="border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; padding:16px 18px;">
                            <div class="text-sm font-semibold text-slate-900" style="margin-bottom:12px;">Hesap Özeti</div>
                            <div class="setup-modal-result-grid" style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px;">
                                <div class="setup-modal-result-card" style="border:1px solid #dbe4ee; border-radius:12px; background:#fff; padding:10px 12px;">
                                    <div class="text-[11px] uppercase tracking-[0.02em] text-slate-500">Birim etki</div>
                                    <div class="mt-1 text-sm font-semibold text-slate-900" data-setup-modal-unit-effect>${escapeHtml(formatMoney(Number(printRow.setup_unit_amount || 0), currentQuoteCurrency()) || '0,00 ' + displayCurrencyLabel(currentQuoteCurrency()))}</div>
                                </div>
                                <div class="setup-modal-result-card" style="border:1px solid #dbe4ee; border-radius:12px; background:#fff; padding:10px 12px;">
                                    <div class="text-[11px] uppercase tracking-[0.02em] text-slate-500">Nihai baskı birim fiyatı</div>
                                    <div class="mt-1 text-sm font-semibold text-slate-900" data-setup-modal-final-unit>${escapeHtml(formatMoney(Number(printRow.print_unit_price || 0), currentQuoteCurrency()) || '0,00 ' + displayCurrencyLabel(currentQuoteCurrency()))}</div>
                                </div>
                                <div class="setup-modal-result-card" style="border:1px solid #dbe4ee; border-radius:12px; background:#fff; padding:10px 12px;">
                                    <div class="text-[11px] uppercase tracking-[0.02em] text-slate-500">Nihai baskı toplamı</div>
                                    <div class="mt-1 text-sm font-semibold text-slate-900" data-setup-modal-final-total>${escapeHtml(formatMoney(Number(printRow.print_total || 0), currentQuoteCurrency()) || '0,00 ' + displayCurrencyLabel(currentQuoteCurrency()))}</div>
                                </div>
                            </div>
                            <div class="hidden" data-setup-modal-quantity>${escapeHtml(printRow.print_quantity)}</div>
                        </div>
                    </div>
                    <div class="setup-modal-actions" style="display:flex; align-items:center; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding:14px 24px 16px; background:#fff;">
                        <button type="button" class="pd-btn pd-btn-light" data-action="cancel-setup-modal">Vazgeç / Kapat</button>
                        <button type="button" class="pd-btn pd-btn-primary" data-action="apply-setup-modal">Uygula</button>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function renderItem(item) {
    const supplierStock = item.stock_snapshot?.supplier_stock_quantity ?? 0;
    const displayWarningBadges = quoteLineWarningBadges(item);
    const warningMeta = quoteWarningMeta(item);
    const thumbnail = item.product_snapshot?.image_url || '';
    const invoiceStatus = invoiceStatusValue();
    const vatMode = invoiceStatus === 'fatura' ? 'taxable' : 'none';
    const vatRate = Number(item.price_snapshot?.vat_rate ?? 20) || 20;
    const showVatDetails = invoiceStatus === 'fatura';
    const productLineTotal = Number(item.price_snapshot?.product_line_total ?? item.line_total ?? 0);
    const calculatedUnitPrice = Number(item.calculated_unit_price || item.price_snapshot?.calculated_unit_price || calculateItemUnitPrice(item) || 0);
    const manualUnitPrice = item.manual_unit_price || item.price_snapshot?.manual_unit_price;
    const compactWarningBadges = displayWarningBadges
        .map((badge) => badgeHtml(badge.text, badge.tone || 'amber'));

    return `
        <div class="pd-quote-item pd-quote-item-group ${warningMeta.hasSupplierWarning || warningMeta.hasStockWarning ? 'is-warning' : ''}" data-item-index="${item._index}" data-stable-key="${escapeHtml(item._stable_key || '')}" data-row-error-message="${escapeHtml(item._row_error || '')}" data-error-path="${escapeHtml(item._error_path || '')}">
            <div class="pd-quote-line-row">
                <div class="pd-quote-item-number">${item._index + 1}</div>
                <div class="pd-quote-line-product">
                    <div class="pd-catalog-search">
                        <input type="text" name="items[${item._index}][product_name]" class="pd-compact-input catalog-search-input" value="${escapeHtml(item.product_name)}" placeholder="Ürün adı, ürün kodu, SKU, renk...">
                        <div class="pd-catalog-results hidden"></div>
                    </div>                    <div class="pd-quote-line-product-meta">
                        ${thumbnail ? `<img src="${escapeHtml(thumbnail)}" alt="${escapeHtml(item.product_name || `Ürün ${item._index + 1}`)}" class="pd-quote-item-thumb">` : ''}
                        <div class="min-w-0">
                            ${renderLiveProductInfoPanel(item)}
                        </div>
                    </div>
                    ${showVatDetails || warningMeta.hasStockWarning || compactWarningBadges.length ? `
                        <div class="pd-quote-line-subtitle pd-quote-line-subtitle-rich">
                            ${showVatDetails ? `
                                <label class="pd-quote-inline-pill pd-quote-inline-pill-input">
                                    <strong>KDV</strong>
                                    <input type="number" name="items[${item._index}][vat_rate]" value="${escapeHtml(vatRate)}" step="0.01" min="0" max="100" class="pd-inline-number-input" placeholder="20">
                                </label>
                            ` : ''}
                            ${warningMeta.hasStockWarning ? badgeHtml('Stok Yok', 'red') : ''}
                            ${compactWarningBadges.join('')}
                        </div>
                    ` : ''}
                    ${item._row_error ? `<div class="mt-2 text-xs font-medium text-red-700">${escapeHtml(item._row_error)}</div>` : ''}
                </div>
                <div><input type="number" name="items[${item._index}][quantity]" value="${escapeHtml(formatInputNumber(item.quantity || ''))}" step="0.01" min="0.01" class="pd-compact-input" placeholder="1.00"></div>
                <div><input type="number" name="items[${item._index}][list_price]" value="${escapeHtml(formatInputNumber(item.list_price || ''))}" step="0.01" min="0" class="pd-compact-input" placeholder="${quoteWorkspace.canViewFinancialData ? '0.00' : 'Gizli'}" ${quoteWorkspace.canViewFinancialData ? '' : 'readonly'}></div>
                <div><input type="number" name="items[${item._index}][discount_rate]" value="${escapeHtml(formatInputNumber(item.discount_rate || '0'))}" step="0.01" min="0" max="100" class="pd-compact-input"></div>
                <div><input type="number" name="items[${item._index}][unit_price]" value="${escapeHtml(formatInputNumber(item.unit_price || ''))}" step="0.01" min="0" class="pd-compact-input unit-price-input" placeholder="${quoteWorkspace.canViewFinancialData ? '0.00' : 'Gizli'}" ${quoteWorkspace.canViewFinancialData ? '' : 'readonly'}></div>
                <div><input type="number" name="items[${item._index}][line_total]" value="${escapeHtml(formatInputNumber(productLineTotal || item.line_total || ''))}" step="0.01" min="0" class="pd-compact-input" placeholder="${quoteWorkspace.canViewFinancialData ? '0.00' : 'Gizli'}" readonly></div>
                <div class="pd-quote-line-actions">
                    <label class="pd-checkbox">
                        <input type="hidden" name="items[${item._index}][has_print]" value="0">
                        <input type="checkbox" class="quote-has-print" name="items[${item._index}][has_print]" value="1" ${item.has_print ? 'checked' : ''}>
                        <span>Baskı Var</span>
                    </label>
                    <button type="button" class="pd-btn pd-btn-light pd-btn-xs pd-print-add-button" data-action="add-print">Baskı Ekle</button>
                </div>
                <div>
                    <button type="button" class="pd-btn pd-btn-danger-soft pd-btn-xs" data-action="remove-item">Sil</button>
                </div>
            </div>

            <div class="pd-print-operations ${item.has_print ? '' : 'hidden'}" data-print-wrapper>
                <div class="pd-print-table-head pd-print-table-head-flat">
                    <span>No</span>
                    <span>Baskı türü</span>
                    <span>Baskı seçeneği</span>
                    <span>Baskı miktarı</span>
                    <span>Birim baskı fiyatı</span>
                    <span>Baskı toplamı</span>
                    <span>Baskı adı</span>
                    <span>İşlem</span>
                </div>
                <div class="space-y-2" data-print-list>
                    ${renderPrintRows(item)}
                </div>
                ${renderPrintSetupModals(item)}
            </div>

            <input type="hidden" name="items[${item._index}][product_code]" value="${escapeHtml(item.product_code || '')}">
            <input type="hidden" name="items[${item._index}][stable_key]" value="${escapeHtml(item._stable_key || '')}">
            <input type="hidden" name="items[${item._index}][unit]" value="${escapeHtml(item.unit || 'Adet')}">
            <input type="hidden" name="items[${item._index}][vat_mode]" value="${vatMode}">
            <input type="hidden" name="items[${item._index}][tenant_catalog_product_id]" value="${escapeHtml(item.tenant_catalog_product_id || '')}">
            <input type="hidden" name="items[${item._index}][tenant_catalog_product_variant_id]" value="${escapeHtml(item.tenant_catalog_product_variant_id || '')}">
            <input type="hidden" name="items[${item._index}][standard_product_id]" value="${escapeHtml(item.standard_product_id || '')}">
            <input type="hidden" name="items[${item._index}][standard_product_variant_id]" value="${escapeHtml(item.standard_product_variant_id || '')}">
            <input type="hidden" name="items[${item._index}][supplier_id]" value="${escapeHtml(item.supplier_id || '')}">
            <input type="hidden" name="items[${item._index}][supplier_source_id]" value="${escapeHtml(item.supplier_source_id || '')}">
            <input type="hidden" name="items[${item._index}][catalog_source]" value="${escapeHtml(item.catalog_source || 'tenant_catalog')}">
            <input type="hidden" name="items[${item._index}][selected_catalog_identity]" value="${escapeHtml(JSON.stringify(item.selected_catalog_identity || null))}">
            <input type="hidden" name="items[${item._index}][manual_unit_price]" value="${manualUnitPrice ? '1' : '0'}" data-manual-unit-price>
            <input type="hidden" name="items[${item._index}][calculated_unit_price]" value="${escapeHtml(formatInputNumber(calculatedUnitPrice || ''))}" data-calculated-unit-price>
            <input type="hidden" name="items[${item._index}][description]" value="${escapeHtml(item.description || '')}">
            <input type="hidden" name="items[${item._index}][invoice_status]" value="${invoiceStatus}">
            <input type="hidden" name="items[${item._index}][product_snapshot]" value="${escapeHtml(JSON.stringify(item.product_snapshot || null))}">
            <input type="hidden" name="items[${item._index}][price_snapshot]" value="${escapeHtml(JSON.stringify(item.price_snapshot || null))}">
            <input type="hidden" name="items[${item._index}][stock_snapshot]" value="${escapeHtml(JSON.stringify(item.stock_snapshot || null))}">
        </div>
    `;
}

function syncQuoteItemFormOwnership(root = document) {
    if (!root || !canonicalQuoteFormId) {
        return;
    }

    root.querySelectorAll('input[name^="items["], select[name^="items["], textarea[name^="items["]').forEach((element) => {
        if (element.getAttribute('form') !== canonicalQuoteFormId) {
            element.setAttribute('form', canonicalQuoteFormId);
        }
    });
}

function mountItems(items) {
    const container = document.getElementById('product-items-container');
    container.innerHTML = '';

    items.forEach((item, index) => {
        try {
            const normalized = normalizeItem(item, index);
            const debugItem = ensureQuotePrintDebugItem(index, normalized._stable_key || '');
            debugItem.lastMountCount = Array.isArray(normalized.prints) ? normalized.prints.length : 0;
            container.insertAdjacentHTML('beforeend', renderItem(normalized));
            quotePrintDebugLog('mount-item', {
                itemIndex: index,
                stableKey: normalized._stable_key,
                printsLength: Array.isArray(normalized.prints) ? normalized.prints.length : 0,
            });
            syncQuoteItemFormOwnership(container);
        } catch (error) {
            const fallbackItem = normalizeItem({
                ...defaultItem(),
                ...safeObject(item),
                _row_error: 'Bu satir tam yuklenemedi. Lütfen ürünü katalogdan yeniden seçin.',
            }, index);
            const debugItem = ensureQuotePrintDebugItem(index, fallbackItem._stable_key || '');
            debugItem.lastError = error?.message || 'mount-error';
            container.insertAdjacentHTML('beforeend', renderItem(fallbackItem));
            syncQuoteItemFormOwnership(container);
            setClientFormError('Teklif satırlarından biri güvenli şekilde yeniden yüklendi. Lütfen hatalı satırı kontrol edin.');
        }
    });

    productItemCount = items.length;
    if (activeItemIndex >= productItemCount) {
        activeItemIndex = Math.max(0, productItemCount - 1);
    }
    document.querySelectorAll('.pd-print-operation').forEach((printOperation) => {
        syncPrintOptionState(printOperation, null, true);
        refreshPrintSetupPricing(printOperation);
    });
    collectItems().forEach((item, itemIndex) => {
        const debugItem = ensureQuotePrintDebugItem(itemIndex, item._stable_key || '');
        debugItem.lastDomCount = countDomPrintRows(itemIndex);
    });
    collectItems().forEach((item) => {
        ensureLiveProductInfo(item);
    });
    recalculateTotals();
    refreshAllPrintCurrencyWarnings();
    refreshCustomerSummary();
    renderQuotePrintDebugPanel('mount');
}

function collectItems() {
    const items = Array.from(document.querySelectorAll('.pd-quote-item')).map((element, index) => {
        const productSnapshot = parseJsonValue(element.querySelector('input[name$="[product_snapshot]"]')?.value);
        const priceSnapshot = parseJsonValue(element.querySelector('input[name$="[price_snapshot]"]')?.value);
        const stockSnapshot = parseJsonValue(element.querySelector('input[name$="[stock_snapshot]"]')?.value);
        const selectedCatalogIdentity = parseJsonValue(element.querySelector('input[name$="[selected_catalog_identity]"]')?.value);

        return normalizeItem({
            stable_key: element.dataset.stableKey || element.querySelector('input[name$="[stable_key]"]')?.value || '',
            product_name: element.querySelector('input[name$="[product_name]"]')?.value || '',
            product_code: element.querySelector('input[name$="[product_code]"]')?.value || '',
            quantity: element.querySelector('input[name$="[quantity]"]')?.value || '',
            unit: element.querySelector('input[name$="[unit]"]')?.value || 'Adet',
            list_price: element.querySelector('input[name$="[list_price]"]')?.value || '',
            discount_rate: element.querySelector('input[name$="[discount_rate]"]')?.value || '0',
            unit_price: element.querySelector('input[name$="[unit_price]"]')?.value || '',
            line_total: element.querySelector('input[name$="[line_total]"]')?.value || '',
            vat_mode: element.querySelector('input[name$="[vat_mode]"]')?.value || 'none',
            vat_rate: element.querySelector('input[name$="[vat_rate]"]')?.value || priceSnapshot?.vat_rate || '',
            manual_unit_price: element.querySelector('input[data-manual-unit-price]')?.value === '1',
            calculated_unit_price: element.querySelector('input[data-calculated-unit-price]')?.value || '',
            description: element.querySelector('input[name$="[description]"]')?.value || '',
            has_print: element.querySelector('.quote-has-print')?.checked || false,
            quote_item_id: element.querySelector('[data-live-product-info-box]')?.dataset.quoteItemId || '',
            tenant_catalog_product_id: element.querySelector('input[name$="[tenant_catalog_product_id]"]')?.value || '',
            tenant_catalog_product_variant_id: element.querySelector('input[name$="[tenant_catalog_product_variant_id]"]')?.value || '',
            standard_product_id: element.querySelector('input[name$="[standard_product_id]"]')?.value || '',
            standard_product_variant_id: element.querySelector('input[name$="[standard_product_variant_id]"]')?.value || '',
            supplier_id: element.querySelector('input[name$="[supplier_id]"]')?.value || '',
            supplier_source_id: element.querySelector('input[name$="[supplier_source_id]"]')?.value || '',
            catalog_source: element.querySelector('input[name$="[catalog_source]"]')?.value || 'tenant_catalog',
            _row_error: element.dataset.rowErrorMessage || '',
            _error_path: element.dataset.errorPath || '',
            product_snapshot: productSnapshot,
            price_snapshot: priceSnapshot,
            stock_snapshot: stockSnapshot,
            selected_catalog_identity: selectedCatalogIdentity,
            print_vat_rate: priceSnapshot?.print_vat_rate || quoteWorkspace.defaultPrintVatRate || 20,
            prints: Array.from(element.querySelectorAll('[data-print-list] .pd-print-operation')).map((row, printIndex) => {
                const setupModal = resolveSetupModalElement(row);

                return normalizePrint({
                    stable_key: row.dataset.printKey || '',
                    tenant_print_setting_id: row.querySelector('[data-print-setting-id]')?.value || '',
                    standard_print_type_id: row.querySelector('[data-standard-print-type-id]')?.value || '',
                    tenant_print_option_id: row.querySelector('[data-print-option-id]')?.value || '',
                    print_type: row.querySelector('[data-print-type-input]')?.value || '',
                    print_option: row.querySelector('select[name*="[print_option]"]')?.value || '',
                    production_type: row.querySelector('[data-production-type-input]')?.value || '',
                    subcontractor_company_id: row.querySelector('[data-subcontractor-company-id]')?.value || '',
                    cliche_status: setupModal?.querySelector('select[name*="[cliche_status]"]')?.value || '',
                    setup_pricing_enabled: row.querySelector('input[name*="[setup_pricing_enabled]"]')?.value || '0',
                    setup_type: row.querySelector('input[name*="[setup_type]"]')?.value || '',
                    setup_status: row.querySelector('input[name*="[setup_status]"]')?.value || '',
                    setup_total_amount: setupModal?.querySelector('input[name*="[setup_total_amount]"]')?.value || '',
                    setup_distribution_quantity: row.querySelector('input[name*="[setup_distribution_quantity]"]')?.value || '',
                    setup_unit_amount: row.querySelector('input[name*="[setup_unit_amount]"]')?.value || '',
                    base_print_unit_price: setupModal?.querySelector('input[name*="[base_print_unit_price]"]')?.value || '',
                    print_quantity: row.querySelector('input[name*="[print_quantity]"]')?.value || '',
                    print_unit_price: row.querySelector('input[name*="[print_unit_price]"]')?.value || '',
                    print_total: row.querySelector('input[name*="[print_total]"]')?.value || '',
                    _row_error: row.dataset.rowErrorMessage || '',
                    _error_path: row.dataset.errorPath || '',
                    note: row.querySelector('input[name*="[note]"]')?.value || '',
                    print_vat_rate: row.querySelector('input[name*="[print_vat_rate]"]')?.value || quoteWorkspace.defaultPrintVatRate || 20,
                    _manual_quantity: row.querySelector('input[name*="[print_quantity]"]')?.dataset.manualQuantity === '1',
                    _price_suggested: row.querySelector('input[name*="[print_unit_price]"]')?.dataset.priceSuggested === '1',
                }, printIndex);
            }),
        }, index);
    });

    items.forEach((item, itemIndex) => {
        const debugItem = ensureQuotePrintDebugItem(itemIndex, item._stable_key || '');
        debugItem.lastCollectCount = Array.isArray(item.prints) ? item.prints.length : 0;
    });

    return items;
}

function addProductItem() {
    const items = collectItems();
    items.push(normalizeItem(defaultItem(), items.length));
    activeItemIndex = items.length - 1;
    expandAllItems = false;
    mountItems(items);
}

function removeProductItem(index) {
    const items = collectItems().filter((item) => item._index !== index);
    activeItemIndex = Math.max(0, Math.min(activeItemIndex, items.length - 1));
    mountItems(items.length ? items : [normalizeItem(defaultItem(), 0)]);
}

function addPrintRow(itemIndex, printRow = null) {
    const items = collectItems();
    const target = items[itemIndex];
    if (!target) {
        return;
    }
    const debugItem = ensureQuotePrintDebugItem(itemIndex, target._stable_key || '');
    debugItem.lastClickAt = debugNow();
    debugItem.lastClickReason = 'add-print';
    debugItem.lastAddBefore = Array.isArray(target.prints) ? target.prints.length : 0;
    target.prints = Array.isArray(target.prints) ? target.prints : [];
    target.has_print = true;
    target.prints.push(createDefaultPrintForItem(target, target.prints.length, printRow || {}));
    debugItem.lastAddAfter = target.prints.length;
    quotePrintDebugLog('add-print-row', {
        itemIndex,
        before: debugItem.lastAddBefore,
        after: debugItem.lastAddAfter,
        generatedLabel: printRowCode(itemIndex, target.prints.length - 1),
        generatedKey: target.prints[target.prints.length - 1]?._stable_key || '',
    });
    activeItemIndex = itemIndex;
    mountItems(items);
}

function handleHasPrintToggle(itemIndex, enabled) {
    const items = collectItems();
    const target = items[itemIndex];
    if (!target) {
        return false;
    }

    if (enabled) {
        ensureItemHasFirstPrintRow(target);
        activeItemIndex = itemIndex;
        mountItems(items);
        return true;
    }

    if (itemHasMeaningfulPrintData(target) && !window.confirm('Bu üründeki baskı satırları kaldırılacak. Devam edilsin mi?')) {
        return false;
    }

    target.has_print = false;
    target.prints = [];
    activeItemIndex = itemIndex;
    mountItems(items);
    return true;
}

function removePrintRow(itemIndex, printIndex) {
    const items = collectItems();
    const target = items[itemIndex];
    if (!target) {
        return;
    }
    const debugItem = ensureQuotePrintDebugItem(itemIndex, target._stable_key || '');
    debugItem.lastClickAt = debugNow();
    debugItem.lastClickReason = 'remove-print';
    target.prints = target.prints.filter((_, index) => index !== printIndex);
    if (!target.prints.length) {
        target.prints = [];
        target.has_print = false;
    }

    activeItemIndex = itemIndex;
    mountItems(items);
    recalculateTotals();
}

function resolveWarningBadges(entry) {
    return Array.isArray(entry?.warning_badges) && entry.warning_badges.includes('Kırmızı Ürün')
        ? ['Kırmızı Ürün']
        : [];
}

function resolveWarningMessages(entry) {
    return entry?.warning_messages || [];
}

function buildCatalogResult(entry) {
    const code = entry.product_code || '-';
    const label = entry.product_name || '-';
    const thumbnail = entry.image_url || '';
    const badges = resolveWarningBadges(entry);
    const metaLine = buildCompactProductMetaLine(entry, entry, { includePrice: true });
    const entryKey = rememberCatalogEntry(entry);

    return `
    <button type="button" class="pd-catalog-result" data-entry-key="${escapeHtml(entryKey)}">
        <div class="flex items-start gap-3">
            ${thumbnail ? `<img src="${escapeHtml(thumbnail)}" alt="Ürün" class="pd-catalog-result-thumb">` : ''}
            <div class="min-w-0">
                <div class="font-medium text-slate-800">${escapeHtml(label)}</div>
                <div class="text-xs text-slate-500">${escapeHtml(metaLine)}</div>
                <div class="pd-chip-row mt-1">
                    ${badgeHtml(entry.catalog_source === 'local_product' ? 'Local Ürün' : 'Tedarikçi Ürünü', entry.catalog_source === 'local_product' ? 'green' : 'blue')}
                    ${((entry.visible_stock_quantity ?? 0) <= 0) ? badgeHtml('Stok Yok', 'red') : ''}
                    ${badges.map((badge) => badgeHtml(badge, 'amber')).join('')}
                </div>
            </div>
        </div>
    </button>
    `;
}

function normalizeCatalogResults(payload) {
    if (Array.isArray(payload)) {
        return payload;
    }

    if (Array.isArray(payload?.results)) {
        return payload.results;
    }

    if (Array.isArray(payload?.items)) {
        return payload.items;
    }

    return [];
}

function renderCatalogResults(itemElement, results) {
    const resultsBox = itemElement.querySelector('.pd-catalog-results');
    if (!results.length) {
        resultsBox.innerHTML = '<div class="pd-catalog-result text-sm text-slate-500">Sonuç bulunamadı.</div>';
        showCatalogResults(itemElement);
        return;
    }

    let html = '';
    results.forEach((entry) => {
        html += buildCatalogResult(entry);
    });

    resultsBox.innerHTML = html;
    showCatalogResults(itemElement);
}

function positionCatalogResults(itemElement) {
    const searchBox = itemElement.querySelector('.pd-catalog-search');
    const resultsBox = itemElement.querySelector('.pd-catalog-results');

    if (!searchBox || !resultsBox || resultsBox.classList.contains('hidden')) {
        return;
    }

    resultsBox.style.top = 'calc(100% + 4px)';
    resultsBox.style.bottom = 'auto';

    window.requestAnimationFrame(() => {
        const searchRect = searchBox.getBoundingClientRect();
        const viewportPadding = 16;
        const preferredHeight = Math.min(resultsBox.scrollHeight || 320, 320);
        const availableBelow = Math.max(window.innerHeight - searchRect.bottom - viewportPadding, 120);
        const availableAbove = Math.max(searchRect.top - viewportPadding, 120);

        if (availableBelow < Math.min(preferredHeight, 220) && availableAbove > availableBelow) {
            resultsBox.style.top = 'auto';
            resultsBox.style.bottom = 'calc(100% + 4px)';
            resultsBox.style.maxHeight = `${Math.min(availableAbove, 320)}px`;
            return;
        }

        resultsBox.style.top = 'calc(100% + 4px)';
        resultsBox.style.bottom = 'auto';
        resultsBox.style.maxHeight = `${Math.min(availableBelow, 320)}px`;
    });
}

function showCatalogResults(itemElement) {
    const resultsBox = itemElement.querySelector('.pd-catalog-results');

    if (!resultsBox) {
        return;
    }

    itemElement.classList.add('is-search-open');
    resultsBox.classList.remove('hidden');
    positionCatalogResults(itemElement);
}

function hideCatalogResults(itemElement) {
    const resultsBox = itemElement.querySelector('.pd-catalog-results');

    if (!resultsBox) {
        return;
    }

    itemElement.classList.remove('is-search-open');
    resultsBox.classList.add('hidden');
    resultsBox.style.top = 'calc(100% + 4px)';
    resultsBox.style.bottom = 'auto';
    resultsBox.style.maxHeight = '320px';
}

function updateItemSummary(itemElement, entry) {
    const itemIndex = Number(itemElement.dataset.itemIndex);
    const items = collectItems();
    const target = items[itemIndex];
    if (!target) {
        return;
    }
    let normalizedEntry;

    try {
        normalizedEntry = normalizeCatalogSelectionEntry(entry, target);
    } catch (error) {
        target._row_error = 'Seçilen ürün bilgisi güvenli şekilde işlenemedi. Lütfen ürünü yeniden seçin.';
        target._error_path = `items.${itemIndex}.product_snapshot`;
        items[itemIndex] = target;
        setClientFormError('Seçilen ürün bilgisi işlenemedi. Lütfen hatalı satırı kontrol edin.');
        mountItems(items);
        return;
    }

    const selectedPrice = normalizedEntry.list_price;
    const selectedStock = normalizedEntry.visible_stock_quantity;
    const localStock = normalizedEntry.local_stock_quantity;
    const supplierStock = normalizedEntry.supplier_stock_quantity;
    const sourceSummary = normalizedEntry.sourceSummary;

    target._stable_key = target._stable_key || itemElement.dataset.stableKey || defaultItem()._stable_key;
    target.product_name = normalizedEntry.product_name || target.product_name || '';
    target.product_code = normalizedEntry.product_code || target.product_code || '';
    target.quantity = formatInputNumber(firstFilledValue([target.quantity, 1], 1));
    target.list_price = selectedPrice.toFixed(2);
    target.discount_rate = '0';
    target.unit_price = selectedPrice.toFixed(2);
    target.manual_unit_price = false;
    target.calculated_unit_price = selectedPrice.toFixed(2);
    target.catalog_source = normalizedEntry.catalog_source;
    target.tenant_catalog_product_id = normalizedEntry.tenant_catalog_product_id;
    target.tenant_catalog_product_variant_id = normalizedEntry.tenant_catalog_product_variant_id;
    target.standard_product_id = normalizedEntry.standard_product_id;
    target.standard_product_variant_id = normalizedEntry.standard_product_variant_id;
    target.supplier_id = sourceSummary[0]?.supplier_id || target.supplier_id || '';
    target.supplier_source_id = sourceSummary[0]?.supplier_source_id || target.supplier_source_id || '';
    target.quote_item_id = '';
    target.selected_catalog_identity = normalizedEntry.selected_catalog_identity;
    target._row_error = '';
    target._error_path = '';
    clearClientFormError();
    target.product_snapshot = {
        ...safeObject(target.product_snapshot),
        ...normalizedEntry.product_snapshot,
        tenant_catalog_product_id: normalizedEntry.tenant_catalog_product_id || null,
        tenant_catalog_product_variant_id: normalizedEntry.tenant_catalog_product_variant_id || null,
        product_code: target.product_code,
        product_name: target.product_name,
        image_url: normalizedEntry.image_url,
        catalog_source_label: normalizedEntry.catalog_source === 'local_product' ? 'Local Ürün' : 'Tedarikçi Ürünü',
        local_stock_priority: normalizedEntry.local_stock_priority,
        local_stock_quantity: localStock,
        supplier_stock_quantity: supplierStock,
        visible_stock_quantity: selectedStock,
        source_summary: sourceSummary,
        warning_badges: normalizedEntry.warning_badges,
        warning_messages: normalizedEntry.warning_messages,
        supplier_name: normalizedEntry.supplier_name,
        category_name: normalizedEntry.category_name,
        visible_in_catalog: normalizedEntry.visible_in_catalog,
        visible_in_quote: normalizedEntry.visible_in_quote,
        is_warning_sellable: normalizedEntry.is_warning_sellable,
        warning_tone: normalizedEntry.warning_tone,
        warning_summary: normalizedEntry.warning_summary,
    };
    target.price_snapshot = {
        ...safeObject(target.price_snapshot),
        ...normalizedEntry.price_snapshot,
        ...normalizedEntry.quote_price_snapshot,
        list_price: selectedPrice,
        display_price: selectedPrice,
        currency: normalizedEntry.currency,
        document_currency: normalizedEntry.currency,
        quote_price_value: normalizedEntry.quote_price_value ?? selectedPrice,
        quote_currency: normalizedEntry.quote_currency || normalizedEntry.currency,
        quote_price_status: normalizedEntry.quote_price_status || 'not_required',
        quote_price_snapshot: normalizedEntry.quote_price_snapshot,
        vat_mode: invoiceStatusValue() === 'fatura' ? 'taxable' : 'none',
        invoice_status: invoiceStatusValue(),
        vat_rate: normalizedEntry.vat_rate,
        print_vat_rate: quoteWorkspace.defaultPrintVatRate || 20,
        warning_badges: normalizedEntry.warning_badges,
        warning_messages: normalizedEntry.warning_messages,
        net_price_warning: normalizedEntry.net_price_warning,
        price_policy_warning: normalizedEntry.price_policy_warning,
        pricing_policy_type: normalizedEntry.pricing_policy_type,
        supplier_warning_flag: normalizedEntry.supplier_warning_flag,
        supplier_warning_type: normalizedEntry.supplier_warning_type,
    };
    target.stock_snapshot = {
        ...safeObject(target.stock_snapshot),
        ...normalizedEntry.stock_snapshot,
        total_stock_quantity: normalizedEntry.total_stock_quantity,
        local_stock_quantity: localStock,
        supplier_stock_quantity: supplierStock,
        visible_stock_quantity: selectedStock,
        safe_stock_quantity: normalizedEntry.safe_stock_quantity,
        local_stock_priority: normalizedEntry.local_stock_priority,
        stock_status: selectedStock > 0 ? 'available' : 'out_of_stock',
        warning_flag: !!firstFilledValue([
            normalizedEntry.entry.warning_flag,
            normalizedEntry.stock_snapshot.warning_flag,
            safeObject(target.stock_snapshot).warning_flag,
            false,
        ], false),
    };
    target.warning_badges = normalizedEntry.warning_badges;
    target.warning_messages = normalizedEntry.warning_messages;
    target.line_total = (selectedPrice * Number(target.quantity || 0)).toFixed(2);
    target.prints = (target.prints || []).map((printRow, index) => normalizePrint({
        ...printRow,
        print_quantity: printRow._manual_quantity ? printRow.print_quantity : (target.quantity || printRow.print_quantity || ''),
    }, index));
    activeItemIndex = itemIndex;
    mountItems(items);
}

async function performCatalogSearch(itemElement, term) {
    const resultsBox = itemElement.querySelector('.pd-catalog-results');

    if (!resultsBox) {
        return;
    }

    if (term.length < 3) {
        resultsBox.innerHTML = '';
        hideCatalogResults(itemElement);
        return;
    }

    const params = new URLSearchParams();
    params.set('q', term);
    params.set('currency', resolveQuoteCurrencyCode());
    params.set('quote_date', currentQuoteDate());
    params.set('only_visible', '1');
    params.set('only_quote_visible', '1');

    resultsBox.innerHTML = '<div class="pd-catalog-result text-sm text-slate-500">Aranıyor...</div>';
    showCatalogResults(itemElement);

    try {
        const response = await fetch(`${quoteWorkspace.searchUrl}?${params.toString()}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('Catalog search failed');
        }

        const payload = await response.json();
        const results = normalizeCatalogResults(payload);
        renderCatalogResults(itemElement, results);
    } catch (error) {
        resultsBox.innerHTML = '<div class="pd-catalog-result text-sm text-red-600">Ürün araması şu anda tamamlanamadı.</div>';
        showCatalogResults(itemElement);
    }
}

function recalculateTotals() {
    let subtotal = 0;
    let productSubtotal = 0;
    let printSubtotal = 0;
    let vatTotal = 0;
    let grandTotal = 0;
    let quantityTotal = 0;
    let printCount = 0;
    let vatAppliedCount = 0;
    let warningCount = 0;
    let missingPriceCount = 0;
    let stockWarningCount = 0;
    const currency = document.querySelector('select[name="currency"]')?.value || quoteWorkspace.currency || 'TRY';
    const itemElements = Array.from(document.querySelectorAll('.pd-quote-item'));
    const vatTotalsByRate = new Map();

    itemElements.forEach((element) => {
        const quantity = Number(element.querySelector('input[name$="[quantity]"]')?.value || 0);
        const listPrice = Number(element.querySelector('input[name$="[list_price]"]')?.value || 0);
        const discountRate = Number(element.querySelector('input[name$="[discount_rate]"]')?.value || 0);
        const invoiceStatus = invoiceStatusValue();
        const vatMode = invoiceStatus === 'fatura' ? 'taxable' : 'none';
        const calculatedUnitPrice = Number((listPrice * (1 - (discountRate / 100))).toFixed(2));
        const manualUnitPriceInput = element.querySelector('input[data-manual-unit-price]');
        const calculatedUnitPriceInput = element.querySelector('input[data-calculated-unit-price]');
        const isManualUnitPrice = manualUnitPriceInput?.value === '1';
        const unitPrice = isManualUnitPrice
            ? Number(element.querySelector('input[name$="[unit_price]"]')?.value || 0)
            : calculatedUnitPrice;
        const productBaseTotal = unitPrice * quantity;
        let lineNetTotal = productBaseTotal;
        let printLineTotal = 0;
        const unitPriceInput = element.querySelector('input[name$="[unit_price]"]');
        const lineTotalInput = element.querySelector('input[name$="[line_total]"]');
        if (calculatedUnitPriceInput) {
            calculatedUnitPriceInput.value = calculatedUnitPrice.toFixed(2);
        }
        if (unitPriceInput && !isManualUnitPrice) {
            unitPriceInput.value = unitPrice.toFixed(2);
        }
        quantityTotal += quantity;

        const priceSnapshot = parseJsonValue(element.querySelector('input[name$="[price_snapshot]"]')?.value) || {};
        const productSnapshot = parseJsonValue(element.querySelector('input[name$="[product_snapshot]"]')?.value) || {};
        const stockSnapshot = parseJsonValue(element.querySelector('input[name$="[stock_snapshot]"]')?.value) || {};
        const itemVatRate = vatMode === 'taxable'
            ? Number(element.querySelector('input[name$="[vat_rate]"]')?.value || priceSnapshot.vat_rate || 20)
            : 0;
        const defaultPrintVatRate = Number(priceSnapshot.print_vat_rate || quoteWorkspace.defaultPrintVatRate || 20) || 20;
        let lineVatTotal = 0;
        let lineGrossTotal = lineNetTotal;
        const lineVatBreakdownMap = new Map();
        const badges = [...new Set([...(productSnapshot.warning_badges || []), ...(priceSnapshot.warning_badges || [])])];
        if (badges.length) warningCount += 1;
        if (badges.includes('Fiyat eksik')) missingPriceCount += 1;
        if (badges.includes('Stok yok')) stockWarningCount += 1;
        if (vatMode !== 'none') vatAppliedCount += 1;

        if (element.querySelector('.quote-has-print')?.checked) {
            element.querySelectorAll('[data-print-list] .pd-print-operation').forEach((printElement) => {
                const printQuantity = Number(printElement.querySelector('input[name*="[print_quantity]"]')?.value || 0);
                const printUnitPrice = Number(printElement.querySelector('input[name*="[print_unit_price]"]')?.value || 0);
                const printTotal = printQuantity * printUnitPrice;
                const printTotalInput = printElement.querySelector('input[name*="[print_total]"]');
                const printVatRateInput = printElement.querySelector('input[name*="[print_vat_rate]"]');
                if (printTotalInput) printTotalInput.value = printTotal.toFixed(2);
                if (printVatRateInput) printVatRateInput.value = String(defaultPrintVatRate);
                printLineTotal += printTotal;
                printCount += 1;
                if (vatMode !== 'none' && defaultPrintVatRate > 0) {
                    const printVatTotal = printTotal * (defaultPrintVatRate / 100);
                    vatTotalsByRate.set(defaultPrintVatRate, (vatTotalsByRate.get(defaultPrintVatRate) || 0) + printVatTotal);
                    lineVatBreakdownMap.set(defaultPrintVatRate, (lineVatBreakdownMap.get(defaultPrintVatRate) || 0) + printVatTotal);
                    lineVatTotal += printVatTotal;
                    lineGrossTotal += printVatTotal;
                }
            });
        }

        lineNetTotal = productBaseTotal + printLineTotal;
        lineGrossTotal = lineNetTotal;

        if (vatMode !== 'none' && itemVatRate > 0) {
            const productVatTotal = productBaseTotal * (itemVatRate / 100);
            vatTotalsByRate.set(itemVatRate, (vatTotalsByRate.get(itemVatRate) || 0) + productVatTotal);
            lineVatBreakdownMap.set(itemVatRate, (lineVatBreakdownMap.get(itemVatRate) || 0) + productVatTotal);
            lineVatTotal += productVatTotal;
            lineGrossTotal += productVatTotal;
        }

        if (lineTotalInput) lineTotalInput.value = productBaseTotal.toFixed(2);

        priceSnapshot.invoice_status = invoiceStatus;
        priceSnapshot.vat_mode = vatMode;
        priceSnapshot.vat_rate = itemVatRate;
        priceSnapshot.print_vat_rate = defaultPrintVatRate;
        priceSnapshot.calculated_unit_price = Number(calculatedUnitPrice.toFixed(2));
        priceSnapshot.manual_unit_price = isManualUnitPrice;
        priceSnapshot.manual_sales_price_override = isManualUnitPrice;
        priceSnapshot.document_currency = currency;
        priceSnapshot.quote_currency = priceSnapshot.quote_currency || currency;
        if (isManualUnitPrice) {
            priceSnapshot.manual_entry_currency = currency;
            priceSnapshot.manual_entry_amount = Number(unitPrice.toFixed(2));
            priceSnapshot.actual_sales_unit_price_document = Number(unitPrice.toFixed(2));
        } else {
            priceSnapshot.manual_entry_currency = null;
            priceSnapshot.manual_entry_amount = null;
            priceSnapshot.suggested_sales_unit_price_document = Number(calculatedUnitPrice.toFixed(2));
            priceSnapshot.actual_sales_unit_price_document = Number(unitPrice.toFixed(2));
        }
        priceSnapshot.product_line_total = Number(productBaseTotal.toFixed(2));
        priceSnapshot.print_line_total = Number(printLineTotal.toFixed(2));
        priceSnapshot.product_total = Number(productBaseTotal.toFixed(2));
        priceSnapshot.print_total = Number(printLineTotal.toFixed(2));
        priceSnapshot.subtotal = Number((productBaseTotal + printLineTotal).toFixed(2));
        priceSnapshot.line_net_total = Number(lineNetTotal.toFixed(2));
        priceSnapshot.line_vat_total = Number(lineVatTotal.toFixed(2));
        priceSnapshot.line_gross_total = Number(lineGrossTotal.toFixed(2));
        priceSnapshot.product_vat_total = Number((vatMode !== 'none' && itemVatRate > 0 ? productBaseTotal * (itemVatRate / 100) : 0).toFixed(2));
        priceSnapshot.print_vat_total = Number((vatMode !== 'none' && defaultPrintVatRate > 0 ? printLineTotal * (defaultPrintVatRate / 100) : 0).toFixed(2));
        priceSnapshot.vat_breakdown = [...lineVatBreakdownMap.entries()].map(([rate, total]) => ({ rate, total: Number(total.toFixed(2)) }));
        priceSnapshot.vat_total = Number(lineVatTotal.toFixed(2));
        priceSnapshot.grand_total = Number(lineGrossTotal.toFixed(2));
        const priceSnapshotInput = element.querySelector('input[name$="[price_snapshot]"]');
        if (priceSnapshotInput) {
            priceSnapshotInput.value = JSON.stringify(priceSnapshot);
        }

        productSubtotal += productBaseTotal;
        subtotal += lineNetTotal;
        printSubtotal += printLineTotal;
        vatTotal += lineVatTotal;
        grandTotal += lineGrossTotal;
    });
    const summaryItemCount = document.getElementById('summary-item-count');
    const summaryPrintCount = document.getElementById('summary-print-count');
    if (summaryItemCount) {
        summaryItemCount.textContent = `${itemElements.length} kalem`;
    }
    if (summaryPrintCount) {
        summaryPrintCount.textContent = `${printCount} işlem`;
    }
    if (quoteWorkspace.canViewFinancialData) {
        document.getElementById('summary-product-total').textContent = formatMoney(productSubtotal, currency);
        document.getElementById('summary-subtotal').textContent = formatMoney(subtotal, currency);
        document.getElementById('summary-print-total').textContent = formatMoney(printSubtotal, currency);
        document.getElementById('summary-vat').textContent = formatMoney(vatTotal, currency);
        document.getElementById('summary-grand-total').textContent = formatMoney(grandTotal, currency);
        const vatRow = document.getElementById('summary-vat-total-row');
        const vatLabel = document.getElementById('summary-vat-label');
        const vatBreakdown = document.getElementById('summary-vat-breakdown');
        if (vatAppliedCount > 0) {
            vatRow?.classList.remove('hidden');
            if (vatBreakdown) {
                vatBreakdown.classList.remove('hidden');
                const breakdownRows = [...vatTotalsByRate.entries()]
                    .sort((a, b) => Number(b[0]) - Number(a[0]))
                    .map(([rate, total]) => `<div class="pd-summary-row"><span>KDV %${String(rate).replace('.', ',')}</span><strong>${formatMoney(total, currency)}</strong></div>`)
                    .join('');
                vatBreakdown.innerHTML = `
                    <div class="pd-summary-section-title">KDV Dağılımı</div>
                    ${breakdownRows}
                `;
            }
            if (vatLabel) {
                vatLabel.textContent = 'KDV Toplamı';
            }
        } else {
            vatRow?.classList.add('hidden');
            if (vatBreakdown) {
                vatBreakdown.classList.add('hidden');
                vatBreakdown.innerHTML = '';
            }
            if (vatLabel) {
                vatLabel.textContent = 'KDV Toplamı';
            }
        }
    }
}

function refreshCustomerSummary() {
    const selectedCard = document.getElementById('quote-customer-selected-card');
    const emptyState = document.getElementById('quote-customer-empty-state');
    const searchInput = document.getElementById('quote-customer-search');
    const searchBox = document.querySelector('.pd-customer-search-box');
    const selectedOption = getCustomerSelect()?.selectedOptions?.[0];
    const customerId = getCustomerSelect()?.value ? String(getCustomerSelect().value) : '';

    if (!selectedCard || !searchInput || !searchBox) {
        return;
    }

    const customer = customerId ? customerLookup.get(customerId) : null;

    if (!customer && selectedOption && selectedOption.value) {
        const fallbackCustomer = {
            id: selectedOption.value,
            display_name: selectedOption.textContent.trim(),
            legal_name: selectedOption.textContent.trim(),
            email: '',
            phone: '',
            tax_number: '',
            contact_name: '',
            current_account_id: null,
            label: selectedOption.textContent.trim(),
            summary: selectedOption.textContent.trim(),
        };
        customerLookup.set(String(fallbackCustomer.id), fallbackCustomer);
    }

    const resolvedCustomer = customerId ? customerLookup.get(customerId) : null;

    if (!resolvedCustomer) {
        selectedCard.classList.add('hidden');
        selectedCard.innerHTML = '';
        searchBox.classList.remove('is-selected');
        emptyState?.classList.add('hidden');
        if (!searchInput.value.trim()) {
            setCustomerSearchStatus('Müşteri aramak için en az 3 karakter yazın.');
        }
        refreshCustomerSearchDropdown();
        return;
    }

    searchInput.value = resolvedCustomer.display_name || resolvedCustomer.legal_name || '';
    const customerName = resolvedCustomer.legal_name || resolvedCustomer.display_name || resolvedCustomer.label || '';
    const contactName = resolvedCustomer.contact_name || '';
    const phone = resolvedCustomer.phone || '';
    const email = resolvedCustomer.email || '';
    const taxNumber = resolvedCustomer.tax_number || '';
    const currentAccountLabel = resolvedCustomer.current_account_id ? 'Hazır' : 'Bilgi yok';
    const portalLabel = resolvedCustomer.portal_status_label || 'Bilgi yok';
    const deliveryLabel = resolvedCustomer.default_delivery_type_name || 'Bilgi yok';
    selectedCard.innerHTML = `
        <div class="pd-customer-selected-title">
            <span>Seçili Müşteri</span>
            <div class="flex items-center gap-2">
                <span class="pd-badge pd-badge-green">Seçili</span>
                <button type="button" class="pd-btn pd-btn-light pd-btn-xs" data-action="reset-selected-customer">Değiştir</button>
            </div>
        </div>
        <div class="pd-customer-selected-grid">
            <div class="pd-customer-selected-primary">
                <div class="pd-customer-selected-name">${escapeHtml(customerName)}</div>
                <div class="pd-customer-selected-list">
                    ${contactName ? `<div><strong>Yetkili:</strong> ${escapeHtml(contactName)}</div>` : ''}
                    ${phone ? `<div><strong>WhatsApp:</strong> ${escapeHtml(phone)}</div>` : ''}
                    ${email ? `<div><strong>E-posta:</strong> ${escapeHtml(email)}</div>` : ''}
                    ${taxNumber ? `<div><strong>VKN/TCKN:</strong> ${escapeHtml(taxNumber)}</div>` : ''}
                    ${!contactName && !phone && !email && !taxNumber ? `<div>${escapeHtml(resolvedCustomer.summary || customerName)}</div>` : ''}
                </div>
            </div>
            <div class="pd-customer-selected-secondary">
                <div class="pd-customer-mini-row"><span>Cari durumu</span><strong>${escapeHtml(currentAccountLabel)}</strong></div>
                <div class="pd-customer-mini-row"><span>Varsayılan teslimat</span><strong>${escapeHtml(deliveryLabel)}</strong></div>
                <div class="pd-customer-mini-row"><span>Portal durumu</span><strong>${escapeHtml(portalLabel)}</strong></div>
            </div>
        </div>
    `;
    selectedCard.classList.remove('hidden');
    searchBox.classList.add('is-selected');
    emptyState?.classList.add('hidden');
    clearCustomerSearchResults();
    setCustomerSearchStatus('');
    refreshCustomerSearchDropdown();
}

function resetSelectedCustomer() {
    const select = getCustomerSelect();
    const searchInput = document.getElementById('quote-customer-search');

    if (!select || !searchInput) {
        return;
    }

    select.value = '';
    searchInput.value = '';
    refreshCustomerSummary();
    searchInput.focus();
}

function getCustomerSelect() {
    return document.getElementById('customer-select');
}

function setCustomerSearchStatus(message = '', tone = '') {
    const status = document.getElementById('quote-customer-search-status');
    if (!status) {
        return;
    }

    status.textContent = message;
    status.classList.remove('is-warning', 'is-danger');

    if (tone === 'warning') {
        status.classList.add('is-warning');
    } else if (tone === 'danger') {
        status.classList.add('is-danger');
    }
}

function clearCustomerSearchResults() {
    const results = document.getElementById('quote-customer-search-results');
    if (!results) {
        return;
    }

    results.innerHTML = '';
    results.classList.add('hidden');
    refreshCustomerSearchDropdown();
}

function setCustomerNotFoundState(visible) {
    document.getElementById('quote-customer-empty-state')?.classList.toggle('hidden', !visible);
    refreshCustomerSearchDropdown();
}

function refreshCustomerSearchDropdown() {
    const dropdown = document.getElementById('quote-customer-search-dropdown');
    const results = document.getElementById('quote-customer-search-results');
    const emptyState = document.getElementById('quote-customer-empty-state');

    if (!dropdown) {
        return;
    }

    const hasVisibleResults = !!results && !results.classList.contains('hidden');
    const hasVisibleEmptyState = !!emptyState && !emptyState.classList.contains('hidden');

    dropdown.classList.toggle('hidden', !(hasVisibleResults || hasVisibleEmptyState));
}

function closeCustomerSearchDropdown() {
    clearCustomerSearchResults();
    setCustomerNotFoundState(false);
}

function registerCustomerOption(customer) {
    if (!customer || !customer.id) {
        return null;
    }

    customerLookup.set(String(customer.id), customer);

    const select = getCustomerSelect();
    if (!select) {
        return null;
    }

    let option = Array.from(select.options).find((item) => String(item.value) === String(customer.id));

    if (!option) {
        option = new Option(customer.legal_name || customer.display_name || customer.label || `Müşteri #${customer.id}`, String(customer.id));
        select.add(option);
    } else {
        option.text = customer.legal_name || customer.display_name || customer.label || option.text;
    }

    return option;
}

function selectCustomer(customer) {
    const select = getCustomerSelect();
    if (!select || !customer?.id) {
        return;
    }

    registerCustomerOption(customer);
    select.value = String(customer.id);
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

function renderCustomerSearchResults(customers = []) {
    const results = document.getElementById('quote-customer-search-results');
    if (!results) {
        return;
    }

    if (!customers.length) {
        clearCustomerSearchResults();
        return;
    }

    results.innerHTML = customers.map((customer) => `
        <button type="button" class="pd-customer-search-result" data-customer-result="${escapeHtml(customer.id)}">
            <div class="pd-customer-search-result-title">${escapeHtml(customer.legal_name || customer.display_name || customer.label || '')}</div>
            <div class="pd-customer-search-result-meta">${escapeHtml(customer.summary || '')}</div>
        </button>
    `).join('');
    results.classList.remove('hidden');
    refreshCustomerSearchDropdown();
}

async function performCustomerSearch(term) {
    const query = String(term || '').trim();

    if (query.length < 3) {
        clearCustomerSearchResults();
        setCustomerNotFoundState(false);
        setCustomerSearchStatus('Müşteri aramak için en az 3 karakter yazın.');
        return;
    }

    if (!quoteWorkspace.customerSearchUrl) {
        setCustomerSearchStatus('Müşteri arama servisi şu anda kullanılamıyor.', 'danger');
        return;
    }

    try {
        setCustomerSearchStatus('Müşteri aranıyor...');
        const response = await fetch(`${quoteWorkspace.customerSearchUrl}?q=${encodeURIComponent(query)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });

        const payload = await response.json();
        const customers = Array.isArray(payload.data) ? payload.data : [];
        customers.forEach((customer) => customerLookup.set(String(customer.id), customer));

        if (!response.ok) {
            throw new Error(payload?.message || 'customer-search-failed');
        }

        renderCustomerSearchResults(customers);

        if (customers.length) {
            setCustomerNotFoundState(false);
            setCustomerSearchStatus(`${customers.length} müşteri bulundu.`);
            return;
        }

        clearCustomerSearchResults();
        setCustomerNotFoundState(true);
        setCustomerSearchStatus(payload?.meta?.message || 'Müşteri bulunamadı. Hızlı müşteri ekleyebilirsiniz.', 'warning');
    } catch (error) {
        clearCustomerSearchResults();
        setCustomerNotFoundState(false);
        setCustomerSearchStatus('Müşteri araması şu anda tamamlanamadı. Tekrar deneyin.', 'danger');
    }
}

function openQuickCustomerModal() {
    const modal = document.getElementById('quick-customer-modal');
    if (!modal) {
        return;
    }

    quickCustomerModalSnapshot = {
        legal_name: document.getElementById('quick-customer-legal-name')?.value || '',
        tax_number: document.getElementById('quick-customer-tax-number')?.value || '',
        identity_type: document.getElementById('quick-customer-identity-type')?.value || 'company',
        email: document.getElementById('quick-customer-email')?.value || '',
        phone: document.getElementById('quick-customer-phone')?.value || '',
        contact_name: document.getElementById('quick-customer-contact-name')?.value || '',
        city: document.getElementById('quick-customer-city')?.value || '',
        address_note: document.getElementById('quick-customer-address-note')?.value || '',
    };

    const searchValue = document.getElementById('quote-customer-search')?.value?.trim() || '';
    if (!document.getElementById('quick-customer-legal-name')?.value && searchValue.length >= 3) {
        document.getElementById('quick-customer-legal-name').value = searchValue;
    }

    renderQuickCustomerErrors({});
    modal.classList.remove('hidden');
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeQuickCustomerModal({ restore = false } = {}) {
    const modal = document.getElementById('quick-customer-modal');
    if (!modal) {
        return;
    }

    if (restore && quickCustomerModalSnapshot) {
        document.getElementById('quick-customer-legal-name').value = quickCustomerModalSnapshot.legal_name || '';
        document.getElementById('quick-customer-tax-number').value = quickCustomerModalSnapshot.tax_number || '';
        document.getElementById('quick-customer-identity-type').value = quickCustomerModalSnapshot.identity_type || 'company';
        document.getElementById('quick-customer-email').value = quickCustomerModalSnapshot.email || '';
        document.getElementById('quick-customer-phone').value = quickCustomerModalSnapshot.phone || '';
        document.getElementById('quick-customer-contact-name').value = quickCustomerModalSnapshot.contact_name || '';
        document.getElementById('quick-customer-city').value = quickCustomerModalSnapshot.city || '';
        document.getElementById('quick-customer-address-note').value = quickCustomerModalSnapshot.address_note || '';
    }

    modal.classList.add('hidden');
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function renderQuickCustomerErrors(errors = {}) {
    const box = document.getElementById('quick-customer-form-errors');
    if (!box) {
        return;
    }

    const messages = Object.values(errors).flat().filter(Boolean);
    if (!messages.length) {
        box.classList.add('hidden');
        box.innerHTML = '';
        return;
    }

    box.innerHTML = messages.map((message) => `<div>${escapeHtml(message)}</div>`).join('');
    box.classList.remove('hidden');
}

async function submitQuickCustomerForm() {
    if (!quoteWorkspace.quickCustomerStoreUrl) {
        renderQuickCustomerErrors({
            general: ['Hızlı müşteri ekleme servisi şu anda kullanılamıyor.'],
        });
        return;
    }

    const submitButton = document.getElementById('quick-customer-save-button');
    const payload = {
        legal_name: document.getElementById('quick-customer-legal-name')?.value?.trim() || '',
        tax_number: document.getElementById('quick-customer-tax-number')?.value?.trim() || '',
        identity_type: document.getElementById('quick-customer-identity-type')?.value || 'company',
        email: document.getElementById('quick-customer-email')?.value?.trim() || '',
        phone: document.getElementById('quick-customer-phone')?.value?.trim() || '',
        contact_name: document.getElementById('quick-customer-contact-name')?.value?.trim() || '',
        city: document.getElementById('quick-customer-city')?.value?.trim() || '',
        address_note: document.getElementById('quick-customer-address-note')?.value?.trim() || '',
    };

    try {
        if (submitButton) {
            submitButton.disabled = true;
        }

        renderQuickCustomerErrors({});

        const response = await fetch(quoteWorkspace.quickCustomerStoreUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
            body: JSON.stringify(payload),
        });

        const result = await response.json();

        if (!response.ok) {
            renderQuickCustomerErrors(result.errors || { general: [result.message || 'Müşteri kaydedilemedi.'] });
            return;
        }

        if (result.data) {
            registerCustomerOption(result.data);
            selectCustomer(result.data);
            document.getElementById('quote-customer-search').value = result.data.display_name || result.data.legal_name || '';
        }

        setCustomerSearchStatus(result.message || 'Müşteri kaydedildi ve teklif formuna seçildi.');
        closeQuickCustomerModal();
    } catch (error) {
        renderQuickCustomerErrors({
            general: ['Müşteri kaydedilemedi. Alanları kontrol edip tekrar deneyin.'],
        });
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const initialItems = quoteWorkspace.items?.length ? quoteWorkspace.items : [defaultItem()];
    mountItems(initialItems);

    const initialErrorTarget = document.querySelector('[data-error-target]')?.dataset.errorTarget || '';
    if (initialErrorTarget) {
        window.setTimeout(() => {
            focusValidationTarget(initialErrorTarget);
        }, 40);
    }

    document.getElementById('add-product-item')?.addEventListener('click', addProductItem);
    document.getElementById('customer-select')?.addEventListener('change', refreshCustomerSummary);
    document.getElementById('quote-customer-search')?.addEventListener('input', (event) => {
        clearTimeout(customerSearchTimer);
        customerSearchTimer = setTimeout(() => performCustomerSearch(event.target.value), 250);
    });
    document.getElementById('quote-customer-search-results')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-customer-result]');
        if (!button) {
            return;
        }

        const customer = customerLookup.get(String(button.dataset.customerResult));
        if (customer) {
            selectCustomer(customer);
        }
    });
    document.getElementById('quick-customer-open-button')?.addEventListener('click', openQuickCustomerModal);
    document.getElementById('quick-customer-empty-button')?.addEventListener('click', openQuickCustomerModal);
    document.getElementById('quick-customer-cancel-button')?.addEventListener('click', () => closeQuickCustomerModal({ restore: true }));
    document.getElementById('quick-customer-save-button')?.addEventListener('click', submitQuickCustomerForm);
    document.getElementById('quick-customer-modal')?.addEventListener('click', (event) => {
        if (event.target.id === 'quick-customer-modal') {
            closeQuickCustomerModal({ restore: true });
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && document.getElementById('quick-customer-modal')?.classList.contains('is-open')) {
            closeQuickCustomerModal({ restore: true });
            return;
        }

        if (event.key === 'Escape') {
            closeCustomerSearchDropdown();
        }
    });
    document.getElementById('invoice-status-select')?.addEventListener('change', () => {
        mountItems(collectItems());
    });
    document.getElementById('quote-date-input')?.addEventListener('change', async (event) => {
        const deliveryInput = document.getElementById('delivery-date-input');
        if (deliveryInput && deliveryInput.dataset.manualOverride !== '1' && event.target.value) {
            const baseDate = new Date(`${event.target.value}T00:00:00`);
            if (!Number.isNaN(baseDate.getTime())) {
                baseDate.setDate(baseDate.getDate() + 7);
                deliveryInput.value = baseDate.toISOString().slice(0, 10);
            }
        }

        await repriceAllQuoteItems();
    });
    document.getElementById('delivery-date-input')?.addEventListener('change', () => {
        const deliveryInput = document.getElementById('delivery-date-input');
        if (deliveryInput) {
            deliveryInput.dataset.manualOverride = '1';
        }
    });

    document.addEventListener('input', (event) => {
        const itemElement = event.target.closest('.pd-quote-item');
        if (!itemElement) {
            if (event.target.name === 'currency') {
                recalculateTotals();
                refreshAllPrintCurrencyWarnings();
            }
            return;
        }

        if (event.target.classList.contains('catalog-search-input')) {
            const itemIndex = itemElement.dataset.itemIndex;
            clearTimeout(catalogSearchTimers.get(itemIndex));
            const timer = setTimeout(() => performCatalogSearch(itemElement, event.target.value.trim()), 300);
            catalogSearchTimers.set(itemIndex, timer);
            return;
        }

        if (event.target.classList.contains('print-quantity-input')) {
            event.target.dataset.manualQuantity = '1';
        }

        if (event.target.name?.includes('[quantity]') && !event.target.name?.includes('[print_quantity]')) {
            const quantityValue = event.target.value || '';
            itemElement.querySelectorAll('.print-quantity-input').forEach((input) => {
                if (input.dataset.manualQuantity !== '1') {
                    input.value = quantityValue;
                }
            });
        }

        if (
            event.target.name?.includes('[print_quantity]') ||
            event.target.name?.includes('[setup_total_amount]') ||
            event.target.name?.includes('[base_print_unit_price]') ||
            event.target.name?.includes('[print_unit_price]') ||
            event.target.name?.includes('[quantity]') ||
            event.target.name?.includes('[list_price]') ||
            event.target.name?.includes('[discount_rate]') ||
            event.target.name?.includes('[vat_rate]')
        ) {
            const printOperation = event.target.closest('.pd-print-operation');
            if (printOperation) {
                refreshPrintSetupPricing(printOperation);
            }
            recalculateTotals();
        }

        if (event.target.name?.includes('[print_unit_price]') || event.target.name?.includes('[base_print_unit_price]')) {
            event.target.dataset.priceSuggested = '0';
        }

        if (event.target.classList.contains('unit-price-input')) {
            const itemElement = event.target.closest('.pd-quote-item');
            itemElement?.querySelector('input[data-manual-unit-price]')?.setAttribute('value', '1');
            if (itemElement?.querySelector('input[data-manual-unit-price]')) {
                itemElement.querySelector('input[data-manual-unit-price]').value = '1';
            }
            recalculateTotals();
        }
    });

    document.addEventListener('change', async (event) => {
        if (event.target.classList.contains('quote-has-print')) {
            const itemElement = event.target.closest('.pd-quote-item');
            const itemIndex = Number(itemElement?.dataset.itemIndex ?? -1);
            const changed = handleHasPrintToggle(itemIndex, !!event.target.checked);
            if (!changed) {
                event.target.checked = true;
            }
            return;
        }

        if (event.target.classList.contains('print-type-select')) {
            const printOperation = event.target.closest('.pd-print-operation');
            const settingIdInput = printOperation?.querySelector('[data-print-setting-id]');
            const standardTypeInput = printOperation?.querySelector('[data-standard-print-type-id]');
            const printTypeInput = printOperation?.querySelector('[data-print-type-input]');
            const subcontractorCompanyInput = printOperation?.querySelector('[data-subcontractor-company-id]');
            const selectedValue = event.target.value || '';
            let effectivePrintType = '';
            let currentSetting = null;

            if (selectedValue.startsWith('setting:')) {
                const settingId = selectedValue.replace('setting:', '');
                const setting = tenantPrintSettingsById.get(String(settingId));
                if (setting) {
                    if (settingIdInput) settingIdInput.value = String(setting.id);
                    if (standardTypeInput) standardTypeInput.value = String(setting.standard_print_type_id || '');
                    if (printTypeInput) printTypeInput.value = setting.display_name || '';
                    if (subcontractorCompanyInput && !subcontractorCompanyInput.value && setting.default_subcontractor_company_id) {
                        subcontractorCompanyInput.value = String(setting.default_subcontractor_company_id);
                    }
                    applyTenantPrintSettingPriceSuggestion(printOperation, setting);
                    refreshPrintCurrencyWarning(printOperation, setting);

                    effectivePrintType = setting.display_name || '';
                    currentSetting = setting;
                }
            } else {
                const legacyType = selectedValue.replace('legacy:', '');
                if (settingIdInput) settingIdInput.value = '';
                if (standardTypeInput) standardTypeInput.value = '';
                const optionIdInput = printOperation?.querySelector('[data-print-option-id]');
                if (optionIdInput) optionIdInput.value = '';
                if (printTypeInput) printTypeInput.value = legacyType;
                effectivePrintType = legacyType;
                refreshPrintCurrencyWarning(printOperation, null);
            }

            syncPrintOptionState(printOperation, currentSetting, true);
            mountItems(collectItems());
            return;
        }

        if (event.target.classList.contains('print-option-select')) {
            const printOperation = event.target.closest('.pd-print-operation');
            syncPrintOptionState(printOperation);
            mountItems(collectItems());
            return;
        }

        if (event.target.name?.includes('[cliche_status]')) {
            const printOperation = event.target.closest('.pd-print-operation');
            refreshPrintSetupPricing(printOperation);
            recalculateTotals();
            return;
        }

        if (event.target.name === 'currency') {
            recalculateTotals();
            refreshAllPrintCurrencyWarnings();
            await repriceAllQuoteItems();
            return;
        }

        if (event.target.name === 'invoice_status') {
            recalculateTotals();
            refreshAllPrintCurrencyWarnings();
        }
    });

    document.addEventListener('click', (event) => {
        if (event.target.matches('[data-action="reset-selected-customer"]')) {
            resetSelectedCustomer();
            return;
        }

        if (!event.target.closest('[data-customer-picker]')) {
            closeCustomerSearchDropdown();
        }

        const setupModal = event.target.closest('[data-setup-modal]');
        if (setupModal) {
            const modalPrintRow = resolvePrintOperationForModal(setupModal);
            if (event.target.matches('[data-action="cancel-setup-modal"]') || event.target.matches('[data-action="close-setup-modal"]')) {
                toggleSetupModal(modalPrintRow, false, { revert: true });
                return;
            }
            if (event.target.matches('[data-action="apply-setup-modal"]')) {
                applySetupModal(modalPrintRow);
                return;
            }
        }

        const errorTargetButton = event.target.closest('[data-error-target]');
        if (errorTargetButton) {
            focusValidationTarget(errorTargetButton.dataset.errorTarget || '');
            return;
        }

        const resultButton = event.target.closest('.pd-catalog-result[data-entry-key]');
        if (resultButton) {
            const itemElement = resultButton.closest('.pd-quote-item');
            const entry = getCatalogEntry(resultButton.dataset.entryKey);
            if (!entry) {
                const items = collectItems();
                const itemIndex = Number(itemElement?.dataset.itemIndex ?? -1);
                if (items[itemIndex]) {
                    items[itemIndex]._row_error = 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.';
                    items[itemIndex]._error_path = `items.${itemIndex}.product_snapshot`;
                    mountItems(items);
                    setClientFormError('Teklif kaydedilemedi. Hatalı satırları kontrol edip tekrar deneyin.', [
                        {
                            path: `items.${itemIndex}.product_snapshot`,
                            label: buildValidationSummaryLabel('item', itemIndex),
                            message: 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.',
                        },
                    ]);
                    focusValidationTarget(`items.${itemIndex}.product_snapshot`);
                }
                hideCatalogResults(itemElement);
                return;
            }

            updateItemSummary(itemElement, entry);
            hideCatalogResults(itemElement);
            return;
        }
        const itemElement = event.target.closest('.pd-quote-item');
        if (itemElement) {
            const itemIndex = Number(itemElement.dataset.itemIndex);

            if (event.target.matches('[data-action="remove-item"]')) {
                removeProductItem(itemIndex);
                return;
            }

            if (event.target.matches('[data-action="add-print"]')) {
                addPrintRow(itemIndex);
                return;
            }

            const printRow = event.target.closest('.pd-print-operation');
            if (printRow) {
                const printIndex = Number(printRow.dataset.printIndex);
                if (event.target.matches('[data-action="remove-print"]')) {
                    removePrintRow(itemIndex, printIndex);
                    return;
                }
                if (event.target.matches('[data-action="open-setup-modal"]')) {
                    toggleSetupModal(printRow, true);
                    return;
                }
            }
        }

        if (!event.target.closest('.pd-catalog-search')) {
            document.querySelectorAll('.pd-quote-item').forEach((itemElement) => hideCatalogResults(itemElement));
        }
    });

    document.getElementById(canonicalQuoteFormId)?.addEventListener('submit', (event) => {
        const items = collectItems();
        const summaryEntries = [];
        let firstErrorPath = '';

        const nextItems = items.map((item, index) => {
            const normalized = normalizeItem(item, index);
            const identity = safeObject(normalized.selected_catalog_identity);
            const productSnapshot = safeObject(normalized.product_snapshot);
            const priceSnapshot = safeObject(normalized.price_snapshot);
            const hasCatalogIdentity = Boolean(
                identity.tenant_catalog_product_id
                || identity.tenant_catalog_product_variant_id
                || normalized.tenant_catalog_product_id
                || normalized.tenant_catalog_product_variant_id
                || normalized.standard_product_id
            );

            let rowError = '';
            let rowErrorPath = '';

            if ((!normalized.product_name || !normalized.product_code) && hasCatalogIdentity && !productSnapshot.product_code) {
                rowError = 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.';
                rowErrorPath = `items.${index}.product_snapshot`;
            } else if (hasCatalogIdentity && !productSnapshot.product_name) {
                rowError = 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.';
                rowErrorPath = `items.${index}.product_snapshot`;
            } else if (hasCatalogIdentity && !priceSnapshot.list_price && !priceSnapshot.display_price) {
                rowError = 'Ürün fiyat özeti okunamadı. Satırı yeniden seçip tekrar deneyin.';
                rowErrorPath = `items.${index}.price_snapshot`;
            } else if (
                identity.tenant_catalog_product_variant_id
                && normalized.tenant_catalog_product_variant_id
                && String(identity.tenant_catalog_product_variant_id) !== String(normalized.tenant_catalog_product_variant_id)
            ) {
                rowError = 'Seçilen varyasyon ürün ile eşleşmiyor. Lütfen satırı yeniden seçin.';
                rowErrorPath = `items.${index}.tenant_catalog_product_variant_id`;
            } else if (identity.is_warning_sellable && hasCatalogIdentity && !productSnapshot.product_name) {
                rowError = 'Uyarılı ürün seçildi ancak teklif satırı eksik veri taşıyor. Lütfen satırı yeniden seçin veya manuel ürün olarak kaydedin.';
                rowErrorPath = `items.${index}.product_snapshot`;
            }

            if (rowErrorPath) {
                summaryEntries.push({
                    path: rowErrorPath,
                    label: buildValidationSummaryLabel('item', index),
                    message: rowError,
                });
                firstErrorPath = firstErrorPath || rowErrorPath;
            }

            const nextPrints = normalized.prints.map((printRow, printIndex) => {
                const nextPrintRow = normalizePrint(printRow, printIndex);
                let printError = '';
                let printErrorPath = '';

                if (quoteWorkspace.intermediateElementEnabled && nextPrintRow.requires_setup && !printSetupSelectionProvided(nextPrintRow)) {
                    printError = 'Bu baskı için ara eleman ayarı gereklidir.';
                    printErrorPath = `items.${index}.prints.${printIndex}.setup_requirement`;
                    summaryEntries.push({
                        path: printErrorPath,
                        label: buildValidationSummaryLabel('print', index, printIndex),
                        message: printError,
                    });
                    firstErrorPath = firstErrorPath || printErrorPath;
                }

                return {
                    ...nextPrintRow,
                    _row_error: printError,
                    _error_path: printErrorPath,
                };
            });

            return {
                ...normalized,
                prints: nextPrints,
                _row_error: rowError,
                _error_path: rowErrorPath,
            };
        });

        if (summaryEntries.length) {
            event.preventDefault();
            mountItems(nextItems);
            setClientFormError(`Teklif kaydedilemedi. ${summaryEntries.length} ürün/baskı satırında düzeltme gerekiyor.`, summaryEntries);
            if (firstErrorPath) {
                window.setTimeout(() => {
                    focusValidationTarget(firstErrorPath);
                }, 40);
            }
            return;
        }

        clearClientFormError();
    });
    window.addEventListener('resize', () => {
        document.querySelectorAll('.pd-quote-item.is-search-open').forEach((itemElement) => positionCatalogResults(itemElement));
    });

    window.addEventListener('scroll', () => {
        document.querySelectorAll('.pd-quote-item.is-search-open').forEach((itemElement) => positionCatalogResults(itemElement));
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        const visibleModal = document.querySelector('[data-setup-modal]:not(.hidden)');
        if (!visibleModal) {
            return;
        }

        const printOperation = resolvePrintOperationForModal(visibleModal);
        toggleSetupModal(printOperation, false, { revert: true });
    });

    if (quotePrintDebugEnabled) {
        window.__quotePrintDebug = {
            dump() {
                const snapshot = collectQuotePrintDebugSnapshot('dump');
                console.table(snapshot.map((item) => ({
                    itemIndex: item.itemIndex,
                    stableKey: item.stableKey,
                    hasPrint: item.hasPrint,
                    printsLength: item.printsLength,
                    domRowCount: item.domRowCount,
                    lastAddBefore: item.lastAddBefore,
                    lastAddAfter: item.lastAddAfter,
                    lastMountCount: item.lastMountCount,
                    lastCollectCount: item.lastCollectCount,
                    lastDomCount: item.lastDomCount,
                })));
                snapshot.forEach((item) => console.table(item.rows));
                return snapshot;
            },
            dumpItem(itemIndex) {
                const snapshot = collectQuotePrintDebugSnapshot('dump-item').find((item) => item.itemIndex === Number(itemIndex)) || null;
                if (snapshot) {
                    console.table([{
                        itemIndex: snapshot.itemIndex,
                        stableKey: snapshot.stableKey,
                        hasPrint: snapshot.hasPrint,
                        printsLength: snapshot.printsLength,
                        domRowCount: snapshot.domRowCount,
                    }]);
                    console.table(snapshot.rows);
                }
                return snapshot;
            },
            addPrint(itemIndex) {
                addPrintRow(Number(itemIndex));
                return this.dumpItem(Number(itemIndex));
            },
            forceAddPrints(itemIndex, count = 1) {
                const iterations = Math.max(0, Number(count) || 0);
                for (let index = 0; index < iterations; index += 1) {
                    addPrintRow(Number(itemIndex));
                }
                return this.dumpItem(Number(itemIndex));
            },
            countDomRows(itemIndex) {
                return countDomPrintRows(Number(itemIndex));
            },
            collectSnapshot() {
                const snapshot = collectItems();
                console.log('[quote-print-debug] collect-snapshot', snapshot);
                return snapshot;
            },
        };
    }

    if (quoteWorkspace.selectedCustomer?.id) {
        registerCustomerOption(quoteWorkspace.selectedCustomer);
    }
    refreshCustomerSummary();
    renderQuotePrintDebugPanel('ready');
});
</script>
