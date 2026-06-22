@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Kaynağı Önizleme')

@section('content')
<div class="pd-page-header">
    <div>
        <h1 class="pd-section-title">Tedarikçi Kaynağı Önizleme</h1>
        <p class="pd-muted mt-1">{{ $source->source_name }} - {{ $source->supplier->name }}</p>
    </div>
    <div class="pd-actions">
        <a href="{{ route('admin.product-data-hub.sources') }}" class="pd-btn pd-btn-light">Kaynağa Dön</a>
        <a href="{{ route('admin.product-data-hub.field-mappings.source', $source) }}" class="pd-btn pd-btn-light">Mapping Ekranına Git</a>
    </div>
</div>

<div class="pd-note mb-4">Global tedarikçi kaynakları Super Admin tarafından yönetilir. Tenant tarafı bu ekranda yalnız kaynak önizlemesini ve mapping durumunu görebilir.</div>

<div class="pd-card mb-6">
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-2">
            <div><label class="pd-label">Kaynak Adı</label><input type="text" class="pd-input" value="{{ $source->source_name }}" readonly></div>
            <div><label class="pd-label">Tedarikçi</label><input type="text" class="pd-input" value="{{ $source->supplier->name }}" readonly></div>
            <div><label class="pd-label">Format</label><input type="text" class="pd-input" value="{{ strtoupper($parserResult['content_type'] ?? ($source->config['format'] ?? $source->source_type)) }}" readonly></div>
            <div><label class="pd-label">Profil</label><input type="text" class="pd-input" value="{{ $parserResult['profile_key'] ?? '-' }}" readonly></div>
            <div><label class="pd-label">Node Path</label><input type="text" class="pd-input" value="{{ $parserResult['node_path'] ?? $standardizationNotes['product_node_path'] ?? '-' }}" readonly></div>
            <div>
                <label class="pd-label">Veri Modu</label>
                <div class="mt-2">
                    <span class="pd-badge pd-badge-{{ $sourceMode === 'live_source' ? 'green' : 'amber' }}">
                        {{ $sourceMode === 'live_source' ? 'Gerçek kaynak verisi' : 'Demo fallback' }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="pd-grid pd-grid-4 mb-6">
    <div class="pd-card">
        <div class="pd-card-body">
            <div class="pd-note">Okunan Kayıt</div>
            <div class="pd-metric">{{ $stats['records_read'] }}</div>
        </div>
    </div>
    <div class="pd-card">
        <div class="pd-card-body">
            <div class="pd-note">Ürün Preview</div>
            <div class="pd-metric">{{ $stats['product_count'] }}</div>
        </div>
    </div>
    <div class="pd-card">
        <div class="pd-card-body">
            <div class="pd-note">Varyasyon Preview</div>
            <div class="pd-metric">{{ $stats['variant_count'] }}</div>
        </div>
    </div>
    <div class="pd-card">
        <div class="pd-card-body">
            <div class="pd-note">Uyarı / Hata</div>
            <div class="pd-metric">{{ $stats['warnings'] }} / {{ $stats['errors'] }}</div>
            <div class="mt-2">
                <span class="pd-badge pd-badge-{{ $mappingMode === 'db' ? 'green' : 'amber' }}">
                    Mapping kaynağı: {{ $mappingMode === 'db' ? 'DB' : 'Öneri' }}
                </span>
            </div>
        </div>
    </div>
</div>

@if($sourceMode === 'live_source')
    <div class="pd-note mb-4">Gerçek kaynak verisi üzerinden önizleme oluşturuldu. Kayıtlar henüz staging’e aktarılmadı.</div>
@else
    <div class="pd-warn mb-4">Gerçek kaynak okunamadı. Demo fallback verisi gösteriliyor.</div>
@endif

@foreach($previewWarnings as $previewWarning)
    <div class="pd-note mb-2">{{ $previewWarning }}</div>
@endforeach

@foreach($previewErrors as $previewError)
    <div class="pd-warn mb-2">{{ $previewError }}</div>
@endforeach

@if(!empty($mappingWarnings))
    <div class="pd-warn mb-4">Bu kaynak import için hazır değil. Zorunlu alan eşlemeleri eksik.</div>
    @foreach($mappingWarnings as $mappingWarning)
        <div class="pd-note mb-2">{{ $mappingWarning }}</div>
    @endforeach
@endif

<div class="pd-card mb-6">
    <div class="pd-card-header">
        <strong>Kaynak ve Model Notları</strong>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-2">
            <div><label class="pd-label">Tedarikçi Prefix</label><input type="text" class="pd-input" value="{{ $standardizationNotes['supplier_prefix'] }}" readonly></div>
            <div><label class="pd-label">Ürün Modeli</label><input type="text" class="pd-input" value="{{ $standardizationNotes['product_model'] }}" readonly></div>
            <div><label class="pd-label">Generated Code Template</label><input type="text" class="pd-input" value="{{ $standardizationNotes['generated_code_template'] }}" readonly></div>
            <div><label class="pd-label">Generated Variant Code Template</label><input type="text" class="pd-input" value="{{ $standardizationNotes['generated_variant_code_template'] }}" readonly></div>
        </div>
    </div>
</div>

<div class="pd-card mb-6">
    <div class="pd-card-header">
        <strong>Ana Ürün Önizleme</strong>
    </div>
    <div class="pd-card-body">
        @if($previewProducts->isEmpty())
            <div class="pd-note">Henüz ürün önizleme kaydı oluşmadı.</div>
        @else
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Generated Code</th>
                            <th>Supplier Code</th>
                            <th>Group Code</th>
                            <th>Ürün Adı</th>
                            <th>Kategori</th>
                            <th>Fiyat</th>
                            <th>Para Birimi</th>
                            <th>Görsel</th>
                            <th>Warning Flag</th>
                            <th>Uyarılar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($previewProducts as $item)
                            <tr>
                                <td><strong>{{ $item['generated_product_code'] ?: '-' }}</strong></td>
                                <td>{{ $item['supplier_product_code'] ?: '-' }}</td>
                                <td>{{ $item['supplier_group_code'] ?: '-' }}</td>
                                <td>{{ $item['product_name'] ?: '-' }}</td>
                                <td>{{ $item['supplier_category_name'] ?: '-' }}</td>
                                <td>{{ is_null($item['purchase_price']) ? '-' : number_format((float) $item['purchase_price'], 2, ',', '.') }}</td>
                                <td>{{ $item['currency'] ?: '-' }}</td>
                                <td>{{ $item['image_url'] ?: '-' }}</td>
                                <td>
                                    <span class="pd-badge pd-badge-{{ !empty($item['warning_flag']) ? 'amber' : 'gray' }}">
                                        {{ !empty($item['warning_flag']) ? 'Var' : 'Yok' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="mb-2">
                                        <span class="pd-badge pd-badge-{{ $mappingMode === 'db' ? 'green' : 'amber' }}">
                                            {{ $item['mapping_badge'] }}
                                        </span>
                                    </div>
                                    @forelse(($item['warnings'] ?? []) as $warning)
                                        <div class="text-xs">{{ $warning }}</div>
                                    @empty
                                        <span class="pd-note">-</span>
                                    @endforelse
                                    @foreach(($item['errors'] ?? []) as $error)
                                        <div class="pd-warn mt-2">{{ $error }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<div class="pd-card mb-6">
    <div class="pd-card-header">
        <strong>Varyasyon Önizleme</strong>
    </div>
    <div class="pd-card-body">
        @if($previewVariants->isEmpty())
            <div class="pd-note">Bu kaynak için ayrı varyasyon satırı oluşmadı.</div>
        @else
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Generated Variant Code</th>
                            <th>Parent Code</th>
                            <th>Variant ID</th>
                            <th>Stok Kodu</th>
                            <th>Renk</th>
                            <th>Stok</th>
                            <th>Görsel</th>
                            <th>Fallback</th>
                            <th>Uyarılar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($previewVariants as $item)
                            <tr>
                                <td><strong>{{ $item['generated_variant_code'] ?: '-' }}</strong></td>
                                <td>{{ $item['parent_supplier_product_id'] ?: '-' }}</td>
                                <td>{{ $item['variant_id'] ?: '-' }}</td>
                                <td>{{ $item['variant_stock_code'] ?: '-' }}</td>
                                <td>{{ $item['variant_color'] ?: '-' }}</td>
                                <td>{{ is_null($item['variant_stock_quantity']) ? '-' : number_format((float) $item['variant_stock_quantity'], 0, ',', '.') }}</td>
                                <td>{{ $item['variant_image_url'] ?: '-' }}</td>
                                <td>
                                    <span class="pd-badge pd-badge-{{ !empty($item['image_fallback_used']) ? 'amber' : 'gray' }}">
                                        {{ !empty($item['image_fallback_used']) ? 'Evet' : 'Hayır' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="mb-2">
                                        <span class="pd-badge pd-badge-{{ $mappingMode === 'db' ? 'green' : 'amber' }}">
                                            {{ $item['mapping_badge'] }}
                                        </span>
                                    </div>
                                    @forelse(($item['warnings'] ?? []) as $warning)
                                        <div class="text-xs">{{ $warning }}</div>
                                    @empty
                                        <span class="pd-note">-</span>
                                    @endforelse
                                    @foreach(($item['errors'] ?? []) as $error)
                                        <div class="pd-warn mt-2">{{ $error }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<div class="pd-card mb-6">
    <div class="pd-card-body">
        <div class="pd-note mb-3">Örnek uyarılar</div>
        <div class="text-sm">Grup kodu bulundu, varyasyon olarak işlenecek.</div>
        <div class="text-sm">Varyasyon görseli boş, ana ürün görseli kullanılacak.</div>
        <div class="text-sm">turuncu alanı warning_flag olarak eşlendi.</div>
        <div class="text-sm">Ürün kodu normalize edildi.</div>
        <div class="text-sm">Prefix uygulandı.</div>
        <div class="text-sm">Zorunlu alan eksikse kırmızı uyarı gösterilir.</div>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-body">
        <div class="pd-actions">
            <a href="{{ route('admin.product-data-hub.field-mappings.source', $source) }}" class="pd-btn pd-btn-light">Mapping Ekranına Git</a>
            <a href="{{ route('admin.product-data-hub.sources') }}" class="pd-btn pd-btn-light">Kaynağa Dön</a>
        </div>
        <div class="pd-note mt-3">Staging’e aktar, test ve kaynak düzenleme işlemleri Super Admin tarafından yönetilir.</div>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-card">
    <div class="pd-card-body">
        <div class="pd-summary-title">Önizleme Özeti</div>
        <div class="pd-summary-row"><span>Kayıt</span><strong>{{ $stats['records_read'] }}</strong></div>
        <div class="pd-summary-row"><span>Ürün</span><strong>{{ $stats['product_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Varyasyon</span><strong>{{ $stats['variant_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Uyarı</span><strong>{{ $stats['warnings'] }}</strong></div>
        <div class="pd-summary-row"><span>Hata</span><strong>{{ $stats['errors'] }}</strong></div>
        <div class="mt-4">
            <span class="pd-badge pd-badge-{{ $mappingMode === 'db' ? 'green' : 'amber' }}">
                {{ $mappingMode === 'db' ? 'DB mapping kullanıldı' : 'Öneri mapping kullanıldı' }}
            </span>
        </div>
        <div class="mt-3">
            <span class="pd-badge pd-badge-{{ $sourceMode === 'live_source' ? 'green' : 'amber' }}">
                {{ $sourceMode === 'live_source' ? 'Gerçek kaynak verisi' : 'Demo fallback' }}
            </span>
        </div>
    </div>
</div>
@endsection
