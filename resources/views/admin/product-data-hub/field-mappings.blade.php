@extends('layouts.prodelya-admin')

@section('title', 'Alan Eşleme')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Alan Eşleme</h1>
        <p class="pd-muted mt-1">Tedarikçi veri alanlarını standart alanlara eşleyin.</p>
    </div>
    <div>
        <button class="pd-btn pd-btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Yeni Eşleme Ekle
        </button>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-body">
        <div class="overflow-x-auto">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tedarikçi</th>
                        <th>Kaynak Alan</th>
                        <th>Hedef Alan</th>
                        <th>Eşleme Tipi</th>
                        <th>Gerekli</th>
                        <th>Varsayılan</th>
                        <th>Doğrulama</th>
                        <th>Dönüşüm</th>
                        <th>Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mappings as $mapping)
                    <tr>
                        <td>
                            <div class="font-medium">{{ $mapping['supplier'] }}</div>
                        </td>
                        <td>
                            <span class="pd-badge pd-badge-blue">{{ $mapping['source_field'] }}</span>
                        </td>
                        <td>
                            <span class="pd-badge pd-badge-green">{{ $mapping['target_field'] }}</span>
                        </td>
                        <td>
                            <span class="pd-badge pd-badge-{{ $mapping['mapping_type'] === 'direct' ? 'green' : 'yellow' }}">
                                {{ $mapping['mapping_type'] === 'direct' ? 'Doğrudan' : 'Dönüşüm' }}
                            </span>
                        </td>
                        <td>
                            @if($mapping['is_required'])
                                <span class="pd-badge pd-badge-red">Evet</span>
                            @else
                                <span class="pd-badge pd-badge-gray">Hayır</span>
                            @endif
                        </td>
                        <td>
                            <span class="text-sm text-gray-600">{{ $mapping['default_value'] ?: '-' }}</span>
                        </td>
                        <td>
                            <div class="text-sm">
                                @foreach($mapping['validation_rules'] as $rule)
                                <span class="pd-badge pd-badge-sm pd-badge-blue">{{ $rule }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td>
                            <span class="text-sm text-gray-600">{{ $mapping['transform_function'] ?: '-' }}</span>
                        </td>
                        <td>
                            <div class="flex items-center space-x-2">
                                <button class="pd-btn pd-btn-sm pd-btn-light">Düzenle</button>
                                <button class="pd-btn pd-btn-sm pd-btn-danger">Sil</button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="pd-grid pd-grid-2" style="margin-top: 14px;">
    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Standart Field Dictionary</h3>
            <p class="pd-card-subtitle">Raw product, raw variant ve standard product katmanlari icin hedef alanlar.</p>
        </div>
        <div class="pd-card-body">
            @foreach($fieldDictionary as $layer => $fields)
                <div style="margin-bottom: 12px;">
                    <div class="font-medium" style="margin-bottom: 6px;">{{ str_replace('_', ' ', strtoupper($layer)) }}</div>
                    <div class="space-y-1">
                        @foreach($fields as $field)
                            <span class="pd-badge pd-badge-blue">{{ $field }}</span>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Tedarikci Model Notlari</h3>
            <p class="pd-card-subtitle">Prefix, kod template ve farkli XML varyasyon modelleri icin omurga.</p>
        </div>
        <div class="pd-card-body">
            @foreach($supplierProfiles as $supplierCode => $profile)
                <div class="pd-note" style="margin-bottom: 10px;">
                    <div class="font-medium">{{ $profile['display_name'] }} ({{ $supplierCode }})</div>
                    <div class="text-sm">Prefix: {{ $profile['supplier_code_prefix'] }}</div>
                    <div class="text-sm">Model: {{ $profile['product_model'] }}</div>
                    <div class="text-sm">Code: {{ $profile['generated_code_template'] }}</div>
                    <div class="text-sm">Variant: {{ $profile['generated_variant_code_template'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-quote-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Eşleme Özeti</div>
        
        <div class="space-y-3">
            <div class="pd-summary-row">
                <span>Toplam Eşleme:</span>
                <span class="font-medium">{{ count($mappings) }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Gerekli Alan:</span>
                <span class="font-medium text-red-600">{{ collect($mappings)->where('is_required', true)->count() }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Dönüşüm Alan:</span>
                <span class="font-medium text-yellow-600">{{ collect($mappings)->where('mapping_type', 'transform')->count() }}</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <button class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">
                Otomatik Eşleme
            </button>
            <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">
                Eşlemeyi Test Et
            </button>
        </div>
    </div>
</div>
@endsection
