@extends('layouts.prodelya-admin')

@section('title', $supplier->name . ' Kaynak Detayı')
@section('page_title', $supplier->name . ' Kaynak Detayı')
@section('page_subtitle', 'Bu ekranda seçili tedarikçinin kaynakları, sync raporları ve tenant katalog etkisi tek yerde açılır. Günlük supplier listesi sadece özet gösterir.')

@section('page_actions')
    <a href="{{ $panelBackRoute }}" class="pd-btn pd-btn-light">Tedarikçi Listesine Dön</a>
@endsection

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-card pd-section-card pd-product-hub__setup-flow mb-6">
        <div class="pd-card-body">
            <div class="pd-hub-section-head">
                <div>
                    <div class="pd-hub-section-title">Tedarikçi Bazlı Birleşik Kurulum Akışı</div>
                    <div class="pd-hub-section-copy">Bu tedarikçi için kaynak bilgisi, ön kontrol, alan eşleme, ilk kategori eşleme ve otomatik senkron hazırlığı aynı sayfada takip edilir. Yalnız sorunlu kayıtlar Bekleyen Kontroller alanına düşer.</div>
                </div>
            </div>
            <div class="pd-grid pd-grid-3">
                <div class="pd-note"><strong>Kaynak Bilgisi</strong><br>Kaynak adı, format, bağlantı ve sıklık bilgisi.</div>
                <div class="pd-note"><strong>Ön Kontrol</strong><br>5-10 örnek ürün, fiyat, stok, görsel ve varyant okuması.</div>
                <div class="pd-note"><strong>Alan Eşleme</strong><br>Eksik zorunlu alanlar burada akışı durdurur.</div>
                <div class="pd-note"><strong>İlk Kategori Eşleme</strong><br>Yeni kategori gelirse burada karar verilir.</div>
                <div class="pd-note"><strong>Toplu Kategori Değiştir</strong><br>Benzer kategoriler için toplu karar ekranına gidilir.</div>
                <div class="pd-note"><strong>Otomatik Senkron</strong><br>Hazır kayıtlar Abone Firma ürün listesine ve teklif/sipariş ürün seçimine otomatik yansır.</div>
            </div>
        </div>
    </section>

    <section class="pd-kpi-strip">
        <div class="pd-metric-card pd-metric-card-soft-blue"><div class="pd-metric-card-label">Toplam Kaynak</div><div class="pd-metric-card-value">{{ $stats['total_sources'] }}</div><div class="pd-metric-card-note">Bu tedarikçiye bağlı</div></div>
        <div class="pd-metric-card pd-metric-card-soft-green"><div class="pd-metric-card-label">Aktif Kaynak</div><div class="pd-metric-card-value">{{ $stats['active_sources'] }}</div><div class="pd-metric-card-note">Günlük akışta kullanılan</div></div>
        <div class="pd-metric-card pd-metric-card-soft-amber"><div class="pd-metric-card-label">Review</div><div class="pd-metric-card-value">{{ $stats['review_total'] }}</div><div class="pd-metric-card-note">İnceleme bekleyen kayıt</div></div>
        <div class="pd-metric-card pd-metric-card-soft-red"><div class="pd-metric-card-label">Projection Bekleyen</div><div class="pd-metric-card-value">{{ $stats['projection_pending'] }}</div><div class="pd-metric-card-note">Tenant kataloga yansımayı bekliyor</div></div>
        <div class="pd-metric-card pd-metric-card-soft-slate"><div class="pd-metric-card-label">Tenant Katalog</div><div class="pd-metric-card-value">{{ $stats['tenant_catalog_products'] }}</div><div class="pd-metric-card-note">{{ $stats['tenant_catalog_variants'] }} varyant</div></div>
    </section>

    <div class="pd-note pd-product-hub__auto-note">Kaynaklar / XML-JSON ayarları, ön kontrol, alan eşleme, kategori eşleme ve bekleyen kararlar aşağıdaki source kartlarından açılır. Normal kullanımda ekstra havuza/kataloğa/teklife gönder adımı beklenmez.</div>

    <div class="pd-source-list">
        @foreach($sources as $source)
            @include('super-admin.product-data-hub.sources._source-detail-card', ['source' => $source])
        @endforeach
    </div>
</div>
@endsection
