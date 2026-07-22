@extends('layouts.prodelya-admin')

@section('title', 'Üretim / Fason')
@section('page_topbar_hidden', true)
@section('hide_side_summary', true)

@section('content')
@php
    use App\Models\OrderItemPrintGraphic;
    use App\Models\OrderItemPrintProduction;
    use App\Models\OrderItemProcurement;

    $activeRoute = $filters['route'] ?? 'internal';
    $statusTones = [
        OrderItemPrintProduction::STATUS_PENDING => 'amber',
        OrderItemPrintProduction::STATUS_INTERNAL => 'blue',
        OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR => 'blue',
        OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR => 'amber',
        OrderItemPrintProduction::STATUS_QUALITY_CONTROL => 'amber',
        OrderItemPrintProduction::STATUS_COMPLETED => 'green',
        OrderItemPrintProduction::STATUS_PROBLEMATIC => 'red',
        OrderItemPrintProduction::STATUS_CANCELLED => 'gray',
    ];
    $graphicStatuses = [
        OrderItemPrintGraphic::STATUS_PRODUCTION_READY => 'Üretime Hazır',
        OrderItemPrintGraphic::STATUS_WAITING_VISUAL => 'Grafik Bekliyor',
        OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => 'Revize Var',
        OrderItemPrintGraphic::STATUS_APPROVED => 'Onaylı',
        OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED => 'Görsel Yüklendi',
        OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => 'Grafik Onayı Bekliyor',
    ];
    $procurementStatuses = [
        OrderItemProcurement::STATUS_FULLY_RECEIVED => 'Ürün Geldi',
        OrderItemProcurement::STATUS_PENDING => 'Tedarik Bekliyor',
        OrderItemProcurement::STATUS_PARTIALLY_RECEIVED => 'Kısmi Geldi',
        OrderItemProcurement::STATUS_CUSTOMER_WAITING => 'Müşteri Ürünü Bekleniyor',
        OrderItemProcurement::STATUS_NOT_REQUIRED => 'Tedarik Gerekmez',
        OrderItemProcurement::STATUS_CUSTOMER_RECEIVED => 'Müşteri Ürünü Geldi',
    ];
    $routeLabels = collect($routeTabs)->pluck('label', 'key');
    $listedJobs = $rows->count();
    $methodCount = $methodGroups->count();
    $readyCount = $methodGroups->sum(fn ($group) => $group['metrics']['ready'] ?? 0);
    $progressCount = $methodGroups->sum(fn ($group) => $group['metrics']['progress'] ?? 0);
    $problemCount = $methodGroups->sum(fn ($group) => $group['metrics']['problem'] ?? 0);
@endphp

