@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Ürünleri')
@section('page_title', 'Tedarikçi Ürünleri')
@section('page_subtitle', 'Tenant’a açılmış tedarikçi ürünlerini katalog, stok ve uyarı açısından yönetin.')

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Tedarikçi Ürünleri</h1>
                    <p class="pd-hero-subtitle">Bu ekran yalnız tenant’a açılmış tedarikçi projection ürünlerini gösterir. XML URL, mapping veya global kategori ayarları burada değiştirilemez.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-light">Ana Kataloğa Dön</a>
                    <a href="{{ route('admin.catalog.warnings') }}" class="pd-btn pd-btn-warning">Uyarılı Ürünler</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler</h3>
                <p class="pd-section-subtitle">Tedarikçi, stok, fiyat ve satılabilirlik durumuna göre listeyi daraltın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" action="{{ route('admin.catalog.supplier-products') }}" class="pd-form-grid-3">
                <div>
                    <label class="pd-label">Arama</label>
                    <input name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Ürün adı, kod, grup veya varyant">
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
                    <label class="pd-label">Ürün tipi</label>
                    <select name="product_type" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="variant" @selected($filters['product_type'] === 'variant')>Varyant</option>
                        <option value="flat" @selected($filters['product_type'] === 'flat')>Flat</option>
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
                        <option value="missing" @selected($filters['price_state'] === 'missing')>Fiyat eksik</option>
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
                    <a href="{{ route('admin.catalog.supplier-products') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tedarikçi Projection Listesi</h3>
                <p class="pd-section-subtitle">Son sync, stok, fiyat ve katalog görünürlüğünü tek ekranda izleyin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-hub-table-wrap">
                <table class="pd-table pd-package-table">
                    <thead>
                        <tr>
                            <th>Tedarikçi</th>
                            <th>Ürün</th>
                            <th>Kategori</th>
                            <th>Stok</th>
                            <th>Liste Fiyatı</th>
                            <th>Son Sync</th>
                            <th>Görünürlük</th>
                            <th>Uyarı</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td>{{ $product->supplier_label }}</td>
                                <td>
                                    <div class="font-medium">{{ $product->display_name }}</div>
                                    <div class="text-xs text-gray-500">{{ $product->display_code }}</div>
                                </td>
                                <td>{{ $product->category_display_name }}</td>
                                <td>{{ number_format((float) $product->effective_stock_quantity, 0, ',', '.') }}</td>
                                <td>{{ $product->display_price ? number_format((float) $product->display_price, 2, ',', '.') . ' ' . ($product->currency ?? 'TL') : '-' }}</td>
                                <td>{{ optional($product->last_synced_at ?: $product->updated_at)->format('d.m.Y H:i') ?: '-' }}</td>
                                <td>
                                    <div class="pd-badge pd-badge-{{ $product->visible_in_catalog ? 'green' : 'gray' }}">{{ $product->visible_in_catalog ? 'Katalogda' : 'Gizli' }}</div>
                                    <div class="pd-badge pd-badge-{{ $product->visible_in_quote ? 'blue' : 'gray' }}" style="margin-top:6px;">{{ $product->visible_in_quote ? 'Teklifte açık' : 'Teklifte kapalı' }}</div>
                                </td>
                                <td>
                                    @forelse($product->warning_items as $warning)
                                        <div class="pd-badge pd-badge-amber" style="margin-bottom:4px;">{{ $warning }}</div>
                                    @empty
                                        <span class="pd-badge pd-badge-green">Temiz</span>
                                    @endforelse
                                </td>
                                <td>
                                    <div class="pd-source-actions">
                                        <a href="{{ route('admin.catalog.show', $product) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                        <form action="{{ route('admin.catalog.toggle-visibility', $product) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">{{ $product->visible_in_catalog ? 'Gizle' : 'Göster' }}</button>
                                        </form>
                                        <details class="pd-inline-stock-entry">
                                            <summary class="pd-btn pd-btn-sm pd-btn-light">Local Stoğa Al</summary>
                                            @php
                                                $listPrice = (float) data_get($product->meta, 'price_snapshot.list_price', $product->display_price ?? 0);
                                                $discountRate = (float) data_get($product->meta, 'price_snapshot.discount_rate', 0);
                                                $calculatedPurchasePrice = round($listPrice * (1 - ($discountRate / 100)), 4);
                                            @endphp
                                            <form action="{{ route('admin.catalog.local-stock-entry', $product) }}" method="POST" class="pd-inline-stock-form" data-stock-entry-form>
                                                @csrf
                                                @if($product->getAttribute('catalog_row_variant_id'))
                                                    <input type="hidden" name="tenant_catalog_product_variant_id" value="{{ $product->getAttribute('catalog_row_variant_id') }}">
                                                @endif
                                                <div class="pd-muted" style="grid-column:1 / -1;">{{ $product->display_name }} · {{ $product->display_code }}</div>
                                                <select name="entry_type" class="pd-select">
                                                    <option value="existing_stock">Eldeki stok</option>
                                                    <option value="supplier_purchase">Tedarikçiden satın alma</option>
                                                </select>
                                                <input name="quantity" type="number" min="1" step="1" class="pd-input" placeholder="Miktar" required>
                                                <input name="list_price" type="number" min="0" step="0.01" class="pd-input" value="{{ $listPrice }}" placeholder="Liste fiyatı" readonly>
                                                <input name="discount_rate" type="number" min="0" max="100" step="0.01" class="pd-input" value="{{ $discountRate }}" placeholder="İskonto %">
                                                <input name="calculated_purchase_unit_price" type="number" min="0" step="0.01" class="pd-input" value="{{ $calculatedPurchasePrice }}" placeholder="Hesaplanan" readonly data-calculated-price>
                                                <input name="unit_purchase_price" type="number" min="0" step="0.01" class="pd-input" value="{{ $calculatedPurchasePrice }}" placeholder="Alış birim fiyatı" data-unit-price>
                                                <label class="pd-muted"><input type="checkbox" name="manual_purchase_unit_price" value="1" data-manual-price> Manuel fiyat</label>
                                                <button type="button" class="pd-btn pd-btn-sm pd-btn-light" data-auto-price>Otomatik hesapla</button>
                                                <div class="pd-muted" style="grid-column:1 / -1;">Eldeki stok girişinde tedarikçiye borç oluşturulmaz.</div>
                                                <button type="submit" class="pd-btn pd-btn-sm pd-btn-primary">Kaydet</button>
                                            </form>
                                        </details>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="pd-muted">Tenant’a açık tedarikçi ürünü bulunamadı.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('admin.catalog.partials.pagination', ['paginator' => $products, 'filters' => $filters])
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('input', (event) => {
    const form = event.target.closest('[data-stock-entry-form]');
    if (!form) return;

    const listPrice = Number(form.querySelector('input[name="list_price"]')?.value || 0);
    const discountRate = Number(form.querySelector('input[name="discount_rate"]')?.value || 0);
    const calculated = Number((listPrice * (1 - (discountRate / 100))).toFixed(4));
    const calculatedInput = form.querySelector('[data-calculated-price]');
    if (calculatedInput) calculatedInput.value = calculated;
    const manual = form.querySelector('[data-manual-price]');
    const unit = form.querySelector('[data-unit-price]');
    if (unit && manual && !manual.checked && !event.target.matches('[data-unit-price]')) {
        unit.value = calculated;
    }

    if (event.target.matches('[data-unit-price]')) {
        if (manual) manual.checked = true;
    }
});

document.addEventListener('click', (event) => {
    if (!event.target.matches('[data-auto-price]')) return;
    const form = event.target.closest('[data-stock-entry-form]');
    if (!form) return;

    const calculated = form.querySelector('[data-calculated-price]')?.value || '0';
    const unit = form.querySelector('[data-unit-price]');
    const manual = form.querySelector('[data-manual-price]');
    if (unit) unit.value = calculated;
    if (manual) manual.checked = false;
});
</script>
@endpush
