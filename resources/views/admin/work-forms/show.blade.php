@extends('layouts.prodelya-admin')

@section('title', 'İş Formu')
@section('page_topbar_hidden', '1')
@section('hide_side_summary', '1')

@section('content')
@php
    $renderTimestamp = $renderedAt->format('d.m.Y H:i');
    $updatedTimestamp = optional($workForm->updated_at)->format('d.m.Y H:i') ?: '-';
    $createdTimestamp = optional($workForm->created_at)->format('d.m.Y H:i') ?: '-';
    $productImage = data_get($productSnapshot, 'image_url');
    $graphicStatus = data_get($graphicSnapshot, 'status', 'bekliyor');
    $approvalStatus = data_get($graphicSnapshot, 'approval_status', 'bekliyor');
    $productionStatus = data_get($productionSnapshot, 'status', 'bekliyor');
    $qualityControlStatus = data_get($productionSnapshot, 'qc_status', 'bekliyor');
    $deliveryStatus = data_get($deliverySnapshot, 'status', 'bekliyor');
@endphp

<style>
    @page {
        size: A4;
        margin: 10mm 11mm;
    }

    :root {
        --wf-bg: #f3f4f6;
        --wf-paper: #ffffff;
        --wf-line: #d7dce3;
        --wf-soft-line: #e8ebef;
        --wf-band: #f1f3f5;
        --wf-text: #1f2937;
        --wf-title: #111827;
        --wf-muted: #6b7280;
        --wf-blue: #2563eb;
        --wf-green: #15803d;
        --wf-amber: #b45309;
        --wf-purple: #7c3aed;
        --wf-gray: #6b7280;
    }

    .wf-shell {
        max-width: 920px;
        margin: 0 auto;
        padding-bottom: 24px;
    }

    .wf-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 12px;
    }

    .wf-btn,
    .wf-btn:visited {
        border: 1px solid var(--wf-line);
        background: #fff;
        color: var(--wf-text);
        border-radius: 8px;
        padding: 7px 11px;
        font-size: 12px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .wf-btn.is-disabled {
        color: var(--wf-muted);
        background: #f9fafb;
        cursor: not-allowed;
        opacity: 0.85;
    }

    .wf-paper {
        background: var(--wf-paper);
        border: 1px solid var(--wf-line);
        border-radius: 14px;
        box-shadow: 0 10px 26px rgba(15, 23, 42, 0.08);
        overflow: hidden;
    }

    .wf-header {
        display: grid;
        grid-template-columns: 1fr 1.1fr 1fr;
        gap: 12px;
        padding: 12px 14px 10px;
        border-bottom: 1px solid var(--wf-soft-line);
    }

    .wf-tenant {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .wf-logo-box {
        width: 58px;
        height: 40px;
        border: 1px dashed #c7ccd4;
        border-radius: 8px;
        background: #fafafa;
        color: var(--wf-muted);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 9px;
        flex-shrink: 0;
    }

    .wf-tenant-name {
        font-size: 16px;
        font-weight: 600;
        color: var(--wf-title);
        margin-bottom: 2px;
    }

    .wf-tenant-note,
    .wf-center-sub,
    .wf-meta-note,
    .wf-footnote,
    .wf-placeholder-note {
        color: var(--wf-muted);
        font-size: 9.5px;
    }

    .wf-center {
        text-align: center;
    }

    .wf-title {
        margin: 0 0 2px;
        font-size: 18px;
        font-weight: 600;
        letter-spacing: 0.4px;
        color: var(--wf-title);
    }

    .wf-price-note {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 999px;
        border: 1px solid #d7dce3;
        background: #fafbfc;
        color: #4b5563;
        font-size: 9.5px;
        margin-top: 4px;
    }

    .wf-right {
        display: grid;
        grid-template-columns: 64px 1fr;
        gap: 10px;
        align-items: start;
    }

    .wf-qr {
        width: 64px;
        height: 64px;
        border: 1px solid #ced4dd;
        border-radius: 8px;
        background: #fff;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 4px;
    }

    .wf-qr svg {
        width: 100%;
        height: 100%;
        display: block;
    }

    .wf-meta-table {
        width: 100%;
        border-collapse: collapse;
    }

    .wf-meta-table td {
        padding: 2px 0;
        border: 0;
        font-size: 10px;
    }

    .wf-meta-label {
        color: var(--wf-muted);
        width: 84px;
    }

    .wf-meta-value {
        font-weight: 600;
        color: var(--wf-title);
        word-break: break-word;
    }

    .wf-section {
        padding: 10px 14px;
        border-bottom: 1px solid var(--wf-soft-line);
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .wf-section:last-of-type {
        border-bottom: 0;
    }

    .wf-section-title {
        background: var(--wf-band);
        border: 1px solid var(--wf-soft-line);
        border-radius: 7px;
        padding: 5px 8px;
        margin-bottom: 8px;
        font-size: 10.5px;
        font-weight: 600;
        color: var(--wf-title);
    }

    .wf-two-col,
    .wf-ops-two-col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .wf-box {
        border: 1px solid var(--wf-soft-line);
        border-radius: 10px;
        background: #fcfcfd;
        padding: 8px;
        min-width: 0;
    }

    .wf-mini-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px 10px;
    }

    .wf-field-label {
        display: block;
        margin-bottom: 2px;
        color: var(--wf-muted);
        font-size: 9.5px;
    }

    .wf-field-value {
        border: 1px solid var(--wf-soft-line);
        border-radius: 7px;
        padding: 5px 7px;
        background: #fff;
        min-height: 29px;
        font-size: 10px;
        word-break: break-word;
    }

    .wf-product-block {
        display: grid;
        grid-template-columns: 84px 1fr;
        gap: 10px;
        align-items: start;
    }

    .wf-product-image,
    .wf-product-placeholder {
        width: 84px;
        height: 84px;
        border: 1px solid var(--wf-soft-line);
        border-radius: 10px;
        background: #fafafa;
        overflow: hidden;
    }

    .wf-product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .wf-product-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: var(--wf-muted);
        font-size: 9px;
        padding: 8px;
        background: repeating-linear-gradient(-45deg, #fafafb, #fafafb 10px, #f3f4f6 10px, #f3f4f6 20px);
    }

    .wf-product-name {
        font-size: 13px;
        font-weight: 600;
        color: var(--wf-title);
        margin: 0 0 6px;
    }

    .wf-chip-row {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 6px;
    }

    .wf-badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 7px;
        border-radius: 999px;
        font-size: 9.5px;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .wf-badge-blue { background: #e8f0ff; color: var(--wf-blue); border-color: #cfe0ff; }
    .wf-badge-green { background: #eaf8ef; color: var(--wf-green); border-color: #cdecd6; }
    .wf-badge-amber { background: #fff4df; color: var(--wf-amber); border-color: #f6dfb3; }
    .wf-badge-purple { background: #f3ebff; color: var(--wf-purple); border-color: #e0d0ff; }
    .wf-badge-gray { background: #f3f4f6; color: var(--wf-gray); border-color: #e2e5ea; }

    .wf-table-wrap {
        border: 1px solid var(--wf-soft-line);
        border-radius: 9px;
        overflow: hidden;
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .wf-table {
        width: 100%;
        border-collapse: collapse;
    }

    .wf-table th,
    .wf-table td {
        padding: 6px 7px;
        border-bottom: 1px solid var(--wf-soft-line);
        text-align: left;
        vertical-align: top;
        font-size: 9.5px;
    }

    .wf-table thead th {
        background: #f8fafc;
        color: #374151;
        font-weight: 600;
    }

    .wf-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .wf-graphic {
        display: grid;
        grid-template-columns: 120px 1fr;
        gap: 10px;
    }

    .wf-visual-placeholder,
    .wf-visual-card {
        min-height: 116px;
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 10px;
    }

    .wf-visual-placeholder {
        border: 1px dashed #cad1db;
        background: repeating-linear-gradient(-45deg, #fafafb, #fafafb 10px, #f3f4f6 10px, #f3f4f6 20px);
    }

    .wf-visual-card {
        border: 1px solid var(--wf-soft-line);
        background: #fff;
        flex-direction: column;
        gap: 6px;
    }

    .wf-photo-box,
    .wf-doc-box {
        min-height: 64px;
        border: 1px dashed #cad1db;
        border-radius: 8px;
        background: #fafafa;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 8px;
        color: var(--wf-muted);
        font-size: 9.5px;
    }

    .wf-thumb-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(96px, 1fr));
        gap: 8px;
        margin-top: 8px;
    }

    .wf-thumb {
        border: 1px solid var(--wf-soft-line);
        border-radius: 8px;
        background: #fff;
        min-height: 72px;
        padding: 8px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 4px;
        font-size: 9px;
    }

    .wf-thumb-type {
        font-weight: 600;
        color: var(--wf-title);
    }

    .wf-thumb-meta {
        color: var(--wf-muted);
        word-break: break-word;
    }

    .wf-upload-form {
        display: grid;
        gap: 8px;
        margin-top: 10px;
        padding: 10px;
        border: 1px dashed #cad1db;
        border-radius: 9px;
        background: #f9fafb;
    }

    .wf-upload-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .wf-upload-full {
        grid-column: 1 / -1;
    }

    .wf-upload-label {
        display: block;
        margin-bottom: 4px;
        color: var(--wf-muted);
        font-size: 9.5px;
    }

    .wf-upload-input,
    .wf-upload-select {
        width: 100%;
        border: 1px solid var(--wf-soft-line);
        border-radius: 8px;
        padding: 8px 9px;
        font-size: 10px;
        font-family: Arial, Helvetica, sans-serif;
        background: #fff;
    }

    .wf-thumb img {
        width: 100%;
        height: 74px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid var(--wf-soft-line);
    }

    .wf-attachment-actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        align-items: center;
        margin-top: 2px;
    }

    .wf-link {
        color: var(--wf-blue);
        text-decoration: none;
        font-size: 9.5px;
        font-weight: 600;
    }

    .wf-flash {
        margin-bottom: 12px;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 12px;
        border: 1px solid var(--wf-soft-line);
    }

    .wf-flash-success {
        background: #ecfdf3;
        color: #166534;
        border-color: #bbf7d0;
    }

    .wf-flash-error {
        background: #fef2f2;
        color: #991b1b;
        border-color: #fecaca;
    }

    .wf-upload-only {
        display: block;
    }

    .wf-summary-band {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 8px;
    }

    .wf-summary-pill {
        border: 1px solid var(--wf-soft-line);
        border-radius: 8px;
        background: #fff;
        padding: 7px 8px;
    }

    .wf-summary-pill strong {
        display: block;
        font-size: 10px;
        color: var(--wf-title);
        margin-bottom: 2px;
    }

    .wf-history-list {
        display: grid;
        gap: 6px;
    }

    .wf-history-row {
        display: grid;
        grid-template-columns: 110px 1fr auto;
        gap: 8px;
        align-items: center;
        border: 1px solid var(--wf-soft-line);
        border-radius: 8px;
        padding: 6px 8px;
        background: #fff;
        font-size: 9.5px;
    }

    .wf-footer {
        padding: 9px 14px 11px;
        font-size: 9.5px;
        color: var(--wf-muted);
        border-top: 1px solid var(--wf-soft-line);
    }

    @media (max-width: 900px) {
        .wf-header,
        .wf-two-col,
        .wf-ops-two-col,
        .wf-graphic {
            grid-template-columns: 1fr;
        }

        .wf-summary-band {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .wf-history-row {
            grid-template-columns: 1fr;
        }

        .wf-upload-grid {
            grid-template-columns: 1fr;
        }
    }

    @media print {
        body {
            background: #fff;
        }

        .wf-toolbar,
        .wf-upload-only,
        .pd-sidebar,
        .pd-mobilebar {
            display: none !important;
        }

        .pd-layout,
        .pd-main,
        .pd-content {
            display: block !important;
            width: auto !important;
            max-width: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .wf-shell {
            max-width: none;
            padding: 0;
        }

        .wf-paper {
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }
    }

    .pd-ui-v1-work-form-production {
        display: grid;
        gap: 10px;
    }

    .pd-work-form-production__print-row {
        border: 1px solid var(--wf-line);
        border-radius: 8px;
        padding: 10px;
        page-break-inside: avoid;
        background: #fff;
    }

    .pd-work-form-production__print-key {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
        font-weight: 700;
        color: var(--wf-title);
    }

    .pd-work-form-production__route,
    .pd-work-form-production__metrics,
    .pd-work-form-production__process,
    .pd-work-form-production__media {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 8px;
    }

    .pd-work-form-production__summary {
        color: var(--wf-muted);
        font-size: 12px;
        margin-top: 4px;
    }

    .pd-work-form-production__photos {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 8px;
        width: 100%;
    }
</style>

<div class="wf-shell">
    @if(session('success'))
        <div class="wf-flash wf-flash-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="wf-flash wf-flash-error">{{ $errors->first() }}</div>
    @endif

    <div class="wf-toolbar">
        <a href="{{ $backUrl }}" class="wf-btn">Listeye Dön</a>
        <button type="button" class="wf-btn" onclick="window.print()">Yazdır</button>
        <a href="{{ route('public.work-forms.track', $workForm->public_tracking_token) }}" class="wf-btn" target="_blank" rel="noopener">Müşteri Takip Linki</a>
        <a href="{{ route('admin.work-forms.pdf', $workForm) }}" class="wf-btn" target="_blank" rel="noopener">PDF İndir</a>
    </div>

    <div class="wf-paper">
        <div class="wf-header">
            <div class="wf-tenant">
                <div class="wf-logo-box">Firma</div>
                <div>
                    <div class="wf-tenant-name">{{ $tenantName }}</div>
                    <div class="wf-tenant-note">Firma içi operasyon takip formu</div>
                </div>
            </div>

            <div class="wf-center">
                <h1 class="wf-title">İŞ FORMU</h1>
                <div class="wf-center-sub">Ürün bazlı operasyon renderı</div>
                <div class="wf-price-note">Fiyat bilgisi içermez</div>
            </div>

            <div class="wf-right">
                <div class="wf-qr" aria-label="İş Formu Takip QR Kodu">{!! $qrSvg !!}</div>
                <table class="wf-meta-table">
                    <tr>
                        <td class="wf-meta-label">İş Formu No</td>
                        <td class="wf-meta-value">{{ $workForm->work_form_number }}</td>
                    </tr>
                    <tr>
                        <td class="wf-meta-label">Sipariş No</td>
                        <td class="wf-meta-value">{{ data_get($orderSnapshot, 'document_number', '-') }}</td>
                    </tr>
                    <tr>
                        <td class="wf-meta-label">Kalem</td>
                        <td class="wf-meta-value">{{ str_pad((string) $workForm->item_sequence, 2, '0', STR_PAD_LEFT) }}</td>
                    </tr>
                    <tr>
                        <td class="wf-meta-label">Versiyon</td>
                        <td class="wf-meta-value">v{{ $workForm->version }}</td>
                    </tr>
                    <tr>
                        <td class="wf-meta-label">Public Link</td>
                        <td class="wf-meta-note">
                            <a href="{{ $trackingUrl }}" target="_blank" rel="noopener" class="wf-link">
                                Müşteri takip ekranını aç
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td class="wf-meta-label">QR Notu</td>
                        <td class="wf-meta-note">QR ile müşteri takip ekranı açılır.</td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="wf-section">
            <div class="wf-section-title">Çalışma Klasörü</div>
            <div class="wf-box">
                @if($systemWorkFolder)
                    <div class="wf-mini-grid">
                        <div>
                            <span class="wf-field-label">Durum</span>
                            <div class="wf-field-value">{{ $systemWorkFolder['status_label'] }}</div>
                        </div>
                        <div>
                            <span class="wf-field-label">Display Path</span>
                            <div class="wf-field-value">{{ $systemWorkFolder['display_path'] }}</div>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <span class="wf-field-label">Not</span>
                            <div class="wf-field-value">Orijinal grafik dosyalarını 01_GRAFIK klasörüne, baskıya hazır dosyaları 02_BASKIYA_HAZIR klasörüne koyun. 03_URETIM_TESLIMAT klasörü üretim ve teslimat ekleri içindir.</div>
                        </div>
                    </div>
                @else
                    <div class="wf-placeholder-note">Çalışma klasörü kaydı henüz görünmüyor.</div>
                @endif
            </div>
        </div>

        <div class="wf-section">
            <div class="wf-section-title">Tedarik</div>
            <div class="wf-box">
                <div class="wf-mini-grid">
                    <div>
                        <span class="wf-field-label">Tedarik Durumu</span>
                        <div class="wf-field-value">{{ data_get($procurementSnapshot, 'procurement_status_label', '-') }}</div>
                    </div>
                    <div>
                        <span class="wf-field-label">Kaynak Tipi</span>
                        <div class="wf-field-value">{{ data_get($procurementSnapshot, 'fulfillment_source_label', '-') }}</div>
                    </div>
                    <div>
                        <span class="wf-field-label">İstenen Miktar</span>
                        <div class="wf-field-value">{{ data_get($procurementSnapshot, 'requested_quantity', 0) }}</div>
                    </div>
                    <div>
                        <span class="wf-field-label">Local Ayrılan</span>
                        <div class="wf-field-value">{{ data_get($procurementSnapshot, 'local_allocated_quantity', 0) }}</div>
                    </div>
                    <div>
                        <span class="wf-field-label">Tedarikçiden İstenen</span>
                        <div class="wf-field-value">{{ data_get($procurementSnapshot, 'supplier_requested_quantity', 0) }}</div>
                    </div>
                    <div>
                        <span class="wf-field-label">Gelen Miktar</span>
                        <div class="wf-field-value">{{ data_get($procurementSnapshot, 'received_quantity', 0) }}</div>
                    </div>
                    <div>
                        <span class="wf-field-label">Kalan Miktar</span>
                        <div class="wf-field-value">{{ data_get($procurementSnapshot, 'remaining_quantity', 0) }}</div>
                    </div>
                    <div>
                        <span class="wf-field-label">Müşteriye Görünen Durum</span>
                        <div class="wf-field-value">{{ data_get($procurementSnapshot, 'public_status_label', '-') }}</div>
                    </div>
                    <div>
                        <span class="wf-field-label">Stok İşleme Modu</span>
                        <div class="wf-field-value">{{ data_get($procurementSnapshot, 'stock_handling_mode_label', '-') }}</div>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <span class="wf-field-label">Not</span>
                        <div class="wf-field-value">{{ data_get($procurementSnapshot, 'note', 'Tedarik notu bulunmuyor.') ?: 'Tedarik notu bulunmuyor.' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="wf-section">
            <div class="wf-two-col">
                <div>
                    <div class="wf-section-title">1. Sipariş ve Müşteri Bilgileri</div>
                    <div class="wf-box">
                        <div class="wf-mini-grid">
                            <div>
                                <span class="wf-field-label">Sipariş No</span>
                                <div class="wf-field-value">{{ data_get($orderSnapshot, 'document_number', '-') }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Kaynak Teklif No</span>
                                <div class="wf-field-value">{{ data_get($orderSnapshot, 'source_quote_number', '-') }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Sipariş Tarihi</span>
                                <div class="wf-field-value">{{ data_get($orderSnapshot, 'order_date', '-') }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Teslim Tarihi</span>
                                <div class="wf-field-value">-</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Müşteri</span>
                                <div class="wf-field-value">{{ data_get($customerSnapshot, 'company_name', '-') }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Yetkili / Telefon</span>
                                <div class="wf-field-value">{{ data_get($customerSnapshot, 'contact_name', '-') }} @if(filled(data_get($customerSnapshot, 'phone'))) / {{ data_get($customerSnapshot, 'phone') }} @endif</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Teslimat Tipi</span>
                                <div class="wf-field-value">{{ data_get($orderSnapshot, 'delivery_type', '-') }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Belge Türü</span>
                                <div class="wf-field-value">{{ data_get($orderSnapshot, 'invoice_status', '-') }}</div>
                            </div>
                        </div>
                        <div style="margin-top:8px;">
                            <span class="wf-field-label">Kısa Not</span>
                            <div class="wf-field-value">{{ data_get($orderSnapshot, 'notes', '-') ?: '-' }}</div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="wf-section-title">2. Ürün Bilgileri</div>
                    <div class="wf-box">
                        <div class="wf-product-block">
                            @if(filled($productImage))
                                <div class="wf-product-image">
                                    <img src="{{ $productImage }}" alt="Ürün görseli">
                                </div>
                            @else
                                <div class="wf-product-placeholder">Ürün görseli yok</div>
                            @endif

                            <div>
                                <div class="wf-product-name">{{ data_get($productSnapshot, 'product_name', '-') }}</div>
                                <div class="wf-mini-grid">
                                    <div>
                                        <span class="wf-field-label">Ürün Kodu / SKU</span>
                                        <div class="wf-field-value">{{ data_get($productSnapshot, 'product_code', '-') }}</div>
                                    </div>
                                    <div>
                                        <span class="wf-field-label">Varyant</span>
                                        <div class="wf-field-value">{{ data_get($productSnapshot, 'variant_name', '-') }}</div>
                                    </div>
                                    <div>
                                        <span class="wf-field-label">Miktar</span>
                                        <div class="wf-field-value">{{ rtrim(rtrim(number_format((float) data_get($productSnapshot, 'quantity', 0), 2, ',', '.'), '0'), ',') }}</div>
                                    </div>
                                    <div>
                                        <span class="wf-field-label">Birim</span>
                                        <div class="wf-field-value">{{ data_get($productSnapshot, 'unit', '-') }}</div>
                                    </div>
                                    <div>
                                        <span class="wf-field-label">Tedarikçi / Kaynak</span>
                                        <div class="wf-field-value">{{ data_get($productSnapshot, 'supplier_name', '-') }} @if(filled(data_get($productSnapshot, 'catalog_source'))) / {{ data_get($productSnapshot, 'catalog_source') }} @endif</div>
                                    </div>
                                    <div>
                                        <span class="wf-field-label">Uyarılar</span>
                                        <div class="wf-field-value">
                                            {{ collect(data_get($productSnapshot, 'warning_labels', []))->filter()->implode(', ') ?: '-' }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="wf-section">
            <div class="wf-section-title">3. Baskı Bilgileri</div>
            <div class="wf-table-wrap">
                <table class="wf-table">
                    <thead>
                    <tr>
                        <th>No</th>
                        <th>Baskı Türü</th>
                        <th>Seçenek</th>
                        <th>Üretim Tipi</th>
                        <th>Firma / Birim</th>
                        <th>Baskı Miktarı</th>
                        <th>Baskı Adı / Not</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($printSnapshot as $printLine)
                        <tr>
                            <td>{{ data_get($printLine, 'sequence', '-') }}</td>
                            <td>{{ data_get($printLine, 'print_type', '-') }}</td>
                            <td>{{ data_get($printLine, 'print_option', '-') }}</td>
                            <td>{{ data_get($printLine, 'production_type', '-') }}</td>
                            <td>{{ data_get($printLine, 'subcontractor_company_name', 'Kendi üretim') }}</td>
                            <td>{{ rtrim(rtrim(number_format((float) data_get($printLine, 'print_quantity', 0), 2, ',', '.'), '0'), ',') }}</td>
                            <td>
                                <strong>{{ data_get($printLine, 'note', '-') }}</strong>
                                @if(filled(data_get($printLine, 'production_note')))
                                    <div class="wf-meta-note">{{ data_get($printLine, 'production_note') }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="wf-meta-note">Bu kalem için baskı operasyonu bulunmuyor.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="wf-section">
            <div class="wf-section-title">4. Üretim / Fason Exact Satırlar</div>
            <div class="pd-ui-v1-work-form-production">
                @forelse($exactProductionRows as $productionRow)
                    <div class="pd-work-form-production__print-row">
                        <div class="pd-work-form-production__print-key">
                            <span>{{ data_get($productionRow, 'sequence', '-') }}</span>
                            <span>{{ data_get($productionRow, 'print_type', '-') }}</span>
                            <span>{{ data_get($productionRow, 'production_type_label', '-') }}</span>
                            <span class="wf-badge wf-badge-blue">{{ data_get($productionRow, 'production_status_label', '-') }}</span>
                        </div>
                        <div class="pd-work-form-production__summary">
                            {{ data_get($productionRow, 'print_option', '-') }} · {{ data_get($productionRow, 'planned_quantity', 0) }} {{ data_get($productSnapshot, 'unit', '') }}
                        </div>
                        <div class="pd-work-form-production__route">
                            @if(data_get($productionRow, 'is_outsourced'))
                                <span class="wf-badge wf-badge-gray">Fason Firma: {{ data_get($productionRow, 'production_company_name') ?: 'Atanmamış' }}</span>
                            @else
                                <span class="wf-badge wf-badge-gray">Operatör: {{ data_get($productionRow, 'operator_name') ?: 'Operatör kaydı yok' }}</span>
                                <span class="wf-badge wf-badge-gray">Birim: {{ data_get($productionRow, 'production_unit_name') ?: '-' }}</span>
                            @endif
                        </div>
                        <div class="pd-work-form-production__metrics">
                            <span class="wf-badge wf-badge-green">Planlanan {{ data_get($productionRow, 'planned_quantity', 0) }}</span>
                            <span class="wf-badge wf-badge-green">Tamamlanan {{ data_get($productionRow, 'completed_quantity', 0) }}</span>
                            <span class="wf-badge wf-badge-amber">Kalan {{ data_get($productionRow, 'remaining_quantity', 0) }}</span>
                            @if(data_get($productionRow, 'is_outsourced'))
                                @if(data_get($productionRow, 'prior_internal_completed_quantity') !== null)<span class="wf-badge wf-badge-gray">Önceden Tamamlanan {{ data_get($productionRow, 'prior_internal_completed_quantity') }}</span>@endif
                                @if(data_get($productionRow, 'sent_quantity') !== null)<span class="wf-badge wf-badge-blue">Fasona Gönderilen {{ data_get($productionRow, 'sent_quantity') }}</span>@endif
                                @if(data_get($productionRow, 'received_from_subcontractor_quantity') !== null)<span class="wf-badge wf-badge-green">Fasondan Gelen {{ data_get($productionRow, 'received_from_subcontractor_quantity') }}</span>@endif
                                @if(data_get($productionRow, 'remaining_from_subcontractor_quantity') !== null)<span class="wf-badge wf-badge-amber">Fasonda Kalan {{ data_get($productionRow, 'remaining_from_subcontractor_quantity') }}</span>@endif
                            @endif
                        </div>
                        @if(data_get($productionRow, 'legacy_subcontract_baseline_missing'))
                            <div class="pd-work-form-production__summary">Bu eski kayıtta fason başlangıç miktarı ayrı izlenemiyor.</div>
                        @endif
                        <div class="pd-work-form-production__process">
                            <span class="wf-badge wf-badge-gray">Grafik: {{ data_get($productionRow, 'graphic_status_label', '-') }}</span>
                            <span class="wf-badge wf-badge-gray">Tedarik: {{ data_get($productionRow, 'procurement_status_label', '-') }}</span>
                            <span class="wf-badge wf-badge-gray">QC: {{ data_get($productionRow, 'qc_status_label', 'Kalite Kontrol Gerekli Değil') }}</span>
                        </div>
                        <div class="pd-work-form-production__media">
                            <span class="wf-badge wf-badge-gray">Grafik Çalışması: {{ data_get($productionRow, 'final_graphic.file_name', 'Final Grafik Yok') }}</span>
                            <span class="wf-badge wf-badge-gray">Üretim Fotoğrafları: {{ data_get($productionRow, 'photo_count', 0) }}</span>
                            @if(!empty(data_get($productionRow, 'photos', [])))
                                <div class="pd-work-form-production__photos">
                                    @foreach(data_get($productionRow, 'photos', []) as $photo)
                                        <div class="wf-thumb">
                                            @if($photo['is_image'] && $photo['preview_url'])<img src="{{ $photo['preview_url'] }}" alt="{{ $photo['file_name'] }}">@endif
                                            <div class="wf-thumb-type">{{ $photo['file_name'] }}</div>
                                            <div class="wf-thumb-meta">{{ $photo['created_at'] ?: '-' }}</div>
                                            @if(filled($photo['note']))<div class="wf-thumb-meta">{{ $photo['note'] }}</div>@endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="wf-placeholder-note">Exact üretim satırı bulunmuyor.</div>
                @endforelse
            </div>
        </div>
        @php
            $workFormSetupItems = collect(data_get($productionSnapshot, 'setup_summary.items', []))->values();
        @endphp

        @if($workFormSetupItems->isNotEmpty())
            <div class="wf-section">
                <div class="wf-section-title">3A. Hazırlık / Ara Eleman</div>
                <div class="wf-table-wrap">
                    <table class="wf-table">
                        <thead>
                        <tr>
                            <th>Hazırlık Tipi</th>
                            <th>Durum</th>
                            <th>Atanan Firma</th>
                            <th>Tamamlandı</th>
                            <th>Not</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($workFormSetupItems as $setupItem)
                            <tr>
                                <td>{{ data_get($setupItem, 'setup_type_label', '-') }}</td>
                                <td>{{ data_get($setupItem, 'status_label', '-') }}</td>
                                <td>{{ data_get($setupItem, 'assigned_company_name', '-') ?: '-' }}</td>
                                <td>{{ filled(data_get($setupItem, 'completed_at')) ? \Illuminate\Support\Carbon::parse(data_get($setupItem, 'completed_at'))->format('d.m.Y H:i') : '-' }}</td>
                                <td>{{ data_get($setupItem, 'note', '-') ?: '-' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="wf-section">
            <div class="wf-section-title">4. Grafik</div>
            <div class="wf-box">
                <div class="wf-graphic">
                    @if($primaryGraphicAttachment)
                        <div class="wf-visual-card">
                            <div class="wf-thumb-type">Grafik görseli</div>
                            <div class="wf-thumb-meta">{{ $primaryGraphicAttachment['file_name'] }}</div>
                            <div class="wf-thumb-meta">{{ $primaryGraphicAttachment['visibility_label'] }}</div>
                        </div>
                    @else
                        <div class="wf-visual-placeholder">Grafik görseli henüz eklenmedi</div>
                    @endif

                    <div class="wf-mini-grid">
                        <div>
                            <span class="wf-field-label">Grafik Durumu</span>
                            <div class="wf-field-value">{{ data_get($graphicSnapshot, 'status_label', ucfirst(str_replace('_', ' ', $graphicStatus))) }}</div>
                        </div>
                        <div>
                            <span class="wf-field-label">Onay Türü</span>
                            <div class="wf-field-value">{{ data_get($graphicSnapshot, 'approval_type', '-') ?: '-' }}</div>
                        </div>
                        <div>
                            <span class="wf-field-label">Onay Durumu</span>
                            <div class="wf-field-value">{{ data_get($graphicSnapshot, 'approval_status_label', ucfirst(str_replace('_', ' ', $approvalStatus))) }}</div>
                        </div>
                        <div>
                            <span class="wf-field-label">Onaylayan</span>
                            <div class="wf-field-value">{{ data_get($graphicSnapshot, 'approved_by_name', '-') ?: '-' }}</div>
                        </div>
                        <div>
                            <span class="wf-field-label">Grafiker</span>
                            <div class="wf-field-value">{{ data_get($graphicSnapshot, 'designer_name', '-') ?: '-' }}</div>
                        </div>
                        <div>
                            <span class="wf-field-label">Görsel Dosyası</span>
                            <div class="wf-field-value">{{ $primaryGraphicAttachment['file_name'] ?? '-' }}</div>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <span class="wf-field-label">Onay / Revize Notu</span>
                            <div class="wf-field-value">{{ data_get($graphicSnapshot, 'revision_note', data_get($graphicSnapshot, 'short_note', '-')) ?: '-' }}</div>
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.work-forms.attachments.store', $workForm) }}" enctype="multipart/form-data" class="wf-upload-form wf-upload-only">
                    @csrf
                    <input type="hidden" name="attachment_type" value="graphic_visual">
                    <input type="hidden" name="section" value="graphic">
                    <div class="wf-upload-grid">
                        <div class="wf-upload-full">
                            <label class="wf-upload-label">Grafik Görseli Ekle</label>
                            <input type="file" name="file" class="wf-upload-input" accept="image/jpeg,image/png,image/webp,application/pdf">
                        </div>
                        <div>
                            <label class="wf-upload-label">Görünürlük</label>
                            <select name="visibility" class="wf-upload-select">
                                <option value="internal">İç Kayıt</option>
                                <option value="customer_visible">Müşteriye Açık</option>
                            </select>
                        </div>
                        <div>
                            <label class="wf-upload-label">Kısa Not</label>
                            <input type="text" name="note" class="wf-upload-input" placeholder="Grafik notu">
                        </div>
                        <div class="wf-upload-full">
                            <button type="submit" class="wf-btn">Grafik Görseli Ekle</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="wf-section">
            <div class="wf-ops-two-col">
                <div>
                    <div class="wf-section-title">5. Üretim</div>
                    <div class="wf-box">
                        <div class="wf-mini-grid">
                            <div>
                                <span class="wf-field-label">Üretim Durumu</span>
                                <div class="wf-field-value">{{ data_get($productionSnapshot, 'production_status_label', ucfirst(str_replace('_', ' ', $productionStatus))) }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Kalite Kontrol</span>
                                <div class="wf-field-value">{{ data_get($productionSnapshot, 'qc_status_label', ucfirst(str_replace('_', ' ', $qualityControlStatus))) }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Üretim Tipi</span>
                                <div class="wf-field-value">{{ data_get($productionSnapshot, 'production_type_label', collect($printSnapshot)->pluck('production_type')->filter()->unique()->implode(', ') ?: '-') }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Üretim / Fason Firma</span>
                                <div class="wf-field-value">{{ data_get($productionSnapshot, 'production_company_name', collect($printSnapshot)->pluck('subcontractor_company_name')->filter()->unique()->implode(', ') ?: 'Kendi üretim') }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Planlanan Miktar</span>
                                <div class="wf-field-value">{{ data_get($productionSnapshot, 'planned_quantity', '-') }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Tamamlanan / Kalan</span>
                                <div class="wf-field-value">{{ data_get($productionSnapshot, 'completed_quantity', 0) }} / {{ data_get($productionSnapshot, 'remaining_quantity', 0) }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Klişe / Kalıp Durumu</span>
                                <div class="wf-field-value">{{ data_get($productionSnapshot, 'cliche_status_label', '-') }}</div>
                            </div>
                            @if(data_get($productionSnapshot, 'setup_required'))
                                <div>
                                    <span class="wf-field-label">Hazırlık / Ara Eleman</span>
                                    <div class="wf-field-value">{{ data_get($productionSnapshot, 'setup_summary_label', 'Hazırlık planlandı') }}</div>
                                </div>
                            @endif
                            <div>
                                <span class="wf-field-label">Müşteriye Görünen Durum</span>
                                <div class="wf-field-value">{{ $publicProductionStatusLabel }}</div>
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <span class="wf-field-label">Üretim Notu</span>
                                <div class="wf-field-value">{{ data_get($productionSnapshot, 'note', '-') ?: '-' }}</div>
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <span class="wf-field-label">Sorun Notu</span>
                                <div class="wf-field-value">{{ data_get($productionSnapshot, 'issue_note', '-') ?: '-' }}</div>
                            </div>
                        </div>

                        <div style="margin-top:8px;">
                            <span class="wf-field-label">Üretim fotoğrafları</span>
                            @if($productionPhotos->isEmpty())
                                <div class="wf-photo-box">Üretim fotoğrafı henüz eklenmedi</div>
                            @else
                                <div class="wf-thumb-grid">
                                    @foreach($productionPhotos as $photo)
                                        <div class="wf-thumb">
                                            @if($photo['is_image'] && $photo['preview_url'])
                                                <img src="{{ $photo['preview_url'] }}" alt="{{ $photo['file_name'] }}">
                                            @endif
                                            <div class="wf-thumb-type">{{ $photo['file_name'] }}</div>
                                            <div class="wf-thumb-meta">{{ $photo['visibility_label'] }}</div>
                                            @if(filled($photo['note']))
                                                <div class="wf-thumb-meta">{{ $photo['note'] }}</div>
                                            @endif
                                            <div class="wf-attachment-actions">
                                                @if($photo['preview_url'])
                                                    <a href="{{ $photo['preview_url'] }}" target="_blank" rel="noopener" class="wf-link">Aç</a>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            <form method="POST" action="{{ route('admin.work-forms.attachments.store', $workForm) }}" enctype="multipart/form-data" class="wf-upload-form wf-upload-only">
                                @csrf
                                <input type="hidden" name="attachment_type" value="production_photo">
                                <input type="hidden" name="section" value="production">
                                <div class="wf-upload-grid">
                                    <div class="wf-upload-full">
                                        <label class="wf-upload-label">Üretim Fotoğrafı Ekle</label>
                                        <input type="file" name="file" class="wf-upload-input" accept="image/*" capture="environment">
                                    </div>
                                    <div>
                                        <label class="wf-upload-label">Görünürlük</label>
                                        <select name="visibility" class="wf-upload-select">
                                            <option value="internal">İç Kayıt</option>
                                            <option value="customer_visible">Müşteriye Açık</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="wf-upload-label">Kısa Not</label>
                                        <input type="text" name="note" class="wf-upload-input" placeholder="Üretim notu">
                                    </div>
                                    <div class="wf-upload-full">
                                        <button type="submit" class="wf-btn">Telefondan Fotoğraf Ekle</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="wf-section-title">6. Teslimat</div>
                    <div class="wf-box">
                        <div class="wf-mini-grid">
                            <div>
                                <span class="wf-field-label">Teslimat Durumu</span>
                                <div class="wf-field-value">{{ data_get($deliverySnapshot, 'delivery_status_label', ucfirst(str_replace('_', ' ', $deliveryStatus))) }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Teslimat Yöntemi</span>
                                <div class="wf-field-value">{{ data_get($deliverySnapshot, 'delivery_method_label', data_get($deliverySnapshot, 'delivery_type', '-')) }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Kargo / Kurye / Elden / Ambar</span>
                                <div class="wf-field-value">{{ data_get($deliverySnapshot, 'carrier_name', data_get($deliverySnapshot, 'carrier_type', '-')) ?: '-' }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Alıcı</span>
                                <div class="wf-field-value">{{ data_get($deliverySnapshot, 'recipient_name', '-') ?: '-' }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Teslim Edilen Miktar</span>
                                <div class="wf-field-value">{{ data_get($deliverySnapshot, 'delivered_quantity', 0) }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Kalan Miktar</span>
                                <div class="wf-field-value">{{ data_get($deliverySnapshot, 'remaining_quantity', 0) }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Teslim Tarihi</span>
                                <div class="wf-field-value">{{ data_get($deliverySnapshot, 'delivered_at', '-') ?: '-' }}</div>
                            </div>
                            <div>
                                <span class="wf-field-label">Finans Uyarısı</span>
                                <div class="wf-field-value">{{ data_get($deliverySnapshot, 'financial_warning_label', 'Finans uyarısı yok') }}</div>
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <span class="wf-field-label">Teslimat Notu</span>
                                <div class="wf-field-value">{{ data_get($deliverySnapshot, 'delivery_note', '-') ?: '-' }}</div>
                            </div>
                        </div>

                        <div style="margin-top:8px;">
                            <span class="wf-field-label">Teslimat fotoğrafı / belge</span>
                            @if($deliveryAttachments->isEmpty())
                                <div class="wf-doc-box">Teslimat belgesi/fotoğrafı henüz eklenmedi</div>
                            @else
                                <div class="wf-thumb-grid">
                                    @foreach($deliveryAttachments as $attachment)
                                        <div class="wf-thumb">
                                            @if($attachment['is_image'] && $attachment['preview_url'])
                                                <img src="{{ $attachment['preview_url'] }}" alt="{{ $attachment['file_name'] }}">
                                            @endif
                                            <div class="wf-thumb-type">{{ $attachment['file_name'] }}</div>
                                            <div class="wf-thumb-meta">{{ $attachment['attachment_type'] }}</div>
                                            <div class="wf-thumb-meta">{{ $attachment['visibility_label'] }}</div>
                                            @if(filled($attachment['note']))
                                                <div class="wf-thumb-meta">{{ $attachment['note'] }}</div>
                                            @endif
                                            <div class="wf-attachment-actions">
                                                @if($attachment['preview_url'])
                                                    <a href="{{ $attachment['preview_url'] }}" target="_blank" rel="noopener" class="wf-link">Aç / İndir</a>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            <form method="POST" action="{{ route('admin.work-forms.attachments.store', $workForm) }}" enctype="multipart/form-data" class="wf-upload-form wf-upload-only">
                                @csrf
                                <input type="hidden" name="section" value="delivery">
                                <div class="wf-upload-grid">
                                    <div>
                                        <label class="wf-upload-label">Dosya Tipi</label>
                                        <select name="attachment_type" class="wf-upload-select">
                                            <option value="delivery_photo">Teslimat Fotoğrafı</option>
                                            <option value="delivery_document">Teslimat Belgesi</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="wf-upload-label">Görünürlük</label>
                                        <select name="visibility" class="wf-upload-select">
                                            <option value="internal">İç Kayıt</option>
                                            <option value="customer_visible">Müşteriye Açık</option>
                                        </select>
                                    </div>
                                    <div class="wf-upload-full">
                                        <label class="wf-upload-label">Teslimat Fotoğrafı / Belgesi Ekle</label>
                                        <input type="file" name="file" class="wf-upload-input" accept="image/*,application/pdf" capture="environment">
                                    </div>
                                    <div class="wf-upload-full">
                                        <label class="wf-upload-label">Kısa Not</label>
                                        <input type="text" name="note" class="wf-upload-input" placeholder="Teslimat notu">
                                    </div>
                                    <div class="wf-upload-full">
                                        <button type="submit" class="wf-btn">Teslimat Fotoğrafı Ekle</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="wf-section">
            <div class="wf-section-title">Operasyon Durum Özeti</div>
            <div class="wf-summary-band">
                <div class="wf-summary-pill"><strong>Grafik</strong>{{ $operationSummary['graphic'] }}</div>
                <div class="wf-summary-pill"><strong>Tedarik</strong>{{ $operationSummary['procurement'] }}</div>
                <div class="wf-summary-pill"><strong>Üretim</strong>{{ $operationSummary['production'] }}</div>
                <div class="wf-summary-pill"><strong>Kalite Kontrol</strong>{{ $operationSummary['quality_control'] }}</div>
                <div class="wf-summary-pill"><strong>Teslimat</strong>{{ $operationSummary['delivery'] }}</div>
            </div>
        </div>

        <div class="wf-section">
            <div class="wf-section-title">7. Workflow Geçmişi</div>
            <div class="wf-history-list">
                @foreach($workflowHistory as $historyRow)
                    <div class="wf-history-row">
                        <div>{{ $historyRow['at'] ?: '-' }}</div>
                        <div>
                            <div>{{ $historyRow['label'] }}</div>
                            @if(filled($historyRow['note'] ?? null))
                                <div class="wf-meta-note">{{ $historyRow['note'] }}</div>
                            @endif
                        </div>
                        <div>
                            <span class="wf-badge {{ $historyRow['visibility'] === 'Müşteriye Açık' ? 'wf-badge-blue' : 'wf-badge-gray' }}">
                                {{ $historyRow['visibility'] }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="wf-footer">
            Bu form firma içi operasyon takip formudur. Fiyat bilgisi içermez. QR kod ile sipariş/kalem durumu takip edilebilir.
            <div style="margin-top:4px;">
                Oluşturulma: {{ $createdTimestamp }} |
                Son güncelleme: {{ $updatedTimestamp }} |
                Render zamanı: {{ $renderTimestamp }} |
                Versiyon: v{{ $workForm->version }}
            </div>
        </div>
    </div>
</div>
@endsection