<div class="pd-production-pool pd-ui-v1-production pd-production-pool__full-width">
    <section class="pd-production-compact-header">
        <div>
            <span class="pd-production-eyebrow">Operasyon · Üretim Yönetimi</span>
            <h2>Üretim / Fason</h2>
            <p>İşleri üretim yoluna ve baskı tekniğine göre yönetin. Her satır exact baskı üretim işidir.</p>
        </div>
        <span class="pd-production-hero__badge">{{ $routeLabels[$activeRoute] ?? 'İç Baskı' }}</span>
    </section>

    <section class="pd-production-metrics" aria-label="Genel üretim özeti">
        @foreach($summaryCards as $card)
            <article class="pd-production-metric">
                <span>{{ $card['label'] }}</span>
                <strong>{{ $card['value'] }}</strong>
            </article>
        @endforeach
    </section>

    <nav class="pd-production-route-tabs" aria-label="Üretim yolu sekmeleri">
        @foreach($routeTabs as $tab)
            <a href="{{ $tab['url'] }}" class="pd-production-route-tab {{ $activeRoute === $tab['key'] ? 'is-active' : '' }} {{ $tab['key'] === 'supplier_printed' ? 'is-soft' : '' }}">
                <span>{{ $tab['label'] }}</span>
                <strong>{{ $tab['count'] }}</strong>
            </a>
        @endforeach
    </nav>

    <section class="pd-production-pool__summary-strip" aria-label="Havuz özeti">
        <div class="pd-production-pool__summary-title"><span>Havuz Özeti</span><strong>{{ collect($routeTabs)->firstWhere('key', $activeRoute)['label'] ?? 'Tümü' }}</strong></div>
        <div><span>Listelenen</span><strong>{{ $listedJobs }}</strong></div>
        <div><span>Üretim Yolu</span><strong>{{ $routeLabels[$activeRoute] ?? 'İç Baskı' }}</strong></div>
        <div><span>Baskı Tekniği</span><strong>{{ $methodCount }}</strong></div>
        <div><span>Başlamaya Hazır</span><strong>{{ $readyCount }}</strong></div>
        <div><span>Devam Eden</span><strong>{{ $progressCount }}</strong></div>
        <div><span>Sorunlu</span><strong>{{ $problemCount }}</strong></div>
    </section>

    <section class="pd-production-filter-card">
        <form method="GET" action="{{ route('admin.productions.index') }}" class="pd-production-filters">
            <input type="hidden" name="route" value="{{ $activeRoute }}">
            <div class="pd-production-field pd-production-field--wide">
                <label>Arama</label>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Sipariş no, iş formu, müşteri, ürün, SKU veya baskı">
            </div>
            <div class="pd-production-field">
                <label>Baskı Tekniği</label>
                <select name="method">
                    <option value="">Hepsi</option>
                    @foreach($methodOptions as $method)
                        <option value="{{ $method['key'] }}" @selected(($filters['method'] ?? '') === $method['key'])>{{ $method['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pd-production-field">
                <label>Durum</label>
                <select name="status">
                    <option value="">Hepsi</option>
                    @foreach($statusLabels as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pd-production-field">
                <label>Grafik</label>
                <select name="graphic_status">
                    <option value="">Hepsi</option>
                    @foreach($graphicStatuses as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['graphic_status'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pd-production-field">
                <label>Tedarik</label>
                <select name="procurement_status">
                    <option value="">Hepsi</option>
                    @foreach($procurementStatuses as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['procurement_status'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pd-production-field">
                <label>Sayfa</label>
                <select name="per_page">
                    @foreach([10, 20, 50] as $perPageOption)
                        <option value="{{ $perPageOption }}" @selected((int) ($filters['per_page'] ?? 10) === $perPageOption)>{{ $perPageOption }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pd-production-filter-actions">
                <button type="submit" class="pd-production-btn pd-production-btn--primary">Filtrele</button>
                <a href="{{ route('admin.productions.index', ['route' => $activeRoute]) }}" class="pd-production-btn">Temizle</a>
            </div>
        </form>
    </section>

    @if($activeRoute === 'supplier_printed' && $methodGroups->isEmpty())
        <section class="pd-production-empty-state">
            <h3>Tedarikçiden Baskılı</h3>
            <p>Henüz bu üretim yolu için ayrı bir canonical sınıflandırma bulunmuyor. Başka route’taki kayıtlar tahminle bu sekmeye taşınmadı.</p>
        </section>
    @endif

    <div class="pd-production-method-groups">
        @forelse($methodGroups as $group)
            <section class="pd-production-method-group pd-production-method-group--{{ $group['tone'] }}">
                <header class="pd-production-method-group__header">
                    <div>
                        <span class="pd-production-method-group__kicker">Baskı tekniği</span>
                        <h3>{{ $group['label'] }}</h3>
                    </div>
                    <div class="pd-production-method-group__metrics">
                        <span><strong>{{ $group['metrics']['total'] }}</strong> iş</span>
                        <span><strong>{{ $group['metrics']['ready'] }}</strong> hazır</span>
                        <span><strong>{{ $group['metrics']['progress'] }}</strong> devam</span>
                        <span><strong>{{ $group['metrics']['problem'] }}</strong> sorunlu</span>
                    </div>
                </header>

                <div class="pd-production-jobs">
                    @foreach($group['rows'] as $row)
                        @php
                            /** @var \App\Models\OrderItemPrintProduction $production */
                            $production = $row['production'];
                            $snapshot = $row['snapshot'];
                            $readiness = $row['readiness'];
                            $nextAction = $row['next_action'];
                            $poolReadiness = $row['pool_readiness'] ?? [];
                            $orderNumber = $snapshot['order_number'] ?? ($production->order?->document_number ?: '-');
                            $workFormNumber = $snapshot['work_form_number'] ?? ($production->workForm?->work_form_number ?: '-');
                            $customerName = $production->order?->customer?->legal_name ?: '-';
                            $productName = $snapshot['product_name'] ?? ($production->orderItem?->product_name ?: '-');
                            $productCode = $snapshot['product_code'] ?? ($production->orderItem?->product_code ?: '-');
                            $printSequence = $snapshot['print_sequence'] ?? '-';
                            $unit = $snapshot['unit'] ?? ($production->orderItem?->unit ?: 'Adet');
                            $graphicTone = $poolReadiness['graphic_tone'] ?? ($snapshot['graphic_status_tone'] ?? (($readiness['graphic_ready'] ?? false) ? 'green' : 'amber'));
                            $procurementTone = $poolReadiness['procurement_tone'] ?? ($snapshot['procurement_status_tone'] ?? (($readiness['procurement_ready'] ?? false) ? 'green' : 'amber'));
                            $statusTone = $statusTones[$production->production_status] ?? 'gray';
                            $canTransferToSubcontract = OrderItemPrintProduction::normalizeProductionType($production->production_type ?: $production->orderItemPrint?->production_type) === OrderItemPrintProduction::TYPE_INTERNAL
                                && !in_array($production->production_status, [OrderItemPrintProduction::STATUS_COMPLETED, OrderItemPrintProduction::STATUS_CANCELLED], true)
                                && (float) $row['remaining_quantity'] > 0.0;
                        @endphp
                        <article class="pd-production-job-row" data-production-id="{{ $production->id }}" data-print-row-id="{{ $production->order_item_print_id }}">
                            <div class="pd-production-job-key">
                                <strong>{{ $printSequence }}</strong>
                                <span>{{ $orderNumber }}</span>
                                <small>{{ $workFormNumber }}</small>
                            </div>

                            <div class="pd-production-job-product">
                                <div>
                                    <strong>{{ $productName }}</strong>
                                    <span>{{ $productCode }}</span>
                                </div>
                                <small>{{ $customerName }}</small>
                            </div>

                            <div class="pd-production-job-print">
                                <strong>{{ $snapshot['print_type'] ?? $group['label'] }}</strong>
                                <span>{{ $snapshot['print_option'] ?? '-' }}</span>
                                <small>{{ OrderItemPrintProduction::formatDisplayQuantity(data_get($snapshot, 'print_quantity', $row['planned_quantity'])) }} {{ $unit }}</small>
                            </div>

                            <div class="pd-production-job-readiness">
                                <span class="pd-production-badge pd-production-badge--{{ $graphicTone }}">{{ $poolReadiness['graphic_label'] ?? ($readiness['graphic_status_label'] ?? '-') }}</span>
                                <span class="pd-production-badge pd-production-badge--{{ $procurementTone }}">{{ $poolReadiness['procurement_label'] ?? ($snapshot['procurement_status_label'] ?? '-') }}</span>
                            </div>

                            <div class="pd-production-job-progress">
                                <div class="pd-production-progress-text">
                                    <strong>{{ OrderItemPrintProduction::formatDisplayQuantity($row['completed_quantity']) }}</strong>
                                    <span>/ {{ OrderItemPrintProduction::formatDisplayQuantity($row['planned_quantity']) }} {{ $unit }}</span>
                                </div>
                                <div class="pd-production-progress-track" aria-hidden="true">
                                    <span style="width: {{ $row['progress_percent'] }}%;"></span>
                                </div>
                                <small>Kalan: {{ OrderItemPrintProduction::formatDisplayQuantity($row['remaining_quantity']) }} {{ $unit }}</small>
                            </div>

                            <div class="pd-production-job-status">
                                <span class="pd-production-badge pd-production-badge--{{ $statusTone }}">{{ $production->safeStatusLabel() }}</span>
                                <small>{{ $nextAction['hint'] }}</small>
                            </div>

                            <div class="pd-production-job-next">
                                <a href="{{ $nextAction['url'] }}" class="pd-production-btn pd-production-btn--primary">{{ $nextAction['label'] }}</a>
                                <div class="pd-production-secondary-links">
                                    @if($production->workForm)
                                        <a href="{{ route('admin.work-forms.show', $production->workForm) }}">İş Formu</a>
                                    @endif
                                    @if($canTransferToSubcontract)
                                        <a href="{{ route('admin.productions.operator', $production) }}#route-transfer-panel" class="pd-production-row__secondary-action">Fasona Devret</a>
                                    @endif
                                    <a href="{{ route('admin.productions.show', $production) }}">Detay</a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            @if($activeRoute !== 'supplier_printed')
                <section class="pd-production-empty-state">
                    <h3>Üretim işi bulunamadı</h3>
                    <p>Seçili filtrelere uygun exact baskı üretim kaydı yok.</p>
                </section>
            @endif
        @endforelse
    </div>

    @if($rows->hasPages())
        <nav class="pd-production-pagination" aria-label="Üretim sayfalama">
            @if($rows->onFirstPage())
                <span class="is-disabled">Geri</span>
            @else
                <a href="{{ $rows->previousPageUrl() }}">Geri</a>
            @endif

            @foreach($rows->getUrlRange(1, $rows->lastPage()) as $page => $url)
                @if($page === $rows->currentPage())
                    <span class="is-active">{{ $page }}</span>
                @else
                    <a href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach

            @if($rows->hasMorePages())
                <a href="{{ $rows->nextPageUrl() }}">İleri</a>
            @else
                <span class="is-disabled">İleri</span>
            @endif
        </nav>
    @endif
</div>
@endsection
