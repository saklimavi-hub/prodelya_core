@extends('layouts.prodelya-admin')

@section('title', 'Katalog Ürünleri')
@section('page_title', 'Katalog Ürünleri')
@section('page_subtitle', 'Abone Firma kataloğuna açılmış ürünleri yönetin. Bu ekran satış/katalog yansıtma katmanını gösterir; Product Hub teknik ekranı değildir.')

@section('content')
<div class="pd-hub-family-shell">
    @if(session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="pd-alert-warning">{{ session('error') }}</div>
    @endif

    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">{{ $catalogContext['surface_title'] ?? 'Katalog Ürünleri' }}</h1>
                    <p class="pd-hero-subtitle">{{ $catalogContext['surface_subtitle'] ?? 'Abone Firma kataloğuna açılmış tedarikçi ve local ürünleri yönetin. Satılabilir varyantlar teklif aramada kullanılır; grup ürünler varyanttan seçilir.' }}</p>
                    <div class="pd-note pd-note-soft-blue pd-context-note">
                        <strong>Abone Firma:</strong> {{ $catalogContext['tenant_name'] ?? 'Bilinmiyor' }}
                        @if(!empty($catalogContext['is_platform_admin_context']))
                            <div class="pd-card-subtitle pd-subnote-top">Platform yöneticisi olarak Abone Firma panelindesiniz. Bu ekran yalnız seçili Abone Firma kataloğunu gösterir.</div>
                        @elseif(!empty($catalogContext['is_fallback_tenant']))
                            <div class="pd-card-subtitle pd-subnote-top">Bu ekran şu an seçili Abone Firma context’iyle açıldı. Beklediğiniz tedarikçi görünmüyorsa doğru Abone Firma panel hostunu kontrol edin.</div>
                        @endif
                    </div>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">{{ $stats['total'] }} toplam katalog ürünü</span>
                        <span class="pd-badge pd-badge-green">{{ $stats['supplier'] }} tedarikçi ürünü</span>
                        <span class="pd-badge pd-badge-purple">{{ $stats['local'] }} local ürün</span>
                        <span class="pd-badge pd-badge-amber">{{ $stats['warning'] }} uyarılı ürün</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.catalog.local-products.create') }}" class="pd-btn pd-btn-light">Local Ürün Ekle</a>
                    <a href="{{ route('admin.catalog.warnings') }}" class="pd-btn pd-btn-warning">Uyarılıları Göster</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-metric-grid">
        <div class="pd-metric-card pd-metric-card-soft-blue"><div class="pd-metric-card-label">Toplam katalog ürünü</div><div class="pd-metric-card-value">{{ $stats['total'] }}</div><div class="pd-metric-card-note">Abone Firma kataloğundaki tüm ürünler</div></div>
        <div class="pd-metric-card pd-metric-card-soft-green"><div class="pd-metric-card-label">Tedarikçi ürünü</div><div class="pd-metric-card-value">{{ $stats['supplier'] }}</div><div class="pd-metric-card-note">Abone Firmaya açılmış tedarikçi ürünleri</div></div>
        <div class="pd-metric-card pd-metric-card-soft-purple"><div class="pd-metric-card-label">Local ürün</div><div class="pd-metric-card-value">{{ $stats['local'] }}</div><div class="pd-metric-card-note">Abone Firmaya özel manuel ürünler</div></div>
        <div class="pd-metric-card pd-metric-card-soft-slate"><div class="pd-metric-card-label">Stokta olan</div><div class="pd-metric-card-value">{{ $stats['in_stock'] }}</div><div class="pd-metric-card-note">Satışta kullanılabilir stoklu ürün</div></div>
        <div class="pd-metric-card pd-metric-card-soft-amber"><div class="pd-metric-card-label">Fiyatı eksik</div><div class="pd-metric-card-value">{{ $stats['missing_price'] }}</div><div class="pd-metric-card-note">Manuel fiyat kontrolü gerekebilir</div></div>
        <div class="pd-metric-card pd-metric-card-soft-red"><div class="pd-metric-card-label">Uyarılı ürün</div><div class="pd-metric-card-value">{{ $stats['warning'] }}</div><div class="pd-metric-card-note">Fiyat, görsel, kategori veya stok kontrolü</div></div>
        <div class="pd-metric-card pd-metric-card-soft-green"><div class="pd-metric-card-label">Katalogda görünen</div><div class="pd-metric-card-value">{{ $stats['visible'] }}</div><div class="pd-metric-card-note">Müşteri katalog görünümü açık</div></div>
        <div class="pd-metric-card pd-metric-card-soft-slate"><div class="pd-metric-card-label">Katalogda gizli</div><div class="pd-metric-card-value">{{ $stats['hidden'] }}</div><div class="pd-metric-card-note">Abone Firma tarafından gizlenen ürünler</div></div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler ve Hızlı Geçişler</h3>
                <p class="pd-section-subtitle">Tedarikçi, local stok ve uyarı durumuna göre kataloğu daraltın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-chip-group pd-gap-bottom-sm">
                <a href="{{ route('admin.catalog.index') }}" class="pd-chip {{ $filters['source_type'] === '' ? 'is-active' : '' }}">Tüm ürünler</a>
                <a href="{{ route('admin.catalog.index', array_merge(request()->query(), ['source_type' => 'supplier'])) }}" class="pd-chip {{ $filters['source_type'] === 'supplier' ? 'is-active' : '' }}">Tedarikçi ürünleri</a>
                <a href="{{ route('admin.catalog.index', array_merge(request()->query(), ['source_type' => 'local'])) }}" class="pd-chip {{ $filters['source_type'] === 'local' ? 'is-active' : '' }}">Local ürünler</a>
                <a href="{{ route('admin.catalog.index', array_merge(request()->query(), ['stock_state' => 'in_stock'])) }}" class="pd-chip {{ $filters['stock_state'] === 'in_stock' ? 'is-active' : '' }}">Stokta var</a>
                <a href="{{ route('admin.catalog.index', array_merge(request()->query(), ['stock_state' => 'out_of_stock'])) }}" class="pd-chip {{ $filters['stock_state'] === 'out_of_stock' ? 'is-active' : '' }}">Stok yok</a>
                <a href="{{ route('admin.catalog.index', array_merge(request()->query(), ['warning_state' => 'missing_price'])) }}" class="pd-chip {{ $filters['warning_state'] === 'missing_price' ? 'is-active' : '' }}">Fiyatı eksik</a>
                <a href="{{ route('admin.catalog.index', array_merge(request()->query(), ['warning_state' => 'missing_image'])) }}" class="pd-chip {{ $filters['warning_state'] === 'missing_image' ? 'is-active' : '' }}">Görseli eksik</a>
                <a href="{{ route('admin.catalog.index', array_merge(request()->query(), ['warning_state' => 'warning'])) }}" class="pd-chip {{ $filters['warning_state'] === 'warning' ? 'is-active' : '' }}">Uyarılı</a>
                <a href="{{ route('admin.catalog.index', array_merge(request()->query(), ['visibility' => 'visible'])) }}" class="pd-chip {{ $filters['visibility'] === 'visible' ? 'is-active' : '' }}">Katalogda görünen</a>
                <a href="{{ route('admin.catalog.index', array_merge(request()->query(), ['visibility' => 'hidden'])) }}" class="pd-chip {{ $filters['visibility'] === 'hidden' ? 'is-active' : '' }}">Katalogda gizli</a>
            </div>

            <form method="GET" action="{{ route('admin.catalog.index') }}" class="pd-form-grid-3">
                <div>
                    <label class="pd-label">Arama</label>
                    <input name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Ürün adı, ürün kodu, tedarikçi kodu veya kategori">
                </div>
                <div>
                    <label class="pd-label">Kategori</label>
                    <select name="category" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected($filters['category'] === $category->id)>{{ $category->full_path }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Tedarikçi</label>
                    <select name="supplier" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected($filters['supplier'] === $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Durum</label>
                    <select name="status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="active" @selected($filters['status'] === 'active')>Aktif</option>
                        <option value="inactive" @selected($filters['status'] === 'inactive')>Pasif</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Stok durumu</label>
                    <select name="stock_state" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="in_stock" @selected($filters['stock_state'] === 'in_stock')>Stokta var</option>
                        <option value="out_of_stock" @selected($filters['stock_state'] === 'out_of_stock')>Stok yok</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Katalog görünürlüğü</label>
                    <select name="visibility" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="visible" @selected($filters['visibility'] === 'visible')>Görünen</option>
                        <option value="hidden" @selected($filters['visibility'] === 'hidden')>Gizli</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Teklif görünürlüğü</label>
                    <select name="quote_visibility" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="open" @selected($filters['quote_visibility'] === 'open')>Teklifte kullanılabilir</option>
                        <option value="closed" @selected($filters['quote_visibility'] === 'closed')>Teklife kapalı</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Kayıt limiti</label>
                    <select name="limit" class="pd-select">
                        @foreach([50, 100, 250, 500] as $limit)
                            <option value="{{ $limit }}" @selected((string) $filters['limit'] === (string) $limit)>{{ $limit }}</option>
                        @endforeach
                        <option value="all" @selected($filters['limit'] === 'all')>Tümü</option>
                    </select>
                </div>
                <div class="pd-hero-actions">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                </div>
            </form>
        </div>
    </section>

    @if((int) data_get($catalogContext ?? [], 'supplier_local_stock_warning.legacy_unassigned_count', 0) > 0)
        <section class="pd-section-card pd-section-card-soft-amber">
            <div class="pd-section-body">
                <div class="pd-alert-warning">
                    Varyantı belirlenmemiş stok kaydı bulunuyor.
                    <strong>{{ number_format((int) data_get($catalogContext, 'supplier_local_stock_warning.legacy_unassigned_count', 0), 0, ',', '.') }} kayıt / {{ number_format((float) data_get($catalogContext, 'supplier_local_stock_warning.legacy_unassigned_quantity', 0), 0, ',', '.') }} adet</strong>
                </div>
            </div>
        </section>
    @endif

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Ürün Listesi</h3>
                <p class="pd-section-subtitle">Tedarikçi ürünleri ve local ürünler aynı satış kataloğunda birleşir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            @if($products->isEmpty())
                <div class="pd-empty-card">
                    <h3 class="text-lg font-medium pd-title-gap-xs">Katalog ürünü bulunamadı.</h3>
                    <p class="pd-muted pd-gap-bottom-sm">Henüz Abone Firmaya açık tedarikçi ürünü yoksa Super Admin erişimlerini kontrol edin veya local ürün ekleyin.</p>
                    <div class="pd-hero-actions">
                        <a href="{{ route('admin.catalog.local-products.create') }}" class="pd-btn pd-btn-light">Local Ürün Ekle</a>
                    </div>
                </div>
            @else
                <div class="pd-hub-table-wrap">
                    <table class="pd-table pd-package-table">
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>Kaynak</th>
                                <th>Kategori</th>
                                <th>Local Stok</th>
                                <th>Tedarikçi Stok</th>
                                <th>Satış Stoku</th>
                                <th>Liste Fiyatı</th>
                                <th>KDV</th>
                                <th>Uyarılar</th>
                                <th>Görünürlük</th>
                                <th>Son Güncelleme</th>
                                <th>Aksiyon</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($products as $product)
                                @php
                                    $vatRate = data_get($product->meta, 'price_snapshot.vat_rate', data_get($product->source_summary, '0.vat_rate'));
                                @endphp
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            @if($product->primaryImage?->image_url || $product->image_url)
                                                <img src="{{ $product->primaryImage?->image_url ?: $product->image_url }}" alt="{{ $product->display_name }}" class="catalog-product-thumb pd-allow-large">
                                            @else
                                                <div class="catalog-product-thumb catalog-product-thumb-placeholder">Yok</div>
                                            @endif
                                            <div>
                                                <div class="font-medium">{{ $product->display_name }}</div>
                                                <div class="text-xs text-gray-500">{{ $product->display_code }}</div>
                                                <div class="text-xs text-gray-500">{{ $product->supplier_label }}</div>
                                                @if($product->has_local_stock_priority)
                                                    <div class="pd-badge pd-badge-purple pd-badge-stack-gap">Local stok öncelikli</div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="pd-badge pd-badge-{{ $product->catalog_source === 'local_product' ? 'purple' : 'blue' }}">{{ $product->catalog_source_label }}</span>
                                        <div class="pd-badge pd-badge-light pd-badge-stack-gap-sm">{{ $product->catalog_row_role_label }}</div>
                                    </td>
                                    <td>{{ $product->category_display_name }}</td>
                                    <td>{{ number_format((float) ($product->local_stock_quantity ?? 0), 0, ',', '.') }}</td>
                                    <td>{{ number_format((float) ($product->supplier_stock_quantity ?? 0), 0, ',', '.') }}</td>
                                    <td>{{ number_format((float) $product->effective_stock_quantity, 0, ',', '.') }}</td>
                                    <td>{{ $product->display_price ? number_format((float) $product->display_price, 2, ',', '.') . ' ' . ($product->currency ?? 'TL') : '-' }}</td>
                                    <td>{{ $vatRate !== null ? '%' . number_format((float) $vatRate, 0, ',', '.') : '-' }}</td>
                                    <td>
                                        @forelse($product->warning_items as $warning)
                                            <div class="pd-badge pd-badge-amber pd-badge-list-gap">{{ $warning }}</div>
                                        @empty
                                            <span class="pd-badge pd-badge-green">Sorun yok</span>
                                        @endforelse
                                    </td>
                                    <td>
                                        <div class="pd-badge pd-badge-{{ $product->visible_in_catalog ? 'green' : 'gray' }}">{{ $product->visible_in_catalog ? 'Katalogda görünüyor' : 'Katalogda gizli' }}</div>
                                        <div class="pd-badge pd-badge-{{ $product->quote_visibility_badge_class }} pd-badge-stack-gap-sm">{{ $product->quote_visibility_label }}</div>
                                        @if($product->quote_visibility_hint)
                                            <div class="pd-card-subtitle pd-subnote-top">{{ $product->quote_visibility_hint }}</div>
                                        @endif
                                    </td>
                                    <td>{{ optional($product->last_synced_at ?: $product->updated_at)->format('d.m.Y H:i') ?: '-' }}</td>
                                    <td>
                                        <div class="pd-source-actions">
                                            <a href="{{ $product->getAttribute('catalog_row_variant_id') ? route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $product->getAttribute('catalog_row_variant_id')]) : route('admin.catalog.show', $product) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                            <form action="{{ route('admin.catalog.toggle-visibility', $product) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="pd-btn pd-btn-sm {{ $product->visible_in_catalog ? 'pd-btn-warning' : 'pd-btn-success' }}">
                                                    {{ $product->visible_in_catalog ? 'Katalogda Gizle' : 'Katalogda Göster' }}
                                                </button>
                                            </form>
                                            @if($product->local_stock_action_available)
                                                <a href="{{ route('admin.stock-purchases.create', array_filter(['variant' => $product->getAttribute('catalog_row_variant_id'), 'product' => $product->getAttribute('catalog_row_variant_id') ? null : $product->id])) }}" class="pd-btn pd-btn-sm pd-btn-primary">Stoğa Al</a>
                                                @if($product->getAttribute('catalog_row_supplier_id'))
                                                    <a href="{{ route('admin.procurements.supplier-requests.create', array_filter(['tenant_catalog_product_id' => $product->id, 'tenant_catalog_product_variant_id' => $product->getAttribute('catalog_row_variant_id'), 'supplier_id' => $product->getAttribute('catalog_row_supplier_id'), 'requested_quantity' => 1, 'source' => 'catalog'])) }}" class="pd-btn pd-btn-sm pd-btn-light">Tedarik Süreci Başlat</a>
                                                @else
                                                    <span class="pd-btn pd-btn-sm pd-btn-light" aria-disabled="true">Tedarikçi Eşleşmesi Eksik</span>
                                                @endif
                                            @endif
                                            @if($product->quote_toggle_available)
                                                <form action="{{ route('admin.catalog.toggle-quote-visibility', $product) }}" method="POST">
                                                    @csrf
                                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">{{ $product->quote_toggle_action_label }}</button>
                                                </form>
                                            @endif
                                            @if(!empty($product->warning_items))
                                                <a href="{{ route('admin.catalog.warnings') }}" class="pd-btn pd-btn-sm pd-btn-warning">Uyarıları Gör</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @include('admin.catalog.partials.pagination', ['paginator' => $products, 'filters' => $filters])
            @endif
        </div>
    </section>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Katalog Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Toplam ürün</span><strong>{{ $summary['total_products'] }}</strong></div>
            <div class="pd-status-row"><span>Local stoklu</span><strong>{{ $summary['local_stock_priority'] }}</strong></div>
            <div class="pd-status-row"><span>Tedarikçi ürünleri</span><strong>{{ $summary['supplier_products'] }}</strong></div>
            <div class="pd-status-row"><span>Fiyatı eksik</span><strong>{{ $summary['missing_price'] }}</strong></div>
            <div class="pd-status-row"><span>Uyarılı</span><strong>{{ $summary['warnings'] }}</strong></div>
            <div class="pd-status-row"><span>Katalogda görünen</span><strong>{{ $summary['visible'] }}</strong></div>
            <div class="pd-status-row"><span>Son sync</span><strong>{{ $summary['last_sync'] }}</strong></div>
        </div>
        <div class="pd-side-note">Abone Firma burada yalnız kendisine açılmış katalog ürünlerini ve local stoklarını yönetir. Product Hub teknik akışları ayrı ekranlardadır.</div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Katalog aksiyonları:</strong>
    <span class="pd-muted">Local ürün ekleyin, görünürlükleri yönetin ve uyarılı ürünleri hızlıca kontrol edin.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.catalog.local-products.create') }}" class="pd-btn pd-btn-primary">Local Ürün Ekle</a>
    <a href="{{ route('admin.catalog.visibility') }}" class="pd-btn pd-btn-light">Görünürlükleri Kaydet</a>
    <a href="{{ route('admin.catalog.warnings') }}" class="pd-btn pd-btn-warning">Uyarılıları Göster</a>
    <a href="{{ route('admin.promotion-quotes.create') }}" class="pd-btn pd-btn-light">Teklif Ürün Seçimine Git</a>
</div>
@endsection
