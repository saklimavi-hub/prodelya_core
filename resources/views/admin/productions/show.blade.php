@extends('layouts.prodelya-admin')

@section('title', 'Üretim Detayı')
@section('page_title', 'Üretim Detayı')

@section('content')
@php
    $snapshot = is_array($production->production_snapshot) ? $production->production_snapshot : [];
    $plannedQuantity = (float) $production->planned_quantity;
    $completedQuantity = (float) $production->completed_quantity;
    $remainingQuantity = (float) $production->remaining_quantity;
    $progressPercent = $plannedQuantity > 0 ? min(100, max(0, round(($completedQuantity / $plannedQuantity) * 100))) : 0;
    $productionTypeLabel = data_get($snapshot, 'production_type_label')
        ?: ($production->safeProductionTypeLabel() ?: 'Belirlenmedi');
    $orderRoute = $production->order ? route('admin.orders.show', $production->order) : null;
    $workFormRoute = $production->workForm ? route('admin.work-forms.show', $production->workForm) : null;
    $procurementRoute = $production->workForm?->procurement ? route('admin.procurements.show', $production->workForm->procurement) : null;
    $photosCount = $production->workForm?->productionPhotos()->count() ?? 0;
    $operatorName = $production->assignedUser?->name ?: 'Planlanmadı';
    $formattedPlannedQuantity = \App\Models\OrderItemPrintProduction::formatDisplayQuantity($plannedQuantity);
    $formattedCompletedQuantity = \App\Models\OrderItemPrintProduction::formatDisplayQuantity($completedQuantity);
    $formattedRemainingQuantity = \App\Models\OrderItemPrintProduction::formatDisplayQuantity($remainingQuantity);
    $statusTone = match ($production->production_status) {
        \App\Models\OrderItemPrintProduction::STATUS_COMPLETED => 'green',
        \App\Models\OrderItemPrintProduction::STATUS_PROBLEMATIC => 'red',
        \App\Models\OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
        \App\Models\OrderItemPrintProduction::STATUS_QUALITY_CONTROL => 'amber',
        default => 'blue',
    };
@endphp

