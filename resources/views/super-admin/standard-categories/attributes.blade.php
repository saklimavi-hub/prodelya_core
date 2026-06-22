@extends('layouts.prodelya-admin')

@section('title', 'Kategori Özellik Kuralları')
@section('page_title', 'Kategori Özellik Kuralları')
@section('page_subtitle', 'Seçili kategori için filtrede görünecek ve satış/katalog tarafında kullanılacak özellikleri yönetin.')

@section('page_actions')
<div class="pd-actions-wrap">
    <a href="{{ route('admin.super.standard-categories.index', ['attribute_category' => $category->id]) }}" class="pd-btn pd-btn-light">Listeye Dön</a>
</div>
@endsection

@section('content')
<section class="pd-card mb-6">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Seçili Kategori</h3>
        <p class="pd-card-subtitle">{{ $category->full_path }}</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-4">
            <div class="pd-profile-info">
                <div class="pd-profile-info-label">Kod</div>
                <div class="pd-profile-info-value">{{ $category->code }}</div>
            </div>
            <div class="pd-profile-info">
                <div class="pd-profile-info-label">Ad</div>
                <div class="pd-profile-info-value">{{ $category->name }}</div>
            </div>
            <div class="pd-profile-info">
                <div class="pd-profile-info-label">Ürün Ailesi</div>
                <div class="pd-profile-info-value">{{ $category->product_family === 'promotion' ? 'Promosyon' : 'Matbaa' }}</div>
            </div>
            <div class="pd-profile-info">
                <div class="pd-profile-info-label">Yol</div>
                <div class="pd-profile-info-value">{{ $category->full_path }}</div>
            </div>
        </div>
    </div>
</section>

<section class="pd-card mb-6">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Hazır Şablonlar</h3>
        <p class="pd-card-subtitle">Kategoriye uygun hazır özellik setini tek tıkla uygulayın.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-template-strip">
            @foreach($templates as $templateKey => $template)
                <form method="POST" action="{{ route('admin.super.standard-categories.attributes.apply-template', $category) }}">
                    @csrf
                    <input type="hidden" name="template_key" value="{{ $templateKey }}">
                    <button type="submit" class="pd-template-chip">{{ $template['label'] }}</button>
                </form>
            @endforeach
        </div>
    </div>
</section>

<section class="pd-card mb-20">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Özellik Kuralları</h3>
        <p class="pd-card-subtitle">Filtrede ve katalogda görünürlük kurallarını kategori bazında düzenleyin.</p>
    </div>
    <div class="pd-card-body">
        <form method="POST" action="{{ route('admin.super.standard-categories.attributes.update', $category) }}" id="pdAttributeRulesForm">
            @csrf
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Özellik</th>
                            <th>Kod</th>
                            <th>Tip</th>
                            <th>Birim</th>
                            <th>Filtrede Görünsün</th>
                            <th>Zorunlu</th>
                            <th>Katalogda Görünsün</th>
                            <th>Sıra</th>
                            <th>Aktif mi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($attributeDefinitions as $definition)
                            @php
                                $rule = $attributeRules->get($definition->id);
                            @endphp
                            <tr>
                                <td>{{ $definition->name }}</td>
                                <td><span class="pd-category-code">{{ $definition->code }}</span></td>
                                <td>{{ strtoupper($definition->type) }}</td>
                                <td>{{ $definition->unit ?: '-' }}</td>
                                <td><input type="checkbox" name="attributes[{{ $definition->id }}][is_filterable]" value="1" @checked($rule?->is_filterable ?? $definition->is_filterable)></td>
                                <td><input type="checkbox" name="attributes[{{ $definition->id }}][is_required]" value="1" @checked($rule?->is_required ?? false)></td>
                                <td><input type="checkbox" name="attributes[{{ $definition->id }}][visible_in_catalog]" value="1" @checked($rule?->visible_in_catalog ?? false)></td>
                                <td><input type="number" name="attributes[{{ $definition->id }}][sort_order]" value="{{ $rule?->sort_order ?? $definition->sort_order }}" class="pd-input" style="min-width: 78px;"></td>
                                <td>
                                    <input type="hidden" name="attributes[{{ $definition->id }}][enabled]" value="0">
                                    <input type="checkbox" name="attributes[{{ $definition->id }}][enabled]" value="1" @checked((bool) $rule)>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</section>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Kategori Notları</h3>
        <div class="pd-summary-list">
            <span class="pd-summary-item">4 seviye ve üstü kategori yerine özellik/filtre kullanın.</span>
            <span class="pd-summary-item">Şablonlar başlangıç setidir; kategoriye göre kaydedip güncelleyebilirsiniz.</span>
            <span class="pd-summary-item">Tenant bu kural setini değiştiremez.</span>
        </div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Not:</strong>
    <span class="pd-muted">Kategori özellik kuralları katalog ve satış filtrelerinin temelini hazırlar.</span>
</div>
<div class="pd-bottom-action-buttons">
    <button type="submit" form="pdAttributeRulesForm" class="pd-btn pd-btn-primary">Kuralları Kaydet</button>
    <a href="{{ route('admin.super.standard-categories.index', ['attribute_category' => $category->id]) }}" class="pd-btn pd-btn-light">Kategori Listesi</a>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-warning">Kategori Eşleme</a>
</div>
@endsection
