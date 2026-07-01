@php
    $formMethod = $formMethod ?? 'POST';
    $formAction = $formAction ?? '#';
    $submitLabel = $submitLabel ?? 'Kaydet';
    $cancelUrl = $cancelUrl ?? route('admin.promotion-quotes.index');
    $currency = old('currency', $quote->currency ?? 'TL');
    $quoteDateValue = old('quote_date', isset($quote) ? optional($quote->quote_date ?? $quote->created_at)->format('Y-m-d') : now()->format('Y-m-d'));
    $deliveryDateValue = old('valid_until', isset($quote) ? optional($quote->valid_until)->format('Y-m-d') : now()->addDays(7)->format('Y-m-d'));
    $invoiceStatusValue = old('invoice_status', $quote->invoice_status ?? 'fis');
    $initialItems = collect($initialItems ?? [[]])->values()->all();
    $quoteStatusLabel = old('quote_status_label', isset($quote) ? $quote->quoteDisplayStatusLabel() : 'Teklif');
    $showInitialVatSummary = $invoiceStatusValue === 'fatura';
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

<form method="POST" action="{{ $formAction }}" id="quote-form" class="space-y-5">
    @csrf
    @if ($formMethod !== 'POST')
        @method($formMethod)
    @endif

    @if ($errors->any())
        @php
            $generalError = $errors->first('error');
            $fieldErrors = collect($errors->all())->reject(fn ($message) => $message === $generalError)->values();
        @endphp
        <div class="pd-card border-red-200 bg-red-50">
            <div class="pd-card-body">
                @if ($generalError)
                    <div class="text-sm font-semibold text-red-800">{{ $generalError }}</div>
                @endif
                @if ($fieldErrors->isNotEmpty())
                    <div class="text-sm font-semibold text-red-800 {{ $generalError ? 'mt-3' : '' }}">Formda düzeltilmesi gereken alanlar var.</div>
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
                            <div class="pd-inline-actions">
                                <select name="customer_company_id" id="customer-select" required class="pd-select flex-1 min-w-0">
                                    <option value="">Müşteri seçin</option>
                                    @foreach ($customers as $customer)
                                        <option value="{{ $customer->id }}" @selected((string) old('customer_company_id', $quote->customer_company_id ?? '') === (string) $customer->id)>
                                            {{ $customer->legal_name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <p class="pd-section-subtitle mt-2">Yeni müşteri kaydı gerekiyorsa önce Cari Kart ekranından ekleyin.</p>
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
                            <input type="text" name="delivery_type" value="{{ old('delivery_type', $quote->delivery_type ?? '') }}" class="pd-compact-input" placeholder="Kargo, kurye, teslim...">
                        </div>
                        <div class="pd-quote-meta-row">
                            <label class="pd-label">Para birimi</label>
                            <select name="currency" class="pd-compact-select">
                                @foreach (['TL', 'USD', 'EUR'] as $option)
                                    <option value="{{ $option }}" @selected($currency === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
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
                        <span>Liste</span>
                        <span>İskonto %</span>
                        <span>Birim Fiyat</span>
                        <span>Toplam</span>
                        <span>Baskı</span>
                        <span>Sil</span>
                    </div>
                    <div id="product-items-container" class="space-y-3"></div>
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
                                    <strong id="summary-product-total">0,00 {{ $currency }}</strong>
                                </div>
                                <div class="pd-summary-stack-row pd-summary-stack-row-print">
                                    <span>Baskı Toplamı</span>
                                    <strong id="summary-print-total">0,00 {{ $currency }}</strong>
                                </div>
                                <div class="pd-summary-stack-row">
                                    <span>Ara Toplam</span>
                                    <strong id="summary-subtotal">0,00 {{ $currency }}</strong>
                                </div>
                            </div>
                            <div id="summary-vat-breakdown" class="space-y-2 {{ $showInitialVatSummary ? '' : 'hidden' }}"></div>
                            <div class="pd-summary-stack-row pd-summary-stack-row-vat {{ $showInitialVatSummary ? '' : 'hidden' }}" id="summary-vat-total-row">
                                <span id="summary-vat-label">KDV Toplamı</span>
                                <strong id="summary-vat">0,00 {{ $currency }}</strong>
                            </div>
                            <div class="pd-summary-total-box">
                                <div class="pd-summary-total-label">Genel toplam</div>
                                <strong id="summary-grand-total">0,00 {{ $currency }}</strong>
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

@php
    $workspacePayload = [
        'searchUrl' => $catalogSearchUrl,
        'currency' => $currency,
        'canViewFinancialData' => (bool) ($canViewFinancialData ?? false),
        'items' => $initialItems,
        'tenantPrintSettings' => $tenantPrintSettings ?? [],
        'legacyPrintTypeOptions' => $legacyPrintTypeOptions,
        'printOptionMap' => $printOptionMap,
        'clicheOptions' => $clicheOptions,
        'clicheRequiredTypes' => $clicheRequiredTypes,
        'invoiceStatus' => $invoiceStatusValue,
        'defaultPrintVatRate' => 20,
    ];
@endphp

<script>
const quoteWorkspace = @json($workspacePayload, JSON_UNESCAPED_UNICODE);
let productItemCount = 0;
let activeItemIndex = 0;
let expandAllItems = false;
const catalogSearchTimers = new Map();
const catalogEntryStore = new Map();
let catalogEntrySequence = 0;
const tenantPrintSettings = Array.isArray(quoteWorkspace.tenantPrintSettings) ? quoteWorkspace.tenantPrintSettings : [];
const tenantPrintSettingsById = new Map(tenantPrintSettings.map((setting) => [String(setting.id), setting]));
const legacyPrintTypeOptions = Array.isArray(quoteWorkspace.legacyPrintTypeOptions) ? quoteWorkspace.legacyPrintTypeOptions : [];
const printOptionMap = quoteWorkspace.printOptionMap || {};
const clicheRequiredTypes = quoteWorkspace.clicheRequiredTypes || ['Sıcak Baskı'];

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatMoney(value, currency = document.querySelector('select[name="currency"]')?.value || quoteWorkspace.currency || 'TL') {
    const number = Number(value ?? 0);
    return `${number.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
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

function setClientFormError(message = '') {
    const box = document.getElementById('quote-client-error');
    const label = box?.querySelector('[data-client-error-message]');

    if (!box || !label) {
        return;
    }

    if (!message) {
        label.textContent = '';
        box.classList.add('hidden');
        return;
    }

    label.textContent = message;
    box.classList.remove('hidden');
}

function clearClientFormError() {
    setClientFormError('');
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
            normalizedEntry.currency,
            entryPriceSnapshot.currency,
            currentPriceSnapshot.currency,
            quoteWorkspace.currency,
            'TL',
        ], 'TL'),
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

    return {
        tenant_print_setting_id: resolvedSettingId,
        standard_print_type_id: resolvedStandardId || setting?.standard_print_type_id || '',
        print_type: resolvedType,
        print_option: printRow.print_option || '',
        production_type: printRow.production_type || '',
        subcontractor_company_id: printRow.subcontractor_company_id || '',
        cliche_status: printRow.cliche_status || '',
        print_quantity: formatInputNumber(printRow.print_quantity ?? ''),
        print_unit_price: formatInputNumber(printRow.print_unit_price ?? ''),
        print_total: formatInputNumber(printRow.print_total ?? ''),
        note: printRow.note || '',
        print_vat_rate: printRow.print_vat_rate ?? quoteWorkspace.defaultPrintVatRate ?? 20,
        requires_setup: printRow.requires_setup === true || printRow.requires_setup === 1 || printRow.requires_setup === '1' || !!setting?.requires_setup,
        setup_types: Array.isArray(printRow.setup_types) ? printRow.setup_types : (setting?.setup_types || []),
        _manual_quantity: !!printRow._manual_quantity,
        _price_suggested: !!printRow._price_suggested,
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
        prints: [normalizePrint()],
        manual_unit_price: false,
        calculated_unit_price: '',
        tenant_catalog_product_id: '',
        tenant_catalog_product_variant_id: '',
        standard_product_id: '',
        standard_product_variant_id: '',
        supplier_id: '',
        supplier_source_id: '',
        catalog_source: 'tenant_catalog',
        product_snapshot: null,
        price_snapshot: null,
        stock_snapshot: null,
        selected_catalog_identity: null,
        print_vat_rate: quoteWorkspace.defaultPrintVatRate || 20,
        _row_error: '',
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
        : [normalizePrint()];

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
    const badges = [];
    const rawBadges = item.warning_badges || [];
    const hasSupplierWarning = rawBadges.includes('Kırmızı Ürün') || rawBadges.includes('Turuncu Ürün');
    const hasNetWarning = rawBadges.includes('Net fiyat uyarısı');
    const hasMissingPrice = rawBadges.includes('Fiyat eksik');
    const hasMissingImage = rawBadges.includes('Görsel eksik');
    const hiddenInCatalog = item.product_snapshot?.visible_in_catalog === false;
    const closedForQuote = item.product_snapshot?.visible_in_quote === false;

    if (hasSupplierWarning) {
        badges.push({ text: 'Uyarılı', tone: 'red' });
    }

    if (hasNetWarning) {
        badges.push({ text: 'Net Fiyat', tone: 'amber' });
    }

    if (hasMissingPrice) {
        badges.push({ text: 'Fiyat Eksik', tone: 'red' });
    }

    if (hasMissingImage) {
        badges.push({ text: 'Görsel Yok', tone: 'slate' });
    }

    if (hiddenInCatalog) {
        badges.push({ text: 'Katalogda Gizli', tone: 'slate' });
    }

    if (closedForQuote) {
        badges.push({ text: 'Teklife Kapalı', tone: 'slate' });
    }

    return badges;
}

function quoteWarningMeta(item) {
    const rawBadges = item.warning_badges || [];
    return {
        hasSupplierWarning: rawBadges.includes('Kırmızı Ürün') || rawBadges.includes('Turuncu Ürün'),
        hasNetWarning: rawBadges.includes('Net fiyat uyarısı'),
        hasStockWarning: rawBadges.includes('Stok yok'),
    };
}

function printRowCode(itemIndex, printIndex) {
    return `${itemIndex + 1}${String.fromCharCode(97 + printIndex)}`;
}

function printRequiresCliche(printType, requiresSetup = false, setupTypes = []) {
    if (requiresSetup && Array.isArray(setupTypes) && setupTypes.includes('cliche')) {
        return true;
    }

    return clicheRequiredTypes.includes(printType || '');
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
    return document.querySelector('select[name="currency"]')?.value || quoteWorkspace.currency || 'TL';
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

    unitPriceInput.value = Number(setting.default_unit_price).toFixed(2);
    unitPriceInput.dataset.priceSuggested = '1';
}

function renderPrintRows(item) {
    return item.prints.map((printRow, printIndex) => {
        const printSetting = resolveTenantPrintSetting(printRow);
        const printType = currentPrintSettingOrLegacyName(printRow);
        const printSelectorOptions = buildPrintTypeOptions(printRow);
        const printOptionOptions = printOptionsForType(printSetting?.standard_name || printType);
        const showCliche = printRequiresCliche(printType, printRow.requires_setup, printRow.setup_types);
        const selectorValue = printSelectorValue(printRow);
        const showSetupBadge = !!(printSetting?.requires_setup || printRow.requires_setup);
        const currencyWarning = printCurrencyWarningMessage(printSetting);
        return `
            <div class="pd-print-operation" data-print-index="${printIndex}">
                <div class="pd-print-operation-grid pd-print-operation-grid-flat">
                    <div class="pd-print-operation-index">${escapeHtml(printRowCode(item._index, printIndex))}</div>
                    <div>
                        <select class="pd-compact-select print-type-select" data-print-setting-select>
                            ${optionsHtml(printSelectorOptions, selectorValue, tenantPrintSettings.length ? 'Baskı tipi seç' : 'Baskı ayarı bulunamadı')}
                        </select>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][tenant_print_setting_id]" value="${escapeHtml(printRow.tenant_print_setting_id || '')}" data-print-setting-id>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][standard_print_type_id]" value="${escapeHtml(printRow.standard_print_type_id || '')}" data-standard-print-type-id>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][print_type]" value="${escapeHtml(printType)}" data-print-type-input>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][production_type]" value="${escapeHtml(printRow.production_type || '')}" data-production-type-input>
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][subcontractor_company_id]" value="${escapeHtml(printRow.subcontractor_company_id || '')}" data-subcontractor-company-id>
                        ${showSetupBadge ? `<div class="pd-print-inline-meta mt-1"><span class="pd-badge pd-badge-amber">Ara eleman gerekir</span></div>` : ''}
                    </div>
                    <div>
                        <select name="items[${item._index}][prints][${printIndex}][print_option]" class="pd-compact-select print-option-select">
                            ${optionsHtml(printOptionOptions, printRow.print_option, 'Baskı seçeneği seç')}
                        </select>
                        ${showCliche ? `<div class="pd-print-inline-meta mt-1"><span>Klişe / kalıp:</span> ${escapeHtml(printRow.cliche_status || 'Seçilmedi')}</div>` : ''}
                    </div>
                    <div>
                        <input type="number" name="items[${item._index}][prints][${printIndex}][print_quantity]" value="${escapeHtml(printRow.print_quantity)}" step="0.01" min="0" class="pd-compact-input print-quantity-input" data-manual-quantity="${printRow._manual_quantity ? '1' : '0'}" placeholder="0">
                    </div>
                    <div>
                        <input type="number" name="items[${item._index}][prints][${printIndex}][print_unit_price]" value="${escapeHtml(printRow.print_unit_price)}" step="0.01" min="0" class="pd-compact-input" placeholder="${quoteWorkspace.canViewFinancialData ? '0.00' : 'Gizli'}" data-price-suggested="${printRow._price_suggested ? '1' : '0'}" ${quoteWorkspace.canViewFinancialData ? '' : 'readonly'}>
                        <div class="pd-print-inline-meta mt-1 ${currencyWarning ? '' : 'hidden'}" data-print-price-currency-warning>${escapeHtml(currencyWarning)}</div>
                    </div>
                    <div>
                        <input type="number" name="items[${item._index}][prints][${printIndex}][print_total]" value="${escapeHtml(printRow.print_total)}" step="0.01" min="0" class="pd-compact-input" placeholder="${quoteWorkspace.canViewFinancialData ? '0.00' : 'Gizli'}" readonly>
                    </div>
                    <div>
                        <input type="text" name="items[${item._index}][prints][${printIndex}][note]" value="${escapeHtml(printRow.note || '')}" class="pd-compact-input" placeholder="Baskı adı">
                        <div class="${showCliche ? '' : 'hidden'}" data-cliche-wrap>
                            <select name="items[${item._index}][prints][${printIndex}][cliche_status]" class="pd-compact-select mt-1">
                                ${optionsHtml(quoteWorkspace.clicheOptions, printRow.cliche_status, 'Klişe / kalıp seç')}
                            </select>
                        </div>
                    </div>
                    <div class="pd-print-row-actions">
                        <input type="hidden" name="items[${item._index}][prints][${printIndex}][print_vat_rate]" value="${escapeHtml(printRow.print_vat_rate ?? quoteWorkspace.defaultPrintVatRate ?? 20)}">
                        <button type="button" class="pd-btn pd-btn-danger-soft pd-btn-xs" data-action="remove-print">Sil</button>
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
    const compactInfoBits = [
        item.product_code || 'Ürün kodu ürün seçilince otomatik gelir',
        `Stok: ${formatStock(supplierStock)}`,
    ];
    const compactWarningBadges = displayWarningBadges
        .filter((badge) => ['Uyarılı', 'Net Fiyat', 'Fiyat Eksik'].includes(badge.text))
        .map((badge) => badgeHtml(badge.text, badge.tone || 'amber'));

    return `
        <div class="pd-quote-item pd-quote-item-group ${warningMeta.hasSupplierWarning || warningMeta.hasStockWarning ? 'is-warning' : ''}" data-item-index="${item._index}" data-stable-key="${escapeHtml(item._stable_key || '')}" data-row-error-message="${escapeHtml(item._row_error || '')}">
            <div class="pd-quote-line-row">
                <div class="pd-quote-item-number">${item._index + 1}</div>
                <div class="pd-quote-line-product">
                    <div class="pd-catalog-search">
                        <input type="text" name="items[${item._index}][product_name]" class="pd-compact-input catalog-search-input" value="${escapeHtml(item.product_name)}" placeholder="Ürün adı, ürün kodu, SKU, renk...">
                        <div class="pd-catalog-results hidden"></div>
                    </div>
                    <div class="pd-quote-line-product-meta">
                        ${thumbnail ? `<img src="${escapeHtml(thumbnail)}" alt="Ürün görseli" class="pd-quote-item-thumb">` : ''}
                        <div class="min-w-0">
                            <div class="pd-quote-line-title">${escapeHtml(item.product_name || `Ürün ${item._index + 1}`)}</div>
                            <div class="pd-quote-line-subtitle pd-quote-line-subtitle-rich">
                                ${compactInfoBits.map((bit) => `<span class="pd-quote-subtle-bit">${escapeHtml(bit)}</span>`).join('')}
                                ${manualUnitPrice ? badgeHtml('Manuel', 'purple') : ''}
                                ${showVatDetails ? `
                                    <label class="pd-quote-inline-pill pd-quote-inline-pill-input">
                                        <strong>KDV</strong>
                                        <input type="number" name="items[${item._index}][vat_rate]" value="${escapeHtml(vatRate)}" step="0.01" min="0" max="100" class="pd-inline-number-input" placeholder="20">
                                    </label>
                                ` : ''}
                                ${warningMeta.hasStockWarning ? badgeHtml('Stok Yok', 'red') : ''}
                                ${compactWarningBadges.join('')}
                            </div>
                        </div>
                    </div>
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
                        <span>Var</span>
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

function mountItems(items) {
    const container = document.getElementById('product-items-container');
    container.innerHTML = '';

    items.forEach((item, index) => {
        try {
            const normalized = normalizeItem(item, index);
            container.insertAdjacentHTML('beforeend', renderItem(normalized));
        } catch (error) {
            const fallbackItem = normalizeItem({
                ...defaultItem(),
                ...safeObject(item),
                _row_error: 'Bu satir tam yuklenemedi. Lütfen ürünü katalogdan yeniden seçin.',
            }, index);
            container.insertAdjacentHTML('beforeend', renderItem(fallbackItem));
            setClientFormError('Teklif satırlarından biri güvenli şekilde yeniden yüklendi. Lütfen hatalı satırı kontrol edin.');
        }
    });

    productItemCount = items.length;
    if (activeItemIndex >= productItemCount) {
        activeItemIndex = Math.max(0, productItemCount - 1);
    }
    recalculateTotals();
    refreshAllPrintCurrencyWarnings();
    refreshCustomerSummary();
}

function collectItems() {
    return Array.from(document.querySelectorAll('.pd-quote-item')).map((element, index) => {
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
            tenant_catalog_product_id: element.querySelector('input[name$="[tenant_catalog_product_id]"]')?.value || '',
            tenant_catalog_product_variant_id: element.querySelector('input[name$="[tenant_catalog_product_variant_id]"]')?.value || '',
            standard_product_id: element.querySelector('input[name$="[standard_product_id]"]')?.value || '',
            standard_product_variant_id: element.querySelector('input[name$="[standard_product_variant_id]"]')?.value || '',
            supplier_id: element.querySelector('input[name$="[supplier_id]"]')?.value || '',
            supplier_source_id: element.querySelector('input[name$="[supplier_source_id]"]')?.value || '',
            catalog_source: element.querySelector('input[name$="[catalog_source]"]')?.value || 'tenant_catalog',
            product_snapshot: productSnapshot,
            price_snapshot: priceSnapshot,
            stock_snapshot: stockSnapshot,
            selected_catalog_identity: selectedCatalogIdentity,
            _row_error: element.dataset.rowErrorMessage || '',
            print_vat_rate: priceSnapshot?.print_vat_rate || quoteWorkspace.defaultPrintVatRate || 20,
            prints: Array.from(element.querySelectorAll('[data-print-list] .pd-print-operation')).map((row, printIndex) => normalizePrint({
                tenant_print_setting_id: row.querySelector('[data-print-setting-id]')?.value || '',
                standard_print_type_id: row.querySelector('[data-standard-print-type-id]')?.value || '',
                print_type: row.querySelector('[data-print-type-input]')?.value || '',
                print_option: row.querySelector('select[name*="[print_option]"]')?.value || '',
                production_type: row.querySelector('[data-production-type-input]')?.value || '',
                subcontractor_company_id: row.querySelector('[data-subcontractor-company-id]')?.value || '',
                cliche_status: row.querySelector('select[name*="[cliche_status]"]')?.value || '',
                print_quantity: row.querySelector('input[name*="[print_quantity]"]')?.value || '',
                print_unit_price: row.querySelector('input[name*="[print_unit_price]"]')?.value || '',
                print_total: row.querySelector('input[name*="[print_total]"]')?.value || '',
                note: row.querySelector('input[name*="[note]"]')?.value || '',
                print_vat_rate: row.querySelector('input[name*="[print_vat_rate]"]')?.value || quoteWorkspace.defaultPrintVatRate || 20,
                _manual_quantity: row.querySelector('input[name*="[print_quantity]"]')?.dataset.manualQuantity === '1',
                _price_suggested: row.querySelector('input[name*="[print_unit_price]"]')?.dataset.priceSuggested === '1',
            }, printIndex)),
        }, index);
    });
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
    target.has_print = true;
    target.prints.push(normalizePrint({
        print_quantity: target.quantity || '',
        ...printRow,
    }, target.prints.length));
    activeItemIndex = itemIndex;
    mountItems(items);
}

function removePrintRow(itemIndex, printIndex) {
    const items = collectItems();
    const target = items[itemIndex];
    if (!target) {
        return;
    }
    target.prints = target.prints.filter((_, index) => index !== printIndex);
    if (!target.prints.length) {
        target.prints = [normalizePrint()];
        target.has_print = false;
    }
    activeItemIndex = itemIndex;
    mountItems(items);
}

function resolveWarningBadges(entry) {
    return entry?.warning_badges || [];
}

function resolveWarningMessages(entry) {
    return entry?.warning_messages || [];
}

function buildCatalogResult(entry) {
    const price = entry.list_price ?? 0;
    const code = entry.product_code || '-';
    const label = entry.product_name || '-';
    const thumbnail = entry.image_url || '';
    const badges = resolveWarningBadges(entry);
    const localPriority = entry.local_stock_priority ?? false;
    const metaBits = [
        entry.supplier_name || '',
        `SKU: ${code}`,
        `Stok: ${formatStock(entry.visible_stock_quantity ?? 0)}`,
        `${formatMoney(price, entry.currency || 'TL')}`,
    ].filter(Boolean).join(' • ');
    const entryKey = rememberCatalogEntry(entry);

    return `
        <button type="button" class="pd-catalog-result" data-entry-key="${escapeHtml(entryKey)}">
            <div class="flex items-start gap-3">
                ${thumbnail ? `<img src="${escapeHtml(thumbnail)}" alt="Ürün" class="pd-catalog-result-thumb">` : ''}
                <div class="min-w-0">
                    <div class="font-medium text-slate-800">${escapeHtml(label)}</div>
                    <div class="text-xs text-slate-500">${escapeHtml(metaBits)}</div>
                    <div class="pd-chip-row mt-1">
                        ${badgeHtml(entry.catalog_source === 'local_product' ? 'Local Ürün' : 'Tedarikçi Ürünü', entry.catalog_source === 'local_product' ? 'green' : 'blue')}
                        ${localPriority ? badgeHtml('Local Stok', 'green') : badgeHtml('Tedarikçi Stok', 'slate')}
                        ${((entry.visible_stock_quantity ?? 0) <= 0) ? badgeHtml('Stok Yok', 'red') : ''}
                        ${badges.map((badge) => badgeHtml(badge, 'amber')).join('')}
                    </div>
                </div>
            </div>
        </button>
    `;
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
    target.selected_catalog_identity = normalizedEntry.selected_catalog_identity;
    target._row_error = '';
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
        list_price: selectedPrice,
        display_price: selectedPrice,
        currency: normalizedEntry.currency,
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

    if (term.length < 2) {
        resultsBox.innerHTML = '';
        hideCatalogResults(itemElement);
        return;
    }

    resultsBox.innerHTML = '<div class="pd-catalog-result text-sm text-slate-500">Aranıyor...</div>';
    showCatalogResults(itemElement);

    try {
        const response = await fetch(`${quoteWorkspace.searchUrl}?q=${encodeURIComponent(term)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('Catalog search failed');
        }

        renderCatalogResults(itemElement, await response.json());
    } catch (error) {
        resultsBox.innerHTML = '<div class="pd-catalog-result text-sm text-red-600">Katalog arama sırasında hata oluştu.</div>';
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
    const currency = document.querySelector('select[name="currency"]')?.value || quoteWorkspace.currency || 'TL';

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
    return;
}

