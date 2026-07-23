@extends('layouts.prodelya-admin')

@section('title', 'Siparişler')
@section('page_title', 'Siparişler')
@section('page_subtitle', 'Siparişe dönüşen işleri, operasyon durumlarını ve sıradaki aksiyonları tek ekranda takip edin.')
@section('hide_side_summary', '1')

@php
    $selectedPanel = $selectedRow['sticky_panel'] ?? ($rows->first()['sticky_panel'] ?? null);
    $selectedPanelJson = $selectedPanel
        ? json_encode($selectedPanel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
        : '';

    $queueRows = $queueRows ?? collect();
    $graphicPending = $queueRows->filter(fn (array $row) => str_contains(mb_strtolower((string) data_get($row, 'sticky_panel.module_statuses.graphic.label', ''), 'UTF-8'), 'bek'))->count();
    $procurementPending = $queueRows->filter(fn (array $row) => str_contains(mb_strtolower((string) data_get($row, 'sticky_panel.module_statuses.procurement.label', ''), 'UTF-8'), 'bek'))->count();
    $productionPending = $queueRows->filter(function (array $row) {
        $label = mb_strtolower((string) data_get($row, 'sticky_panel.module_statuses.production.label', ''), 'UTF-8');
        return str_contains($label, 'bek') || str_contains($label, 'blok') || str_contains($label, 'devam');
    })->count();
    $deliveryPending = $queueRows->filter(fn (array $row) => str_contains(mb_strtolower((string) data_get($row, 'sticky_panel.module_statuses.delivery.label', ''), 'UTF-8'), 'bek'))->count();

    $orderSummaryCards = [
        ['label' => 'Açık Sipariş', 'value' => $summary['open'] ?? 0, 'note' => 'Aktif operasyonda kalan siparişler', 'tone' => 'blue'],
        ['label' => 'Grafik Bekleyen', 'value' => $graphicPending, 'note' => 'Grafik veya onay adımı bekleyen işler', 'tone' => 'purple'],
        ['label' => 'Tedarik Bekleyen', 'value' => $procurementPending, 'note' => 'Talep veya tedarik tamamlanmamış kayıtlar', 'tone' => 'amber'],
        ['label' => 'Üretim Bekleyen / Bloklu', 'value' => $productionPending, 'note' => 'Baskı öncesi engeli olan işler', 'tone' => 'red'],
        ['label' => 'Teslimat Bekleyen', 'value' => $deliveryPending, 'note' => 'Teslimata hazırlanan veya bekleyen siparişler', 'tone' => 'green'],
    ];

    $statusChips = [
        ['value' => 'open', 'label' => 'Aktif Siparişler'],
        ['value' => 'completed', 'label' => 'Tamamlanan Siparişler'],
        ['value' => 'all', 'label' => 'Tümü'],
        ['value' => 'in_operation', 'label' => 'Operasyonda'],
        ['value' => 'delivery_pending', 'label' => 'Teslimat Bekleyen'],
    ];

    if ($canViewFinancialData) {
        $statusChips[] = ['value' => 'payment_pending', 'label' => 'Ödeme Bekleyen'];
    }

    $selectedLinks = $selectedPanel['links'] ?? [];
    $selectedStatuses = $selectedPanel['module_statuses'] ?? [];
    $statusRouteQuery = request()->query();
@endphp

@section('content')
<div class="pd-ui-v1-orders"><div class="poi-page">
    <section class="poi-page-head">
        <div class="poi-page-title">
            <h2>Siparişler</h2>
            <p>Aktif siparişleri, sıradaki işlemleri ve tamamlanan işleri tek listeden takip edin.</p>
        </div>
        <div class="poi-header-tools pd-ui-v1-orders__header-actions">
            <a href="{{ route('admin.promotion-quotes.index') }}" class="pd-btn pd-btn-light">Tekliflere Git</a>
        </div>
    </section>

    <section class="poi-stats" aria-label="Sipariş özeti">
        @foreach($orderSummaryCards as $card)
            <article class="poi-stat {{ $card['tone'] }}">
                <div class="poi-stat-label">{{ $card['label'] }}</div>
                <div class="poi-stat-value">{{ $card['value'] }}</div>
                <div class="poi-stat-note">{{ $card['note'] }}</div>
            </article>
        @endforeach
    </section>

        <section class="pd-ui-v1-orders__tabs-card">
        <div class="pd-ui-v1-orders__section-head">
            <div>
                <h3>Sekmeler</h3>
                <p>Aktif, tamamlanan ve Tüm Siparişler görünümleri arasında geçiş yapın.</p>
            </div>
        </div>
        <div class="pd-ui-v1-orders__section-body">
            <div class="poi-tabs pd-ui-v1-orders__tabs">
                @foreach($statusChips as $chip)
                    <a href="{{ route('admin.orders.index', array_merge($statusRouteQuery, ['filter' => $chip['value']])) }}" class="poi-tab {{ ($filters['status'] ?? 'all') === $chip['value'] ? 'is-active' : '' }}">{{ $chip['label'] }} <em>{{ $tabCounts[$chip['value']] ?? 0 }}</em></a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="poi-card poi-filter-card">
        <div class="poi-filter-head">
            <div>
                <h3>Filtreler</h3>
                <p>Sipariş no, müşteri ve operasyon durumuna göre listeyi daraltın.</p>
            </div>
        </div>

        <form method="GET" class="poi-filter-grid">
            <input type="hidden" name="selected_order_id" value="{{ $filters['selected_order_id'] }}">

            <div class="poi-field">
                <label>Arama</label>
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Sipariş no, müşteri, kaynak teklif">
            </div>

            <div class="poi-field">
                <label>Durum</label>
                <select name="filter">
                    @foreach($statusOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($filters['status'] === $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="poi-field">
                <label>Müşteri</label>
                <select name="customer_company_id">
                    <option value="">Tümü</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((string) $filters['customer_company_id'] === (string) $customer->id)>{{ $customer->legal_name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="poi-field">
                <label>Başlangıç Tarihi</label>
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}">
            </div>

            <div class="poi-field">
                <label>Bitiş Tarihi</label>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}">
            </div>

            <div class="poi-filter-actions">
                <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                <a href="{{ route('admin.orders.index', ['filter' => 'open']) }}" class="pd-btn pd-btn-light">Temizle</a>
            </div>
        </form>
    </section>

    <div class="poi-layout">
        <section class="poi-table-card">
            <div class="poi-table-top">
                <div class="poi-table-top-left">
                    <b>Sipariş Listesi</b>
                    <span>Aktif görünüm günlük iş bekleyen siparişleri gösterir. Tamamlanan siparişler ayrı filtrede tutulur.</span>
                </div>
                <div class="poi-tabs">
                    @foreach($statusChips as $chip)
                        <a href="{{ route('admin.orders.index', array_merge($statusRouteQuery, ['filter' => $chip['value']])) }}" class="poi-tab {{ ($filters['status'] ?? 'all') === $chip['value'] ? 'is-active' : '' }}">{{ $chip['label'] }} <em>{{ $tabCounts[$chip['value']] ?? 0 }}</em></a>
                    @endforeach
                </div>
            </div>

            <div class="poi-table-wrap">
                <table class="poi-table">
                    <thead>
                        <tr>
                            <th>Sipariş No</th>
                            <th>Müşteri</th>
                            <th>Kaynak Teklif</th>
                            <th>Sipariş Tarihi</th>
                            <th>Teslim Tarihi</th>
                            <th>Genel Durum</th>
                            <th>Operasyon Durumu</th>
                            @if($canViewFinancialData)
                                <th>Ödeme Durumu</th>
                            @endif
                            <th>Sıradaki İş</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            @php
                                $panelJson = json_encode($row['sticky_panel'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                $isSelected = (int) $selectedOrderId === (int) $row['order']->id;
                                $moduleStatuses = $row['sticky_panel']['module_statuses'] ?? [];
                            @endphp
                            <tr
                                data-testid="order-row-{{ $row['order']->id }}"
                                data-order-id="{{ $row['order']->id }}"
                                data-order-panel='{{ $panelJson }}'
                                data-order-row
                                tabindex="0"
                                class="{{ $isSelected ? 'poi-row-selected pd-order-row-selected' : '' }}">
                                <td data-label="Sipariş">
                                    <span class="poi-order-no">{{ $row['order']->document_number }}</span>
                                    <span class="poi-small poi-muted">{{ $row['customer_name'] }}</span>
                                    <div class="poi-small poi-muted">{{ $row['general_status_label'] }}</div>
                                </td>
                                <td data-label="Müşteri">
                                    <div class="poi-customer">
                                        <b data-testid="order-{{ $row['order']->id }}-customer">{{ $row['customer_name'] }}</b>
                                        <span>{{ $row['general_status_label'] }}</span>
                                    </div>
                                </td>
                                <td data-label="Kaynak Teklif">
                                    <span class="poi-order-no" data-testid="order-{{ $row['order']->id }}-source-quote">{{ $row['source_quote_number'] }}</span>
                                </td>
                                <td data-label="Sipariş Tarihi" data-testid="order-{{ $row['order']->id }}-order-date">{{ $row['order_date_label'] }}</td>
                                <td data-label="Teslim Tarihi" data-testid="order-{{ $row['order']->id }}-delivery-date">{{ $row['delivery_date_label'] }}</td>
                                <td data-label="Genel Durum">
                                    <span class="pd-badge pd-badge-{{ $row['general_status_badge'] }}" data-testid="order-{{ $row['order']->id }}-general-status">{{ $row['general_status_label'] }}</span>
                                </td>
                                <td data-label="Operasyon Durumu">
                                    <div class="poi-badge-row">
                                        <div class="poi-badge-inline">
                                            <span class="pd-badge pd-badge-{{ $row['operation_status_badge'] }}" data-testid="order-{{ $row['order']->id }}-operation-status">{{ $row['operation_status_label'] }}</span>
                                        </div>
                                        <div class="poi-badge-inline">
                                            @foreach(['graphic' => 'Grafik', 'procurement' => 'Tedarik', 'production' => 'Üretim', 'delivery' => 'Teslimat'] as $statusKey => $statusLabel)
                                                @if(!empty($moduleStatuses[$statusKey]))
                                                    <span class="pd-badge pd-badge-{{ $moduleStatuses[$statusKey]['badge'] }}">{{ $statusLabel }}: {{ $moduleStatuses[$statusKey]['label'] }}</span>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                </td>
                                @if($canViewFinancialData)
                                    <td data-label="Ödeme Durumu">
                                        @if($row['payment_status_label'])
                                            <div class="poi-finance-stack" data-testid="order-{{ $row['order']->id }}-payment">
                                                <span class="pd-badge pd-badge-{{ $row['payment_status_badge'] }}">{{ $row['payment_status_label'] }}</span>
                                                <b>Genel Toplam: {{ $row['grand_total_label'] }}</b>
                                                @if($row['balance_due_label'])
                                                    <span>Bakiye: {{ $row['balance_due_label'] }}</span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="poi-muted">-</span>
                                        @endif
                                    </td>
                                @endif
                                <td data-label="Sıradaki İş">
                                    <div class="poi-next-stack">
                                        <b data-testid="order-{{ $row['order']->id }}-next-action">{{ $row['next_action_label'] }}</b>
                                        <span>{{ $row['operation_status_label'] }}</span>
                                    </div>
                                </td>
                                <td data-label="Aksiyon">
                                    <div class="poi-row-actions">
                                        <a href="{{ $row['links']['show'] }}" class="pd-btn pd-btn-sm pd-btn-primary" data-testid="order-{{ $row['order']->id }}-show-link">{{ $row['next_action_label'] ?: 'Siparişi Aç' }}</a>
                                        <span class="poi-row-help">Modüller detayda</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canViewFinancialData ? 10 : 9 }}" class="poi-empty">Bu filtrelerle gösterilecek sipariş bulunamadı.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="poi-side-stack">
            <section class="poi-side-card" data-testid="orders-sticky-panel">
                <div id="orderStickyPanel" data-selected-order='{{ $selectedPanelJson }}'>
                    @if($selectedPanel)
                        <div class="poi-side-title">
                            <b>Seçili Sipariş</b>
                            <span>Kontrol Paneli</span>
                        </div>

                        <div class="poi-kv">
                            <div>Sipariş No</div><div data-panel-order-number>{{ $selectedPanel['order_number'] }}</div>
                            <div>Müşteri</div><div data-panel-customer>{{ $selectedPanel['customer_name'] }}</div>
                            <div>Kaynak Teklif</div><div data-panel-source-quote>{{ $selectedPanel['source_quote_number'] }}</div>
                            <div>Teslim Tarihi</div><div data-panel-delivery-date>{{ $selectedPanel['delivery_date_label'] }}</div>
                            <div>Genel Durum</div><div data-panel-general-status>{!! '<span class="pd-badge pd-badge-'.$selectedPanel['general_status_badge'].'">'.$selectedPanel['general_status_label'].'</span>' !!}</div>
                            <div>Sıradaki İş</div><div data-panel-next-action>{{ $selectedPanel['next_action_label'] }}</div>
                        </div>

                        <div class="mt-4">
                            <div class="poi-side-title">
                                <b>Hızlı Geçişler</b>
                                <span>Modüller</span>
                            </div>
                            <div class="poi-quick-grid" data-panel-links>
                                <a href="{{ $selectedLinks['show'] }}" class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">Siparişi Aç</a>
                                @if(!empty($selectedLinks['work_form']))
                                    <a href="{{ $selectedLinks['work_form'] }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">İş Formu</a>
                                @endif
                                @if(!empty($selectedLinks['graphic']))
                                    <a href="{{ $selectedLinks['graphic'] }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">Grafik</a>
                                @endif
                                @if(!empty($selectedLinks['procurement']))
                                    <a href="{{ $selectedLinks['procurement'] }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">Tedarik</a>
                                @endif
                                @if(!empty($selectedLinks['production']))
                                    <a href="{{ $selectedLinks['production'] }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">Üretim</a>
                                @endif
                                @if(!empty($selectedLinks['delivery']))
                                    <a href="{{ $selectedLinks['delivery'] }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">Teslimat</a>
                                @endif
                                @if(!empty($selectedLinks['finance']))
                                    <a href="{{ $selectedLinks['finance'] }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">Finans</a>
                                @endif
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="poi-side-title">
                                <b>Süreç Durumu</b>
                                <span>Operasyon</span>
                            </div>
                            <div class="poi-process-list" data-panel-statuses>
                                @foreach(['graphic' => 'Grafik', 'procurement' => 'Tedarik', 'production' => 'Üretim', 'delivery' => 'Teslimat'] as $statusKey => $statusLabel)
                                    @if(!empty($selectedStatuses[$statusKey]))
                                        <div class="poi-process-item">
                                            <span>{{ $statusLabel }}</span>
                                            {!! '<span class="pd-badge pd-badge-'.$selectedStatuses[$statusKey]['badge'].'">'.$selectedStatuses[$statusKey]['label'].'</span>' !!}
                                        </div>
                                    @endif
                                @endforeach
                                @if($canViewFinancialData && !empty($selectedStatuses['finance']))
                                    <div class="poi-process-item">
                                        <span>Finans</span>
                                        {!! '<span class="pd-badge pd-badge-'.$selectedStatuses['finance']['badge'].'">'.$selectedStatuses['finance']['label'].'</span>' !!}
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if($canViewFinancialData && !empty($selectedPanel['finance']))
                            <div class="mt-4" data-panel-finance>
                                <div class="poi-side-title">
                                    <b>Finans Özeti</b>
                                    <span>Yetkili görünüm</span>
                                </div>
                                <div class="poi-summary-list">
                                    <div class="poi-summary-item total"><span>Genel Toplam</span><b>{{ $selectedPanel['finance']['grand_total_label'] }}</b></div>
                                    <div class="poi-summary-item"><span>Ödenen</span><b>{{ $selectedPanel['finance']['paid_total_label'] }}</b></div>
                                    <div class="poi-summary-item"><span>Bakiye</span><b>{{ $selectedPanel['finance']['balance_due_label'] }}</b></div>
                                    <div class="poi-summary-item"><span>Ödeme Durumu</span><b>{!! '<span class="pd-badge pd-badge-'.$selectedPanel['finance']['payment_status_badge'].'">'.$selectedPanel['finance']['payment_status_label'].'</span>' !!}</b></div>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="poi-side-title">
                            <b>Seçili Sipariş</b>
                            <span>Kontrol Paneli</span>
                        </div>
                        <div class="poi-note-box">Bir sipariş seçin. Sağ panelde sıradaki iş, modül geçişleri ve yetkiniz varsa finans özeti görünür.</div>
                    @endif
                </div>
            </section>

            <section class="poi-side-card">
                <div class="poi-side-title">
                    <b>Operasyon Notu</b>
                    <span>Güvenli görünüm</span>
                </div>
                <div class="poi-note-box">
                    Sipariş listesi operasyon odaklıdır. Finans özeti yalnız yetkili kullanıcıda gösterilir; fiyat, cari ve maliyet detayları operasyon kullanıcılarına açılmaz.
                </div>
            </section>
        </aside>
    </div>
</div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const panelRoot = document.getElementById('orderStickyPanel');
    const rows = Array.from(document.querySelectorAll('[data-order-row]'));

    if (!panelRoot || rows.length === 0) {
        return;
    }

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const decodeHtmlEntities = (value) => {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = String(value ?? '');
        return textarea.value;
    };

    const buildBadge = (label, badge) => `<span class="pd-badge pd-badge-${escapeHtml(badge || 'gray')}">${escapeHtml(label || '-')}</span>`;

    const renderLinks = (links) => {
        const entries = [
            ['show', 'Siparişi Aç', 'pd-btn pd-btn-primary pd-btn-sm pd-btn-block'],
            ['work_form', 'İş Formu', 'pd-btn pd-btn-light pd-btn-sm pd-btn-block'],
            ['graphic', 'Grafik', 'pd-btn pd-btn-light pd-btn-sm pd-btn-block'],
            ['procurement', 'Tedarik', 'pd-btn pd-btn-light pd-btn-sm pd-btn-block'],
            ['production', 'Üretim', 'pd-btn pd-btn-light pd-btn-sm pd-btn-block'],
            ['delivery', 'Teslimat', 'pd-btn pd-btn-light pd-btn-sm pd-btn-block'],
            ['finance', 'Finans', 'pd-btn pd-btn-light pd-btn-sm pd-btn-block'],
        ];

        return entries
            .filter(([key]) => links && links[key])
            .map(([key, label, css]) => `<a href="${escapeHtml(links[key])}" class="${css}">${escapeHtml(label)}</a>`)
            .join('');
    };

    const renderStatuses = (statuses, showFinance) => {
        const items = [
            ['Grafik', statuses?.graphic],
            ['Tedarik', statuses?.procurement],
            ['Üretim', statuses?.production],
            ['Teslimat', statuses?.delivery],
        ];

        if (showFinance && statuses?.finance) {
            items.push(['Finans', statuses.finance]);
        }

        return items
            .filter(([, item]) => item)
            .map(([label, item]) => `
                <div class="poi-process-item">
                    <span>${escapeHtml(label)}</span>
                    ${buildBadge(item.label, item.badge)}
                </div>
            `)
            .join('');
    };

    const renderFinance = (finance) => {
        if (!finance) {
            return '';
        }

        return `
            <div class="mt-4" data-panel-finance>
                <div class="poi-side-title">
                    <b>Finans Özeti</b>
                    <span>Yetkili görünüm</span>
                </div>
                <div class="poi-summary-list">
                    <div class="poi-summary-item total"><span>Genel Toplam</span><b>${escapeHtml(finance.grand_total_label)}</b></div>
                    <div class="poi-summary-item"><span>Ödenen</span><b>${escapeHtml(finance.paid_total_label)}</b></div>
                    <div class="poi-summary-item"><span>Bakiye</span><b>${escapeHtml(finance.balance_due_label)}</b></div>
                    <div class="poi-summary-item"><span>Ödeme Durumu</span><b>${buildBadge(finance.payment_status_label, finance.payment_status_badge)}</b></div>
                </div>
            </div>
        `;
    };

    const renderPanel = (panel) => {
        if (!panel) {
            panelRoot.innerHTML = '<div class="poi-side-title"><b>Seçili Sipariş</b><span>Kontrol Paneli</span></div><div class="poi-note-box">Bir sipariş seçin. Sağ panelde sıradaki iş, modül geçişleri ve yetkiniz varsa finans özeti görünür.</div>';
            return;
        }

        panelRoot.innerHTML = `
            <div class="poi-side-title">
                <b>Seçili Sipariş</b>
                <span>Kontrol Paneli</span>
            </div>
            <div class="poi-kv">
                <div>Sipariş No</div><div data-panel-order-number>${escapeHtml(panel.order_number)}</div>
                <div>Müşteri</div><div data-panel-customer>${escapeHtml(panel.customer_name)}</div>
                <div>Kaynak Teklif</div><div data-panel-source-quote>${escapeHtml(panel.source_quote_number)}</div>
                <div>Teslim Tarihi</div><div data-panel-delivery-date>${escapeHtml(panel.delivery_date_label)}</div>
                <div>Genel Durum</div><div data-panel-general-status>${buildBadge(panel.general_status_label, panel.general_status_badge)}</div>
                <div>Sıradaki İş</div><div data-panel-next-action>${escapeHtml(panel.next_action_label)}</div>
            </div>
            <div class="mt-4">
                <div class="poi-side-title">
                    <b>Hızlı Geçişler</b>
                    <span>Modüller</span>
                </div>
                <div class="poi-quick-grid" data-panel-links>${renderLinks(panel.links)}</div>
            </div>
            <div class="mt-4">
                <div class="poi-side-title">
                    <b>Süreç Durumu</b>
                    <span>Operasyon</span>
                </div>
                <div class="poi-process-list" data-panel-statuses>${renderStatuses(panel.module_statuses, Boolean(panel.finance))}</div>
            </div>
            ${renderFinance(panel.finance)}
        `;
    };

    const updateSelection = (row) => {
        rows.forEach((item) => item.classList.remove('pd-order-row-selected', 'poi-row-selected'));
        row.classList.add('pd-order-row-selected', 'poi-row-selected');

        try {
            const payload = decodeHtmlEntities(row.getAttribute('data-order-panel') || '{}');
            renderPanel(JSON.parse(payload));
        } catch (error) {
            renderPanel(null);
        }
    };

    rows.forEach((row) => {
        row.addEventListener('click', function (event) {
            if (event.target.closest('a, button, form, input, select, textarea, label')) {
                return;
            }
            updateSelection(row);
        });

        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                updateSelection(row);
            }
        });
    });
});
</script>
@endpush
