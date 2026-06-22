@extends('layouts.prodelya-admin')

@section('title', $category->name . ' Düzenle')
@section('page_title', $category->name . ' Düzenle')
@section('page_subtitle', 'Standart kategori özelliklerini ve ağaç bağlantısını güncelleyin.')

@section('page_actions')
<div class="pd-actions-wrap">
    <span class="pd-badge pd-badge-purple">Super Admin</span>
    <span class="pd-badge {{ $category->is_active ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $category->is_active ? 'Aktif' : 'Pasif' }}</span>
    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
</div>
@endsection

@section('content')
@include('super-admin.standard-categories._form', [
    'mode' => 'edit',
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
        <div class="pd-note mt-3">Üst kategori değişirse path ve depth alanları yeniden hesaplanır.</div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Form notu:</strong>
    <span class="pd-muted">Kategori durumunu, görünürlüğünü ve eşleme hazırlığını buradan güncelleyebilirsiniz.</span>
</div>
<div class="pd-bottom-action-buttons">
    <button type="submit" form="pdStandardCategoryForm" class="pd-btn pd-btn-primary">Kaydet</button>
    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
    <form method="POST" action="{{ route('admin.super.standard-categories.toggle-active', $category) }}">
        @csrf
        <button type="submit" class="pd-btn {{ $category->is_active ? 'pd-btn-warning' : 'pd-btn-success' }}">{{ $category->is_active ? 'Pasife Al' : 'Aktif Et' }}</button>
    </form>
</div>
@endsection
