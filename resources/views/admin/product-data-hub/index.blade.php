@extends('layouts.prodelya-admin')

@section('title', 'Product Data Hub')
@section('page_title', 'Product Data Hub')
@section('page_subtitle', 'Tedarikçi ürün verilerini yönetin, eşleyin ve senkronize edin.')

@section('page_actions')
<div class="flex gap-2">
    <span class="pd-badge pd-badge-blue">Data Hub</span>
    <span class="pd-badge pd-badge-green">{{ $stats['active_sources'] }} Aktif Kaynak</span>
    <span class="pd-badge pd-badge-amber">{{ $stats['sync_errors'] }} Uyarı</span>
</div>
@endsection

@section('content')
<div class="pd-grid pd-grid-4" style="margin-bottom: 14px;">
    <div class="pd-card"><div class="pd-card-body"><div class="flex items-center"><div class="pd-stat-icon bg-blue-100"><svg class="pd-icon-lg text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path></svg></div><div class="ml-4"><div class="text-sm text-gray-600">Aktif Kaynak</div><div class="text-2xl font-bold">{{ $stats['active_sources'] }}</div></div></div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="flex items-center"><div class="pd-stat-icon bg-yellow-100"><svg class="pd-icon-lg text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg></div><div class="ml-4"><div class="text-sm text-gray-600">Ham Ürün</div><div class="text-2xl font-bold">{{ number_format($stats['total_raw_products'], 0, '.', '.') }}</div></div></div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="flex items-center"><div class="pd-stat-icon bg-green-100"><svg class="pd-icon-lg text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div><div class="ml-4"><div class="text-sm text-gray-600">Standart Ürün</div><div class="text-2xl font-bold">{{ number_format($stats['standard_products'], 0, '.', '.') }}</div></div></div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="flex items-center"><div class="pd-stat-icon bg-red-100"><svg class="pd-icon-lg text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div><div class="ml-4"><div class="text-sm text-gray-600">Senkron Hatası</div><div class="text-2xl font-bold">{{ $stats['sync_errors'] }}</div></div></div></div></div>
</div>

