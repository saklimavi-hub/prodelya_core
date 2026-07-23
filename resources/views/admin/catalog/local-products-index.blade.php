@extends('layouts.prodelya-admin')

@section('title', 'Ürün Listem')
@section('page_title', 'Ürün Listem')
@section('page_subtitle', 'Kendi ürünlerinizi flat ve exact varyant bazında yönetin; parent gruplar yalnız bilgi amaçlı görünür.')

@section('content')
<div class="pd-local-product-shell">
    @if(session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="pd-alert-warning">{{ session('error') }}</div>
    @endif

    @include('admin.catalog.partials._local-products-subnav')

    <section class="pd-hero-card pd-local-product-hero">
        <div class="pd-card-body">
            <div class="pd-local-product-hero-row">
                <div>
                    <span class="pd-local-product-eyebrow">Abone Firma · Kendi Ürünlerim</span>
                    <h1 class="pd-local-product-hero-title">Ürün Listem</h1>
                    <p class="pd-local-product-hero-subtitle">Varyantsız ürünler exact satır olarak görünür. Varyantlı ürünlerde parent satırı yalnız grup özeti sunar; stok, teklif ve quote search truth’u exact varyant satırlarındadır.</p>
                </div>
                <div class="pd-local-product-hero-actions">
                    <a href="{{ route('admin.catalog.local-products.create') }}" class="pd-btn pd-btn-primary">Yeni Ürün Ekle</a>
                    <a href="{{ route('admin.catalog.local-products.import') }}" class="pd-btn pd-btn-light">CSV Import</a>
                </div>
            </div>
            <div class="pd-local-product-stat-strip">
                <div class="pd-local-product-stat"><span>Toplam ürün kaydı</span><strong>{{ number_format((int) ($stats['total'] ?? 0), 0, ',', '.') }}</strong></div>
                <div class="pd-local-product-stat"><span>Aktif ürün</span><strong>{{ number_format((int) (($stats['total'] ?? 0) - ($stats['inactive'] ?? 0)), 0, ',', '.') }}</strong></div>
                <div class="pd-local-product-stat"><span>Stokta</span><strong>{{ number_format((int) ($stats['in_stock'] ?? 0), 0, ',', '.') }}</strong></div>
                <div class="pd-local-product-stat"><span>Katalog görünür</span><strong>{{ number_format((int) ($stats['visible'] ?? 0), 0, ',', '.') }}</strong></div>
            </div>
        </div>
    </section>

    <div class="pd-local-product-layout">
        <section class="pd-section-card pd-local-product-main-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Kendi Ürün Listesi</h3>
                    <p class="pd-section-subtitle">Flat ürünler ve varyant grupları aynı tabloda; exact aksiyonlar yalnız satılabilir satırlarda gösterilir.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form method="GET" action="{{ route('admin.catalog.local-products') }}" class="pd-local-product-filter-grid">
                    <div>
                        <label class="pd-label">Ürün adı veya kodu</label>
                        <input name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Arama yapın">
                    </div>
                    <div>
                        <label class="pd-label">Stok durumu</label>
                        <select name="stock_state" class="pd-select">
                            <option value="">Tümü</option>
                            <option value="in_stock" @selected($filters['stock_state'] === 'in_stock')>Stok var</option>
                            <option value="out_of_stock" @selected($filters['stock_state'] === 'out_of_stock')>Stok yok</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Kategori seçimi</label>
                        <select class="pd-select" aria-label="Kategori seçimi">
                            <option value="">Aktif katalog kategorileri</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->full_path }}</option>
                            @endforeach
                        </select>
                    </div>                    <div>
                        <label class="pd-label">Sayfa boyutu</label>
                        <select name="limit" class="pd-select">
                            @foreach([50, 100, 250, 500] as $limit)
                                <option value="{{ $limit }}" @selected((string) $filters['limit'] === (string) $limit)>{{ $limit }}</option>
                            @endforeach
                            <option value="all" @selected($filters['limit'] === 'all')>Tümü</option>
                        </select>
                    </div>
                    <div class="pd-local-product-filter-actions">
                        <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                        <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-light">Temizle</a>
                    </div>
                </form>

                <div class="pd-local-product-table-wrap">
                    <table class="pd-table pd-local-product-table">
                        <thead>
                            <tr>
                                <th>Ürün / Varyant</th>
                                <th>Kimlik</th>
                                <th>Fiyat</th>
                                <th>Local Stok</th>
                                <th>Görünürlük</th>
                                <th>Durum</th>
                                <th>Aksiyon</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($products as $product)
                                @php
                                    $variants = $product->variants()->orderBy('variant_name')->get();
                                    $isParent = $variants->isNotEmpty();
                                    $groupLocalStock = $variants->sum('local_stock_quantity');
                                    $priceValues = $variants->pluck('display_price')->filter(fn ($value) => $value !== null)->map(fn ($value) => (float) $value);
                                    $priceRange = $priceValues->isEmpty() ? null : [$priceValues->min(), $priceValues->max()];
                                @endphp
                                @if($isParent)
                                    <tr>
                                        <td>
                                            <div class="pd-local-product-table-product">
                                                <div class="pd-local-product-thumb">G</div>
                                                <div>
                                                    <strong>{{ $product->display_name }}</strong>
                                                    <div class="pd-local-product-muted-row">
                                                        <span class="pd-badge pd-badge-purple">Varyant Grubu</span>
                                                        <span>{{ $variants->count() }} exact varyant</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong>{{ $product->display_code }}</strong>
                                            <div class="pd-local-product-muted-row">Parent satırı bilgi amaçlıdır.</div>
                                        </td>
                                        <td>
                                            @if($priceRange)
                                                {{ number_format($priceRange[0], 2, ',', '.') }} - {{ number_format($priceRange[1], 2, ',', '.') }} {{ ($product->currency ?? 'TRY') === 'TRY' ? 'TL' : ($product->currency ?? 'TRY') }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>{{ number_format((float) $groupLocalStock, 0, ',', '.') }}</td>
                                        <td><span class="pd-badge pd-badge-gray">Exact varyantlarda yönetilir</span></td>
                                        <td><span class="pd-badge pd-badge-{{ $product->is_active ? 'green' : 'gray' }}">{{ $product->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                                        <td>
                                            <div class="pd-local-product-action-row">
                                                <a href="{{ route('admin.catalog.local-products.show', $product) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                                <a href="{{ route('admin.catalog.local-products.edit', $product) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
                                            </div>
                                        </td>
                                    </tr>
                                    @foreach($variants as $variant)
                                        @php
                                            $variantVisibleInQuote = (bool) data_get($variant->meta, 'quote_search_visible', $product->visible_in_quote);
                                        @endphp
                                        <tr>
                                            <td>
                                                <div style="padding-left: 24px;">
                                                    <strong>{{ $variant->display_name }}</strong>
                                                    <div class="pd-local-product-muted-row">{{ $variant->variant_color ?: 'Varyant' }} @if($variant->variant_size) · {{ $variant->variant_size }} @endif</div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong>{{ $variant->variant_code }}</strong>
                                                <div class="pd-local-product-muted-row">{{ $product->display_code }}</div>
                                            </td>
                                            <td>{{ $variant->display_price !== null ? number_format((float) $variant->display_price, 2, ',', '.') . ' ' . (($variant->currency ?? 'TRY') === 'TRY' ? 'TL' : ($variant->currency ?? 'TRY')) : '-' }}</td>
                                            <td>{{ number_format((float) ($variant->local_stock_quantity ?? 0), 0, ',', '.') }}</td>
                                            <td>
                                                <div class="pd-local-product-visibility-stack">
                                                    <span class="pd-badge pd-badge-{{ $variant->visible_in_catalog ? 'green' : 'gray' }}">Katalog {{ $variant->visible_in_catalog ? 'Açık' : 'Kapalı' }}</span>
                                                    <span class="pd-badge pd-badge-{{ $variantVisibleInQuote ? 'blue' : 'gray' }}">Teklif {{ $variantVisibleInQuote ? 'Açık' : 'Kapalı' }}</span>
                                                </div>
                                            </td>
                                            <td><span class="pd-badge pd-badge-{{ $variant->is_active ? 'green' : 'gray' }}">{{ $variant->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                                            <td>
                                                <div class="pd-local-product-action-row">
                                                    <a href="{{ route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $variant->id]) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                                    <a href="{{ route('admin.catalog.local-products.edit', $product) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
                                                    <a href="{{ route('admin.stock-purchases.create', ['variant' => $variant->id]) }}" class="pd-btn pd-btn-sm pd-btn-light">Stoğa Al</a>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td>
                                            <div class="pd-local-product-table-product">
                                                <div class="pd-local-product-thumb">{{ \Illuminate\Support\Str::limit($product->display_name, 1, '') }}</div>
                                                <div>
                                                    <strong>{{ $product->display_name }}</strong>
                                                    <div class="pd-local-product-muted-row"><span class="pd-badge pd-badge-purple">Kendi Ürünüm</span></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>{{ $product->display_code }}</td>
                                        <td>{{ $product->display_price ? number_format((float) $product->display_price, 2, ',', '.') . ' ' . (($product->currency ?? 'TRY') === 'TRY' ? 'TL' : ($product->currency ?? 'TRY')) : '-' }}</td>
                                        <td>{{ number_format((float) ($product->display_local_stock_quantity ?? 0), 0, ',', '.') }}</td>
                                        <td>
                                            <div class="pd-local-product-visibility-stack">
                                                <span class="pd-badge pd-badge-{{ $product->visible_in_catalog ? 'green' : 'gray' }}">Katalog {{ $product->visible_in_catalog ? 'Açık' : 'Kapalı' }}</span>
                                                <span class="pd-badge pd-badge-{{ $product->visible_in_quote ? 'blue' : 'gray' }}">Teklif {{ $product->visible_in_quote ? 'Açık' : 'Kapalı' }}</span>
                                            </div>
                                        </td>
                                        <td><span class="pd-badge pd-badge-{{ $product->is_active ? 'green' : 'gray' }}">{{ $product->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                                        <td>
                                            <div class="pd-local-product-action-row">
                                                <a href="{{ route('admin.catalog.local-products.show', $product) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                                <a href="{{ route('admin.catalog.local-products.edit', $product) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
                                                <a href="{{ route('admin.stock-purchases.create', ['product' => $product->id]) }}" class="pd-btn pd-btn-sm pd-btn-light">Stoğa Al</a>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="7"><div class="pd-note">Henüz kendi ürününüz eklenmedi.</div></td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @include('admin.catalog.partials.pagination', ['paginator' => $products, 'filters' => $filters])
            </div>
        </section>

        <aside class="pd-local-product-sidebar">
            <section class="pd-section-card pd-local-product-summary-card">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Hızlı Özet</h3>
                        <p class="pd-section-subtitle">Parent gruplar bilgi amaçlı, stock ve teklif truth’u ise exact satırlarda tutulur.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-local-product-sidebar-list">
                        <div class="pd-local-product-sidebar-row"><span>Toplam</span><strong>{{ number_format((int) ($stats['total'] ?? 0), 0, ',', '.') }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Pasif</span><strong>{{ number_format((int) ($stats['inactive'] ?? 0), 0, ',', '.') }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Stokta</span><strong>{{ number_format((int) ($stats['in_stock'] ?? 0), 0, ',', '.') }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Katalog görünür</span><strong>{{ number_format((int) ($stats['visible'] ?? 0), 0, ',', '.') }}</strong></div>
                    </div>
                    <div class="pd-local-product-sidebar-links">
                        <a href="{{ route('admin.catalog.local-products.create') }}">Yeni ürün oluştur</a>
                        <a href="{{ route('admin.catalog.local-products.import') }}">CSV Import</a>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection
