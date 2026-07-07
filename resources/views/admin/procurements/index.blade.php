@extends('layouts.prodelya-admin')

@section('title', 'Tedarik Yönetimi')
@section('page_title', 'Tedarik Yönetimi')
@section('page_subtitle', 'Siparişe bağlı ürün tedariklerini tedarikçi bazlı gruplayın, toplu talep hazırlayın ve gelen ürünleri takip edin.')
@section('hide_side_summary', '1')

@section('content')
@php
    $statusLabels = \App\Models\OrderItemProcurement::statusLabels();
    $statusTones = [
        'tedarik_bekliyor' => 'amber',
        'tedarik_talebi_acildi' => 'blue',
        'siparis_verildi' => 'blue',
        'kismi_geldi' => 'amber',
        'tamami_geldi' => 'green',
        'iptal' => 'red',
        'tedarik_gerekmiyor' => 'gray',
        'musteri_urunu_bekleniyor' => 'purple',
        'musteri_urunu_geldi' => 'green',
    ];
    $canManage = $canManageProcurementRequests ?? false;
    $selectedSummary = null;
    if ($selectedProcurement) {
        $selectedSnapshot = $selectedProcurement->snapshot ?? [];
        $selectedRequestItem = $selectedProcurement->supplierRequestItems->first();
        $selectedRequestRecord = $selectedRequestItem?->request;
        $selectedSupplierDisplay = $selectedProcurement->supplier?->name
            ?: data_get($selectedSnapshot, 'supplier_name')
            ?: $selectedProcurement->safeFulfillmentSourceLabel();
        $selectedSupplierCompany = $supplierCompanyMap[$selectedProcurement->supplier_id] ?? null;
        $selectedCanReopen = in_array($selectedProcurement->procurement_status, [
            \App\Models\OrderItemProcurement::STATUS_CANCELLED,
            \App\Models\OrderItemProcurement::STATUS_NOT_REQUIRED,
            \App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
        ], true) && (float) $selectedProcurement->received_quantity <= 0.0001;
        $selectedCanChangeSupplier = $selectedProcurement->supplier_id
            && (float) $selectedProcurement->received_quantity <= 0.0001;
        $selectedShowRequestAction = $selectedProcurement->procurement_status === \App\Models\OrderItemProcurement::STATUS_PENDING
            && ($selectedRequestRecord || $selectedProcurement->supplier_id);
        $selectedSummary = [
            'order_number' => $selectedProcurement->order?->document_number ?: '-',
            'work_form_number' => $selectedProcurement->workForm?->work_form_number ?: '-',
            'product_name' => data_get($selectedSnapshot, 'product_name', '-'),
            'product_code' => data_get($selectedSnapshot, 'product_code', '-'),
            'supplier_name' => $selectedSupplierDisplay,
            'supplier_company_name' => $selectedSupplierCompany['company_name'] ?? null,
            'request_number' => $selectedRequestRecord?->request_number,
            'requested_quantity' => number_format((float) $selectedProcurement->requested_quantity, 2, ',', '.'),
            'received_quantity' => number_format((float) $selectedProcurement->received_quantity, 2, ',', '.'),
            'remaining_quantity' => number_format((float) $selectedProcurement->remaining_quantity, 2, ',', '.'),
            'status_label' => $selectedProcurement->safeStatusLabel(),
            'next_action' => match ($selectedProcurement->procurement_status) {
                'tedarik_bekliyor' => 'Talep açılabilir',
                'tedarik_talebi_acildi' => 'Sipariş verildi işaretlenmeli',
                'siparis_verildi' => 'Gelen miktar bekleniyor',
                'kismi_geldi' => 'Eksik teslim bekleniyor',
                'tamami_geldi' => 'Tamamlandı',
                'iptal' => 'Geri alınabilir',
                'tedarik_gerekmiyor' => 'Geri alınabilir',
                default => 'Detay incele',
            },
            'request_url' => $selectedRequestRecord ? route('admin.procurements.supplier-requests.edit', $selectedRequestRecord) : ($selectedProcurement->supplier_id ? route('admin.procurements.supplier-requests.create', ['supplier_id' => $selectedProcurement->supplier_id]) : null),
            'request_label' => $selectedRequestRecord ? 'Talebi Aç' : ($selectedProcurement->supplier_id ? 'Talep Aç' : 'Talep Yok'),
            'detail_url' => route('admin.procurements.show', $selectedProcurement),
            'status_action_url' => route('admin.procurements.update-status', $selectedProcurement),
            'show_request_action' => $selectedShowRequestAction,
            'can_supplier_order' => $canManage && $selectedProcurement->procurement_status === \App\Models\OrderItemProcurement::STATUS_REQUEST_CREATED,
            'can_partial' => $canManage && in_array($selectedProcurement->procurement_status, [
                \App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                \App\Models\OrderItemProcurement::STATUS_PARTIALLY_RECEIVED,
            ], true) && !$selectedProcurement->isFullyReceived() && !$selectedProcurement->isNotRequired(),
            'can_receive' => $canManage && in_array($selectedProcurement->procurement_status, [
                \App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                \App\Models\OrderItemProcurement::STATUS_PARTIALLY_RECEIVED,
            ], true) && !$selectedProcurement->isFullyReceived() && !$selectedProcurement->isNotRequired(),
            'can_reopen' => $canManage && $selectedCanReopen,
            'reopen_label' => $selectedProcurement->procurement_status === \App\Models\OrderItemProcurement::STATUS_CANCELLED ? 'İptalden Geri Al' : 'Geri Al',
            'can_change_supplier' => $canManage && $selectedCanChangeSupplier,
            'supplier_id' => $selectedProcurement->supplier_id,
            'is_completed' => $selectedProcurement->isFullyReceived(),
        ];
    }
@endphp

<style>
    .pm-main { display: grid; gap: 14px; }
    .pm-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05); overflow: hidden; }
    .pm-body { padding: 16px; }
    .pm-band { border: 1px solid #dbeafe; border-radius: 6px; background: #f8fbff; padding: 10px 12px; color: #475569; font-size: 12px; line-height: 1.45; }
    .pm-summary { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; }
    .pm-metric { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 12px; }
    .pm-metric-label { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .pm-metric-value { margin-top: 6px; font-size: 22px; font-weight: 700; color: #111827; }
    .pm-groups { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
    .pm-group-card { border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; padding: 14px; display: grid; gap: 10px; }
    .pm-group-title { font-size: 16px; font-weight: 700; color: #111827; }
    .pm-group-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .pm-group-box { border: 1px solid #eef2f7; border-radius: 6px; background: #fbfcfe; padding: 10px; }
    .pm-group-box span { display: block; color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .pm-group-box strong { display: block; margin-top: 4px; color: #111827; font-size: 16px; }
    .pm-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .pm-actions-compact { display: flex; flex-wrap: wrap; gap: 6px; }
    .pm-filters { display: grid; grid-template-columns: 1.4fr 1fr 1fr 1fr auto; gap: 10px; align-items: end; }
    .pm-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 12px; font-weight: 600; }
    .pm-table-wrap { overflow: auto; }
    .pm-table { width: 100%; border-collapse: collapse; min-width: 1100px; }
    .pm-table th, .pm-table td { padding: 11px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
    .pm-table th { background: #fbfcfe; color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .pm-note-muted { color: #6b7280; font-size: 12px; }
    .pm-action-stack { display: grid; gap: 8px; }
    .pm-link-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; background: #eff6ff; color: #1d4ed8; font-size: 11px; font-weight: 700; text-decoration: none; }
    .pm-more { position: relative; }
    .pm-more summary { list-style: none; cursor: pointer; }
    .pm-more summary::-webkit-details-marker { display: none; }
    .pm-more-menu { position: absolute; right: 0; top: calc(100% + 6px); min-width: 180px; padding: 8px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12); z-index: 20; display: grid; gap: 6px; }
    .pm-inline-form { margin: 0; }
    .pm-modal-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); display: none; align-items: center; justify-content: center; padding: 16px; z-index: 1200; }
    .pm-modal-backdrop.is-open { display: flex; }
    .pm-modal { width: min(100%, 520px); background: #fff; border-radius: 8px; box-shadow: 0 18px 36px rgba(15, 23, 42, 0.18); overflow: hidden; }
    .pm-modal-head { padding: 16px 18px; border-bottom: 1px solid #e5e7eb; }
    .pm-modal-body { padding: 16px 18px; display: grid; gap: 12px; }
    .pm-modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .pm-modal-box { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 10px; }
    .pm-modal-label { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .pm-modal-value { margin-top: 4px; color: #111827; font-size: 13px; font-weight: 700; }
    .pm-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 14px; align-items: start; }
    .pm-selected-card { position: sticky; top: 18px; }
    .pm-selected-grid { display: grid; gap: 8px; }
    .pm-selected-row { display: flex; justify-content: space-between; gap: 12px; font-size: 12px; color: #475569; }
    .pm-selected-row strong { color: #111827; }
    .pm-selected-actions { display: grid; gap: 8px; }
    .pm-selected-group { display: grid; gap: 8px; padding-top: 10px; border-top: 1px solid #e5e7eb; }
    .pm-selected-group:first-of-type { padding-top: 0; border-top: 0; }
    .pm-selected-group-title { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .pm-selected-empty { color: #6b7280; font-size: 12px; }
    .pm-row-selected { background: #f8fbff; }
    @media (max-width: 1280px) { .pm-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 760px) {
        .pm-summary, .pm-filters, .pm-group-grid { grid-template-columns: 1fr; }
        .pm-modal-grid { grid-template-columns: 1fr; }
        .pm-grid { grid-template-columns: 1fr; }
        .pm-selected-card { position: static; }
    }
</style>

@if(session('success'))
    <div class="pd-alert" style="margin-bottom:12px;">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="pd-alert pd-alert-danger" style="margin-bottom:12px;">{{ $errors->first() }}</div>
@endif

<div class="pm-main">
        <section class="pm-card">
            <div class="pm-body">
                <div class="pm-band">Tedarikçi kartları, açık tedarik ihtiyacı olan siparişlerden otomatik oluşur. Açık tedarikleri tedarikçi bazında yönetin, talebi hazırlayın ve gelen ürünleri hızlıca işaretleyin.</div>
            </div>
        </section>

        <section class="pm-summary">
            @foreach($summaryCards as $card)
                <div class="pm-metric">
                    <div class="pm-metric-label">{{ $card['label'] }}</div>
                    <div class="pm-metric-value">{{ $card['value'] }}</div>
                </div>
            @endforeach
        </section>

        <section class="pm-card">
            <div class="pm-body">
                <div class="pm-actions" style="justify-content: space-between; margin-bottom: 12px;">
                    <div>
                        <strong>Aktif Tedarikler</strong>
                        <div class="pm-note-muted" style="margin-top:4px;">Bugün açık tedarik ihtiyacı olan tedarikçiler</div>
                    </div>
                    <div class="pm-actions">
                        @if(!empty($filters['supplier_id']))
                            <a href="{{ route('admin.procurements.supplier-requests.create', ['supplier_id' => $filters['supplier_id']]) }}" class="pd-btn pd-btn-primary">Toplu Talep Hazırla</a>
                        @else
                            <button type="button" class="pd-btn pd-btn-light" disabled>Toplu Talep Hazırla</button>
                        @endif
                    </div>
                </div>

                @if(empty($supplierGroups))
                    <div class="pm-band">Açık procurement ihtiyacı bulunan tedarikçi yok.</div>
                @else
                    <div class="pm-groups">
                        @foreach($supplierGroups as $group)
                            <article class="pm-group-card">
                                <div class="pm-group-title">{{ $group['supplier_name'] }}</div>
                                <div class="pm-group-grid">
                                    <div class="pm-group-box">
                                        <span>Açık Kalem</span>
                                        <strong>{{ $group['open_item_count'] }}</strong>
                                    </div>
                                    <div class="pm-group-box">
                                        <span>Eksik Toplam</span>
                                        <strong>{{ number_format((float) $group['total_missing_quantity'], 2, ',', '.') }}</strong>
                                    </div>
                                    <div class="pm-group-box">
                                        <span>Bekleyen Talep</span>
                                        <strong>{{ $group['open_request_count'] }}</strong>
                                    </div>
                                    <div class="pm-group-box">
                                        <span>Durum Özeti</span>
                                        <strong>{{ collect($group['statuses_summary'])->sum() }} kayıt</strong>
                                    </div>
                                </div>
                                <div class="pm-actions">
                                    <a href="{{ route('admin.procurements.index', ['supplier_id' => $group['supplier_id']]) }}" class="pd-btn pd-btn-light">Filtrele</a>
                                    @if($group['can_create_request'])
                                        <a href="{{ route('admin.procurements.supplier-requests.create', ['supplier_id' => $group['supplier_id']]) }}" class="pd-btn pd-btn-primary">Talep Hazırla</a>
                                    @else
                                        <button type="button" class="pd-btn pd-btn-light" disabled>Talep Hazırla</button>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section class="pm-card">
            <div class="pm-body">
                <form method="GET" action="{{ route('admin.procurements.index') }}" class="pm-filters">
                    <div class="pm-field">
                        <label>Arama</label>
                        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Sipariş no, iş formu, ürün, müşteri">
                    </div>
                    <div class="pm-field">
                        <label>Tedarik Durumu</label>
                        <select name="status">
                            <option value="">Tümü</option>
                            @foreach($statusLabels as $statusKey => $statusLabel)
                                <option value="{{ $statusKey }}" @selected(($filters['status'] ?? '') === $statusKey)>{{ $statusLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pm-field">
                        <label>Tedarikçi</label>
                        <select name="supplier_id">
                            <option value="">Tümü</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((int) ($filters['supplier_id'] ?? 0) === (int) $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pm-field">
                        <label>Gelen Durumu</label>
                        <select name="receipt_state">
                            <option value="">Tümü</option>
                            <option value="bekliyor" @selected(($filters['receipt_state'] ?? '') === 'bekliyor')>Bekliyor</option>
                            <option value="hic_gelmedi" @selected(($filters['receipt_state'] ?? '') === 'hic_gelmedi')>Hiç Gelmedi</option>
                            <option value="kismi" @selected(($filters['receipt_state'] ?? '') === 'kismi')>Kısmi Geldi</option>
                            <option value="tamam" @selected(($filters['receipt_state'] ?? '') === 'tamam')>Tamamı Geldi</option>
                        </select>
                    </div>
                    <div class="pm-actions">
                        <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                        <a href="{{ route('admin.procurements.index') }}" class="pd-btn pd-btn-light">Temizle</a>
                    </div>
                </form>
            </div>
        </section>

        <section class="pm-grid">
            <div class="pm-card">
            <div class="pm-table-wrap">
                <table class="pm-table">
                    <thead>
                        <tr>
                            <th>Sipariş No</th>
                            <th>İş Formu No</th>
                            <th>Tedarikçi</th>
                            <th>Ürün Kodu</th>
                            <th>Ürün Adı</th>
                            <th>İstenen</th>
                            <th>Alınan</th>
                            <th>Eksik</th>
                            <th>Durum</th>
                            <th>Talep No</th>
                            <th>Sıradaki İş</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            @php
                                $snapshot = $row->snapshot ?? [];
                                $requestItem = $row->supplierRequestItems->first();
                                $requestRecord = $requestItem?->request;
                                $nextAction = match ($row->procurement_status) {
                                    'tedarik_bekliyor' => 'Talep açılabilir',
                                    'tedarik_talebi_acildi' => 'Sipariş verildi işaretlenmeli',
                                    'siparis_verildi' => 'Gelen miktar bekleniyor',
                                    'kismi_geldi' => 'Eksik teslim bekleniyor',
                                    'tamami_geldi' => 'Tamamlandı',
                                    default => 'Detay incele',
                                };
                                $supplierDisplay = $row->supplier?->name
                                    ?: data_get($snapshot, 'supplier_name')
                                    ?: $row->safeFulfillmentSourceLabel();
                                $supplierCompany = $supplierCompanyMap[$row->supplier_id] ?? null;
                                $canReopen = in_array($row->procurement_status, [
                                    \App\Models\OrderItemProcurement::STATUS_CANCELLED,
                                    \App\Models\OrderItemProcurement::STATUS_NOT_REQUIRED,
                                    \App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                                ], true) && (float) $row->received_quantity <= 0.0001;
                                $canChangeSupplier = $row->supplier_id && (float) $row->received_quantity <= 0.0001;
                                $showRequestAction = $row->procurement_status === \App\Models\OrderItemProcurement::STATUS_PENDING
                                    && ($requestRecord || $row->supplier_id);
                                $rowSummary = [
                                    'order_number' => $row->order?->document_number ?: '-',
                                    'work_form_number' => $row->workForm?->work_form_number ?: '-',
                                    'product_name' => data_get($snapshot, 'product_name', '-'),
                                    'product_code' => data_get($snapshot, 'product_code', '-'),
                                    'supplier_name' => $supplierDisplay,
                                    'supplier_company_name' => $supplierCompany['company_name'] ?? null,
                                    'request_number' => $requestRecord?->request_number,
                                    'request_url' => $requestRecord ? route('admin.procurements.supplier-requests.edit', $requestRecord) : ($row->supplier_id ? route('admin.procurements.supplier-requests.create', ['supplier_id' => $row->supplier_id]) : null),
                                    'request_label' => $requestRecord ? 'Talebi Aç' : ($row->supplier_id ? 'Talep Aç' : 'Talep Yok'),
                                    'requested_quantity' => number_format((float) $row->requested_quantity, 2, ',', '.'),
                                    'received_quantity' => number_format((float) $row->received_quantity, 2, ',', '.'),
                                    'remaining_quantity' => number_format((float) $row->remaining_quantity, 2, ',', '.'),
                                    'status_label' => $row->safeStatusLabel(),
                                    'next_action' => $nextAction,
                                    'detail_url' => route('admin.procurements.show', $row),
                                    'status_action_url' => route('admin.procurements.update-status', $row),
                                    'remaining_quantity_raw' => number_format((float) $row->remaining_quantity, 2, '.', ''),
                                    'show_request_action' => $showRequestAction,
                                    'can_supplier_order' => $canManage && $row->procurement_status === \App\Models\OrderItemProcurement::STATUS_REQUEST_CREATED,
                                    'can_partial' => $canManage && in_array($row->procurement_status, [
                                        \App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                                        \App\Models\OrderItemProcurement::STATUS_PARTIALLY_RECEIVED,
                                    ], true) && !$row->isFullyReceived() && !$row->isNotRequired(),
                                    'can_receive' => $canManage && in_array($row->procurement_status, [
                                        \App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
                                        \App\Models\OrderItemProcurement::STATUS_PARTIALLY_RECEIVED,
                                    ], true) && !$row->isFullyReceived() && !$row->isNotRequired(),
                                    'can_reopen' => $canManage && $canReopen,
                                    'reopen_label' => $row->procurement_status === \App\Models\OrderItemProcurement::STATUS_CANCELLED ? 'İptalden Geri Al' : 'Geri Al',
                                    'can_change_supplier' => $canManage && $canChangeSupplier,
                                    'supplier_id' => $row->supplier_id,
                                    'is_completed' => $row->isFullyReceived(),
                                ];
                            @endphp
                            <tr data-procurement-row data-summary='@json($rowSummary)'>
                                <td>
                                    <div>{{ $row->order?->document_number ?: '-' }}</div>
                                    <div class="pm-note-muted">{{ $row->order?->customer?->legal_name ?: '-' }}</div>
                                </td>
                                <td>{{ $row->workForm?->work_form_number ?: '-' }}</td>
                                <td>
                                    <div>{{ $supplierDisplay }}</div>
                                    @if($supplierCompany)
                                        <div class="pm-note-muted">Eşleşen cari: {{ $supplierCompany['company_name'] }}</div>
                                    @elseif($row->supplier_id)
                                        <div class="pm-note-muted" style="color:#b45309;">Eşleşen cari: Yok</div>
                                    @endif
                                </td>
                                <td>{{ data_get($snapshot, 'product_code', '-') }}</td>
                                <td>{{ data_get($snapshot, 'product_name', '-') }}</td>
                                <td>{{ number_format((float) $row->requested_quantity, 2, ',', '.') }}</td>
                                <td>{{ number_format((float) $row->received_quantity, 2, ',', '.') }}</td>
                                <td>{{ number_format((float) $row->remaining_quantity, 2, ',', '.') }}</td>
                                <td><span class="pd-badge pd-badge-{{ $statusTones[$row->procurement_status] ?? 'gray' }}">{{ $row->safeStatusLabel() }}</span></td>
                                <td>
                                    @if($requestRecord)
                                        <div>
                                            <a class="pm-link-badge" href="{{ route('admin.procurements.supplier-requests.edit', $requestRecord) }}">{{ $requestRecord->request_number }}</a>
                                        </div>
                                        <div class="pm-note-muted">{{ $requestRecord->safeStatusLabel() }}</div>
                                    @elseif($row->supplier_id)
                                        <a class="pm-link-badge" href="{{ route('admin.procurements.supplier-requests.create', ['supplier_id' => $row->supplier_id]) }}">Talep Aç</a>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td><span class="pm-note-muted">{{ $nextAction }}</span></td>
                                <td><a href="{{ route('admin.procurements.show', $row) }}" class="pd-btn pd-btn-sm pd-btn-light">Detay</a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="pm-note-muted">Filtreye uygun tedarik kaydı bulunamadı.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            </div>

            <aside class="pm-card pm-selected-card" id="selected-procurement-card">
                <div class="pm-body">
                    <div style="display:grid; gap:12px;">
                        <div>
                            <strong>Seçili Tedarik Özeti</strong>
                            <div class="pm-note-muted" style="margin-top:4px;">Satıra tıklayınca hızlı aksiyonlar bu alanda güncellenir.</div>
                        </div>

                        @if($selectedSummary)
                            <div class="pm-selected-grid">
                                <div class="pm-selected-row"><span>Sipariş No</span><strong data-selected-field="order_number">{{ $selectedSummary['order_number'] }}</strong></div>
                                <div class="pm-selected-row"><span>İş Formu No</span><strong data-selected-field="work_form_number">{{ $selectedSummary['work_form_number'] }}</strong></div>
                                <div class="pm-selected-row"><span>Ürün</span><strong data-selected-field="product_name">{{ $selectedSummary['product_name'] }}</strong></div>
                                <div class="pm-selected-row"><span>Ürün Kodu</span><strong data-selected-field="product_code">{{ $selectedSummary['product_code'] }}</strong></div>
                                <div class="pm-selected-row"><span>Tedarikçi</span><strong data-selected-field="supplier_name">{{ $selectedSummary['supplier_name'] }}</strong></div>
                                <div class="pm-selected-row"><span>Eşleşen Cari</span><strong data-selected-field="supplier_company_name">{{ $selectedSummary['supplier_company_name'] ?: 'Yok' }}</strong></div>
                                <div class="pm-selected-row"><span>Talep No</span><strong data-selected-field="request_number">{{ $selectedSummary['request_number'] ?: '-' }}</strong></div>
                                <div class="pm-selected-row"><span>İstenen</span><strong data-selected-field="requested_quantity">{{ $selectedSummary['requested_quantity'] }}</strong></div>
                                <div class="pm-selected-row"><span>Alınan</span><strong data-selected-field="received_quantity">{{ $selectedSummary['received_quantity'] }}</strong></div>
                                <div class="pm-selected-row"><span>Eksik</span><strong data-selected-field="remaining_quantity">{{ $selectedSummary['remaining_quantity'] }}</strong></div>
                                <div class="pm-selected-row"><span>Durum</span><strong data-selected-field="status_label">{{ $selectedSummary['status_label'] }}</strong></div>
                                <div class="pm-selected-row"><span>Sıradaki İş</span><strong data-selected-field="next_action">{{ $selectedSummary['next_action'] }}</strong></div>
                            </div>

                            <div class="pm-selected-actions">
                                <div class="pm-selected-group">
                                    <div class="pm-selected-group-title">Birincil Aksiyonlar</div>
                                    <a href="{{ $selectedSummary['request_url'] ?: '#' }}" class="pd-btn pd-btn-primary" data-selected-request-link @if(!$selectedSummary['show_request_action']) hidden @endif>{{ $selectedSummary['request_label'] }}</a>
                                    <form method="POST" action="{{ $selectedSummary['status_action_url'] }}" data-selected-supplier-ordered-form @if(!$selectedSummary['can_supplier_order']) hidden @endif>
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="action" value="supplier_ordered">
                                        <input type="hidden" name="return_back" value="1">
                                        <button type="submit" class="pd-btn pd-btn-primary" style="width:100%;">Sipariş Verildi</button>
                                    </form>
                                    <button type="button" class="pd-btn pd-btn-primary" data-selected-partial-trigger @if(!$selectedSummary['can_partial']) hidden @endif>Kısmi Geldi</button>
                                    <form method="POST" action="{{ $selectedSummary['status_action_url'] }}" data-selected-fully-received-form @if(!$selectedSummary['can_receive']) hidden @endif onsubmit="return confirm('Kalan tüm miktar geldi olarak işaretlenecek. Devam edilsin mi?');">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="action" value="fully_received">
                                        <input type="hidden" name="return_back" value="1">
                                        <button type="submit" class="pd-btn pd-btn-success" style="width:100%;">Tamamı Geldi</button>
                                    </form>
                                    <div class="pm-band" data-selected-complete-note @if(!$selectedSummary['is_completed']) hidden @endif>Bu tedarik kaydı tamamlandı. Yeni işlem gerekmiyor.</div>
                                </div>
                                <div class="pm-selected-group">
                                    <div class="pm-selected-group-title">Diğer Aksiyonlar</div>
                                    <a href="{{ $selectedSummary['detail_url'] }}" class="pd-btn pd-btn-light" data-selected-detail-link>Detay</a>
                                    <form method="POST" action="{{ $selectedSummary['status_action_url'] }}" data-selected-reopen-form @if(!$selectedSummary['can_reopen']) hidden @endif>
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="action" value="reopen">
                                        <input type="hidden" name="return_back" value="1">
                                        <button type="submit" class="pd-btn pd-btn-light" style="width:100%;" data-selected-reopen-label>{{ $selectedSummary['reopen_label'] }}</button>
                                    </form>
                                    <button type="button" class="pd-btn pd-btn-light" data-selected-change-supplier-trigger @if(!$selectedSummary['can_change_supplier']) hidden @endif>Tedarikçi Değiştir</button>
                                </div>
                            </div>
                        @else
                            <div class="pm-selected-empty">Seçilecek açık tedarik kaydı yok.</div>
                        @endif
                    </div>
                </div>
            </aside>
        </section>
</div>

<div class="pm-modal-backdrop" id="partial-receive-modal" aria-hidden="true">
    <div class="pm-modal">
        <div class="pm-modal-head">
            <strong>Kısmi Geldi</strong>
        </div>
        <form method="POST" id="partial-receive-form">
            @csrf
            @method('PATCH')
            <input type="hidden" name="action" value="partially_received">
            <input type="hidden" name="return_back" value="1">
            <div class="pm-modal-body">
                <div class="pm-modal-box">
                    <div class="pm-modal-label">Ürün</div>
                    <div class="pm-modal-value" data-modal-field="product_name">-</div>
                </div>
                <div class="pm-modal-box">
                    <div class="pm-modal-label">Kalan Adet</div>
                    <div class="pm-modal-value" data-modal-field="remaining_quantity">-</div>
                </div>
                <div class="pm-field">
                    <label>Bu Tur Gelen Adet</label>
                    <input type="number" name="received_quantity" id="partial-received-quantity" min="0.01" step="0.01" required>
                </div>
                <div class="pm-field">
                    <label>Kısa Not</label>
                    <input type="text" name="note" maxlength="1000" placeholder="Opsiyonel kısa not">
                </div>
                <div class="pm-actions" style="justify-content:flex-end;">
                    <button type="button" class="pd-btn pd-btn-light" data-modal-close>Vazgeç</button>
                    <button type="submit" class="pd-btn pd-btn-primary">Kısmi Geldi Kaydet</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="pm-modal-backdrop" id="change-supplier-modal" aria-hidden="true">
    <div class="pm-modal">
        <div class="pm-modal-head">
            <strong>Tedarikçi Değiştir</strong>
        </div>
        <form method="POST" id="change-supplier-form">
            @csrf
            @method('PATCH')
            <input type="hidden" name="action" value="change_supplier">
            <input type="hidden" name="return_back" value="1">
            <div class="pm-modal-body">
                <div class="pm-modal-box">
                    <div class="pm-modal-label">Ürün</div>
                    <div class="pm-modal-value" data-change-supplier-product>-</div>
                </div>
                <div class="pm-modal-box">
                    <div class="pm-modal-label">Mevcut Tedarikçi</div>
                    <div class="pm-modal-value" data-change-supplier-current>-</div>
                </div>
                <div class="pm-field">
                    <label>Yeni Tedarikçi</label>
                    <select name="supplier_id" id="change-supplier-id" required>
                        <option value="">Seçiniz</option>
                        @foreach($availableSuppliers as $supplierOption)
                            <option value="{{ $supplierOption->id }}">{{ $supplierOption->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pm-band" style="border-color:#fde68a; background:#fffdf5;">
                    Bu işlem mevcut açık talep bağlantısını kapatabilir ve kalemi yeni tedarikçiden yeniden talep edilebilir hale getirir.
                </div>
                <div class="pm-field">
                    <label>Kısa Not</label>
                    <input type="text" name="note" maxlength="1000" placeholder="Opsiyonel kısa not">
                </div>
                <div class="pm-actions" style="justify-content:flex-end;">
                    <button type="button" class="pd-btn pd-btn-light" data-change-supplier-close>Vazgeç</button>
                    <button type="submit" class="pd-btn pd-btn-primary">Tedarikçiyi Güncelle</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const partialModal = document.getElementById('partial-receive-modal');
    const partialForm = document.getElementById('partial-receive-form');
    const partialQuantity = document.getElementById('partial-received-quantity');
    const partialTriggers = document.querySelectorAll('[data-partial-trigger]');
    const procurementRows = document.querySelectorAll('[data-procurement-row]');
    const selectedCard = document.getElementById('selected-procurement-card');
    const selectedRequestLink = selectedCard?.querySelector('[data-selected-request-link]');
    const selectedDetailLink = selectedCard?.querySelector('[data-selected-detail-link]');
    const selectedSupplierOrderedForm = selectedCard?.querySelector('[data-selected-supplier-ordered-form]');
    const selectedFullyReceivedForm = selectedCard?.querySelector('[data-selected-fully-received-form]');
    const selectedReopenForm = selectedCard?.querySelector('[data-selected-reopen-form]');
    const selectedReopenLabel = selectedCard?.querySelector('[data-selected-reopen-label]');
    const selectedPartialTrigger = selectedCard?.querySelector('[data-selected-partial-trigger]');
    const selectedChangeSupplierTrigger = selectedCard?.querySelector('[data-selected-change-supplier-trigger]');
    const selectedCompleteNote = selectedCard?.querySelector('[data-selected-complete-note]');
    const changeSupplierModal = document.getElementById('change-supplier-modal');
    const changeSupplierForm = document.getElementById('change-supplier-form');
    const changeSupplierSelect = document.getElementById('change-supplier-id');
    let selectedPayload = null;

    const openPartialModal = (payload) => {
        if (!partialModal || !partialForm) {
            return;
        }

        partialForm.setAttribute('action', payload.actionUrl);
        partialQuantity.setAttribute('max', payload.maxRemaining);
        partialQuantity.value = '';
        partialForm.querySelector('[name="note"]').value = '';

        const fieldMap = {
            product_name: payload.productName,
            remaining_quantity: payload.remaining,
        };

        Object.keys(fieldMap).forEach((key) => {
            const target = partialModal.querySelector('[data-modal-field="' + key + '"]');
            if (target) {
                target.textContent = fieldMap[key] || '-';
            }
        });

        partialModal.classList.add('is-open');
        partialModal.setAttribute('aria-hidden', 'false');
    };

    const openChangeSupplierModal = (payload) => {
        if (!changeSupplierModal || !changeSupplierForm || !changeSupplierSelect) {
            return;
        }

        changeSupplierForm.setAttribute('action', payload.status_action_url);
        changeSupplierSelect.value = '';
        changeSupplierForm.querySelector('[name="note"]').value = '';

        const productTarget = changeSupplierModal.querySelector('[data-change-supplier-product]');
        const supplierTarget = changeSupplierModal.querySelector('[data-change-supplier-current]');

        if (productTarget) {
            productTarget.textContent = payload.product_name || '-';
        }

        if (supplierTarget) {
            supplierTarget.textContent = payload.supplier_name || '-';
        }

        Array.from(changeSupplierSelect.options).forEach((option) => {
            option.hidden = option.value !== '' && option.value === String(payload.supplier_id || '');
        });

        changeSupplierModal.classList.add('is-open');
        changeSupplierModal.setAttribute('aria-hidden', 'false');
    };

    const setSelectedField = (key, value) => {
        const target = selectedCard?.querySelector('[data-selected-field="' + key + '"]');
        if (target) {
            target.textContent = value || '-';
        }
    };

    const setFormAction = (form, actionUrl) => {
        if (form && actionUrl) {
            form.setAttribute('action', actionUrl);
        }
    };

    const applySelectedPayload = (payload, row) => {
        selectedPayload = payload;

        procurementRows.forEach((tableRow) => tableRow.classList.remove('pm-row-selected'));
        if (row) {
            row.classList.add('pm-row-selected');
        }

        [
            'order_number',
            'work_form_number',
            'product_name',
            'product_code',
            'supplier_name',
            'supplier_company_name',
            'request_number',
            'requested_quantity',
            'received_quantity',
            'remaining_quantity',
            'status_label',
            'next_action',
        ].forEach((field) => setSelectedField(field, payload[field] || '-'));

        if (selectedRequestLink) {
            if (payload.show_request_action && payload.request_url) {
                selectedRequestLink.hidden = false;
                selectedRequestLink.setAttribute('href', payload.request_url);
                selectedRequestLink.textContent = payload.request_label || 'Talep Aç';
            } else {
                selectedRequestLink.hidden = true;
            }
        }

        if (selectedDetailLink) {
            selectedDetailLink.setAttribute('href', payload.detail_url || '#');
        }

        setFormAction(selectedSupplierOrderedForm, payload.status_action_url);
        setFormAction(selectedFullyReceivedForm, payload.status_action_url);
        setFormAction(selectedReopenForm, payload.status_action_url);

        if (selectedSupplierOrderedForm) {
            selectedSupplierOrderedForm.hidden = !payload.can_supplier_order;
        }

        if (selectedFullyReceivedForm) {
            selectedFullyReceivedForm.hidden = !payload.can_receive;
        }

        if (selectedReopenForm) {
            selectedReopenForm.hidden = !payload.can_reopen;
        }

        if (selectedReopenLabel) {
            selectedReopenLabel.textContent = payload.reopen_label || 'Geri Al';
        }

        if (selectedPartialTrigger) {
            selectedPartialTrigger.hidden = !payload.can_partial;
        }

        if (selectedChangeSupplierTrigger) {
            selectedChangeSupplierTrigger.hidden = !payload.can_change_supplier;
        }

        if (selectedCompleteNote) {
            selectedCompleteNote.hidden = !payload.is_completed;
        }
    };

    procurementRows.forEach((row, index) => {
        const payloadText = row.getAttribute('data-summary');
        if (!payloadText) {
            return;
        }

        const payload = JSON.parse(payloadText);

        row.addEventListener('click', function (event) {
            if (event.target.closest('button, a, form, input, select, summary, details')) {
                return;
            }

            applySelectedPayload(payload, row);
        });

        if (index === 0) {
            applySelectedPayload(payload, row);
        }
    });

    partialTriggers.forEach((trigger) => {
        trigger.addEventListener('click', function () {
            openPartialModal({
                actionUrl: trigger.getAttribute('data-action-url'),
                productName: trigger.getAttribute('data-product-name'),
                remaining: trigger.getAttribute('data-remaining'),
                maxRemaining: trigger.getAttribute('data-max-remaining'),
            });
        });
    });

    selectedPartialTrigger?.addEventListener('click', function () {
        if (!selectedPayload) {
            return;
        }

        openPartialModal({
            actionUrl: selectedPayload.status_action_url,
            productName: selectedPayload.product_name,
            remaining: selectedPayload.remaining_quantity,
            maxRemaining: selectedPayload.remaining_quantity_raw,
        });
    });

    document.querySelectorAll('[data-change-supplier-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', function () {
            openChangeSupplierModal({
                status_action_url: trigger.getAttribute('data-action-url'),
                supplier_name: trigger.getAttribute('data-current-supplier'),
                supplier_id: trigger.getAttribute('data-current-supplier-id'),
                product_name: trigger.getAttribute('data-product-name'),
            });
        });
    });

    selectedChangeSupplierTrigger?.addEventListener('click', function () {
        if (!selectedPayload) {
            return;
        }

        openChangeSupplierModal(selectedPayload);
    });

    partialModal?.addEventListener('click', function (event) {
        if (event.target === partialModal || event.target.hasAttribute('data-modal-close')) {
            partialModal.classList.remove('is-open');
            partialModal.setAttribute('aria-hidden', 'true');
        }
    });

    changeSupplierModal?.addEventListener('click', function (event) {
        if (event.target === changeSupplierModal || event.target.hasAttribute('data-change-supplier-close')) {
            changeSupplierModal.classList.remove('is-open');
            changeSupplierModal.setAttribute('aria-hidden', 'true');
        }
    });
});
</script>
@endsection
