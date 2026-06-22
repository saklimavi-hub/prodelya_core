@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Kaynakları')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Tedarikçi Kaynakları</h1>
        <p class="pd-muted mt-1">Size açılan global tedarikçi kaynaklarının durumunu ve önizlemesini takip edin.</p>
    </div>
    <div class="pd-actions">
        <a href="{{ route('admin.product-data-hub.index') }}" class="pd-btn pd-btn-light">Product Data Hub Özeti</a>
        <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-light">Gelişmiş Ürün ve Katalog</a>
    </div>
</div>

<div class="pd-note mb-4">Global tedarikçi kaynakları Super Admin tarafından yönetilir. Bu ekranda yalnız size açılmış kaynakların durumunu görebilirsiniz.</div>

<div class="pd-grid pd-grid-4 mb-6">
    <div class="pd-card"><div class="pd-card-body"><div class="pd-note">Toplam Kaynak</div><div class="pd-metric">{{ $stats['total'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="pd-note">Aktif Kaynak</div><div class="pd-metric">{{ $stats['active'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="pd-note">Pasif Kaynak</div><div class="pd-metric">{{ $stats['inactive'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="pd-note">Hatalı Kaynak</div><div class="pd-metric">{{ $stats['error'] }}</div></div></div>
</div>

<div class="pd-card">
    <div class="pd-card-body">
        @if($sources->count() > 0)
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Tedarikçi</th>
                            <th>Kaynak Adı</th>
                            <th>Tip</th>
                            <th>Profil</th>
                            <th>Prefix</th>
                            <th>URL / Dosya</th>
                            <th>Node / Path</th>
                            <th>Son Test</th>
                            <th>Son Preview</th>
                            <th>Durum</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sources as $source)
                            <tr>
                                <td><strong>{{ $source->supplier->name }}</strong><div class="pd-note">{{ $source->supplier->code }}</div></td>
                                <td>{{ $source->source_name }}</td>
                                <td><span class="pd-badge pd-badge-{{ $source->display_source_type === 'json' ? 'purple' : ($source->display_source_type === 'xml' ? 'blue' : ($source->display_source_type === 'csv' ? 'amber' : 'green')) }}">{{ strtoupper($source->display_source_type) }}</span></td>
                                <td>{{ $source->profile_key }}</td>
                                <td>{{ $source->profile_prefix }}</td>
                                <td>{{ $source->display_location }}</td>
                                <td>{{ $source->display_path }}</td>
                                <td>{{ $source->last_test_display ? \Carbon\Carbon::parse($source->last_test_display)->format('d.m.Y H:i') : '-' }}</td>
                                <td>{{ $source->last_preview_display ? \Carbon\Carbon::parse($source->last_preview_display)->format('d.m.Y H:i') : '-' }}</td>
                                <td><span class="pd-badge pd-badge-{{ $source->status === 'active' ? 'green' : ($source->status === 'inactive' ? 'gray' : 'red') }}">{{ $source->status === 'active' ? 'Aktif' : ($source->status === 'inactive' ? 'Pasif' : 'Hatalı') }}</span></td>
                                <td>
                                    <div class="pd-actions">
                                        <span class="pd-note">Önizleme ve test işlemleri Super Admin tarafından yönetilir.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-center py-12">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Henüz tedarikçi kaynağı eklenmedi.</h3>
                <p class="text-gray-600 mb-6">Global kaynak tanımı Super Admin tarafından yapıldıktan sonra size açılan tedarikçiler burada listelenir.</p>
            </div>
        @endif
    </div>
</div>
@endsection

@section('summary')
<div class="pd-card">
    <div class="pd-card-body">
        <div class="pd-summary-title">Kaynak Ekleme Özeti</div>
        <div class="pd-summary-row"><span>Toplam kaynak</span><strong>{{ $stats['total'] }}</strong></div>
        <div class="pd-summary-row"><span>Aktif</span><strong>{{ $stats['active'] }}</strong></div>
        <div class="pd-summary-row"><span>Hatalı</span><strong>{{ $stats['error'] }}</strong></div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Aksiyonlar</h4>
            <div class="pd-summary-list">
                <a href="{{ route('admin.product-data-hub.index') }}" class="pd-summary-item">Product Data Hub Özeti</a>
                <a href="{{ route('admin.catalog.index') }}" class="pd-summary-item">Gelişmiş Ürün ve Katalog</a>
                <a href="{{ route('admin.product-data-hub.field-mappings') }}" class="pd-summary-item">Alan Eşleme Durumu</a>
            </div>
        </div>
    </div>
</div>
@endsection
