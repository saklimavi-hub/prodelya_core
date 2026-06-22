@extends('layouts.prodelya-admin')

@section('title', 'Product Data Hub Ürün Paneli')
@section('page_title', 'Product Data Hub > Ürün Paneli')
@section('page_subtitle', 'Günlük hızlı ürün kontrolü için sade tablo görünümü.')

@section('page_actions')
    <a href="{{ route('admin.super.product-data-hub.common-products') }}" class="pd-btn pd-btn-light">Ortak Ürün Havuzu</a>
    <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light">Standart Ürünler</a>
@endsection

@section('content')
    @php
        $badgeClass = static function (string $warning): string {
            return match ($warning) {
                'Kategori Bekliyor' => 'pd-badge-amber',
                'Fiyat Eksik', 'Stok Yok' => 'pd-badge-red',
                'Resim Eksik' => 'pd-badge-light',
                default => 'pd-badge-blue',
            };
        };
        $panelQuery = request()->except(['category_mapping_product_id']);
        $drawerProduct = $categoryMappingDrawer['product'] ?? null;
        $drawerMapping = $categoryMappingDrawer['mapping'] ?? null;
        $drawerSearchUrl = route('admin.super.product-data-hub.categories.search');
        $drawerFormAction = isset($categoryMappingDrawer['save_action'])
            ? $categoryMappingDrawer['save_action'] . (!empty($panelQuery) ? ('?' . http_build_query($panelQuery)) : '')
            : null;
    @endphp

    @if(session('product_panel_category_mapping_saved'))
        <div class="pd-card pd-section-card-soft-amber" style="margin-bottom:16px;">
            <div class="pd-card-body" style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
                <div>
                    <div style="font-weight:700;">Kategori eşlendi, ürün listesine yansıtma bekliyor.</div>
                    <div class="pd-card-subtitle">Ürün/projection kategori alanı ayrı adımda güncellenir.</div>
                </div>
                <span class="pd-badge pd-badge-amber">Yansıtma Bekliyor</span>
            </div>
        </div>
    @endif

    <div class="pd-card pd-form-card" style="margin-bottom:16px;">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Filtreler</h2>
                <p class="pd-card-subtitle">Ürün kodu, grup kodu, ad, kategori ve teknik JSON blob içinde arama yapılabilir.</p>
            </div>
        </div>
        <div class="pd-card-body">
            <form method="GET" action="{{ route('admin.super.product-data-hub.product-panel') }}" class="pd-form-grid-3">
                <div>
                    <label class="pd-label">Arama</label>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Kod, ad, grup, kategori">
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
                        <option value="clean" @selected($filters['warning_state'] === 'clean')>Temiz</option>
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
                    <a href="{{ route('admin.super.product-data-hub.product-panel') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <label class="pd-checkbox">
                        <input type="checkbox" name="technical_columns" value="1" @checked($filters['technical_columns'])>
                        Teknik kolonları göster
                    </label>
                </div>
            </form>
        </div>
    </div>

    <div class="pd-card pd-table-card">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Ürün Listesi</h2>
                <p class="pd-card-subtitle">Toplam {{ number_format($rows->total(), 0, ',', '.') }} satılabilir kayıt, sayfa başı {{ $rows->perPage() }} ürün.</p>
            </div>
            <div class="flex items-center" style="gap:8px;">
                <span class="pd-badge pd-badge-green">Satılabilir: {{ $stats['sellable'] }}</span>
                <span class="pd-badge pd-badge-amber">Uyarılı: {{ $stats['with_warning'] }}</span>
            </div>
        </div>
        <div class="pd-card-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" disabled></th>
                            <th>Resim</th>
                            <th>Tedarikçi</th>
                            <th>Ürün Kodu</th>
                            @if($filters['technical_columns'])
                                <th>Grup Kodu</th>
                            @endif
                            <th>Ürün Adı</th>
                            <th>Kategori</th>
                            <th>Eşleşen Kategori</th>
                            <th>Fiyat</th>
                            <th>Stok</th>
                            <th>Renk</th>
                            <th>Ebat / Ölçü</th>
                            <th>Uyarı</th>
                            <th>Durum</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td><input type="checkbox" disabled></td>
                                <td>
                                    @if($row['image_url'])
                                        <img src="{{ $row['image_url'] }}" alt="{{ $row['display_name'] }}" style="width:44px;height:44px;object-fit:cover;border-radius:8px;">
                                    @else
                                        <span class="pd-badge pd-badge-light">Yok</span>
                                    @endif
                                </td>
                                <td>{{ $row['supplier_name'] }}</td>
                                <td>{{ $row['display_code'] }}</td>
                                @if($filters['technical_columns'])
                                    <td>{{ $row['group_code'] ?: '-' }}</td>
                                @endif
                                <td style="max-width:340px;">
                                    <div style="font-weight:600;">{{ $row['display_name'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $row['row_type'] === 'variant' ? 'Satılabilir varyant' : 'Satılabilir ürün' }}</div>
                                </td>
                                <td>{{ $row['supplier_category_name'] }}</td>
                                <td>{{ $row['matched_category_name'] }}</td>
                                <td>
                                    @if($row['price'])
                                        {{ number_format((float) $row['price'], 2, ',', '.') }} {{ $row['currency'] }}
                                    @else
                                        <span class="pd-badge pd-badge-red">Eksik</span>
                                    @endif
                                </td>
                                <td>{{ number_format($row['stock_quantity'], 0, ',', '.') }}</td>
                                <td>{{ $row['color'] ?: '-' }}</td>
                                <td>{{ $row['size'] ?: $row['measure'] ?: '-' }}</td>
                                <td>
                                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                        @if($row['warnings'] === [])
                                            <span class="pd-badge pd-badge-green">Temiz</span>
                                        @else
                                            @foreach($row['warnings'] as $warning)
                                                <span class="pd-badge {{ $badgeClass($warning) }}">{{ $warning }}</span>
                                            @endforeach
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span class="pd-badge {{ $row['status_label'] === 'Aktif' ? 'pd-badge-green' : 'pd-badge-light' }}">{{ $row['status_label'] }}</span>
                                </td>
                                <td>
                                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                        <a href="{{ $row['detail_link'] }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                        @if($row['category_action_required'])
                                            <a href="{{ route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['category_mapping_product_id' => $row['standard_product_id']])) }}" class="pd-btn pd-btn-sm pd-btn-primary">Kategori Eşle</a>
                                        @endif
                                        <a href="{{ $row['standard_link'] }}" class="pd-btn pd-btn-sm pd-btn-light">Önizle</a>
                                        <a href="{{ $row['detail_link'] }}" class="pd-btn pd-btn-sm pd-btn-warning">Teknik Detay</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $filters['technical_columns'] ? 15 : 14 }}">Filtrelere uygun ürün bulunamadı.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top:16px;">
                {{ $rows->links() }}
            </div>
        </div>
    </div>

    @if($drawerProduct && $drawerMapping)
        <div style="position:fixed;inset:0;background:rgba(15,23,42,.32);z-index:80;">
            <a href="{{ $categoryMappingDrawer['cancel_link'] }}" aria-label="Kapat" style="position:absolute;inset:0;display:block;"></a>
            <div style="position:absolute;top:0;right:0;width:min(560px,100%);height:100%;background:#fff;box-shadow:-16px 0 40px rgba(15,23,42,.18);overflow:auto;padding:24px;z-index:81;">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:18px;">
                    <div>
                        <div class="pd-badge pd-badge-blue" style="margin-bottom:8px;">Kategori Eşle</div>
                        <h2 class="pd-card-title" style="margin-bottom:6px;">Kategori Eşle</h2>
                        <p class="pd-card-subtitle">Tedarikçi kategorisini Prodelya standart kategorisine bağlayın.</p>
                    </div>
                    <a href="{{ $categoryMappingDrawer['cancel_link'] }}" class="pd-btn pd-btn-light pd-btn-sm">Vazgeç</a>
                </div>

                <div class="pd-card" style="margin-bottom:14px;">
                    <div class="pd-card-header">
                        <div>
                            <h3 class="pd-card-title">Ürün Bilgisi</h3>
                        </div>
                    </div>
                    <div class="pd-card-body" style="display:grid;grid-template-columns:72px 1fr;gap:14px;">
                        <div>
                            @if($drawerProduct['image_url'])
                                <img src="{{ $drawerProduct['image_url'] }}" alt="{{ $drawerProduct['display_name'] }}" style="width:72px;height:72px;object-fit:cover;border-radius:12px;">
                            @else
                                <div style="width:72px;height:72px;border-radius:12px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;">
                                    <span class="pd-badge pd-badge-light">Görsel Yok</span>
                                </div>
                            @endif
                        </div>
                        <div style="display:grid;gap:8px;">
                            <div>
                                <div class="text-xs text-gray-500">Ürün Kodu</div>
                                <div style="font-weight:700;">{{ $drawerProduct['display_code'] }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Ürün Adı</div>
                                <div style="font-weight:600;">{{ $drawerProduct['display_name'] }}</div>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                <span class="pd-badge pd-badge-light">{{ $drawerProduct['supplier_name'] }}</span>
                                <span class="pd-badge pd-badge-blue">Stok: {{ number_format($drawerProduct['stock_quantity'], 0, ',', '.') }}</span>
                                <span class="pd-badge pd-badge-green">
                                    @if($drawerProduct['price'])
                                        Fiyat: {{ number_format((float) $drawerProduct['price'], 2, ',', '.') }} {{ $drawerProduct['currency'] }}
                                    @else
                                        Fiyat Eksik
                                    @endif
                                </span>
                                @if(!empty($drawerProduct['warnings']))
                                    <span class="pd-badge pd-badge-amber">{{ $drawerProduct['warnings'][0] }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pd-card" style="margin-bottom:14px;">
                    <div class="pd-card-header">
                        <div>
                            <h3 class="pd-card-title">Tedarikçi Kategorisi</h3>
                        </div>
                    </div>
                    <div class="pd-card-body" style="display:grid;gap:10px;">
                        <div>
                            <div class="text-xs text-gray-500">Kategori Adı</div>
                            <div style="font-weight:700;">{{ $drawerMapping->source_category }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">Kategori Yolu</div>
                            <div>{{ $drawerMapping->supplier_category_path ?: '-' }}</div>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                            <span class="pd-badge pd-badge-light">Ürün Sayısı: {{ number_format((int) $drawerMapping->product_count, 0, ',', '.') }}</span>
                            @foreach(($categoryMappingDrawer['sample_products'] ?? []) as $sampleProduct)
                                <span class="pd-badge pd-badge-blue">{{ $sampleProduct }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="pd-card" style="margin-bottom:14px;">
                    <div class="pd-card-header">
                        <div>
                            <h3 class="pd-card-title">Sistem Önerisi</h3>
                        </div>
                    </div>
                    <div class="pd-card-body" style="display:grid;gap:10px;">
                        <div>
                            <div class="text-xs text-gray-500">Önerilen Hedef Kategori</div>
                            <div style="font-weight:700;">{{ $categoryMappingDrawer['suggestion_path'] ?: 'Henüz öneri yok' }}</div>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            <span class="pd-badge pd-badge-green">Güven Skoru: {{ $categoryMappingDrawer['confidence_label'] }}</span>
                        </div>
                        <div class="pd-card-subtitle">{{ $categoryMappingDrawer['suggestion_reason'] }}</div>
                    </div>
                </div>

                <div class="pd-card">
                    <div class="pd-card-header">
                        <div>
                            <h3 class="pd-card-title">Hedef Kategori</h3>
                        </div>
                    </div>
                    <div class="pd-card-body">
                        <form method="POST" action="{{ $drawerFormAction }}" id="product-panel-category-mapping-form">
                            @csrf
                            <div style="display:grid;gap:12px;">
                                <div>
                                    <label class="pd-label" for="quick-category-search">Hızlı kategori arama</label>
                                    <input type="text" id="quick-category-search" class="pd-input" placeholder="Kategori adı, kodu veya yolunu ara" autocomplete="off">
                                </div>
                                <div id="quick-category-results" style="display:grid;gap:8px;"></div>
                                <div>
                                    <label class="pd-label" for="quick-standard-category-id">Seçilen kategori</label>
                                    <input type="hidden" name="standard_category_id" id="quick-standard-category-id" value="{{ old('standard_category_id', $drawerMapping->standard_category_id) }}">
                                    <div id="quick-selected-category" class="pd-input" style="min-height:44px;display:flex;align-items:center;">
                                        {{ old('standard_category_id') ? 'Kategori seçildi' : ($categoryMappingDrawer['suggestion_path'] ?: 'Henüz kategori seçilmedi') }}
                                    </div>
                                </div>
                                <div>
                                    <label class="pd-label">Karar Tipi</label>
                                    <div class="pd-input" style="display:flex;align-items:center;">Eşle</div>
                                </div>
                                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                                    <button type="submit" class="pd-btn pd-btn-primary">Eşle ve Kaydet</button>
                                    <a href="{{ $categoryMappingDrawer['cancel_link'] }}" class="pd-btn pd-btn-light">Vazgeç</a>
                                    <a href="{{ $categoryMappingDrawer['advanced_link'] }}" class="pd-btn pd-btn-light">Gelişmiş Eşleme Ekranında Aç</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (function () {
                const input = document.getElementById('quick-category-search');
                const results = document.getElementById('quick-category-results');
                const selectedInput = document.getElementById('quick-standard-category-id');
                const selectedLabel = document.getElementById('quick-selected-category');
                const searchUrl = @json($drawerSearchUrl);
                const initialCategoryId = @json(old('standard_category_id', $drawerMapping->standard_category_id));
                const initialCategoryPath = @json($categoryMappingDrawer['suggestion_path']);

                if (!input || !results || !selectedInput || !selectedLabel) {
                    return;
                }

                if (initialCategoryId && initialCategoryPath) {
                    selectedInput.value = initialCategoryId;
                    selectedLabel.textContent = initialCategoryPath;
                }

                let timer = null;

                const renderResults = function (items) {
                    if (!items.length) {
                        results.innerHTML = '<div class="pd-card-subtitle">Sonuç bulunamadı.</div>';
                        return;
                    }

                    results.innerHTML = items.map(function (item) {
                        const path = item.path || item.name;
                        return '<button type="button" class="pd-btn pd-btn-light pd-btn-sm quick-category-option" data-id="' + item.id + '" data-path="' + String(path).replace(/"/g, '&quot;') + '" style="justify-content:flex-start;text-align:left;">' + path + '</button>';
                    }).join('');

                    results.querySelectorAll('.quick-category-option').forEach(function (button) {
                        button.addEventListener('click', function () {
                            selectedInput.value = this.getAttribute('data-id') || '';
                            selectedLabel.textContent = this.getAttribute('data-path') || 'Henüz kategori seçilmedi';
                        });
                    });
                };

                input.addEventListener('input', function () {
                    clearTimeout(timer);
                    const term = this.value.trim();

                    if (term.length < 2) {
                        results.innerHTML = '';
                        return;
                    }

                    timer = window.setTimeout(function () {
                        fetch(searchUrl + '?q=' + encodeURIComponent(term), {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json'
                            }
                        })
                            .then(function (response) { return response.json(); })
                            .then(function (items) { renderResults(Array.isArray(items) ? items : []); })
                            .catch(function () {
                                results.innerHTML = '<div class="pd-card-subtitle">Kategori araması şu anda açılamadı.</div>';
                            });
                    }, 220);
                });
            }());
        </script>
    @endif
@endsection
