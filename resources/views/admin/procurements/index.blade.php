@extends('layouts.prodelya-admin')

@section('title', 'Tedarik / Malzeme İşleri')
@section('page_title', 'Tedarik / Malzeme İşleri')
@section('page_subtitle', 'Sipariş ve stok ihtiyaçlarını, tedarikçi taleplerini ve gelen miktarları tek listeden takip edin.')
@section('hide_side_summary', '1')

@section('content')
@php
    $statusLabels = \App\Models\OrderItemProcurement::statusLabels();
    $fulfillmentLabels = \App\Models\OrderItemProcurement::fulfillmentSourceLabels();
    $requestStatusToneMap = [
        \App\Models\SupplierProcurementRequest::STATUS_DRAFT => 'amber',
        \App\Models\SupplierProcurementRequest::STATUS_REQUESTED => 'blue',
        \App\Models\SupplierProcurementRequest::STATUS_SUPPLIER_ORDERED => 'blue',
        \App\Models\SupplierProcurementRequest::STATUS_PARTIALLY_RECEIVED => 'amber',
        \App\Models\SupplierProcurementRequest::STATUS_COMPLETED => 'green',
        \App\Models\SupplierProcurementRequest::STATUS_CANCELLED => 'gray',
        'candidate' => 'amber',
    ];

    $formatQuantity = static function ($value): string {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    };

    $tabInput = (string) request('tab', 'pending');
    $tab = match ($tabInput) {
        'open' => 'pending',
        default => $tabInput,
    };

    $requestRows = \App\Models\SupplierProcurementRequest::query()
        ->with(['supplier', 'items.order', 'items.procurement.workForm'])
        ->where('tenant_account_id', $tenant->id)
        ->latest('id')
        ->get();

    $pendingRequests = $requestRows->filter(fn ($row) => in_array($row->status, [
        \App\Models\SupplierProcurementRequest::STATUS_DRAFT,
        \App\Models\SupplierProcurementRequest::STATUS_REQUESTED,
    ], true))->values();
    $orderedRequests = $requestRows->filter(fn ($row) => $row->status === \App\Models\SupplierProcurementRequest::STATUS_SUPPLIER_ORDERED)->values();
    $partialRequests = $requestRows->filter(fn ($row) => $row->status === \App\Models\SupplierProcurementRequest::STATUS_PARTIALLY_RECEIVED)->values();
    $completedRequests = $requestRows->filter(fn ($row) => $row->status === \App\Models\SupplierProcurementRequest::STATUS_COMPLETED)->values();
    $cancelledRequests = $requestRows->filter(fn ($row) => $row->status === \App\Models\SupplierProcurementRequest::STATUS_CANCELLED)->values();

    $candidateRows = collect($rows ?? [])->filter(fn ($row) => !$row->supplierRequestItems->first()?->request)->values();
    $candidateSuppliers = collect($supplierGroups ?? [])->filter(fn ($group) => (bool) ($group['can_create_request'] ?? false))->values();

    $visibleRows = match ($tab) {
        'ordered' => $orderedRequests,
        'partial' => $partialRequests,
        'completed' => $completedRequests,
        'cancelled' => $cancelledRequests,
        'all' => $requestRows,
        default => $pendingRequests,
    };

    $summaryCards = [
        ['label' => 'Bekleyen Talep', 'value' => $pendingRequests->count(), 'note' => 'Taslak veya iletilmemiş talepler'],
        ['label' => 'Sipariş Verilen', 'value' => $orderedRequests->count(), 'note' => 'Tedarikçiye sipariş verildi'],
        ['label' => 'Kısmi Gelen', 'value' => $partialRequests->count(), 'note' => 'Kalan teslim bekleyenler'],
        ['label' => 'Tamamlanan', 'value' => $completedRequests->count(), 'note' => 'Tam teslim alınan talepler'],
    ];

    $requestNextAction = static function ($requestRecord): string {
        return match ($requestRecord->status) {
            \App\Models\SupplierProcurementRequest::STATUS_DRAFT => 'Talebi Düzenle',
            \App\Models\SupplierProcurementRequest::STATUS_REQUESTED => 'Sipariş Verildi Olarak İşaretle',
            \App\Models\SupplierProcurementRequest::STATUS_SUPPLIER_ORDERED => 'Gelen Ürün Kaydet',
            \App\Models\SupplierProcurementRequest::STATUS_PARTIALLY_RECEIVED => 'Kalanı Teslim Al',
            \App\Models\SupplierProcurementRequest::STATUS_COMPLETED => 'Kaydı Aç',
            \App\Models\SupplierProcurementRequest::STATUS_CANCELLED => 'Kaydı Aç',
            default => 'Talebi Aç',
        };
    };

    $requestPrimaryLabel = static function ($requestRecord): string {
        return match ($requestRecord->status) {
            \App\Models\SupplierProcurementRequest::STATUS_DRAFT => 'Talebi Aç',
            \App\Models\SupplierProcurementRequest::STATUS_REQUESTED => 'Talebi Aç',
            \App\Models\SupplierProcurementRequest::STATUS_SUPPLIER_ORDERED => 'Talebi Aç',
            \App\Models\SupplierProcurementRequest::STATUS_PARTIALLY_RECEIVED => 'Talebi Aç',
            \App\Models\SupplierProcurementRequest::STATUS_COMPLETED => 'Kaydı Aç',
            \App\Models\SupplierProcurementRequest::STATUS_CANCELLED => 'Kaydı Aç',
            default => 'Talebi Aç',
        };
    };

    $selectedRequest = $visibleRows->first();
    $selectedCandidate = $candidateRows->first();
    $selectedContext = $selectedRequest ? 'request' : ($selectedCandidate ? 'candidate' : null);
@endphp

@if(session('success'))
    <div class="pd-alert">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="pd-alert pd-alert-danger">{{ $errors->first() }}</div>
@endif

<div class="pd-procurement-index pd-ui-v1-procurement" data-procurement-reference-family="request-list">
    <section class="pd-ui-v1-procurement__hero-card">
        <div class="pd-ui-v1-procurement__hero-head">
            <div class="pd-ui-v1-procurement__hero-copy">
                <div class="pd-ui-v1-procurement__eyebrow">Yeni Tedarik Talep Ailesi · Tedarik Talepleri</div>
                <h2 class="pd-ui-v1-procurement__hero-title">Tedarik / Malzeme İşleri</h2>
                <p class="pd-ui-v1-procurement__hero-note">Normal tedarik akışı stok ve cari oluşturmadan başlar; yalnız gelen ürün canonical aşamasında stok ve tedarikçi borcu oluşur. Stok Girişi / Satın Alma ayrı ve tamamlanmış alış süreci olarak kalır.</p>
            </div>
            <div class="pd-ui-v1-procurement__hero-side">
                <span class="pd-ui-v1-procurement__hero-chip">Exact ürün / varyant</span>
                <span class="pd-ui-v1-procurement__hero-chip">İstenen · Gelen · Kalan</span>
                <span class="pd-ui-v1-procurement__hero-chip">Tek primary CTA</span>
            </div>
        </div>
    </section>

    <section class="pd-ui-v1-procurement__summary-grid">
        @foreach($summaryCards as $card)
            <article class="pd-ui-v1-procurement__summary-card">
                <div class="pd-ui-v1-procurement__summary-label">{{ $card['label'] }}</div>
                <div class="pd-ui-v1-procurement__summary-value">{{ $card['value'] }}</div>
                <div class="pd-ui-v1-procurement__summary-note">{{ $card['note'] }}</div>
            </article>
        @endforeach
    </section>

    <section class="pd-ui-v1-procurement__toolbar-card">
        <div class="pd-ui-v1-procurement__tabs" data-testid="procurement-request-tabs">
            <a href="{{ route('admin.procurements.index', array_filter(array_merge($filters, ['tab' => 'pending']))) }}" class="pd-ui-v1-procurement__tab {{ $tab === 'pending' ? 'is-active' : '' }}">Bekleyenler / Açık Talepler</a>
            <a href="{{ route('admin.procurements.index', array_filter(array_merge($filters, ['tab' => 'ordered']))) }}" class="pd-ui-v1-procurement__tab {{ $tab === 'ordered' ? 'is-active' : '' }}">Sipariş Verilenler</a>
            <a href="{{ route('admin.procurements.index', array_filter(array_merge($filters, ['tab' => 'partial']))) }}" class="pd-ui-v1-procurement__tab {{ $tab === 'partial' ? 'is-active' : '' }}">Kısmi Gelenler</a>
            <a href="{{ route('admin.procurements.index', array_filter(array_merge($filters, ['tab' => 'completed']))) }}" class="pd-ui-v1-procurement__tab {{ $tab === 'completed' ? 'is-active' : '' }}">Tamamlananlar</a>
            <a href="{{ route('admin.procurements.index', array_filter(array_merge($filters, ['tab' => 'cancelled']))) }}" class="pd-ui-v1-procurement__tab {{ $tab === 'cancelled' ? 'is-active' : '' }}">İptal Edilenler</a>
            <a href="{{ route('admin.procurements.index', array_filter(array_merge($filters, ['tab' => 'all']))) }}" class="pd-ui-v1-procurement__tab {{ $tab === 'all' ? 'is-active' : '' }}">Tümü</a>
        </div>

        <form method="GET" action="{{ route('admin.procurements.index') }}" class="pd-ui-v1-procurement__filter-grid">
            <input type="hidden" name="tab" value="{{ $tab }}">

            <div class="pd-ui-v1-procurement__field">
                <label for="procurement-search">Arama</label>
                <input id="procurement-search" type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Sipariş no, iş formu, ürün, müşteri">
            </div>

            <div class="pd-ui-v1-procurement__field">
                <label for="procurement-status">Durum</label>
                <select id="procurement-status" name="status">
                    <option value="">Tümü</option>
                    @foreach($statusLabels as $statusKey => $statusLabel)
                        <option value="{{ $statusKey }}" @selected(($filters['status'] ?? '') === $statusKey)>{{ $statusLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div class="pd-ui-v1-procurement__field">
                <label for="procurement-supplier">Tedarikçi</label>
                <select id="procurement-supplier" name="supplier_id">
                    <option value="">Tümü</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((int) ($filters['supplier_id'] ?? 0) === (int) $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="pd-ui-v1-procurement__field">
                <label for="procurement-source">Kaynak</label>
                <select id="procurement-source" name="source">
                    <option value="">Tümü</option>
                    @foreach($fulfillmentLabels as $sourceKey => $sourceLabel)
                        <option value="{{ $sourceKey }}" @selected(($filters['source'] ?? '') === $sourceKey)>{{ $sourceLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div class="pd-ui-v1-procurement__field">
                <label for="procurement-receipt">Gelen Durumu</label>
                <select id="procurement-receipt" name="receipt_state">
                    <option value="">Tümü</option>
                    <option value="bekliyor" @selected(($filters['receipt_state'] ?? '') === 'bekliyor')>Bekliyor</option>
                    <option value="hic_gelmedi" @selected(($filters['receipt_state'] ?? '') === 'hic_gelmedi')>Hiç Gelmedi</option>
                    <option value="kismi" @selected(($filters['receipt_state'] ?? '') === 'kismi')>Kısmi Geldi</option>
                    <option value="tamam" @selected(($filters['receipt_state'] ?? '') === 'tamam')>Tamamı Geldi</option>
                </select>
            </div>

            <div class="pd-ui-v1-procurement__field">
                <label for="procurement-limit">Kayıt Limiti</label>
                <select id="procurement-limit" name="limit">
                    @foreach(['25', '50', '100', '250'] as $limitOption)
                        <option value="{{ $limitOption }}" @selected(($filters['limit'] ?? '50') === $limitOption)>{{ $limitOption }}</option>
                    @endforeach
                </select>
            </div>

            <div class="pd-ui-v1-procurement__filter-actions">
                <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                <a href="{{ route('admin.procurements.index', ['tab' => $tab]) }}" class="pd-btn pd-btn-light">Temizle</a>
            </div>
        </form>
    </section>

    <section class="pd-ui-v1-procurement__layout">
        <div class="pd-ui-v1-procurement__main pd-page-stack">
            <section class="pd-ui-v1-procurement__panel-card">
                <div class="pd-ui-v1-procurement__panel-head">
                    <div>
                        <h3 class="pd-ui-v1-procurement__panel-title">Talep Hazırlanacak İhtiyaçlar</h3>
                        <p class="pd-ui-v1-procurement__panel-note">Henüz tedarikçi talebine bağlanmamış gerçek ihtiyaçlar. Draft aşamasında stok ve tedarikçi borcu oluşmaz.</p>
                    </div>
                    <div class="pd-ui-v1-procurement__panel-badge">{{ $candidateRows->count() }} kayıt</div>
                </div>

                <div class="pd-ui-v1-procurement__table-wrap">
                    <table class="pd-ui-v1-procurement__table" data-testid="procurement-candidate-table">
                        <thead>
                            <tr>
                                <th>Talep / Kaynak</th>
                                <th>Ürün / Exact SKU</th>
                                <th>Tedarikçi</th>
                                <th>Miktar</th>
                                <th>Durum</th>
                                <th>Sıradaki İş</th>
                                <th>Aksiyon</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($candidateRows as $candidate)
                                @php
                                    $snapshot = is_array($candidate->snapshot) ? $candidate->snapshot : [];
                                    $candidateTitle = (string) (data_get($snapshot, 'product_name') ?: $candidate->orderItem?->product_name ?: '-');
                                    $candidateSku = (string) (data_get($snapshot, 'product_code') ?: $candidate->orderItem?->product_code ?: '-');
                                    $candidateRequested = (float) $candidate->requested_quantity;
                                    $candidateReceived = (float) $candidate->received_quantity;
                                    $candidateRemaining = (float) $candidate->remaining_quantity;
                                @endphp
                                <tr>
                                    <td data-label="Talep / Kaynak">
                                        <div class="pd-ui-v1-procurement__item-title">{{ $candidate->order?->document_number ?: '-' }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">İş Formu: {{ $candidate->workForm?->work_form_number ?: '-' }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">Müşteri: {{ $candidate->order?->customer?->legal_name ?: ($candidate->order?->customer?->name ?: '-') }}</div>
                                    </td>
                                    <td data-label="Ürün / Exact SKU">
                                        <div class="pd-ui-v1-procurement__item-title">{{ $candidateTitle }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">Exact SKU: {{ $candidateSku }}</div>
                                    </td>
                                    <td data-label="Tedarikçi">
                                        <div class="pd-ui-v1-procurement__item-title">{{ $candidate->supplier?->name ?: '-' }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">Kaynak: {{ $candidate->safeFulfillmentSourceLabel() }}</div>
                                    </td>
                                    <td data-label="Miktar">
                                        <div class="pd-ui-v1-procurement__qty-stack">
                                            <div><span>İstenen</span><strong>{{ $formatQuantity($candidateRequested) }}</strong></div>
                                            <div><span>Gelen</span><strong>{{ $formatQuantity($candidateReceived) }}</strong></div>
                                            <div><span>Kalan</span><strong>{{ $formatQuantity($candidateRemaining) }}</strong></div>
                                        </div>
                                    </td>
                                    <td data-label="Durum">
                                        <span class="pd-badge pd-badge-{{ $requestStatusToneMap['candidate'] }}">{{ $candidate->safeStatusLabel() }}</span>
                                        <div class="pd-ui-v1-procurement__item-meta">Stok: 0 · Cari: 0</div>
                                    </td>
                                    <td data-label="Sıradaki İş">
                                        <div class="pd-ui-v1-procurement__item-title">{{ $candidate->userFacingNextActionLabel() ?: 'Tedarik kaydını incele' }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">Talep taslağı açılmadan satın alma başlamaz.</div>
                                    </td>
                                    <td data-label="Aksiyon">
                                        @if($candidate->supplier_id)
                                            <a href="{{ route('admin.procurements.supplier-requests.create', ['supplier_id' => $candidate->supplier_id]) }}" class="pd-btn pd-btn-primary pd-ui-v1-procurement__primary-action">Talebi Aç</a>
                                        @else
                                            <button type="button" class="pd-btn pd-btn-light" disabled>Talebi Aç</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="pd-ui-v1-procurement__empty">Henüz talep hazırlanacak görünür ihtiyaç bulunmuyor.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="pd-ui-v1-procurement__panel-card">
                <div class="pd-ui-v1-procurement__panel-head">
                    <div>
                        <h3 class="pd-ui-v1-procurement__panel-title">Tedarik Görev Listesi</h3>
                        <p class="pd-ui-v1-procurement__panel-note">İstenen, gelen ve kalan miktarlar ile tek primary sonraki işlem aynı satırda görünür.</p>
                    </div>
                    <div class="pd-ui-v1-procurement__panel-badge">{{ $visibleRows->count() }} kayıt</div>
                </div>

                <div class="pd-ui-v1-procurement__table-wrap">
                    <table class="pd-ui-v1-procurement__table" data-testid="procurement-request-table">
                        <thead>
                            <tr>
                                <th>Talep / Kaynak</th>
                                <th>Ürün / Exact SKU</th>
                                <th>Tedarikçi</th>
                                <th>Miktar</th>
                                <th>Fiyat</th>
                                <th>Durum</th>
                                <th>Sıradaki İş</th>
                                <th>Aksiyon</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($visibleRows as $requestRecord)
                                @php
                                    $firstItem = $requestRecord->items->first();
                                    $firstProcurement = $firstItem?->procurement;
                                    $requestedTotal = (float) $requestRecord->items->sum('requested_quantity');
                                    $receivedTotal = (float) $requestRecord->items->sum('received_quantity');
                                    $remainingTotal = (float) $requestRecord->items->sum('remaining_quantity');
                                    $purchaseTotal = (float) $requestRecord->items->sum(fn ($item) => (float) ($item->purchase_total ?? 0));
                                    $priceLabel = $purchaseTotal > 0 ? number_format($purchaseTotal, 2, ',', '.') . ' TL' : 'Fiyat kaydı bekleniyor';
                                @endphp
                                <tr>
                                    <td data-label="Talep / Kaynak">
                                        <div class="pd-ui-v1-procurement__item-title">{{ $requestRecord->request_number ?: '-' }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">Sipariş: {{ $firstItem?->order?->document_number ?: '-' }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">İş Formu: {{ $firstProcurement?->workForm?->work_form_number ?: '-' }}</div>
                                    </td>
                                    <td data-label="Ürün / Exact SKU">
                                        <div class="pd-ui-v1-procurement__item-title">{{ $firstItem?->product_name ?: 'Talep kalemleri' }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">Exact SKU: {{ $firstItem?->product_code ?: '-' }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">Kalem: {{ $requestRecord->items->count() }}</div>
                                    </td>
                                    <td data-label="Tedarikçi">
                                        <div class="pd-ui-v1-procurement__item-title">{{ $requestRecord->supplier?->name ?: '-' }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">Talep tarihi: {{ optional($requestRecord->request_date)->format('d.m.Y') ?: '-' }}</div>
                                    </td>
                                    <td data-label="Miktar">
                                        <div class="pd-ui-v1-procurement__qty-stack">
                                            <div><span>İstenen</span><strong>{{ $formatQuantity($requestedTotal) }}</strong></div>
                                            <div><span>Gelen</span><strong>{{ $formatQuantity($receivedTotal) }}</strong></div>
                                            <div><span>Kalan</span><strong>{{ $formatQuantity($remainingTotal) }}</strong></div>
                                        </div>
                                    </td>
                                    <td data-label="Fiyat">
                                        <div class="pd-ui-v1-procurement__item-title">{{ $priceLabel }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">Satış fiyatı veya müşteri bakiyesi gösterilmez.</div>
                                    </td>
                                    <td data-label="Durum">
                                        <span class="pd-badge pd-badge-{{ $requestStatusToneMap[$requestRecord->status] ?? 'gray' }}">{{ $requestRecord->safeStatusLabel() }}</span>
                                        <div class="pd-ui-v1-procurement__item-meta">{{ $firstProcurement?->safeStatusLabel() ?: 'Tedarik kaydı izleniyor' }}</div>
                                    </td>
                                    <td data-label="Sıradaki İş">
                                        <div class="pd-ui-v1-procurement__item-title">{{ $requestNextAction($requestRecord) }}</div>
                                        <div class="pd-ui-v1-procurement__item-meta">{{ $remainingTotal > 0 ? 'Kalan miktar kapanmadan stok ve cari tamamlanmış sayılmaz.' : 'Talep kapanmış kaydı yalnız inceleme modunda açılır.' }}</div>
                                    </td>
                                    <td data-label="Aksiyon">
                                        <a href="{{ route('admin.procurements.supplier-requests.edit', $requestRecord) }}" class="pd-btn pd-btn-primary pd-ui-v1-procurement__primary-action">{{ $requestPrimaryLabel($requestRecord) }}</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="pd-ui-v1-procurement__empty">Bu sekmede gösterilecek tedarik talebi bulunmadı.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="pd-ui-v1-procurement__sidebar pd-section-stack">
            <section class="pd-ui-v1-procurement__side-card">
                <div class="pd-ui-v1-procurement__side-head">
                    <h3 class="pd-ui-v1-procurement__side-title">Liste Özeti</h3>
                    <p class="pd-ui-v1-procurement__side-note">Fiyat, stok ve talep görünümü tek panelde özetlenir.</p>
                </div>
                <div class="pd-ui-v1-procurement__summary-list">
                    <div class="pd-ui-v1-procurement__summary-row"><span>Listelenen</span><strong>{{ $visibleRows->count() }}</strong></div>
                    <div class="pd-ui-v1-procurement__summary-row"><span>Bekleyen</span><strong>{{ $pendingRequests->count() }}</strong></div>
                    <div class="pd-ui-v1-procurement__summary-row"><span>Kısmi gelen</span><strong>{{ $partialRequests->count() }}</strong></div>
                    <div class="pd-ui-v1-procurement__summary-row"><span>Seçili filtre</span><strong>{{ $filters['supplier_id'] ?? $filters['status'] ?? $filters['receipt_state'] ?? 'Genel görünüm' }}</strong></div>
                </div>
            </section>

            <section class="pd-ui-v1-procurement__side-card">
                <div class="pd-ui-v1-procurement__side-head">
                    <h3 class="pd-ui-v1-procurement__side-title">Sıradaki İş</h3>
                    <p class="pd-ui-v1-procurement__side-note">Seçili kaydın kompakt özeti.</p>
                </div>

                @if($selectedContext === 'request' && $selectedRequest)
                    @php
                        $selectedItem = $selectedRequest->items->first();
                        $selectedRequested = (float) $selectedRequest->items->sum('requested_quantity');
                        $selectedReceived = (float) $selectedRequest->items->sum('received_quantity');
                        $selectedRemaining = (float) $selectedRequest->items->sum('remaining_quantity');
                    @endphp
                    <div class="pd-ui-v1-procurement__focus-card">
                        <div class="pd-ui-v1-procurement__focus-name">{{ $selectedItem?->product_name ?: 'Talep kaydı' }}</div>
                        <div class="pd-ui-v1-procurement__focus-meta">Exact SKU: {{ $selectedItem?->product_code ?: '-' }}</div>
                        <div class="pd-ui-v1-procurement__focus-meta">Tedarikçi: {{ $selectedRequest->supplier?->name ?: '-' }}</div>
                        <div class="pd-ui-v1-procurement__focus-qty">{{ $formatQuantity($selectedRequested) }} / {{ $formatQuantity($selectedReceived) }} / {{ $formatQuantity($selectedRemaining) }}</div>
                        <div class="pd-ui-v1-procurement__focus-next">{{ $requestNextAction($selectedRequest) }}</div>
                    </div>
                @elseif($selectedContext === 'candidate' && $selectedCandidate)
                    @php($selectedSnapshot = is_array($selectedCandidate->snapshot) ? $selectedCandidate->snapshot : [])
                    <div class="pd-ui-v1-procurement__focus-card">
                        <div class="pd-ui-v1-procurement__focus-name">{{ data_get($selectedSnapshot, 'product_name', $selectedCandidate->orderItem?->product_name ?: 'Tedarik ihtiyacı') }}</div>
                        <div class="pd-ui-v1-procurement__focus-meta">Exact SKU: {{ data_get($selectedSnapshot, 'product_code', $selectedCandidate->orderItem?->product_code ?: '-') }}</div>
                        <div class="pd-ui-v1-procurement__focus-meta">Tedarikçi: {{ $selectedCandidate->supplier?->name ?: '-' }}</div>
                        <div class="pd-ui-v1-procurement__focus-qty">{{ $formatQuantity($selectedCandidate->requested_quantity) }} / {{ $formatQuantity($selectedCandidate->received_quantity) }} / {{ $formatQuantity($selectedCandidate->remaining_quantity) }}</div>
                        <div class="pd-ui-v1-procurement__focus-next">{{ $selectedCandidate->userFacingNextActionLabel() ?: 'Tedarik kaydını incele' }}</div>
                    </div>
                @else
                    <div class="pd-ui-v1-procurement__empty-note">Gösterilecek seçili tedarik kaydı bulunmuyor.</div>
                @endif
            </section>

            <section class="pd-ui-v1-procurement__side-card">
                <div class="pd-ui-v1-procurement__side-head">
                    <h3 class="pd-ui-v1-procurement__side-title">Talep Hazırlanacak Tedarikçiler</h3>
                    <p class="pd-ui-v1-procurement__side-note">Orphan kayıt yoktur; yalnız gerçek açık ihtiyaçlardan talep hazırlanır.</p>
                </div>

                <div class="pd-ui-v1-procurement__supplier-stack">
                    @forelse($candidateSuppliers as $group)
                        <article class="pd-ui-v1-procurement__supplier-card">
                            <div class="pd-ui-v1-procurement__supplier-name">{{ $group['supplier_name'] }}</div>
                            <div class="pd-ui-v1-procurement__supplier-grid">
                                <div><span>Açık Kalem</span><strong>{{ $group['open_item_count'] }}</strong></div>
                                <div><span>Aday Kalem</span><strong>{{ $group['candidate_item_count'] ?? 0 }}</strong></div>
                                <div><span>Eksik Toplam</span><strong>{{ number_format((float) $group['total_missing_quantity'], 2, ',', '.') }}</strong></div>
                                <div><span>Bekleyen Talep</span><strong>{{ $group['open_request_count'] }}</strong></div>
                            </div>
                            <div class="pd-ui-v1-procurement__supplier-actions">
                                <a href="{{ route('admin.procurements.index', ['supplier_id' => $group['supplier_id'], 'tab' => $tab]) }}" class="pd-btn pd-btn-light">Filtrele</a>
                                <a href="{{ route('admin.procurements.supplier-requests.create', ['supplier_id' => $group['supplier_id']]) }}" class="pd-btn pd-btn-primary">Talep Hazırla</a>
                            </div>
                        </article>
                    @empty
                        <div class="pd-ui-v1-procurement__empty-note">Şu anda talep hazırlanacak yeni tedarikçi görünmüyor.</div>
                    @endforelse
                </div>
            </section>
        </aside>
    </section>
</div>
@endsection
