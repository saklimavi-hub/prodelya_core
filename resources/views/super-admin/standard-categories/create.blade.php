@extends('layouts.prodelya-admin')

@section('title', 'Yeni Standart Kategori')
@section('page_title', 'Yeni Standart Kategori')
@section('page_subtitle', 'Global kategori ağacına yeni kategori ekleyin.')

@section('page_actions')
<div class="pd-actions-wrap">
    <span class="pd-badge pd-badge-purple">Super Admin</span>
    <span class="pd-badge pd-badge-blue">Global Ağaç</span>
    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
</div>
@endsection

@section('content')
@include('super-admin.standard-categories._form', [
    'mode' => 'create',
])
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Kategori Notları</h3>
        <div class="pd-summary-list">
            <span class="pd-summary-item">Bu kategori globaldir.</span>
            <span class="pd-summary-item">Tenant değiştiremez.</span>
            <span class="pd-summary-item">Tedarikçi kategori eşlemesinde hedef olarak kullanılır.</span>
            <span class="pd-summary-item">Kod benzersiz olmalıdır.</span>
        </div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Form notu:</strong>
    <span class="pd-muted">Global kategori ağacına yeni kayıt ekleyip listeye veya toplu import ekranına dönebilirsiniz.</span>
</div>
<div class="pd-bottom-action-buttons">
    <button type="submit" form="pdStandardCategoryForm" class="pd-btn pd-btn-primary">Kaydet</button>
    <a href="{{ route('admin.super.standard-categories.bulk-paste') }}" class="pd-btn pd-btn-light">Toplu Kopyala/Yapıştır</a>
    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-warning">Listeye Dön</a>
</div>
@endsection
