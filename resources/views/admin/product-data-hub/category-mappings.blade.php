@extends('layouts.prodelya-admin')

@section('title', 'Kategori Eşleme')
@section('page_title', 'Kategori Eşleme')
@section('page_subtitle', 'Tedarikçi kategorilerini Prodelya Standart Kategori Ağacı\'ndaki hedef kategorilere bağlayın.')

@section('content')
<div class="pd-note" style="margin-bottom: 14px;">
    Standart kategori ağacı Super Admin tarafından yönetilir. Tenant bu ağaç üzerinde değişiklik yapamaz; yalnız tedarikçi kategori eşleme ve kendi katalog görünüm ayarları yönetilir.
</div>

<div class="pd-grid" style="grid-template-columns: repeat(5, minmax(0, 1fr)); margin-bottom: 14px;">
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Toplam Kategori</div><div class="text-2xl font-bold">{{ $stats['total'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Eşlenen</div><div class="text-2xl font-bold">{{ $stats['mapped'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Bekleyen</div><div class="text-2xl font-bold">{{ $stats['pending'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Manuel Kontrol</div><div class="text-2xl font-bold">{{ $stats['needs_review'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Düşük Güven</div><div class="text-2xl font-bold">{{ $stats['low_confidence'] }}</div></div></div>
</div>

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-body">
        <form method="GET" class="pd-grid pd-grid-4">
            <div>
                <label class="text-sm font-medium">Tedarikçi</label>
                <select name="supplier_id" class="pd-select">
                    <option value="">Tümü</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(($filters['supplier_id'] ?? null) == $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">Durum</label>
                <select name="status" class="pd-select">
                    <option value="">Tümü</option>
                    <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>Bekleyen</option>
                    <option value="mapped" @selected(($filters['status'] ?? '') === 'mapped')>Eşlenen</option>
                    <option value="ignored" @selected(($filters['status'] ?? '') === 'ignored')>Yok Sayılan</option>
                    <option value="needs_review" @selected(($filters['status'] ?? '') === 'needs_review')>Manuel Kontrol</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">Hedef Kategori</label>
                <select name="standard_category_id" class="pd-select">
                    <option value="">Tümü</option>
                    @foreach($standardCategories as $category)
                        <option value="{{ $category->id }}" @selected(($filters['standard_category_id'] ?? null) == $category->id)>{{ $category->full_path }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">Arama</label>
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" class="pd-input" placeholder="Kategori veya tedarikçi ara">
            </div>
            <div>
                <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
            </div>
        </form>
    </div>
</div>

@if($mappings->isEmpty())
<div class="pd-card">
    <div class="pd-card-body" style="text-align:center; padding: 48px 24px;">
        <h3 class="text-lg font-medium" style="margin-bottom: 8px;">Henüz eşlenecek tedarikçi kategorisi bulunmuyor.</h3>
        <p class="pd-muted" style="margin-bottom: 16px;">Gerçek kategori kayıtları XML/CSV import preview veya sync sonrasında oluşacaktır.</p>
        <a href="{{ route('admin.product-data-hub.sources') }}" class="pd-btn pd-btn-primary">Tedarikçi Kaynaklarına Git</a>
    </div>
</div>
@else
<form id="categoryMappingBulkForm" method="POST" action="{{ route('admin.product-data-hub.category-mappings.bulk-update') }}">
    @csrf
    <input type="hidden" name="_method" value="POST" id="categoryMappingMethod">

    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Tedarikçi Kategori Eşlemeleri</h3>
            <p class="pd-card-subtitle">Önerilen hedef metni bilgi amaçlıdır; seçili hedef kategori gerçek kaydı belirler.</p>
        </div>
        <div class="pd-card-body">
            <div style="margin-bottom: 12px;">
                <button type="submit" class="pd-btn pd-btn-primary" onclick="prepareCategoryMappingBulkSubmit()">Toplu Kaydet</button>
            </div>

            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Tedarikçi</th>
                            <th>Tedarikçi Kategorisi</th>
                            <th>Önerilen Hedef</th>
                            <th>Hedef Prodelya Kategorisi</th>
                            <th>Güven Skoru</th>
                            <th>Durum</th>
                            <th>Son İnceleyen</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mappings as $mapping)
                        <tr>
                            <td><div class="font-medium">{{ $mapping->supplier?->name ?: '-' }}</div><div class="text-sm text-gray-600">{{ $mapping->source?->source_name ?: '-' }}</div></td>
                            <td><span class="pd-badge pd-badge-blue">{{ $mapping->source_category }}</span></td>
                            <td><span class="text-sm text-gray-600">{{ $mapping->target_category ?: '-' }}</span></td>
                            <td>
                                <select name="mappings[{{ $mapping->id }}][standard_category_id]" class="pd-select">
                                    <option value="">Hedef kategori seçin</option>
                                    <optgroup label="Promosyon">
                                        @foreach($standardCategories->where('product_family', 'promotion') as $category)
                                            <option value="{{ $category->id }}" @selected($mapping->standard_category_id == $category->id)>{{ $category->full_path }}</option>
                                        @endforeach
                                    </optgroup>
                                    <optgroup label="Matbaa">
                                        @foreach($standardCategories->where('product_family', 'print') as $category)
                                            <option value="{{ $category->id }}" @selected($mapping->standard_category_id == $category->id)>{{ $category->full_path }}</option>
                                        @endforeach
                                    </optgroup>
                                </select>
                            </td>
                            <td style="min-width: 120px;"><input type="number" name="mappings[{{ $mapping->id }}][confidence_score]" min="0" max="100" step="0.01" value="{{ old("mappings.{$mapping->id}.confidence_score", $mapping->confidence_score) }}" class="pd-input"></td>
                            <td style="min-width: 160px;">
                                <select name="mappings[{{ $mapping->id }}][mapping_status]" class="pd-select">
                                    <option value="pending" @selected($mapping->mapping_status === 'pending')>Bekleyen</option>
                                    <option value="mapped" @selected($mapping->mapping_status === 'mapped')>Eşlenen</option>
                                    <option value="ignored" @selected($mapping->mapping_status === 'ignored')>Yok Sayılan</option>
                                    <option value="needs_review" @selected($mapping->mapping_status === 'needs_review')>Manuel Kontrol</option>
                                </select>
                                <input type="hidden" name="mappings[{{ $mapping->id }}][note]" value="{{ $mapping->description }}">
                            </td>
                            <td><div class="text-sm">{{ $mapping->reviewer?->name ?: '-' }}</div><div class="text-xs text-gray-600">{{ $mapping->reviewed_at ? $mapping->reviewed_at->format('d.m.Y H:i') : '-' }}</div></td>
                            <td>
                                <button type="submit" class="pd-btn pd-btn-sm pd-btn-light" formaction="{{ route('admin.product-data-hub.category-mappings.update', $mapping) }}" onclick="prepareCategoryMappingSingleSubmit()">Kaydet</button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>
@endif
@endsection

@section('summary')
<div class="pd-card">
    <div class="pd-card-body">
        <div class="pd-summary-title">Kategori Özeti</div>

        <div class="space-y-3">
            <div class="pd-summary-row"><span>Toplam Eşleştirme:</span><span class="font-medium">{{ $stats['total'] }}</span></div>
            <div class="pd-summary-row"><span>Standart Kategori:</span><span class="font-medium">{{ $standardCategories->count() }}</span></div>
            <div class="pd-summary-row"><span>Bekleyen:</span><span class="font-medium text-amber-600">{{ $stats['pending'] }}</span></div>
            <div class="pd-summary-row"><span>Manuel Kontrol:</span><span class="font-medium text-red-600">{{ $stats['needs_review'] }}</span></div>
        </div>

        <div class="mt-6 space-y-2">
            <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block text-center">Standart Kategori Ağacı</a>
            <a href="{{ route('admin.product-data-hub.sources') }}" class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block text-center">Tedarikçi Kaynakları</a>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function prepareCategoryMappingBulkSubmit() {
    var methodInput = document.getElementById('categoryMappingMethod');
    if (methodInput) {
        methodInput.value = 'POST';
    }
}

function prepareCategoryMappingSingleSubmit() {
    var methodInput = document.getElementById('categoryMappingMethod');
    if (methodInput) {
        methodInput.value = 'PUT';
    }
}
</script>
@endpush
