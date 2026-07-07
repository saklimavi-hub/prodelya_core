@extends('layouts.prodelya-admin')

@section('title', 'Grafik Yönetimi')
@section('page_title', 'Grafik Yönetimi')
@section('page_subtitle', 'Baskı operasyonlarını ayrı ayrı yönetin, her operasyona kendi görselini ve durumunu bağlayın.')
@section('hide_side_summary', '1')

@section('content')
@php
    $productPreview = $productPreview ?? null;
    $selectedOperation = $selectedOperationCard;
    $selectedAttachment = $selectedOperation['attachment'] ?? null;
    $selectedApprovalCard = $customerApprovalEnabled && $selectedOperation ? ($selectedOperation['customer_approval'] ?? null) : null;
    $selectedLatestApprovalRequest = data_get($selectedApprovalCard, 'latest_request');
    $orderUrl = route('admin.orders.show', $workForm->order_id);
    $showUrl = route('admin.graphics.show', $workForm);
    $activeStep = $activeActionStep ?? 'summary';
    $activeStepTab = collect($actionStepTabs ?? [])->firstWhere('is_active', true);
    $activeStepLabel = $activeStepTab['label'] ?? 'İşlem Adımı';
    $buildShowUrl = function (array $params = []) use ($showUrl, $selectedOperation, $activeStep): string {
        $query = array_filter([
            'operation' => $params['operation'] ?? ($selectedOperation['id'] ?? null),
            'step' => $params['step'] ?? $activeStep,
        ], fn ($value) => filled($value));

        return $query === [] ? $showUrl : $showUrl . '?' . http_build_query($query);
    };
    $mainPreviewUrl = $selectedAttachment['original_url'] ?? data_get($productPreview, 'original_url');
    $mainPreviewImageUrl = $selectedAttachment['preview_url'] ?? data_get($productPreview, 'preview_url');
    $mainPreviewTitle = $selectedAttachment
        ? (($selectedOperation['sequence_code'] ?? '-') . ' - ' . ($selectedAttachment['file_name'] ?? 'Görsel'))
        : data_get($productPreview, 'title', 'Ürün görseli');
@endphp

