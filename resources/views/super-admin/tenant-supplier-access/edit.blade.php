@extends('layouts.prodelya-admin')

@section('title', $tenant->name . ' Erişim Ayarları')
@section('page_title', $tenant->name . ' Erişim Ayarları')
@section('page_subtitle', 'Tenant modülleri, feed limitleri ve tedarikçi bazlı Product Data Hub erişim izinleri.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
</div>
@endsection

@section('content')
<form method="POST" action="{{ route('admin.super.tenant-supplier-access.update', $tenant) }}">
    @csrf
    @method('PUT')

    <div class="pd-grid" style="grid-template-columns: minmax(0, 1fr); margin-bottom: 14px;">
        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Tenant Bilgisi</h3>
                <p class="pd-card-subtitle">Temel tenant özeti ve aktif modüller.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-3">
                    <div><div class="text-sm text-gray-600">Tenant</div><div class="font-medium">{{ $tenant->name }}</div></div>
                    <div><div class="text-sm text-gray-600">Slug</div><div class="font-medium">{{ $tenant->slug }}</div></div>
                    <div><div class="text-sm text-gray-600">Paket</div><div class="font-medium">{{ $tenant->package_key ?: '-' }}</div></div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Modül Ayarları</h3>
                <p class="pd-card-subtitle">Tenant bazlı Product Data Hub özelliklerini açıp kapatın.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-2">
                    <label class="pd-source-cell"><div class="flex justify-between items-center"><span>Product Data Hub</span><input type="checkbox" name="modules[product_data_hub][enabled]" value="1" @checked($moduleStates['product_data_hub']['enabled']) style="width:auto;"></div></label>
                    <label class="pd-source-cell"><div class="flex justify-between items-center"><span>Advanced Catalog</span><input type="checkbox" name="modules[advanced_catalog][enabled]" value="1" @checked($moduleStates['advanced_catalog']['enabled']) style="width:auto;"></div></label>
                    <label class="pd-source-cell"><div class="flex justify-between items-center"><span>Supplier Feed</span><input type="checkbox" name="modules[supplier_feed][enabled]" value="1" @checked($moduleStates['supplier_feed']['enabled']) style="width:auto;"></div></label>
                    <label class="pd-source-cell"><div class="flex justify-between items-center"><span>Export / Web Feed</span><input type="checkbox" name="modules[export_web_feed][enabled]" value="1" @checked($moduleStates['export_web_feed']['enabled']) style="width:auto;"></div></label>
                </div>

                <div style="margin-top: 14px; max-width: 220px;">
                    <label class="text-sm font-medium">Feed Limit</label>
                    <input type="number" name="modules[supplier_feed][limit_value]" min="0" value="{{ old('modules.supplier_feed.limit_value', $moduleStates['supplier_feed']['limit_value']) }}">
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Tedarikçi Erişim Tablosu</h3>
                <p class="pd-card-subtitle">Katalog görünürlüğü, teklifte kullanılabilirlik, satın alma ve fiyat/stok ayarları.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr>
                                <th>Tedarikçi</th>
                                <th>Aktif</th>
                                <th>Katalog</th>
                                <th>Ürün Gör</th>
                                <th>Teklif</th>
                                <th>Satın Alma</th>
                                <th>Export</th>
                                <th>Fiyat Çarpanı</th>
                                <th>Güvenli Stok</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($suppliers as $supplier)
                            @php $access = $accessBySupplier->get($supplier->id); @endphp
                            <tr>
                                <td><div class="font-medium">{{ $supplier->name }}</div><div class="text-sm text-gray-600">{{ $supplier->code }}</div></td>
                                <td><input type="checkbox" name="supplier_access[{{ $supplier->id }}][is_enabled]" value="1" @checked(old("supplier_access.$supplier->id.is_enabled", $access?->is_active)) style="width:auto;"></td>
                                <td><input type="checkbox" name="supplier_access[{{ $supplier->id }}][visible_in_catalog]" value="1" @checked(old("supplier_access.$supplier->id.visible_in_catalog", $access?->visible_in_catalog ?? true)) style="width:auto;"></td>
                                <td><input type="checkbox" name="supplier_access[{{ $supplier->id }}][can_view_products]" value="1" @checked(old("supplier_access.$supplier->id.can_view_products", $access?->can_view_products ?? true)) style="width:auto;"></td>
                                <td><input type="checkbox" name="supplier_access[{{ $supplier->id }}][can_use_in_quotes]" value="1" @checked(old("supplier_access.$supplier->id.can_use_in_quotes", $access?->can_use_in_quotes ?? true)) style="width:auto;"></td>
                                <td><input type="checkbox" name="supplier_access[{{ $supplier->id }}][can_request_purchase]" value="1" @checked(old("supplier_access.$supplier->id.can_request_purchase", $access?->can_request_purchase ?? true)) style="width:auto;"></td>
                                <td><input type="checkbox" name="supplier_access[{{ $supplier->id }}][export_allowed]" value="1" @checked(old("supplier_access.$supplier->id.export_allowed", $access?->export_allowed ?? false)) style="width:auto;"></td>
                                <td><input type="number" step="0.01" min="0" name="supplier_access[{{ $supplier->id }}][price_multiplier]" value="{{ old("supplier_access.$supplier->id.price_multiplier", $access?->price_multiplier) }}"></td>
                                <td><input type="number" min="0" name="supplier_access[{{ $supplier->id }}][safe_stock_quantity]" value="{{ old("supplier_access.$supplier->id.safe_stock_quantity", $access?->safe_stock_quantity) }}"></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
</form>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Düzenleme Notları</h3>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">TODO</h4>
            <div class="pd-summary-list">
                <span class="pd-summary-item">`manage_product_data_hub` permission middleware</span>
                <span class="pd-summary-item">`manage_tenant_supplier_access` permission middleware</span>
                <span class="pd-summary-item">`view_advanced_catalog` gate entegrasyonu</span>
            </div>
        </div>

        <div class="pd-note">Bu ekran gerçek XML parsing değildir, tenant bazlı global kaynak erişim altyapısıdır.</div>
    </div>
</div>
@endsection
