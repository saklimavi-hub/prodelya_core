@extends('layouts.prodelya-admin')

@section('page_title', 'Alan Eşleme')

@section('content')
<div class="pd-page-head">
    <div>
        <h1 class="pd-page-title">Alan Eşleme</h1>
        <p class="pd-page-subtitle">Tedarikçi kaynak alanlarını Prodelya standart ürün alanlarına bağlayın.</p>
    </div>
</div>

<div class="pd-card mb-6">
    <div class="pd-card-body">
        <div class="pd-note">
            <strong>Önemli notlar:</strong> <code>urun_turuncu</code> alanı <code>warning_flag</code> olarak yorumlanır. Update priority için <code>urun_id / urun_kodu</code> alanları önemlidir. Prefix ve code normalization preview katmanında birlikte çalışır.
        </div>
    </div>
</div>

<div class="pd-card mb-6">
    <div class="pd-card-header">
        <h2 class="pd-section-title">Kaynak Listesi</h2>
    </div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tedarikçi</th>
                        <th>Kaynak Adı</th>
                        <th>Tip</th>
                        <th>Format</th>
                        <th>Mapping Durumu</th>
                        <th>Zorunlu Eksik</th>
                        <th>Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sources as $row)
                        <tr>
                            <td>{{ $row['source']->supplier?->name }}</td>
                            <td>{{ $row['source']->source_name }}</td>
                            <td>{{ strtoupper($row['source']->source_type) }}</td>
                            <td>{{ strtoupper($row['source']->config['format'] ?? $row['source']->source_type) }}</td>
                            <td>
                                <span class="pd-badge pd-badge-{{ $row['mapping_status'] === 'hazir' ? 'green' : 'amber' }}">
                                    {{ $row['mapping_status'] === 'hazir' ? 'Hazır' : 'Eksik' }}
                                </span>
                            </td>
                            <td>{{ $row['missing_required_count'] }}</td>
                            <td>
                                <a href="{{ route('admin.product-data-hub.field-mappings.source', $row['source']) }}" class="pd-btn pd-btn-primary pd-btn-sm">Eşleme Aç</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">Henüz erişilebilir tedarikçi kaynağı bulunmuyor.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-header">
        <h2 class="pd-section-title">Standart Alan Sözlüğü</h2>
    </div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Standart Alan</th>
                        <th>Label</th>
                        <th>Zorunlu</th>
                        <th>Tip</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($standardFields as $key => $meta)
                        <tr>
                            <td><code>{{ $key }}</code></td>
                            <td>{{ $meta['label'] }}</td>
                            <td>{{ $meta['required'] ? 'Evet' : 'Hayır' }}</td>
                            <td>{{ $meta['type'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Özet</h3>
        <div class="pd-summary-row"><span>Kaynak</span><strong>{{ count($sources) }}</strong></div>
        <div class="pd-summary-row"><span>Profil</span><strong>{{ count($supplierProfiles) }}</strong></div>
        <div class="pd-summary-row"><span>Alias</span><strong>warning_flag</strong></div>
    </div>
</div>
@endsection