document.addEventListener('DOMContentLoaded', () => {
    const initialItems = quoteWorkspace.items?.length ? quoteWorkspace.items : [defaultItem()];
    mountItems(initialItems);

    document.getElementById('add-product-item')?.addEventListener('click', addProductItem);
    document.getElementById('customer-select')?.addEventListener('change', refreshCustomerSummary);
    document.getElementById('invoice-status-select')?.addEventListener('change', () => {
        mountItems(collectItems());
    });
    document.getElementById('quote-date-input')?.addEventListener('change', (event) => {
        const deliveryInput = document.getElementById('delivery-date-input');
        if (!deliveryInput || deliveryInput.dataset.manualOverride === '1' || !event.target.value) {
            return;
        }

        const baseDate = new Date(`${event.target.value}T00:00:00`);
        if (Number.isNaN(baseDate.getTime())) {
            return;
        }

        baseDate.setDate(baseDate.getDate() + 7);
        deliveryInput.value = baseDate.toISOString().slice(0, 10);
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
            event.target.name?.includes('[print_unit_price]') ||
            event.target.name?.includes('[quantity]') ||
            event.target.name?.includes('[list_price]') ||
            event.target.name?.includes('[discount_rate]') ||
            event.target.name?.includes('[vat_rate]')
        ) {
            recalculateTotals();
        }

        if (event.target.name?.includes('[print_unit_price]')) {
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

    document.addEventListener('change', (event) => {
        if (event.target.classList.contains('quote-has-print')) {
            const itemElement = event.target.closest('.pd-quote-item');
            const wrapper = itemElement?.querySelector('[data-print-wrapper]');
            wrapper?.classList.toggle('hidden', !event.target.checked);
            recalculateTotals();
            return;
        }

        if (event.target.classList.contains('print-type-select')) {
            const printOperation = event.target.closest('.pd-print-operation');
            const clicheWrap = printOperation?.querySelector('[data-cliche-wrap]');
            const optionSelect = printOperation?.querySelector('.print-option-select');
            const settingIdInput = printOperation?.querySelector('[data-print-setting-id]');
            const standardTypeInput = printOperation?.querySelector('[data-standard-print-type-id]');
            const printTypeInput = printOperation?.querySelector('[data-print-type-input]');
            const subcontractorCompanyInput = printOperation?.querySelector('[data-subcontractor-company-id]');
            const selectedValue = event.target.value || '';
            let effectivePrintType = '';
            let effectiveStandardName = '';
            let requiresSetup = false;
            let setupTypes = [];

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
                    effectiveStandardName = setting.standard_name || effectivePrintType;
                    requiresSetup = !!setting.requires_setup;
                    setupTypes = Array.isArray(setting.setup_types) ? setting.setup_types : [];
                }
            } else {
                const legacyType = selectedValue.replace('legacy:', '');
                if (settingIdInput) settingIdInput.value = '';
                if (standardTypeInput) standardTypeInput.value = '';
                if (printTypeInput) printTypeInput.value = legacyType;
                effectivePrintType = legacyType;
                effectiveStandardName = legacyType;
                refreshPrintCurrencyWarning(printOperation, null);
            }

            const nextOptions = printOptionsForType(effectiveStandardName || effectivePrintType);
            if (optionSelect) {
                const currentValue = optionSelect.value;
                optionSelect.innerHTML = optionsHtml(nextOptions, nextOptions.includes(currentValue) ? currentValue : '', 'Baskı seçeneği seç');
            }
            clicheWrap?.classList.toggle('hidden', !printRequiresCliche(effectivePrintType, requiresSetup, setupTypes));
            recalculateTotals();
        }

        if (event.target.name === 'currency' || event.target.name === 'invoice_status') {
            recalculateTotals();
            refreshAllPrintCurrencyWarnings();
        }
    });

    document.addEventListener('click', (event) => {
        const resultButton = event.target.closest('.pd-catalog-result[data-entry-key]');
        if (resultButton) {
            const itemElement = resultButton.closest('.pd-quote-item');
            const entry = getCatalogEntry(resultButton.dataset.entryKey);
            if (!entry) {
                const items = collectItems();
                const itemIndex = Number(itemElement?.dataset.itemIndex ?? -1);
                if (items[itemIndex]) {
                    items[itemIndex]._row_error = 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.';
                    mountItems(items);
                    setClientFormError('Teklif kaydedilemedi. Hatalı satırları kontrol edip tekrar deneyin.');
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
            }
        }

        if (!event.target.closest('.pd-catalog-search')) {
            document.querySelectorAll('.pd-quote-item').forEach((itemElement) => hideCatalogResults(itemElement));
        }
    });

    document.getElementById('quote-form')?.addEventListener('submit', (event) => {
        const items = collectItems();
        let firstErrorIndex = -1;
        let hasErrors = false;

        const nextItems = items.map((item, index) => {
            const normalized = normalizeItem(item, index);
            const identity = safeObject(normalized.selected_catalog_identity);
            const hasCatalogIdentity = Boolean(
                identity.tenant_catalog_product_id
                || identity.tenant_catalog_product_variant_id
                || normalized.tenant_catalog_product_id
                || normalized.tenant_catalog_product_variant_id
                || normalized.standard_product_id
            );
            let rowError = '';

            if (!normalized.product_name || !normalized.product_code && hasCatalogIdentity && !safeObject(normalized.product_snapshot).product_code) {
                rowError = 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.';
            } else if (hasCatalogIdentity && !safeObject(normalized.product_snapshot).product_name) {
                rowError = 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.';
            } else if (hasCatalogIdentity && !safeObject(normalized.price_snapshot).list_price && !safeObject(normalized.price_snapshot).display_price) {
                rowError = 'Ürün fiyat özeti okunamadı. Satırı yeniden seçip tekrar deneyin.';
            } else if (
                identity.tenant_catalog_product_variant_id
                && normalized.tenant_catalog_product_variant_id
                && String(identity.tenant_catalog_product_variant_id) !== String(normalized.tenant_catalog_product_variant_id)
            ) {
                rowError = 'Seçilen varyasyon ürün ile eşleşmiyor. Lütfen satırı yeniden seçin.';
            } else if (identity.is_warning_sellable && hasCatalogIdentity && !safeObject(normalized.product_snapshot).product_name) {
                rowError = 'Uyarılı ürün seçildi ancak teklif satırı eksik veri taşıyor. Lütfen satırı yeniden seçin veya manuel ürün olarak kaydedin.';
            }

            if (rowError) {
                hasErrors = true;
                if (firstErrorIndex === -1) {
                    firstErrorIndex = index;
                }
            }

            return {
                ...normalized,
                _row_error: rowError,
            };
        });

        if (hasErrors) {
            event.preventDefault();
            mountItems(nextItems);
            setClientFormError('Teklif kaydedilemedi. Hatalı satırları kontrol edip tekrar deneyin.');
            const firstErrorRow = document.querySelector(`.pd-quote-item[data-item-index="${firstErrorIndex}"]`);
            firstErrorRow?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            clearClientFormError();
        }
    });

    window.addEventListener('resize', () => {
        document.querySelectorAll('.pd-quote-item.is-search-open').forEach((itemElement) => positionCatalogResults(itemElement));
    });

    window.addEventListener('scroll', () => {
        document.querySelectorAll('.pd-quote-item.is-search-open').forEach((itemElement) => positionCatalogResults(itemElement));
    }, true);
});
</script>
