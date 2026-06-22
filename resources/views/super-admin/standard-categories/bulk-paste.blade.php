@extends('layouts.prodelya-admin')

@section('title', 'Toplu Kopyala/Yapıştır')
@section('page_title', 'Toplu Kopyala/Yapıştır')
@section('page_subtitle', 'Kategori ağacını satır satır yapıştırarak hızlı toplu import önizlemesi oluşturun.')

@section('page_actions')
<div class="pd-actions-wrap">
    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
    <a href="{{ route('admin.super.standard-categories.template') }}" class="pd-btn pd-btn-light">Şablon İndir</a>
</div>
@endsection

@section('content')
<div class="pd-grid pd-grid-2">
    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Toplu Yapıştır</h3>
            <p class="pd-card-subtitle">Her satır: code ; name ; parent_code ; product_family ; sort_order</p>
        </div>
        <div class="pd-card-body">
            <form method="POST" action="{{ route('admin.super.standard-categories.bulk-paste.preview') }}">
                @csrf
                <textarea name="bulk_text" class="pd-textarea" rows="16" placeholder="code;name;parent_code;product_family;sort_order&#10;PROMO;Promosyon Ürünleri;;promotion;1&#10;PROMO-KALEMLER;Kalemler;PROMO;promotion;10&#10;PROMO-KALEMLER-PLASTIK;Plastik Kalemler;PROMO-KALEMLER;promotion;100">{{ old('bulk_text') }}</textarea>
                <div class="mt-3">
                    <button type="submit" class="pd-btn pd-btn-primary">Önizle</button>
                </div>
            </form>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Format Rehberi</h3>
            <p class="pd-card-subtitle">Kaydetmeden önce önizleme ekranında satırlar kontrol edilir.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-note">
                <strong>Örnek Format</strong><br>
                <code>code ; name ; parent_code ; product_family ; sort_order</code>
            </div>

            <div class="pd-summary-section mt-4">
                <h4 class="pd-summary-section-title">Örnek Satırlar</h4>
                <div class="pd-summary-list">
                    <span class="pd-summary-item">code;name;parent_code;product_family;sort_order</span>
                    <span class="pd-summary-item">PROMO;Promosyon Ürünleri;;promotion;1</span>
                    <span class="pd-summary-item">PROMO-KALEMLER;Kalemler;PROMO;promotion;10</span>
                    <span class="pd-summary-item">PRINT;Matbaa Teklif ve Sipariş Ürünleri;;print;2</span>
                </div>
            </div>

            <div class="pd-note mt-3">
                Kod zaten varsa kayıt güncellenecek. Gerçek Excel parser bu aşamada yok; bu ekran önerilen toplu giriş yöntemidir.
            </div>
        </div>
    </div>
</div>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Kategori Notları</h3>
        <div class="pd-summary-list">
            <span class="pd-summary-item">Bu akış global kategori ağacı için çalışır.</span>
            <span class="pd-summary-item">Tenant bu toplu aktarımı kullanamaz.</span>
            <span class="pd-summary-item">Preview ekranında yeni kayıt ve güncellenecek satırlar ayrılır.</span>
        </div>
    </div>
</div>
@endsection
