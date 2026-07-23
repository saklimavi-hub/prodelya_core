@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Talebi')
@section('page_title', 'Tedarikçi Talebi')
@section('page_subtitle', 'Talep kalemlerini, gelen ürün kaydını ve fiyatsız dış formu aynı referans ailesi içinde yönetin.')
@section('hide_side_summary', '1')

@section('content')
@php
    $items = $editData['items'];
    $canManage = $canManageProcurementRequests ?? false;
    $showPartialReceiveInputs = $canManage && ($requestRecord->isSupplierOrdered() || $requestRecord->isPartiallyReceived());
    $canSaveRequest = $canManage && !$requestRecord->isCompleted() && !$requestRecord->isCancelled();
    $canSaveCompletedPrices = $canSaveCompletedPurchasePrices ?? false;
    $canRefreshPurchaseTruth = $canManage && ($canViewPurchasePrices ?? false) && $requestRecord->isDraft() && (($editData['legacy_purchase_truth_item_count'] ?? 0) > 0);
    $isCompletedRequest = $requestRecord->isCompleted();
    $resolvedDepth = $requestRecord->tenant ? app(\App\Services\ProcessDepth\TenantProcessDepthResolver::class)->resolve($requestRecord->tenant) : ['key' => 'standard', 'label' => 'Standart Akış'];
    $depthKey = (string) data_get($resolvedDepth, 'key', 'standard');
    $depthLabel = (string) data_get($resolvedDepth, 'label', 'Standart Akış');
    $depthPolicy = app(\App\Services\ProcessDepth\TenantProcessDepthPolicy::class)->forDepth($depthKey);
    $isFastDepth = $depthKey === 'fast';
    $isControlledDepth = $depthKey === 'controlled';
    $showAdvancedTimeline = (bool) data_get($depthPolicy, 'show_advanced_activity_timeline', false);
