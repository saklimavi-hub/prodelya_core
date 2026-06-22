@extends('layouts.prodelya-admin')

@section('title', 'Grafik Yönetimi')
@section('page_title', 'Grafik Yönetimi')
@section('page_subtitle', 'Baskı operasyonlarını ayrı ayrı yönetin, her operasyona kendi görselini ve durumunu bağlayın.')
@section('hide_side_summary', '1')

@section('content')
@php
    $productImage = data_get($productSnapshot, 'image_url');
    $selectedOperation = $selectedOperationCard;
    $selectedAttachment = $selectedOperation['attachment'] ?? null;
@endphp

<style>
    .gg-page {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 320px;
        gap: 16px;
        align-items: start;
        padding-bottom: 24px;
    }

    .gg-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }

    .gg-card + .gg-card {
        margin-top: 14px;
    }

    .gg-card-body {
        padding: 16px;
    }

    .gg-section-title {
        font-size: 16px;
        font-weight: 600;
        color: #111827;
        margin-bottom: 10px;
    }

    .gg-topbar,
    .gg-actions,
    .gg-inline-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .gg-topbar {
        margin-bottom: 14px;
    }

    .gg-btn,
    .gg-btn:visited {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 34px;
        padding: 8px 11px;
        border-radius: 5px;
        border: 1px solid #d1d5db;
        background: #fff;
        color: #1f2937;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
    }

    .gg-btn-primary {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }

    .gg-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .gg-box {
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #fcfcfd;
        padding: 12px;
    }

    .gg-mini-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px 12px;
    }

    .gg-label {
        color: #6b7280;
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .gg-value {
        font-size: 13px;
        font-weight: 600;
        color: #1f2937;
        word-break: break-word;
    }

    .gg-product,
    .graphic-product-summary {
        display: grid;
        grid-template-columns: 148px 1fr;
        gap: 16px;
        align-items: start;
    }

    .gg-product-image,
    .graphic-product-image-box,
    .gg-product-placeholder,
    .gg-attachment-preview,
    .graphic-preview-stage,
    .gg-attachment-empty {
        width: 100%;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
        background: #f8fafc;
        overflow: hidden;
    }

    .gg-product-image,
    .graphic-product-image-box,
    .gg-product-placeholder {
        width: 148px;
        height: 118px;
        min-height: 118px;
    }

    .gg-product-image img,
    .graphic-product-image-fit,
    .gg-attachment-preview img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block;
        background: #fff;
    }

    .gg-product-image img,
    .graphic-product-image-fit {
        padding: 10px;
    }

    .gg-product-placeholder,
    .gg-attachment-empty {
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #6b7280;
        font-size: 11px;
        padding: 8px;
    }

    .gg-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.2;
        white-space: nowrap;
    }

    .gg-badge-amber { background: #fff7ed; color: #b45309; }
    .gg-badge-blue { background: #eff6ff; color: #2563eb; }
    .gg-badge-green { background: #ecfdf3; color: #15803d; }
    .gg-badge-gray { background: #f3f4f6; color: #6b7280; }
    .gg-badge-red { background: #fef2f2; color: #b91c1c; }

    .gg-operations {
        display: grid;
        gap: 14px;
    }

    .gg-operation-card {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fff;
        overflow: hidden;
    }

    .gg-operation-head {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        background: #fbfcfe;
        border-bottom: 1px solid #e5e7eb;
    }

    .gg-operation-title {
        font-size: 15px;
        font-weight: 700;
        color: #111827;
    }

    .gg-operation-sub {
        margin-top: 5px;
        color: #6b7280;
        font-size: 12px;
    }

    .gg-operation-body {
        padding: 16px;
        display: grid;
        grid-template-columns: minmax(0, 1.25fr) minmax(240px, .75fr);
        gap: 14px;
    }

    .gg-attachment-preview,
    .graphic-preview-stage,
    .gg-attachment-empty {
        min-height: 260px;
        height: 260px;
    }

    .gg-attachment-preview,
    .graphic-preview-stage {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 14px;
    }

    .gg-attachment-meta {
        margin-top: 10px;
        display: grid;
        gap: 6px;
        font-size: 12px;
        color: #4b5563;
    }

    .gg-file-chip-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    .gg-file-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 10px;
        border: 1px solid #d1d5db;
        border-radius: 999px;
        background: #fff;
        color: #374151;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
    }

    .graphic-preview-image {
        width: 100%;
        height: 100%;
        max-width: 100%;
        max-height: 260px;
        object-fit: contain;
        display: block;
        background: #fff;
        image-rendering: auto;
    }

    .gg-upload-zone {
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        background: #f8fafc;
        padding: 12px;
        min-height: 92px;
        transition: border-color .18s ease, background-color .18s ease, box-shadow .18s ease;
        cursor: pointer;
    }

    .gg-upload-zone.is-active,
    .gg-upload-zone.is-dragover {
        border-color: #2563eb;
        background: #eff6ff;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
    }

    .gg-upload-zone-title {
        font-size: 13px;
        font-weight: 700;
        color: #111827;
    }

    .gg-upload-zone-sub {
        margin-top: 6px;
        color: #6b7280;
        font-size: 12px;
        line-height: 1.45;
    }

    .gg-upload-zone-preview {
        margin-top: 10px;
        min-height: 42px;
    }

    .gg-upload-zone-preview img {
        width: 100%;
        max-width: 220px;
        height: 140px;
        object-fit: contain;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
        background: #fff;
        display: block;
    }

    .gg-upload-zone-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 10px;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        background: #fff;
        color: #374151;
        font-size: 12px;
        font-weight: 600;
    }

    .gg-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .gg-field label {
        display: block;
        margin-bottom: 5px;
        color: #6b7280;
        font-size: 12px;
        font-weight: 600;
    }

    .gg-field input,
    .gg-field select,
    .gg-field textarea {
        width: 100%;
        border: 1px solid #d1d5db;
        border-radius: 5px;
        padding: 9px 10px;
        font: inherit;
        color: #1f2937;
        background: #fff;
    }

    .gg-field textarea {
        min-height: 90px;
        resize: vertical;
    }

    .gg-full {
        grid-column: 1 / -1;
    }

    .gg-note {
        font-size: 12px;
        color: #6b7280;
    }

    .gg-approval-card {
        margin-top: 14px;
        border: 1px solid #dbe4f0;
        border-radius: 10px;
        background: #fbfdff;
        padding: 14px;
    }

    .gg-approval-head {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: flex-start;
        margin-bottom: 12px;
    }

    .gg-approval-head h4 {
        margin: 0 0 4px;
        font-size: 15px;
        font-weight: 700;
        color: #111827;
    }

    .gg-approval-head p {
        margin: 0;
        color: #6b7280;
        font-size: 12px;
        line-height: 1.45;
    }

    .gg-approval-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .gg-approval-line {
        padding: 10px 11px;
        border: 1px solid #e7edf5;
        border-radius: 8px;
        background: #fff;
    }

    .gg-guidance {
        margin-top: 12px;
        padding: 10px 12px;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        font-size: 12px;
        line-height: 1.5;
        color: #475569;
    }

    .gg-history {
        display: grid;
        gap: 8px;
    }

    .gg-history-row {
        display: grid;
        grid-template-columns: 116px 1fr auto;
        gap: 10px;
        border: 1px solid #e5e7eb;
        border-radius: 5px;
        padding: 9px 10px;
        background: #fff;
        font-size: 12px;
        align-items: start;
    }

    .gg-sticky {
        position: sticky;
        top: 18px;
    }

    .gg-link-box {
        padding: 10px 12px;
        border: 1px dashed #d1d5db;
        border-radius: 5px;
        background: #fafafa;
        font-size: 12px;
        color: #6b7280;
        word-break: break-word;
    }

    .gg-flash {
        margin-bottom: 12px;
        border-radius: 5px;
        padding: 10px 12px;
        font-size: 12px;
        border: 1px solid #e5e7eb;
    }

    .gg-flash-success { background: #ecfdf3; color: #166534; border-color: #bbf7d0; }
    .gg-flash-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

    .gg-lightbox {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.78);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        z-index: 80;
    }

    .gg-lightbox.is-open {
        display: flex;
    }

    .gg-lightbox-dialog {
        width: min(1100px, 100%);
        max-height: calc(100vh - 32px);
        background: #fff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
    }

    .gg-lightbox-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        border-bottom: 1px solid #e5e7eb;
    }

    .gg-lightbox-body {
        padding: 18px;
        background: #f8fafc;
    }

    .gg-lightbox-image {
        width: min(92vw, 100%);
        max-width: 92vw;
        height: min(85vh, 820px);
        max-height: 85vh;
        object-fit: contain;
        display: block;
        background: #fff;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        margin: 0 auto;
    }

    @media (max-width: 1100px) {
        .gg-page,
        .gg-operation-body {
            grid-template-columns: 1fr;
        }

        .gg-sticky {
            position: static;
        }
    }

    @media (max-width: 760px) {
        .gg-grid,
        .gg-mini-grid,
        .gg-approval-grid,
        .gg-form-grid,
        .gg-history-row,
        .gg-product {
            grid-template-columns: 1fr;
        }

        .gg-operation-head {
            flex-direction: column;
        }
    }
</style>

@if(session('success'))
    <div class="gg-flash gg-flash-success">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="gg-flash gg-flash-error">{{ $errors->first() }}</div>
@endif

<div class="gg-topbar">
    <a href="{{ route('admin.graphics.index') }}" class="gg-btn">Listeye Dön</a>
    <a href="{{ route('admin.work-forms.show', $workForm) }}" class="gg-btn">İş Formu</a>
    <a href="{{ route('admin.work-forms.pdf', $workForm) }}" class="gg-btn">PDF Oluştur</a>
    <a href="{{ $trackingUrl }}" target="_blank" rel="noopener" class="gg-btn">Müşteri Takip Linki</a>
</div>

<div class="gg-page">
    <div class="gg-main">
        <div class="gg-card">
            <div class="gg-card-body">
                <div class="gg-section-title">Sipariş ve Ürün Özeti</div>
                <div class="gg-grid">
                    <div class="gg-box">
                        <div class="gg-mini-grid">
                            <div>
                                <div class="gg-label">Sipariş No</div>
                                <div class="gg-value">{{ data_get($orderSnapshot, 'document_number', '-') }}</div>
                            </div>
                            <div>
                                <div class="gg-label">İş Formu No</div>
                                <div class="gg-value">{{ $workForm->work_form_number }}</div>
                            </div>
                            <div>
                                <div class="gg-label">Müşteri</div>
                                <div class="gg-value">{{ data_get($customerSnapshot, 'company_name', '-') }}</div>
                            </div>
                            <div>
                                <div class="gg-label">Genel Grafik Durumu</div>
                                <div class="gg-value">{{ $generalGraphicStatusLabel }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="gg-box">
                        <div class="gg-product graphic-product-summary">
                            @if($productImage)
                                <div class="gg-product-image graphic-product-image-box" data-product-image-wrap>
                                    <img class="graphic-product-image-fit" src="{{ $productImage }}" alt="{{ data_get($productSnapshot, 'product_name', '-') }}" loading="lazy">
                                </div>
                            @else
                                <div class="gg-product-placeholder graphic-product-image-box">Ürün Görseli</div>
                            @endif
                            <div>
                                <div class="gg-label">Ürün</div>
                                <div class="gg-value">{{ data_get($productSnapshot, 'product_name', '-') }}</div>
                                <div class="gg-label" style="margin-top:8px;">Ürün Kodu</div>
                                <div class="gg-value">{{ data_get($productSnapshot, 'product_code', '-') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="gg-card">
            <div class="gg-card-body">
                <div class="gg-section-title">Çalışma Klasörü</div>
                @if($systemWorkFolder)
                    <div class="gg-box">
                        <div class="gg-mini-grid">
                            <div>
                                <div class="gg-label">Durum</div>
                                <div>
                                    <span class="gg-badge {{ $systemWorkFolder['has_error'] ? 'gg-badge-red' : ($systemWorkFolder['status'] === 'created' ? 'gg-badge-green' : 'gg-badge-gray') }}">
                                        {{ $systemWorkFolder['status_label'] }}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <div class="gg-label">Display Path</div>
                                <div class="gg-value">{{ $systemWorkFolder['display_path'] }}</div>
                            </div>
                        </div>
                        <div class="gg-note" style="margin-top:10px;">Bu ekran yalnız display path gösterir. Fiziksel dosya yolu veya teknik storage bilgisi paylaşılmaz.</div>
                    </div>
                @else
                    <div class="gg-link-box">Çalışma klasörü bilgisi henüz görünmüyor.</div>
                @endif
            </div>
        </div>

        <div class="gg-card">
            <div class="gg-card-body">
                <div class="gg-section-title">Grafik Operasyonları</div>
                <div class="gg-note" style="margin-bottom:12px;">Her baskı operasyonu ayrı grafik kartı olarak yönetilir. Bir operasyona yüklenen görsel yalnız o operasyonda görünür.</div>
                <div class="gg-operations">
                    @forelse($printOperationCards as $operation)
                        <div class="gg-operation-card" id="operation-{{ $operation['id'] }}">
                            <div class="gg-operation-head">
                                <div>
                                    <div class="gg-operation-title">{{ $operation['title'] }}</div>
                                    <div class="gg-operation-sub">{{ $operation['print_meta']['production_type'] }} · {{ $operation['print_meta']['print_quantity'] }}</div>
                                </div>
                                <div class="gg-inline-actions">
                                    <span class="gg-badge {{ $operation['status_badge'] }}">{{ $operation['status_label'] }}</span>
                                    <span class="gg-badge {{ $operation['customer_approval_badge'] }}">{{ $operation['customer_approval_label'] }}</span>
                                </div>
                            </div>
                            <div class="gg-operation-body">
                                <div>
                                    <div class="gg-label">Son Görsel</div>
                                    @if($operation['attachment'])
                                        @if($operation['attachment']['is_image'] && $operation['attachment']['preview_url'])
                                            <button
                                                type="button"
                                                class="gg-attachment-preview graphic-preview-stage"
                                                data-lightbox-trigger
                                                data-lightbox-src="{{ $operation['attachment']['open_url'] }}"
                                                data-lightbox-title="{{ $operation['sequence_code'] }} — {{ $operation['attachment']['file_name'] }}"
                                            >
                                                <img class="graphic-preview-image" src="{{ $operation['attachment']['preview_url'] }}" alt="{{ $operation['attachment']['file_name'] }}" loading="lazy">
                                            </button>
                                        @elseif($operation['attachment']['is_image'])
                                            <div class="gg-attachment-empty graphic-preview-stage">Önizleme alınamadı</div>
                                        @else
                                            <div class="gg-attachment-empty graphic-preview-stage">{{ $operation['attachment']['kind_label'] }}</div>
                                        @endif
                                        <div class="gg-attachment-meta">
                                            <div><strong>{{ $operation['attachment']['file_name'] }}</strong></div>
                                            <div>{{ $operation['attachment']['created_at'] }}</div>
                                            <div><span class="gg-badge {{ $operation['attachment']['visibility'] === 'customer_visible' ? 'gg-badge-blue' : 'gg-badge-gray' }}">{{ $operation['attachment']['visibility_label'] }}</span></div>
                                        </div>
                                        <div class="gg-file-chip-row">
                                            @if($operation['attachment']['is_image'] && $operation['attachment']['open_url'])
                                                <button
                                                    type="button"
                                                    class="gg-file-chip"
                                                    data-lightbox-trigger
                                                    data-lightbox-src="{{ $operation['attachment']['open_url'] }}"
                                                    data-lightbox-title="{{ $operation['sequence_code'] }} — {{ $operation['attachment']['file_name'] }}"
                                                >
                                                    Büyük Gör
                                                </button>
                                            @else
                                                <span class="gg-file-chip">Önizleme Yok</span>
                                            @endif
                                            @if($operation['attachment']['open_url'] && !$operation['attachment']['is_image'])
                                                <a href="{{ $operation['attachment']['open_url'] }}" target="_blank" rel="noopener" class="gg-file-chip">Dosyayı Aç</a>
                                            @endif
                                        </div>
                                    @else
                                        <div class="gg-attachment-empty graphic-preview-stage">Son Görsel: yok</div>
                                    @endif
                                </div>
                                <div>
                                    <form method="POST" action="{{ $operation['upload_url'] }}" enctype="multipart/form-data" class="gg-form-grid">
                                        @csrf
                                        <input type="hidden" name="attachment_type" value="graphic_visual">
                                        <input type="hidden" name="redirect_to" value="admin.graphics.show">
                                        <input type="hidden" name="order_item_print_graphic_id" value="{{ $operation['id'] }}">
                                        <div class="gg-field gg-full">
                                            <label>Dosya Seç</label>
                                            <div
                                                class="gg-upload-zone"
                                                tabindex="0"
                                                data-paste-zone
                                                data-order-item-print-graphic-id="{{ $operation['id'] }}"
                                            >
                                                <div class="gg-upload-zone-title">{{ $operation['sequence_code'] }} operasyonuna dosya ekle</div>
                                                <div class="gg-upload-zone-sub">Ctrl + V ile ekran görüntüsü yapıştırın veya dosyayı buraya bırakın. Tarayıcı desteklemezse aşağıdaki dosya seç alanı çalışmaya devam eder.</div>
                                                <div class="gg-upload-zone-preview" data-upload-preview></div>
                                            </div>
                                            <input
                                                type="file"
                                                name="file"
                                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                                required
                                                data-upload-input
                                                data-order-item-print-graphic-id="{{ $operation['id'] }}"
                                            >
                                        </div>
                                        <div class="gg-field">
                                            <label>Görünürlük</label>
                                            <select name="visibility">
                                                <option value="internal" @selected($operation['visibility_default'] !== 'customer_visible')>İç Kayıt</option>
                                                <option value="customer_visible" @selected($operation['visibility_default'] === 'customer_visible')>Müşteriye Açık</option>
                                            </select>
                                        </div>
                                        <div class="gg-field">
                                            <label>Grafik Notu</label>
                                            <input type="text" name="note" value="{{ $operation['graphic_note'] }}" placeholder="Operasyon notu">
                                        </div>
                                        <div class="gg-full gg-actions">
                                            <button type="submit" class="gg-btn gg-btn-primary">Görsel Yükle</button>
                                        </div>
                                    </form>

                                    <div class="gg-actions" style="margin-top:12px;">
                                        <form method="POST" action="{{ $operation['status_url'] }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="action" value="approved">
                                            <button type="submit" class="gg-btn">Onaylandı İşaretle</button>
                                        </form>
                                        <form method="POST" action="{{ $operation['status_url'] }}" class="gg-form-grid" style="width:100%;">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="action" value="revision_requested">
                                            <div class="gg-field gg-full">
                                                <label>Revize Notu</label>
                                                <input type="text" name="note" value="{{ $operation['customer_note'] }}" placeholder="Revize notu girin">
                                            </div>
                                            <div class="gg-full">
                                                <button type="submit" class="gg-btn">Revize İstendi</button>
                                            </div>
                                        </form>
                                        <form method="POST" action="{{ $operation['status_url'] }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="action" value="production_ready">
                                            <button type="submit" class="gg-btn" @disabled(!$operation['can_mark_production_ready'])>Üretime Hazır İşaretle</button>
                                        </form>
                                    </div>

                                    @if($customerApprovalEnabled)
                                        @php($approvalCard = $operation['customer_approval'])
                                        <div class="gg-approval-card" data-testid="graphic-customer-approval-card">
                                            <div class="gg-approval-head">
                                                <div>
                                                    <h4>Müşteri Onayı</h4>
                                                    <p>Gönderilen dosyayı, son müşteri yanıtını ve public onay linkini bu karttan izleyin. Üretime hazır kararı ayrı ilerler.</p>
                                                </div>
                                                <span class="gg-badge {{ $approvalCard['latest_request']['status_badge'] ?? $operation['customer_approval_badge'] }}">{{ $approvalCard['latest_request']['status_label'] ?? $operation['customer_approval_label'] }}</span>
                                            </div>

                                            @if($approvalCard['latest_request'])
                                                <div class="gg-approval-grid" style="margin-bottom:10px;">
                                                    <div class="gg-approval-line">
                                                        <div class="gg-label">Son Durum</div>
                                                        <div><span class="gg-badge {{ $approvalCard['latest_request']['status_badge'] }}">{{ $approvalCard['latest_request']['status_label'] }}</span></div>
                                                    </div>
                                                    <div class="gg-approval-line">
                                                        <div class="gg-label">Son Gönderim</div>
                                                        <div class="gg-value">{{ $approvalCard['latest_request']['created_at'] ?: '-' }}</div>
                                                    </div>
                                                    <div class="gg-approval-line">
                                                        <div class="gg-label">Gönderilen Dosya</div>
                                                        <div class="gg-value">{{ $approvalCard['latest_request']['attachment_file_name'] }}</div>
                                                    </div>
                                                    <div class="gg-approval-line">
                                                        <div class="gg-label">Görüntülendi</div>
                                                        <div class="gg-value">{{ $approvalCard['latest_request']['viewed_at'] ?: '-' }}</div>
                                                    </div>
                                                    <div class="gg-approval-line">
                                                        <div class="gg-label">Müşteri Yanıtı</div>
                                                        <div class="gg-value">{{ $approvalCard['latest_request']['responded_at'] ?: '-' }}</div>
                                                    </div>
                                                    <div class="gg-approval-line">
                                                        <div class="gg-label">Bağlantı Durumu</div>
                                                        @if($approvalCard['latest_request']['open_url'])
                                                            <a href="{{ $approvalCard['latest_request']['open_url'] }}" class="gg-btn" target="_blank" rel="noopener" data-testid="graphic-approval-open-link-button">Onay Linkini Aç</a>
                                                        @else
                                                            <div class="gg-note">Aktif onay linki bulunmuyor.</div>
                                                        @endif
                                                    </div>
                                                    @if($approvalCard['latest_request']['expires_at'])
                                                        <div class="gg-approval-line">
                                                            <div class="gg-label">Link Bitişi</div>
                                                            <div class="gg-value">{{ $approvalCard['latest_request']['expires_at'] }}</div>
                                                        </div>
                                                    @endif
                                                    @if(filled($approvalCard['latest_request']['customer_note']))
                                                        <div class="gg-full gg-approval-line">
                                                            <div class="gg-label">Müşteri Notu</div>
                                                            <div class="gg-note">{{ $approvalCard['latest_request']['customer_note'] }}</div>
                                                        </div>
                                                    @endif
                                                </div>
                                            @else
                                                <div class="gg-guidance">Henüz müşteri onay gönderimi yapılmadı. Uygun müşteri görünür dosya seçerek ilk gönderimi başlatın.</div>
                                            @endif

                                            <div class="gg-guidance">
                                                <span class="gg-badge {{ $operation['production_ready_guidance']['badge'] }}">Üretime Hazır</span>
                                                {{ $operation['production_ready_guidance']['label'] }}
                                            </div>

                                            @if(!empty($approvalCard['eligible_attachments']))
                                                <form method="POST" action="{{ $approvalCard['send_url'] }}" class="gg-form-grid">
                                                    @csrf
                                                    <div class="gg-field gg-full">
                                                        <label>Müşteriye Gönderilecek Dosya</label>
                                                        <select name="attachment_id" required>
                                                            <option value="">Dosya seçin</option>
                                                            @foreach($approvalCard['eligible_attachments'] as $approvalAttachment)
                                                                <option value="{{ $approvalAttachment['id'] }}">
                                                                    {{ $approvalAttachment['file_name'] }} · {{ $approvalAttachment['attachment_type_label'] }} · {{ $approvalAttachment['uploaded_at'] ?: '-' }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="gg-full gg-actions">
                                                        <button type="submit" class="gg-btn gg-btn-primary" data-testid="graphic-approval-send-button">{{ $approvalCard['send_action_label'] }}</button>
                                                        @if($approvalCard['latest_request']['open_url'] ?? null)
                                                            <a href="{{ $approvalCard['latest_request']['open_url'] }}" class="gg-btn" target="_blank" rel="noopener">Onay Linkini Aç</a>
                                                        @endif
                                                    </div>
                                                </form>
                                            @else
                                                <div class="gg-note">Müşteri görünür grafik dosyası yok.</div>
                                                <div class="gg-actions" style="margin-top:10px;">
                                                    <button type="button" class="gg-btn" disabled data-testid="graphic-approval-send-disabled">Müşteri Onayına Gönder</button>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="gg-link-box">Bu iş formu için per-print grafik operasyonu bulunmuyor.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="gg-card">
            <div class="gg-card-body">
                <div class="gg-section-title">Workflow Geçmişi</div>
                <div class="gg-history">
                    @foreach($workflowHistory as $history)
                        <div class="gg-history-row">
                            <div class="gg-note">{{ $history['at'] ?: '-' }}</div>
                            <div>
                                <div>{{ $history['label'] }}</div>
                                @if(filled($history['note'] ?? null))
                                    <div class="gg-note">{{ $history['note'] }}</div>
                                @endif
                            </div>
                            <div>
                                <span class="gg-badge {{ $history['visibility'] === 'Müşteriye Açık' ? 'gg-badge-blue' : 'gg-badge-gray' }}">{{ $history['visibility'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="gg-side">
        <div class="gg-sticky">
            <div class="gg-card">
                <div class="gg-card-body">
                    <div class="gg-section-title">Sıradaki İş</div>
                    <div class="gg-mini-grid">
                        <div class="gg-full">
                            <div class="gg-label">Seçili Sipariş</div>
                            <div class="gg-value">{{ data_get($orderSnapshot, 'document_number', '-') }}</div>
                        </div>
                        <div class="gg-full">
                            <div class="gg-label">Sıradaki İş</div>
                            <div class="gg-value">{{ $nextActionLabel }}</div>
                        </div>
                        <div>
                            <div class="gg-label">Genel Durum</div>
                            <div><span class="gg-badge {{ match($generalGraphicStatusKey) {
                                'production_ready', 'approved' => 'gg-badge-green',
                                'visual_uploaded', 'customer_approval_waiting' => 'gg-badge-blue',
                                'revision_requested' => 'gg-badge-red',
                                default => 'gg-badge-amber',
                            } }}">{{ $generalGraphicStatusLabel }}</span></div>
                        </div>
                        <div>
                            <div class="gg-label">Onay Özeti</div>
                            <div><span class="gg-badge gg-badge-gray">{{ $approvalStatusLabel }}</span></div>
                        </div>
                    </div>

                    @if($selectedOperation)
                        <div class="gg-link-box" style="margin-top:14px;">
                            <strong>Seçili / Öncelikli Operasyon</strong><br>
                            {{ $selectedOperation['title'] }}<br>
                            {{ $selectedOperation['status_label'] }}
                        </div>
                    @endif

                    @if($selectedAttachment)
                        <div class="gg-link-box" style="margin-top:10px;">
                            <strong>Son Görsel</strong><br>
                            {{ $selectedAttachment['file_name'] }}<br>
                            {{ $selectedAttachment['kind_label'] }}
                        </div>
                    @endif

                    <div class="gg-link-box" style="margin-top:10px;">
                        <strong>İş Formu Linki</strong><br>
                        {{ route('admin.work-forms.show', $workForm) }}
                    </div>

                    <div class="gg-link-box" style="margin-top:10px;">
                        <strong>PDF Linki</strong><br>
                        {{ route('admin.work-forms.pdf', $workForm) }}
                    </div>

                    @if($systemWorkFolder)
                        <div class="gg-link-box" style="margin-top:10px;">
                            <strong>Çalışma Klasörü</strong><br>
                            {{ $systemWorkFolder['display_path'] }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<div class="gg-lightbox" data-lightbox-modal>
    <div class="gg-lightbox-dialog">
        <div class="gg-lightbox-head">
            <div>
                <div class="gg-label">Büyük Önizleme</div>
                <div class="gg-value" data-lightbox-title>Görsel</div>
            </div>
            <button type="button" class="gg-btn" data-lightbox-close>Kapat</button>
        </div>
        <div class="gg-lightbox-body">
            <img src="" alt="" class="gg-lightbox-image" data-lightbox-image>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const lightbox = document.querySelector('[data-lightbox-modal]');
    const lightboxImage = lightbox?.querySelector('[data-lightbox-image]');
    const lightboxTitle = lightbox?.querySelector('[data-lightbox-title]');

    document.querySelectorAll('[data-lightbox-trigger]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            if (!lightbox || !lightboxImage || !lightboxTitle) {
                return;
            }

            const src = trigger.getAttribute('data-lightbox-src');
            const title = trigger.getAttribute('data-lightbox-title') || 'Görsel';

            if (!src) {
                return;
            }

            lightboxImage.src = src;
            lightboxImage.alt = title;
            lightboxTitle.textContent = title;
            lightbox.classList.add('is-open');
        });
    });

    lightbox?.addEventListener('click', function (event) {
        if (event.target === lightbox || event.target.hasAttribute('data-lightbox-close')) {
            lightbox.classList.remove('is-open');
            if (lightboxImage) {
                lightboxImage.src = '';
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && lightbox?.classList.contains('is-open')) {
            lightbox.classList.remove('is-open');
            if (lightboxImage) {
                lightboxImage.src = '';
            }
        }
    });

    const renderUploadPreview = function (file, previewTarget) {
        if (!previewTarget || !file) {
            return;
        }

        previewTarget.innerHTML = '';

        if (file.type && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function (event) {
                const image = document.createElement('img');
                image.src = event.target?.result || '';
                image.alt = file.name;
                previewTarget.appendChild(image);
            };
            reader.readAsDataURL(file);
            return;
        }

        const chip = document.createElement('span');
        chip.className = 'gg-upload-zone-chip';
        chip.textContent = file.name + ' hazır';
        previewTarget.appendChild(chip);
    };

    document.querySelectorAll('[data-paste-zone]').forEach(function (zone) {
        const card = zone.closest('.gg-operation-card');
        const input = card?.querySelector('[data-upload-input]');
        const preview = zone.querySelector('[data-upload-preview]');

        const activate = function () {
            document.querySelectorAll('[data-paste-zone]').forEach(function (otherZone) {
                otherZone.classList.remove('is-active');
            });
            zone.classList.add('is-active');
        };

        zone.addEventListener('click', function () {
            activate();
            input?.click();
        });

        zone.addEventListener('focus', activate);

        input?.addEventListener('change', function () {
            const file = input.files && input.files[0] ? input.files[0] : null;
            renderUploadPreview(file, preview);
        });

        zone.addEventListener('paste', function (event) {
            const clipboardItems = Array.from(event.clipboardData?.items || []);
            const imageItem = clipboardItems.find(function (item) {
                return item.type && item.type.startsWith('image/');
            });

            if (!imageItem || !input) {
                return;
            }

            const blob = imageItem.getAsFile();
            if (!blob) {
                return;
            }

            const file = new File([blob], 'clipboard-' + Date.now() + '.png', { type: blob.type || 'image/png' });
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            input.files = dataTransfer.files;
            renderUploadPreview(file, preview);
            event.preventDefault();
        });

        ['dragenter', 'dragover'].forEach(function (eventName) {
            zone.addEventListener(eventName, function (event) {
                event.preventDefault();
                activate();
                zone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend', 'drop'].forEach(function (eventName) {
            zone.addEventListener(eventName, function (event) {
                if (eventName !== 'drop') {
                    zone.classList.remove('is-dragover');
                    return;
                }

                event.preventDefault();
                zone.classList.remove('is-dragover');

                if (!input || !event.dataTransfer?.files?.length) {
                    return;
                }

                input.files = event.dataTransfer.files;
                renderUploadPreview(input.files[0], preview);
            });
        });
    });
});
</script>
@endsection