<div class="pd-grid" style="grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); margin-bottom: 14px;">
    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Tedarikçi Kaynakları</h3>
            <p class="pd-card-subtitle">Size açılan global kaynakların durumu ve canlı özetleri.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-note" style="margin-bottom: 12px;">Global tedarikçi kaynakları Super Admin tarafından yönetilir. Bu ekranda yalnız size açılmış kaynakların durumunu görebilirsiniz.</div>
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Kaynak</th>
                            <th>Tedarikçi</th>
                            <th>Tip</th>
                            <th>Ürün</th>
                            <th>Durum</th>
                            <th>Son Senkron</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentSources as $source)
                        <tr>
                            <td class="font-medium">{{ $source['name'] }}</td>
                            <td>{{ $source['supplier'] }}</td>
                            <td>{{ $source['type'] }}</td>
                            <td>{{ number_format($source['products'], 0, '.', '.') }}</td>
                            <td><span class="pd-badge pd-badge-{{ $source['status'] === 'active' ? 'green' : ($source['status'] === 'warning' ? 'amber' : 'gray') }}">{{ $source['status'] === 'active' ? 'Aktif' : ($source['status'] === 'warning' ? 'Uyarı' : 'Pasif') }}</span></td>
                            <td>{{ $source['last_sync'] ? $source['last_sync']->diffForHumans() : 'Henüz yok' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Veri Akışı</h3>
            <p class="pd-card-subtitle">Product Data Hub işleme hattının özeti.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-summary-list">
                <div class="pd-source-cell"><strong>1.</strong> Veri Al: XML / CSV / JSON</div>
                <div class="pd-source-cell"><strong>2.</strong> Alan Eşleme: Otomatik haritalama</div>
                <div class="pd-source-cell"><strong>3.</strong> Kategori Eşleme: AI destekli eşleme</div>
                <div class="pd-source-cell"><strong>4.</strong> Ortak Ürün Havuzu: {{ number_format($stats['common_products'], 0, '.', '.') }} ürün</div>
                <div class="pd-source-cell"><strong>5.</strong> Tenant Çıkışı: {{ number_format($stats['tenant_catalog_products'], 0, '.', '.') }} projection</div>
            </div>
        </div>
    </div>
</div>

<div class="pd-grid pd-grid-2" style="margin-bottom: 14px;">
    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Alan Eşleme</h3>
            <p class="pd-card-subtitle">Bekleyen eşlemeler ve güven skorları.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-summary-list">
                @foreach($pendingMappings as $mapping)
                <div class="pd-source-cell">
                    <div class="flex justify-between items-center">
                        <div>
                            <strong>{{ $mapping['source'] }}</strong>
                            <div class="text-sm text-gray-600">{{ $mapping['target'] }}</div>
                        </div>
                        <span class="pd-badge pd-badge-{{ $mapping['confidence'] >= 0.9 ? 'green' : ($mapping['confidence'] >= 0.8 ? 'amber' : 'red') }}">{{ round($mapping['confidence'] * 100) }}%</span>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Ürün Conflictleri</h3>
            <p class="pd-card-subtitle">Son karşılaşılan ürün uyuşmazlıkları.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-summary-list">
                @foreach($recentConflicts as $conflict)
                <div class="pd-source-cell" style="border-left: 4px solid #dc2626;">
                    <div class="flex justify-between items-center">
                        <div>
                            <strong>{{ $conflict['product_code'] }}</strong>
                            <div class="text-sm text-gray-600">{{ implode(' vs ', $conflict['suppliers']) }} - {{ $conflict['conflict_type'] }}</div>
                        </div>
                        <span class="pd-badge pd-badge-{{ $conflict['resolution'] === 'pending' ? 'amber' : 'green' }}">{{ $conflict['resolution'] === 'pending' ? 'Bekliyor' : 'Çözüldü' }}</span>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<div class="pd-grid pd-grid-2" style="margin-bottom: 14px;">
    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Ham / Standart Ürün Özeti</h3>
            <p class="pd-card-subtitle">Bekleyen ve işlenmiş ürün miktarları.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-summary-info">
                <div class="pd-summary-row"><span>Toplam Ham Ürün</span><span class="font-medium">{{ number_format($stats['total_raw_products'], 0, '.', '.') }}</span></div>
                <div class="pd-summary-row"><span>Standart Ürün</span><span class="font-medium">{{ number_format($stats['standard_products'], 0, '.', '.') }}</span></div>
                <div class="pd-summary-row"><span>Parent / Grup</span><span class="font-medium">{{ number_format($stats['parent_products'], 0, '.', '.') }}</span></div>
                <div class="pd-summary-row"><span>Varyant</span><span class="font-medium">{{ number_format($stats['variant_products'], 0, '.', '.') }}</span></div>
                <div class="pd-summary-row"><span>Flat</span><span class="font-medium">{{ number_format($stats['flat_products'], 0, '.', '.') }}</span></div>
                <div class="pd-summary-row"><span>Son Senkron</span><span class="font-medium">{{ $stats['last_sync'] ? $stats['last_sync']->diffForHumans() : 'Henüz yok' }}</span></div>
            </div>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Export / Web Feed</h3>
            <p class="pd-card-subtitle">Bu tenantta açılabilecek yayın tiplerinin bilgilendirme özeti.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-grid pd-grid-2">
                <div class="pd-source-cell"><strong>XML Export</strong><div class="text-sm text-gray-600">Kurulum gerekli</div></div>
                <div class="pd-source-cell"><strong>JSON Export</strong><div class="text-sm text-gray-600">Kurulum gerekli</div></div>
                <div class="pd-source-cell"><strong>CSV Export</strong><div class="text-sm text-gray-600">Kurulum gerekli</div></div>
                <div class="pd-source-cell"><strong>API Feed</strong><div class="text-sm text-gray-600">Ek modül</div></div>
            </div>
        </div>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Loglar</h3>
        <p class="pd-card-subtitle">Son senkron zamanını ve operasyonel notları izleyin.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-summary-info">
            <div class="pd-summary-row"><span>Tenant projection</span><span class="font-medium">{{ number_format($stats['tenant_catalog_products'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Katalogda görünen</span><span class="font-medium">{{ number_format($stats['tenant_catalog_visible'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Teklifte kullanılabilir</span><span class="font-medium">{{ number_format($stats['quote_visible'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Kategori eşlenmiş ürün</span><span class="font-medium">{{ number_format($stats['category_mapped_products'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Kategori eksik</span><span class="font-medium">{{ number_format($stats['category_missing'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Fallback kategoriye düşen</span><span class="font-medium">{{ number_format($stats['fallback_category_products'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Kategori eşleşmemiş ama görünür</span><span class="font-medium">{{ number_format($stats['category_pending_visible'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Kategori nedeniyle bloklanan</span><span class="font-medium">{{ number_format($stats['category_blocked_products'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Fiyat eksik</span><span class="font-medium">{{ number_format($stats['price_missing'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Projection blocked</span><span class="font-medium">{{ number_format($stats['projection_blocked'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Variant health review</span><span class="font-medium">{{ number_format($stats['variant_health_review'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Güvenli repair adayı</span><span class="font-medium">{{ number_format($stats['safe_repair_candidates'], 0, '.', '.') }}</span></div>
            <div class="pd-summary-row"><span>Son senkron</span><span class="font-medium">{{ $stats['last_sync'] ? $stats['last_sync']->diffForHumans() : 'Henüz yok' }}</span></div>
        </div>
    </div>
</div>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Hub Özeti</h3>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Durum</h4>
            <div class="pd-summary-info">
                <div class="pd-summary-row"><span>Aktif Kaynak</span><span class="font-medium">{{ $stats['active_sources'] }}</span></div>
                <div class="pd-summary-row"><span>Bekleyen Eşleme</span><span class="font-medium">{{ $stats['pending_mappings'] }}</span></div>
                <div class="pd-summary-row"><span>Hata Kaydı</span><span class="font-medium">{{ $stats['sync_errors'] }}</span></div>
                <div class="pd-summary-row"><span>Son Senkron</span><span class="font-medium">{{ $stats['last_sync'] ? $stats['last_sync']->diffForHumans() : 'Henüz yok' }}</span></div>
                <div class="pd-summary-row"><span>Projection</span><span class="font-medium">{{ number_format($stats['tenant_catalog_products'], 0, '.', '.') }}</span></div>
                <div class="pd-summary-row"><span>Review</span><span class="font-medium">{{ number_format($stats['variant_health_review'], 0, '.', '.') }}</span></div>
            </div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Aksiyonlar</h4>
            <div class="pd-summary-list">
                <a href="{{ route('admin.product-data-hub.sources') }}" class="pd-summary-item">Kaynak Durumunu Gör</a>
                <a href="{{ route('admin.catalog.index') }}" class="pd-summary-item">Gelişmiş Ürün ve Katalog</a>
                <a href="{{ route('admin.promotion-quotes.create') }}" class="pd-summary-item">Teklif Oluştur</a>
                <span class="pd-summary-item" aria-disabled="true">Export Özeti: kurulum gerekli</span>
            </div>
        </div>

        <div class="pd-note">Global tedarikçi kaynakları Super Admin tarafından yönetilir. Satışa çıkan temiz vitrin, Gelişmiş Ürün ve Katalog tarafında gösterilir.</div>
    </div>
</div>
@endsection