<div class="prd-show-shell">
    <div class="prd-show-main">
        @if(session('success'))
            <div class="pd-alert pd-alert-success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="pd-alert pd-alert-warning">{{ $errors->first() }}</div>
        @endif

        <section class="prd-topbar">
            <div class="prd-topbar-meta">
                <span class="prd-topbar-chip">Üretim No: {{ $production->id }}</span>
                <div class="prd-topbar-copy">
                    <div class="prd-topbar-line">
                        <strong>{{ $snapshot['product_name'] ?? ($production->orderItem?->product_name ?: 'Üretim Kaydı') }}</strong>
                        @if(filled($snapshot['print_sequence'] ?? null))
                            <span class="prd-topbar-sep">/</span>
                            <span>{{ $snapshot['print_sequence'] }}</span>
                        @endif
                        @if(filled($snapshot['print_type'] ?? null))
                            <span class="prd-topbar-sep">/</span>
                            <span>{{ $snapshot['print_type'] }}</span>
                        @endif
                    </div>
                    <div class="prd-topbar-subline">
                        <span>Sipariş No: {{ $snapshot['order_number'] ?? ($production->order?->document_number ?: 'Belirtilmedi') }}</span>
                        @if(filled($snapshot['work_form_number'] ?? null))
                            <span class="prd-topbar-sep">•</span>
                            <span>İş Formu No: {{ $snapshot['work_form_number'] }}</span>
                        @endif
                        <span class="prd-topbar-sep">•</span>
                        <span>{{ $productionTypeLabel }}</span>
                        <span class="prd-topbar-sep">•</span>
                        <span class="prd-status-pill prd-status-pill-{{ $statusTone }}">{{ $production->safeStatusLabel() }}</span>
                    </div>
                </div>
            </div>

            <div class="prd-topbar-actions">
                @if($orderRoute)
                    <a href="{{ $orderRoute }}" class="btn btn-sm btn-outline-primary">Siparişi Aç</a>
                @endif
                @if($workFormRoute)
                    <a href="{{ $workFormRoute }}" class="btn btn-sm btn-outline-primary">İş Formu</a>
                @endif
                <a href="{{ $tabUrl('islemler') }}" class="btn btn-sm btn-primary">İşlem Geçmişi</a>
            </div>
        </section>

        <ul class="nav nav-tabs tabs-nav">
            <li class="tabs-nav-item {{ $activeTab === 'genel' ? 'active' : '' }}">
                <a href="{{ $tabUrl('genel') }}">Genel Özet</a>
            </li>
            <li class="tabs-nav-item {{ $activeTab === 'ic-uretim' ? 'active' : '' }}">
                <a href="{{ $tabUrl('ic-uretim') }}">İç Üretim</a>
            </li>
            <li class="tabs-nav-item {{ $activeTab === 'dis-uretim' ? 'active' : '' }}">
                <a href="{{ $tabUrl('dis-uretim') }}">Dış Üretim / Fason</a>
            </li>
            <li class="tabs-nav-item {{ $activeTab === 'islemler' ? 'active' : '' }}">
                <a href="{{ $tabUrl('islemler') }}">İşlemler</a>
            </li>
            <li class="tabs-nav-item {{ $activeTab === 'fotograflar' ? 'active' : '' }}">
                <a href="{{ $tabUrl('fotograflar') }}">Fotoğraflar</a>
            </li>
            <li class="tabs-nav-item {{ $activeTab === 'gecmis' ? 'active' : '' }}">
                <a href="{{ $tabUrl('gecmis') }}">Geçmiş</a>
            </li>
        </ul>

        <div class="prd-tab-panel">
            @if($activeTab === 'genel')
                @include('admin.productions.partials._production_summary')
            @elseif($activeTab === 'ic-uretim')
                @include('admin.productions.partials._production_internal')
            @elseif($activeTab === 'dis-uretim')
                @include('admin.productions.partials._production_external')
            @elseif($activeTab === 'islemler')
                @include('admin.productions.partials._production_actions')
            @elseif($activeTab === 'fotograflar')
                @include('admin.productions.partials._production_photos')
            @elseif($activeTab === 'gecmis')
                @include('admin.productions.partials._production_history')
            @endif
        </div>
    </div>

    <aside class="prd-show-sidebar">
        <section class="prd-side-card">
            <h3 class="prd-side-title">Özet Bilgi</h3>

            <div class="prd-side-rows">
                <div class="prd-side-row"><span>Üretim No</span><strong>{{ $production->id }}</strong></div>
                <div class="prd-side-row"><span>Üretim Türü</span><strong>{{ $productionTypeLabel }}</strong></div>
                <div class="prd-side-row"><span>Planlanan</span><strong>{{ $formattedPlannedQuantity }}</strong></div>
                <div class="prd-side-row"><span>Tamamlanan</span><strong>{{ $formattedCompletedQuantity }}</strong></div>
                <div class="prd-side-row"><span>Kalan</span><strong>{{ $formattedRemainingQuantity }}</strong></div>
                <div class="prd-side-row"><span>Üretim Durumu</span><strong class="prd-status-{{ $statusTone }}">{{ $production->safeStatusLabel() }}</strong></div>
                <div class="prd-side-row"><span>Son Güncelleme</span><strong>{{ optional($production->updated_at)->format('d.m.Y H:i') ?: '-' }}</strong></div>
                <div class="prd-side-row"><span>Operatör</span><strong>{{ $operatorName }}</strong></div>
            </div>

            <div class="prd-mini-progress">
                <div class="prd-mini-progress-head">
                    <span>İlerleme</span>
                    <strong>%{{ $progressPercent }}</strong>
                </div>
                <div class="prd-progress-track">
                    <div class="prd-progress-fill" style="width: {{ $progressPercent }}%;"></div>
                </div>
            </div>
        </section>

        <section class="prd-side-card">
            <h3 class="prd-side-title">Hızlı İşlemler</h3>
            <div class="prd-side-actions-grid">
                <a href="{{ $tabUrl('islemler') }}" class="prd-quick-link">Durumu Güncelle</a>
                <a href="{{ $tabUrl('islemler') }}#atama-guncelle" class="prd-quick-link">Atama Güncelle</a>
                <a href="{{ $tabUrl('fotograflar') }}" class="prd-quick-link">Fotoğraf Yükle</a>
                <a href="{{ $tabUrl('islemler') }}#hizli-not" class="prd-quick-link">Not Ekle</a>
            </div>
        </section>

        <section class="prd-side-card">
            <h3 class="prd-side-title">Takip</h3>
            <div class="prd-side-actions-grid">
                <a href="{{ route('admin.productions.index', ['pool' => 'ready']) }}" class="prd-quick-link">Görevlerim</a>
                <a href="{{ route('admin.notifications.index') }}" class="prd-quick-link">Bildirimler</a>
                <a href="{{ route('admin.dashboard') }}" class="prd-quick-link">Raporlar</a>
            </div>
        </section>
    </aside>
