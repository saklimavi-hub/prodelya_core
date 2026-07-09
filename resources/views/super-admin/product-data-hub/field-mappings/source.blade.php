@extends('layouts.prodelya-admin')

@section('page_title', 'Alan Eşleme')

@section('content')
<div class="pd-page-head pd-product-hub">
    <div>
        <h1 class="pd-page-title">Alan Eşleme — {{ $source->source_name }}</h1>
        <p class="pd-page-subtitle">Bu ekran birleşik kurulum akışının alan eşleme adımıdır. Ön kontrolde görülen örnek değerleri Prodelya standart alanlarına bağlayın; uygun ürünler sonra otomatik senkron akışına katılır.</p>
    </div>
    <div class="pd-page-actions">
        <a href="{{ route('admin.super.product-data-hub.sources.preview', $source) }}" class="pd-btn pd-btn-light">Önizlemeye Dön</a>
        <a href="{{ route('admin.super.product-data-hub.category-mappings.index', ['supplier_id' => $source->supplier_id]) }}" class="pd-btn pd-btn-light">Kategorileri Eşle</a>
    </div>
</div>

<div class="pd-note mb-6">Bu ekran Super Admin tarafından yönetilir. Abone Firma bu verileri değiştiremez.</div>

<div class="pd-note mb-6 pd-product-hub__auto-note">Tedarikçiden gelen alanları Prodelya standart alanlarına bağlayın. Zorunlu alanlar tamamlanmadan ürünler güvenli otomatik akışa tam açılmaz; eksik veya şüpheli kayıtlar Bekleyen Kontroller tarafında kalır.</div>

<div class="pd-card mb-6 pd-product-hub__setup-flow">
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-3">
            <div class="pd-note"><strong>1. Kaynak Bilgisi</strong><br>Kaynak ve bağlantı kaydı hazır.</div>
            <div class="pd-note"><strong>2. Ön Kontrol</strong><br>Örnek ürünler preview ekranında doğrulandı.</div>
            <div class="pd-note"><strong>3. Alan Eşleme</strong><br>Şu an bu adımı tamamlıyorsunuz.</div>
            <div class="pd-note"><strong>4. İlk Kategori Eşleme</strong><br>Sonraki adımda yeni kategori kararlarını bağlayın.</div>
            <div class="pd-note"><strong>5. Bekleyen Kontroller</strong><br>Eksik alan, şüpheli fiyat veya kategori kararı ayrı kuyrukta toplanır.</div>
            <div class="pd-note"><strong>6. Otomatik Senkron</strong><br>Kaynak aktif kaldığında uygun ürünler Abone Firma ürün listesine ve teklif/sipariş ürün seçimine otomatik yansır.</div>
        </div>
    </div>
</div>

