@extends('layouts.prodelya-admin')

@section('title', 'Tedarik Detayı')
@section('page_title', 'Tedarik Detayı')
@section('page_subtitle', 'Tedarik kaydını yeni referans ailesiyle tek akışta izleyin, sıradaki işi görün ve gerçek aksiyona geçin.')
@section('hide_side_summary', '1')

@section('content')
@php
    $snapshot = $procurement->snapshot ?? [];
    $unitLabel = $snapshot['unit'] ?? ($procurement->orderItem?->unit ?: 'Adet');
    $requestedFormatted = rtrim(rtrim(number_format((float) $procurement->requested_quantity, 4, ',', '.'), '0'), ',');
    $receivedFormatted = rtrim(rtrim(number_format((float) $procurement->received_quantity, 4, ',', '.'), '0'), ',');
    $remainingFormatted = rtrim(rtrim(number_format((float) $procurement->remaining_quantity, 4, ',', '.'), '0'), ',');
    $receiptLabel = (float) $procurement->received_quantity <= 0.0001
        ? 'Gelen ürün bekleniyor'
        : ((float) $procurement->remaining_quantity <= 0.0001 ? 'Tamamlandı' : 'Kısmi geldi');
    $supplierDisplay = $snapshot['supplier_name'] ?? ($procurement->supplier?->name ?: $procurement->safeFulfillmentSourceLabel());
    $tenantModel = \App\Models\TenantAccount::query()->find($procurement->tenant_account_id);
    $resolvedDepth = $tenantModel ? app(\App\Services\ProcessDepth\TenantProcessDepthResolver::class)->resolve($tenantModel) : ['key' => 'standard', 'label' => 'Standart Akış', 'source_label' => 'Paket varsayılanı', 'source' => 'package_default'];
    $depthPolicy = app(\App\Services\ProcessDepth\TenantProcessDepthPolicy::class)->forDepth((string) data_get($resolvedDepth, 'key', 'standard'));
    $depthKey = (string) data_get($resolvedDepth, 'key', 'standard');
    $depthLabel = (string) data_get($resolvedDepth, 'label', 'Standart Akış');
    $depthSourceLabel = (string) data_get($resolvedDepth, 'source_label', 'Paket varsayılanı');
    $isFastDepth = $depthKey === 'fast';
    $isControlledDepth = $depthKey === 'controlled';
    $showExtended = (bool) data_get($depthPolicy, 'show_extended_readiness_details', true);
    $showAdvancedTimeline = (bool) data_get($depthPolicy, 'show_advanced_activity_timeline', false);
    $canViewPurchasePrices = auth()->user()?->hasPermissionInTenant('view_procurement_purchase_prices', $procurement->tenant_account_id) ?? false;
    $canViewCurrentAccounts = auth()->user()?->hasPermissionInTenant('view_current_account_transactions', $procurement->tenant_account_id) ?? false;
    $canManage = auth()->user()?->hasPermissionInTenant('manage_procurement_requests', $procurement->tenant_account_id) ?? false;
    $showUrl = route('admin.procurements.show', $procurement);
    $orderUrl = route('admin.orders.show', $procurement->order);
    $linkedRequestUrl = $linkedRequest ? route('admin.procurements.supplier-requests.edit', $linkedRequest) : ($procurement->supplier_id ? route('admin.procurements.supplier-requests.create', ['supplier_id' => $procurement->supplier_id]) : null);
    $primaryAction = ['label' => 'Talebi Aç', 'url' => $linkedRequestUrl, 'kind' => 'link'];

    if ($canManage && $procurement->procurement_status === \App\Models\OrderItemProcurement::STATUS_REQUEST_CREATED) {
        $primaryAction = ['label' => 'Sipariş Verildi', 'url' => route('admin.procurements.update-status', $procurement), 'kind' => 'supplier_ordered'];
    } elseif ($canManage && in_array($procurement->procurement_status, [\App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED, \App\Models\OrderItemProcurement::STATUS_PARTIALLY_RECEIVED], true) && !$procurement->isFullyReceived()) {
        $primaryAction = ['label' => 'Gelen Ürün Kaydı', 'url' => $linkedRequest ? route('admin.procurements.supplier-requests.edit', $linkedRequest) . '#receipt-panel' : route('admin.procurements.update-status', $procurement), 'kind' => 'link'];
    } elseif ($procurement->isFullyReceived()) {
        $primaryAction = ['label' => 'Listeye Dön', 'url' => route('admin.procurements.index'), 'kind' => 'link'];
    }

    $timelineItems = collect($history ?? [])->take($showAdvancedTimeline ? 10 : ($isFastDepth ? 1 : 4))->values();
    $requestItem = $procurement->supplierRequestItems->first();
    $requestLine = $requestItem ? [
        'requested' => number_format((float) $requestItem->requested_quantity, 2, ',', '.'),
        'received' => number_format((float) $requestItem->received_quantity, 2, ',', '.'),
        'remaining' => number_format((float) $requestItem->remaining_quantity, 2, ',', '.'),
    ] : null;
    $purchaseSummary = null;
    if ($canViewPurchasePrices && $requestItem) {
        $sourceCurrency = (string) ($requestItem->purchase_source_currency ?? data_get($requestItem->purchase_price_snapshot, 'purchase_source_currency') ?? '');
        $sourceAmount = $requestItem->purchase_source_amount ?? data_get($requestItem->purchase_price_snapshot, 'purchase_source_amount');
        $listTry = $requestItem->purchase_list_price_try ?? data_get($requestItem->purchase_price_snapshot, 'purchase_list_price_try') ?? $requestItem->purchase_list_price;
        $fxRate = $requestItem->purchase_fx_rate ?? data_get($requestItem->purchase_price_snapshot, 'purchase_fx_rate');
        $fxRateDate = $requestItem->purchase_fx_rate_date ?? data_get($requestItem->purchase_price_snapshot, 'purchase_fx_rate_date');
        $calculatedUnit = $requestItem->purchase_calculated_unit_price ?? data_get($requestItem->purchase_price_snapshot, 'purchase_calculated_unit_price');
        $purchaseSummary = [
            'source' => $sourceAmount !== null && $sourceCurrency !== '' ? number_format((float) $sourceAmount, 2, ',', '.') . ' ' . $sourceCurrency : '-',
            'try_equivalent' => $listTry !== null ? number_format((float) $listTry, 2, ',', '.') . ' TL' : '-',
            'rate' => $sourceCurrency !== '' && $sourceCurrency !== 'TRY' && $fxRate !== null ? '1 ' . $sourceCurrency . ' = ' . number_format((float) $fxRate, 4, ',', '.') . ' TL' : null,
            'rate_date' => $sourceCurrency !== '' && $sourceCurrency !== 'TRY' && $fxRateDate ? \Illuminate\Support\Carbon::parse((string) $fxRateDate)->format('d.m.Y') : null,
            'discount' => number_format((float) ($requestItem->discount_rate ?? 0), 2, ',', '.') . ' %',
            'calculated_unit' => $calculatedUnit !== null ? number_format((float) $calculatedUnit, 2, ',', '.') . ' TL' : '-',
            'unit_price' => $requestItem->purchase_unit_price !== null ? number_format((float) $requestItem->purchase_unit_price, 2, ',', '.') . ' TL' : '-',
            'total' => $requestItem->purchase_total !== null ? number_format((float) $requestItem->purchase_total, 2, ',', '.') . ' TL' : '-',
            'manual_override' => (bool) ($requestItem->purchase_manual_override ?? false),
        ];
    }
