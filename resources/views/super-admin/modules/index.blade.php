@extends('layouts.prodelya-admin')

@section('title', 'Super Admin Modüller')

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Super Admin Modüller</h1>
                    <p class="pd-hero-subtitle">Modül yönetimi ekranı Product Data Hub ailesiyle aynı görsel dile yaklaştırıldı. İçerik şu an stabilizasyon aşamasında tutuluyor.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.settings') }}" class="pd-btn pd-btn-light">Super Ayarlar</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-purple">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Modül Durumu</h3>
                <p class="pd-section-subtitle">Bu ekran stabilizasyon aşamasında placeholder olarak açık tutulmuştur.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-mini-grid">
                <div class="pd-mini-link-card">
                    <div class="pd-mini-link-title">Yönetim görünümü</div>
                    <div class="pd-mini-link-copy">Modül kapsamı daha sonra genişletilecek.</div>
                </div>
                <div class="pd-mini-link-card">
                    <div class="pd-mini-link-title">Template uyumu</div>
                    <div class="pd-mini-link-copy">Product Data Hub ailesi ile aynı kart dili uygulandı.</div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection
