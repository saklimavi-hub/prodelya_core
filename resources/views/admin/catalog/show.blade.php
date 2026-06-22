@extends('layouts.prodelya-admin')

@section('title', 'Ürün Detayı')
@section('page_title', 'Ürün Detayı')
@section('page_subtitle', 'Tenant kataloğuna çıkan ürün, local stok ve teklif görünürlüğü detayları.')

@section('content')
<div class="pd-grid pd-grid-3">
    <div class="pd-card" style="grid-column: span 2;">
        <div class="pd-card-header">
            <strong>Ürün Bilgisi</strong>
        </div>
        <div class="pd-card-body">
            <div class="pd-grid pd-grid-2">
                <div>
                    <div class="pd-summary-row"><span>Ürün Adı</span><strong>{{ $product->display_name }}</strong></div>
                    <div class="pd-summary-row"><span>Kod</span><strong>{{ $product->display_code }}</strong></div>
                    <div class="pd-summary-row"><span>Kategori</span><strong>{{ $product->category_display_name }}</strong></div>
                    <div class="pd-summary-row"><span>Ürün Ailesi</span><strong>{{ $product->product_family ?? '-' }}</strong></div>
                    <div class="pd-summary-row"><span>Görünen Fiyat</span><strong>{{ $product->getFormattedSellingPrice() }}</strong></div>
                    <div class="pd-summary-row"><span>Toplam Stok</span><strong>{{ number_format((float) ($product->total_stock_quantity ?? 0), 0, ',', '.') }}</strong></div>
                    <div class="pd-summary-row"><span>Satışta kullanılacak stok</span><strong>{{ number_format((float) ($product->effective_stock_quantity ?? 0), 0, ',', '.') }}</strong></div>
                </div>
                <div>
                    @if($product->image_url)
                        <img src="{{ $product->image_url }}" alt="{{ $product->display_name }}" class="catalog-product-image pd-allow-large">
                        @if($product->images->isNotEmpty())
                            <div class="pd-gallery-strip">
                                @foreach($product->images->take(12) as $image)
                                    <a href="{{ $image->image_url }}" target="_blank" rel="noopener noreferrer">
                                        <img src="{{ $image->image_url }}" alt="Katalog görseli">
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <div class="pd-note">Bu ürün için henüz görsel bulunmuyor.</div>
                    @endif
                </div>
            </div>

            <div class="pd-note mt-4">
                {{ $product->description ?: 'Açıklama henüz eklenmedi. Ürün detay açıklamaları sonraki aşamada zenginleştirilecek.' }}
            </div>

            <div class="pd-card mt-4">
                <div class="pd-card-header">
                    <strong>Ürün Galerisi</strong>
                </div>
                <div class="pd-card-body">
                    @if($product->images->isEmpty())
                        <div class="pd-note">Bu ürün için henüz galeri görseli bulunmuyor.</div>
                    @else
                        <div class="pd-gallery-strip">
                            @foreach($product->images as $image)
                                <a href="{{ $image->image_url }}" target="_blank" rel="noopener noreferrer">
                                    <img src="{{ $image->image_url }}" alt="Ürün galeri görseli">
                                </a>
                            @endforeach
                        </div>
                    @endif

                    <div class="pd-product-link-list mt-3">
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
                </div>
            </div>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-body">
            <div class="pd-summary-title">Katalog Ayarları</div>
            <div class="pd-summary-row"><span>Katalog Durumu</span><strong>{{ $product->visible_in_catalog ? 'Görünür' : 'Gizli' }}</strong></div>
            <div class="pd-summary-row"><span>Teklifte kullanım</span><strong>{{ $product->visible_in_quote ? 'Açık' : 'Kapalı' }}</strong></div>
            <div class="pd-summary-row"><span>Local Stok</span><strong>{{ number_format((float) ($product->local_stock_quantity ?? 0), 0, ',', '.') }}</strong></div>
            <div class="pd-summary-row"><span>Tedarikçi Stok</span><strong>{{ number_format((float) ($product->supplier_stock_quantity ?? 0), 0, ',', '.') }}</strong></div>
            <div class="pd-summary-row"><span>Güvenli Stok</span><strong>{{ $product->safe_stock_quantity ?? 0 }}</strong></div>
            <div class="pd-summary-row"><span>Fiyat Çarpanı</span><strong>{{ number_format((float) ($product->price_multiplier ?? 1), 2, ',', '.') }}</strong></div>
            @if($product->has_local_stock_priority)
                <div class="pd-note mt-4">Local stok öncelikli. Satışta önce tenant local stoğu kullanılmalıdır.</div>
            @endif
        </div>
    </div>