@endphp

<style>
    .pd-page-stack,
    .pd-section-stack { display:grid; gap:14px; }
    .pd-card-stack { display:grid; gap:12px; }
    .pd-inline-stack { display:flex; gap:10px; flex-wrap:wrap; }
    .pd-tight-stack { display:grid; gap:8px; }
    .pd-two-column-layout { display:grid; grid-template-columns:minmax(0,1fr) 330px; gap:14px; align-items:start; }
    .prd-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 12px 28px rgba(15,23,42,.05); }
    .prd-body { padding:14px; }
    .prd-header { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start; }
    .prd-title { margin:0; font-size:15px; font-weight:700; color:#111827; }
    .prd-note { color:#6b7280; font-size:12px; line-height:1.5; }
    .prd-topbar { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .prd-breadcrumb { color:#64748b; font-size:12px; }
    .prd-focus { border:1px solid #dbe5f0; border-radius:10px; background:#f8fbff; padding:14px; }
    .prd-focus-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; margin-top:12px; }
    .prd-focus-box, .prd-box { border:1px solid #e5e7eb; border-radius:8px; background:#fff; padding:12px; }
    .prd-label { color:#6b7280; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .prd-value { margin-top:5px; color:#111827; font-size:14px; font-weight:700; line-height:1.45; }
    .prd-muted { margin-top:4px; color:#64748b; font-size:12px; }
    .prd-grid-3 { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; }
    .prd-process-steps { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; }
    .prd-step { border:1px solid #e5e7eb; border-radius:8px; background:#fbfdff; padding:12px; }
    .prd-step.is-active { border-color:#93c5fd; background:#eff6ff; }
    .prd-step.is-complete { border-color:#bbf7d0; background:#f0fdf4; }
    .prd-list { display:grid; gap:8px; }
    .prd-row { display:flex; justify-content:space-between; gap:12px; color:#475569; font-size:12px; }
    .prd-row strong { color:#111827; }
    .prd-history { display:grid; gap:8px; }
    .prd-history-item { border:1px solid #e5e7eb; border-radius:8px; background:#fff; padding:10px 12px; }
    .prd-sticky { position:sticky; top:16px; }
    .prd-helper-bar { border-top:1px solid #e5e7eb; padding-top:12px; }
    @media (max-width:1100px) {
        .pd-two-column-layout,
        .prd-focus-grid,
        .prd-grid-3,
        .prd-process-steps { grid-template-columns:1fr; }
        .prd-sticky { position:static; }
    }
</style>

@if(session('success'))
    <div class="pd-alert">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="pd-alert pd-alert-danger">{{ $errors->first() }}</div>
@endif

<div class="pd-page-stack" data-procurement-reference-family="detail" data-procurement-depth="{{ $depthKey }}" data-procurement-depth-marker="true">
    <section class="prd-card">
        <div class="prd-body pd-card-stack">
            <div class="prd-topbar">
                <div>
                    <div class="prd-breadcrumb"><a href="{{ route('admin.procurements.index') }}">Tedarik Talepleri</a> / Tedarik Detayı</div>
                    <h3 class="prd-title" style="margin-top:6px;">{{ data_get($snapshot, 'product_name', $procurement->orderItem?->product_name ?: 'Tedarik kaydı') }}</h3>
                    <div class="prd-note">Yeni tedarik referans ailesi içinde üst sıradaki iş, üç aşamalı süreç ve sağ sticky özet aynı dilde korunur.</div>
                </div>
                <div class="pd-inline-stack">
                    <span class="pd-badge pd-badge-gray">{{ $depthLabel }}</span>
                    <span class="pd-badge pd-badge-{{ $procurement->isFullyReceived() ? 'green' : ((float) $procurement->received_quantity > 0.0001 ? 'amber' : 'blue') }}">{{ $receiptLabel }}</span>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-two-column-layout">
        <div class="pd-page-stack">
            <section class="prd-card">
                <div class="prd-body pd-card-stack">
                    <div class="prd-focus" data-testid="procurement-top-next-action-surface">
                        <div class="prd-header">
                            <div>
                                <h3 class="prd-title">Üst Sıradaki İş</h3>
                                <div class="prd-note">Çalışma şekli: {{ $depthLabel }} | Kaynak: {{ $depthSourceLabel }}</div>
                            </div>
                            @if($primaryAction['kind'] === 'supplier_ordered')
                                <form method="POST" action="{{ $primaryAction['url'] }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="action" value="supplier_ordered">
                                    <button type="submit" class="pd-btn pd-btn-primary">{{ $primaryAction['label'] }}</button>
                                </form>
                            @elseif($primaryAction['url'])
                                <a href="{{ $primaryAction['url'] }}" class="pd-btn pd-btn-primary">{{ $primaryAction['label'] }}</a>
                            @endif
                        </div>
                        <div class="prd-focus-grid">
                            <div class="prd-focus-box"><div class="prd-label">Durum</div><div class="prd-value">{{ $procurement->safeStatusLabel() }}</div></div>
                            <div class="prd-focus-box"><div class="prd-label">Sıradaki İş</div><div class="prd-value">{{ $nextActionLabel }}</div></div>
                            <div class="prd-focus-box"><div class="prd-label">Talep</div><div class="prd-value">{{ $linkedRequest?->request_number ?: 'Henüz açılmadı' }}</div></div>
                            <div class="prd-focus-box"><div class="prd-label">Tedarikçi</div><div class="prd-value">{{ $supplierDisplay }}</div></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="prd-card">
                <div class="prd-body pd-card-stack">
                    <div>
                        <h3 class="prd-title">Ürün ve İhtiyaç</h3>
                        <div class="prd-note">İstenen, alınan ve kalan miktar aynı yüzeyde izlenir.</div>
                    </div>
                    <div class="prd-grid-3">
                        <div class="prd-box"><div class="prd-label">Sipariş No</div><div class="prd-value">{{ $snapshot['order_number'] ?? ($procurement->order?->document_number ?: '-') }}</div><div class="prd-muted">İş Formu: {{ $snapshot['work_form_number'] ?? ($procurement->workForm?->work_form_number ?: '-') }}</div></div>
                        <div class="prd-box"><div class="prd-label">Ürün</div><div class="prd-value">{{ data_get($snapshot, 'product_name', $procurement->orderItem?->product_name ?: '-') }}</div><div class="prd-muted">Kod: {{ data_get($snapshot, 'product_code', $procurement->orderItem?->product_code ?: '-') }}</div></div>
                        <div class="prd-box"><div class="prd-label">Müşteri</div><div class="prd-value">{{ $procurement->order?->customer?->legal_name ?: '-' }}</div><div class="prd-muted">İhtiyaç tipi: {{ $procurement->safeFulfillmentSourceLabel() }}</div></div>
                    </div>
                    <div class="prd-grid-3">
                        <div class="prd-box"><div class="prd-label">İstenen</div><div class="prd-value">{{ $requestedFormatted }} {{ $unitLabel }}</div></div>
                        <div class="prd-box"><div class="prd-label">Alınan</div><div class="prd-value">{{ $receivedFormatted }} {{ $unitLabel }}</div></div>
                        <div class="prd-box"><div class="prd-label">Kalan</div><div class="prd-value">{{ $remainingFormatted }} {{ $unitLabel }}</div></div>
                    </div>
                </div>
            </section>

            <section class="prd-card">
                <div class="prd-body pd-card-stack" data-testid="procurement-three-step-process">
                    <div>
                        <h3 class="prd-title">Üç Aşamalı Süreç</h3>
                        <div class="prd-note">Talep, sipariş ve gelen ürün adımları aynı akış kararını paylaşır; ayrıntı yoğunluğu yalnız çalışma şekline göre değişir.</div>
                    </div>
                    <div class="prd-process-steps">
                        <article class="prd-step {{ $linkedRequest ? 'is-complete' : 'is-active' }}">
                            <div class="prd-label">1. Talep</div>
                            <div class="prd-value">{{ $linkedRequest ? ($linkedRequest->request_number ?: 'Talep hazırlandı') : 'Talep hazırlanacak' }}</div>
                            <div class="prd-muted">{{ $linkedRequest ? $linkedRequest->safeStatusLabel() : 'Gerçek açık ihtiyaçtan yeni talep açılır.' }}</div>
                        </article>
                        <article class="prd-step {{ in_array($procurement->procurement_status, [\App\Models\OrderItemProcurement::STATUS_REQUEST_CREATED, \App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED, \App\Models\OrderItemProcurement::STATUS_PARTIALLY_RECEIVED, \App\Models\OrderItemProcurement::STATUS_FULLY_RECEIVED], true) ? 'is-active' : '' }} {{ in_array($procurement->procurement_status, [\App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED, \App\Models\OrderItemProcurement::STATUS_PARTIALLY_RECEIVED, \App\Models\OrderItemProcurement::STATUS_FULLY_RECEIVED], true) ? 'is-complete' : '' }}">
                            <div class="prd-label">2. Sipariş</div>
                            <div class="prd-value">{{ in_array($procurement->procurement_status, [\App\Models\OrderItemProcurement::STATUS_SUPPLIER_ORDERED, \App\Models\OrderItemProcurement::STATUS_PARTIALLY_RECEIVED, \App\Models\OrderItemProcurement::STATUS_FULLY_RECEIVED], true) ? 'Sipariş verildi' : 'Sipariş onayı bekleniyor' }}</div>
                            <div class="prd-muted">Talep tedarikçiye iletilince sipariş aşaması ilerler.</div>
                        </article>
                        <article class="prd-step {{ (float) $procurement->received_quantity > 0.0001 || $procurement->isFullyReceived() ? 'is-active' : '' }} {{ $procurement->isFullyReceived() ? 'is-complete' : '' }}">
                            <div class="prd-label">3. Gelen Ürün</div>
                            <div class="prd-value">{{ $receiptLabel }}</div>
                            <div class="prd-muted">Kısmi giriş gerçek receipt servisiyle işlenir; tam girişte kayıt kapanır.</div>
                        </article>
                    </div>
                </div>
            </section>

            @if(!$isFastDepth)
                <section class="prd-card">
                    <div class="prd-body pd-card-stack">
                        <div>
                            <h3 class="prd-title">Kısa Faaliyet Geçmişi</h3>
                            <div class="prd-note">{{ $isControlledDepth ? 'Kontrollü Akışta daha ayrıntılı kayıtlar korunur.' : 'Standart Akışta yalnız gerekli geçmiş görünür.' }}</div>
                        </div>
                        <div class="prd-history" data-testid="procurement-history-surface">
                            @forelse($timelineItems as $log)
                                <article class="prd-history-item">
                                    <div class="prd-label">{{ optional($log->created_at)->format('d.m.Y H:i') ?: '-' }}</div>
                                    <div class="prd-value">{{ $log->note ?: 'İşlem kaydı' }}</div>
                                    @if($isControlledDepth)
                                        <div class="prd-muted">{{ $log->visibility === 'customer_visible' ? 'Müşteriye açık kayıt' : 'İç kayıt' }}</div>
                                    @endif
                                </article>
                            @empty
                                <div class="prd-box">Henüz faaliyet kaydı görünmüyor.</div>
                            @endforelse
                        </div>
                    </div>
                </section>
            @endif
        </div>

        <aside class="prd-sticky pd-section-stack" data-testid="procurement-sticky-summary">
            <section class="prd-card">
                <div class="prd-body pd-card-stack">
                    <div>
                        <h3 class="prd-title">Sağ Kısa Özet</h3>
                        <div class="prd-note">Teknik ham alanlar, ID veya path gösterilmez.</div>
                    </div>
                    <div class="prd-list">
                        <div class="prd-row"><span>Çalışma şekli</span><strong>{{ $depthLabel }}</strong></div>
                        <div class="prd-row"><span>Sipariş</span><strong>{{ $snapshot['order_number'] ?? ($procurement->order?->document_number ?: '-') }}</strong></div>
                        <div class="prd-row"><span>Talep No</span><strong>{{ $linkedRequest?->request_number ?: '-' }}</strong></div>
                        <div class="prd-row"><span>Tedarikçi</span><strong>{{ $supplierDisplay }}</strong></div>
                        <div class="prd-row"><span>İstenen / Kalan</span><strong>{{ $requestedFormatted }} / {{ $remainingFormatted }} {{ $unitLabel }}</strong></div>
                        @if($showExtended)
                            <div class="prd-row"><span>Gelen durumu</span><strong>{{ $receiptLabel }}</strong></div>
                        @endif
                    </div>
                    <div class="prd-helper-bar pd-inline-stack">
                        <a href="{{ route('admin.procurements.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                        <a href="{{ $orderUrl }}" class="pd-btn pd-btn-light">Siparişe Git</a>
                        @if($procurement->workForm)
                            <a href="{{ route('admin.work-forms.show', $procurement->workForm) }}" class="pd-btn pd-btn-light">İş Formu Aç</a>
                        @endif
                    </div>
                </div>
            </section>

            @if($isControlledDepth && $requestLine)
                <section class="prd-card">
                    <div class="prd-body pd-card-stack">
                        <div>
                            <h3 class="prd-title">Kontrol Özeti</h3>
                            <div class="prd-note">Talep satırı karşılaştırması yalnız Kontrollü Akışta açılır.</div>
                        </div>
                        <div class="prd-list">
                            <div class="prd-row"><span>Talep satırı</span><strong>{{ $linkedRequest?->request_number ?: '-' }}</strong></div>
                            <div class="prd-row"><span>İstenen</span><strong>{{ $requestLine['requested'] }}</strong></div>
                            <div class="prd-row"><span>Alınan</span><strong>{{ $requestLine['received'] }}</strong></div>
                            <div class="prd-row"><span>Kalan</span><strong>{{ $requestLine['remaining'] }}</strong></div>
                        </div>
                    </div>
                </section>
            @endif

            @if($isControlledDepth && $canViewCurrentAccounts && $supplierCompanyMatch)
                <section class="prd-card">
                    <div class="prd-body pd-card-stack">
                        <div>
                            <h3 class="prd-title">Tedarikçi Cari Bağlantısı</h3>
                            <div class="prd-note">Bu alan yalnız mevcut cari görüntüleme yetkisiyle açılır.</div>
                        </div>
                        <div class="prd-box">
                            <div class="prd-label">Eşleşen Cari</div>
                            <div class="prd-value">{{ $supplierCompanyMatch['company_name'] }}</div>
                        </div>
                        <a href="{{ route('admin.companies.show', $supplierCompanyMatch['company_id']) }}" class="pd-btn pd-btn-light">Cari Kartı Aç</a>
                    </div>
                </section>
            @endif

            @if($isControlledDepth && $purchaseSummary)
                <section class="prd-card">
                    <div class="prd-body pd-card-stack">
                        <div>
                            <h3 class="prd-title">Alış Özeti</h3>
                            <div class="prd-note">Bu blok yalnız iç yetkili kullanıcıya görünür.</div>
                        </div>
                        <div class="prd-list">
                            <div class="prd-row"><span>Tedarikçi liste</span><strong>{{ $purchaseSummary['source'] }}</strong></div>
                            <div class="prd-row"><span>TL karşılığı</span><strong>{{ $purchaseSummary['try_equivalent'] }}</strong></div>
                            @if($purchaseSummary['rate'])
                                <div class="prd-row"><span>Kur</span><strong>{{ $purchaseSummary['rate'] }}</strong></div>
                            @endif
                            @if($purchaseSummary['rate_date'])
                                <div class="prd-row"><span>Kur tarihi</span><strong>{{ $purchaseSummary['rate_date'] }}</strong></div>
                            @endif
                            <div class="prd-row"><span>Alış iskontosu</span><strong>{{ $purchaseSummary['discount'] }}</strong></div>
                            <div class="prd-row"><span>Hesaplanan</span><strong>{{ $purchaseSummary['calculated_unit'] }}</strong></div>
                            <div class="prd-row"><span>Alış birim</span><strong>{{ $purchaseSummary['unit_price'] }}</strong></div>
                            <div class="prd-row"><span>Toplam</span><strong>{{ $purchaseSummary['total'] }}</strong></div>
                            @if($purchaseSummary['manual_override'])
                                <div class="prd-row"><span>Override</span><strong>Manuel</strong></div>
                            @endif
                        </div>
                    </div>
                </section>
            @endif
        </aside>
    </section>
</div>
@endsection
