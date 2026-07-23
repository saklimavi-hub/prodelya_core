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
    $selectedAttachments = collect($selectedOperation['attachments'] ?? [])->values();
    $customerVisibleAttachments = $selectedAttachments->where('visibility', 'customer_visible')->values();
    $internalAttachments = $selectedAttachments->where('visibility', '!=', 'customer_visible')->values();
    $selectedApprovalCard = $customerApprovalEnabled && $selectedOperation ? ($selectedOperation['customer_approval'] ?? null) : null;
    $selectedLatestApprovalRequest = data_get($selectedApprovalCard, 'latest_request');
    $orderUrl = route('admin.orders.show', $workForm->order_id);
    $showUrl = route('admin.graphics.show', $workForm);
    $activeStep = $activeActionStep ?? 'summary';
    $activeStepTab = collect($actionStepTabs ?? [])->firstWhere('is_active', true);
    $activeStepLabel = $activeStepTab['label'] ?? 'İşlem Adımı';
    $processDepth = $processDepth ?? [];
    $depthKey = (string) data_get($processDepth, 'key', 'standard');
    $depthLabel = (string) data_get($processDepth, 'label', 'Standart Akış');
    $depthPresentation = (array) data_get($processDepth, 'presentation', []);
    $primaryAction = (array) data_get($processDepth, 'primary_action', []);
    $showOperationOverview = (bool) data_get($depthPresentation, 'show_operation_overview', true);
    $showVisibilityDetails = (bool) data_get($depthPresentation, 'show_visibility_details', true);
    $showCustomerApprovalDetails = (bool) data_get($depthPresentation, 'show_customer_approval_details', true);
    $showRevisionDetails = (bool) data_get($depthPresentation, 'show_revision_details', true);
    $showReadinessDetails = (bool) data_get($depthPresentation, 'show_readiness_details', false);
    $showAttachmentList = (bool) data_get($depthPresentation, 'show_attachment_list', false);
    $showFullActivityHistory = (bool) data_get($depthPresentation, 'show_full_activity_history', false);
    $showOperationStatusSidebar = (bool) data_get($depthPresentation, 'show_operation_status_sidebar', true);
    $showRecentActivitySidebar = (bool) data_get($depthPresentation, 'show_recent_activity_sidebar', false);
    $showCompactHistoryOnly = (bool) data_get($depthPresentation, 'show_compact_history_only', false);
    $historySectionTitle = $showFullActivityHistory ? 'Ayrıntılı Activity History' : 'Kısa Activity History';
    $visibleHistory = collect($workflowHistory ?? [])->take((int) data_get($depthPresentation, 'history_limit', count($workflowHistory ?? [])))->values();
    $latestHistory = $visibleHistory->first();
    $buildShowUrl = function (array $params = []) use ($showUrl, $selectedOperation, $activeStep): string {
        $query = array_filter([
            'operation' => $params['operation'] ?? ($selectedOperation['id'] ?? null),
            'step' => $params['step'] ?? $activeStep,
        ], fn ($value) => filled($value));

        return $query === [] ? $showUrl : $showUrl . '?' . http_build_query($query);
    };
    $customerCompanyName = data_get($customerSnapshot, 'company_name', '-');
    $productReferenceTitle = data_get($productPreview, 'title', 'Ürün Referansı');
    $mainPreviewUrl = $selectedAttachment['original_url'] ?? null;
    $mainPreviewImageUrl = $selectedAttachment['preview_url'] ?? null;
    $mainPreviewOpenUrl = $selectedAttachment['open_url'] ?? null;
    $mainPreviewTitle = $selectedAttachment
        ? (($selectedOperation['sequence_code'] ?? '-') . ' - ' . ($selectedAttachment['file_name'] ?? 'Grafik Çalışması'))
        : 'Grafik Çalışması';
@endphp

