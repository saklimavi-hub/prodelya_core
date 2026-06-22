@extends('layouts.prodelya-admin')

@section('title', 'Yeni Tedarikçi Kaynağı')

@php
    $profileOptions = collect($sourceProfiles)->mapWithKeys(fn ($profile, $key) => [$key => $profile['display_name'] ?? $key])->all();
@endphp

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Yeni Tedarikçi Kaynağı</h1>
        <p class="pd-muted mt-1">Etkin, Akdeniz, İlpen ve Yeni Nesil için XML, JSON, CSV veya API kaynağı ekleyin.</p>
    </div>
    <div class="pd-actions">
        <a href="{{ route('admin.product-data-hub.sources') }}" class="pd-btn pd-btn-light">Kaynaklara Dön</a>
    </div>
</div>

<div class="pd-note mb-4">Global tedarikçi kaynakları Super Admin tarafından yönetilir. Tenant tarafı yalnız kendisine açılan tedarikçi ürünlerini Gelişmiş Ürün ve Katalog ekranında görür.</div>

<form action="{{ route('admin.product-data-hub.sources.store') }}" method="POST" id="sourceForm">
    @csrf

    <div class="pd-card mb-6">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Kaynak Tanımı</h3>
        </div>
        <div class="pd-card-body">
            <div class="pd-form-grid-2">
                <div>
                    <label class="pd-label">Tedarikçi *</label>
                    <select name="supplier_id" class="pd-select" required>
                        <option value="">Tedarikçi seçin</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" {{ old('supplier_id') == $supplier->id ? 'selected' : '' }}>{{ $supplier->name }} ({{ $supplier->code }})</option>
                        @endforeach
                    </select>
                    <div class="pd-note mt-2">Etkin Promosyon, Akdeniz Promosyon, İlpen, Yeni Nesil veya ileride yeni tedarikçi.</div>
                </div>
                <div>
                    <label class="pd-label">Kaynak Adı *</label>
                    <input type="text" name="source_name" class="pd-input" value="{{ old('source_name') }}" required placeholder="Örn: Etkin JSON Feed">
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Kaynak Tipi *</label>
                    <select name="source_type" class="pd-select" required>
                        <option value="">Seçin</option>
                        @foreach(['xml' => 'XML', 'json' => 'JSON', 'csv' => 'CSV', 'api' => 'API', 'excel' => 'Excel'] as $value => $label)
                            <option value="{{ $value }}" {{ old('source_type') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Format / Profil *</label>
                    <select name="profile_key" class="pd-select">
                        @foreach($profileOptions as $value => $label)
                            <option value="{{ $value }}" {{ old('profile_key') === $value ? 'selected' : '' }}>{{ $value }} - {{ $label }}</option>
                        @endforeach
                        <option value="CUSTOM" {{ old('profile_key') === 'CUSTOM' ? 'selected' : '' }}>CUSTOM</option>
                    </select>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Kaynak URL</label>
                    <input type="url" name="url" class="pd-input" value="{{ old('url', 'https://promosyondunyasi.com/api_urunler.json') }}" placeholder="https://promosyondunyasi.com/api_urunler.json">
                </div>
                <div>
                    <label class="pd-label">Yerel Dosya Yolu</label>
                    <input type="text" name="source_file_path" class="pd-input" value="{{ old('source_file_path') }}" placeholder="C:\laragon\www\feeds\ilpen.xml">
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Ürün Node Path / Items Path</label>
                    <input type="text" name="product_node_path" class="pd-input" value="{{ old('product_node_path') }}" placeholder="RECORD / urunler / Urun">
                </div>
                <div>
                    <label class="pd-label">JSON Items Path</label>
                    <input type="text" name="items_path" class="pd-input" value="{{ old('items_path') }}" placeholder="items / data / products / urunler">
                </div>
            </div>
        </div>
    </div>

    <div class="pd-card mb-6">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Kod ve Profil Ayarları</h3>
        </div>
        <div class="pd-card-body">
            <div class="pd-form-grid-2">
                <div>
                    <label class="pd-label">Tedarikçi Prefix</label>
                    <input type="text" name="supplier_prefix" class="pd-input" value="{{ old('supplier_prefix') }}" placeholder="ET / AK / IL / YN">
                </div>
                <div>
                    <label class="pd-label">Format</label>
                    <input type="text" name="format" class="pd-input" value="{{ old('format') }}" placeholder="json / xml / csv">
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Ürün Kodu Şablonu</label>
                    <input type="text" name="generated_code_template" class="pd-input" value="{{ old('generated_code_template', '{PREFIX}-{SUPPLIER_PRODUCT_CODE}') }}" placeholder="{PREFIX}-{SUPPLIER_PRODUCT_CODE}">
                </div>
                <div>
                    <label class="pd-label">Varyasyon Kodu Şablonu</label>
                    <input type="text" name="generated_variant_code_template" class="pd-input" value="{{ old('generated_variant_code_template', '{PREFIX}-{SUPPLIER_GROUP_CODE}-{COLOR}') }}" placeholder="{PREFIX}-{SUPPLIER_GROUP_CODE}-{COLOR}">
                </div>
            </div>
        </div>
    </div>

    <div class="pd-card mb-6">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Senkron ve Kimlik Doğrulama</h3>
        </div>
        <div class="pd-card-body">
            <div class="pd-form-grid-2">
                <div>
                    <label class="pd-label">Senkron Sıklığı *</label>
                    <select name="sync_frequency" class="pd-select" required>
                        @foreach(['manual' => 'Manuel', 'hourly' => 'Saatlik', 'daily' => 'Günlük', 'weekly' => 'Haftalık'] as $value => $label)
                            <option value="{{ $value }}" {{ old('sync_frequency', 'manual') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Durum *</label>
                    <select name="status" class="pd-select" required>
                        <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Aktif</option>
                        <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Pasif</option>
                    </select>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Auth Tipi</label>
                    <select name="auth_type" class="pd-select">
                        <option value="none" {{ old('auth_type', 'none') === 'none' ? 'selected' : '' }}>Yok</option>
                        <option value="basic" {{ old('auth_type') === 'basic' ? 'selected' : '' }}>Basic Auth</option>
                        <option value="api_key" {{ old('auth_type') === 'api_key' ? 'selected' : '' }}>API Key</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Kullanıcı Adı</label>
                    <input type="text" name="username" class="pd-input" value="{{ old('username') }}" placeholder="Gerekirse kullanıcı adı">
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Şifre</label>
                    <input type="password" name="password" class="pd-input" value="{{ old('password') }}" placeholder="Gerekirse şifre">
                </div>
                <div>
                    <label class="pd-label">API Key</label>
                    <input type="text" name="api_key" class="pd-input" value="{{ old('api_key') }}" placeholder="Gerekirse API key">
                </div>
            </div>
        </div>
    </div>

    <div class="pd-card mb-6">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Notlar</h3>
        </div>
        <div class="pd-card-body">
            <textarea name="notes" class="pd-textarea" rows="4" placeholder="Kaynak ile ilgili notlar">{{ old('notes') }}</textarea>
        </div>
    </div>

    <div class="pd-actions">
        <a href="{{ route('admin.product-data-hub.sources') }}" class="pd-btn pd-btn-light">İptal</a>
        <button type="submit" class="pd-btn pd-btn-primary">Kaynağı Kaydet</button>
    </div>
</form>
@endsection

@section('summary')
<div class="pd-card">
    <div class="pd-card-body">
        <div class="pd-summary-title">Kaynak Ekleme Özeti</div>

        <div class="pd-summary-section">
            <div class="pd-summary-list">
                <div class="pd-summary-item">Bu kaynak Super Admin tarafından yönetilir.</div>
                <div class="pd-summary-item">Tenant bu URL’yi değiştiremez.</div>
                <div class="pd-summary-item">Tenant’a erişim ayrı ekrandan verilir.</div>
                <div class="pd-summary-item">Önce Kaynak Test Et, sonra Preview Al.</div>
            </div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hazır Profil İpuçları</h4>
            <div class="pd-summary-list">
                <div class="pd-summary-item">Etkin JSON: `https://promosyondunyasi.com/api_urunler.json`</div>
                <div class="pd-summary-item">Akdeniz XML: `RECORD` node path</div>
                <div class="pd-summary-item">İlpen XML: `Urun` node path</div>
                <div class="pd-summary-item">Yeni Nesil XML/JSON: `urunler` listesi</div>
            </div>
        </div>
    </div>
</div>
@endsection
