@extends('layouts.prodelya-admin')

@section('title', $order->document_number)
@section('page_title', $order->document_number)
@section('page_subtitle', ($order->customer?->legal_name ?: 'Müşteri bilgisi yok') . ' · Sipariş Detayı')

@section('page_actions')
    <div class="flex gap-3">
        <a href="{{ route('admin.orders.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
        @if($order->workForms->first())
            <a href="{{ route('admin.work-forms.show', $order->workForms->first()) }}" class="pd-btn pd-btn-light">İş Formu</a>
        @endif
        @if($financialDataVisible)
            <a href="{{ route('admin.finance.show', $order) }}" class="pd-btn pd-btn-primary">Finans Özeti</a>
        @endif
    </div>
@endsection

@section('content')
@php
    $orderStatusLabel = $overview['general_status_label'] ?? 'Sipariş';
    $operationStatusLabel = $overview['operation_status_label'] ?? 'İzleniyor';
    $operationBadge = $overview['operation_status_badge'] ?? 'gray';
    $generalBadge = $overview['general_status_badge'] ?? 'gray';
    $workForm = $order->workForms->first();
    $workFormPdfUrl = $workForm ? route('admin.work-forms.pdf', $workForm) : null;
    $trackingUrl = ($workForm && filled($workForm->public_tracking_token))
        ? route('admin.orders.tracking.open', ['order' => $order->id, 'workForm' => $workForm->id])
        : null;
    $sourceQuoteUrl = $order->sourceQuote ? route('admin.promotion-quotes.show', $order->sourceQuote) : null;
    $historyRows = $order->workForms
        ->flatMap(fn ($form) => $form->activityLogs)
        ->sortByDesc('created_at')
        ->values();
    $deliveryInfo = $deliveryTab['delivery_info'] ?? [];
    $latestLabelBatch = $deliveryTab['latest_label_batch'] ?? null;
@endphp

