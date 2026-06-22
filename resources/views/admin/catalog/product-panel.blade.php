@extends('layouts.prodelya-admin')

@section('title', 'Ürün Paneli')
@section('page_title', 'Sade Ürün Paneli')
@section('page_subtitle', 'Satılabilir ürünleri sade ve hızlı tablo görünümünde kontrol edin.')

@section('page_actions')
    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-light">Detaylı Görünüm</a>
    <a href="{{ route('admin.catalog.warnings') }}" class="pd-btn pd-btn-warning">Uyarılar</a>
@endsection

@section('content')
    @php
        $warningBadge = static function (string $warning): string {
            return match ($warning) {
                'Kategori Bekliyor' => 'pd-badge-amber',
                'Stok yok', 'Stok Yok' => 'pd-badge-red',
                'Fiyat eksik', 'Fiyat Eksik' => 'pd-badge-red',
                default => 'pd-badge-blue',
            };
        };
    @endphp

    <div class="pd-card pd-form-card" style="margin-bottom:16px;">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Filtreler</h2>
                <p class="pd-card-subtitle">Ürün kodu, ad, SKU, renk, ölçü ve grup kodu ile arama yapılabilir.</p>
            </div>
        </div>
        <div class="pd-card-body">
            <form method="GET" action="{{ route('admin.catalog.product-panel') }}" class="pd-form-grid-3">
                <div>
                    <label class="pd-label">Arama</label>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Ürün kodu, ad, SKU, renk">
                </div>
                <div>
                    <label class="pd-label">Tedarikçi</label>
                    <select name="supplier" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((int) $filters['supplier'] === (int) $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Kategori Durumu</label>
                    <select name="category_status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="matched" @selected($filters['category_status'] === 'matched')>Eşleşmiş</option>
                        <option value="category_waiting" @selected($filters['category_status'] === 'category_waiting')>Kategori Bekliyor</option>
                        <option value="target_missing" @selected($filters['category_status'] === 'target_missing')>Hedef Bulunamayan</option>
                        <option value="warning" @selected($filters['category_status'] === 'warning')>Uyarılı</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Kategori</label>
                    <select name="category" class="pd-select">
                        <option value="">Tüm Kategoriler</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) $filters['category'] === (int) $category->id)>{{ $category->path ?: $category->name }}</option>
                        @endforeach
                    </select>
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
                    <label class="pd-label">Fiyat</label>
                    <select name="price_state" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="available" @selected($filters['price_state'] === 'available')>Fiyat var</option>
                        <option value="missing" @selected($filters['price_state'] === 'missing')>Fiyat yok</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Resim</label>
                    <select name="image_state" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="available" @selected($filters['image_state'] === 'available')>Resimli</option>
                        <option value="missing" @selected($filters['image_state'] === 'missing')>Resimsiz</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Uyarı</label>
                    <select name="warning_state" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="warning" @selected($filters['warning_state'] === 'warning')>Uyarılı</option>
                        <option value="missing_price" @selected($filters['warning_state'] === 'missing_price')>Fiyat Eksik</option>
                        <option value="stock_warning" @selected($filters['warning_state'] === 'stock_warning')>Stok Yok</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Kayıt</label>
                    <select name="limit" class="pd-select">
                        @foreach(['50', '100', '250', '500'] as $limit)
                            <option value="{{ $limit }}" @selected((string) $filters['limit'] === $limit)>{{ $limit }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="display:flex;align-items:flex-end;gap:8px;">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.catalog.product-panel') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>
    </div>

    <div class="pd-card pd-table-card">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Ürün Listesi</h2>
                <p class="pd-card-subtitle">Toplam {{ number_format($products->total(), 0, ',', '.') }} kayıt, sayfa başı {{ $products->perPage() }} ürün.</p>
            </div>
            <div class="flex items-center" style="gap:8px;">
                <span class="pd-badge pd-badge-green">Katalogda Görünüyor: {{ $stats['visible'] ?? 0 }}</span>
                <span class="pd-badge pd-badge-amber">Uyarılı: {{ $stats['warning'] ?? 0 }}</span>
            </div>
        </div>
        <div class="pd-card-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Resim</th>
                            <th>Tedarikçi</th>
                            <th>Ürün Kodu</th>
                            <th>Ürün Adı</th>
                            <th>Kategori</th>
                            <th>Fiyat</th>
                            <th>Stok</th>
                            <th>Renk</th>
                            <th>Ebat / Ölçü</th>
                            <th>Uyarı</th>
                            <th>Görünürlük</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td>
                                    @if($product->image_url)
                                        <img src="{{ $product->image_url }}" alt="{{ $product->display_name }}" style="width:44px;height:44px;object-fit:cover;border-radius:8px;">
                                    @else
                                        <span class="pd-badge pd-badge-light">Yok</span>
                                    @endif
                                </td>
                                <td>{{ $product->supplier_label }}</td>
                                <td>{{ $product->display_code }}</td>
                                <td style="max-width:360px;">
                                    <div style="font-weight:600;">{{ $product->display_name }}</div>
                                    <div class="text-xs text-gray-500">{{ $product->catalog_row_type === 'variant' ? 'Satılabilir varyant' : 'Satılabilir ürün' }}</div>
                                </td>
                                <td>{{ $product->category_display_name }}</td>
                                <td>{{ $product->formatted_selling_price }}</td>
                                <td>{{ number_format((float) $product->effective_stock_quantity, 0, ',', '.') }}</td>
                                <td>{{ data_get($product->meta, 'variant_color') ?: '-' }}</td>
                                <td>{{ data_get($product->meta, 'variant_size') ?: data_get($product->meta, 'variant_attributes.measure') ?: '-' }}</td>
                                <td>
                                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                        @php
                                            $badges = collect($product->warning_items)
                                                ->filter(fn ($warning) => in_array($warning, ['Kategori Bekliyor', 'Stok yok', 'Fiyat eksik', 'Net fiyat uyarısı', 'Tedarikçi özel fiyat uyarısı'], true))
                                                ->values();
                                        @endphp
                                        @if($badges->isEmpty())
                                            <span class="pd-badge pd-badge-green">Temiz</span>
                                        @else
                                            @foreach($badges as $warning)
                                                <span class="pd-badge {{ $warningBadge($warning) }}">{{ $warning }}</span>
                                            @endforeach
                                        @endif
                                        <span class="pd-badge {{ $product->visible_in_catalog ? 'pd-badge-green' : 'pd-badge-light' }}">{{ $product->visible_in_catalog ? 'Katalogda Görünüyor' : 'Gizli' }}</span>
                                        <span class="pd-badge {{ $product->visible_in_quote ? 'pd-badge-blue' : 'pd-badge-light' }}">{{ $product->visible_in_quote ? 'Teklife Açık' : 'Teklife Kapalı' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="pd-badge {{ $product->visible_in_catalog ? 'pd-badge-green' : 'pd-badge-light' }}">{{ $product->visible_in_catalog ? 'Açık' : 'Kapalı' }}</span>
                                </td>
                                <td>
                                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                        <a href="{{ route('admin.catalog.show', $product->id) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                        <a href="{{ route('admin.catalog.show', $product->id) }}" class="pd-btn pd-btn-sm pd-btn-light">Local Stoğa Al</a>
                                        @if(!$product->visible_in_quote)
                                            <form action="{{ route('admin.catalog.toggle-quote-visibility', $product->id) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="pd-btn pd-btn-sm pd-btn-primary">Teklifte Kullan</button>
                                            </form>
                                        @endif
                                        <form action="{{ route('admin.catalog.toggle-visibility', $product->id) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="pd-btn pd-btn-sm {{ $product->visible_in_catalog ? 'pd-btn-warning' : 'pd-btn-primary' }}">{{ $product->visible_in_catalog ? 'Gizle' : 'Göster' }}</button>
                                        </form>
                                        @if(!empty($product->warning_items))
                                            <a href="{{ route('admin.catalog.warnings', ['search' => $product->display_code]) }}" class="pd-btn pd-btn-sm pd-btn-warning">Uyarıyı Gör</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12">Filtrelere uygun ürün bulunamadı.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top:16px;">
                {{ $products->links() }}
            </div>
        </div>
    </div>
@endsection
