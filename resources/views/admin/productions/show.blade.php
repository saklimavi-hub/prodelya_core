@extends('layouts.prodelya-admin')

@section('title', 'Üretim Detayı')
@section('page_topbar_hidden', true)
@section('hide_side_summary', true)

@section('content')
@php
    use App\Models\OrderItemPrintProduction;

    $snapshot = is_array($production->production_snapshot) ? $production->production_snapshot : [];
    $detail = $detailPresentation ?? [];
    $unit = $detail['unit'] ?? ($snapshot['unit'] ?? ($production->orderItem?->unit ?: 'Adet'));
    $plannedQuantity = (float) ($detail['planned'] ?? $production->planned_quantity);
    $completedQuantity = (float) ($detail['completed'] ?? $production->completed_quantity);
    $remainingQuantity = (float) ($detail['remaining'] ?? $production->remaining_quantity);
    $progressPercent = (int) ($detail['progress_percent'] ?? 0);
    $formattedPlannedQuantity = OrderItemPrintProduction::formatDisplayQuantity($plannedQuantity);
    $formattedCompletedQuantity = OrderItemPrintProduction::formatDisplayQuantity($completedQuantity);
    $formattedRemainingQuantity = OrderItemPrintProduction::formatDisplayQuantity($remainingQuantity);
    $statusLabel = $detail['status_label'] ?? $production->safeStatusLabel();
    $statusTone = $detail['status_tone'] ?? 'blue';
    $productionTypeLabel = data_get($snapshot, 'production_type_label') ?: ($production->safeProductionTypeLabel() ?: 'Belirlenmedi');
    $orderRoute = $production->order ? route('admin.orders.show', $production->order) : null;
    $workFormRoute = $production->workForm ? route('admin.work-forms.show', $production->workForm) : null;
    $orderNumber = $snapshot['order_number'] ?? ($production->order?->document_number ?: 'Belirtilmedi');
    $workFormNumber = $snapshot['work_form_number'] ?? ($production->workForm?->work_form_number ?: 'Belirtilmedi');
    $customerName = $production->order?->customer?->legal_name ?: 'Belirtilmemiş';
    $productName = $snapshot['product_name'] ?? ($production->orderItem?->product_name ?: 'Üretim Kaydı');
    $productCode = $snapshot['product_code'] ?? ($production->orderItem?->product_code ?: null);
    $printSequence = $snapshot['print_sequence'] ?? ($production->orderItemPrint?->sequence_code ?: '-');
    $printType = $production->orderItemPrint?->displayPrintType() ?: ($snapshot['print_type'] ?? 'Baskı tekniği');
    $printOption = $snapshot['print_option'] ?? ($production->orderItemPrint?->print_option ?: '-');
    $operatorOrPartner = $production->productionCompany?->legal_name ?: ($production->assignedUser?->name ?: 'Planlanmadı');
    $productionUnit = $production->production_unit_name ?: ($production->productionCompany?->short_name ?: '-');
    $productImageUrl = $detail['product_image_url'] ?? null;
    $graphicUrl = $detail['graphic_url'] ?? null;
    $graphicIsImage = (bool) ($detail['graphic_is_image'] ?? false);
    $next = $detail['next'] ?? [
        'message' => $nextActionLabel ?? 'Sıradaki işlem bekleniyor.',
        'label' => $canonicalActionLabel,
        'url' => route($canonicalRouteName, $production),
        'show_cta' => true,
    ];
    $canTransferToSubcontract = OrderItemPrintProduction::normalizeProductionType($production->production_type ?: $production->orderItemPrint?->production_type) === OrderItemPrintProduction::TYPE_INTERNAL
        && !in_array($production->production_status, [OrderItemPrintProduction::STATUS_COMPLETED, OrderItemPrintProduction::STATUS_CANCELLED], true)
        && $remainingQuantity > 0.0;
@endphp

<div class="pd-ui-v1-production-detail pd-production-detail" data-production-id="{{ $production->id }}" data-print-row-id="{{ $production->order_item_print_id }}">
    @if(session('success'))
        <div class="pd-alert pd-alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="pd-alert pd-alert-warning">{{ $errors->first() }}</div>
    @endif

    <section class="pd-production-detail__header">
        <div>
            <span class="pd-production-detail__eyebrow">Üretim Detayı · Exact Baskı</span>
            <h2>{{ $orderNumber }} · {{ $printSequence }} · {{ $printType }}</h2>
            <p>{{ $productName }} @if(filled($productCode)) · {{ $productCode }} @endif</p>
            <div class="pd-production-detail__meta-line">
                <span>{{ $customerName }}</span>
                <span>{{ $productionTypeLabel }}</span>
                <span class="pd-production-detail__status pd-production-detail__status--{{ $statusTone }}">{{ $statusLabel }}</span>
            </div>
            <div class="pd-production-detail__meta-line pd-production-detail__meta-line--soft">
                <span>İş Formu {{ $workFormNumber }}</span>
                <span>Üretim No {{ $production->id }}</span>
            </div>
        </div>
        <div class="pd-production-detail__header-actions">
            @if($orderRoute)
                <a href="{{ $orderRoute }}" class="pd-production-detail__secondary">Siparişi Aç</a>
            @endif
            @if($workFormRoute)
                <a href="{{ $workFormRoute }}" class="pd-production-detail__secondary">İş Formu</a>
            @endif
            @if($canTransferToSubcontract)
                <a href="{{ route('admin.productions.operator', $production) }}#route-transfer-panel" class="pd-production-detail__secondary">Fasona Devret</a>
            @endif
        </div>
    </section>

    <section class="pd-production-detail__metrics" aria-label="Miktar ve durum özeti">
        <article><span>Planlanan</span><strong>{{ $formattedPlannedQuantity }}</strong><small>{{ $unit }}</small></article>
        <article><span>Tamamlanan</span><strong>{{ $formattedCompletedQuantity }}</strong><small>{{ $unit }}</small></article>
        <article><span>Kalan</span><strong>{{ $formattedRemainingQuantity }}</strong><small>{{ $unit }}</small></article>
        <article><span>İlerleme</span><strong>%{{ $progressPercent }}</strong><small>{{ $statusLabel }}</small></article>
        <article><span>Durum</span><strong>{{ $statusLabel }}</strong><small>{{ $productionTypeLabel }}</small></article>
    </section>

    <section class="pd-production-detail__process" aria-label="Süreç durumu">
        @foreach(($detail['steps'] ?? []) as $step)
            <article class="pd-production-detail__step pd-production-detail__step--{{ $step['state'] }}">
                <span>{{ $step['label'] }}</span>
                <strong>{{ $step['status'] }}</strong>
            </article>
        @endforeach
    </section>

    <section class="pd-production-detail__summary" aria-label="Kompakt üretim özeti">
        <div class="pd-production-detail__product-card">
            <div class="pd-production-detail__image-frame">
                @if($productImageUrl)
                    <img src="{{ $productImageUrl }}" alt="{{ $productName }}">
                @else
                    <span>Ürün Görseli Yok</span>
                @endif
            </div>
            <div>
                <span class="pd-production-detail__kicker">Ürün / Exact Baskı</span>
                <h3>{{ $productName }}</h3>
                <dl class="pd-production-detail__rows">
                    @if(filled($productCode))<div><dt>SKU</dt><dd>{{ $productCode }}</dd></div>@endif
                    <div><dt>Baskı</dt><dd>{{ $printSequence }} · {{ $printType }}</dd></div>
                    <div><dt>Seçenek</dt><dd>{{ $printOption }}</dd></div>
                    <div><dt>Grafik</dt><dd>{{ $detail['graphic_label'] ?? '-' }}</dd></div>
                    <div><dt>Tedarik</dt><dd>{{ $detail['procurement_label'] ?? '-' }}</dd></div>
                </dl>
            </div>
        </div>
        <div class="pd-production-detail__assignment-card">
            <div>
                <span class="pd-production-detail__kicker">Atama / Grafik</span>
                <h3>{{ $operatorOrPartner }}</h3>
                <dl class="pd-production-detail__rows">
                    <div><dt>Üretim yolu</dt><dd>{{ $productionTypeLabel }}</dd></div>
                    <div><dt>Birim / Firma</dt><dd>{{ $productionUnit }}</dd></div>
                    <div><dt>QC kaynağı</dt><dd>{{ !empty($detail['qc_required']) ? 'Kalite kontrol gerekli' : 'Kalite kontrol gerekli değil' }}</dd></div>
                </dl>
            </div>
            <div class="pd-production-detail__graphic-frame">
                @if($graphicUrl && $graphicIsImage)
                    <a href="{{ $graphicUrl }}" target="_blank" rel="noopener"><img src="{{ $graphicUrl }}" alt="Onaylı grafik"></a>
                @elseif($graphicUrl)
                    <a href="{{ $graphicUrl }}" target="_blank" rel="noopener">Grafiği Aç</a>
                @else
                    <span>Final grafik yok</span>
                @endif
            </div>
        </div>
    </section>

    <section class="pd-production-detail__next" aria-label="Sıradaki işlem">
        <div>
            <span class="pd-production-detail__kicker">Sıradaki İşlem</span>
            <h3>{{ $next['message'] ?? 'Sıradaki işlem bekleniyor.' }}</h3>
        </div>
        @if($next['show_cta'] ?? true)
            <a href="{{ $next['url'] }}" class="pd-production-detail__primary">{{ $next['label'] }}</a>
        @endif
    </section>

    <details class="pd-production-detail__collapse">
        <summary>Teknik / Kayıt Detayları</summary>
        <div class="pd-production-detail__technical-grid">
            <div><span>Üretim No</span><strong>{{ $production->id }}</strong></div>
            <div><span>Son Güncelleme</span><strong>{{ optional($production->updated_at)->format('d.m.Y H:i') ?: '-' }}</strong></div>
            <div><span>Başlangıç</span><strong>{{ optional($production->started_at)->format('d.m.Y H:i') ?: 'Henüz başlamadı' }}</strong></div>
            <div><span>Bitiş</span><strong>{{ optional($production->completed_at)->format('d.m.Y H:i') ?: 'Henüz tamamlanmadı' }}</strong></div>
        </div>
    </details>

    <details class="pd-production-detail__collapse" @if($activeTab === 'fotograflar') open @endif>
        <summary>Fotoğraflar</summary>
        @include('admin.productions.partials._production_photos')
    </details>

    <details class="pd-production-detail__collapse" @if($activeTab === 'gecmis') open @endif>
        <summary>Geçmiş</summary>
        @include('admin.productions.partials._production_history')
    </details>
</div>
@endsection
