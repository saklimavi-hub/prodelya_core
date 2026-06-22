@extends('layouts.prodelya-admin')

@section('title', 'CSV/Excel Import')
@section('page_title', 'CSV/Excel Import')
@section('page_subtitle', 'Standart kategori ağacı için import yönlendirme ekranı.')

@section('page_actions')
<div class="pd-actions-wrap">
    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
    <a href="{{ route('admin.super.standard-categories.template') }}" class="pd-btn pd-btn-light">Şablon İndir</a>
</div>
@endsection

@section('content')
<div class="pd-card">
    <div class="pd-card-header">
        <h3 class="pd-card-title">CSV/Excel Import</h3>
        <p class="pd-card-subtitle">Gerçek Excel parser bu aşamada dahil değildir.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-note">
            Gerçek Excel parser sonraki aşamada geliştirilecek. Şimdilik Toplu Kopyala/Yapıştır ekranını kullanabilirsiniz.
        </div>

        <div class="flex gap-2 flex-wrap mt-3">
            <a href="{{ route('admin.super.standard-categories.bulk-paste') }}" class="pd-btn pd-btn-primary">Toplu Kopyala/Yapıştır’a Git</a>
            <a href="{{ route('admin.super.standard-categories.template') }}" class="pd-btn pd-btn-light">Şablon İndir</a>
            <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
        </div>
    </div>
</div>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Import Notları</h3>
        <div class="pd-summary-list">
            <span class="pd-summary-item">Gerçek CSV parser sonraki aşamada geliştirilecek.</span>
            <span class="pd-summary-item">Gerçek Excel parser sonraki aşamada geliştirilecek.</span>
            <span class="pd-summary-item">Şimdilik önerilen yöntem Toplu Kopyala/Yapıştır ekranıdır.</span>
        </div>
    </div>
</div>
@endsection
