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

    $graphicPending = $rows->filter(fn (array $row) => str_contains(mb_strtolower((string) data_get($row, 'sticky_panel.module_statuses.graphic.label', ''), 'UTF-8'), 'bek'))->count();
    $procurementPending = $rows->filter(fn (array $row) => str_contains(mb_strtolower((string) data_get($row, 'sticky_panel.module_statuses.procurement.label', ''), 'UTF-8'), 'bek'))->count();
    $productionPending = $rows->filter(function (array $row) {
        $label = mb_strtolower((string) data_get($row, 'sticky_panel.module_statuses.production.label', ''), 'UTF-8');
        return str_contains($label, 'bek') || str_contains($label, 'blok') || str_contains($label, 'devam');
    })->count();
    $deliveryPending = $rows->filter(fn (array $row) => str_contains(mb_strtolower((string) data_get($row, 'sticky_panel.module_statuses.delivery.label', ''), 'UTF-8'), 'bek'))->count();

    $orderSummaryCards = [
        ['label' => 'Açık Sipariş', 'value' => $summary['open'] ?? 0, 'note' => 'Aktif operasyonda kalan siparişler', 'tone' => 'blue'],
        ['label' => 'Grafik Bekleyen', 'value' => $graphicPending, 'note' => 'Grafik veya onay adımı bekleyen işler', 'tone' => 'purple'],
        ['label' => 'Tedarik Bekleyen', 'value' => $procurementPending, 'note' => 'Talep veya tedarik tamamlanmamış kayıtlar', 'tone' => 'amber'],
        ['label' => 'Üretim Bekleyen / Bloklu', 'value' => $productionPending, 'note' => 'Baskı öncesi engeli olan işler', 'tone' => 'red'],
        ['label' => 'Teslimat Bekleyen', 'value' => $deliveryPending, 'note' => 'Teslimata hazırlanan veya bekleyen siparişler', 'tone' => 'green'],
    ];

    $statusChips = [
        ['value' => 'all', 'label' => 'Tümü'],
        ['value' => 'open', 'label' => 'Açık Sipariş'],
        ['value' => 'in_operation', 'label' => 'Operasyonda'],
        ['value' => 'delivery_pending', 'label' => 'Teslimat Bekleyen'],
        ['value' => 'completed', 'label' => 'Tamamlanan'],
    ];

    if ($canViewFinancialData) {
        $statusChips[] = ['value' => 'payment_pending', 'label' => 'Ödeme Bekleyen'];
    }

    $selectedLinks = $selectedPanel['links'] ?? [];
    $selectedStatuses = $selectedPanel['module_statuses'] ?? [];
    $statusRouteQuery = request()->query();
@endphp

