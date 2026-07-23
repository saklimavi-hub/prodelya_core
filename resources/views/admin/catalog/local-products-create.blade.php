@extends('layouts.prodelya-admin')

@section('title', $editProduct ? 'Ürünü Düzenle' : 'Yeni Ürün Ekle')
@section('page_title', $editProduct ? 'Ürünü Düzenle' : 'Yeni Ürün Ekle')
@section('page_subtitle', 'Kendi ürünlerinizi aynı form düzeniyle ekleyin ve güncelleyin.')

@section('content')
@php
    $previewImage = old('image_url', $editProduct?->image_url);
    $previewPrice = old('display_price', $editProduct?->display_price);
    $previewCurrency = old('currency', $editProduct?->currency ?? 'TL');
@endphp
<div class="pd-local-product-shell">
    @if(session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="pd-alert-warning">{{ session('error') }}</div>
    @endif

    @include('admin.catalog.partials._local-products-subnav')

    <section class="pd-hero-card pd-local-product-hero">
        <div class="pd-card-body">
            <div class="pd-local-product-hero-row">
                <div>
                    <span class="pd-local-product-eyebrow">Abone Firma · Ürün Formu</span>
                    <h1 class="pd-local-product-hero-title">{{ $editProduct ? 'Kendi Ürünümü Düzenle' : 'Yeni Ürün Ekle' }}</h1>
                    <p class="pd-local-product-hero-subtitle">Ürün bilgilerini, fiyatı, görseli ve görünürlük ayarlarını tek form üzerinden kolayca yönetin.</p>
                    <div class="pd-local-product-stat-strip pd-local-product-stat-strip-3">
                        <div class="pd-local-product-stat"><span>Kaynak</span><strong>Kendi Ürünüm</strong></div>
                        <div class="pd-local-product-stat"><span>Form düzeni</span><strong>Tek ekran</strong></div>
                        <div class="pd-local-product-stat"><span>Görsel alanı</span><strong>Dosya + bağlantı</strong></div>
                    </div>
                </div>
                <div class="pd-local-product-hero-actions">
                    <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-light">Ürün Listem</a>
                    <a href="{{ route('admin.catalog.local-products.import') }}" class="pd-btn pd-btn-light">Dosyadan Ürün Aktar</a>
                </div>
            </div>
        </div>
    </section>

    <div class="pd-local-product-layout">
        <section class="pd-section-card pd-local-product-main-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">{{ $editProduct ? 'Ürün Düzenleme Formu' : 'Yeni Ürün Formu' }}</h3>
                    <p class="pd-section-subtitle">Ürün kartı, fiyat ve görünürlük alanları tek bir düzenli akışta sunulur.</p>
                </div>
            </div>
            <div class="pd-section-body">
                @include('admin.catalog.partials._local-product-form')
            </div>
        </section>

        <aside class="pd-local-product-sidebar">
            <section class="pd-section-card pd-local-product-summary-card">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Canlı Önizleme</h3>
                        <p class="pd-section-subtitle">Form alanlarını doldurdukça önizleme kartı anlık olarak güncellenir.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-local-product-preview-card">
                        <div class="pd-local-product-preview-media">
                            <img src="{{ $previewImage }}" alt="Ürün görsel önizlemesi" class="pd-allow-large" data-local-product-preview-image @if(blank($previewImage)) hidden @endif>
                            <div class="pd-local-product-preview-empty" data-local-product-preview-empty @if(filled($previewImage)) hidden @endif>Görsel önizlemesi burada görünecek.</div>
                        </div>
                        <div class="pd-local-product-preview-body">
                            <span class="pd-badge pd-badge-purple">Kendi Ürünüm</span>
                            <h4>{{ old('product_name', $editProduct?->display_name ?: 'Ürün adı') }}</h4>
                            <div class="pd-local-product-preview-meta">
                                <span>SKU: {{ old('product_code', $editProduct?->display_code ?: '-') }}</span>
                                <span>{{ filled($previewPrice) ? number_format((float) $previewPrice, 2, ',', '.') . ' ' . $previewCurrency : 'Fiyat girilmedi' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="pd-local-product-sidebar-list">
                        <div class="pd-local-product-sidebar-row"><span>Katalog</span><strong>{{ old('visible_in_catalog', $editProduct?->visible_in_catalog ?? true) ? 'Açık' : 'Kapalı' }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Teklif</span><strong>{{ old('visible_in_quote', $editProduct?->visible_in_quote ?? true) ? 'Açık' : 'Kapalı' }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Aktiflik</span><strong>{{ old('is_active', $editProduct?->is_active ?? true) ? 'Aktif' : 'Pasif' }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Görsel kaynağı</span><strong>{{ filled($previewImage) ? 'Hazır' : 'Bekleniyor' }}</strong></div>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection
