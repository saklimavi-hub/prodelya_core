@extends('layouts.prodelya-admin')

@section('title', 'Ham Ürünler')

@section('content')
<div class="pd-page-header">
    <div>
        <h1 class="pd-section-title">Ham Ürünler</h1>
        <p class="pd-muted mt-1">Preview aşamasından staging havuzuna aktarılan ham ürün kayıtları.</p>
    </div>
    <div class="pd-actions">
        <a href="{{ route('admin.product-data-hub.sources') }}" class="pd-btn pd-btn-light">Tedarikçi Kaynakları</a>
    </div>
</div>

@if($products->isNotEmpty())
    <div class="pd-card mb-6">
        <div class="pd-card-header">
            <strong>Kaynak Bazlı Toplu Dönüşüm</strong>
        </div>
        <div class="pd-card-body">
            <div class="pd-actions">
                @foreach($products->groupBy('supplier_source_id') as $sourceId => $sourceProducts)
                    @php($source = $sourceProducts->first()->source)
                    @if($source)
                        <form action="{{ route('admin.product-data-hub.sources.build-standard-products', $source) }}" method="POST">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-primary">
                                {{ $source->source_name }} kaynağını standart ürünlere dönüştür
                            </button>
                        </form>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
@endif

<div class="pd-card">
    <div class="pd-card-body">
        @if($products->isEmpty())
            <div class="pd-note">
                Henüz ham ürün staging kaydı yok. Önce bir tedarikçi kaynağında Preview → Staging’e Aktar yapın.
            </div>
        @else
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Tedarikçi</th>
                            <th>Kaynak</th>
                            <th>Generated Code</th>
                            <th>Supplier Code</th>
                            <th>Group Code</th>
                            <th>Ürün Adı</th>
                            <th>Kategori</th>
                            <th>Fiyat</th>
                            <th>Para Birimi</th>
                            <th>Standart Ürün</th>
                            <th>Varyasyon</th>
                            <th>Dönüşüm Durumu</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $product)
                            <tr>
                                <td>{{ $product->supplier?->name ?? '-' }}</td>
                                <td>{{ $product->source?->source_name ?? '-' }}</td>
                                <td><strong>{{ $product->normalized_payload['generated_product_code'] ?? $product->supplier_product_code ?? '-' }}</strong></td>
                                <td>{{ $product->supplier_product_code ?? $product->source_sku ?? '-' }}</td>
                                <td>{{ $product->supplier_group_code ?? '-' }}</td>
                                <td>{{ $product->product_name ?? $product->source_name ?? '-' }}</td>
                                <td>{{ $product->supplier_category_name ?? $product->source_category ?? '-' }}</td>
                                <td>{{ is_null($product->purchase_price) && is_null($product->source_price) ? '-' : number_format((float) ($product->purchase_price ?? $product->source_price), 2, ',', '.') }}</td>
                                <td>{{ $product->currency ?? $product->source_currency ?? '-' }}</td>
                                <td>
                                    @if($product->standardProduct)
                                        <div class="pd-badge pd-badge-green">Dönüştürüldü</div>
                                        <div class="text-xs mt-1">{{ $product->standardProduct->standard_product_code }}</div>
                                    @else
                                        <span class="pd-note">-</span>
                                    @endif
                                </td>
                                <td>{{ $product->variants_count }}</td>
                                <td>
                                    @php
                                        $statusColor = match ($product->sync_status) {
                                            'processed' => 'green',
                                            'staged' => 'amber',
                                            'pending' => 'amber',
                                            'error' => 'red',
                                            default => 'gray',
                                        };
                                    @endphp
                                    <span class="pd-badge pd-badge-{{ $statusColor }}">{{ $product->getSyncStatusDisplayName() }}</span>
                                </td>
                                <td>
                                    <div class="pd-actions">
                                        @if($product->standard_product_id)
                                            <span class="pd-badge pd-badge-green">Dönüştürüldü</span>
                                        @else
                                            <form action="{{ route('admin.product-data-hub.raw-products.build-standard', $product) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="pd-btn pd-btn-sm pd-btn-primary">Standart Ürüne Dönüştür</button>
                                            </form>
                                        @endif
                                    </div>
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
        <div class="pd-summary-title">Ham Ürün Özeti</div>
        <div class="pd-summary-row"><span>Toplam Ürün</span><strong>{{ $products->count() }}</strong></div>
        <div class="pd-summary-row"><span>Ham Havuz</span><strong>{{ $products->where('sync_status', 'staged')->count() }}</strong></div>
        <div class="pd-summary-row"><span>Dönüştürülen</span><strong>{{ $products->whereNotNull('standard_product_id')->count() }}</strong></div>
        <div class="pd-summary-row"><span>Varyasyon</span><strong>{{ $products->sum('variants_count') }}</strong></div>
    </div>
</div>
@endsection