<style>
    .gg-page {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 296px;
        gap: 16px;
        align-items: start;
        padding-bottom: 20px;
    }

    .gg-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
    }

    .gg-card + .gg-card {
        margin-top: 14px;
    }

    .gg-card-body {
        padding: 14px;
    }

    .gg-section-title {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
        color: #111827;
    }

    .gg-note {
        font-size: 12px;
        color: #6b7280;
        line-height: 1.5;
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

    .gg-header-row,
    .gg-preview-head {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
    }

    .gg-header-row {
        margin-bottom: 10px;
    }

    .gg-summary-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.25fr) 220px;
        gap: 12px;
        align-items: start;
    }

    .gg-summary-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
    }

    .gg-summary-card,
    .gg-box {
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #fbfcfe;
        padding: 11px 12px;
    }

    .gg-label {
        color: #6b7280;
        font-size: 11px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .gg-value {
        font-size: 13px;
        font-weight: 700;
        color: #1f2937;
        word-break: break-word;
    }

    .gg-subvalue {
        margin-top: 3px;
        font-size: 11px;
        color: #6b7280;
    }

    .gg-mini-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px 12px;
    }

    .gg-product-summary {
        display: grid;
        gap: 10px;
    }

    .gg-preview-frame {
        width: 100%;
        border-radius: 8px;
        border: 1px solid #e5eaf2;
        background: #f8fafc;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .gg-preview-trigger {
        appearance: none;
        padding: 0;
        cursor: zoom-in;
    }

    .gg-preview-frame--summary {
        min-height: 168px;
        height: 168px;
    }

    .gg-main-preview-frame {
        width: 100%;
        height: clamp(360px, 46vh, 560px);
        min-height: 360px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        background: #f8fafc;
        border: 1px solid #e5eaf2;
        border-radius: 8px;
    }

    .gg-preview-frame img,
    .gg-preview-image,
    .gg-main-preview-frame img.gg-preview-image.pd-allow-large {
        display: block;
        width: 100% !important;
        height: 100% !important;
        max-width: 100% !important;
        max-height: 100% !important;
        object-fit: contain;
        object-position: center;
        background: #fff;
    }

    .gg-product-placeholder,
    .gg-preview-empty {
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #6b7280;
        font-size: 12px;
        line-height: 1.5;
        padding: 12px;
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

    .gg-operation-tabs,
    .gg-step-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .gg-operation-tab,
    .gg-step-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 11px;
        border-radius: 999px;
        border: 1px solid #dbe3ef;
        background: #f8fafc;
        color: #334155;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
    }

    .gg-operation-tab.is-active,
    .gg-step-tab.is-active {
        background: #eff6ff;
        border-color: #93c5fd;
        color: #1d4ed8;
        box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.08);
    }

    .gg-workspace {
        display: grid;
        grid-template-columns: minmax(0, 1.08fr) minmax(300px, 0.92fr);
        gap: 14px;
    }

    .gg-preview-panel,
    .gg-flow-column,
    .gg-history {
        display: grid;
        gap: 12px;
    }

    .gg-operation-title {
        font-size: 15px;
        font-weight: 700;
        color: #111827;
    }

    .gg-operation-sub {
        margin-top: 4px;
        color: #6b7280;
        font-size: 12px;
    }

    .gg-preview-meta {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .gg-file-chip-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
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
        image-rendering: auto;
    }

    .gg-step-panel {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 13px;
        background: #fff;
    }

    .gg-step-panel--upload { background: #eefbf5; border-color: #cfeedd; }
    .gg-step-panel--summary { background: #f0f7ff; border-color: #d7e8fb; }
    .gg-step-panel--approval { background: #fff8eb; border-color: #f4dfb7; }
    .gg-step-panel--revision { background: #fff1f2; border-color: #f6cdd5; }
    .gg-step-panel--ready { background: #f3f1ff; border-color: #ddd7fb; }

    .gg-step-panel-head {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: flex-start;
        margin-bottom: 12px;
    }

    .gg-step-panel-title {
        margin: 0;
        color: #111827;
        font-size: 14px;
        font-weight: 700;
    }

    .gg-step-panel-sub {
        margin-top: 4px;
        color: #64748b;
        font-size: 12px;
        line-height: 1.45;
    }

    .gg-upload-zone {
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        background: #f8fafc;
        padding: 12px;
        min-height: 84px;
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
        min-height: 88px;
        resize: vertical;
    }

    .gg-full {
        grid-column: 1 / -1;
    }

    .gg-approval-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .gg-approval-line,
    .gg-action-item {
        padding: 10px 11px;
        border: 1px solid rgba(148, 163, 184, 0.26);
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.8);
    }

    .gg-action-item .gg-actions {
        margin-top: 8px;
    }

    .gg-guidance {
        padding: 10px 12px;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        font-size: 12px;
        line-height: 1.5;
        color: #475569;
    }

    .gg-quick-links {
        display: grid;
        gap: 8px;
    }

    .gg-quick-link {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 10px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #f8fafc;
        color: #1f2937;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
    }

    .gg-quick-link span:last-child {
        color: #6b7280;
        font-weight: 500;
    }

    .gg-history-row {
        display: grid;
        grid-template-columns: 108px 1fr auto;
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
        display: grid;
        gap: 12px;
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

    .gg-side-section + .gg-side-section {
        margin-top: 12px;
    }

    .gg-side-title {
        font-size: 14px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 10px;
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

    @media (max-width: 1180px) {
        .gg-summary-layout,
        .gg-page,
        .gg-workspace {
            grid-template-columns: 1fr;
        }

        .gg-summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .gg-sticky {
            position: static;
        }
    }

    @media (max-width: 760px) {
        .gg-summary-grid,
        .gg-mini-grid,
        .gg-preview-meta,
        .gg-approval-grid,
        .gg-form-grid,
        .gg-history-row {
            grid-template-columns: 1fr;
        }

        .gg-preview-head,
        .gg-header-row,
        .gg-step-panel-head {
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
    <a href="{{ $orderUrl }}" class="gg-btn">Sipariş Detayı</a>
    <a href="{{ route('admin.work-forms.pdf', $workForm) }}" class="gg-btn">PDF Oluştur</a>
    <a href="{{ $trackingUrl }}" target="_blank" rel="noopener" class="gg-btn">Müşteri Takip Linki</a>
</div>

<div class="gg-page">
    <div class="gg-main">
        <div class="gg-card">
            <div class="gg-card-body">
                <div class="gg-header-row">
                    <div>
                        <h3 class="gg-section-title">Genel Durum</h3>
                        <div class="gg-note">İlk ekranda sipariş, seçili iş ve görsel durumu kompakt olarak görünür.</div>
                    </div>
                    <span class="gg-badge {{ match($generalGraphicStatusKey) {
                        'production_ready', 'approved' => 'gg-badge-green',
                        'visual_uploaded', 'customer_approval_waiting' => 'gg-badge-blue',
                        'revision_requested' => 'gg-badge-red',
                        default => 'gg-badge-amber',
                    } }}">{{ $generalGraphicStatusLabel }}</span>
                </div>
                <div class="gg-summary-layout">
                    <div class="gg-summary-grid">
                        <div class="gg-summary-card">
                            <div class="gg-label">Sipariş No</div>
                            <div class="gg-value">{{ data_get($orderSnapshot, 'document_number', '-') }}</div>
                            <div class="gg-subvalue">İş Formu: {{ $workForm->work_form_number }}</div>
                        </div>
                        <div class="gg-summary-card">
                            <div class="gg-label">Seçili İş</div>
                            <div class="gg-value">{{ $selectedOperation['sequence_code'] ?? '-' }}</div>
                            <div class="gg-subvalue">{{ $selectedOperation['title'] ?? 'Operasyon yok' }}</div>
                        </div>
                        <div class="gg-summary-card">
                            <div class="gg-label">Genel Durum</div>
                            <div class="gg-value">{{ $generalGraphicStatusLabel }}</div>
                            <div class="gg-subvalue">Sıradaki iş: {{ $nextActionLabel }}</div>
                        </div>
                        <div class="gg-summary-card">
                            <div class="gg-label">{{ $customerApprovalEnabled ? 'Müşteri Onayı' : 'Onay Durumu' }}</div>
                            <div class="gg-value">{{ $approvalStatusLabel }}</div>
                            <div class="gg-subvalue">Aktif adım: {{ $activeStepLabel }}</div>
                        </div>
                        <div class="gg-summary-card">
                            <div class="gg-label">Çalışma Klasörü</div>
                            <div class="gg-value">{{ $systemWorkFolder['status_label'] ?? 'Henüz hazır değil' }}</div>
                            <div class="gg-subvalue">{{ $systemWorkFolder['display_path'] ?? 'Güvenli klasör görünümü bulunmuyor.' }}</div>
                        </div>
                    </div>
                    <div class="gg-product-summary">
                        @if($productPreview)
                            <button
                                type="button"
                                class="gg-preview-frame gg-preview-frame--summary gg-preview-trigger"
                                data-lightbox-trigger
                                data-lightbox-src="{{ $productPreview['original_url'] }}"
                                data-lightbox-title="{{ $productPreview['title'] }}"
                            >
                                <img class="gg-preview-image pd-allow-large" src="{{ $productPreview['preview_url'] }}" alt="{{ $productPreview['title'] }}" loading="lazy">
                            </button>
                        @else
                            <div class="gg-preview-frame gg-preview-frame--summary gg-product-placeholder">Ürün görseli henüz yok</div>
                        @endif
                        <div class="gg-box">
                            <div class="gg-label">Ürün</div>
                            <div class="gg-value">{{ data_get($productSnapshot, 'product_name', '-') }}</div>
                            <div class="gg-subvalue">Ürün kodu: {{ data_get($productSnapshot, 'product_code', '-') }}</div>
                            @if(!empty($operationSummaryLines))
                                <div class="gg-subvalue">{{ implode(' · ', $operationSummaryLines) }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="gg-card">
            <div class="gg-card-body">
                <div class="gg-preview-head" style="margin-bottom: 12px;">
                    <div>
                        <h3 class="gg-section-title">Grafik Operasyonları</h3>
                        <div class="gg-note">Operasyonu seçin, büyük önizlemeyi inceleyin ve yalnız ilgili adımı açarak işleyin.</div>
                    </div>
                    @if($selectedOperation)
                        <span class="gg-badge {{ $selectedOperation['status_badge'] }}">{{ $selectedOperation['status_label'] }}</span>
                    @endif
                </div>

                @if(!empty($operationTabs))
                    <div class="gg-operation-tabs" data-testid="graphic-operation-tabs" style="margin-bottom: 14px;">
                        @foreach($operationTabs as $operationTab)
                            <a
                                href="{{ $buildShowUrl(['operation' => $operationTab['id']]) }}"
                                class="gg-operation-tab {{ $operationTab['is_active'] ? 'is-active' : '' }}"
                            >
                                <span>{{ $operationTab['sequence_code'] }}</span>
                                <span>{{ $operationTab['title'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if($selectedOperation)
                    <div class="gg-workspace">
                        <div class="gg-preview-panel">
                            <div class="gg-preview-head">
                                <div>
                                    <div class="gg-label">Büyük Önizleme</div>
                                    <div class="gg-operation-title">{{ $selectedOperation['title'] }}</div>
                                    <div class="gg-operation-sub">{{ $selectedOperation['print_meta']['production_type'] }} · {{ $selectedOperation['print_meta']['print_quantity'] }}</div>
                                </div>
                                <div class="gg-inline-actions">
                                    <span class="gg-badge {{ $selectedOperation['status_badge'] }}">{{ $selectedOperation['status_label'] }}</span>
                                    <span class="gg-badge {{ $selectedOperation['customer_approval_badge'] }}">{{ $selectedOperation['customer_approval_label'] }}</span>
                                </div>
                            </div>

                            @if($mainPreviewImageUrl && $mainPreviewUrl)
                                <button
                                    type="button"
                                    class="gg-main-preview-frame gg-preview-trigger"
                                    data-lightbox-trigger
                                    data-lightbox-src="{{ $mainPreviewUrl }}"
                                    data-lightbox-title="{{ $mainPreviewTitle }}"
                                    data-testid="graphic-main-preview-frame"
                                >
                                    <img class="gg-preview-image graphic-preview-image pd-allow-large" src="{{ $mainPreviewImageUrl }}" alt="{{ $mainPreviewTitle }}" loading="lazy">
                                </button>
                            @elseif($selectedAttachment && !$selectedAttachment['is_image'])
                                <div class="gg-main-preview-frame gg-preview-empty">{{ $selectedAttachment['kind_label'] }}</div>
                            @else
                                <div class="gg-main-preview-frame gg-preview-empty">Önizleme alınamadı</div>
                            @endif

                            <div class="gg-preview-meta">
                                <div class="gg-box">
                                    <div class="gg-label">{{ $selectedAttachment ? 'Seçili Görsel' : 'Referans Görsel' }}</div>
                                    <div class="gg-value">{{ $selectedAttachment['file_name'] ?? data_get($productPreview, 'title', 'Görsel bulunamadı') }}</div>
                                    <div class="gg-subvalue">{{ $selectedAttachment['created_at'] ?? 'Ürün görseli referans olarak kullanılıyor.' }}</div>
                                </div>
                                <div class="gg-box">
                                    <div class="gg-label">Görünürlük</div>
                                    <div class="gg-value">{{ $selectedAttachment['visibility_label'] ?? ($selectedOperation['visibility_default'] === 'customer_visible' ? 'Müşteriye Açık' : 'İç Kayıt') }}</div>
                                    <div class="gg-subvalue">Büyük önizleme tam alan standardıyla gösterilir.</div>
                                </div>
                            </div>

                            <div class="gg-file-chip-row">
                                @if($mainPreviewUrl)
                                    <button
                                        type="button"
                                        class="gg-file-chip"
                                        data-lightbox-trigger
                                        data-lightbox-src="{{ $mainPreviewUrl }}"
                                        data-lightbox-title="{{ $mainPreviewTitle }}"
                                    >
                                        Büyük Önizleme
                                    </button>
                                @else
                                    <span class="gg-file-chip">Önizleme Yok</span>
                                @endif

                                @if($selectedAttachment && $selectedAttachment['open_url'] && !$selectedAttachment['is_image'])
                                    <a href="{{ $selectedAttachment['open_url'] }}" target="_blank" rel="noopener" class="gg-file-chip">Dosyayı Aç</a>
                                @endif
                            </div>
                        </div>

                        <div class="gg-flow-column">
                            <div class="gg-card">
                                <div class="gg-card-body">
                                    <div class="gg-preview-head" style="margin-bottom: 10px;">
                                        <div>
                                            <div class="gg-label">İşlem Adımları</div>
                                            <div class="gg-note">Tüm kartlar aynı anda açılmaz. Yalnız seçili adım görünür.</div>
                                        </div>
                                        <span class="gg-badge gg-badge-gray">{{ $activeStepLabel }}</span>
                                    </div>

                                    <div class="gg-step-tabs" data-testid="graphic-action-step-tabs">
                                        @foreach($actionStepTabs as $stepTab)
                                            <a
                                                href="{{ $buildShowUrl(['step' => $stepTab['key']]) }}"
                                                class="gg-step-tab {{ $stepTab['is_active'] ? 'is-active' : '' }}"
                                            >
                                                {{ $stepTab['label'] }}
                                            </a>
                                        @endforeach
                                    </div>

                                    <div class="gg-flow-column" style="margin-top: 12px;">
                                        @if($activeStep === 'upload')
                                            <div class="gg-step-panel gg-step-panel--upload" data-testid="graphic-step-panel-upload">
                                                <div class="gg-step-panel-head">
                                                    <div>
                                                        <h4 class="gg-step-panel-title">1. Görsel Yükleme</h4>
                                                        <div class="gg-step-panel-sub">Dosyayı seçin, görünürlüğü belirleyin ve görseli doğrudan bu operasyona yükleyin.</div>
                                                    </div>
                                                    <span class="gg-badge gg-badge-green">İlk Adım</span>
                                                </div>
                                                <form method="POST" action="{{ $selectedOperation['upload_url'] }}" enctype="multipart/form-data" class="gg-form-grid">
                                                    @csrf
                                                    <input type="hidden" name="attachment_type" value="graphic_visual">
                                                    <input type="hidden" name="redirect_to" value="admin.graphics.show">
                                                    <input type="hidden" name="order_item_print_graphic_id" value="{{ $selectedOperation['id'] }}">
                                                    <div class="gg-field gg-full">
                                                        <label>Dosya Seç</label>
                                                        <div class="gg-upload-zone" tabindex="0" data-paste-zone data-order-item-print-graphic-id="{{ $selectedOperation['id'] }}">
                                                            <div class="gg-upload-zone-title">{{ $selectedOperation['sequence_code'] }} operasyonuna görsel ekle</div>
                                                            <div class="gg-upload-zone-sub">Ctrl + V ile ekran görüntüsü yapıştırın veya dosyayı bu alana bırakın. Yüklenen görsel büyük önizleme alanında tam çerçeve ile görünür.</div>
                                                            <div class="gg-upload-zone-preview" data-upload-preview></div>
                                                        </div>
                                                        <input
                                                            type="file"
                                                            name="file"
                                                            accept="image/jpeg,image/png,image/webp,application/pdf"
                                                            required
                                                            data-upload-input
                                                            data-order-item-print-graphic-id="{{ $selectedOperation['id'] }}"
                                                        >
                                                    </div>
                                                    <div class="gg-field">
                                                        <label>Görünürlük</label>
                                                        <select name="visibility">
                                                            <option value="internal" @selected($selectedOperation['visibility_default'] !== 'customer_visible')>İç Kayıt</option>
                                                            <option value="customer_visible" @selected($selectedOperation['visibility_default'] === 'customer_visible')>Müşteriye Açık</option>
                                                        </select>
                                                    </div>
                                                    <div class="gg-field">
                                                        <label>Grafik Notu</label>
                                                        <input type="text" name="note" value="{{ $selectedOperation['graphic_note'] }}" placeholder="Grafik notu girin">
                                                    </div>
                                                    <div class="gg-full gg-actions">
                                                        <button type="submit" class="gg-btn gg-btn-primary">Görsel Yükle</button>
                                                    </div>
                                                </form>
                                            </div>
                                        @elseif($activeStep === 'summary')
                                            <div class="gg-step-panel gg-step-panel--summary" data-testid="graphic-step-panel-summary">
                                                <div class="gg-step-panel-head">
                                                    <div>
                                                        <h4 class="gg-step-panel-title">2. Operasyon Özeti</h4>
                                                        <div class="gg-step-panel-sub">Seçili operasyonun görünürlüğü, onay durumu ve notları burada toplanır.</div>
                                                    </div>
                                                    <span class="gg-badge gg-badge-blue">Bilgi</span>
                                                </div>
                                                <div class="gg-mini-grid">
                                                    <div>
                                                        <div class="gg-label">Görünürlük</div>
                                                        <div class="gg-value">{{ $selectedAttachment['visibility_label'] ?? ($selectedOperation['visibility_default'] === 'customer_visible' ? 'Müşteriye Açık' : 'İç Kayıt') }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="gg-label">{{ $customerApprovalEnabled ? 'Müşteri Onayı' : 'Onay Durumu' }}</div>
                                                        <div class="gg-value">{{ $selectedOperation['customer_approval_label'] }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="gg-label">Grafik Notu</div>
                                                        <div class="gg-note">{{ filled($selectedOperation['graphic_note']) ? $selectedOperation['graphic_note'] : 'Henüz not yok' }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="gg-label">Revize Notu</div>
                                                        <div class="gg-note">{{ filled($selectedOperation['customer_note']) ? $selectedOperation['customer_note'] : 'Henüz revize notu yok' }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        @elseif($activeStep === 'approval')
                                            <div class="gg-step-panel gg-step-panel--approval" data-testid="graphic-step-panel-approval">
                                                <div class="gg-step-panel-head">
                                                    <div>
                                                        <h4 class="gg-step-panel-title">{{ $customerApprovalEnabled ? '3. Müşteri Onayı' : '3. Onay Durumu' }}</h4>
                                                        <div class="gg-step-panel-sub">{{ $customerApprovalEnabled ? 'Gönderilecek dosyayı seçin, son durumu takip edin ve gerekirse onayı ilerletin.' : 'Bu operasyonda müşteri onayı kullanılmıyorsa iç akış burada izlenir.' }}</div>
                                                    </div>
                                                    <span class="gg-badge {{ data_get($selectedLatestApprovalRequest, 'status_badge', $selectedOperation['customer_approval_badge']) }}">
                                                        {{ data_get($selectedLatestApprovalRequest, 'status_label', $selectedOperation['customer_approval_label']) }}
                                                    </span>
                                                </div>

                                                @if($customerApprovalEnabled)
                                                    <div class="gg-flow-column">
                                                        <form method="POST" action="{{ $selectedApprovalCard['send_url'] }}" class="gg-action-item gg-form-grid">
                                                            @csrf
                                                            <div class="gg-field gg-full">
                                                                <label>Müşteriye Gönderilecek Dosya</label>
                                                                <select name="attachment_id" {{ !empty($selectedApprovalCard['eligible_attachments']) ? 'required' : '' }} @disabled(empty($selectedApprovalCard['eligible_attachments']))>
                                                                    <option value="">Dosya seçin</option>
                                                                    @foreach($selectedApprovalCard['eligible_attachments'] as $approvalAttachment)
                                                                        <option value="{{ $approvalAttachment['id'] }}">
                                                                            {{ $approvalAttachment['file_name'] }} · {{ $approvalAttachment['attachment_type_label'] }} · {{ $approvalAttachment['uploaded_at'] ?: '-' }}
                                                                        </option>
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                            @if(empty($selectedApprovalCard['eligible_attachments']))
                                                                <div class="gg-full gg-note">Müşteri görünür grafik dosyası yok. Önce uygun görünürlükte görsel yükleyin.</div>
                                                            @endif
                                                            <div class="gg-full gg-actions">
                                                                <button type="submit" class="gg-btn" @disabled(empty($selectedApprovalCard['eligible_attachments'])) data-testid="{{ empty($selectedApprovalCard['eligible_attachments']) ? 'graphic-approval-send-disabled' : 'graphic-approval-send-button' }}">{{ $selectedApprovalCard['send_action_label'] }}</button>
                                                            </div>
                                                        </form>

                                                        @if($selectedLatestApprovalRequest)
                                                            <div class="gg-approval-grid">
                                                                <div class="gg-approval-line">
                                                                    <div class="gg-label">Son Durum</div>
                                                                    <div><span class="gg-badge {{ $selectedLatestApprovalRequest['status_badge'] }}">{{ $selectedLatestApprovalRequest['status_label'] }}</span></div>
                                                                </div>
                                                                <div class="gg-approval-line">
                                                                    <div class="gg-label">Son Gönderim</div>
                                                                    <div class="gg-value">{{ $selectedLatestApprovalRequest['created_at'] ?: '-' }}</div>
                                                                </div>
                                                                <div class="gg-approval-line">
                                                                    <div class="gg-label">Gönderilen Dosya</div>
                                                                    <div class="gg-value">{{ $selectedLatestApprovalRequest['attachment_file_name'] }}</div>
                                                                </div>
                                                                <div class="gg-approval-line">
                                                                    <div class="gg-label">Müşteri Yanıtı</div>
                                                                    <div class="gg-value">{{ $selectedLatestApprovalRequest['responded_at'] ?: '-' }}</div>
                                                                </div>
                                                                @if($selectedLatestApprovalRequest['open_url'])
                                                                    <div class="gg-approval-line gg-full">
                                                                        <div class="gg-label">Onay Bağlantısı</div>
                                                                        <a href="{{ $selectedLatestApprovalRequest['open_url'] }}" class="gg-btn" target="_blank" rel="noopener" data-testid="graphic-approval-open-link-button">Onay Linkini Aç</a>
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        @else
                                                            <div class="gg-guidance">Henüz müşteri onay gönderimi yapılmadı. Önce uygun müşteri görünür dosyayı seçin, ardından müşteriye gönderin.</div>
                                                        @endif

                                                        <form method="POST" action="{{ $selectedOperation['status_url'] }}" class="gg-action-item">
                                                            @csrf
                                                            @method('PATCH')
                                                            <input type="hidden" name="action" value="approved">
                                                            <div class="gg-actions">
                                                                <button type="submit" class="gg-btn">Onaylandı İşaretle</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                @else
                                                    <div class="gg-guidance">Bu operasyonda müşteri onayı özelliği aktif değil. Gerekli değilse doğrudan iç akışla ilerlenir.</div>
                                                    <form method="POST" action="{{ $selectedOperation['status_url'] }}" class="gg-action-item" style="margin-top: 10px;">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="action" value="approved">
                                                        <div class="gg-actions">
                                                            <button type="submit" class="gg-btn">Onaylandı İşaretle</button>
                                                        </div>
                                                    </form>
                                                @endif
                                            </div>
                                        @elseif($activeStep === 'revision')
                                            <div class="gg-step-panel gg-step-panel--revision" data-testid="graphic-step-panel-revision">
                                                <div class="gg-step-panel-head">
                                                    <div>
                                                        <h4 class="gg-step-panel-title">4. Revize</h4>
                                                        <div class="gg-step-panel-sub">Müşteri veya iç ekip revize istediyse notu yazın ve operasyonu revizeye alın.</div>
                                                    </div>
                                                    <span class="gg-badge gg-badge-red">Revize</span>
                                                </div>
                                                <form method="POST" action="{{ $selectedOperation['status_url'] }}" class="gg-action-item gg-form-grid">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="action" value="revision_requested">
                                                    <div class="gg-field gg-full">
                                                        <label>Revize Notu</label>
                                                        <input type="text" name="note" value="{{ $selectedOperation['customer_note'] }}" placeholder="Revize notu girin">
                                                    </div>
                                                    <div class="gg-full gg-actions">
                                                        <button type="submit" class="gg-btn">Revize İstendi</button>
                                                    </div>
                                                </form>
                                            </div>
                                        @else
                                            <div class="gg-step-panel gg-step-panel--ready" data-testid="graphic-step-panel-ready">
                                                <div class="gg-step-panel-head">
                                                    <div>
                                                        <h4 class="gg-step-panel-title">5. Üretime Hazır</h4>
                                                        <div class="gg-step-panel-sub">Onay ve revize durumu uygunsa operasyonu üretime hazır olarak işaretleyin.</div>
                                                    </div>
                                                    <span class="gg-badge {{ $selectedOperation['production_ready_guidance']['badge'] }}">Son Adım</span>
                                                </div>
                                                <div class="gg-guidance">{{ $selectedOperation['production_ready_guidance']['label'] }}</div>
                                                <form method="POST" action="{{ $selectedOperation['status_url'] }}" class="gg-action-item" style="margin-top: 10px;">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="action" value="production_ready">
                                                    <div class="gg-actions">
                                                        <button type="submit" class="gg-btn" @disabled(!$selectedOperation['can_mark_production_ready'])>Üretime Hazır İşaretle</button>
                                                    </div>
                                                </form>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="gg-link-box">Bu iş formu için baskı operasyonu bulunmuyor.</div>
                @endif
            </div>
        </div>

        <div class="gg-card">
            <div class="gg-card-body">
                <h3 class="gg-section-title">Workflow Geçmişi</h3>
                <div class="gg-history" style="margin-top: 12px;">
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
                    <div class="gg-side-section">
                        <div class="gg-side-title">İş Özeti</div>
                        <div class="gg-mini-grid">
                            <div class="gg-full">
                                <div class="gg-label">Sipariş No</div>
                                <div class="gg-value">{{ data_get($orderSnapshot, 'document_number', '-') }}</div>
                            </div>
                            <div class="gg-full">
                                <div class="gg-label">Seçili İş</div>
                                <div class="gg-value">{{ $selectedOperation['title'] ?? $workForm->work_form_number }}</div>
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
                                <div class="gg-label">{{ $customerApprovalEnabled ? 'Müşteri Onayı' : 'Onay Durumu' }}</div>
                                <div><span class="gg-badge gg-badge-gray">{{ $approvalStatusLabel }}</span></div>
                            </div>
                            <div class="gg-full">
                                <div class="gg-label">Sıradaki İş</div>
                                <div class="gg-value">{{ $nextActionLabel }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="gg-side-section">
                        <div class="gg-side-title">Kısayollar</div>
                        <div class="gg-quick-links" data-testid="graphic-quick-links">
                            <a href="{{ route('admin.work-forms.show', $workForm) }}" class="gg-quick-link">
                                <span>İş Formu</span>
                                <span>Aç</span>
                            </a>
                            <a href="{{ route('admin.work-forms.pdf', $workForm) }}" class="gg-quick-link">
                                <span>PDF Oluştur</span>
                                <span>Aç</span>
                            </a>
                            <a href="{{ $orderUrl }}" class="gg-quick-link">
                                <span>Sipariş Detayı</span>
                                <span>Aç</span>
                            </a>
                            <a href="{{ $trackingUrl }}" target="_blank" rel="noopener" class="gg-quick-link">
                                <span>Müşteri Takip Linki</span>
                                <span>Aç</span>
                            </a>
                        </div>
                    </div>

                    <div class="gg-side-section">
                        <div class="gg-side-title">Çalışma Klasörü</div>
                        <div class="gg-link-box">
                            <strong>{{ $systemWorkFolder['status_label'] ?? 'Henüz hazır değil' }}</strong><br>
                            {{ $systemWorkFolder['display_path'] ?? 'Güvenli klasör görünümü bulunmuyor.' }}
                        </div>
                    </div>
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
        const form = zone.closest('form');
        const input = form?.querySelector('[data-upload-input]');
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