</div>

<style>
    .prd-show-shell {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 320px;
        gap: 18px;
        align-items: start;
    }
    .prd-show-main,
    .prd-side-card,
    .prd-tab-card,
    .prd-card {
        background: #fff;
        border: 1px solid #e7edf4;
        border-radius: 10px;
    }
    .prd-topbar,
    .prd-side-card,
    .prd-tab-panel {
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.04);
    }
    .prd-topbar {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: center;
        padding: 16px 18px;
        margin-bottom: 14px;
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        border: 1px solid #e7edf4;
        border-radius: 10px;
    }
    .prd-topbar-meta {
        display: flex;
        gap: 14px;
        align-items: center;
        min-width: 0;
    }
    .prd-topbar-chip {
        display: inline-flex;
        align-items: center;
        padding: 6px 10px;
        border-radius: 999px;
        background: #eef4ff;
        color: #2856d6;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }
    .prd-topbar-copy {
        min-width: 0;
    }
    .prd-topbar-line {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
        color: #182230;
        font-size: 16px;
    }
    .prd-topbar-subline {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
        margin-top: 4px;
        color: #667085;
        font-size: 12px;
        font-weight: 600;
    }
    .prd-topbar-sep {
        color: #98a2b3;
    }
    .prd-status-pill {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
    }
    .prd-status-pill-blue {
        background: #edf2ff;
        color: #2856d6;
    }
    .prd-status-pill-green {
        background: #ecfdf3;
        color: #15803d;
    }
    .prd-status-pill-amber {
        background: #fff7ed;
        color: #c2410c;
    }
    .prd-status-pill-red {
        background: #fef3f2;
        color: #b42318;
    }
    .prd-topbar-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: flex-end;
    }
    .tabs-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 0;
        border-bottom: 1px solid #e8ecf1;
        margin: 0 0 16px;
        padding: 0;
        list-style: none;
    }
    .tabs-nav .tabs-nav-item {
        margin: 0;
    }
    .tabs-nav .tabs-nav-item a {
        display: inline-block;
        text-decoration: none;
        padding: 10px 14px;
        color: #697586;
        border-radius: 8px 8px 0 0;
        font-size: 13px;
        font-weight: 700;
    }
    .tabs-nav .tabs-nav-item.active a {
        color: #2563eb;
        background: #edf2ff;
    }
    .prd-tab-panel {
        border: 1px solid #e7edf4;
        border-radius: 10px;
        background: #fff;
        padding: 16px;
    }
    .prd-show-sidebar {
        position: sticky;
        top: 20px;
        display: grid;
        gap: 14px;
    }
    .prd-side-card {
        padding: 16px;
    }
    .prd-side-title {
        margin: 0 0 12px;
        font-size: 15px;
        font-weight: 700;
        color: #182230;
    }
    .prd-side-rows {
        display: grid;
        gap: 10px;
    }
    .prd-side-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: flex-start;
        padding-bottom: 10px;
        border-bottom: 1px dashed #e4e7ec;
        color: #475467;
        font-size: 12px;
    }
    .prd-side-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }
    .prd-side-row strong {
        color: #182230;
        text-align: right;
        font-size: 12px;
    }
    .prd-status-blue { color: #2856d6; }
    .prd-status-green { color: #15803d; }
    .prd-status-amber { color: #c2410c; }
    .prd-status-red { color: #b42318; }
    .prd-mini-progress {
        margin-top: 14px;
        padding-top: 14px;
        border-top: 1px solid #e4e7ec;
    }
    .prd-mini-progress-head {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 8px;
        color: #475467;
        font-size: 12px;
        font-weight: 700;
    }
    .prd-progress-track {
        width: 100%;
        height: 8px;
        border-radius: 999px;
        background: #edf2f7;
        overflow: hidden;
    }
    .prd-progress-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #2856d6 0%, #4f7cff 100%);
    }
    .prd-quick-links {
        display: grid;
        gap: 8px;
    }
    .prd-side-actions-grid {
        display: grid;
        gap: 8px;
    }
    .prd-quick-link,
    .prd-inline-link {
        color: #2856d6;
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
    }
    .prd-quick-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 38px;
        border: 1px solid #d7e3ff;
        border-radius: 8px;
        background: #f8fbff;
    }
    .prd-quick-link:hover {
        background: #eef4ff;
    }
    .prd-card {
        padding: 16px;
    }
    .prd-section-title {
        margin: 0 0 14px;
        font-size: 18px;
        font-weight: 700;
        color: #182230;
    }
    .prd-section-subtitle {
        margin: -6px 0 14px;
        color: #667085;
        font-size: 13px;
    }
    .prd-metric-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }
    .prd-metric-card,
    .prd-info-card,
    .prd-step-card,
    .prd-note-card,
    .prd-photo-card,
    .prd-table-card,
    .prd-form-card {
        border: 1px solid #e7edf4;
        border-radius: 10px;
        background: #fbfdff;
        padding: 14px;
    }
    .prd-metric-label,
    .prd-info-label,
    .prd-table-label {
        display: block;
        margin-bottom: 6px;
        color: #667085;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .02em;
    }
    .prd-metric-value,
    .prd-info-value {
        color: #182230;
        font-size: 17px;
        font-weight: 700;
        line-height: 1.35;
    }
    .prd-grid-2 {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }
    .prd-grid-3 {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }
    .prd-grid-4 {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }
    .prd-grid-5 {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 12px;
    }
    .prd-stack {
        display: grid;
        gap: 14px;
    }
    .prd-summary-band {
        display: grid;
        grid-template-columns: minmax(0, 1.65fr) minmax(0, 1fr);
        gap: 14px;
    }
    .prd-snapshot-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 12px;
    }
    .prd-snapshot-item {
        border: 1px solid #e7edf4;
        border-radius: 10px;
        background: #fbfdff;
        padding: 12px;
    }
    .prd-snapshot-label {
        display: block;
        margin-bottom: 6px;
        color: #667085;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .02em;
    }
    .prd-snapshot-value {
        color: #182230;
        font-size: 15px;
        font-weight: 700;
        line-height: 1.35;
    }
    .prd-snapshot-note {
        margin-top: 4px;
        color: #667085;
        font-size: 12px;
    }
    .prd-summary-hero {
        display: grid;
        grid-template-columns: 112px minmax(0, 1fr);
        gap: 14px;
        align-items: center;
    }
    .prd-split {
        display: grid;
        grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr);
        gap: 14px;
    }
    .prd-product-preview {
        display: grid;
        grid-template-columns: 116px minmax(0, 1fr);
        gap: 12px;
        align-items: start;
    }
    .prd-product-image {
        width: 116px;
        min-height: 104px;
        border-radius: 10px;
        overflow: hidden;
        background: #f3f6fb;
        border: 1px solid #e7edf4;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .prd-product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .prd-product-empty {
        color: #98a2b3;
        font-size: 12px;
        font-weight: 700;
        text-align: center;
        padding: 10px;
    }
    .prd-product-lines {
        display: grid;
        gap: 7px;
    }
    .prd-line-item {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        border-bottom: 1px dashed #e5e7eb;
        padding-bottom: 6px;
        color: #374151;
        font-size: 13px;
    }
    .prd-line-item span:first-child {
        color: #667085;
    }
    .prd-line-item:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }
    .prd-summary-stat-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .prd-summary-stat {
        border: 1px solid #e7edf4;
        border-radius: 10px;
        background: #fbfdff;
        padding: 12px;
    }
    .prd-summary-stat-label {
        display: block;
        margin-bottom: 6px;
        color: #667085;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .02em;
    }
    .prd-summary-stat-value {
        color: #182230;
        font-size: 22px;
        font-weight: 700;
        line-height: 1.2;
    }
    .prd-summary-stat-meta {
        margin-top: 4px;
        color: #667085;
        font-size: 12px;
    }
    .prd-step-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 12px;
    }
    .prd-step-card {
        min-height: 176px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        justify-content: space-between;
    }
    .prd-step-card.is-active {
        border-color: #5b7cff;
        background: #f5f8ff;
        box-shadow: inset 0 0 0 1px rgba(91, 124, 255, 0.16);
    }
    .prd-step-card.is-done {
        border-color: #cae7d4;
        background: #f4fbf6;
    }
    .prd-step-card.is-muted {
        background: #fcfcfd;
    }
    .prd-step-top {
        display: grid;
        gap: 8px;
    }
    .prd-step-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 999px;
        background: #edf2ff;
        color: #2856d6;
        font-size: 12px;
        font-weight: 700;
    }
    .prd-step-title {
        color: #182230;
        font-size: 14px;
        font-weight: 700;
    }
    .prd-step-copy {
        color: #667085;
        font-size: 12px;
        line-height: 1.45;
    }
    .prd-step-meta {
        color: #98a2b3;
        font-size: 11px;
        font-weight: 600;
    }
    .prd-step-action {
        margin-top: auto;
    }
    .prd-touch-button {
        min-height: 46px;
        font-size: 14px;
        font-weight: 700;
        border-radius: 10px;
    }
    .prd-touch-input {
        min-height: 42px;
        font-size: 14px;
    }
    .prd-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .prd-form-card textarea.form-control {
        min-height: 92px;
    }
    .prd-form-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }
    .prd-table-wrap {
        overflow-x: auto;
    }
    .prd-table {
        width: 100%;
        border-collapse: collapse;
    }
    .prd-table th,
    .prd-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #edf2f7;
        text-align: left;
        vertical-align: top;
        font-size: 12px;
    }
    .prd-table th {
        color: #667085;
        font-weight: 700;
        background: #fafcff;
    }
    .prd-note-list {
        margin: 0;
        padding-left: 18px;
        color: #475467;
        font-size: 13px;
        line-height: 1.55;
    }
    .prd-empty {
        padding: 18px;
        border: 1px dashed #d0d5dd;
        border-radius: 10px;
        background: #fcfcfd;
        color: #667085;
        font-size: 13px;
        text-align: center;
    }
    .prd-soft-message {
        padding: 12px 14px;
        border-radius: 8px;
        background: #f8fafc;
        color: #475467;
        font-size: 13px;
        line-height: 1.5;
    }
    @media (max-width: 1200px) {
        .prd-show-shell {
            grid-template-columns: 1fr;
        }
        .prd-show-sidebar {
            position: static;
        }
        .prd-step-grid,
        .prd-metric-grid,
        .prd-grid-4,
        .prd-grid-5,
        .prd-summary-band,
        .prd-snapshot-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 820px) {
        .prd-topbar {
            flex-direction: column;
            align-items: stretch;
        }
        .tabs-nav {
            overflow-x: auto;
            flex-wrap: nowrap;
            white-space: nowrap;
        }
        .prd-grid-2,
        .prd-grid-3,
        .prd-grid-4,
        .prd-grid-5,
        .prd-metric-grid,
        .prd-summary-band,
        .prd-snapshot-grid,
        .prd-summary-stat-grid,
        .prd-step-grid,
        .prd-form-grid,
        .prd-split {
            grid-template-columns: 1fr;
        }
        .prd-summary-hero {
            grid-template-columns: 1fr;
        }
        .prd-product-preview {
            grid-template-columns: 1fr;
        }
        .prd-product-image {
            width: 100%;
        }
    }
</style>
@endsection