<style>
    .pd-order-layout { display:grid; grid-template-columns:minmax(0, 2fr) minmax(280px, 1fr); gap:16px; align-items:start; }
    .pd-order-stack { display:grid; gap:14px; }
    .pd-order-tabs { display:flex; gap:8px; flex-wrap:wrap; }
    .pd-order-tab {
        display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:0 14px;
        border:1px solid var(--pd-line); border-radius:8px; background:#fff; color:#344054; text-decoration:none; font-size:13px; font-weight:600;
    }
    .pd-order-tab.is-active { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
    .pd-order-tab:hover { border-color:#bfd4ef; color:#1d4ed8; }
    .pd-order-grid-2 { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; }
    .pd-order-grid-3 { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:14px; }
    .pd-order-grid-4 { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:14px; }
    .pd-order-kpi { border:1px solid var(--pd-line); border-radius:8px; background:#fff; padding:14px; }
    .pd-order-kpi-label { color:var(--pd-muted); font-size:12px; }
    .pd-order-kpi-value { margin-top:6px; font-weight:700; color:#111827; }
    .pd-order-summary-panel { position:sticky; top:16px; display:grid; gap:14px; }
    .pd-order-mini-list, .pd-order-history-list, .pd-order-step-list, .pd-order-package-list { display:grid; gap:10px; }
    .pd-order-list-row, .pd-order-step-row, .pd-order-history-row, .pd-order-package-card {
        border:1px solid var(--pd-line); border-radius:8px; background:#fff; padding:12px;
    }
    .pd-order-list-row { display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:12px; align-items:start; }
    .pd-order-step-row { display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:12px; align-items:center; }
    .pd-order-history-row { display:grid; grid-template-columns:140px minmax(0, 1fr); gap:12px; }
    .pd-order-package-items { margin-top:10px; display:grid; gap:8px; }
    .pd-order-form-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
    .pd-order-form-grid .full { grid-column:1 / -1; }
    .pd-order-form-grid label { display:block; margin-bottom:5px; color:#475467; font-size:12px; font-weight:700; }
    .pd-order-form-note { padding:12px; border:1px dashed var(--pd-line); border-radius:8px; background:#fbfcfe; color:#475467; font-size:12px; line-height:1.55; }
    .pd-order-item-table { width:100%; border-collapse:collapse; }
    .pd-order-item-table th, .pd-order-item-table td { border-bottom:1px solid var(--pd-line); padding:10px 8px; vertical-align:top; text-align:left; }
    .pd-order-item-table th { color:#475467; font-size:12px; font-weight:700; }
    .pd-order-alert-grid { display:grid; gap:10px; }
    .pd-order-package-builder { display:grid; gap:12px; }
    .pd-order-package-builder .pd-order-package-card { background:#f8fafc; }
    .pd-order-package-toolbar { display:flex; gap:8px; justify-content:space-between; align-items:center; flex-wrap:wrap; }
    .pd-order-package-actions { display:flex; gap:8px; flex-wrap:wrap; }
    @media (max-width: 1100px) {
        .pd-order-layout { grid-template-columns:1fr; }
        .pd-order-summary-panel { position:static; }
        .pd-order-grid-4 { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 900px) {
        .pd-order-grid-2, .pd-order-grid-3, .pd-order-grid-4, .pd-order-form-grid, .pd-order-history-row, .pd-order-list-row, .pd-order-step-row { grid-template-columns:1fr; }
    }
</style>

@if(session('success'))
    <div class="pd-alert">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="pd-alert-warning">{{ session('error') }}</div>
@endif

@if($errors->any())
    <div class="pd-alert-warning">{{ $errors->first() }}</div>
@endif

<div class="pd-order-layout">
    <div class="pd-order-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <div>
                        <div class="text-xs" style="color:var(--pd-muted);">Sekmeli Görünüm</div>
                        <div style="margin-top:6px; font-weight:700;">Sipariş akışını sekmelerden yönetin</div>
                        <div style="margin-top:6px; color:var(--pd-muted);">Genel özet, operasyon modülleri, teslimat planı ve finans görünümü ayrı alanlarda sade tutulur.</div>
                    </div>
                    <div class="pd-order-tabs" role="tablist" aria-label="Sipariş sekmeleri">
                        @foreach($orderTabs as $tabKey => $tabLabel)
                            <a
                                href="{{ route('admin.orders.show', ['order' => $order, 'tab' => $tabKey]) }}"
                                class="pd-order-tab {{ $activeOrderTab === $tabKey ? 'is-active' : '' }}"
                                aria-current="{{ $activeOrderTab === $tabKey ? 'page' : 'false' }}"
                            >
                                {{ $tabLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        @if($activeOrderTab === 'genel')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Genel Özet</h3>
                    <p class="pd-card-subtitle">Siparişin genel durumu, sıradaki iş ve operasyon özetleri tek alanda toplanır.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-grid-4">
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">Sipariş No</div><div class="pd-order-kpi-value">{{ $order->document_number }}</div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">Müşteri</div><div class="pd-order-kpi-value">{{ $order->customer?->legal_name ?: 'Müşteri bilgisi yok' }}</div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">Sipariş Tarihi</div><div class="pd-order-kpi-value">{{ $overview['order_date_label'] ?? '-' }}</div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">Teslim Tarihi</div><div class="pd-order-kpi-value">{{ $overview['delivery_date_label'] ?? '-' }}</div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">Sipariş Durumu</div><div class="pd-order-kpi-value"><span class="pd-badge pd-badge-{{ $generalBadge }}">{{ $orderStatusLabel }}</span></div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">Operasyon Durumu</div><div class="pd-order-kpi-value"><span class="pd-badge pd-badge-{{ $operationBadge }}">{{ $operationStatusLabel }}</span></div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">Kalem Sayısı</div><div class="pd-order-kpi-value">{{ $order->items->count() }}</div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">Sıradaki İş</div><div class="pd-order-kpi-value">{{ $overview['next_action_label'] ?? 'Siparişi incele' }}</div></div>
                    </div>

                    <div class="pd-order-alert-grid" style="margin-top:14px;">
                        <div class="pd-order-form-note">
                            <strong>Operasyon notu:</strong> Teslimat tamamlandığında sipariş operasyon akışından çıkarılabilir. Finans açık ise Cari Ekstre ve Finans ekranında tahsilat takibi sürer.
                        </div>
                        @if($financialDataVisible && $financeOverview)
                            <div class="pd-order-form-note">
                                <strong>Kısa finans durumu:</strong>
                                {{ data_get($financeOverview, 'overall.status_label', 'Finans açık') }}
                                · Kalan Bakiye:
                                @include('admin.current-accounts._money-display', [
                                    'label' => number_format((float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'),
                                    'amount' => (float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0),
                                ])
                            </div>
                        @endif
                    </div>

                    @if($financialDataVisible && $financeOverview)
                        <div class="pd-order-grid-4" style="margin-top:14px;">
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Müşteri Borcu</div>
                                <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.debit_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.debit_amount', 0)])</div>
                            </div>
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Tahsil Edilen</div>
                                <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.collected_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.collected_amount', 0)])</div>
                            </div>
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Kalan Bakiye</div>
                                <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0)])</div>
                            </div>
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Karşı Borçlar</div>
                                <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format(((float) data_get($financeOverview, 'supplier_debts.remaining_amount', 0)) + ((float) data_get($financeOverview, 'subcontractor_debts.remaining_amount', 0)), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => ((float) data_get($financeOverview, 'supplier_debts.remaining_amount', 0)) + ((float) data_get($financeOverview, 'subcontractor_debts.remaining_amount', 0))])</div>
                            </div>
                        </div>
                    @endif

                    <div class="pd-order-grid-2" style="margin-top:14px;">
                        @foreach($moduleCards as $moduleCard)
                            <div class="pd-order-list-row">
                                <div>
                                    <div class="text-xs" style="color:var(--pd-muted);">{{ $moduleCard['title'] }}</div>
                                    <div style="margin-top:6px; font-weight:700;">{{ $moduleCard['status'] }}</div>
                                    <div style="margin-top:4px; color:var(--pd-muted);">{{ $moduleCard['copy'] }}</div>
                                </div>
                                <div style="display:grid; gap:8px; justify-items:end;">
                                    <span class="pd-badge pd-badge-{{ $moduleCard['badge'] }}">{{ $moduleCard['title'] }}</span>
                                    @if(!empty($moduleCard['url']))
                                        <a href="{{ $moduleCard['url'] }}" class="pd-btn pd-btn-light pd-btn-sm">Aç</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'is-formu')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">İş Formu</h3>
                    <p class="pd-card-subtitle">İş formu kaydı, PDF ve müşteri takip bağlantısı güvenli şekilde burada toplanır.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-grid-3">
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">İş Formu Durumu</div><div class="pd-order-kpi-value">{{ $workForm ? 'Hazır' : 'Henüz oluşmadı' }}</div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">İş Formu No</div><div class="pd-order-kpi-value">{{ $workForm?->work_form_number ?: '-' }}</div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">Versiyon</div><div class="pd-order-kpi-value">{{ $workForm?->version ?: '-' }}</div></div>
                    </div>

                    <div class="pd-order-package-actions" style="margin-top:14px;">
                        @if($workForm)
                            <a href="{{ route('admin.work-forms.show', $workForm) }}" class="pd-btn pd-btn-primary">İş Formunu Aç</a>
                        @endif
                        @if($workFormPdfUrl)
                            <a href="{{ $workFormPdfUrl }}" class="pd-btn pd-btn-light">PDF İndir</a>
                        @endif
                        @if($trackingUrl)
                            <a href="{{ $trackingUrl }}" class="pd-btn pd-btn-light">Müşteri Takip Ekranı</a>
                        @endif
                    </div>

                    <table class="pd-order-item-table" style="margin-top:14px;">
                        <thead>
                            <tr>
                                <th>Kalem</th>
                                <th>Ürün</th>
                                <th>Durum</th>
                                <th>İş Formu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($itemRows as $itemRow)
                                <tr>
                                    <td>{{ $itemRow['sequence'] }}</td>
                                    <td>
                                        <div style="font-weight:600;">{{ $itemRow['product_name'] }}</div>
                                        <div class="text-xs" style="color:var(--pd-muted);">{{ $itemRow['product_code'] }}</div>
                                    </td>
                                    <td>{{ $itemRow['operation_status'] }}</td>
                                    <td>{{ $itemRow['work_form_number'] ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-sm" style="color:var(--pd-muted);">Kalem özeti bulunmuyor.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'grafik')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Grafik</h3>
                    <p class="pd-card-subtitle">Grafik hazırlığı, müşteri onayı ve revize görünümü kısa özet olarak izlenir.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-grid-2">
                        @forelse($order->workForms as $workFormRow)
                            @php
                                $graphicSnapshot = is_array($workFormRow->graphic_snapshot) ? $workFormRow->graphic_snapshot : [];
                                $graphicStatusLabel = data_get($graphicSnapshot, 'public_status_label')
                                    ?: data_get($graphicSnapshot, 'status_label')
                                    ?: 'Grafik bekliyor';
                            @endphp
                            <div class="pd-order-list-row">
                                <div>
                                    <div style="font-weight:700;">{{ $workFormRow->work_form_number ?: 'İş Formu' }}</div>
                                    <div style="margin-top:4px;">{{ $graphicStatusLabel }}</div>
                                    <div class="text-xs" style="margin-top:4px; color:var(--pd-muted);">{{ $workFormRow->orderItem?->product_name ?: 'Kalem bilgisi yok' }}</div>
                                </div>
                                <div style="display:grid; gap:8px; justify-items:end;">
                                    <span class="pd-badge pd-badge-amber">Grafik</span>
                                    <a href="{{ route('admin.graphics.show', $workFormRow) }}" class="pd-btn pd-btn-light pd-btn-sm">Grafik Ekranına Git</a>
                                </div>
                            </div>
                        @empty
                            <div class="pd-order-form-note">Grafik kaydı bulunmuyor.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'tedarik')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Tedarik</h3>
                    <p class="pd-card-subtitle">Tedarik ihtiyaçları, kalan miktar ve tedarikçi yönlendirmeleri burada izlenir.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-package-actions" style="margin-bottom:14px;">
                        <a href="{{ route('admin.procurements.index') }}" class="pd-btn pd-btn-light">Tedarik Ekranına Git</a>
                        @if($order->procurements->first())
                            <a href="{{ route('admin.procurements.show', $order->procurements->first()) }}" class="pd-btn pd-btn-primary">Talebi Aç</a>
                        @endif
                    </div>
                    <table class="pd-order-item-table">
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>Tedarik Durumu</th>
                                <th>Kalan Miktar</th>
                                <th>Aksiyon</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($order->procurements as $procurement)
                                <tr>
                                    <td>{{ $procurement->orderItem?->product_name ?: 'Kalem bilgisi yok' }}</td>
                                    <td>{{ $procurement->safeStatusLabel() }}</td>
                                    <td>{{ rtrim(rtrim(number_format((float) $procurement->remaining_quantity, 4, ',', '.'), '0'), ',') }}</td>
                                    <td><a href="{{ route('admin.procurements.show', $procurement) }}" class="pd-btn pd-btn-light pd-btn-sm">Tedarik Kaydını Aç</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-sm" style="color:var(--pd-muted);">Tedarik ihtiyacı görünmüyor.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'uretim')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Üretim</h3>
                    <p class="pd-card-subtitle">Baskı ve üretim satırları, operasyon durumu ve kalite kontrol uyarıları burada görünür.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-package-actions" style="margin-bottom:14px;">
                        <a href="{{ route('admin.productions.index') }}" class="pd-btn pd-btn-light">Üretim Ekranına Git</a>
                        @if($order->printProductions->first())
                            <a href="{{ route('admin.productions.show', $order->printProductions->first()) }}" class="pd-btn pd-btn-primary">Üretim Kaydını Aç</a>
                        @endif
                    </div>
                    <table class="pd-order-item-table">
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>Üretim Durumu</th>
                                <th>Tamamlanan</th>
                                <th>Kalan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($order->printProductions as $production)
                                <tr>
                                    <td>{{ $production->orderItemPrint?->orderItem?->product_name ?: 'Kalem bilgisi yok' }}</td>
                                    <td>{{ $production->safeStatusLabel() }}</td>
                                    <td>{{ rtrim(rtrim(number_format((float) $production->completed_quantity, 4, ',', '.'), '0'), ',') }}</td>
                                    <td>{{ rtrim(rtrim(number_format((float) $production->remaining_quantity, 4, ',', '.'), '0'), ',') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-sm" style="color:var(--pd-muted);">Üretim kaydı görünmüyor.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'teslimat')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Teslimat</h3>
                    <p class="pd-card-subtitle">Teslimata hazırlama, koli planı, etiket ve teslim bilgisi tek operasyon alanında yönetilir.</p>
                </div>
                <div class="pd-card-body">
                    @if(!($deliveryTab['planning_available'] ?? true) && !empty($deliveryTab['planning_notice']))
                        <div class="pd-order-form-note" style="margin-bottom:14px;">{{ $deliveryTab['planning_notice'] }}</div>
                    @endif

                    <div class="pd-order-step-list">
                        @foreach($deliveryTab['steps'] as $step)
                            <div class="pd-order-step-row">
                                <div>
                                    <div style="font-weight:700;">{{ $step['title'] }}</div>
                                    <div class="text-xs" style="margin-top:4px; color:var(--pd-muted);">{{ $step['detail'] }}</div>
                                </div>
                                <span class="pd-badge pd-badge-{{ $step['status_tone'] }}">{{ $step['status_label'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    @if($deliveryTab['completion_note'])
                        <div class="pd-order-form-note" style="margin-top:14px;">{{ $deliveryTab['completion_note'] }}</div>
                    @endif

                    <div class="pd-order-grid-2" style="margin-top:14px;">
                        <div class="pd-card" style="border:none; box-shadow:none; background:#f8fafc;">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Teslimata Hazırla</h3>
                                <p class="pd-card-subtitle">Hazır miktarlar teslimat planı için referans alınır.</p>
                            </div>
                            <div class="pd-card-body">
                                <table class="pd-order-item-table">
                                    <thead>
                                        <tr>
                                            <th>Ürün</th>
                                            <th>Sipariş Miktarı</th>
                                            <th>Hazır</th>
                                            <th>Henüz Hazır Değil</th>
                                            <th>Durum</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($deliveryTab['item_readiness'] as $readiness)
                                            <tr>
                                                <td>
                                                    <div style="font-weight:600;">{{ $readiness['product_name'] }}</div>
                                                    <div class="text-xs" style="color:var(--pd-muted);">{{ $readiness['product_code'] }}</div>
                                                </td>
                                                <td>{{ $readiness['ordered_quantity_label'] }}</td>
                                                <td>{{ $readiness['ready_quantity_label'] }}</td>
                                                <td>{{ $readiness['waiting_quantity_label'] }}</td>
                                                <td><span class="pd-badge pd-badge-{{ $readiness['status_tone'] }}">{{ $readiness['status_label'] }}</span></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="pd-card" style="border:none; box-shadow:none; background:#f8fafc;">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Koli ve Etiket Özeti</h3>
                                <p class="pd-card-subtitle">Koli adedi kadar etiket hazırlanır.</p>
                            </div>
                            <div class="pd-card-body">
                                <div class="pd-order-grid-3">
                                    <div class="pd-order-kpi"><div class="pd-order-kpi-label">Koli Sayısı</div><div class="pd-order-kpi-value">{{ $deliveryTab['package_count'] }}</div></div>
                                    <div class="pd-order-kpi"><div class="pd-order-kpi-label">Etiket Adedi</div><div class="pd-order-kpi-value">{{ $deliveryTab['label_count'] }}</div></div>
                                    <div class="pd-order-kpi"><div class="pd-order-kpi-label">Teslim Durumu</div><div class="pd-order-kpi-value">{{ $deliveryTab['is_delivered'] ? 'Operasyon tamamlandı' : 'Teslim bilgisi bekliyor' }}</div></div>
                                </div>
                                @if($latestLabelBatch)
                                    <div class="pd-order-form-note" style="margin-top:14px;">
                                        <strong>Son etiket partisi:</strong> {{ \App\Models\OrderDeliveryLabelBatch::templateLabels()[$latestLabelBatch->template_type] ?? 'Etiket' }}
                                        · {{ $deliveryTab['label_batches'][0]['page_summary'] ?? '' }}
                                    </div>
                                @endif
                                <div class="pd-order-package-actions" style="margin-top:14px;">
                                    @if($deliveryTab['label_count'] > 0)
                                        <a href="{{ route('admin.orders.delivery-labels.print', ['order' => $order, 'batch' => $latestLabelBatch?->id]) }}" class="pd-btn pd-btn-light" target="_blank">Etiketleri Yazdır</a>
                                    @endif
                                    @if($order->deliveries->first())
                                        <a href="{{ route('admin.deliveries.show', $order->deliveries->first()) }}" class="pd-btn pd-btn-light">Teslimat Kaydını Aç</a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pd-card" style="margin-top:14px; border:none; box-shadow:none; background:#f8fafc;">
                        <div class="pd-card-header">
                            <h3 class="pd-card-title">Koli Planı</h3>
                            <p class="pd-card-subtitle">Bir ürün birden fazla koliye bölünebilir, bir koli içinde birden fazla ürün olabilir.</p>
                        </div>
                        <div class="pd-card-body">
                            <div class="pd-order-form-note">Tek ürün 2 koli, iki ürün tek koli veya çok ürün çok koli senaryoları desteklenir. Boş koli satırı kaydedilmez.</div>

                            @if($deliveryTab['package_rows'] !== [])
                                <div class="pd-order-package-list" style="margin-top:14px;">
                                    @foreach($deliveryTab['package_rows'] as $packageRow)
                                        <div class="pd-order-package-card">
                                            <div class="pd-order-package-toolbar">
                                                <div>
                                                    <div style="font-weight:700;">{{ $packageRow['package_label'] }}</div>
                                                    <div class="text-xs" style="margin-top:4px; color:var(--pd-muted);">{{ $packageRow['package_type_label'] }} · Toplam {{ $packageRow['total_quantity_label'] }}</div>
                                                </div>
                                                <span class="pd-badge pd-badge-{{ $packageRow['status_tone'] }}">{{ $packageRow['status_label'] }}</span>
                                            </div>
                                            <div class="pd-order-package-items">
                                                @foreach($packageRow['items'] as $packageItem)
                                                    <div class="pd-order-list-row" style="padding:8px 10px;">
                                                        <div>{{ $packageItem['product_name'] }} <span class="text-xs" style="color:var(--pd-muted);">{{ $packageItem['product_code'] }}</span></div>
                                                        <div>{{ $packageItem['quantity_label'] }}</div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <form method="POST" action="{{ route('admin.orders.delivery-packages.store', $order) }}" style="margin-top:14px;">
                                @csrf
                                <div class="pd-order-package-builder" data-package-builder>
                                    <div data-package-list>
                                        @php($renderedPackages = max(count(old('packages', [])), max(1, count($deliveryTab['package_rows'] ?? []))))
                                        @for($packageIndex = 0; $packageIndex < $renderedPackages; $packageIndex++)
                                            <div class="pd-order-package-card" data-package-card>
                                                <div class="pd-order-package-toolbar">
                                                    <div style="font-weight:700;">Koli <span data-package-number>{{ $packageIndex + 1 }}</span></div>
                                                    <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-remove-package>Kaldır</button>
                                                </div>
                                                <div class="pd-order-form-grid" style="margin-top:12px;">
                                                    <div>
                                                        <label>Koli Etiketi</label>
                                                        <input type="text" name="packages[{{ $packageIndex }}][package_label]" class="pd-form-control" value="{{ old('packages.' . $packageIndex . '.package_label', $deliveryTab['package_rows'][$packageIndex]['package_label'] ?? '') }}" placeholder="Örn. Koli 1">
                                                    </div>
                                                    <div>
                                                        <label>Koli Tipi</label>
                                                        <select name="packages[{{ $packageIndex }}][package_type]" class="pd-form-control">
                                                            @foreach($deliveryTab['package_type_options'] as $optionValue => $optionLabel)
                                                                <option value="{{ $optionValue }}" @selected(old('packages.' . $packageIndex . '.package_type', 'box') === $optionValue)>{{ $optionLabel }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="full">
                                                        <label>Koli Notu</label>
                                                        <input type="text" name="packages[{{ $packageIndex }}][notes]" class="pd-form-control" value="{{ old('packages.' . $packageIndex . '.notes') }}" placeholder="İsteğe bağlı not">
                                                    </div>
                                                    <div class="full">
                                                        <table class="pd-order-item-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>Ürün</th>
                                                                    <th>Hazır Adet</th>
                                                                    <th>Koliye Yazılacak Adet</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach($deliveryTab['item_readiness'] as $itemIndex => $readiness)
                                                                    <tr>
                                                                        <td>
                                                                            <input type="hidden" name="packages[{{ $packageIndex }}][items][{{ $itemIndex }}][order_item_id]" value="{{ $readiness['order_item_id'] }}">
                                                                            <div style="font-weight:600;">{{ $readiness['product_name'] }}</div>
                                                                            <div class="text-xs" style="color:var(--pd-muted);">{{ $readiness['product_code'] }}</div>
                                                                        </td>
                                                                        <td>{{ $readiness['ready_quantity_label'] }}</td>
                                                                        <td><input type="number" step="0.0001" min="0" name="packages[{{ $packageIndex }}][items][{{ $itemIndex }}][quantity]" class="pd-form-control" value="{{ old('packages.' . $packageIndex . '.items.' . $itemIndex . '.quantity') }}" placeholder="0"></td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        @endfor
                                    </div>
                                    <div class="pd-order-package-actions">
                                        <button type="button" class="pd-btn pd-btn-light" data-add-package>Yeni Koli Ekle</button>
                                        <button type="submit" class="pd-btn pd-btn-primary" @disabled(!($deliveryTab['planning_available'] ?? true))>Koli Planını Kaydet</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="pd-order-grid-2" style="margin-top:14px;">
                        <div class="pd-card" style="border:none; box-shadow:none; background:#f8fafc;">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Etiket Oluştur</h3>
                                <p class="pd-card-subtitle">A4 veya rulo etiket şablonuyla tek seferde baskı için hazırlık yapılır.</p>
                            </div>
                            <div class="pd-card-body">
                                <form method="POST" action="{{ route('admin.orders.delivery-labels.store', $order) }}">
                                    @csrf
                                    <div class="pd-order-form-grid">
                                        <div>
                                            <label>Etiket Şablonu</label>
                                            <select name="template_type" class="pd-form-control" data-label-template>
                                                @foreach($deliveryTab['label_template_options'] as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}" @selected(old('template_type', \App\Models\OrderDeliveryLabelBatch::TEMPLATE_A4_1_4) === $optionValue)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label>Etiket Adedi</label>
                                            <input type="text" class="pd-form-control" value="{{ $deliveryTab['label_count'] }}" readonly>
                                        </div>
                                        <div data-roll-field style="{{ old('template_type') === \App\Models\OrderDeliveryLabelBatch::TEMPLATE_ROLL ? '' : 'display:none;' }}">
                                            <label>Etiket Eni (mm)</label>
                                            <input type="number" step="0.01" min="1" name="roll_width_mm" class="pd-form-control" value="{{ old('roll_width_mm', '100') }}">
                                        </div>
                                        <div data-roll-field style="{{ old('template_type') === \App\Models\OrderDeliveryLabelBatch::TEMPLATE_ROLL ? '' : 'display:none;' }}">
                                            <label>Etiket Boyu (mm)</label>
                                            <input type="number" step="0.01" min="1" name="roll_height_mm" class="pd-form-control" value="{{ old('roll_height_mm', '70') }}">
                                        </div>
                                        <div data-roll-field style="{{ old('template_type') === \App\Models\OrderDeliveryLabelBatch::TEMPLATE_ROLL ? '' : 'display:none;' }}">
                                            <label>Etiket Ara Mesafesi (mm)</label>
                                            <input type="number" step="0.01" min="0" name="roll_gap_mm" class="pd-form-control" value="{{ old('roll_gap_mm', '3') }}">
                                        </div>
                                    </div>
                                    <div class="pd-order-package-actions" style="margin-top:14px;">
                                        <button type="submit" class="pd-btn pd-btn-primary" @disabled(!($deliveryTab['planning_available'] ?? true))>Etiket Partisi Oluştur</button>
                                        @if($latestLabelBatch)
                                            <a href="{{ route('admin.orders.delivery-labels.print', ['order' => $order, 'batch' => $latestLabelBatch->id]) }}" class="pd-btn pd-btn-light" target="_blank">Etiketleri Aç</a>
                                        @elseif(!($deliveryTab['planning_available'] ?? true))
                                            <a href="{{ route('admin.orders.delivery-labels.print', ['order' => $order]) }}" class="pd-btn pd-btn-light" target="_blank">Etiket Görünümünü Aç</a>
                                        @endif
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="pd-card" style="border:none; box-shadow:none; background:#f8fafc;">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Teslim Bilgisi</h3>
                                <p class="pd-card-subtitle">Teslim yöntemi, kişi, takip ve teslim notu burada kaydedilir.</p>
                            </div>
                            <div class="pd-card-body">
                                <form method="POST" action="{{ route('admin.orders.delivery-info.update', $order) }}">
                                    @csrf
                                    <div class="pd-order-form-grid">
                                        <div>
                                            <label>Teslim Yöntemi</label>
                                            <select name="delivery_method" class="pd-form-control">
                                                <option value="">Seçin</option>
                                                @foreach($deliveryTab['method_options'] as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}" @selected(old('delivery_method', $deliveryInfo['delivery_method'] ?? '') === $optionValue)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label>Ticari Teslim Tipi</label>
                                            <input type="text" name="delivery_type" class="pd-form-control" value="{{ old('delivery_type', $order->delivery_type) }}" placeholder="Kargo / Ambar / Kurye">
                                        </div>
                                        <div>
                                            <label>Teslim Alan Kişi</label>
                                            <input type="text" name="recipient_name" class="pd-form-control" value="{{ old('recipient_name', $deliveryInfo['recipient_name'] ?? '') }}">
                                        </div>
                                        <div>
                                            <label>Telefon</label>
                                            <input type="text" name="recipient_phone" class="pd-form-control" value="{{ old('recipient_phone', $deliveryInfo['recipient_phone'] ?? '') }}">
                                        </div>
                                        <div>
                                            <label>Kargo / Ambar Adı</label>
                                            <input type="text" name="carrier_name" class="pd-form-control" value="{{ old('carrier_name', $deliveryInfo['carrier_name'] ?? '') }}">
                                        </div>
                                        <div>
                                            <label>Takip No</label>
                                            <input type="text" name="tracking_number" class="pd-form-control" value="{{ old('tracking_number', $deliveryInfo['tracking_number'] ?? '') }}">
                                        </div>
                                        <div>
                                            <label>Belge No</label>
                                            <input type="text" name="delivery_document_no" class="pd-form-control" value="{{ old('delivery_document_no', $deliveryInfo['delivery_document_no'] ?? '') }}">
                                        </div>
                                        <div class="full">
                                            <label>Teslim Notu</label>
                                            <textarea name="delivery_note" class="pd-form-control" rows="3">{{ old('delivery_note', $deliveryInfo['delivery_note'] ?? '') }}</textarea>
                                        </div>
                                    </div>
                                    <div class="pd-order-package-actions" style="margin-top:14px;">
                                        <button type="submit" class="pd-btn pd-btn-primary">Teslim Bilgisini Kaydet</button>
                                    </div>
                                </form>

                                <form method="POST" action="{{ route('admin.orders.delivery.complete', $order) }}" style="margin-top:14px;" onsubmit="return confirm('Teslimat tamamlandığında sipariş operasyon akışından çıkarılır. Finans bakiyesi açıksa takip devam eder. Devam etmek istiyor musunuz?');">
                                    @csrf
                                    <input type="hidden" name="delivery_method" value="{{ $deliveryInfo['delivery_method'] ?? '' }}">
                                    <input type="hidden" name="recipient_name" value="{{ $deliveryInfo['recipient_name'] ?? '' }}">
                                    <input type="hidden" name="delivery_document_no" value="{{ $deliveryInfo['delivery_document_no'] ?? '' }}">
                                    <input type="hidden" name="tracking_number" value="{{ $deliveryInfo['tracking_number'] ?? '' }}">
                                    <input type="hidden" name="carrier_name" value="{{ $deliveryInfo['carrier_name'] ?? '' }}">
                                    <input type="hidden" name="delivery_note" value="{{ $deliveryInfo['delivery_note'] ?? '' }}">
                                    <button type="submit" class="pd-btn pd-btn-success">Teslim Edildi</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'finans')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Finans</h3>
                    <p class="pd-card-subtitle">Müşteri borcu, tahsilat ve karşı borçlar finans özetinden izlenir.</p>
                </div>
                <div class="pd-card-body">
                    @if($financialDataVisible && $financeOverview)
                        <div class="pd-order-grid-4">
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Müşteri Borcu</div>
                                <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.debit_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.debit_amount', 0)])</div>
                            </div>
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Tahsil Edilen</div>
                                <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.collected_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.collected_amount', 0)])</div>
                            </div>
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Kalan Bakiye</div>
                                <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0)])</div>
                            </div>
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Finans Durumu</div>
                                <div class="pd-order-kpi-value">{{ data_get($financeOverview, 'overall.status_label', 'Finans açık') }}</div>
                            </div>
                        </div>
                        <div class="pd-order-package-actions" style="margin-top:14px;">
                            <a href="{{ route('admin.finance.show', $order) }}" class="pd-btn pd-btn-primary">Finans Özeti</a>
                            @if($customerCurrentAccount)
                                <a href="{{ route('admin.current-accounts.transactions.index', $customerCurrentAccount) }}" class="pd-btn pd-btn-light">Cari Ekstre</a>
                            @endif
                        </div>
                    @else
                        <div class="pd-order-form-note">Finans tutarları yalnız yetkili kullanıcıya gösterilir.</div>
                    @endif
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'gecmis')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Geçmiş</h3>
                    <p class="pd-card-subtitle">Siparişin grafik, tedarik, üretim, teslimat ve iş formu aksiyonları zaman sırasıyla görünür.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-history-list">
                        @forelse($historyRows as $historyRow)
                            <div class="pd-order-history-row">
                                <div class="text-xs" style="color:var(--pd-muted);">{{ optional($historyRow->created_at)->format('d.m.Y H:i') ?: '-' }}</div>
                                <div>
                                    <div style="font-weight:700;">{{ str_replace('_', ' ', \Illuminate\Support\Str::headline($historyRow->action_type)) }}</div>
                                    <div style="margin-top:4px;">{{ $historyRow->note ?: 'İşlem kaydı' }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="pd-order-form-note">Geçmiş kaydı henüz görünmüyor.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </div>

    <aside class="pd-order-summary-panel">
        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Sağ Özet</h3>
                <p class="pd-card-subtitle">Tekrarsız kısa görünüm</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-order-mini-list">
                    <div class="pd-order-list-row">
                        <div>
                            <div class="text-xs" style="color:var(--pd-muted);">Müşteri</div>
                            <div style="margin-top:6px; font-weight:700;">{{ $order->customer?->legal_name ?: 'Müşteri bilgisi yok' }}</div>
                        </div>
                        <span class="pd-badge pd-badge-{{ $generalBadge }}">{{ $orderStatusLabel }}</span>
                    </div>
                    <div class="pd-order-list-row">
                        <div>
                            <div class="text-xs" style="color:var(--pd-muted);">Operasyon</div>
                            <div style="margin-top:6px; font-weight:700;">{{ $operationStatusLabel }}</div>
                        </div>
                        <span class="pd-badge pd-badge-{{ $operationBadge }}">{{ $overview['next_action_label'] ?? 'İzle' }}</span>
                    </div>
                    <div class="pd-order-list-row">
                        <div>
                            <div class="text-xs" style="color:var(--pd-muted);">Teslimat</div>
                            <div style="margin-top:6px; font-weight:700;">{{ $deliveryInfo['summary'] ?? 'Teslim bilgisi yok' }}</div>
                        </div>
                        <span class="pd-badge pd-badge-{{ $deliveryTab['is_delivered'] ? 'green' : 'gray' }}">{{ $deliveryTab['is_delivered'] ? 'Tamamlandı' : 'Bekliyor' }}</span>
                    </div>
                </div>

                <div class="pd-order-package-actions" style="margin-top:14px;">
                    <a href="{{ route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']) }}" class="pd-btn pd-btn-light">Teslimata Git</a>
                    @if($sourceQuoteUrl)
                        <a href="{{ $sourceQuoteUrl }}" class="pd-btn pd-btn-light">Teklifi Aç</a>
                    @endif
                </div>
            </div>
        </div>
    </aside>
</div>

<template id="pd-order-package-template">
    <div class="pd-order-package-card" data-package-card>
        <div class="pd-order-package-toolbar">
            <div style="font-weight:700;">Koli <span data-package-number></span></div>
            <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-remove-package>Kaldır</button>
        </div>
    </div>
</template>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const packageBuilder = document.querySelector('[data-package-builder]');
        const packageList = packageBuilder?.querySelector('[data-package-list]');
        const addPackageButton = packageBuilder?.querySelector('[data-add-package]');

        const renumberPackages = function () {
            if (!packageList) {
                return;
            }

            [...packageList.querySelectorAll('[data-package-card]')].forEach(function (card, index) {
                const packageNumber = index + 1;
                const numberTarget = card.querySelector('[data-package-number]');
                if (numberTarget) {
                    numberTarget.textContent = String(packageNumber);
                }

                [...card.querySelectorAll('input, select, textarea')].forEach(function (field) {
                    const name = field.getAttribute('name');
                    if (!name) {
                        return;
                    }

                    field.setAttribute('name', name.replace(/packages\[\d+\]/, 'packages[' + index + ']'));
                });
            });
        };

        packageList?.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-remove-package]');
            if (!removeButton) {
                return;
            }

            const cards = packageList.querySelectorAll('[data-package-card]');
            if (cards.length <= 1) {
                return;
            }

            removeButton.closest('[data-package-card]')?.remove();
            renumberPackages();
        });

        addPackageButton?.addEventListener('click', function () {
            if (!packageList) {
                return;
            }

            const cards = packageList.querySelectorAll('[data-package-card]');
            const clone = cards[cards.length - 1]?.cloneNode(true);

            if (!clone) {
                return;
            }

            clone.querySelectorAll('input').forEach(function (field) {
                if (field.type === 'hidden') {
                    return;
                }

                field.value = '';
            });

            clone.querySelectorAll('textarea').forEach(function (field) {
                field.value = '';
            });

            clone.querySelectorAll('select').forEach(function (field) {
                field.selectedIndex = 0;
            });

            packageList.appendChild(clone);
            renumberPackages();
        });

        const templateSelect = document.querySelector('[data-label-template]');
        const rollFields = document.querySelectorAll('[data-roll-field]');

        const toggleRollFields = function () {
            const isRoll = templateSelect?.value === 'roll';
            rollFields.forEach(function (field) {
                field.style.display = isRoll ? '' : 'none';
            });
        };

        templateSelect?.addEventListener('change', toggleRollFields);
        toggleRollFields();
        renumberPackages();
    });
</script>
@endsection
