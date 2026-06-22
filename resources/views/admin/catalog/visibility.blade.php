@extends('layouts.prodelya-admin')

@section('title', 'Katalog Görünürlüğü')
@section('page_title', 'Katalog Görünürlüğü')
@section('page_subtitle', 'Katalogda gösterim, teklifte kullanım ve local stok önceliğini tenant bazında toplu yönetin.')

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
                    <h1 class="pd-hero-title">Katalog Görünürlüğü</h1>
                    <p class="pd-hero-subtitle">Katalogda göster, teklifte kullan, öne çıkar ve local stok önceliğini ürün bazında veya toplu aksiyonlarla güncelle.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-light">Ana Kataloğa Dön</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler</h3>
                <p class="pd-section-subtitle">Kaynak, görünürlük, teklif ve uyarı durumuna göre daraltın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" action="{{ route('admin.catalog.visibility') }}" class="pd-form-grid-3">
                <div>
                    <label class="pd-label">Arama</label>
                    <input name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Ürün adı, kod veya kategori">
                </div>
                <div>
                    <label class="pd-label">Kaynak tipi</label>
                    <select name="source_type" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="local" @selected($filters['source_type'] === 'local')>Local</option>
                        <option value="supplier" @selected($filters['source_type'] === 'supplier')>Tedarikçi</option>
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
                    <label class="pd-label">Katalog</label>
                    <select name="visibility" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="visible" @selected($filters['visibility'] === 'visible')>Görünen</option>
                        <option value="hidden" @selected($filters['visibility'] === 'hidden')>Gizli</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Teklif</label>
                    <select name="quote_visibility" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="open" @selected($filters['quote_visibility'] === 'open')>Açık</option>
                        <option value="closed" @selected($filters['quote_visibility'] === 'closed')>Kapalı</option>
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
                    <a href="{{ route('admin.catalog.visibility') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>
    </section>

    <form action="{{ route('admin.catalog.visibility.bulk-update') }}" method="POST" class="pd-section-card">
        @csrf
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Toplu Görünürlük Operasyonları</h3>
                <p class="pd-section-subtitle">Seçilen ürünleri katalogda göster/gizle, teklif kullanımını aç/kapat veya satır bazlı ayarları tek seferde kaydedin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-hero-actions" style="margin-bottom: 16px; flex-wrap: wrap;">
                <button type="submit" name="action" value="show_selected" class="pd-btn pd-btn-success">Seçilenleri Katalogda Göster</button>
                <button type="submit" name="action" value="hide_selected" class="pd-btn pd-btn-warning">Seçilenleri Katalogdan Gizle</button>
                <button type="submit" name="action" value="enable_quote" class="pd-btn pd-btn-light">Seçilenleri Teklifte Aç</button>
                <button type="submit" name="action" value="disable_quote" class="pd-btn pd-btn-light">Seçilenleri Teklife Kapat</button>
                <button type="submit" name="action" value="hide_warnings" class="pd-btn pd-btn-warning">Uyarılı Ürünleri Gizle</button>
                <button type="submit" name="action" value="disable_missing_price" class="pd-btn pd-btn-warning">Fiyatı Eksikleri Teklife Kapat</button>
                <button type="submit" name="action" value="save_rows" class="pd-btn pd-btn-primary">Görünürlükleri Kaydet</button>
            </div>

            <div class="pd-hub-table-wrap">
                <table class="pd-table pd-package-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" onclick="document.querySelectorAll('.pd-select-product').forEach(el => el.checked = this.checked)"></th>
                            <th>Ürün</th>
                            <th>Kaynak</th>
                            <th>Katalog</th>
                            <th>Teklif</th>
                            <th>Öne Çıkan</th>
                            <th>Local Öncelik</th>
                            <th>Hidden Reason</th>
                            <th>Uyarı</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td><input type="checkbox" class="pd-select-product" name="selected_products[]" value="{{ $product->id }}"></td>
                                <td>
                                    <div class="font-medium">{{ $product->display_name }}</div>
                                    <div class="text-xs text-gray-500">{{ $product->display_code }}</div>
                                </td>
                                <td>
                                    <span class="pd-badge pd-badge-{{ $product->catalog_source === 'local_product' ? 'purple' : 'blue' }}">{{ $product->catalog_source_label }}</span>
                                </td>
                                <td><input type="checkbox" name="rows[{{ $product->id }}][visible_in_catalog]" value="1" @checked($product->visible_in_catalog)></td>
                                <td><input type="checkbox" name="rows[{{ $product->id }}][visible_in_quote]" value="1" @checked($product->visible_in_quote)></td>
                                <td><input type="checkbox" name="rows[{{ $product->id }}][is_featured]" value="1" @checked($product->is_featured)></td>
                                <td><input type="checkbox" name="rows[{{ $product->id }}][local_stock_priority]" value="1" @checked($product->local_stock_priority ?? true)></td>
                                <td>
                                    <input name="rows[{{ $product->id }}][hidden_reason]" class="pd-input" value="{{ $product->hidden_reason }}" placeholder="Gizleme nedeni">
                                </td>
                                <td>
                                    @forelse($product->warning_items as $warning)
                                        <div class="pd-badge pd-badge-amber" style="margin-bottom:4px;">{{ $warning }}</div>
                                    @empty
                                        <span class="pd-badge pd-badge-green">Temiz</span>
                                    @endforelse
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="pd-muted">Güncellenecek katalog ürünü bulunamadı.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('admin.catalog.partials.pagination', ['paginator' => $products, 'filters' => $filters])
        </div>
    </form>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Görünürlük Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Toplam ürün</span><strong>{{ $stats['total'] }}</strong></div>
            <div class="pd-status-row"><span>Katalogda görünen</span><strong>{{ $stats['visible'] }}</strong></div>
            <div class="pd-status-row"><span>Katalogda gizli</span><strong>{{ $stats['hidden'] }}</strong></div>
            <div class="pd-status-row"><span>Uyarılı</span><strong>{{ $stats['warning'] }}</strong></div>
        </div>
        <div class="pd-side-note">Local ve tedarikçi ürünleri aynı görünürlük ekranında yönetilir; ancak tenant yalnız kendi katalog kayıtlarını etkiler.</div>
    </div>
</div>
@endsection