<div class="pd-kpi-strip mb-6">
    <div class="pd-card"><div class="pd-card-body"><div class="pd-kpi-label">Kaynak Alan</div><div class="pd-kpi-value">{{ $stats['source_fields'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="pd-kpi-label">Eşlenen</div><div class="pd-kpi-value">{{ $stats['mapped'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="pd-kpi-label">Zorunlu Eksik</div><div class="pd-kpi-value">{{ $stats['missing_required'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="pd-kpi-label">Alias / Uyarı</div><div class="pd-kpi-value">{{ $stats['alias_count'] }} / {{ $stats['warning_count'] }}</div></div></div>
</div>

<div class="pd-card mb-6">
    <div class="pd-card-header">
        <h2 class="pd-section-title">Kaynak Özeti</h2>
    </div>
    <div class="pd-card-body pd-mini-kpi-strip">
        <div><label class="pd-label">Tedarikçi</label><input class="pd-input" value="{{ $source->supplier?->name }}" readonly></div>
        <div><label class="pd-label">Kaynak Tipi</label><input class="pd-input" value="{{ strtoupper($source->source_type) }}" readonly></div>
        <div><label class="pd-label">Tedarikçi Profili</label><input class="pd-input" value="{{ $profileKey ?? '-' }}" readonly></div>
        <div><label class="pd-label">Ürün Modeli</label><input class="pd-input" value="{{ $profile['product_model'] ?? '-' }}" readonly></div>
        <div><label class="pd-label">Prefix</label><input class="pd-input" value="{{ $profile['supplier_code_prefix'] ?? '-' }}" readonly></div>
        <div><label class="pd-label">Ürün Kodu Şablonu</label><input class="pd-input" value="{{ $profile['generated_code_template'] ?? '-' }}" readonly></div>
        <div><label class="pd-label">Varyasyon Kodu Şablonu</label><input class="pd-input" value="{{ $profile['generated_variant_code_template'] ?? '-' }}" readonly></div>
    </div>
</div>

<div class="pd-card mb-6">
    <div class="pd-card-header">
        <h2 class="pd-section-title">Örnek Veri Bağlamı</h2>
    </div>
    <div class="pd-card-body pd-mini-kpi-strip">
        <div><label class="pd-label">Node Path</label><input class="pd-input" value="{{ $sourceFieldContext['node_path'] ?: '-' }}" readonly></div>
        <div><label class="pd-label">Okunan Örnek Kayıt</label><input class="pd-input" value="{{ $sourceFieldContext['records_read'] }}" readonly></div>
        <div><label class="pd-label">Alan Kaynağı</label><input class="pd-input" value="{{ $sourceFieldContext['source_mode'] === 'live_source' ? 'Gerçek XML / API örneği' : 'Profil / kayıtlı mapping fallback' }}" readonly></div>
    </div>
</div>

@if(!empty($requiredWarnings))
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <div class="pd-warn">Bu kaynak import için hazır değil. Zorunlu alan eşlemeleri eksik.</div>
            <div class="pd-note mt-3">Zorunlu alanlar tamamlanmadan güvenli otomatik akış başlatılmamalıdır.</div>
            <div class="pd-chip-group mt-3">
                @foreach($missingRequiredLabels as $label)
                    <span class="pd-badge pd-badge-amber">{{ $label }}</span>
                @endforeach
            </div>
            <div class="mt-3">
                @foreach($requiredWarnings as $warning)
                    <div class="pd-note mt-2">{{ $warning }}</div>
                @endforeach
            </div>
        </div>
    </div>
@else
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <span class="pd-badge pd-badge-green">Zorunlu alanlar tamam.</span>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('admin.super.product-data-hub.field-mappings.source.update', $source) }}">
    @csrf
    <div class="pd-card">
        <div class="pd-card-header">
            <h2 class="pd-section-title">Kaynak Alan Eşlemeleri</h2>
            <div class="pd-page-actions">
                @foreach($availableFilters as $filterKey => $filterLabel)
                    <a href="{{ route('admin.super.product-data-hub.field-mappings.source', ['source' => $source->id, 'filter' => $filterKey]) }}" class="pd-btn {{ $activeFilter === $filterKey ? 'pd-btn-primary' : 'pd-btn-light' }}">{{ $filterLabel }}</a>
                @endforeach
            </div>
        </div>
        <div class="pd-card-body">
            <div class="pd-note mb-4">Hızlı filtrelerle yalnız zorunlu eksikleri, eşlenmemiş alanları veya fiyat, görsel, kategori ve varyant alanlarını açabilirsiniz. Bu tabloda kaynak alanı, örnek değer, Prodelya alanı ve durum aynı akışta görünür.</div>
            <div class="pd-form-actions mb-4">
                <button type="button" class="pd-btn pd-btn-light" id="applySuggestedMappings">Önerilenleri Doldur</button>
                <button type="submit" class="pd-btn pd-btn-primary">Eşlemeyi Kaydet</button>
            </div>
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Kaynak Alan</th>
                            <th>Örnek Değer</th>
                            <th>Önerilen Standart Alan</th>
                            <th>Seçilen Standart Alan</th>
                            <th>Durum</th>
                            <th>Detay</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mappingRows as $row)
                            <tr @class(['pd-table-row-selected' => $activeFilter === 'required_missing' && blank($row['selected_standard_field']) && in_array($row['suggested_standard_field'], collect($requiredSummary['missing'])->pluck('accepted_fields')->flatten()->all(), true)])>
                                <td><code>{{ $row['source_field_name'] }}</code></td>
                                <td class="pd-compact-meta-cell">{{ filled($row['sample_value']) ? $row['sample_value'] : 'Örnek değer bulunamadı' }}</td>
                                <td>
                                    @if($row['suggested_standard_field'])
                                        {{ $standardFields[$row['suggested_standard_field']]['label'] ?? $row['suggested_standard_field'] }}
                                        <div><code>{{ $row['suggested_standard_field'] }}</code></div>
                                    @else
                                        <span class="pd-badge pd-badge-gray">Öneri yok</span>
                                    @endif
                                </td>
                                <td>
                                    <select
                                        name="mappings[{{ $row['source_field_name'] }}][standard_field_key]"
                                        class="pd-select"
                                        data-mapping-select
                                        data-source-field="{{ $row['source_field_name'] }}"
                                        data-suggested-field="{{ $row['suggested_standard_field'] ?? '' }}"
                                    >
                                        <option value="">Standart alan seçin</option>
                                        @foreach($standardFieldGroups as $group)
                                            <optgroup label="{{ $group['label'] }}">
                                                @foreach($group['fields'] as $fieldKey)
                                                    @continue(!isset($standardFields[$fieldKey]))
                                                    <option value="{{ $fieldKey }}" @selected($row['selected_standard_field'] === $fieldKey)>
                                                        {{ $standardFields[$fieldKey]['label'] }} ({{ $fieldKey }})
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                        @foreach($standardFields as $fieldKey => $fieldMeta)
                                            @continue(collect($standardFieldGroups)->pluck('fields')->flatten()->contains($fieldKey))
                                            <option value="{{ $fieldKey }}" @selected($row['selected_standard_field'] === $fieldKey)>
                                                {{ $fieldMeta['label'] }} ({{ $fieldKey }})
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>{{ $row['is_required'] ? 'Evet' : 'Hayır' }}</td>
                                <td>{{ $row['type_label'] }}</td>
                                <td>
                                    <select name="mappings[{{ $row['source_field_name'] }}][mapping_status]" class="pd-select">
                                        @foreach($mappingStatusOptions as $statusKey => $statusLabel)
                                            <option value="{{ $statusKey }}" @selected($row['mapping_status'] === $statusKey)>{{ $statusLabel }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <div class="pd-muted mb-2">Normalize / Alias: {{ $row['legacy_field_name'] ?? $row['normalized_source_field'] }}</div>
                                    <div class="pd-muted mb-2">Zorunlu: {{ $row['is_required'] ? 'Evet' : 'Hayır' }}</div>
                                    <div class="pd-muted mb-2">Tür: {{ $row['type_label'] }}</div>
                                    <input type="text" name="mappings[{{ $row['source_field_name'] }}][transform_rule]" value="{{ $row['transform_rule'] }}" class="pd-input mb-2" placeholder="trim|upper|price">
                                    <input type="text" name="mappings[{{ $row['source_field_name'] }}][note]" value="{{ $row['note'] }}" class="pd-input" placeholder="İsteğe bağlı not">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Özet</h3>
        <div class="pd-summary-row"><span>Tedarikçi</span><strong>{{ $source->supplier?->name }}</strong></div>
        <div class="pd-summary-row"><span>Profil</span><strong>{{ $profileKey ?? '-' }}</strong></div>
        <div class="pd-summary-row"><span>Node path</span><strong>{{ $sourceFieldContext['node_path'] ?: '-' }}</strong></div>
        <div class="pd-summary-row"><span>Örnek kayıt</span><strong>{{ $sourceFieldContext['records_read'] }}</strong></div>
        @if(!empty($profileAliases))
            <div class="pd-summary-row"><span>Alias</span><strong>{{ collect($profileAliases)->take(3)->map(fn ($target, $sourceField) => $sourceField . ' → ' . $target)->implode(' | ') }}</strong></div>
        @endif
        <div class="pd-summary-row"><span>Son İnceleme</span><strong>{{ now()->format('d.m.Y') }}</strong></div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Geçişler</h4>
            <div class="pd-summary-action-list">
                <a href="{{ route('admin.super.product-data-hub.sources.preview', $source) }}" class="pd-summary-action">
                    <span>Kaynak Önizleme</span>
                    <span class="pd-badge pd-badge-blue">Preview</span>
                </a>
                <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-summary-action">
                    <span>Tedarikçi Akışları</span>
                    <span class="pd-badge pd-badge-green">Akış</span>
                </a>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-summary-action">
                    <span>Kategori Eşleme</span>
                    <span class="pd-badge pd-badge-amber">Kategori</span>
                </a>
            </div>
        </div>

        <div class="pd-side-note">Zorunlu alanlar tamamlanmadan ürünler güvenli otomatik akışa tam açılmaz. Sonraki adımda ilk kategori eşlemeyi tamamlayın; eşlenmeyen kayıtlar Bekleyen Kontroller tarafında kalır.</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const applyButton = document.getElementById('applySuggestedMappings');

    if (!applyButton) {
        return;
    }

    applyButton.addEventListener('click', function () {
        document.querySelectorAll('[data-mapping-select]').forEach(function (select) {
            if (select.value) {
                return;
            }

            const suggestedField = select.dataset.suggestedField;

            if (!suggestedField) {
                return;
            }

            const matchingOption = Array.from(select.options).find(function (option) {
                return option.value === suggestedField;
            });

            if (matchingOption) {
                select.value = suggestedField;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });
});
</script>
@endpush
