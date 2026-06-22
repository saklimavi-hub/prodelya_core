@extends('layouts.prodelya-admin')

@section('title', 'Kendi Ürünlerim')
@section('page_title', 'Kendi Ürünlerim')
@section('page_subtitle', 'Tenant’a özel local ürünleri ekleyin, düzenleyin ve yalnız kendi satış kataloğunuzda yönetin.')

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
                    <h1 class="pd-hero-title">Local / Kendi Ürünlerim</h1>
                    <p class="pd-hero-subtitle">Global Product Data Hub kaynaklarıyla karışmadan tenant’a özel ürün, fiyat ve stok bilgisini yönetin.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-purple">{{ $stats['local'] }} local ürün</span>
                        <span class="pd-badge pd-badge-green">{{ $stats['in_stock'] }} stokta olan</span>
                        <span class="pd-badge pd-badge-amber">{{ $stats['warning'] }} uyarılı</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-light">Ana Kataloğa Dön</a>
                    <a href="{{ route('admin.catalog.local-products.import') }}" class="pd-btn pd-btn-primary">CSV Import</a>
                    <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-light">Yeni Form</a>
                </div>
            </div>
        </div>
    </section>

    <div class="pd-grid pd-grid-2">
        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">{{ $editProduct ? 'Local Ürünü Düzenle' : 'Yeni Local Ürün' }}</h3>
                    <p class="pd-section-subtitle">{{ $editProduct ? 'Seçilen tenant ürününü güncelleyin.' : 'Basit manuel ürün giriş formu.' }}</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form action="{{ $editProduct ? route('admin.catalog.local-products.update', $editProduct) : route('admin.catalog.local-products.store') }}" method="POST" class="pd-form-grid-2">
                    @csrf
                    @if($editProduct)
                        @method('PUT')
                    @endif
                    <div>
                        <label class="pd-label">Ürün adı</label>
                        <input name="product_name" class="pd-input" value="{{ old('product_name', $editProduct?->display_name) }}" required>
                    </div>
                    <div>
                        <label class="pd-label">Ürün kodu</label>
                        <input name="product_code" class="pd-input" value="{{ old('product_code', $editProduct?->display_code) }}" required>
                    </div>
                    <div>
                        <label class="pd-label">Kategori</label>
                        <select name="standard_category_id" class="pd-select">
                            <option value="">Seçiniz</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) old('standard_category_id', $editProduct?->standard_category_id) === (string) $category->id)>{{ $category->full_path }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Görsel URL</label>
                        <input name="image_url" class="pd-input" value="{{ old('image_url', $editProduct?->image_url) }}">
                    </div>
                    <div>
                        <label class="pd-label">Liste fiyatı</label>
                        <input name="display_price" type="number" step="0.01" class="pd-input" value="{{ old('display_price', $editProduct?->display_price) }}">
                    </div>
                    <div>
                        <label class="pd-label">Para birimi</label>
                        <input name="currency" class="pd-input" value="{{ old('currency', $editProduct?->currency ?? 'TL') }}">
                    </div>
                    <div>
                        <label class="pd-label">KDV</label>
                        <input name="vat_rate" type="number" step="0.01" class="pd-input" value="{{ old('vat_rate', data_get($editProduct?->meta, 'price_snapshot.vat_rate', 20)) }}">
                    </div>
                    <div>
                        <label class="pd-label">Stok</label>
                        <input name="local_stock_quantity" type="number" step="1" class="pd-input" value="{{ old('local_stock_quantity', $editProduct?->local_stock_quantity ?? 0) }}">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label class="pd-label">Açıklama</label>
                        <textarea name="description" class="pd-textarea" rows="4">{{ old('description', $editProduct?->description) }}</textarea>
                    </div>
                    <div class="pd-chip-group" style="grid-column: 1 / -1;">
                        <label><input type="checkbox" name="visible_in_catalog" value="1" @checked(old('visible_in_catalog', $editProduct?->visible_in_catalog ?? true))> Katalogda görünsün</label>
                        <label><input type="checkbox" name="visible_in_quote" value="1" @checked(old('visible_in_quote', $editProduct?->visible_in_quote ?? true))> Teklifte kullanılsın</label>
                        <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editProduct?->is_active ?? true))> Aktif</label>
                        <label><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $editProduct?->is_featured ?? false))> Öne çıkan</label>
                        <label><input type="checkbox" name="local_stock_priority" value="1" @checked(old('local_stock_priority', $editProduct?->local_stock_priority ?? true))> Local stok öncelikli</label>
                    </div>
                    <div class="pd-hero-actions" style="grid-column: 1 / -1;">
                        <button type="submit" class="pd-btn pd-btn-primary">{{ $editProduct ? 'Değişiklikleri Kaydet' : 'Local Ürünü Kaydet' }}</button>
                        @if($editProduct)
                            <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-light">İptal</a>
                        @endif
                    </div>
                </form>
            </div>
        </section>

        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Local Ürün Listesi</h3>
                    <p class="pd-section-subtitle">Başka tenant’lar bu ürünleri göremez. Kullanılmış ürünler silinmek yerine arşivlenir.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form method="GET" action="{{ route('admin.catalog.local-products') }}" class="pd-form-grid-3" style="margin-bottom:16px;">
                    <div>
                        <label class="pd-label">Arama</label>
                        <input name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Ürün adı veya kod">
                    </div>
                    <div>
                        <label class="pd-label">Stok</label>
                        <select name="stock_state" class="pd-select">
                            <option value="">Tümü</option>
                            <option value="in_stock" @selected($filters['stock_state'] === 'in_stock')>Stok var</option>
                            <option value="out_of_stock" @selected($filters['stock_state'] === 'out_of_stock')>Stok yok</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Limit</label>
                        <select name="limit" class="pd-select">
                            @foreach([50, 100, 250, 500] as $limit)
                                <option value="{{ $limit }}" @selected((string) $filters['limit'] === (string) $limit)>{{ $limit }}</option>
                            @endforeach
                            <option value="all" @selected($filters['limit'] === 'all')>Tümü</option>
                        </select>
                    </div>
                    <div class="pd-hero-actions">
                        <button class="pd-btn pd-btn-primary">Filtrele</button>
                        <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-light">Temizle</a>
                    </div>
                </form>
                @php
                    $supplierLocalProducts = collect($products->items())->filter(fn ($product) => $product->catalog_source !== 'local_product' && (float) ($product->local_stock_quantity ?? 0) > 0);
                    $ownLocalProducts = collect($products->items())->filter(fn ($product) => $product->catalog_source === 'local_product');
                @endphp

                <div class="pd-note pd-note-soft-blue" style="margin-bottom:12px;">
                    Tedarikçiden local stoğa alınan ürünler ile tenant’ın kendi ürünleri ayrı takip edilir.
                </div>

                <h4 class="pd-section-title" style="font-size:15px; margin-bottom:10px;">Tedarikçiden Local Stoğa Alınan Ürünler</h4>
                <div class="pd-source-list" style="margin-bottom:18px;">
                    @forelse($supplierLocalProducts as $product)
                        <div class="pd-source-row">
                            <div class="pd-source-main">
                                <h4 class="pd-source-name">{{ $product->display_name }}</h4>
                                <div class="pd-source-subline">
                                    <span class="pd-badge pd-badge-green">Tedarikçiden Local Stok</span>
                                    <span class="pd-badge pd-badge-blue">{{ $product->display_code }}</span>
                                    <span class="pd-badge pd-badge-gray">{{ $product->supplier_label }}</span>
                                    <span class="pd-badge pd-badge-{{ $product->visible_in_catalog ? 'green' : 'gray' }}">{{ $product->visible_in_catalog ? 'Katalogda görünür' : 'Katalogda gizli' }}</span>
                                    <span class="pd-badge pd-badge-{{ $product->visible_in_quote ? 'blue' : 'gray' }}">{{ $product->visible_in_quote ? 'Teklifte açık' : 'Teklifte kapalı' }}</span>
                                    @if($product->has_local_stock_priority)
                                        <span class="pd-badge pd-badge-amber">Local stok öncelikli</span>
                                    @endif
                                </div>
                            </div>
                            <div class="pd-source-meta">
                                <div class="pd-source-meta-line">Kategori: {{ $product->category_display_name }}</div>
                                <div class="pd-source-meta-line">Fiyat: {{ $product->display_price ? number_format((float) $product->display_price, 2, ',', '.') . ' ' . ($product->currency ?? 'TL') : '-' }}</div>
                                <div class="pd-source-meta-line">Local stok: {{ number_format((float) ($product->local_stock_quantity ?? 0), 0, ',', '.') }}</div>
                                <div class="pd-source-meta-line">Durum: {{ $product->is_active ? 'Aktif' : 'Pasif' }}</div>
                            </div>
                            <div class="pd-source-actions">
                                <a href="{{ route('admin.catalog.show', $product) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                <form action="{{ route('admin.catalog.toggle-visibility', $product) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">{{ $product->visible_in_catalog ? 'Katalogda Gizle' : 'Katalogda Göster' }}</button>
                                </form>
                                <form action="{{ route('admin.catalog.toggle-quote-visibility', $product) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">{{ $product->visible_in_quote ? 'Teklife Kapat' : 'Teklifte Aç' }}</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="pd-note">Henüz tedarikçiden local stoğa alınmış ürün yok.</div>
                    @endforelse
                </div>

                <h4 class="pd-section-title" style="font-size:15px; margin-bottom:10px;">Kendi Ürünlerim</h4>
                <div class="pd-source-list">
                    @forelse($ownLocalProducts as $product)
                        <div class="pd-source-row">
                            <div class="pd-source-main">
                                <h4 class="pd-source-name">{{ $product->display_name }}</h4>
                                <div class="pd-source-subline">
                                    <span class="pd-badge pd-badge-purple">Kendi Ürünüm</span>
                                    <span class="pd-badge pd-badge-blue">{{ $product->display_code }}</span>
                                    <span class="pd-badge pd-badge-{{ $product->visible_in_catalog ? 'green' : 'gray' }}">{{ $product->visible_in_catalog ? 'Katalogda görünür' : 'Katalogda gizli' }}</span>
                                    <span class="pd-badge pd-badge-{{ $product->visible_in_quote ? 'blue' : 'gray' }}">{{ $product->visible_in_quote ? 'Teklifte açık' : 'Teklifte kapalı' }}</span>
                                    @if($product->has_local_stock_priority)
                                        <span class="pd-badge pd-badge-amber">Local stok öncelikli</span>
                                    @endif
                                </div>
                            </div>
                            <div class="pd-source-meta">
                                <div class="pd-source-meta-line">Kategori: {{ $product->category_display_name }}</div>
                                <div class="pd-source-meta-line">Fiyat: {{ $product->display_price ? number_format((float) $product->display_price, 2, ',', '.') . ' ' . ($product->currency ?? 'TL') : '-' }}</div>
                                <div class="pd-source-meta-line">Local stok: {{ number_format((float) ($product->local_stock_quantity ?? 0), 0, ',', '.') }}</div>
                                <div class="pd-source-meta-line">Durum: {{ $product->is_active ? 'Aktif' : 'Pasif' }}</div>
                            </div>
                            <div class="pd-source-actions">
                                <a href="{{ route('admin.catalog.local-products', ['edit' => $product->id]) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
                                <form action="{{ route('admin.catalog.local-products.deactivate', $product) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-warning">Pasif Yap</button>
                                </form>
                                <form action="{{ route('admin.catalog.toggle-visibility', $product) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">{{ $product->visible_in_catalog ? 'Katalogda Gizle' : 'Katalogda Göster' }}</button>
                                </form>
                                <form action="{{ route('admin.catalog.toggle-quote-visibility', $product) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">{{ $product->visible_in_quote ? 'Teklife Kapat' : 'Teklifte Aç' }}</button>
                                </form>
                                <form action="{{ route('admin.catalog.local-products.destroy', $product) }}" method="POST" onsubmit="return confirm('Bu local ürün silinecek veya geçmiş kullanımı varsa arşivlenecek. Devam etmek istiyor musunuz?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-danger">Sil</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="pd-note">Henüz kendi local ürününüz eklenmedi.</div>
                    @endforelse
                </div>
                @include('admin.catalog.partials.pagination', ['paginator' => $products, 'filters' => $filters])
            </div>
        </section>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Local Ürün Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Toplam local ürün</span><strong>{{ $stats['local'] }}</strong></div>
            <div class="pd-status-row"><span>Stokta olan</span><strong>{{ $stats['in_stock'] }}</strong></div>
            <div class="pd-status-row"><span>Uyarılı</span><strong>{{ $stats['warning'] }}</strong></div>
            <div class="pd-status-row"><span>Katalogda görünen</span><strong>{{ $stats['visible'] }}</strong></div>
        </div>
        <div class="pd-side-note">Local ürünler yalnız bu tenant’a aittir. Global XML kaynaklarına veya Product Data Hub ayarlarına dokunmaz.</div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Local ürün aksiyonları:</strong>
    <span class="pd-muted">Ekle, düzenle, görünürlüğünü yönet ve gerekiyorsa güvenli şekilde pasifleştir.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-primary">Yeni Local Ürün</a>
    <a href="{{ route('admin.catalog.visibility') }}" class="pd-btn pd-btn-light">Görünürlükleri Yönet</a>
    <a href="{{ route('admin.catalog.warnings') }}" class="pd-btn pd-btn-warning">Uyarılıları Göster</a>
</div>
@endsection
