@extends('layouts.prodelya-admin')

@section('title', $supplier->name . ' Kaynak Detayı')
@section('page_title', $supplier->name . ' Kaynak Detayı')
@section('page_subtitle', 'Bu ekranda seçili tedarikçinin kaynakları, sync raporları ve tenant katalog etkisi tek yerde açılır. Günlük supplier listesi sadece özet gösterir.')

@section('page_actions')
    <a href="{{ $panelBackRoute }}" class="pd-btn pd-btn-light">Tedarikçi Listesine Dön</a>
@endsection

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-kpi-strip">
        <div class="pd-metric-card pd-metric-card-soft-blue"><div class="pd-metric-card-label">Toplam Kaynak</div><div class="pd-metric-card-value">{{ $stats['total_sources'] }}</div><div class="pd-metric-card-note">Bu tedarikçiye bağlı</div></div>
        <div class="pd-metric-card pd-metric-card-soft-green"><div class="pd-metric-card-label">Aktif Kaynak</div><div class="pd-metric-card-value">{{ $stats['active_sources'] }}</div><div class="pd-metric-card-note">Günlük akışta kullanılan</div></div>
        <div class="pd-metric-card pd-metric-card-soft-amber"><div class="pd-metric-card-label">Review</div><div class="pd-metric-card-value">{{ $stats['review_total'] }}</div><div class="pd-metric-card-note">İnceleme bekleyen kayıt</div></div>
        <div class="pd-metric-card pd-metric-card-soft-red"><div class="pd-metric-card-label">Projection Bekleyen</div><div class="pd-metric-card-value">{{ $stats['projection_pending'] }}</div><div class="pd-metric-card-note">Tenant kataloga yansımayı bekliyor</div></div>
        <div class="pd-metric-card pd-metric-card-soft-slate"><div class="pd-metric-card-label">Tenant Katalog</div><div class="pd-metric-card-value">{{ $stats['tenant_catalog_products'] }}</div><div class="pd-metric-card-note">{{ $stats['tenant_catalog_variants'] }} varyant</div></div>
    </section>

    <div class="pd-note">Kaynaklar / XML-JSON ayarları, son güncelleme, değişen ürünler, kategori eşleme ve teknik kayıtlar aşağıdaki source kartlarından açılır.</div>

    <div class="pd-source-list">
        @foreach($sources as $source)
            @include('super-admin.product-data-hub.sources._source-detail-card', ['source' => $source])
        @endforeach
    </div>
</div>
@endsection