@section('content')
<style>
    .poi-page{display:grid;gap:14px;padding-bottom:24px;font-family:Arial,Helvetica,sans-serif;color:#17233c}
    .poi-card,.poi-stat,.poi-table-card,.poi-side-card{background:#fff;border:1px solid #e4e8ef;border-radius:8px;box-shadow:0 8px 24px rgba(16,24,40,.055)}
    .poi-page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
    .poi-page-title h2{margin:0;font-size:18px;line-height:1.25;letter-spacing:-.02em}
    .poi-page-title p{margin:4px 0 0;font-size:12px;color:#66728a}
    .poi-header-tools{display:flex;flex-wrap:wrap;gap:8px}
    .poi-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
    .poi-stat{padding:12px 13px;min-height:76px}
    .poi-stat-label{font-size:11px;color:#66728a;font-weight:700}
    .poi-stat-value{margin-top:5px;font-size:24px;font-weight:700;letter-spacing:-.03em}
    .poi-stat-note{margin-top:2px;font-size:11px;color:#66728a;line-height:1.4}
    .poi-stat.blue .poi-stat-value{color:#2f6fed}
    .poi-stat.green .poi-stat-value{color:#17a55b}
    .poi-stat.amber .poi-stat-value{color:#d98207}
    .poi-stat.red .poi-stat-value{color:#d14343}
    .poi-stat.purple .poi-stat-value{color:#6f50d8}
    .poi-filter-card{padding:12px 14px}
    .poi-filter-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:10px}
    .poi-filter-head h3{margin:0;font-size:13px;font-weight:700}
    .poi-filter-head p{margin:3px 0 0;font-size:11px;color:#66728a}
    .poi-filter-grid{display:grid;grid-template-columns:1.4fr .9fr .9fr .8fr .8fr auto;gap:8px;align-items:end}
    .poi-field label{display:block;margin-bottom:5px;font-size:11px;color:#59657a;font-weight:700}
    .poi-field input,.poi-field select{width:100%;height:34px;border:1px solid #cfd7e3;border-radius:5px;background:#fff;padding:0 10px;color:#24324a;font-size:12px;outline:none}
    .poi-field input:focus,.poi-field select:focus{border-color:#7aa5ff;box-shadow:0 0 0 3px rgba(47,111,237,.08)}
    .poi-filter-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
    .poi-layout{display:grid;grid-template-columns:minmax(0,1fr) 306px;gap:14px;align-items:start}
    .poi-table-card{overflow:hidden}
    .poi-table-top{padding:12px 14px 10px;border-bottom:1px solid #edf1f6;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
    .poi-table-top-left b{display:block;font-size:13px}
    .poi-table-top-left span{display:block;margin-top:2px;font-size:11px;color:#66728a;line-height:1.45}
    .poi-tabs{display:flex;flex-wrap:wrap;gap:6px}
    .poi-tab{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border:1px solid #dbe2ec;border-radius:5px;background:#fff;color:#475467;font-size:12px;font-weight:700}
    .poi-tab.is-active{background:#eaf1ff;border-color:#bfd2ff;color:#245bc7}
    .poi-table-wrap{overflow-x:auto}
    .poi-table{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed}
    .poi-table th{height:37px;background:#f8fafc;border-bottom:1px solid #edf1f6;color:#59657a;text-align:left;font-size:11px;font-weight:700;padding:0 9px;white-space:nowrap}
    .poi-table td{padding:10px 9px;border-bottom:1px solid #edf1f6;vertical-align:top;color:#24324a;font-size:12px}
    .poi-table tbody tr{cursor:pointer}
    .poi-table tbody tr:hover{background:#fbfdff}
    .poi-table tbody tr.poi-row-selected{background:linear-gradient(90deg,#eef4ff 0%,#f7faff 100%);box-shadow:inset 4px 0 0 #2f6fed}
    .poi-table tbody tr.poi-row-selected td{background:transparent}
    .poi-table tbody tr.poi-row-selected .poi-order-no{color:#1f4fb8}
    .poi-table tbody tr.poi-row-selected .pd-btn-light{border-color:#bfd2ff;background:#fff}
    .poi-order-no{display:block;margin-bottom:2px;font-weight:700;color:#21426f}
    .poi-muted{color:#66728a}
    .poi-small{font-size:11px}
    .poi-customer b{display:block;font-weight:700;line-height:1.3}
    .poi-customer span{display:block;margin-top:2px;color:#66728a;font-size:11px;line-height:1.3}
    .poi-badge-row{display:flex;flex-direction:column;gap:4px;align-items:flex-start}
    .poi-badge-inline{display:flex;flex-wrap:wrap;gap:4px}
    .poi-finance-stack,.poi-next-stack{display:flex;flex-direction:column;gap:4px;align-items:flex-start}
    .poi-finance-stack b,.poi-next-stack b{font-size:12px;line-height:1.3}
    .poi-finance-stack span,.poi-next-stack span{font-size:10.5px;color:#66728a;line-height:1.3}
    .poi-row-actions{display:flex;flex-direction:column;gap:5px;align-items:stretch}
    .poi-row-help{font-size:10px;color:#66728a;line-height:1.2;text-align:center}
    .poi-side-stack{display:grid;gap:12px}
    .poi-side-card{padding:14px}
    .poi-side-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
    .poi-side-title b{font-size:13px}
    .poi-side-title span{font-size:11px;color:#66728a}
    .poi-kv{display:grid;grid-template-columns:90px 1fr;gap:7px 8px;font-size:12px}
    .poi-kv div:nth-child(odd){color:#66728a}
    .poi-kv div:nth-child(even){font-weight:700;text-align:right}
    .poi-quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px}
    .poi-quick-grid .pd-btn-primary{grid-column:1 / -1}
    .poi-process-list,.poi-summary-list{display:grid;gap:8px}
    .poi-process-item,.poi-summary-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid #edf1f6}
    .poi-process-item:last-child,.poi-summary-item:last-child{border-bottom:0}
    .poi-process-item span:first-child,.poi-summary-item span:first-child{color:#344054;font-size:12px;font-weight:700}
    .poi-summary-item b{font-size:12px}
    .poi-summary-item.total b{font-size:15px}
    .poi-note-box{background:#f8fafc;border:1px solid #e4e8ef;border-radius:6px;padding:10px;font-size:11.5px;color:#536075;line-height:1.45}
    .poi-empty{padding:24px;text-align:center;color:#66728a}
    @media (max-width:1540px){.poi-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media (max-width:1240px){.poi-filter-grid{grid-template-columns:1fr 1fr 1fr}.poi-filter-actions{grid-column:1 / -1}.poi-layout{grid-template-columns:1fr}.poi-side-stack{grid-template-columns:1fr 1fr}}
    @media (max-width:960px){.poi-page-head{flex-direction:column}.poi-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.poi-side-stack{grid-template-columns:1fr}}
    @media (max-width:720px){.poi-stats,.poi-filter-grid,.poi-quick-grid{grid-template-columns:1fr}.poi-table,.poi-table thead,.poi-table tbody,.poi-table tr,.poi-table th,.poi-table td{display:block}.poi-table thead{display:none}.poi-table tr{padding:10px 0;border-bottom:1px solid #edf1f6}.poi-table td{border-bottom:0;padding:6px 10px}.poi-table td::before{content:attr(data-label);display:block;margin-bottom:4px;color:#66728a;font-size:10px;font-weight:700;text-transform:uppercase}}
</style>

<div class="poi-page">
    <section class="poi-page-head">
        <div class="poi-page-title">
            <h2>Daha Verimli Sipariş Takip Ekranı</h2>
            <p>Liste satırında sadece ana aksiyon bırakıldı; modül geçişleri sağ panel ve sipariş detayında yönetilir.</p>
        </div>
        <div class="poi-header-tools">
            <a href="{{ route('admin.orders.index') }}" class="pd-btn pd-btn-light">Tüm Siparişler</a>
            <a href="{{ route('admin.orders.index', ['status' => 'open']) }}" class="pd-btn pd-btn-light">Açık Siparişler</a>
            @if(data_get($selectedRow, 'links.work_form'))
                <a href="{{ data_get($selectedRow, 'links.work_form') }}" class="pd-btn pd-btn-light">İş Formu</a>
            @endif
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
                <select name="status">
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
                <a href="{{ route('admin.orders.index') }}" class="pd-btn pd-btn-light">Temizle</a>
            </div>
        </form>
    </section>

    <div class="poi-layout">
        <section class="poi-table-card">
            <div class="poi-table-top">
                <div class="poi-table-top-left">
                    <b>Sipariş Listesi</b>
                    <span>Satırda sadece ana işlem bulunur. İş Formu, Grafik, Tedarik, Üretim, Teslimat ve Finans geçişleri sağ panel ve detay ekranında kalır.</span>
                </div>
                <div class="poi-tabs">
                    @foreach($statusChips as $chip)
                        <a href="{{ route('admin.orders.index', array_merge($statusRouteQuery, ['status' => $chip['value']])) }}" class="poi-tab {{ ($filters['status'] ?? 'all') === $chip['value'] ? 'is-active' : '' }}">{{ $chip['label'] }}</a>
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
                                        <a href="{{ $row['links']['show'] }}" class="pd-btn pd-btn-sm pd-btn-light" data-testid="order-{{ $row['order']->id }}-show-link">Siparişi Aç</a>
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
