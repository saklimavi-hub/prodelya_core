@extends('layouts.prodelya-admin')

@section('title', 'Standart Ürünler')

@section('content')
<div class="pd-page-header">
    <div>
        <h1 class="pd-section-title">Standart Ürünler</h1>
        <p class="pd-muted mt-1">Ham ürünlerden dönüştürülen standart ürün ve varyasyon kayıtları.</p>
    </div>
    <div class="pd-actions">
        <a href="{{ route('admin.product-data-hub.raw-products') }}" class="pd-btn pd-btn-light">Ham Ürünler</a>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-body">
        @if($products->isEmpty())
            <div class="pd-note">Henüz standart ürün oluşturulmadı. Önce ham ürünleri standart ürüne dönüştürün.</div>
        @else
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Görsel</th>
                            <th>Standart Kod</th>
                            <th>Ürün Adı</th>
                            <th>Kategori</th>
                            <th>Varyasyon Sayısı</th>
                            <th>Galeri</th>
                            <th>Varyasyon Görseli</th>
                            <th>Ürün Linkleri</th>
                            <th>Toplam Stok</th>
                            <th>Min Fiyat</th>
                            <th>Max Fiyat</th>
                            <th>Tedarikçi Sayısı</th>
                            <th>Katalogda Görünür</th>
                            <th>Durum</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $product)
                            <tr>
                                <td>
                                    @php
                                        $primaryImage = $product->primaryImage?->image_url ?: $product->image_url;
                                        $productImages = collect($product->getRelation('images') ?? []);
                                        $galleryCount = $productImages->where('image_type', 'gallery')->count();
                                        $galleryPreview = $productImages->where('image_type', 'gallery')->take(5);
                                        $variantImageCount = $product->variants->flatMap->images->count();
                                    @endphp
                                    @if($primaryImage)
                                        <div>
                                            <img src="{{ $primaryImage }}" alt="{{ $product->display_name }}" class="pd-product-thumb-sm">
                                            @if($galleryPreview->isNotEmpty())
                                                <div class="pd-gallery-strip">
                                                    @foreach($galleryPreview as $image)
                                                        <a href="{{ $image->image_url }}" target="_blank" rel="noopener noreferrer">
                                                            <img src="{{ $image->image_url }}" alt="Galeri görseli">
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <span class="pd-badge pd-badge-gray">Yok</span>
                                    @endif
                                </td>
                                <td><span class="pd-badge pd-badge-blue">{{ $product->standard_product_code ?: $product->sku }}</span></td>
                                <td>{{ $product->display_name }}</td>
                                <td>{{ $product->category_display_name }}</td>
                                <td>{{ $product->variants_count ?? $product->variant_count }}</td>
                                <td>{{ $galleryCount > 0 ? $galleryCount . ' görsel' : '-' }}</td>
                                <td>{{ $variantImageCount > 0 ? $variantImageCount . ' görsel' : '-' }}</td>
                                <td>
                                    <div class="pd-product-link-list">
                                        @if($product->product_url)
                                            <a href="{{ $product->product_url }}" target="_blank" rel="noopener noreferrer">Ürün Sayfası</a>
                                        @endif
                                        @if($product->detail_url)
                                            <a href="{{ $product->detail_url }}" target="_blank" rel="noopener noreferrer">Detay Linki</a>
                                        @endif
                                        @if(!$product->product_url && !$product->detail_url)
                                            <span class="pd-badge pd-badge-gray">Yok</span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ is_null($product->total_stock_quantity) ? '-' : number_format((float) $product->total_stock_quantity, 0, ',', '.') }}</td>
                                <td>{{ is_null($product->min_purchase_price) ? '-' : number_format((float) $product->min_purchase_price, 2, ',', '.') . ' ' . ($product->currency ?: 'TL') }}</td>
                                <td>{{ is_null($product->max_purchase_price) ? '-' : number_format((float) $product->max_purchase_price, 2, ',', '.') . ' ' . ($product->currency ?: 'TL') }}</td>
                                <td>{{ $product->supplier_count }}</td>
                                <td>
                                    <span class="pd-badge pd-badge-{{ $product->visible_in_catalog ? 'green' : 'gray' }}">
                                        {{ $product->visible_in_catalog ? 'Evet' : 'Hayır' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="pd-badge pd-badge-{{ $product->is_active ? 'green' : 'gray' }}">
                                        {{ $product->is_active ? 'Aktif' : 'Pasif' }}
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="pd-btn pd-btn-sm pd-btn-light" disabled>Detay</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

@section('summary')
<div class="pd-card">
    <div class="pd-card-body">
        <div class="pd-summary-title">Standart Ürün Özeti</div>
        <div class="pd-summary-row"><span>Toplam Ürün</span><strong>{{ $products->count() }}</strong></div>
        <div class="pd-summary-row"><span>Aktif</span><strong>{{ $products->where('is_active', true)->count() }}</strong></div>
        <div class="pd-summary-row"><span>Varyasyon</span><strong>{{ $products->sum(fn ($product) => $product->variants_count ?? $product->variant_count) }}</strong></div>
        <div class="pd-summary-row"><span>Katalogda Görünen</span><strong>{{ $products->where('visible_in_catalog', true)->count() }}</strong></div>
        <div class="pd-note mt-4">Tenant catalog projection Aşama 8’de yapılacak.</div>
    </div>
</div>
@endsection
