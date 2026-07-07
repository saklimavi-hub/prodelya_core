@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Talebi Düzenle')
@section('page_title', 'Tedarikçi Talebi Düzenle')
@section('page_subtitle', 'Talep kalemlerini gözden geçirin, iç tedarik bilgilerini yönetin ve fiyatsız dış forma geçin.')

@section('content')
@php
    $items = $editData['items'];
    $canManage = $canManageProcurementRequests ?? false;
    $showPartialReceiveInputs = $canManage && ($requestRecord->isSupplierOrdered() || $requestRecord->isPartiallyReceived());
    $canSaveRequest = $canManage && !$requestRecord->isCompleted() && !$requestRecord->isCancelled();
@endphp
<style>
    .spr-edit { display: grid; gap: 14px; }
    .spr-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05); overflow: hidden; }
    .spr-body { padding: 16px; }
    .spr-head { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
    .spr-box { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 12px; }
    .spr-label { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .spr-value { margin-top: 6px; color: #111827; font-size: 14px; font-weight: 700; }
    .spr-table-wrap { overflow-x: auto; }
    .spr-table { width: 100%; border-collapse: collapse; min-width: 1180px; }
    .spr-table th, .spr-table td { padding: 10px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
    .spr-table th { background: #fbfcfe; color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .spr-table input[type="text"], .spr-table input[type="number"] { width: 100%; }
    .spr-band { border: 1px solid #dbeafe; border-radius: 6px; background: #f8fbff; padding: 10px 12px; color: #475569; font-size: 12px; line-height: 1.45; }
    .spr-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .spr-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
    .spr-toolbar-group { display: flex; flex-wrap: wrap; gap: 8px; }
    .spr-product-name { font-weight: 700; color: #111827; }
    .spr-meta { margin-top: 4px; color: #6b7280; font-size: 11px; line-height: 1.45; }
    .spr-warning-list { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 6px; }
    .spr-warning-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; background: #fff7ed; color: #9a3412; font-size: 11px; font-weight: 700; }
    .spr-price-output { min-width: 92px; display: inline-block; font-weight: 700; color: #111827; }
    .spr-manual-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; background: #eff6ff; color: #1d4ed8; font-size: 11px; font-weight: 700; }
    @media (max-width: 1100px) { .spr-head { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 640px) { .spr-head { grid-template-columns: 1fr; } }
</style>

@if(session('success'))
    <div class="pd-alert">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="pd-alert pd-alert-danger">
        {{ $errors->first() }}
    </div>
@endif

<div class="spr-edit">
    <section class="spr-card">
        <div class="spr-body">
            <div class="spr-head">
                <div class="spr-box">
                    <div class="spr-label">Talep No</div>
                    <div class="spr-value">{{ $requestRecord->request_number }}</div>
                </div>
                <div class="spr-box">
                    <div class="spr-label">Tedarikçi</div>
                    <div class="spr-value">{{ $requestRecord->supplier?->name ?: '-' }}</div>
                </div>
                <div class="spr-box">
                    <div class="spr-label">Talep Tarihi</div>
                    <div class="spr-value">{{ optional($requestRecord->request_date)->format('d.m.Y') ?: '-' }}</div>
                </div>
                <div class="spr-box">
                    <div class="spr-label">Durum</div>
                    <div class="spr-value">{{ $requestRecord->safeStatusLabel() }}</div>
                </div>
                <div class="spr-box">
                    <div class="spr-label">Kalem / Toplam Adet</div>
                    <div class="spr-value">{{ $editData['included_item_count'] }} / {{ number_format($editData['total_quantity'], 2, ',', '.') }}</div>
                </div>
            </div>
        </div>
    </section>

    <section class="spr-card">
        <div class="spr-body">
            <div class="spr-toolbar" style="margin-bottom: 14px;">
                <div>
                    <strong>Talep Aksiyonları</strong>
                    <div class="spr-meta" style="margin-top:4px;">Kaydetme, fiyatsız form ve listeye dönüş işlemlerini bu alandan yönetin.</div>
                </div>
                <div class="spr-toolbar-group">
                    @if($canSaveRequest)
                        @if($requestRecord->isDraft())
                            <button type="submit" name="submit_action" value="draft" form="supplier-request-update-form" class="pd-btn pd-btn-light">Taslak Olarak Kaydet</button>
                        @endif
                        <button type="submit" name="submit_action" value="request" form="supplier-request-update-form" class="pd-btn pd-btn-primary">Kaydet</button>
                    @endif
                    <a href="{{ route('admin.procurements.supplier-requests.print', $requestRecord) }}" class="pd-btn pd-btn-light" target="_blank" rel="noopener">Fiyatsız Talep Formunu Aç</a>
                    <a href="{{ route('admin.procurements.index', ['supplier_id' => $requestRecord->supplier_id]) }}" class="pd-btn pd-btn-light">Tedarik Listesine Dön</a>
                </div>
            </div>
            <div class="spr-band" style="margin-bottom: 12px;">
                Bu ekrandaki alış liste fiyatı, iskonto, alış birim fiyatı ve alış toplam alanları yalnız iç tedarik takibi içindir. Tedarikçiye gönderilecek talep formunda fiyat bilgileri gösterilmez.
            </div>
            @if($editData['has_missing_purchase_prices'] ?? false)
                <div class="spr-band" style="margin-bottom: 12px; border-color:#fde68a; background:#fffdf5;">
                    Bazı kalemlerde güvenilir liste fiyatı bulunamadı. Bu satırlarda `0,00` gösterilir; gerekiyorsa özel alış fiyatını manuel girin.
                </div>
            @endif

            <form id="supplier-request-update-form" method="POST" action="{{ route('admin.procurements.supplier-requests.update', $requestRecord) }}">
                @csrf
                @method('PATCH')

                <div style="margin-bottom: 12px;">
                    <label for="request-note" class="spr-label" style="display:block; margin-bottom:6px;">Not</label>
                    <input id="request-note" type="text" name="note" value="{{ old('note', $requestRecord->note) }}" placeholder="Opsiyonel iç not">
                </div>

                <div class="spr-table-wrap">
                    <table class="spr-table">
                        <thead>
                            <tr>
                                <th>Dahil</th>
                                <th>Sipariş No</th>
                                <th>İş Formu No</th>
                                <th>Ürün Kodu</th>
                                <th>Ürün Adı</th>
                                <th>İstenen Adet</th>
                                <th>Birim</th>
                                <th>Alınan</th>
                                <th>Kalan</th>
                                @if($showPartialReceiveInputs)
                                    <th>Bu Tur Gelen</th>
                                @endif
                                @if($canViewPurchasePrices)
                                    <th>Alış Liste Fiyatı</th>
                                    <th>İskonto %</th>
                                    <th>Alış Birim Fiyatı</th>
                                    <th>Alış Toplam</th>
                                @endif
                                <th>Not</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $index => $item)
                                @php
                                    $listPriceValue = old("items.$index.purchase_list_price", number_format((float) ($item->purchase_list_price ?? $item->suggested_purchase_list_price ?? 0), 2, '.', ''));
                                    $discountRateValue = old("items.$index.discount_rate", number_format((float) ($item->discount_rate ?? 0), 2, '.', ''));
                                    $unitPriceValue = old("items.$index.purchase_unit_price", $item->purchase_unit_price !== null ? number_format((float) $item->purchase_unit_price, 2, '.', '') : '');
                                    $purchaseTotalValue = $item->purchase_total !== null ? number_format((float) $item->purchase_total, 2, ',', '.') . ' TL' : '-';
                                    $salesUnitPrice = $item->sales_unit_price ?? null;
                                    $salesTotal = $item->sales_total ?? null;
                                    $salesWarnings = (array) ($item->purchase_sales_warnings ?? []);
                                    $expectedUnitPrice = round((float) ($item->purchase_list_price ?? 0) * (1 - ((float) ($item->discount_rate ?? 0) / 100)), 2);
                                    $hasManualUnitPrice = $item->purchase_unit_price !== null
                                        && (
                                            $item->purchase_list_price === null
                                            || (float) $item->purchase_list_price == 0.0
                                            || abs((float) $item->purchase_unit_price - $expectedUnitPrice) > 0.009
                                        );
                                @endphp
                                <tr
                                    data-spr-row
                                    data-sales-unit-price="{{ $salesUnitPrice !== null ? number_format((float) $salesUnitPrice, 2, '.', '') : '' }}"
                                    data-sales-total="{{ $salesTotal !== null ? number_format((float) $salesTotal, 2, '.', '') : '' }}"
                                    data-manual-unit-price="{{ $hasManualUnitPrice ? '1' : '0' }}"
                                >
                                    <td>
                                        <input type="hidden" name="items[{{ $index }}][included]" value="0">
                                        <input type="checkbox" name="items[{{ $index }}][included]" value="1" checked>
                                        <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}">
                                    </td>
                                    <td>{{ $item->order?->document_number ?: '-' }}</td>
                                    <td>{{ $item->workForm?->work_form_number ?: '-' }}</td>
                                    <td>{{ $item->product_code ?: '-' }}</td>
                                    <td>
                                        <div class="spr-product-name">{{ $item->product_name }}</div>
                                            @if($canViewSalesReference)
                                                <div class="spr-meta">
                                                    @if($salesUnitPrice !== null)
                                                    <span data-spr-sales-unit-text>Satış Ref: {{ number_format((float) $salesUnitPrice, 2, ',', '.') }} TL / adet</span>
                                                @else
                                                    <span data-spr-sales-unit-text>Satış fiyatı referansı bulunamadı</span>
                                                @endif
                                                @if($salesTotal !== null)
                                                    <br><span data-spr-sales-total-text>Satış Toplam: {{ number_format((float) $salesTotal, 2, ',', '.') }} TL</span>
                                                @endif
                                            </div>
                                            <div class="spr-warning-list" data-spr-warning-list @if(empty($salesWarnings)) hidden @endif>
                                                    @foreach($salesWarnings as $warning)
                                                        <span class="spr-warning-badge" data-spr-warning-item>{{ $warning }}</span>
                                                    @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            name="items[{{ $index }}][requested_quantity]"
                                            value="{{ old("items.$index.requested_quantity", number_format((float) $item->requested_quantity, 2, '.', '')) }}"
                                            data-spr-quantity
                                        >
                                    </td>
                                    <td>{{ $item->safeUnitLabel() }}</td>
                                    <td>{{ number_format((float) $item->received_quantity, 2, ',', '.') }}</td>
                                    <td>{{ number_format((float) $item->remaining_quantity, 2, ',', '.') }}</td>
                                    @if($showPartialReceiveInputs)
                                        <td>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="{{ number_format((float) $item->remaining_quantity, 2, '.', '') }}"
                                                name="received_items[{{ $item->id }}]"
                                                value="{{ old("received_items.$item->id", '') }}"
                                                placeholder="0,00"
                                                form="supplier-request-partial-form"
                                            >
                                        </td>
                                    @endif
                                    @if($canViewPurchasePrices)
                                        <td>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                name="items[{{ $index }}][purchase_list_price]"
                                                value="{{ $listPriceValue }}"
                                                data-spr-list-price
                                            >
                                            @if($item->purchase_list_price_missing ?? false)
                                                <div class="pm-note-muted" style="margin-top:4px;">Liste fiyatı bulunamadı; özel alış fiyatı girin</div>
                                            @endif
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                name="items[{{ $index }}][discount_rate]"
                                                value="{{ $discountRateValue }}"
                                                data-spr-discount
                                            >
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                name="items[{{ $index }}][purchase_unit_price]"
                                                value="{{ $unitPriceValue }}"
                                                data-spr-unit-price
                                            >
                                            <div style="margin-top:4px;">
                                                <span class="spr-manual-badge" data-spr-manual-badge @if(!$hasManualUnitPrice) hidden @endif>Manuel fiyat</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="spr-price-output" data-spr-total-output>{{ $purchaseTotalValue }}</span>
                                        </td>
                                    @endif
                                    <td>
                                        <input type="text" name="items[{{ $index }}][note]" value="{{ old("items.$index.note", $item->note) }}" placeholder="Kalem notu">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

            </form>

            @if($canManage)
                <div class="spr-band" style="margin-top: 14px; margin-bottom: 10px; border-color:#e5e7eb; background:#fbfcfe;">
                    Durum işlemleri yalnız tedarik aşamasına göre açılır. Aynı anda tüm workflow butonları gösterilmez.
                </div>
                <div class="spr-actions" style="margin-top: 10px;">
                    @if($requestRecord->isDraft())
                        <form method="POST" action="{{ route('admin.procurements.supplier-requests.cancel', $requestRecord) }}">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-light">İptal</button>
                        </form>
                    @elseif($requestRecord->isRequested())
                        <form method="POST" action="{{ route('admin.procurements.supplier-requests.cancel', $requestRecord) }}">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-light">İptal</button>
                        </form>
                        <form method="POST" action="{{ route('admin.procurements.supplier-requests.mark-supplier-ordered', $requestRecord) }}">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-primary">Sipariş Verildi</button>
                        </form>
                    @elseif($requestRecord->isSupplierOrdered() || $requestRecord->isPartiallyReceived())
                        <form id="supplier-request-partial-form" method="POST" action="{{ route('admin.procurements.supplier-requests.mark-partially-received', $requestRecord) }}">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-light">Kısmi Geldi</button>
                        </form>
                        <form method="POST" action="{{ route('admin.procurements.supplier-requests.mark-completed', $requestRecord) }}">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-success">Tamamı Geldi</button>
                        </form>
                    @else
                        <div class="spr-band">Bu talep tamamlandı veya kapatıldı. Yeni durum işlemi bulunmuyor.</div>
                    @endif
                </div>
            @endif
        </div>
    </section>
</div>
@if($canViewPurchasePrices)
<script>
    (function () {
        const rows = document.querySelectorAll('.spr-table tbody tr');

        const formatMoney = (value) => {
            if (!Number.isFinite(value)) {
                return '-';
            }

            return value.toLocaleString('tr-TR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' TL';
        };

        const parseRawNumber = (value) => {
            if (value === null || value === undefined) {
                return null;
            }

            const raw = String(value).replace(/\s+/g, '').replace(',', '.').trim();

            if (raw === '') {
                return null;
            }

            const parsed = Number.parseFloat(raw);
            return Number.isFinite(parsed) ? parsed : null;
        };

        const buildWarnings = (purchaseListPrice, purchaseUnitPrice, purchaseTotal, salesUnitPrice, salesTotal) => {
            const warnings = [];

            if (((purchaseListPrice ?? 0) === 0) && ((purchaseUnitPrice ?? 0) === 0)) {
                warnings.push('Liste fiyatı bulunamadı; özel alış fiyatı girin');
            }

            if (salesUnitPrice === null && salesTotal === null) {
                warnings.push('Satış fiyatı referansı bulunamadı');
            }

            if (purchaseUnitPrice !== null && salesUnitPrice !== null) {
                if (purchaseUnitPrice > salesUnitPrice) {
                    warnings.push('Alış fiyatı satış fiyatını aşıyor');
                } else if (salesUnitPrice > 0
                    && purchaseUnitPrice >= (salesUnitPrice * 0.90)
                    && purchaseUnitPrice <= salesUnitPrice) {
                    warnings.push('Alış fiyatı satış fiyatına çok yakın');
                }
            }

            if (purchaseTotal !== null && salesTotal !== null && purchaseTotal > salesTotal) {
                warnings.push('Alış toplamı satış toplamını aşıyor');
            }

            return warnings;
        };

        rows.forEach((row) => {
            const quantityInput = row.querySelector('[data-spr-quantity]');
            const listPriceInput = row.querySelector('[data-spr-list-price]');
            const discountInput = row.querySelector('[data-spr-discount]');
            const unitPriceInput = row.querySelector('[data-spr-unit-price]');
            const totalOutput = row.querySelector('[data-spr-total-output]');
            const warningList = row.querySelector('[data-spr-warning-list]');
            const manualBadge = row.querySelector('[data-spr-manual-badge]');
            const salesUnitPrice = parseRawNumber(row.getAttribute('data-sales-unit-price'));
            const salesTotal = parseRawNumber(row.getAttribute('data-sales-total'));

            if (!quantityInput || !listPriceInput || !discountInput || !unitPriceInput || !totalOutput) {
                return;
            }

            let manualOverride = row.getAttribute('data-manual-unit-price') === '1';

            const parseValue = (input) => {
                return parseRawNumber(input.value || '');
            };

            const syncWarnings = (warnings) => {
                if (!warningList) {
                    return;
                }

                warningList.innerHTML = '';

                if (!warnings.length) {
                    warningList.hidden = true;
                    return;
                }

                warningList.hidden = false;

                warnings.forEach((warning) => {
                    const badge = document.createElement('span');
                    badge.className = 'spr-warning-badge';
                    badge.setAttribute('data-spr-warning-item', '1');
                    badge.textContent = warning;
                    warningList.appendChild(badge);
                });
            };

            const updateTotals = () => {
                const quantity = parseValue(quantityInput) ?? 0;
                const listPrice = parseValue(listPriceInput) ?? 0;
                const discountRate = parseValue(discountInput) ?? 0;
                let unitPrice = parseValue(unitPriceInput);

                if (!manualOverride) {
                    unitPrice = listPrice * (1 - (discountRate / 100));
                    unitPrice = Number.isFinite(unitPrice) ? unitPrice : 0;
                    unitPriceInput.value = unitPrice.toFixed(2);
                }

                const purchaseTotal = unitPrice !== null ? unitPrice * quantity : null;

                totalOutput.textContent = purchaseTotal !== null ? formatMoney(purchaseTotal) : '-';

                if (manualBadge) {
                    manualBadge.hidden = !manualOverride;
                }

                syncWarnings(buildWarnings(
                    listPrice,
                    unitPrice,
                    purchaseTotal,
                    salesUnitPrice,
                    salesTotal
                ));
            };

            listPriceInput.addEventListener('input', () => {
                if (!manualOverride) {
                    updateTotals();
                    return;
                }

                const quantity = parseValue(quantityInput) ?? 0;
                const listPrice = parseValue(listPriceInput) ?? 0;
                const unitPrice = parseValue(unitPriceInput) ?? 0;
                const purchaseTotal = unitPrice * quantity;

                syncWarnings(buildWarnings(listPrice, unitPrice, purchaseTotal, salesUnitPrice, salesTotal));
                totalOutput.textContent = formatMoney(purchaseTotal);
            });

            discountInput.addEventListener('input', () => {
                if (!manualOverride) {
                    updateTotals();
                    return;
                }

                const quantity = parseValue(quantityInput) ?? 0;
                const listPrice = parseValue(listPriceInput) ?? 0;
                const unitPrice = parseValue(unitPriceInput) ?? 0;
                const purchaseTotal = unitPrice * quantity;

                syncWarnings(buildWarnings(listPrice, unitPrice, purchaseTotal, salesUnitPrice, salesTotal));
                totalOutput.textContent = formatMoney(purchaseTotal);
            });

            quantityInput.addEventListener('input', updateTotals);
            unitPriceInput.addEventListener('input', () => {
                manualOverride = unitPriceInput.value.trim() !== '';
                updateTotals();
            });

            updateTotals();
        });
    })();
</script>
@endif
@endsection