@endphp
<style>
    .pd-page-stack,
    .pd-section-stack { display:grid; gap:14px; }
    .pd-card-stack { display:grid; gap:12px; }
    .pd-inline-stack { display:flex; gap:10px; flex-wrap:wrap; }
    .pd-tight-stack { display:grid; gap:8px; }
    .pd-two-column-layout { display:grid; grid-template-columns:minmax(0, 1fr) 330px; gap:14px; align-items:start; }
    .prw-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 12px 28px rgba(15,23,42,.05); }
    .prw-body { padding:14px; }
    .prw-title { margin:0; font-size:15px; font-weight:700; color:#111827; }
    .prw-note { color:#6b7280; font-size:12px; line-height:1.5; }
    .prw-meta { margin-top:6px; color:#64748b; font-size:12px; line-height:1.5; }
    .prw-warning { margin-top:6px; color:#b45309; font-size:12px; font-weight:600; }
    .prw-helper-link { padding:0; border:0; background:none; color:#2563eb; font-size:12px; font-weight:600; cursor:pointer; }
    .prw-helper-link:disabled { color:#94a3b8; cursor:not-allowed; }
    .prw-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; }
    .prw-box { border:1px solid #e5e7eb; border-radius:8px; background:#fbfdff; padding:12px; }
    .prw-label { color:#6b7280; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .prw-value { margin-top:5px; color:#111827; font-size:14px; font-weight:700; }
    .prw-band { border:1px solid #dbe5f0; border-radius:8px; background:#f8fbff; padding:12px; color:#475569; font-size:12px; line-height:1.55; }
    .prw-steps { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; }
    .prw-step { border:1px solid #e5e7eb; border-radius:8px; background:#fbfdff; padding:12px; }
    .prw-step.is-active { border-color:#93c5fd; background:#eff6ff; }
    .prw-table-wrap { overflow:auto; }
    .prw-table { width:100%; border-collapse:collapse; min-width:1180px; }
    .prw-table th, .prw-table td { padding:10px 8px; border-bottom:1px solid #e5e7eb; text-align:left; vertical-align:top; }
    .prw-table th { background:#f8fafc; color:#6b7280; font-size:11px; font-weight:700; text-transform:uppercase; }
    .prw-table input[type="text"], .prw-table input[type="number"] { width:100%; }
    .prw-sticky { position:sticky; top:16px; }
    .prw-list { display:grid; gap:8px; }
    .prw-row { display:flex; justify-content:space-between; gap:12px; font-size:12px; color:#475569; }
    .prw-row strong { color:#111827; }
    .prw-process-window { border:1px solid #dbe5f0; border-radius:10px; background:#f8fbff; padding:14px; }
    @media (max-width:1100px) {
        .pd-two-column-layout,
        .prw-grid,
        .prw-steps { grid-template-columns:1fr; }
        .prw-sticky { position:static; }
    }
</style>

@if(session('success'))
    <div class="pd-alert">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="pd-alert pd-alert-danger">{{ $errors->first() }}</div>
@endif

<div class="pd-page-stack" data-procurement-reference-family="receipt-window" data-procurement-depth="{{ $depthKey }}">
    <section class="prw-card">
        <div class="prw-body pd-card-stack">
            <div class="pd-inline-stack" style="justify-content:space-between; align-items:flex-start;">
                <div>
                    <h3 class="prw-title">Tedarikçi Talebi ve Gelen Ürün Kaydı</h3>
                    <div class="prw-note">Çalışma şekli: {{ $depthLabel }}. Gerçek partial receipt servisi korunur; sahte rezervasyon veya fiyat sızıntısı oluşturulmaz.</div>
                </div>
                <div class="pd-inline-stack">
                    @if($canSaveRequest)
                        @if($requestRecord->isDraft())
                            <button type="submit" name="submit_action" value="draft" form="supplier-request-update-form" class="pd-btn pd-btn-light">Taslak Kaydet</button>
                        @endif
                        <button type="submit" name="submit_action" value="request" form="supplier-request-update-form" class="pd-btn pd-btn-primary">Talebi Kaydet</button>
                    @endif
                    @if($canRefreshPurchaseTruth)
                        <form method="POST" action="{{ route('admin.procurements.supplier-requests.refresh-prices', $requestRecord) }}" onsubmit="return confirm('Legacy draft kalemleri exact tedarikçi kaynağından yenilensin mi?');">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-light">Tedarikçi Fiyatını Yenile</button>
                        </form>
                    @endif
                    <a href="{{ route('admin.procurements.supplier-requests.print', $requestRecord) }}" class="pd-btn pd-btn-light" target="_blank" rel="noopener">Fiyatsız Talep Formu</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-two-column-layout">
        <div class="pd-page-stack">
            <section class="prw-card">
                <div class="prw-body pd-card-stack">
                    <div class="prw-grid">
                        <div class="prw-box"><div class="prw-label">Talep No</div><div class="prw-value">{{ $requestRecord->request_number }}</div></div>
                        <div class="prw-box"><div class="prw-label">Tedarikçi</div><div class="prw-value">{{ $requestRecord->supplier?->name ?: '-' }}</div></div>
                        <div class="prw-box"><div class="prw-label">Durum</div><div class="prw-value">{{ $requestRecord->safeStatusLabel() }}</div></div>
                        <div class="prw-box"><div class="prw-label">Kalem / Toplam</div><div class="prw-value">{{ $editData['included_item_count'] }} / {{ number_format($editData['total_quantity'], 2, ',', '.') }}</div></div>
                    </div>
                    @if($isCompletedRequest)
                        <div class="prw-band">Tüm ürünler teslim alındı. Teslim miktarları kilitlidir; alış fiyatları ve iç notlar güncellenebilir.</div>
                    @endif
                    <div class="prw-band">Dış tedarikçi belgesinde fiyat görünmez. Alış fiyatı, iskonto ve iç uyarılar yalnız yetkili iç kullanıcı için bu ekranda kalır.</div>
                </div>
            </section>

            <section class="prw-card" id="receipt-panel">
                <div class="prw-body pd-card-stack">
                    <div>
                        <h3 class="prw-title">Süreç Penceresi</h3>
                        <div class="prw-note">Talep, sipariş ve gelen ürün kaydı aynı pencere sözleşmesinde gösterilir.</div>
                    </div>
                    <div class="prw-steps" data-testid="procurement-receipt-steps">
                        <article class="prw-step {{ $requestRecord->isDraft() || $requestRecord->isRequested() ? 'is-active' : '' }}"><div class="prw-label">1. Talep</div><div class="prw-value">{{ $requestRecord->isDraft() ? 'Taslak hazır' : 'Tedarikçiye iletildi' }}</div></article>
                        <article class="prw-step {{ $requestRecord->isSupplierOrdered() || $requestRecord->isPartiallyReceived() || $requestRecord->isCompleted() ? 'is-active' : '' }}"><div class="prw-label">2. Sipariş</div><div class="prw-value">{{ $requestRecord->isSupplierOrdered() || $requestRecord->isPartiallyReceived() || $requestRecord->isCompleted() ? 'Sipariş verildi' : 'Sipariş bekleniyor' }}</div></article>
                        <article class="prw-step {{ $requestRecord->isPartiallyReceived() || $requestRecord->isCompleted() ? 'is-active' : '' }}"><div class="prw-label">3. Gelen Ürün</div><div class="prw-value">{{ $requestRecord->isCompleted() ? 'Tamamlandı' : ($requestRecord->isPartiallyReceived() ? 'Kısmi geldi' : 'Kayıt bekliyor') }}</div></article>
                    </div>

                    <div class="prw-process-window" data-testid="procurement-receipt-window">
                        <div class="pd-inline-stack" style="justify-content:space-between; align-items:flex-start;">
                            <div>
                                <div class="prw-title">Gelen Ürün Kaydı</div>
                                <div class="prw-note">{{ $isCompletedRequest ? 'Tedarik tamamlandı. Bu ekranda miktarlar kilitli kalır; yalnız alış fiyatları ve iç notlar güncellenir.' : 'Yalnız açık satırlar güncellenir. Negatif veya kalan miktarı aşan girişler backend validation ile engellenir.' }}</div>
                            </div>
                            @if($showPartialReceiveInputs)
                                <span class="pd-badge pd-badge-blue">Gerçek receipt akışı açık</span>
                            @elseif($isCompletedRequest)
                                <span class="pd-badge pd-badge-green">Tamamlandı - miktarlar kilitli</span>
                            @endif
                        </div>
                    </div>

                    <form id="supplier-request-update-form" method="POST" action="{{ route('admin.procurements.supplier-requests.update', $requestRecord) }}">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="request-note" class="prw-label" style="display:block; margin-bottom:6px;">İç Not</label>
                            <input id="request-note" type="text" name="note" value="{{ old('note', $requestRecord->note) }}" placeholder="Opsiyonel iç not" @if($isCompletedRequest && !$canSaveCompletedPrices) readonly @endif>
                        </div>
                        <div class="prw-table-wrap">
                            <table class="prw-table">
                                <thead>
                                    <tr>
                                        <th>Dahil</th>
                                        <th>Sipariş No</th>
                                        <th>İş Formu No</th>
                                        <th>Ürün Kodu</th>
                                        <th>Ürün Adı</th>
                                        <th>İstenen</th>
                                        <th>Alınan</th>
                                        <th>Kalan</th>
                                        @if($showPartialReceiveInputs)
                                            <th>Bu Teslimatta Gelen</th>
                                        @endif
                                        @if($canViewPurchasePrices)
                                            <th>Tedarikçi Liste</th>
                                            <th>İskonto %</th>
                                            <th>Alış Birim Fiyatı</th>
                                            <th>Alış Toplamı</th>
                                        @endif
                                        <th>Not</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($items as $index => $item)
                                        @php
                                            $purchaseUi = $item->purchase_ui ?? [];
                                            $listPriceValue = old("items.$index.purchase_list_price", $item->purchase_list_price !== null ? number_format((float) $item->purchase_list_price, 2, '.', '') : '');
                                            $discountRateValue = old("items.$index.discount_rate", number_format((float) ($item->discount_rate ?? 0), 2, '.', ''));
                                            $manualUnitValue = $item->purchase_manual_override
                                                ? ($item->purchase_manual_unit_price ?? $item->purchase_unit_price)
                                                : null;
                                            $effectiveUnitValue = data_get($purchaseUi, 'effective_unit_value', $item->purchase_unit_price);
                                            $calculatedUnitValue = data_get($purchaseUi, 'calculated_unit_value', $item->purchase_calculated_unit_price);
                                            $requestedQuantityValue = old("items.$index.requested_quantity", number_format((float) $item->requested_quantity, 2, '.', ''));
                                            $unitPriceValue = old("items.$index.purchase_unit_price", $effectiveUnitValue !== null ? number_format((float) $effectiveUnitValue, 2, '.', '') : '');
                                            $useCalculatedValue = old("items.$index.use_calculated_price", $item->purchase_manual_override ? '0' : '1');
                                            $purchaseListTryValue = $item->purchase_list_price_try ?? data_get($item->purchase_price_snapshot, 'purchase_list_price_try') ?? $item->purchase_list_price;
                                            $purchaseTotalValue = $item->purchase_total !== null ? number_format((float) $item->purchase_total, 2, ',', '.') . ' TL' : '-';
                                            $purchaseTotalRawValue = $item->purchase_total !== null ? number_format((float) $item->purchase_total, 2, '.', '') : '';
                                            $manualOverrideActive = $useCalculatedValue !== '1';
                                        @endphp
                                        <tr @if($canViewPurchasePrices) data-purchase-row data-list-price-try="{{ $purchaseListTryValue !== null ? number_format((float) $purchaseListTryValue, 6, '.', '') : '' }}" @endif>
                                            <td>
                                                <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}">
                                                @if($isCompletedRequest)
                                                    <span class="pd-badge pd-badge-green">Kilitli</span>
                                                @else
                                                    <input type="hidden" name="items[{{ $index }}][included]" value="0">
                                                    <input type="checkbox" name="items[{{ $index }}][included]" value="1" checked>
                                                @endif
                                            </td>
                                            <td>{{ $item->order?->document_number ?: '-' }}</td>
                                            <td>{{ $item->workForm?->work_form_number ?: '-' }}</td>
                                            <td>{{ $item->product_code ?: '-' }}</td>
                                            <td>
                                                <strong>{{ $item->product_name }}</strong>
                                                @if(!empty($purchaseUi['warning_text']))
                                                    <div class="prw-warning">{{ $purchaseUi['warning_text'] }}</div>
                                                @endif
                                            </td>
                                            <td><input type="number" step="0.01" min="0.01" name="items[{{ $index }}][requested_quantity]" value="{{ $requestedQuantityValue }}" @if($isCompletedRequest) readonly @endif data-requested-quantity-input></td>
                                            <td>{{ number_format((float) $item->received_quantity, 2, ',', '.') }}</td>
                                            <td>{{ number_format((float) $item->remaining_quantity, 2, ',', '.') }}</td>
                                            @if($showPartialReceiveInputs)
                                                <td>
                                                    <input type="number" step="0.01" min="0" max="{{ number_format((float) $item->remaining_quantity, 2, '.', '') }}" name="received_items[{{ $item->id }}]" value="{{ old("received_items.$item->id", '') }}" placeholder="0,00" form="supplier-request-partial-form">
                                                </td>
                                            @endif
                                            @if($canViewPurchasePrices)
                                                <td>
                                                    <input type="hidden" name="items[{{ $index }}][purchase_list_price]" value="{{ $listPriceValue }}">
                                                    @if(!empty($purchaseUi['source_display']))
                                                        <div><strong>Tedarikçi liste:</strong> {{ $purchaseUi['source_display'] }}</div>
                                                    @endif
                                                    @if(!empty($purchaseUi['try_equivalent_display']) && ($purchaseUi['source_currency'] ?? '') !== 'TRY')
                                                        <div class="prw-meta">TL karşılığı: {{ $purchaseUi['try_equivalent_display'] }}</div>
                                                    @endif
                                                    @if(!empty($purchaseUi['rate_display']))
                                                        <div class="prw-meta">Kur: {{ $purchaseUi['rate_display'] }}</div>
                                                    @endif
                                                    @if(!empty($purchaseUi['rate_date_display']) && ($purchaseUi['source_currency'] ?? '') !== 'TRY')
                                                        <div class="prw-meta">Kur tarihi: {{ $purchaseUi['rate_date_display'] }}</div>
                                                    @endif
                                                </td>
                                                <td><input type="number" step="0.01" min="0" max="100" name="items[{{ $index }}][discount_rate]" value="{{ $discountRateValue }}" @if($isCompletedRequest && !$canSaveCompletedPrices) readonly @endif data-discount-rate-input></td>
                                                <td>
                                                    <input type="hidden" name="items[{{ $index }}][use_calculated_price]" value="{{ $useCalculatedValue }}" data-use-calculated-flag>
                                                    <input type="number" step="0.01" min="0" name="items[{{ $index }}][purchase_unit_price]" value="{{ $unitPriceValue }}" @if($isCompletedRequest && !$canSaveCompletedPrices) readonly @endif data-purchase-unit-input data-calculated-unit-value="{{ $calculatedUnitValue !== null ? number_format((float) $calculatedUnitValue, 6, '.', '') : '' }}">
                                                    <div class="prw-meta">
                                                        Hesaplanan: <span data-calculated-display>{{ $purchaseUi['calculated_unit_display'] ?? '-' }}</span>
                                                        @if($canSaveRequest || $canSaveCompletedPrices)
                                                            <button type="button" class="prw-helper-link" data-restore-calculated data-calculated-unit-value="{{ $calculatedUnitValue !== null ? number_format((float) $calculatedUnitValue, 6, '.', '') : '' }}" @if($isCompletedRequest && !$canSaveCompletedPrices) disabled @endif>Hesaplananı kullan</button>
                                                        @endif
                                                    </div>
                                                    <div class="prw-meta" data-manual-override-note @if(!$manualOverrideActive) hidden @endif>Manuel override aktif.</div>
                                                    <div class="prw-meta" data-purchase-risk-note @if(!$manualOverrideActive) hidden @endif>İskonto değişse de final alış birim fiyatı manuel değerde korunur.</div>
                                                </td>
                                                <td data-purchase-total-cell data-purchase-total-value="{{ $purchaseTotalRawValue }}">{{ $purchaseTotalValue }}</td>
                                            @endif
                                            <td><input type="text" name="items[{{ $index }}][note]" value="{{ old("items.$index.note", $item->note) }}" placeholder="Kalem notu" @if($isCompletedRequest && !$canSaveCompletedPrices) readonly @endif></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </form>

                    @if($canManage)
                        <div class="pd-inline-stack">
                            @if($requestRecord->isDraft() || $requestRecord->isRequested())
                                <form method="POST" action="{{ route('admin.procurements.supplier-requests.cancel', $requestRecord) }}">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-light">İptal</button>
                                </form>
                            @endif
                            @if($requestRecord->isRequested())
                                <form method="POST" action="{{ route('admin.procurements.supplier-requests.mark-supplier-ordered', $requestRecord) }}">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-primary">Sipariş Verildi</button>
                                </form>
                            @endif
                            @if($showPartialReceiveInputs)
                                <form id="supplier-request-partial-form" method="POST" action="{{ route('admin.procurements.supplier-requests.mark-partially-received', $requestRecord) }}">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-light">Geleni Kaydet</button>
                                </form>
                                <form method="POST" action="{{ route('admin.procurements.supplier-requests.mark-completed', $requestRecord) }}">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-success">Tamamı Geldi</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <aside class="prw-sticky pd-section-stack">
            <section class="prw-card">
                <div class="prw-body pd-card-stack">
                    <div>
                        <h3 class="prw-title">Sağ Kısa Özet</h3>
                        <div class="prw-note">Tek bir yardımcı yüzey; tekrar eden ikinci süreç paneli yok.</div>
                    </div>
                    <div class="prw-list">
                        <div class="prw-row"><span>Çalışma şekli</span><strong>{{ $depthLabel }}</strong></div>
                        <div class="prw-row"><span>Talep</span><strong>{{ $requestRecord->request_number }}</strong></div>
                        <div class="prw-row"><span>Tedarikçi</span><strong>{{ $requestRecord->supplier?->name ?: '-' }}</strong></div>
                        <div class="prw-row"><span>Durum</span><strong>{{ $requestRecord->safeStatusLabel() }}</strong></div>
                        <div class="prw-row"><span>Kalem</span><strong>{{ $editData['included_item_count'] }}</strong></div>
                        <div class="prw-row"><span>Toplam</span><strong>{{ number_format($editData['total_quantity'], 2, ',', '.') }}</strong></div>
                    </div>
                    <div class="pd-inline-stack">
                        <a href="{{ route('admin.procurements.index', ['supplier_id' => $requestRecord->supplier_id]) }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                        <a href="{{ route('admin.procurements.supplier-requests.print', $requestRecord) }}" class="pd-btn pd-btn-light" target="_blank" rel="noopener">Fiyatsız Talep Formu</a>
                        @if($isCompletedRequest && $canSaveCompletedPrices)
                            <button type="submit" form="supplier-request-update-form" class="pd-btn pd-btn-primary">Alış Fiyatlarını Kaydet</button>
                        @endif
                    </div>
                </div>
            </section>

            @if($isControlledDepth)
                <section class="prw-card">
                    <div class="prw-body pd-card-stack">
                        <div>
                            <h3 class="prw-title">Kontrol Notu</h3>
                            <div class="prw-note">Kontrollü Akışta kalem karşılaştırması ve receipt dikkat notları geniş görünür.</div>
                        </div>
                        <div class="prw-band">{{ $showAdvancedTimeline ? 'Ayrıntılı geçmiş ve kontrollü teslim takibi aktif.' : 'Kontrollü teslim görünümü aktif.' }}</div>
                    </div>
                </section>
            @endif
        </aside>
    </section>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const rows = Array.from(document.querySelectorAll('[data-purchase-row]'));

    const parseNumeric = (value) => {
        if (value === null || value === undefined) {
            return null;
        }

        const normalized = String(value)
            .trim()
            .replace(/\s+/g, '')
            .replace(/\.(?=.*[,])/g, '')
            .replace(',', '.');

        if (normalized === '' || normalized === '-') {
            return null;
        }

        const numeric = Number(normalized);

        return Number.isFinite(numeric) ? numeric : null;
    };

    const round = (value, precision) => {
        if (!Number.isFinite(value)) {
            return null;
        }

        const factor = 10 ** precision;

        return Math.round((value + Number.EPSILON) * factor) / factor;
    };

    const formatInput = (value, precision = 2) => {
        if (!Number.isFinite(value)) {
            return '';
        }

        return round(value, precision).toFixed(precision);
    };

    const formatMoney = (value) => {
        if (!Number.isFinite(value)) {
            return '-';
        }

        return new Intl.NumberFormat('tr-TR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(round(value, 2)) + ' TL';
    };

    const syncRow = (row, preserveManual) => {
        const listTry = parseNumeric(row.dataset.listPriceTry);
        const quantityInput = row.querySelector('[data-requested-quantity-input]');
        const discountInput = row.querySelector('[data-discount-rate-input]');
        const unitInput = row.querySelector('[data-purchase-unit-input]');
        const flag = row.querySelector('[data-use-calculated-flag]');
        const calculatedDisplay = row.querySelector('[data-calculated-display]');
        const totalCell = row.querySelector('[data-purchase-total-cell]');
        const restoreButton = row.querySelector('[data-restore-calculated]');
        const manualNote = row.querySelector('[data-manual-override-note]');
        const riskNote = row.querySelector('[data-purchase-risk-note]');

        if (!quantityInput || !discountInput || !unitInput || !flag || !calculatedDisplay || !totalCell) {
            return;
        }

        const quantity = parseNumeric(quantityInput.value) ?? 0;
        const discountRate = Math.min(100, Math.max(0, parseNumeric(discountInput.value) ?? 0));

        if (listTry === null) {
            const manualUnit = parseNumeric(unitInput.value);
            const total = manualUnit !== null ? round(manualUnit * quantity, 2) : null;

            calculatedDisplay.textContent = '-';
            totalCell.textContent = total !== null ? formatMoney(total) : '-';
            totalCell.dataset.purchaseTotalValue = total !== null ? formatInput(total, 2) : '';
            if (manualNote) {
                manualNote.hidden = manualUnit === null;
            }
            if (riskNote) {
                riskNote.hidden = manualUnit === null;
            }
            return;
        }
        const calculated = round(listTry * (1 - (discountRate / 100)), 6);

        unitInput.dataset.calculatedUnitValue = formatInput(calculated, 6);
        if (restoreButton) {
            restoreButton.dataset.calculatedUnitValue = formatInput(calculated, 6);
        }
        calculatedDisplay.textContent = formatMoney(calculated);

        if (flag.value === '1') {
            unitInput.value = formatInput(calculated, 2);
        }

        const finalUnit = flag.value === '1'
            ? calculated
            : (parseNumeric(unitInput.value) ?? calculated);
        const total = round(finalUnit * quantity, 2);

        if (flag.value === '1' || !preserveManual) {
            unitInput.value = formatInput(finalUnit, 2);
        }

        totalCell.textContent = formatMoney(total);
        totalCell.dataset.purchaseTotalValue = formatInput(total, 2);

        if (manualNote) {
            manualNote.hidden = flag.value === '1';
        }

        if (riskNote) {
            riskNote.hidden = flag.value === '1';
        }
    };

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-restore-calculated]');
        if (!trigger) {
            return;
        }

        const row = trigger.closest('[data-purchase-row]');
        const cell = trigger.closest('td');
        const input = cell ? cell.querySelector('[data-purchase-unit-input]') : null;
        const flag = cell ? cell.querySelector('[data-use-calculated-flag]') : null;

        if (flag) {
            flag.value = '1';
        }

        if (input) {
            const calculatedValue = trigger.dataset.calculatedUnitValue || input.dataset.calculatedUnitValue || '';
            input.value = calculatedValue ? formatInput(parseNumeric(calculatedValue) ?? 0, 2) : '';
        }

        if (row) {
            syncRow(row, false);
        }
    });

    document.addEventListener('input', function (event) {
        const row = event.target.closest('[data-purchase-row]');
        if (!row) {
            return;
        }

        if (event.target.matches('[data-purchase-unit-input]')) {
            const flag = row.querySelector('[data-use-calculated-flag]');
            if (flag) {
                flag.value = event.target.value === '' ? '1' : '0';
            }

            syncRow(row, true);
            return;
        }

        if (event.target.matches('[data-discount-rate-input], [data-requested-quantity-input]')) {
            syncRow(row, true);
        }
    });

    document.addEventListener('change', function (event) {
        const row = event.target.closest('[data-purchase-row]');
        if (!row) {
            return;
        }

        if (event.target.matches('[data-discount-rate-input], [data-requested-quantity-input], [data-purchase-unit-input]')) {
            syncRow(row, true);
        }
    });

    rows.forEach(function (row) {
        syncRow(row, true);
    });
});
</script>
@endsection
