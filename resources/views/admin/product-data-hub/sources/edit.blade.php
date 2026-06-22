@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Kaynağı Düzenle')

@php
    $profileOptions = collect($sourceProfiles)->mapWithKeys(fn ($profile, $key) => [$key => $profile['display_name'] ?? $key])->all();
@endphp

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Tedarikçi Kaynağı Düzenle</h1>
        <p class="pd-muted mt-1">{{ $source->source_name }} - {{ $source->supplier->name }}</p>
    </div>
    <div class="pd-actions">
        <a href="{{ route('admin.product-data-hub.sources') }}" class="pd-btn pd-btn-light">Kaynaklara Dön</a>
        <a href="{{ route('admin.product-data-hub.sources.preview', $source) }}" class="pd-btn pd-btn-light">Preview Al</a>
        <form action="{{ route('admin.product-data-hub.sources.test', $source) }}" method="POST">
            @csrf
            <button type="submit" class="pd-btn pd-btn-primary">Bağlantı Test Et</button>
        </form>
    </div>
</div>

<div class="pd-note mb-4">Global tedarikçi kaynakları Super Admin tarafından yönetilir. Tenant tarafı yalnız kendisine açılan tedarikçi ürünlerini Gelişmiş Ürün ve Katalog ekranında görür.</div>

<form action="{{ route('admin.product-data-hub.sources.update', $source) }}" method="POST" id="sourceForm">
    @csrf
    @method('PUT')

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
                            <option value="{{ $supplier->id }}" {{ (int) old('supplier_id', $source->supplier_id) === $supplier->id ? 'selected' : '' }}>{{ $supplier->name }} ({{ $supplier->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Kaynak Adı *</label>
                    <input type="text" name="source_name" class="pd-input" value="{{ old('source_name', $source->source_name) }}" required>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Kaynak Tipi *</label>
                    <select name="source_type" class="pd-select" required>
                        @foreach(['xml' => 'XML', 'json' => 'JSON', 'csv' => 'CSV', 'api' => 'API', 'excel' => 'Excel'] as $value => $label)
                            <option value="{{ $value }}" {{ old('source_type', $selectedSourceType) === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Format / Profil *</label>
                    <select name="profile_key" class="pd-select">
                        @foreach($profileOptions as $value => $label)
                            <option value="{{ $value }}" {{ old('profile_key', $source->config['profile_key'] ?? $source->supplier->code) === $value ? 'selected' : '' }}>{{ $value }} - {{ $label }}</option>
                        @endforeach
                        <option value="CUSTOM" {{ old('profile_key', $source->config['profile_key'] ?? '') === 'CUSTOM' ? 'selected' : '' }}>CUSTOM</option>
                    </select>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Kaynak URL</label>
                    <input type="url" name="url" class="pd-input" value="{{ old('url', $source->url) }}" placeholder="https://promosyondunyasi.com/api_urunler.json">
                </div>
                <div>
                    <label class="pd-label">Yerel Dosya Yolu</label>
                    <input type="text" name="source_file_path" class="pd-input" value="{{ old('source_file_path', $source->config['source_file_path'] ?? '') }}" placeholder="C:\laragon\www\feeds\ilpen.xml">
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Ürün Node Path / Items Path</label>
                    <input type="text" name="product_node_path" class="pd-input" value="{{ old('product_node_path', $source->config['product_node_path'] ?? '') }}" placeholder="RECORD / urunler / Urun">
                </div>
                <div>
                    <label class="pd-label">JSON Items Path</label>
                    <input type="text" name="items_path" class="pd-input" value="{{ old('items_path', $source->config['items_path'] ?? '') }}" placeholder="items / data / products / urunler">
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
                    <input type="text" name="supplier_prefix" class="pd-input" value="{{ old('supplier_prefix', $source->config['supplier_prefix'] ?? '') }}" placeholder="ET / AK / IL / YN">
                </div>
                <div>
                    <label class="pd-label">Format</label>
                    <input type="text" name="format" class="pd-input" value="{{ old('format', $source->config['format'] ?? '') }}" placeholder="json / xml / csv">
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Ürün Kodu Şablonu</label>
                    <input type="text" name="generated_code_template" class="pd-input" value="{{ old('generated_code_template', $source->config['generated_code_template'] ?? '{PREFIX}-{SUPPLIER_PRODUCT_CODE}') }}">
                </div>
                <div>
                    <label class="pd-label">Varyasyon Kodu Şablonu</label>
                    <input type="text" name="generated_variant_code_template" class="pd-input" value="{{ old('generated_variant_code_template', $source->config['generated_variant_code_template'] ?? '{PREFIX}-{SUPPLIER_GROUP_CODE}-{COLOR}') }}">
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
                            <option value="{{ $value }}" {{ old('sync_frequency', $source->config['sync_frequency'] ?? 'manual') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Durum *</label>
                    <select name="status" class="pd-select" required>
                        <option value="active" {{ old('status', $source->status) === 'active' ? 'selected' : '' }}>Aktif</option>
                        <option value="inactive" {{ old('status', $source->status) === 'inactive' ? 'selected' : '' }}>Pasif</option>
                        <option value="error" {{ old('status', $source->status) === 'error' ? 'selected' : '' }}>Hatalı</option>
                    </select>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Auth Tipi</label>
                    <select name="auth_type" class="pd-select">
                        <option value="none" {{ old('auth_type', $source->config['auth_type'] ?? 'none') === 'none' ? 'selected' : '' }}>Yok</option>
                        <option value="basic" {{ old('auth_type', $source->config['auth_type'] ?? 'none') === 'basic' ? 'selected' : '' }}>Basic Auth</option>
                        <option value="api_key" {{ old('auth_type', $source->config['auth_type'] ?? 'none') === 'api_key' ? 'selected' : '' }}>API Key</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Kullanıcı Adı</label>
                    <input type="text" name="username" class="pd-input" value="{{ old('username', $source->config['username'] ?? '') }}">
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Şifre</label>
                    <input type="password" name="password" class="pd-input" value="{{ old('password', $source->config['password'] ?? '') }}">
                </div>
                <div>
                    <label class="pd-label">API Key</label>
                    <input type="text" name="api_key" class="pd-input" value="{{ old('api_key', $source->config['api_key'] ?? '') }}">
                </div>
            </div>
        </div>
    </div>

    <div class="pd-card mb-6">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Notlar</h3>
        </div>
        <div class="pd-card-body">
            <textarea name="notes" class="pd-textarea" rows="4" placeholder="Kaynak ile ilgili notlar">{{ old('notes', $source->config['notes'] ?? '') }}</textarea>
        </div>
    </div>

    <div class="pd-actions">
        <a href="{{ route('admin.product-data-hub.sources') }}" class="pd-btn pd-btn-light">İptal</a>
        <button type="submit" class="pd-btn pd-btn-primary">Kaynağı Güncelle</button>
    </div>
</form>
@endsection

@section('summary')
<div class="pd-card">
    <div class="pd-card-body">
        <div class="pd-summary-title">Kaynak Özeti</div>

        <div class="pd-summary-section">
            <div class="pd-summary-row"><span>Tedarikçi</span><strong>{{ $source->supplier->name }}</strong></div>
            <div class="pd-summary-row"><span>Kaynak tipi</span><strong>{{ strtoupper($selectedSourceType) }}</strong></div>
            <div class="pd-summary-row"><span>Profil</span><strong>{{ $source->config['profile_key'] ?? $source->supplier->code }}</strong></div>
            <div class="pd-summary-row"><span>Prefix</span><strong>{{ $source->config['supplier_prefix'] ?? '-' }}</strong></div>
        </div>

        <div class="pd-summary-section">
            <div class="pd-summary-list">
                <div class="pd-summary-item">Bu kaynak Super Admin tarafından yönetilir.</div>
                <div class="pd-summary-item">Tenant bu URL’yi değiştiremez.</div>
                <div class="pd-summary-item">Preview ve test butonlarıyla akışı doğrulayın.</div>
            </div>
        </div>
    </div>
</div>
@endsection
