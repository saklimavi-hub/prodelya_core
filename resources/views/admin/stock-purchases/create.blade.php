@extends('layouts.prodelya-admin')

@section('title', 'Stok Girişi / Satın Alma')
@section('page_title', 'Stok Girişi / Satın Alma')
@section('page_subtitle', 'Satın alma ve eldeki mevcut stok girişlerini tek ekrandan kaydedin ve oluşan stok hareketlerini takip edin.')

@section('content')
@php
    $entryTypeLabels = [
        'supplier_purchase' => 'Satın Alma',
        'existing_stock' => 'Eldeki Mevcut Stok',
    ];
@endphp
<div class="pd-hub-family-shell pd-stock-purchase-shell">
    @if(session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="pd-alert-warning">
            <strong>Kaydetme sırasında düzeltmeniz gereken alanlar var.</strong>
            <ul class="pd-stock-purchase-error-list">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Stok Girişi / Satın Alma</h1>
                    <p class="pd-hero-subtitle">Ayrı bir local stok modülü olmadan, exact ürün bazında sade stok girişi yapın. Satın alma işleminde stok ve tedarikçi carisi birlikte oluşur; eldeki mevcut stok işleminde yalnız stok artar.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">2 işlem tipi</span>
                        <span class="pd-badge pd-badge-green">Exact varyant öncelikli</span>
                        <span class="pd-badge pd-badge-purple">Canonical katalog arama</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.stock-purchases.index') }}" class="pd-btn pd-btn-light">Kayıt Listesi</a>
                    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-light">Kataloğa Dön</a>
                </div>
            </div>
        </div>
    </section>

    <form method="POST" action="{{ route('admin.stock-purchases.store') }}" class="pd-stock-purchase-form" id="stockPurchaseForm" novalidate>
        @csrf
        <section class="pd-section-card pd-section-card-soft-blue">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Giriş Bilgileri</h3>
                    <p class="pd-section-subtitle">Tek sayfada iki net işlem: Satın Alma veya Eldeki Mevcut Stok.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-stock-purchase-top-grid">
                    <div>
                        <label class="pd-label" for="entry_type">Giriş Tipi</label>
                        <select name="entry_type" id="entry_type" class="pd-select" data-entry-type>
                            @foreach($entryTypeLabels as $value => $label)
                                <option value="{{ $value }}" @selected($entryType === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div data-supplier-field>
                        <label class="pd-label" for="supplier_id">Tedarikçi</label>
                        <select name="supplier_id" id="supplier_id" class="pd-select" data-supplier-filter>
                            <option value="">Seçili satırdan otomatik</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((int) $supplierId === (int) $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label" for="entry_date">Tarih</label>
                        <input type="date" name="entry_date" id="entry_date" value="{{ $entryDate }}" class="pd-input">
                    </div>
                    <div data-purchase-only>
                        <label class="pd-label" for="document_no">Belge No</label>
                        <input type="text" name="document_no" id="document_no" value="{{ $documentNo }}" class="pd-input" placeholder="İrsaliye / Fatura no">
                    </div>
                </div>
                <div class="pd-stock-purchase-mode-grid">
                    <article class="pd-stock-purchase-mode-card {{ $entryType === 'supplier_purchase' ? 'is-active' : '' }}" data-mode-card="supplier_purchase">
                        <h4>Satın Alma</h4>
                        <p>Tedarikçi, liste fiyatı, alış iskontosu ve toplam tutar ile çalışır. Kaydedildiğinde local stok ve tedarikçi cari borcu oluşur.</p>
                    </article>
                    <article class="pd-stock-purchase-mode-card {{ $entryType === 'existing_stock' ? 'is-active' : '' }}" data-mode-card="existing_stock">
                        <h4>Eldeki Mevcut Stok</h4>
                        <p>İlk kurulum, sayım veya daha önce elde bulunan ürünler içindir. Fiyat ve cari borç oluşmaz; yalnız stok artar.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Ürün Satırları</h3>
                    <p class="pd-section-subtitle">Parent ürün seçilemez. Exact varyantlar ve düz satılabilir ürünler canonical katalog aramasıyla gelir.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-stock-purchase-table-wrap">
                    <table class="pd-table pd-stock-purchase-table" id="stockPurchaseRowsTable">
                        <thead>
                            <tr>
                                <th>Dahil</th>
                                <th>Ürün / Varyant</th>
                                <th>Mevcut Stok</th>
                                <th>Giriş Adedi</th>
                                <th>Birim</th>
                                <th data-finance-col>Liste Fiyatı</th>
                                <th data-finance-col>Alış İskontosu %</th>
                                <th data-finance-col>Alış Birim Fiyatı</th>
                                <th data-finance-col>Toplam Tutar</th>
                                <th>Not</th>
                                <th>Sil</th>
                            </tr>
                        </thead>
                        <tbody id="stockPurchaseRows">                            @foreach($rows as $index => $row)
                                @php
                                    $calculatedUnit = round((float) $row['list_price'] * (1 - ((float) $row['discount_rate'] / 100)), 4);
                                    $manualOverride = abs((float) $row['unit_purchase_price'] - $calculatedUnit) > 0.00005;
                                    $searchText = $row['search_text'] ?: trim(collect([$row['product_code'], $row['product_name']])->filter()->implode(' - '));
                                    $stockMeta = collect([
                                        'Local stok: ' . number_format((float) $row['local_stock_quantity'], 0, ',', '.'),
                                        'Tedarikçi stok: ' . number_format((float) $row['supplier_stock_quantity'], 0, ',', '.'),
                                        'Liste: ' . number_format((float) $row['list_price'], 2, ',', '.') . ' ' . ($row['currency'] === 'TRY' ? 'TL' : $row['currency']),
                                    ])->implode(' · ');
                                @endphp
                                <tr class="pd-stock-purchase-row" data-row>
                                    <td>
                                        <label class="pd-stock-purchase-inline-check">
                                            <input type="checkbox" name="rows[{{ $index }}][include]" value="1" {{ !empty($row['include']) ? 'checked' : '' }}>
                                        </label>
                                    </td>
                                    <td class="pd-stock-purchase-product-cell">
                                        <div class="pd-stock-purchase-search-wrap">
                                            <input type="text" name="rows[{{ $index }}][search_text]" value="{{ $searchText }}" class="pd-input" data-product-search placeholder="SKU, ürün adı, varyant, renk, tedarikçi..." autocomplete="off">
                                            <input type="hidden" name="rows[{{ $index }}][selection_key]" value="{{ $row['selection_key'] }}" data-selection-key>
                                            <div class="pd-stock-purchase-search-results is-hidden" data-search-results></div>
                                        </div>
                                        <div class="pd-stock-purchase-selected" data-selected-panel>
                                            <div class="pd-stock-purchase-selected-title" data-selected-title>{{ $row['product_name'] }}</div>
                                            <div class="pd-stock-purchase-selected-meta" data-selected-meta>{{ collect(['SKU: ' . $row['product_code'], $row['supplier_name']])->filter()->implode(' · ') }}</div>
                                            <div class="pd-stock-purchase-selected-stock" data-selected-stock>{{ filled($row['product_name']) ? $stockMeta : '' }}</div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="pd-stock-purchase-stock-main">Local: <strong data-local-stock>{{ number_format((float) $row['local_stock_quantity'], 0, ',', '.') }}</strong></div>
                                        <div class="pd-stock-purchase-stock-note">Tedarikçi: <span data-supplier-stock>{{ number_format((float) $row['supplier_stock_quantity'], 0, ',', '.') }}</span></div>
                                    </td>
                                    <td>
                                        <input type="number" min="0.0001" step="0.0001" name="rows[{{ $index }}][quantity]" value="{{ $row['quantity'] }}" class="pd-input" data-quantity>
                                    </td>
                                    <td>
                                        <span class="pd-badge pd-badge-light">Adet</span>
                                    </td>
                                    <td data-finance-col>
                                        <div class="pd-stock-purchase-price-field">
                                            <input type="number" min="0" step="0.0001" name="rows[{{ $index }}][list_price]" value="{{ $row['list_price'] }}" class="pd-input" data-list-price>
                                            <span class="pd-stock-purchase-currency-chip" data-currency-label>{{ $row['currency'] === 'TRY' ? 'TL' : $row['currency'] }}</span>
                                        </div>
                                    </td>
                                    <td data-finance-col>
                                        <input type="number" min="0" max="100" step="0.01" name="rows[{{ $index }}][discount_rate]" value="{{ $row['discount_rate'] }}" class="pd-input" data-discount-rate>
                                    </td>
                                    <td data-finance-col>
                                        <input type="number" min="0" step="0.0001" name="rows[{{ $index }}][unit_purchase_price]" value="{{ $row['unit_purchase_price'] }}" class="pd-input" data-unit-price data-manual="{{ $manualOverride ? '1' : '0' }}">
                                        <input type="hidden" name="rows[{{ $index }}][currency]" value="{{ $row['currency'] }}" data-currency-input>
                                        <input type="hidden" name="rows[{{ $index }}][exchange_rate]" value="{{ $row['exchange_rate'] }}" data-exchange-rate>
                                        <input type="hidden" name="rows[{{ $index }}][exchange_rate_date]" value="{{ $row['exchange_rate_date'] }}" data-exchange-rate-date>
                                    </td>
                                    <td data-finance-col>
                                        <strong data-line-total>0,00 TL</strong>
                                        <div class="pd-stock-purchase-stock-note" data-rate-note></div>
                                    </td>
                                    <td>
                                        <input type="text" name="rows[{{ $index }}][line_note]" value="{{ $row['line_note'] }}" class="pd-input" placeholder="Satır notu">
                                    </td>
                                    <td>
                                        <button type="button" class="pd-btn pd-btn-sm pd-btn-light" data-remove-row>Sil</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="pd-stock-purchase-toolbar">
                    <button type="button" class="pd-btn pd-btn-light" id="addStockPurchaseRow">Satır Ekle</button>
                    <div class="pd-stock-purchase-bulk-discount" data-purchase-only>
                        <input type="number" min="0" max="100" step="0.01" class="pd-input" id="bulkDiscountRate" placeholder="Toplu iskonto %">
                        <button type="button" class="pd-btn pd-btn-light" id="applyBulkDiscount">Toplu İskonto</button>
                    </div>
                    <div class="pd-stock-purchase-summary">
                        <div><span>Toplam Adet</span><strong id="stockPurchaseTotalQuantity">0</strong></div>
                        <div data-finance-only><span>Genel Toplam</span><strong id="stockPurchaseGrandTotal">0,00 TL</strong></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Genel Not</h3>
                    <p class="pd-section-subtitle">İsteğe bağlı tek açıklama notu.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <textarea name="page_note" rows="3" class="pd-input" placeholder="Açıklama notu">{{ $pageNote }}</textarea>
            </div>
        </section>

        <div class="pd-bottom-action-buttons pd-stock-purchase-bottom-actions">
            <a href="{{ route('admin.stock-purchases.index') }}" class="pd-btn pd-btn-light">Vazgeç</a>
            <button type="submit" class="pd-btn pd-btn-primary" id="stockPurchaseSubmitLabel">Kaydet ve Stoğa Ekle</button>
        </div>
    </form>
</div>
@endsection

@push('styles')
<style>
.pd-stock-purchase-shell .pd-stock-purchase-error-list { margin: 8px 0 0; padding-left: 18px; }
.pd-stock-purchase-shell .pd-stock-purchase-top-grid { display:grid; gap:16px; grid-template-columns:repeat(4,minmax(0,1fr)); }
.pd-stock-purchase-shell .pd-stock-purchase-mode-grid { display:grid; gap:16px; grid-template-columns:repeat(2,minmax(0,1fr)); margin-top:20px; }
.pd-stock-purchase-shell .pd-stock-purchase-mode-card { border:1px solid #d8dee9; border-radius:16px; background:#f8fbff; padding:18px; }
.pd-stock-purchase-shell .pd-stock-purchase-mode-card.is-active { border-color:#7aa2ff; box-shadow:0 12px 26px rgba(59,130,246,.10); background:#eef5ff; }
.pd-stock-purchase-shell .pd-stock-purchase-mode-card h4 { margin:0 0 8px; font-size:15px; }
.pd-stock-purchase-shell .pd-stock-purchase-mode-card p { margin:0; color:#5b6476; line-height:1.5; }
.pd-stock-purchase-shell .pd-stock-purchase-table-wrap { overflow-x:auto; }
.pd-stock-purchase-shell .pd-stock-purchase-table th, .pd-stock-purchase-shell .pd-stock-purchase-table td { vertical-align:top; }
.pd-stock-purchase-shell .pd-stock-purchase-search-wrap { position:relative; min-width:320px; }
.pd-stock-purchase-shell .pd-stock-purchase-search-results { position:absolute; top:calc(100% + 6px); left:0; right:0; z-index:50; background:#fff; border:1px solid #d8dee9; border-radius:16px; box-shadow:0 16px 36px rgba(15,23,42,.12); max-height:320px; overflow:auto; padding:6px; }
.pd-stock-purchase-shell .pd-stock-purchase-search-results.is-hidden { display:none !important; }
.pd-stock-purchase-shell .pd-stock-purchase-result { width:100%; text-align:left; border:0; background:#fff; border-radius:12px; padding:12px 14px; display:block; }
.pd-stock-purchase-shell .pd-stock-purchase-result:hover,
.pd-stock-purchase-shell .pd-stock-purchase-result.is-active { background:#eef5ff; }
.pd-stock-purchase-shell .pd-stock-purchase-result-title { font-weight:700; color:#1f2937; }
.pd-stock-purchase-shell .pd-stock-purchase-result-meta,
.pd-stock-purchase-shell .pd-stock-purchase-result-stock,
.pd-stock-purchase-shell .pd-stock-purchase-selected-meta,
.pd-stock-purchase-shell .pd-stock-purchase-selected-stock,
.pd-stock-purchase-shell .pd-stock-purchase-stock-note { margin-top:6px; color:#6b7280; font-size:12px; line-height:1.45; }
.pd-stock-purchase-shell .pd-stock-purchase-selected { margin-top:10px; }
.pd-stock-purchase-shell .pd-stock-purchase-selected-title { font-weight:700; color:#1f2937; }
.pd-stock-purchase-shell .pd-stock-purchase-stock-main { color:#1f2937; }
.pd-stock-purchase-shell .pd-stock-purchase-price-field { display:flex; gap:8px; align-items:center; }
.pd-stock-purchase-shell .pd-stock-purchase-currency-chip { min-width:40px; text-align:center; padding:8px 10px; border-radius:12px; background:#f5f7fb; color:#344054; font-size:12px; font-weight:600; }
.pd-stock-purchase-shell .pd-stock-purchase-toolbar { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-top:18px; flex-wrap:wrap; }
.pd-stock-purchase-shell .pd-stock-purchase-bulk-discount { display:flex; gap:8px; align-items:center; }
.pd-stock-purchase-shell .pd-stock-purchase-summary { display:flex; gap:18px; flex-wrap:wrap; }
.pd-stock-purchase-shell .pd-stock-purchase-summary div { min-width:140px; padding:12px 14px; border-radius:14px; background:#f6f8fb; }
.pd-stock-purchase-shell .pd-stock-purchase-summary span { display:block; color:#667085; font-size:12px; }
.pd-stock-purchase-shell .pd-stock-purchase-summary strong { display:block; margin-top:4px; font-size:15px; color:#1f2937; }
.pd-stock-purchase-shell .pd-stock-purchase-bottom-actions { margin-top:20px; }
.pd-stock-purchase-shell .pd-stock-purchase-inline-check { display:flex; justify-content:center; padding-top:10px; }
.pd-stock-purchase-shell .is-hidden { display:none !important; }
@media (max-width: 1100px) {
    .pd-stock-purchase-shell .pd-stock-purchase-top-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .pd-stock-purchase-shell .pd-stock-purchase-mode-grid { grid-template-columns:1fr; }
}
@media (max-width: 768px) {
    .pd-stock-purchase-shell .pd-stock-purchase-top-grid { grid-template-columns:1fr; }
    .pd-stock-purchase-shell .pd-stock-purchase-search-wrap { min-width:260px; }
}
</style>
@endpush


@push('styles')
<style>
/* Stock Purchase Template Family Refresh - 2026-07-20 */
.pd-stock-purchase-shell {
    font-family: Arial, Helvetica, sans-serif;
    color: #24324a;
}

.pd-stock-purchase-shell .pd-hero-card,
.pd-stock-purchase-shell .pd-section-card {
    border: 1px solid #e3e8f0;
    border-radius: 12px;
    background: #ffffff;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
}

.pd-stock-purchase-shell .pd-card-body,
.pd-stock-purchase-shell .pd-section-body {
    padding: 22px;
}

.pd-stock-purchase-shell .pd-section-header {
    padding: 18px 22px 14px;
    border-bottom: 1px solid #edf2f7;
}

.pd-stock-purchase-shell .pd-hero-title,
.pd-stock-purchase-shell .pd-section-title {
    font-family: Arial, Helvetica, sans-serif;
    color: #1f2f50;
    letter-spacing: -0.02em;
}

.pd-stock-purchase-shell .pd-hero-title {
    font-size: 34px;
    line-height: 1.08;
    font-weight: 700;
}

.pd-stock-purchase-shell .pd-section-title {
    font-size: 26px;
    line-height: 1.12;
    font-weight: 700;
}

.pd-stock-purchase-shell .pd-hero-subtitle,
.pd-stock-purchase-shell .pd-section-subtitle,
.pd-stock-purchase-shell .pd-stock-purchase-mode-card p,
.pd-stock-purchase-shell .pd-stock-purchase-selected-meta,
.pd-stock-purchase-shell .pd-stock-purchase-selected-stock,
.pd-stock-purchase-shell .pd-stock-purchase-stock-note {
    font-size: 14px;
    line-height: 1.6;
    color: #6b7a96;
}

.pd-stock-purchase-shell .pd-label {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.03em;
    color: #6b7a96;
    text-transform: uppercase;
}

.pd-stock-purchase-shell .pd-input,
.pd-stock-purchase-shell .pd-select,
.pd-stock-purchase-shell textarea.pd-input {
    min-height: 42px;
    border: 1px solid #d9e3f0;
    border-radius: 10px;
    background: #fbfdff;
    color: #22314d;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.02);
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
}

.pd-stock-purchase-shell textarea.pd-input {
    min-height: 112px;
}

.pd-stock-purchase-shell .pd-input:focus,
.pd-stock-purchase-shell .pd-select:focus {
    border-color: #9bb7ff;
    box-shadow: 0 0 0 4px rgba(72, 116, 255, 0.12);
    background: #ffffff;
}

.pd-stock-purchase-shell .pd-stock-purchase-mode-card {
    border: 1px solid #e3e8f0;
    border-radius: 12px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    padding: 16px 18px;
}

.pd-stock-purchase-shell .pd-stock-purchase-mode-card.is-active {
    border-color: #b8cdfd;
    background: #eef4ff;
    box-shadow: 0 12px 24px rgba(47, 111, 237, 0.08);
}

.pd-stock-purchase-shell .pd-stock-purchase-mode-card h4 {
    margin: 0 0 10px;
    font-size: 17px;
    line-height: 1.25;
    font-weight: 700;
    color: #20304c;
}

.pd-stock-purchase-shell .pd-stock-purchase-table-wrap {
    border: 1px solid #e5ecf5;
    border-radius: 12px;
    overflow: hidden;
    background: #ffffff;
}

.pd-stock-purchase-shell .pd-table thead th,
.pd-stock-purchase-shell .pd-stock-purchase-table thead th {
    background: #f6f9fc;
    color: #6b7a96;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    border-bottom: 1px solid #e3e8f0;
    padding-top: 14px;
    padding-bottom: 14px;
}

.pd-stock-purchase-shell .pd-table tbody td,
.pd-stock-purchase-shell .pd-stock-purchase-table tbody td {
    border-top: 1px solid #eef3f8;
    padding-top: 16px;
    padding-bottom: 16px;
    font-size: 14px;
    color: #24324a;
}

.pd-stock-purchase-shell .pd-stock-purchase-search-results {
    border: 1px solid #e3e8f0;
    border-radius: 12px;
    background: #ffffff;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
    padding: 6px;
}

.pd-stock-purchase-shell .pd-stock-purchase-result {
    border-radius: 10px;
    padding: 12px 14px;
}

.pd-stock-purchase-shell .pd-stock-purchase-result:hover,
.pd-stock-purchase-shell .pd-stock-purchase-result.is-active {
    background: #eef5ff;
}

.pd-stock-purchase-shell .pd-stock-purchase-selected {
    margin-top: 10px;
    border: 1px solid #e3e8f0;
    border-radius: 12px;
    background: #f8fbff;
    padding: 14px 16px;
    min-height: 94px;
}

.pd-stock-purchase-shell .pd-stock-purchase-selected-title {
    font-size: 15px;
    line-height: 1.45;
    font-weight: 700;
    color: #24324a;
}

.pd-stock-purchase-shell .pd-stock-purchase-stock-main {
    font-size: 13px;
    line-height: 1.55;
    color: #4c5f80;
}

.pd-stock-purchase-shell .pd-stock-purchase-price-field {
    gap: 10px;
}

.pd-stock-purchase-shell .pd-stock-purchase-currency-chip {
    min-width: 44px;
    padding: 6px 10px;
    border-radius: 999px;
    background: #eef4ff;
    color: #3764d8;
    font-size: 12px;
    font-weight: 700;
}

.pd-stock-purchase-shell .pd-stock-purchase-toolbar {
    gap: 14px;
    margin-top: 18px;
}

.pd-stock-purchase-shell .pd-stock-purchase-bulk-discount,
.pd-stock-purchase-shell .pd-stock-purchase-summary {
    border: 1px solid #e3e8f0;
    border-radius: 12px;
    background: #ffffff;
    padding: 16px 18px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.03);
}

.pd-stock-purchase-shell .pd-stock-purchase-summary strong {
    color: #1f2f50;
}

.pd-stock-purchase-shell .pd-btn {
    min-height: 40px;
    border-radius: 10px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    font-weight: 700;
}

.pd-stock-purchase-shell .pd-btn-primary {
    background: #2f6fed;
    border-color: #2f6fed;
    box-shadow: 0 10px 18px rgba(47, 111, 237, 0.18);
}

.pd-stock-purchase-shell .pd-btn-light {
    background: #ffffff;
    border-color: #d7e1ee;
    color: #324562;
}

.pd-stock-purchase-shell .pd-stock-purchase-bottom-actions {
    gap: 12px;
    margin-top: 18px;
    padding-top: 18px;
    border-top: 1px solid #edf2f7;
}

@media (max-width: 1200px) {
    .pd-stock-purchase-shell .pd-hero-title,
    .pd-stock-purchase-shell .pd-section-title {
        font-size: 28px;
    }
}

@media (max-width: 992px) {
    .pd-stock-purchase-shell .pd-card-body,
    .pd-stock-purchase-shell .pd-section-body,
    .pd-stock-purchase-shell .pd-section-header {
        padding-left: 18px;
        padding-right: 18px;
    }
}
</style>
@endpush
@push('scripts')
<script>
(() => {
    const searchEndpoint = @json($searchEndpoint);
    const initialCandidateMap = new Map(Object.entries(@json($initialCandidates)));
    const rowsBody = document.getElementById('stockPurchaseRows');
    const addRowButton = document.getElementById('addStockPurchaseRow');
    const entryTypeField = document.querySelector('[data-entry-type]');
    const supplierFilter = document.querySelector('[data-supplier-filter]');
    const submitLabel = document.getElementById('stockPurchaseSubmitLabel');
    const totalQuantity = document.getElementById('stockPurchaseTotalQuantity');
    const grandTotal = document.getElementById('stockPurchaseGrandTotal');
    const bulkDiscountButton = document.getElementById('applyBulkDiscount');
    const bulkDiscountField = document.getElementById('bulkDiscountRate');
    const searchTimers = new WeakMap();
    const searchAborters = new WeakMap();

    const formatNumber = (value, fraction = 2) => new Intl.NumberFormat('tr-TR', { minimumFractionDigits: fraction, maximumFractionDigits: fraction }).format(Number(value || 0));
    const currencyLabel = (currency) => (currency === 'TRY' ? 'TL' : (currency || 'TL'));
    const isPurchaseMode = () => entryTypeField.value === 'supplier_purchase';

    function buildSearchText(candidate) {
        return [candidate.product_code, candidate.product_name].filter(Boolean).join(' - ');
    }

    function buildSelectedMeta(candidate) {
        return [`SKU: ${candidate.product_code || ''}`, candidate.supplier_name || ''].filter(Boolean).join(' · ');
    }

    function buildSelectedStockMeta(candidate) {
        return [
            `Local stok: ${formatNumber(candidate.local_stock_quantity || 0, 0)}`,
            `Tedarikçi stok: ${formatNumber(candidate.supplier_stock_quantity || 0, 0)}`,
            `Liste: ${formatNumber(candidate.list_price || 0)} ${currencyLabel(candidate.currency)}`,
        ].join(' · ');
    }

    function setSelectedPanel(row, candidate) {
        row.querySelector('[data-selected-title]').textContent = candidate?.product_name || '';
        row.querySelector('[data-selected-meta]').textContent = candidate ? buildSelectedMeta(candidate) : '';
        row.querySelector('[data-selected-stock]').textContent = candidate ? buildSelectedStockMeta(candidate) : '';
        row.querySelector('[data-local-stock]').textContent = formatNumber(candidate?.local_stock_quantity || 0, 0);
        row.querySelector('[data-supplier-stock]').textContent = formatNumber(candidate?.supplier_stock_quantity || 0, 0);
    }

    function clearSelection(row, keepSearchText = true) {
        if (!keepSearchText) {
            row.querySelector('[data-product-search]').value = '';
        }
        row.querySelector('[data-selection-key]').value = '';
        row.querySelector('[data-unit-price]').dataset.manual = '0';
        setSelectedPanel(row, null);
    }

    function applySelectedCandidate(row, candidate) {
        if (!candidate) {
            clearSelection(row, true);
            recalculateRow(row);
            return;
        }

        initialCandidateMap.set(candidate.selection_key, candidate);
        row.querySelector('[data-product-search]').value = buildSearchText(candidate);
        row.querySelector('[data-selection-key]').value = candidate.selection_key;
        row.querySelector('[data-list-price]').value = Number(candidate.list_price || 0).toFixed(4);
        row.querySelector('[data-currency-input]').value = candidate.currency || 'TRY';
        row.querySelector('[data-exchange-rate]').value = Number(candidate.exchange_rate || 1).toFixed(6);
        row.querySelector('[data-exchange-rate-date]').value = candidate.exchange_rate_date || '';
        row.querySelector('[data-unit-price]').dataset.manual = '0';
        setSelectedPanel(row, candidate);
        closeSearchResults(row);
        recalculateRow(row);
    }

    function recalculateRow(row) {
        const quantity = Number(row.querySelector('[data-quantity]').value || 0);
        const listPrice = Number(row.querySelector('[data-list-price]').value || 0);
        const discountRate = Number(row.querySelector('[data-discount-rate]').value || 0);
        const unitInput = row.querySelector('[data-unit-price]');
        const currency = row.querySelector('[data-currency-input]').value || 'TRY';
        const exchangeRate = Number(row.querySelector('[data-exchange-rate]').value || 1);
        const manualOverride = (unitInput.dataset.manual || '0') === '1';
        const calculatedUnit = Number((listPrice * (1 - (discountRate / 100))).toFixed(4));

        if (!manualOverride) {
            unitInput.value = calculatedUnit > 0 ? calculatedUnit.toFixed(4) : '0.0000';
        }

        const finalUnit = Number(unitInput.value || 0);
        const total = Number((quantity * finalUnit).toFixed(4));
        row.querySelector('[data-line-total]').textContent = `${formatNumber(total)} ${currencyLabel(currency)}`;
        row.querySelector('[data-currency-label]').textContent = currencyLabel(currency);
        row.querySelector('[data-rate-note]').textContent = currency !== 'TRY'
            ? `1 ${currency} = ${formatNumber(exchangeRate, 4)} TL`
            : '';

        updatePageState();
    }

    function updatePageState() {
        const purchase = isPurchaseMode();
        document.querySelectorAll('[data-finance-col], [data-finance-only]').forEach((element) => element.classList.toggle('is-hidden', !purchase));
        document.querySelectorAll('[data-purchase-only]').forEach((element) => element.classList.toggle('is-hidden', !purchase));
        document.querySelectorAll('[data-supplier-field]').forEach((element) => element.classList.toggle('is-hidden', !purchase));
        document.querySelectorAll('[data-mode-card]').forEach((card) => card.classList.toggle('is-active', card.dataset.modeCard === entryTypeField.value));
        submitLabel.textContent = purchase ? 'Kaydet ve Stoğa Ekle' : 'Stoğa Ekle';

        let quantityTotal = 0;
        let amountTotal = 0;
        rowsBody.querySelectorAll('[data-row]').forEach((row) => {
            if (!row.querySelector('input[type="checkbox"]').checked) {
                return;
            }

            quantityTotal += Number(row.querySelector('[data-quantity]').value || 0);
            if (purchase) {
                amountTotal += Number(row.querySelector('[data-quantity]').value || 0) * Number(row.querySelector('[data-unit-price]').value || 0);
            }
        });

        totalQuantity.textContent = formatNumber(quantityTotal, 0);
        grandTotal.textContent = `${formatNumber(amountTotal)} TL`;
    }

    function closeSearchResults(row) {
        const results = row.querySelector('[data-search-results]');
        results.classList.add('is-hidden');
        results.innerHTML = '';
        row.dataset.activeResultIndex = '-1';
    }

    function renderSearchResults(row, items) {
        const results = row.querySelector('[data-search-results]');
        if (!items.length) {
            results.innerHTML = '<div class="pd-stock-purchase-result"><div class="pd-stock-purchase-result-title">Sonuç bulunamadı.</div></div>';
            results.classList.remove('is-hidden');
            row.dataset.activeResultIndex = '-1';
            return;
        }

        results.innerHTML = items.map((item, index) => `
            <button type="button" class="pd-stock-purchase-result${index === 0 ? ' is-active' : ''}" data-search-result data-index="${index}">
                <div class="pd-stock-purchase-result-title">${escapeHtml(item.product_name || '')}</div>
                <div class="pd-stock-purchase-result-meta">${escapeHtml(item.meta_primary || '')}</div>
                <div class="pd-stock-purchase-result-stock">${escapeHtml(item.meta_secondary || '')}</div>
            </button>
        `).join('');
        results.classList.remove('is-hidden');
        row.dataset.activeResultIndex = items.length ? '0' : '-1';
        row._searchItems = items;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    async function fetchSearchResults(row, query) {
        const params = new URLSearchParams({
            q: query,
            entry_type: entryTypeField.value,
            supplier_id: supplierFilter.value || '',
        });
        const previousAborter = searchAborters.get(row);
        if (previousAborter) {
            previousAborter.abort();
        }

        const controller = new AbortController();
        searchAborters.set(row, controller);

        try {
            const response = await fetch(`${searchEndpoint}?${params.toString()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error(`search-${response.status}`);
            }
            const payload = await response.json();
            renderSearchResults(row, Array.isArray(payload) ? payload : []);
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            renderSearchResults(row, []);
        }
    }

    function scheduleSearch(row, query) {
        const existingTimer = searchTimers.get(row);
        if (existingTimer) {
            window.clearTimeout(existingTimer);
        }

        if (query.trim().length < 2) {
            closeSearchResults(row);
            return;
        }

        const timer = window.setTimeout(() => fetchSearchResults(row, query.trim()), 300);
        searchTimers.set(row, timer);
    }

    function moveActiveResult(row, direction) {
        const results = Array.from(row.querySelectorAll('[data-search-result]'));
        if (!results.length) {
            return;
        }

        let index = Number(row.dataset.activeResultIndex || 0);
        index = Math.max(0, Math.min(results.length - 1, index + direction));
        row.dataset.activeResultIndex = String(index);
        results.forEach((result, resultIndex) => result.classList.toggle('is-active', resultIndex === index));
        results[index].scrollIntoView({ block: 'nearest' });
    }

    function selectActiveResult(row) {
        const items = row._searchItems || [];
        const index = Number(row.dataset.activeResultIndex || -1);
        if (index < 0 || !items[index]) {
            return;
        }
        applySelectedCandidate(row, items[index]);
    }

    function buildRowMarkup(index) {
        return `
            <tr class="pd-stock-purchase-row" data-row>
                <td><label class="pd-stock-purchase-inline-check"><input type="checkbox" name="rows[${index}][include]" value="1" checked></label></td>
                <td class="pd-stock-purchase-product-cell">
                    <div class="pd-stock-purchase-search-wrap">
                        <input type="text" name="rows[${index}][search_text]" value="" class="pd-input" data-product-search placeholder="SKU, ürün adı, varyant, renk, tedarikçi..." autocomplete="off">
                        <input type="hidden" name="rows[${index}][selection_key]" value="" data-selection-key>
                        <div class="pd-stock-purchase-search-results is-hidden" data-search-results></div>
                    </div>
                    <div class="pd-stock-purchase-selected" data-selected-panel>
                        <div class="pd-stock-purchase-selected-title" data-selected-title></div>
                        <div class="pd-stock-purchase-selected-meta" data-selected-meta></div>
                        <div class="pd-stock-purchase-selected-stock" data-selected-stock></div>
                    </div>
                </td>
                <td><div class="pd-stock-purchase-stock-main">Local: <strong data-local-stock>0</strong></div><div class="pd-stock-purchase-stock-note">Tedarikçi: <span data-supplier-stock>0</span></div></td>
                <td><input type="number" min="0.0001" step="0.0001" name="rows[${index}][quantity]" value="1" class="pd-input" data-quantity></td>
                <td><span class="pd-badge pd-badge-light">Adet</span></td>
                <td data-finance-col><div class="pd-stock-purchase-price-field"><input type="number" min="0" step="0.0001" name="rows[${index}][list_price]" value="0" class="pd-input" data-list-price><span class="pd-stock-purchase-currency-chip" data-currency-label>TL</span></div></td>
                <td data-finance-col><input type="number" min="0" max="100" step="0.01" name="rows[${index}][discount_rate]" value="0" class="pd-input" data-discount-rate></td>
                <td data-finance-col><input type="number" min="0" step="0.0001" name="rows[${index}][unit_purchase_price]" value="0.0000" class="pd-input" data-unit-price data-manual="0"><input type="hidden" name="rows[${index}][currency]" value="TRY" data-currency-input><input type="hidden" name="rows[${index}][exchange_rate]" value="1" data-exchange-rate><input type="hidden" name="rows[${index}][exchange_rate_date]" value="{{ now()->toDateString() }}" data-exchange-rate-date></td>
                <td data-finance-col><strong data-line-total>0,00 TL</strong><div class="pd-stock-purchase-stock-note" data-rate-note></div></td>
                <td><input type="text" name="rows[${index}][line_note]" value="" class="pd-input" placeholder="Satır notu"></td>
                <td><button type="button" class="pd-btn pd-btn-sm pd-btn-light" data-remove-row>Sil</button></td>
            </tr>
        `;
    }

    function addRow(prefill = null) {
        const index = rowsBody.querySelectorAll('[data-row]').length;
        const wrapper = document.createElement('tbody');
        wrapper.innerHTML = buildRowMarkup(index).trim();
        const row = wrapper.firstElementChild;
        rowsBody.appendChild(row);
        if (prefill) {
            applySelectedCandidate(row, prefill);
        } else {
            recalculateRow(row);
        }
        updatePageState();
    }

    rowsBody.addEventListener('change', (event) => {
        const row = event.target.closest('[data-row]');
        if (!row) return;
        if (event.target.matches('[data-quantity], [data-list-price], [data-discount-rate], input[type="checkbox"]')) {
            recalculateRow(row);
        }
    });

    rowsBody.addEventListener('input', (event) => {
        const row = event.target.closest('[data-row]');
        if (!row) return;
        if (event.target.matches('[data-product-search]')) {
            clearSelection(row, true);
            scheduleSearch(row, event.target.value);
            return;
        }
        if (event.target.matches('[data-unit-price]')) {
            event.target.dataset.manual = '1';
        }
        if (event.target.matches('[data-list-price], [data-discount-rate], [data-unit-price], [data-quantity]')) {
            recalculateRow(row);
        }
    });

    rowsBody.addEventListener('keydown', (event) => {
        const row = event.target.closest('[data-row]');
        if (!row || !event.target.matches('[data-product-search]')) return;
        if (event.key === 'ArrowDown') { event.preventDefault(); moveActiveResult(row, 1); }
        if (event.key === 'ArrowUp') { event.preventDefault(); moveActiveResult(row, -1); }
        if (event.key === 'Enter') {
            const results = row.querySelector('[data-search-results]');
            if (!results.classList.contains('is-hidden')) { event.preventDefault(); selectActiveResult(row); }
        }
        if (event.key === 'Escape') { closeSearchResults(row); }
    });

    rowsBody.addEventListener('click', (event) => {
        if (event.target.matches('[data-remove-row]')) {
            const row = event.target.closest('[data-row]');
            if (!row) return;
            if (rowsBody.querySelectorAll('[data-row]').length === 1) {
                clearSelection(row, false);
                row.querySelector('[data-quantity]').value = 1;
                row.querySelector('[data-list-price]').value = 0;
                row.querySelector('[data-discount-rate]').value = 0;
                row.querySelector('[data-unit-price]').value = '0.0000';
                row.querySelector('[data-unit-price]').dataset.manual = '0';
                recalculateRow(row);
                return;
            }
            row.remove();
            updatePageState();
            return;
        }
        const resultButton = event.target.closest('[data-search-result]');
        if (resultButton) {
            const row = event.target.closest('[data-row]');
            const index = Number(resultButton.dataset.index || -1);
            if (row && index >= 0 && row._searchItems?.[index]) { applySelectedCandidate(row, row._searchItems[index]); }
        }
    });

    document.addEventListener('click', (event) => {
        rowsBody.querySelectorAll('[data-row]').forEach((row) => { if (!row.contains(event.target)) { closeSearchResults(row); } });
    });

    addRowButton.addEventListener('click', () => addRow());
    bulkDiscountButton?.addEventListener('click', () => {
        const value = bulkDiscountField.value || 0;
        rowsBody.querySelectorAll('[data-row]').forEach((row) => {
            if (!row.querySelector('input[type="checkbox"]').checked) { return; }
            row.querySelector('[data-discount-rate]').value = value;
            if ((row.querySelector('[data-unit-price]').dataset.manual || '0') !== '1') { row.querySelector('[data-unit-price]').dataset.manual = '0'; }
            recalculateRow(row);
        });
    });

    entryTypeField.addEventListener('change', () => {
        updatePageState();
        rowsBody.querySelectorAll('[data-row]').forEach((row) => recalculateRow(row));
    });

    supplierFilter?.addEventListener('change', () => {
        rowsBody.querySelectorAll('[data-row]').forEach((row) => closeSearchResults(row));
        updatePageState();
    });

    rowsBody.querySelectorAll('[data-row]').forEach((row) => {
        const selectionKey = row.querySelector('[data-selection-key]').value;
        const candidate = selectionKey ? initialCandidateMap.get(selectionKey) : null;
        if (candidate) { applySelectedCandidate(row, candidate); } else { recalculateRow(row); }
    });

    updatePageState();
})();
</script>
@endpush
