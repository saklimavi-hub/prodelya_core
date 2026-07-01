@extends('layouts.prodelya-admin')

@section('page_title', 'Alan Eşleme Merkezi')

@section('content')
<div class="pd-page-head">
    <div>
        <h1 class="pd-page-title">Alan Eşleme Merkezi</h1>
        <p class="pd-page-subtitle">Hazır Tedarikçi Kaynağı alanlarını kontrol edin, zorunlu eksikleri tamamlayın ve sonra kategori eşlemeye geçin.</p>
    </div>
</div>

<div class="pd-note mb-6">Bu ekran Super Admin tarafından yönetilir. Abone Firma bu verileri değiştiremez.</div>

<div class="pd-card mb-6">
    <div class="pd-card-body">
        <div class="pd-note">
            <strong>Kısa akış:</strong> Önce örnek veriyi kontrol edin, sonra zorunlu alan eşlemelerini tamamlayın. Kategori ve katalog adımlarına geçmeden önce kaynak alanı, örnek değer ve seçilen standart alan ilişkisini burada netleştirin.
        </div>
    </div>
</div>

<div class="pd-card mb-6">
    <div class="pd-card-header">
        <h2 class="pd-section-title">Hazır Tedarikçi Kaynakları</h2>
    </div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tedarikçi</th>
                        <th>Kaynak Adı</th>
                        <th>Profil</th>
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
                            <td><span class="pd-badge pd-badge-gray">{{ $row['profile_key'] ?? 'CUSTOM / Tespit Yok' }}</span></td>
                            <td>{{ strtoupper($row['source']->source_type) }}</td>
                            <td>{{ strtoupper($row['source']->config['format'] ?? $row['source']->source_type) }}</td>
                            <td>
                                <span class="pd-badge pd-badge-{{ $row['mapping_status'] === 'hazir' ? 'green' : 'amber' }}">
                                    {{ $row['mapping_status'] === 'hazir' ? 'Hazır' : 'Eksik' }}
                                </span>
                            </td>
                            <td>
                                <div><strong>{{ $row['missing_required_count'] }}</strong></div>
                                @if(!empty($row['missing_required_labels']))
                                    <div class="pd-chip-group mt-2">
                                        @foreach($row['missing_required_labels'] as $label)
                                            <span class="pd-badge pd-badge-amber">{{ $label }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="pd-badge pd-badge-green">Zorunlu alanlar tamam</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('admin.super.product-data-hub.field-mappings.source', $row['source']) }}" class="pd-btn pd-btn-primary pd-btn-sm">Eşlemeyi Aç</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">Henüz global tedarikçi kaynağı bulunmuyor.</td>
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
        <details>
            <summary class="pd-btn pd-btn-light">Standart alan sözlüğünü aç</summary>
            <div class="pd-table-wrap mt-4">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Standart Alan</th>
                            <th>Label</th>
                            <th>Grup</th>
                            <th>Zorunlu</th>
                            <th>Tip</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($standardFields as $key => $meta)
                            <tr>
                                <td><code>{{ $key }}</code></td>
                                <td>{{ $meta['label'] }}</td>
                                <td>{{ collect($standardFieldGroups)->first(fn ($group) => in_array($key, $group['fields'], true))['label'] ?? 'Diğer' }}</td>
                                <td>{{ $meta['required'] ? 'Evet' : 'Hayır' }}</td>
                                <td>{{ $meta['type'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
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

@section('bottom_actions')
<div>
    <strong>Alan eşleme:</strong>
    <span class="pd-muted">Kaynak alanlarını standart ürün diline bağlayıp kategori eşleme aşamasına kontrollü şekilde geçin.</span>
</div>
<div class="pd-bottom-action-buttons">
    <span class="pd-btn pd-btn-primary pd-btn-disabled">Mapping Kaydet</span>
    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Önizlemeye Dön</a>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-warning">Kategori Eşleme</a>
</div>
@endsection
