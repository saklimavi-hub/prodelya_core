@extends('layouts.prodelya-admin')

@section('page_title', 'Global Alan Eşleme')

@section('content')
<div class="pd-page-head">
    <div>
        <h1 class="pd-page-title">Global Alan Eşleme — {{ $source->source_name }}</h1>
        <p class="pd-page-subtitle">Kaynak bazlı alan eşleme kayıtlarını Super Admin olarak yönetin.</p>
    </div>
    <div class="pd-page-actions">
        <a href="{{ route('admin.super.product-data-hub.sources.preview', $source) }}" class="pd-btn pd-btn-light">Preview'a Dön</a>
    </div>
</div>

<div class="pd-note mb-6">Bu ekran Super Admin tarafından yönetilir. Tenant bu verileri değiştiremez.</div>

<div class="pd-grid pd-grid-4 mb-6">
    <div class="pd-card"><div class="pd-card-body"><div class="pd-kpi-label">Kaynak Alan</div><div class="pd-kpi-value">{{ $stats['source_fields'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="pd-kpi-label">Eşlenen</div><div class="pd-kpi-value">{{ $stats['mapped'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="pd-kpi-label">Zorunlu Eksik</div><div class="pd-kpi-value">{{ $stats['missing_required'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="pd-kpi-label">Alias / Uyarı</div><div class="pd-kpi-value">{{ $stats['alias_count'] }} / {{ $stats['warning_count'] }}</div></div></div>
</div>

<div class="pd-card mb-6">
    <div class="pd-card-body pd-grid pd-grid-3">
        <div><label class="pd-label">Tedarikçi</label><input class="pd-input" value="{{ $source->supplier?->name }}" readonly></div>
        <div><label class="pd-label">Kaynak Tipi</label><input class="pd-input" value="{{ strtoupper($source->source_type) }}" readonly></div>
        <div><label class="pd-label">Tedarikçi Profili</label><input class="pd-input" value="{{ $profileKey ?? '-' }}" readonly></div>
        <div><label class="pd-label">Ürün Modeli</label><input class="pd-input" value="{{ $profile['product_model'] ?? '-' }}" readonly></div>
        <div><label class="pd-label">Prefix</label><input class="pd-input" value="{{ $profile['supplier_code_prefix'] ?? '-' }}" readonly></div>
        <div><label class="pd-label">Ürün Kodu Şablonu</label><input class="pd-input" value="{{ $profile['generated_code_template'] ?? '-' }}" readonly></div>
        <div><label class="pd-label">Varyasyon Kodu Şablonu</label><input class="pd-input" value="{{ $profile['generated_variant_code_template'] ?? '-' }}" readonly></div>
    </div>
</div>

@if(!empty($requiredWarnings))
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <div class="pd-warn">Bu kaynak import için hazır değil. Zorunlu alan eşlemeleri eksik.</div>
            @foreach($requiredWarnings as $warning)
                <div class="pd-note mt-2">{{ $warning }}</div>
            @endforeach
        </div>
    </div>
@endif

<form method="POST" action="{{ route('admin.super.product-data-hub.field-mappings.source.update', $source) }}">
    @csrf
    <div class="pd-card">
        <div class="pd-card-header">
            <h2 class="pd-section-title">Kaynak Alan Eşlemeleri</h2>
            <div class="pd-page-actions">
                <button type="button" class="pd-btn pd-btn-light" id="applySuggestedMappings">Önerileri Uygula</button>
                <button type="submit" class="pd-btn pd-btn-primary">Mapping Kaydet</button>
            </div>
        </div>
        <div class="pd-card-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Kaynak Alan</th>
                            <th>Normalize / Alias</th>
                            <th>Önerilen Standart Alan</th>
                            <th>Seçilen Standart Alan</th>
                            <th>Zorunlu</th>
                            <th>Tür</th>
                            <th>Durum</th>
                            <th>Dönüşüm</th>
                            <th>Not</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mappingRows as $row)
                            <tr>
                                <td><code>{{ $row['source_field_name'] }}</code></td>
                                <td>{{ $row['legacy_field_name'] ?? $row['normalized_source_field'] }}</td>
                                <td><code>{{ $row['suggested_standard_field'] ?? '-' }}</code></td>
                                <td>
                                    <select
                                        name="mappings[{{ $row['source_field_name'] }}][standard_field_key]"
                                        class="pd-select"
                                        data-mapping-select
                                        data-source-field="{{ $row['source_field_name'] }}"
                                        data-suggested-field="{{ $row['suggested_standard_field'] ?? '' }}"
                                    >
                                        <option value="">Standart alan seçin</option>
                                        @foreach($standardFields as $fieldKey => $fieldMeta)
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
                                <td><input type="text" name="mappings[{{ $row['source_field_name'] }}][transform_rule]" value="{{ $row['transform_rule'] }}" class="pd-input" placeholder="trim|upper|price"></td>
                                <td><input type="text" name="mappings[{{ $row['source_field_name'] }}][note]" value="{{ $row['note'] }}" class="pd-input" placeholder="İsteğe bağlı not"></td>
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
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Özet</h3>
        <div class="pd-summary-row"><span>Tedarikçi</span><strong>{{ $source->supplier?->name }}</strong></div>
        <div class="pd-summary-row"><span>Profil</span><strong>{{ $profileKey ?? '-' }}</strong></div>
        @if(!empty($profileAliases))
            <div class="pd-summary-row"><span>Alias</span><strong>{{ collect($profileAliases)->take(3)->map(fn ($target, $sourceField) => $sourceField . ' → ' . $target)->implode(' | ') }}</strong></div>
        @endif
        <div class="pd-summary-row"><span>Son İnceleme</span><strong>{{ now()->format('d.m.Y') }}</strong></div>
        @if(($profileKey ?? null) === 'YENI-NESIL')
            <div class="pd-note mt-3">
                <strong>Yeni Nesil Alias:</strong><br>
                turuncu → warning_flag<br>
                isim → product_name<br>
                baslik → display_product_name
            </div>
        @endif
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
