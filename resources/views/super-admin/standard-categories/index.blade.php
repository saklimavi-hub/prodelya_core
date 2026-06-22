@extends('layouts.prodelya-admin')

@section('title', 'Standart Kategori Ağacı')
@section('page_title', 'Standart Kategori Ağacı')
@section('page_subtitle', 'Global temiz kategori ağacını, parent/child yapıyı, görünürlük ve aktiflik durumlarını yönetin.')

@section('page_actions')
<div class="pd-actions-wrap">
    <a href="{{ route('admin.super.standard-categories.create') }}" class="pd-btn pd-btn-primary">Yeni Kategori</a>
    <a href="{{ route('admin.super.standard-categories.bulk-paste') }}" class="pd-btn pd-btn-light">Toplu Kopyala/Yapıştır</a>
    <a href="{{ route('admin.super.standard-categories.import') }}" class="pd-btn pd-btn-light">CSV/Excel Import</a>
    <a href="{{ route('admin.super.standard-categories.template') }}" class="pd-btn pd-btn-light">Şablon İndir</a>
</div>
@endsection

@section('content')
@php
    $bulkActionRoute = route('admin.super.standard-categories.bulk-action');
    $updateOrderRoute = route('admin.super.standard-categories.update-order');
    $cleanupUnusedRoute = route('admin.super.standard-categories.cleanup-unused');
    $selectedAttributeCategory = $selectedAttributeCategory ?? null;
    $selectedAttributeRules = $selectedAttributeRules ?? collect();
@endphp

<div class="pd-hub-family-shell">
<nav class="pd-section-nav mb-6">
    <a href="#genel-bakis" class="pd-template-chip">Genel Bakış</a>
    <a href="#kategori-agaci" class="pd-template-chip">Kategori Ağacı</a>
    <a href="#toplu-ice-aktarim" class="pd-template-chip">Toplu İçe Aktarım</a>
    <a href="#eslesmemis-kontrol" class="pd-template-chip">Eşlenmemiş Kontrol</a>
    <a href="#kullanim-notlari" class="pd-template-chip">Kullanım Notları</a>
    <a href="{{ route('admin.super.product-data-hub.category-feature-templates.index') }}" class="pd-template-chip">Özellik Şablonları</a>
</nav>

<div id="genel-bakis" class="pd-grid" style="grid-template-columns: repeat(6, minmax(0, 1fr)); margin-bottom: 24px;">
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-blue">{{ $stats['total'] }}</div>
        <div>
            <div class="pd-profile-metric-label">Toplam Kategori</div>
            <div class="pd-profile-metric-value">{{ $stats['total'] }}</div>
        </div>
    </div>
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-green">{{ $stats['active'] }}</div>
        <div>
            <div class="pd-profile-metric-label">Aktif Kategori</div>
            <div class="pd-profile-metric-value">{{ $stats['active'] }}</div>
        </div>
    </div>
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-purple">{{ $stats['visible'] }}</div>
        <div>
            <div class="pd-profile-metric-label">Katalogda Görünen</div>
            <div class="pd-profile-metric-value">{{ $stats['visible'] }}</div>
        </div>
    </div>
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-amber">{{ $stats['pending_mappings'] }}</div>
        <div>
            <div class="pd-profile-metric-label">Eşleme Bekleyen</div>
            <div class="pd-profile-metric-value">{{ $stats['pending_mappings'] }}</div>
        </div>
    </div>
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-blue">{{ $stats['promotion'] }}</div>
        <div>
            <div class="pd-profile-metric-label">Promosyon</div>
            <div class="pd-profile-metric-value">{{ $stats['promotion'] }}</div>
        </div>
    </div>
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-purple">{{ $stats['print'] }}</div>
        <div>
            <div class="pd-profile-metric-label">Matbaa</div>
            <div class="pd-profile-metric-value">{{ $stats['print'] }}</div>
        </div>
    </div>
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-amber">{{ $stats['archived'] }}</div>
        <div>
            <div class="pd-profile-metric-label">Arşiv Kategori</div>
            <div class="pd-profile-metric-value">{{ $stats['archived'] }}</div>
        </div>
    </div>
</div>

<section id="kategori-agaci" class="pd-card pd-section-card mb-6">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Kategori Ağacı</h3>
        <p class="pd-card-subtitle">Global hedef kategori ağacını filtreleyin, toplu işlem uygulayın ve aynı parent altındaki sıraları güvenli şekilde güncelleyin.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-3 mb-5">
            <form method="GET" class="pd-grid pd-grid-3" style="grid-column: 1 / -1;">
                <div>
                    <label class="pd-label">Ürün Ailesi</label>
                    <select name="product_family" class="pd-select">
                        <option value="">Tüm Aileler</option>
                        <option value="promotion" @selected(($filters['product_family'] ?? '') === 'promotion')>Promosyon</option>
                        <option value="print" @selected(($filters['product_family'] ?? '') === 'print')>Matbaa</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Durum</label>
                    <select name="status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Aktif</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Pasif</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Arşiv Durumu</label>
                    <select name="archive_status" class="pd-select">
                        <option value="active" @selected(($filters['archive_status'] ?? 'active') === 'active')>Aktif Kategoriler</option>
                        <option value="archived" @selected(($filters['archive_status'] ?? '') === 'archived')>Arşiv Kategoriler</option>
                        <option value="all" @selected(($filters['archive_status'] ?? '') === 'all')>Tümü</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Arama</label>
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" class="pd-input" placeholder="Kod veya kategori ara">
                </div>
                <div class="flex gap-2 flex-wrap" style="grid-column: 1 / -1;">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>

        <div class="pd-note mb-4">
            Prodelya’da kategori ağacı günlük kullanımda en fazla 3 seviye önerilir. Renk, ölçü, hacim, malzeme, baskı türü gibi detaylar kategori yerine filtre/özellik olarak yönetilmelidir.
        </div>
        @if(($filters['archive_status'] ?? 'active') === 'archived')
            <div class="pd-alert pd-alert-warning mb-4">
                Bu kategori eski kategori ağacından arşivlenmiştir. Yeni eşlemelerde kullanılmaz.
            </div>
        @endif

        <form id="pdStandardCategoryBulkForm" method="POST" action="{{ $bulkActionRoute }}" class="pd-card" style="margin-bottom: 14px;">
            @csrf
            <div class="pd-card-body">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div><strong>Seçilen:</strong> <span id="pdSelectedCategoryCount">0</span> kategori</div>
                    <div class="flex gap-2 flex-wrap items-center">
                        <select name="bulk_action" class="pd-select" style="width: 220px;">
                            <option value="deactivate">Pasife Al</option>
                            <option value="activate">Aktif Et</option>
                            <option value="hide_catalog">Katalogdan Gizle</option>
                            <option value="show_catalog">Katalogda Göster</option>
                            <option value="safe_delete">Güvenli Sil</option>
                        </select>
                        <button type="submit" class="pd-btn pd-btn-primary">Uygula</button>
                        <button type="button" class="pd-btn pd-btn-light" onclick="window.pdClearCategorySelection && window.pdClearCategorySelection()">Seçimi Temizle</button>
                    </div>
                </div>
            </div>
        </form>

        <form id="pdStandardCategoryOrderForm" method="POST" action="{{ $updateOrderRoute }}">
            @csrf
        </form>

        <div class="flex gap-2 flex-wrap mb-3">
            <button type="submit" form="pdStandardCategoryOrderForm" class="pd-btn pd-btn-primary">Sıralamayı Kaydet</button>
            <form method="POST" action="{{ $cleanupUnusedRoute }}">
                @csrf
                <button type="submit" class="pd-btn pd-btn-light" onclick="return confirm('Bağlantısız pasif kategorileri temizlemek istediğinize emin misiniz?')">Bağlantısız Pasifleri Temizle</button>
            </form>
        </div>

        <div class="pd-tree-wrap">
            <table class="pd-table pd-tree-table">
                <thead>
                    <tr>
                        <th style="width: 42px;">
                            <input type="checkbox" id="pdSelectAllCategories" style="width: auto;">
                        </th>
                        <th>Kategori</th>
                        <th>Kod</th>
                        <th>Aile</th>
                        <th>Bağlantılar</th>
                        <th>Sıra</th>
                        <th>Katalog</th>
                        <th>Durum</th>
                        <th>Uyarı</th>
                        <th>Aksiyonlar</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($categories as $category)
                        <tr class="pd-tree-row">
                            <td>
                                <input type="checkbox" name="category_ids[]" value="{{ $category->id }}" form="pdStandardCategoryBulkForm" class="pd-category-check" style="width: auto;">
                            </td>
                            <td>
                                <div class="pd-category-cell" style="padding-left: {{ $category->depth * 18 }}px;">
                                    @if($category->depth > 0)
                                        <span class="text-gray-400">—</span>
                                    @endif
                                    <div>
                                        <div class="font-medium">{{ $category->name }}</div>
                                        <div class="pd-profile-note">
                                            Seviye {{ $category->depth }} · {{ $category->parent?->name ?: 'Kök kategori' }}
                                        </div>
                                    </div>
                                </div>
                                @if($category->description)
                                    <div class="pd-profile-note" style="padding-left: {{ $category->depth * 18 }}px;">{{ $category->description }}</div>
                                @endif
                            </td>
                            <td><span class="pd-category-code">{{ $category->code }}</span></td>
                            <td>{{ $category->product_family === 'promotion' ? 'Promosyon' : 'Matbaa' }}</td>
                            <td>
                                <div class="pd-profile-note">Alt: {{ $category->children_count ?? 0 }}</div>
                                <div class="pd-profile-note">Eşleme: {{ $category->supplier_category_mappings_count ?? 0 }}</div>
                                <div class="pd-profile-note">Ürün: {{ $category->standard_products_count ?? 0 }}</div>
                                <div class="pd-profile-note">Kural: {{ $category->attribute_rules_count ?? 0 }}</div>
                            </td>
                            <td>
                                <input type="number" name="orders[{{ $category->id }}]" value="{{ $category->sort_order }}" form="pdStandardCategoryOrderForm" class="pd-input" style="min-width: 84px;">
                            </td>
                            <td>
                                <span class="pd-badge {{ $category->visible_in_catalog ? 'pd-badge-green' : 'pd-badge-gray' }}">
                                    {{ $category->visible_in_catalog ? 'Görünür' : 'Gizli' }}
                                </span>
                            </td>
                            <td>
                                <span class="pd-badge {{ $category->is_active ? 'pd-badge-green' : 'pd-badge-gray' }}">
                                    {{ $category->is_active ? 'Aktif' : 'Pasif' }}
                                </span>
                            </td>
                            <td>
                                @if(!empty($category->duplicate_warnings))
                                    <div class="flex gap-1 flex-wrap">
                                        @foreach(array_slice($category->duplicate_warnings, 0, 2) as $warning)
                                            <span class="pd-badge pd-badge-amber">{{ $warning }}</span>
                                        @endforeach
                                    </div>
                                @elseif(($category->depth ?? 0) >= 3)
                                    <span class="pd-badge pd-badge-purple">Seviye Kontrol</span>
                                @elseif(!($category->requires_mapping ?? true))
                                    <span class="pd-badge pd-badge-gray">Eşleme Opsiyonel</span>
                                @else
                                    <span class="pd-badge pd-badge-green">Temiz</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2 flex-wrap">
                                    @if($category->isArchivedCategory())
                                        <a href="{{ route('admin.super.standard-categories.edit', $category) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                        <span class="pd-badge pd-badge-amber">Arşiv</span>
                                        <span class="pd-profile-note">Geri yükleme bakım akışı gerektirir.</span>
                                    @else
                                        <a href="{{ route('admin.super.standard-categories.edit', $category) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
                                        <a href="{{ route('admin.super.standard-categories.create', ['parent_id' => $category->id, 'product_family' => $category->product_family]) }}" class="pd-btn pd-btn-sm pd-btn-light">Alt Ekle</a>
                                        <a href="{{ route('admin.super.standard-categories.attributes', $category) }}" class="pd-btn pd-btn-sm pd-btn-light">Özellikler</a>
                                        <details class="pd-inline-details">
                                            <summary class="pd-btn pd-btn-sm pd-btn-light">Taşı</summary>
                                            <form method="POST" action="{{ route('admin.super.standard-categories.move', $category) }}" class="pd-move-form">
                                                @csrf
                                                <div class="pd-profile-note"><strong>Eski yol:</strong> {{ $category->full_path }}</div>
                                                <div>
                                                    <label class="pd-label">Yeni Parent</label>
                                                    <select name="new_parent_id" class="pd-select">
                                                        <option value="">Kök kategori yap</option>
                                                        @foreach($moveParentOptions as $parentOption)
                                                            <option value="{{ $parentOption->id }}"
                                                                data-path="{{ $parentOption->full_path }}"
                                                                data-depth="{{ $parentOption->depth }}"
                                                                @selected($category->parent_id === $parentOption->id)>
                                                                {{ $parentOption->full_path }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="pd-move-grid">
                                                    <div>
                                                        <label class="pd-label">Yeni sıra</label>
                                                        <input type="number" name="new_sort_order" value="{{ $category->sort_order }}" class="pd-input">
                                                    </div>
                                                    <div>
                                                        <label class="pd-label">Ürün ailesi</label>
                                                        <select name="product_family" class="pd-select">
                                                            <option value="promotion" @selected($category->product_family === 'promotion')>Promosyon</option>
                                                            <option value="print" @selected($category->product_family === 'print')>Matbaa</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div>
                                                    <label class="pd-label">Not</label>
                                                    <input type="text" name="notes" class="pd-input" placeholder="Taşıma notu">
                                                </div>
                                                <label class="pd-move-check">
                                                    <input type="checkbox" name="confirm_deep_move" value="1">
                                                    <span>4. seviye ve üstü taşıma uyarısını anladım, yine de taşı.</span>
                                                </label>
                                                <div class="pd-move-preview">
                                                    <strong>Yeni yol:</strong>
                                                    <span class="pd-move-preview-text">{{ $category->full_path }}</span>
                                                </div>
                                                <button type="submit" class="pd-btn pd-btn-primary pd-btn-sm">Parent Değiştir</button>
                                            </form>
                                        </details>
                                        <form method="POST" action="{{ route('admin.super.standard-categories.toggle-active', $category) }}">
                                            @csrf
                                            <button type="submit" class="pd-btn pd-btn-sm {{ $category->is_active ? 'pd-btn-warning' : 'pd-btn-success' }}" onclick="return confirm('{{ $category->is_active ? 'Bu kategoriyi pasife almak istediğinize emin misiniz?' : 'Bu kategoriyi aktif etmek istediğinize emin misiniz?' }}')">
                                                {{ $category->is_active ? 'Pasif Yap' : 'Aktif Yap' }}
                                            </button>
                                        </form>
                                        @if(!$category->isPermanentBackbone())
                                            <form method="POST" action="{{ route('admin.super.standard-categories.destroy', $category) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="pd-btn pd-btn-sm pd-btn-danger" onclick="return confirm('Bu kategoriyi silmek istediğinize emin misiniz?')">Sil</button>
                                            </form>
                                        @else
                                            <span class="pd-badge pd-badge-blue">Kalıcı omurga</span>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center">Standart kategori bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

<section id="toplu-ice-aktarim" class="pd-card pd-section-card mb-6">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Toplu İçe Aktarım</h3>
        <p class="pd-card-subtitle">Kopyala-yapıştır akışı şimdilik önerilen hızlı import yöntemidir. Gerçek Excel parser sonraki aşamada eklenecek.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-note mb-4"><code>code;name;parent_code;product_family;sort_order</code></div>
        <div class="flex gap-2 flex-wrap">
            <a href="{{ route('admin.super.standard-categories.bulk-paste') }}" class="pd-btn pd-btn-primary">Toplu Kopyala/Yapıştır Ekranını Aç</a>
            <a href="{{ route('admin.super.standard-categories.import') }}" class="pd-btn pd-btn-light">CSV/Excel Placeholder</a>
            <a href="{{ route('admin.super.standard-categories.template') }}" class="pd-btn pd-btn-light">Şablon İndir</a>
        </div>
    </div>
</section>

<section id="eslesmemis-kontrol" class="pd-card pd-section-card mb-6">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Eşlenmemiş Kontrol</h3>
        <p class="pd-card-subtitle">Kategori eşleme öncesinde eksik veya opsiyonel alanları hızlıca kontrol edin.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-3">
            <div class="pd-profile-info">
                <div class="pd-profile-info-label">Eşleme Bekleyen</div>
                <div class="pd-profile-info-value">{{ $stats['pending_mappings'] }}</div>
            </div>
            <div class="pd-profile-info">
                <div class="pd-profile-info-label">Opsiyonel Eşleme</div>
                <div class="pd-profile-info-value">{{ $categories->where('requires_mapping', false)->count() }}</div>
            </div>
            <div class="pd-profile-info">
                <div class="pd-profile-info-label">3+ Seviye Kontrol</div>
                <div class="pd-profile-info-value">{{ $categories->where('depth', '>=', 3)->count() }}</div>
            </div>
        </div>
    </div>
</section>

<section id="kullanim-notlari" class="pd-card pd-section-card mb-20">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Kullanım Notları</h3>
        <p class="pd-card-subtitle">Kategori ağacını yalın tutup detayları özellik/filtre katmanına taşıyın.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-summary-list">
            <span class="pd-summary-item">Renk, malzeme, hacim ve baskı tipi gibi alanları kategori çoğaltmak yerine özellik kuralı olarak yönetin.</span>
            <span class="pd-summary-item">4 seviye ve üstü yapı yerine ürün ailesi + kategori + alt kategori + özellik modeli tercih edilmelidir.</span>
            <span class="pd-summary-item">Parent değiştirme aktif. Kategori kendi alt kırılımına taşınamaz ve 4. seviye için uyarı verir.</span>
        </div>
    </div>
</section>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Not:</strong>
    <span class="pd-muted">Standart kategori ağacı Product Data Hub kategori eşlemesinin temelidir.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.standard-categories.create') }}" class="pd-btn pd-btn-primary">Yeni Kategori</a>
    <a href="{{ route('admin.super.standard-categories.bulk-paste') }}" class="pd-btn pd-btn-light">Toplu İçe Aktar</a>
    <button type="submit" form="pdStandardCategoryOrderForm" class="pd-btn pd-btn-light">Sıralamayı Kaydet</button>
    <button type="submit" form="pdStandardCategoryBulkForm" onclick="this.form.bulk_action.value='deactivate'" class="pd-btn pd-btn-warning">Seçilenleri Pasif Yap</button>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-warning">Kategori Eşleme</a>
    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">Product Data Hub</a>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Kategori Notları</h3>

        <div class="pd-summary-action-list">
            <span class="pd-summary-action"><span>Global kategori ağacını Super Admin yönetir.</span><span class="pd-badge pd-badge-blue">Rol</span></span>
            <span class="pd-summary-action"><span>Tenant bu ağacı değiştiremez.</span><span class="pd-badge pd-badge-amber">Kilitli</span></span>
            <span class="pd-summary-action"><span>Tedarikçi eşleme işi ayrı Kategori Eşleme Kuyruğu ekranındadır.</span><span class="pd-badge pd-badge-green">Ayrı</span></span>
            <span class="pd-summary-action"><span>4 seviye ve üstü kategori yerine özellik/filtre kullanılmalıdır.</span><span class="pd-badge pd-badge-purple">Kural</span></span>
            <span class="pd-summary-action"><span>Gerçek Excel parser sonraki aşamada geliştirilecek.</span><span class="pd-badge pd-badge-gray">Plan</span></span>
        </div>

        <div class="pd-summary-section mt-4">
            <h4 class="pd-summary-section-title">Hızlı Aksiyonlar</h4>
            <div class="pd-summary-action-list">
                <a href="{{ route('admin.super.standard-categories.create') }}" class="pd-summary-action"><span>Yeni Kategori</span><span class="pd-badge pd-badge-blue">Yeni</span></a>
                <a href="{{ route('admin.super.standard-categories.bulk-paste') }}" class="pd-summary-action"><span>Toplu Kopyala/Yapıştır</span><span class="pd-badge pd-badge-green">Toplu</span></a>
                <a href="{{ route('admin.super.standard-categories.import') }}" class="pd-summary-action"><span>CSV/Excel Import</span><span class="pd-badge pd-badge-amber">Import</span></a>
                <a href="{{ route('admin.super.product-data-hub.category-feature-templates.index') }}" class="pd-summary-action"><span>Özellik Şablonları</span><span class="pd-badge pd-badge-blue">Şablon</span></a>
                <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-summary-action"><span>Product Data Hub</span><span class="pd-badge pd-badge-gray">Merkez</span></a>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-summary-action"><span>Kategori Eşleme</span><span class="pd-badge pd-badge-purple">Bağla</span></a>
            </div>
        </div>

        <div class="pd-side-note">Bu ağaç ve özellik kuralları Gelişmiş Ürün ve Katalog projeksiyonundan önce netleştirilmelidir.</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('pdSelectAllCategories');
    const checkboxes = Array.from(document.querySelectorAll('.pd-category-check'));
    const countEl = document.getElementById('pdSelectedCategoryCount');
    const moveForms = Array.from(document.querySelectorAll('.pd-move-form'));

    function updateSelectedCount() {
        const selectedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
        if (countEl) {
            countEl.textContent = String(selectedCount);
        }

        if (selectAll) {
            selectAll.checked = selectedCount > 0 && selectedCount === checkboxes.length;
            selectAll.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
            updateSelectedCount();
        });
    }

    checkboxes.forEach((checkbox) => {
        checkbox.addEventListener('change', updateSelectedCount);
    });

    window.pdClearCategorySelection = function () {
        checkboxes.forEach((checkbox) => {
            checkbox.checked = false;
        });
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        updateSelectedCount();
    };

    moveForms.forEach((form) => {
        const select = form.querySelector('select[name="new_parent_id"]');
        const preview = form.querySelector('.pd-move-preview-text');

        if (!select || !preview) {
            return;
        }

        const currentName = @json($categories->keyBy('id')->map(fn($category) => $category->name)->all());
        const categoryId = form.action.split('/').slice(-2, -1)[0];

        const updateMovePreview = function () {
            const selectedOption = select.options[select.selectedIndex];
            const parentPath = selectedOption && selectedOption.value !== '' ? (selectedOption.dataset.path || selectedOption.textContent.trim()) : '';
            const categoryName = currentName[categoryId] || '';
            preview.textContent = parentPath ? parentPath + ' / ' + categoryName : categoryName;
        };

        select.addEventListener('change', updateMovePreview);
        updateMovePreview();
    });

    updateSelectedCount();
});
</script>
@endpush