<style>
    .pd-page-stack,
    .pd-section-stack {
        display: grid;
        gap: 14px;
    }

    .pd-card-stack {
        display: grid;
        gap: 12px;
    }

    .pd-inline-stack {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .pd-tight-stack {
        display: grid;
        gap: 8px;
    }

    .pd-two-column-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 330px;
        gap: 14px;
        align-items: start;
    }

    .gg-page {
        padding-bottom: 20px;
    }

    .gg-main,
    .gg-side {
        min-width: 0;
    }

    .gg-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
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
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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

    .pd-ui-v1-graphic-detail .gg-lightbox-dialog {
        width: min(96vw, 1500px);
        height: min(92vh, 980px);
        max-height: 92vh;
        background: #fff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
        display: grid;
        grid-template-rows: auto minmax(0, 1fr) auto;
    }

    .pd-ui-v1-graphic-detail .gg-lightbox-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        border-bottom: 1px solid #e5e7eb;
    }

    .pd-ui-v1-graphic-detail .gg-lightbox-body {
        padding: 18px;
        background: #f8fafc;
        min-height: 0;
        overflow: hidden;
    }

    .pd-ui-v1-graphic-detail .pd-graphic-lightbox__viewport {
        display: grid;
        place-items: center;
        width: 100%;
        height: 100%;
        min-height: 0;
        overflow: auto;
        padding: 12px;
        box-sizing: border-box;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fff;
    }

    .pd-ui-v1-graphic-detail .gg-lightbox-foot {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 14px 16px;
        border-top: 1px solid #e5e7eb;
        background: #fff;
    }

    .pd-ui-v1-graphic-detail .pd-graphic-lightbox__image {
        display: block;
        width: 100%;
        height: 100%;
        min-width: 0;
        min-height: 0;
        max-width: calc(96vw - 72px);
        max-height: calc(92vh - 120px);
        object-fit: contain;
        object-position: center center;
        margin: 0 auto;
        background: #fff;
    }

    .pd-ui-v1-graphic-detail .pd-graphic-lightbox__image[hidden] {
        display: none;
    }

    .pd-ui-v1-graphic-detail .pd-graphic-lightbox__status {
        display: grid;
        place-items: center;
        width: 100%;
        min-height: 240px;
        padding: 20px;
        text-align: center;
        color: #64748b;
        font-size: 13px;
        line-height: 1.5;
    }

    .pd-ui-v1-graphic-detail .pd-graphic-lightbox__status.is-error {
        color: #b91c1c;
    }

    .pd-ui-v1-graphic-detail .pd-graphic-lightbox__status[hidden] {
        display: none;
    }

    @media (max-width: 1100px) {
        .pd-two-column-layout {
            grid-template-columns: 1fr;
        }

        .gg-summary-layout,
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

@if(session('warning'))
    <div class="gg-flash gg-flash-warning">{{ session('warning') }}</div>
@endif

<div class="pd-page-stack pd-ui-v1-graphic-detail">
<div class="gg-topbar pd-inline-stack">
    <a href="{{ route('admin.graphics.index') }}" class="gg-btn">Listeye Dön</a>
    <a href="{{ route('admin.work-forms.show', $workForm) }}" class="gg-btn">İş Formu</a>
    <a href="{{ $orderUrl }}" class="gg-btn">Sipariş Detayı</a>
    <a href="{{ route('admin.work-forms.pdf', $workForm) }}" class="gg-btn">PDF Oluştur</a>
    <a href="{{ $trackingUrl }}" target="_blank" rel="noopener" class="gg-btn">Müşteri Takip Linki</a>
</div>

<div class="gg-page pd-two-column-layout {{ data_get($depthPresentation, 'branch_class', 'pd-graphic-depth-standard') }}" data-graphic-process-depth="{{ $depthKey }}" data-graphic-depth-branch="{{ $depthKey }}" data-graphic-depth-marker="true" data-sticky-layout="true">
    <div class="gg-main pd-page-stack" data-graphic-sticky-main="true">
        <div class="gg-card">
            <div class="gg-card-body">
                <div class="gg-header-row">
                    <div>
                        <h3 class="gg-section-title">{{ $depthLabel }}</h3>
                        <div class="gg-note">Grafik ekranı mevcut gerçek operasyon modelini korur; İş Özeti ve görünüm yoğunluğu süreç derinliğine göre değişir.</div>
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
                            <div class="gg-label">Müşteri</div>
                            <div class="gg-value">{{ $customerCompanyName }}</div>
                            <div class="gg-subvalue">Sipariş sahibi firma</div>
                        </div>
                        <div class="gg-summary-card">
                            <div class="gg-label">Seçili Baskı</div>
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
                                data-full-src="{{ $productPreview['original_url'] }}"
                                data-lightbox-title="{{ $productPreview['title'] }}"
                            >
                                <img class="gg-preview-image pd-allow-large" src="{{ $productPreview['preview_url'] }}" alt="{{ $productPreview['title'] }}" loading="lazy">
                            </button>
                        @else
                            <div class="gg-preview-frame gg-preview-frame--summary gg-product-placeholder">Ürün görseli henüz yok</div>
                        @endif
                        <div class="gg-box">
                            <div class="gg-label">Ürün Referansı</div>
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

        @if($depthKey === 'fast' && $selectedOperation)
            <div class="gg-card" data-testid="graphic-depth-fast-panel">
                <div class="gg-card-body">
                    <div class="gg-preview-head" style="margin-bottom: 12px;">
                        <div>
                            <h3 class="gg-section-title">Tek Operasyon Odağı</h3>
                            <div class="gg-note">Hızlı akışta yalnız seçili operasyon, kısa durum ve tek aktif adım görünür.</div>
                        </div>
                        <span class="gg-badge {{ $selectedOperation['status_badge'] }}">{{ $selectedOperation['status_label'] }}</span>
                    </div>
                    <div class="gg-mini-grid">
                        <div>
                            <div class="gg-label">Operasyon</div>
                            <div class="gg-value">{{ $selectedOperation['title'] }}</div>
                        </div>
                        <div>
                            <div class="gg-label">Son hareket</div>
                            <div class="gg-note">{{ data_get($latestHistory, 'label', 'Henüz hareket yok') }}</div>
                        </div>
                        <div>
                            <div class="gg-label">{{ $customerApprovalEnabled ? 'Müşteri Onayı' : 'Onay Durumu' }}</div>
                            <div class="gg-value">{{ $selectedOperation['customer_approval_label'] }}</div>
                        </div>
                        <div>
                            <div class="gg-label">Üretime Hazır</div>
                            <div class="gg-note">{{ data_get($selectedOperation, 'production_ready_guidance.label', 'Ayrı izlenir') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

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

                @if($showOperationOverview && !empty($operationTabs))
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
                                    data-full-src="{{ $mainPreviewUrl }}"
                                    data-lightbox-title="{{ $mainPreviewTitle }}"
                                    data-lightbox-open-url="{{ $mainPreviewOpenUrl }}"
                                    data-testid="graphic-main-preview-frame"
                                >
                                    <img class="gg-preview-image graphic-preview-image pd-allow-large" src="{{ $mainPreviewImageUrl }}" alt="{{ $mainPreviewTitle }}" loading="lazy">
                                </button>
                            @elseif($selectedAttachment && !$selectedAttachment['is_image'])
                                <div class="gg-main-preview-frame gg-preview-empty">Bu grafik çalışması önizleme yerine güvenli dosya açma bağlantısıyla sunulur.</div>
                            @else
                                <div class="gg-main-preview-frame gg-preview-empty">Henüz grafik çalışması yüklenmedi.<br>Bu alan, seçili baskı operasyonuna eklenen son grafik çalışmasını gösterecek.</div>
                            @endif

                            <div class="gg-preview-meta">
                                <div class="gg-box">
                                    <div class="gg-label">Grafik Çalışması</div>
                                    <div class="gg-value">{{ $selectedAttachment['file_name'] ?? 'Henüz grafik çalışması yok' }}</div>
                                    <div class="gg-subvalue">{{ $selectedAttachment['created_at'] ?? 'Ürün resmi grafik yerine kullanılmaz.' }}</div>
                                </div>
                                <div class="gg-box">
                                    <div class="gg-label">Ürün Referansı</div>
                                    <div class="gg-value">{{ data_get($productSnapshot, 'product_name', '-') }}</div>
                                    <div class="gg-subvalue">Kod: {{ data_get($productSnapshot, 'product_code', '-') }}</div>
                                </div>
                                @if($showVisibilityDetails)
                                    <div class="gg-box">
                                        <div class="gg-label">Görünürlük</div>
                                        <div class="gg-value">{{ $selectedAttachment['visibility_label'] ?? ($selectedOperation['visibility_default'] === 'customer_visible' ? 'Müşteriye Açık' : 'İç Kayıt') }}</div>
                                        <div class="gg-subvalue">Müşteriye açılan exact attachment güvenli route ile sunulur.</div>
                                    </div>
                                @else
                                    <div class="gg-box">
                                        <div class="gg-label">Kısa Durum</div>
                                        <div class="gg-value">{{ $selectedOperation['status_label'] }}</div>
                                        <div class="gg-subvalue">{{ $selectedOperation['customer_approval_label'] }}</div>
                                    </div>
                                @endif
                                <div class="gg-box">
                                    <div class="gg-label">Müşteri</div>
                                    <div class="gg-value">{{ $customerCompanyName }}</div>
                                    <div class="gg-subvalue">İş Formu: {{ $workForm->work_form_number }}</div>
                                </div>
                            </div>

                            <div class="gg-file-chip-row">
                                @if($mainPreviewUrl)
                                    <button
                                        type="button"
                                        class="gg-file-chip"
                                        data-lightbox-trigger
                                        data-full-src="{{ $mainPreviewUrl }}"
                                        data-lightbox-title="{{ $mainPreviewTitle }}"
                                        data-lightbox-open-url="{{ $mainPreviewOpenUrl }}"
                                    >
                                        Büyük Önizleme
                                    </button>
                                @else
                                    <span class="gg-file-chip">Grafik Çalışması Yok</span>
                                @endif

                                @if($selectedAttachment && $selectedAttachment['open_url'])
                                    <a href="{{ $selectedAttachment['open_url'] }}" target="_blank" rel="noopener" class="gg-file-chip">Orijinal Boyutta Aç</a>
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

                                    @if($showOperationOverview)<div class="gg-step-tabs" data-testid="graphic-action-step-tabs">
                                        @foreach($actionStepTabs as $stepTab)
                                            <a
                                                href="{{ $buildShowUrl(['step' => $stepTab['key']]) }}"
                                                class="gg-step-tab {{ $stepTab['is_active'] ? 'is-active' : '' }}"
                                            >
                                                {{ $stepTab['label'] }}
                                            </a>
                                        @endforeach
                                    </div>@endif

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
                                                        <button type="submit" class="gg-btn">Görsel Yükle</button>
                                                    </div>
                                                </form>
                                            </div>
                                        @elseif($activeStep === 'summary')
                                            <div class="gg-step-panel gg-step-panel--summary" data-testid="graphic-step-panel-summary">
                                                <div class="gg-step-panel-head">
                                                    <div>
                                                        <h4 class="gg-step-panel-title">2. İş Özeti</h4>
                                                        <div class="gg-step-panel-sub">Seçili operasyonun görünürlüğü, onay durumu ve notları burada toplanır.</div>
                                                    </div>
                                                    <span class="gg-badge gg-badge-blue">Bilgi</span>
                                                </div>
                                                <div class="gg-mini-grid">
                                                    @if($showVisibilityDetails)
                                                        <div>
                                                            <div class="gg-label">Görünürlük</div>
                                                            <div class="gg-value">{{ $selectedAttachment['visibility_label'] ?? ($selectedOperation['visibility_default'] === 'customer_visible' ? 'Müşteriye Açık' : 'İç Kayıt') }}</div>
                                                        </div>
                                                    @endif
                                                    @if($showCustomerApprovalDetails)
                                                        <div>
                                                            <div class="gg-label">{{ $customerApprovalEnabled ? 'Müşteri Onayı' : 'Onay Durumu' }}</div>
                                                            <div class="gg-value">{{ $selectedOperation['customer_approval_label'] }}</div>
                                                        </div>
                                                    @endif
                                                    <div>
                                                        <div class="gg-label">Grafik Notu</div>
                                                        <div class="gg-note">{{ filled($selectedOperation['graphic_note']) ? $selectedOperation['graphic_note'] : 'Henüz not yok' }}</div>
                                                    </div>
                                                    @if($showRevisionDetails)
                                                        <div>
                                                            <div class="gg-label">Revize Notu</div>
                                                            <div class="gg-note">{{ filled($selectedOperation['customer_note']) ? $selectedOperation['customer_note'] : 'Henüz revize notu yok' }}</div>
                                                        </div>
                                                    @endif
                                                    @if($showReadinessDetails)
                                                        <div>
                                                            <div class="gg-label">Üretime Hazır</div>
                                                            <div class="gg-note">{{ data_get($selectedOperation, 'production_ready_guidance.label', 'Ayrı izlenir') }}</div>
                                                        </div>
                                                    @endif
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
                @if($showAttachmentList)
                        <div class="gg-card" style="margin-top: 14px;">
                            <div class="gg-card-body" data-testid="graphic-controlled-attachment-block">
                                <div class="gg-preview-head" style="margin-bottom: 10px;">
                                    <div>
                                        <h3 class="gg-section-title">Visibility ve Dosya Ayrımı</h3>
                                        <div class="gg-note">Customer-visible ve iç ekip dosyaları ayrıştırılır; file_path gösterilmez.</div>
                                    </div>
                                </div>
                                <div class="gg-summary-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                                    <div class="gg-box"><div class="gg-label">Müşteriye Açık</div><div class="gg-subvalue">{{ $customerVisibleAttachments->count() }} kayıt</div>@foreach($customerVisibleAttachments as $item)<div class="gg-note">{{ $item['file_name'] }} · {{ $item['uploaded_at'] ?: '-' }}</div>@endforeach</div>
                                    <div class="gg-box"><div class="gg-label">İç Kayıt</div><div class="gg-subvalue">{{ $internalAttachments->count() }} kayıt</div>@foreach($internalAttachments as $item)<div class="gg-note">{{ $item['file_name'] }} · {{ $item['uploaded_at'] ?: '-' }}</div>@endforeach</div>
                                </div>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="gg-link-box">Bu iş formu için baskı operasyonu bulunmuyor.</div>
                @endif
            </div>
        </div>

        @if(! $showCompactHistoryOnly)
        <div class="gg-card" data-testid="graphic-history-block">
            <div class="gg-card-body">
                <h3 class="gg-section-title">{{ $historySectionTitle }}</h3>
                <div class="gg-history" style="margin-top: 12px;">
                    @foreach($visibleHistory as $history)
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
        @endif
    </div>

    <div class="gg-side pd-section-stack" data-sticky-sidebar="true" data-sticky-responsive="stack-under-1100">
        <div class="gg-sticky pd-section-stack">
            <div class="gg-card">
                <div class="gg-card-body pd-card-stack">
                    <div class="gg-side-section">
                        <div class="gg-side-title">Grafik Özeti</div>
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

                    <div class="gg-side-section" data-canonical-focus-panel="true">
                        <div class="gg-side-title">Aktif Odak</div>
                        <div class="gg-mini-grid">
                            <div class="gg-full"><div class="gg-label">Şu an</div><div class="gg-value">{{ $selectedOperation['status_label'] ?? $generalGraphicStatusLabel }}</div></div>
                            <div class="gg-full"><div class="gg-label">Sıradaki işlem</div><div class="gg-value">{{ $primaryAction['label'] ?? $nextActionLabel }}</div></div>
                            <div class="gg-full"><div class="gg-label">{{ $customerApprovalEnabled ? 'Müşteri Onayı' : 'Onay Durumu' }}</div><div class="gg-value">{{ $selectedOperation['customer_approval_label'] ?? $approvalStatusLabel }}</div></div>
                            <div class="gg-full"><div class="gg-label">Üretime Hazır</div><div class="gg-note">{{ data_get($selectedOperation, 'production_ready_guidance.label', 'Ayrı izlenir') }}</div></div>
                        </div>
                        <div class="gg-actions" style="margin-top: 12px;">
                            <a href="{{ $primaryAction['url'] ?? $showUrl }}" class="gg-btn gg-btn-primary pd-graphic-depth-primary-cta">{{ $primaryAction['label'] ?? 'Grafik Operasyonunu Aç' }}</a>
                        </div>
                    </div>

                    @if($showOperationStatusSidebar && !empty($operationTabs))
                    <div class="gg-side-section" data-testid="graphic-operation-status-sidebar">
                        <div class="gg-side-title">Operasyon Durumu</div>
                        <div class="gg-mini-grid">
                            @foreach($operationTabs as $operationTab)
                                <div class="gg-full"><div class="gg-label">{{ $operationTab['sequence_code'] }}</div><div><span class="gg-badge {{ $operationTab['status_badge'] }}">{{ $operationTab['status_label'] }}</span></div></div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    @if($showReadinessDetails && $showRecentActivitySidebar)
                    <div class="gg-side-section" data-testid="graphic-readiness-sidebar">
                        <div class="gg-side-title">Readiness / Onay Özeti</div>
                        <div class="pd-tight-stack">
                            <div class="gg-link-box">
                                <strong>Üretime Hazır</strong><br>
                                {{ data_get($selectedOperation, 'production_ready_guidance.label', 'Ayrı izlenir') }}
                            </div>
                            @if($showCustomerApprovalDetails)
                            <div class="gg-link-box">
                                <strong>{{ $customerApprovalEnabled ? 'Müşteri Onayı' : 'Onay Durumu' }}</strong><br>
                                {{ data_get($selectedLatestApprovalRequest, 'status_label', $selectedOperation['customer_approval_label'] ?? $approvalStatusLabel) }}
                            </div>
                            @endif
                            @if($showVisibilityDetails)
                            <div class="gg-link-box">
                                <strong>Görünürlük</strong><br>
                                {{ $selectedAttachment['visibility_label'] ?? ($selectedOperation['visibility_default'] === 'customer_visible' ? 'Müşteriye Açık' : 'İç Kayıt') }}
                            </div>
                            @endif
                        </div>
                    </div>
                    @endif

                    @if($showRecentActivitySidebar)
                    <div class="gg-side-section" data-testid="graphic-recent-activities-sidebar">
                        <div class="gg-side-title">Son Faaliyetler</div>
                        @foreach(collect($workflowHistory)->take(3) as $history)
                            <div class="gg-link-box"><strong>{{ $history['label'] }}</strong><br>{{ $history['at'] ?: '-' }} · {{ $history['visibility'] }}</div>
                        @endforeach
                    </div>
                    @endif

                    <div class="gg-side-section">
                        <div class="gg-side-title">Hızlı İşlemler</div>
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
</div>

<div class="gg-lightbox" data-lightbox-modal>
    <div class="gg-lightbox-dialog">
        <div class="gg-lightbox-head">
            <div>
                <div class="gg-label">Büyük Önizleme</div>
                <div class="gg-value" data-lightbox-title>Grafik Çalışması</div>
            </div>
            <button type="button" class="gg-btn" data-lightbox-close>Kapat</button>
        </div>
        <div class="gg-lightbox-body">
            <div class="pd-graphic-lightbox__viewport">
                <div class="pd-graphic-lightbox__status" data-lightbox-status hidden>Görsel yükleniyor...</div>
                <img src="" alt="" class="gg-lightbox-image pd-graphic-lightbox__image" data-lightbox-image hidden>
            </div>
        </div>
        <div class="gg-lightbox-foot">
            <a href="#" class="gg-btn" target="_blank" rel="noopener" data-lightbox-open-original hidden>Orijinal Boyutta Aç</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const lightbox = document.querySelector('[data-lightbox-modal]');
    const lightboxImage = lightbox?.querySelector('[data-lightbox-image]');
    const lightboxTitle = lightbox?.querySelector('[data-lightbox-title]');
    const lightboxOpenOriginal = lightbox?.querySelector('[data-lightbox-open-original]');
    const lightboxStatus = lightbox?.querySelector('[data-lightbox-status]');

    const setLightboxStatus = function (message, isError = false) {
        if (!lightboxStatus) {
            return;
        }

        lightboxStatus.textContent = message;
        lightboxStatus.hidden = false;
        lightboxStatus.classList.toggle('is-error', isError === true);
    };

    const resetLightbox = function () {
        if (lightboxImage) {
            lightboxImage.hidden = true;
            lightboxImage.removeAttribute('src');
            lightboxImage.alt = '';
        }

        if (lightboxStatus) {
            lightboxStatus.hidden = true;
            lightboxStatus.classList.remove('is-error');
            lightboxStatus.textContent = 'Görsel yükleniyor...';
        }

        if (lightboxOpenOriginal) {
            lightboxOpenOriginal.href = '#';
            lightboxOpenOriginal.hidden = true;
        }
    };

    lightboxImage?.addEventListener('load', function () {
        if (!lightboxImage) {
            return;
        }

        lightboxImage.hidden = false;
        if (lightboxStatus) {
            lightboxStatus.hidden = true;
            lightboxStatus.classList.remove('is-error');
        }
    });

    lightboxImage?.addEventListener('error', function () {
        if (lightboxImage) {
            lightboxImage.hidden = true;
            lightboxImage.removeAttribute('src');
        }

        setLightboxStatus('Grafik görseli yüklenemedi. Orijinal dosyayı açmayı deneyin.', true);
    });

    document.querySelectorAll('[data-lightbox-trigger]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            if (!lightbox || !lightboxImage || !lightboxTitle) {
                return;
            }

            const fullSrc = trigger.getAttribute('data-full-src');
            const title = trigger.getAttribute('data-lightbox-title') || 'Görsel';
            const openUrl = trigger.getAttribute('data-lightbox-open-url') || fullSrc;

            if (!fullSrc) {
                return;
            }

            resetLightbox();
            setLightboxStatus('Görsel yükleniyor...');
            lightboxImage.alt = title;
            lightboxTitle.textContent = title;
            if (lightboxOpenOriginal) {
                lightboxOpenOriginal.href = openUrl || '#';
                lightboxOpenOriginal.hidden = !openUrl;
            }
            lightbox.classList.add('is-open');
            lightboxImage.src = fullSrc;
        });
    });

    lightbox?.addEventListener('click', function (event) {
        if (event.target === lightbox || event.target.hasAttribute('data-lightbox-close')) {
            lightbox.classList.remove('is-open');
            resetLightbox();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && lightbox?.classList.contains('is-open')) {
            lightbox.classList.remove('is-open');
            resetLightbox();
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