</div>

<div class="pd-card mt-6">
    <div class="pd-card-header">
        <strong>Varyasyonlar</strong>
    </div>
    <div class="pd-card-body">
        @if($product->variants->isEmpty())
            <div class="pd-note">Bu ürün için henüz katalog varyasyonu oluşmadı.</div>
        @else
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Renk</th>
                            <th>Kod</th>
                            <th>Görsel</th>
                            <th>Galeri</th>
                            <th>Stok</th>
                            <th>Fiyat</th>
                            <th>Katalogda Görünür</th>
                            <th>Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($product->variants as $variant)
                            <tr>
                                <td>{{ $variant->variant_color ?? '-' }}</td>
                                <td>{{ $variant->variant_code ?? '-' }}</td>
                                <td>
                                    @if($variant->image_url)
                                    <img src="{{ $variant->image_url }}" alt="{{ $variant->display_name }}" class="catalog-product-thumb pd-allow-large">
                                    @else
                                        <span class="text-xs text-gray-500">Yok</span>
                                    @endif
                                </td>
                                <td>{{ $variant->images->count() > 0 ? $variant->images->count() . ' görsel' : '-' }}</td>
                                <td>{{ number_format((float) ($variant->stock_quantity ?? 0), 0, ',', '.') }}</td>
                                <td>{{ is_null($variant->display_price) ? '-' : number_format((float) $variant->display_price, 2, ',', '.') . ' ' . ($variant->currency ?? 'TL') }}</td>
                                <td>
                                    <span class="pd-badge pd-badge-{{ $variant->visible_in_catalog ? 'green' : 'gray' }}">
                                        {{ $variant->visible_in_catalog ? 'Evet' : 'Hayır' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="pd-badge pd-badge-{{ $variant->is_active ? 'green' : 'gray' }}">
                                        {{ $variant->is_active ? 'Aktif' : 'Pasif' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<div class="pd-card mt-6">
    <div class="pd-card-header">
        <strong>Uyarılar</strong>
    </div>
    <div class="pd-card-body">
        @if(!empty($product->warning_items))
            <div style="margin-bottom: 12px;">
                @foreach($product->warning_items as $warning)
                    <span class="pd-badge pd-badge-amber" style="margin-right:6px; margin-bottom:6px;">{{ $warning }}</span>
                @endforeach
            </div>
        @else
            <div class="pd-note">Bu ürün için aktif uyarı bulunmuyor.</div>
        @endif
    </div>
</div>
@endsection

@section('summary')
<div class="pd-card">
    <div class="pd-card-body">
        <div class="pd-summary-title">Ürün Özeti</div>
        <div class="pd-summary-row"><span>Katalog Durumu</span><strong>{{ $product->visible_in_catalog ? 'Görünür' : 'Gizli' }}</strong></div>
        <div class="pd-summary-row"><span>Kaynak</span><strong>{{ $product->catalog_source_label }}</strong></div>
        <div class="pd-summary-row"><span>Varyasyon</span><strong>{{ $product->variants->count() }}</strong></div>
        <div class="pd-summary-row"><span>Local Stok</span><strong>{{ number_format((float) ($product->local_stock_quantity ?? 0), 0, ',', '.') }}</strong></div>
        <div class="pd-summary-row"><span>Tedarikçi Stok</span><strong>{{ number_format((float) ($product->supplier_stock_quantity ?? 0), 0, ',', '.') }}</strong></div>
        <div class="pd-summary-row"><span>Teklif Kullanımı</span><strong>{{ $product->visible_in_quote ? 'İzinli' : 'Kapalı' }}</strong></div>
        <div class="pd-note mt-4">Tenant global XML kaynağını değil, yalnız kendi katalog görünürlüğünü ve local stoklarını yönetir.</div>
    </div>
</div>
@endsection
