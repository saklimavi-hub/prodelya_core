@extends('layouts.prodelya-admin')

@section('title', 'Yeni Global Tedarikçi Kaynağı')

@php
    $profileOptions = collect($sourceProfiles)->mapWithKeys(fn ($profile, $key) => [$key => $profile['display_name'] ?? $key])->all();
    $profileOptions['CUSTOM'] = 'Boş Profil / Manuel Alan Eşleme';
    $selectedProfileKey = old('profile_key', $formDefaults['profile_key'] ?? array_key_first($sourceProfiles));
    $selectedProfileTemplate = $profileTemplates[$selectedProfileKey] ?? null;
    $selectedSourceType = old('source_type', $formDefaults['source_type'] ?? 'xml');
    $supplierLookup = $suppliers->mapWithKeys(fn ($supplier) => [$supplier->code => $supplier->id])->all();
@endphp

@section('content')
<div class="pd-form-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Yeni Global Tedarikçi Kaynağı</h1>
                    <p class="pd-hero-subtitle">Yeni XML, JSON, CSV veya API tedarikçileri için özel ekran yazmadan hazır profil, mevcut profil kopyası veya manuel alan eşleme akışıyla ilerleyin.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Super Admin</span>
                        <span class="pd-badge pd-badge-green">{{ count($profileTemplates) }} hazır profil</span>
                        <span class="pd-badge pd-badge-amber">Liste fiyatı standardı</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Kaynaklara Dön</a>
                </div>
            </div>
        </div>
    </section>

    @if($templateSource)
        <div class="pd-note">
            Kopya başlangıcı seçildi: <strong>{{ $templateSource->source_name }}</strong> / {{ $templateSource->supplier->name }}.
            Bu ekranda bağlantı bilgilerini ve alanları düzenleyerek yeni bir kaynak oluşturabilirsiniz.
        </div>
    @endif

    <form action="{{ route('admin.super.product-data-hub.sources.store') }}" method="POST" id="sourceForm">
        @csrf
        <input type="hidden" name="template_source_id" value="{{ old('template_source_id', $templateSource?->id) }}">

        <div class="pd-card pd-form-card mb-6">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Adım 1 — Kaynak Tipi Seç</h3>
            </div>
            <div class="pd-card-body">
                <div class="pd-template-choice-grid">
                    <div class="pd-template-choice {{ $selectedProfileKey !== 'CUSTOM' && in_array($selectedSourceType, ['xml', 'api'], true) ? 'is-selected' : '' }}">
                        <div class="pd-template-choice-title">Hazır Profil Kullan</div>
                        <div class="pd-template-choice-copy">Bilinen XML/API yapıları için config tabanlı tedarikçi şablonunu seçin.</div>
                    </div>
                    <div class="pd-template-choice {{ $templateSource ? 'is-selected' : '' }}">
                        <div class="pd-template-choice-title">Mevcut Profilden Kopyala</div>
                        <div class="pd-template-choice-copy">Benzer bir tedarikçiden başlayın, sonra URL ve mapping alanlarını güncelleyin.</div>
                    </div>
                    <div class="pd-template-choice {{ $selectedProfileKey === 'CUSTOM' ? 'is-selected' : '' }}">
                        <div class="pd-template-choice-title">Boş Profil / Manuel Alan Eşleme</div>
                        <div class="pd-template-choice-copy">Yeni gelen 5., 6., 7. tedarikçi için profile library dışında manuel başlangıç yapın.</div>
                    </div>
                    <div class="pd-template-choice {{ $selectedSourceType === 'excel' ? 'is-selected' : '' }}">
                        <div class="pd-template-choice-title">CSV / Excel Kaynağı</div>
                        <div class="pd-template-choice-copy">Kolon bazlı içe alma ve manuel eşleme ile ilerleyin.</div>
                    </div>
                    <div class="pd-template-choice {{ $selectedSourceType === 'json' ? 'is-selected' : '' }}">
                        <div class="pd-template-choice-title">JSON / API Kaynağı</div>
                        <div class="pd-template-choice-copy">Items path, header ve auth bilgileriyle API beslemesini yönetin.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card pd-form-card mb-6">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Adım 2 — Hazır Profil Seç</h3>
            </div>
            <div class="pd-card-body">
                <div class="pd-note mb-4">Hazır profil kartları config içindeki profile library’den gelir. Yeni tedarikçi için özel Blade değil, yeni profil veya manuel mapping akışı kullanılır.</div>
                <div class="pd-profile-grid">
                    @foreach($profileTemplates as $profile)
                        <div class="pd-profile-card {{ $selectedProfileKey === $profile['key'] ? 'pd-profile-card-selected' : '' }}">
                            <div class="pd-profile-head">
                                <div>
                                    <h4 class="pd-profile-name">{{ $profile['display_name'] }}</h4>
                                    <div class="pd-profile-sub">{{ $profile['description'] }}</div>
                                </div>
                                <span class="pd-badge pd-badge-blue">{{ $profile['key'] }}</span>
                            </div>
                            <div class="pd-profile-body">
                                <div class="pd-profile-info-grid">
                                    @foreach($profile['supports_text'] as $label => $value)
                                        <div class="pd-profile-info">
                                            <div class="pd-profile-info-label">{{ $label }}</div>
                                            <div class="pd-profile-info-value">{{ $value }}</div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="pd-profile-note-box mt-3">
                                    Desteklenen alan örnekleri:
                                    {{ implode(', ', array_slice($profile['field_counts']['required'], 0, 4)) }}
                                    @if(count($profile['field_counts']['optional']) > 0)
                                        ... +{{ count($profile['field_counts']['optional']) }} opsiyonel alan
                                    @endif
                                </div>

                                <div class="pd-profile-warnings">
                                    <div class="pd-profile-warning {{ $profile['features']['variants'] ? 'pd-profile-warning-ok' : '' }}">
                                        Varyant desteği: {{ $profile['features']['variants'] ? 'Var' : 'Yok / sınırlı' }}
                                    </div>
                                    <div class="pd-profile-warning {{ $profile['features']['warning_flags'] || $profile['features']['net_price_warning'] ? '' : 'pd-profile-warning-ok' }}">
                                        Uyarı desteği: {{ $profile['features']['warning_flags'] || $profile['features']['net_price_warning'] ? 'Özel fiyat / net fiyat işaretleri okunur' : 'Standart uyarı alanı yok' }}
                                    </div>
                                </div>

                                <div class="pd-template-card-actions">
                                    <button
                                        type="button"
                                        class="pd-btn pd-btn-sm pd-btn-primary"
                                        data-profile-select
                                        data-profile-key="{{ $profile['key'] }}"
                                        data-source-type="xml"
                                        data-supplier-id="{{ $supplierLookup[$profile['key']] ?? '' }}"
                                    >
                                        Bu profili kullan
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <div class="pd-profile-card {{ $selectedProfileKey === 'CUSTOM' ? 'pd-profile-card-selected' : '' }}">
                        <div class="pd-profile-head">
                            <div>
                                <h4 class="pd-profile-name">Boş Profil / Manuel Alan Eşleme</h4>
                                <div class="pd-profile-sub">Tamamen yeni XML veya farklı JSON yapıları için node path ve alan eşlemelerini elle başlatın.</div>
                            </div>
                            <span class="pd-badge pd-badge-gray">CUSTOM</span>
                        </div>
                        <div class="pd-profile-body">
                            <div class="pd-profile-note-box">
                                Yeni tedarikçi bilinen profile benzemiyorsa önce boş profil ile başla, mapping oturduğunda profile library’ye ekle.
                            </div>
                            <div class="pd-template-card-actions">
                                <button type="button" class="pd-btn pd-btn-sm pd-btn-light" data-profile-select data-profile-key="CUSTOM" data-source-type="manual">Boş profil ile başla</button>
                            </div>
                        </div>
                    </div>
                </div>

                @if($copyableSources->isNotEmpty())
                    <div class="pd-inline-section mt-5">
                        <div class="pd-inline-section-title">Mevcut Profilden Kopyala</div>
                        <div class="pd-copy-grid">
                            @foreach($copyableSources as $copySource)
                                <a href="{{ route('admin.super.product-data-hub.sources.create', ['template_source_id' => $copySource->id]) }}" class="pd-copy-card {{ $templateSource && $templateSource->id === $copySource->id ? 'is-selected' : '' }}">
                                    <strong>{{ $copySource->source_name }}</strong>
                                    <span>{{ $copySource->supplier->name }}</span>
                                    <span class="pd-muted">{{ $copySource->config['profile_key'] ?? $copySource->supplier->code }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="pd-card pd-form-card mb-6">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Adım 3 — Bağlantı Bilgileri</h3>
            </div>
            <div class="pd-card-body">
                <div class="pd-form-grid-2">
                    <div>
                        <label class="pd-label">Tedarikçi *</label>
                        <select name="supplier_id" id="supplier_id" class="pd-select">
                            <option value="">Yeni tedarikçi için boş bırakın</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" {{ (int) old('supplier_id', $formDefaults['supplier_id'] ?? 0) === $supplier->id ? 'selected' : '' }}>{{ $supplier->name }} ({{ $supplier->code }})</option>
                            @endforeach
                        </select>
                        <div class="pd-profile-note mt-2">Yeni XML geldiğinde mevcut tedarikçi seçebilir veya aşağıdan yeni tedarikçi adı girerek yeni source açabilirsiniz.</div>
                    </div>
                    <div>
                        <label class="pd-label">Kaynak Adı *</label>
                        <input type="text" name="source_name" class="pd-input" value="{{ old('source_name', $formDefaults['source_name'] ?? '') }}" required placeholder="Örn: Etkin XML Feed">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Yeni Tedarikçi Adı</label>
                        <input type="text" name="supplier_name" class="pd-input" value="{{ old('supplier_name') }}" placeholder="Örn: X Tedarikçi">
                    </div>
                    <div>
                        <label class="pd-label">Profil Key</label>
                        <input type="text" class="pd-input" value="{{ old('profile_key', $selectedProfileKey) }}" readonly>
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Kaynak Tipi *</label>
                        <select name="source_type" id="source_type" class="pd-select" required>
                            @foreach(['xml' => 'XML', 'json' => 'JSON', 'csv' => 'CSV', 'api' => 'API', 'excel' => 'Excel', 'manual' => 'Manuel'] as $value => $label)
                                <option value="{{ $value }}" {{ old('source_type', $selectedSourceType) === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Kaynak Profil Şablonu *</label>
                        <select name="profile_key" id="profile_key" class="pd-select">
                            @foreach($profileOptions as $value => $label)
                                <option value="{{ $value }}" {{ old('profile_key', $selectedProfileKey) === $value ? 'selected' : '' }}>{{ $value }} - {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">XML / API URL</label>
                        <input type="url" name="url" class="pd-input" value="{{ old('url', $formDefaults['url'] ?? '') }}" placeholder="https://example.com/feed.xml">
                    </div>
                    <div>
                        <label class="pd-label">Yerel Dosya Yolu</label>
                        <input type="text" name="source_file_path" class="pd-input" value="{{ old('source_file_path', $formDefaults['source_file_path'] ?? '') }}" placeholder="C:\laragon\www\feeds\supplier.xml">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Ürün Node Path / Items Path</label>
                        <input type="text" name="product_node_path" class="pd-input" value="{{ old('product_node_path', $formDefaults['product_node_path'] ?? '') }}" placeholder="RECORD / urunler / Urun">
                    </div>
                    <div>
                        <label class="pd-label">JSON Items Path</label>
                        <input type="text" name="items_path" class="pd-input" value="{{ old('items_path', $formDefaults['items_path'] ?? '') }}" placeholder="items / data / products / urunler">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Durum *</label>
                        <select name="status" class="pd-select" required>
                            <option value="active" {{ old('status', $formDefaults['status'] ?? 'active') === 'active' ? 'selected' : '' }}>Aktif</option>
                            <option value="inactive" {{ old('status', $formDefaults['status'] ?? 'active') === 'inactive' ? 'selected' : '' }}>Pasif</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <details class="pd-card pd-form-card mb-6">
            <summary class="pd-card-header">
                <h3 class="pd-card-title">Adım 4 — Alan Eşleme Önizleme</h3>
                <span class="pd-badge pd-badge-gray">Gelişmiş</span>
            </summary>
            <div class="pd-card-body">
                @if($selectedProfileTemplate)
                    <div class="pd-note mb-4">{{ $selectedProfileTemplate['display_name'] }} profili için otomatik gelen alan grupları. Gerekirse kayıt sonrası field mapping ekranında ince ayar yapılabilir.</div>
                    <div class="pd-mapping-preview-grid">
                        @foreach($selectedProfileTemplate['mapping_groups'] as $groupTitle => $fields)
                            <div class="pd-mapping-preview-card">
                                <div class="pd-mapping-preview-title">{{ $groupTitle }}</div>
                                @if($fields)
                                    <ul class="pd-mapping-preview-list">
                                        @foreach($fields as $field)
                                            <li>{{ $field }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <div class="pd-muted">Bu grupta örnek alan bulunmuyor.</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="pd-note">Boş profil veya manuel mapping seçildi. Kaynak kaydedildikten sonra field mapping ekranında ürün, kategori, varyant, fiyat, stok, görsel ve uyarı alanlarını siz tanımlayacaksınız.</div>
                @endif

                <div class="pd-form-grid-2 mt-5">
                    <div>
                        <label class="pd-label">Tedarikçi Prefix</label>
                        <input type="text" name="supplier_prefix" class="pd-input" value="{{ old('supplier_prefix', $formDefaults['supplier_prefix'] ?? '') }}" placeholder="ET / AK / IL / YN">
                    </div>
                    <div>
                        <label class="pd-label">Format</label>
                        <input type="text" name="format" class="pd-input" value="{{ old('format', $formDefaults['format'] ?? '') }}" placeholder="xml / json / csv">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Ürün Kodu Şablonu</label>
                        <input type="text" name="generated_code_template" class="pd-input" value="{{ old('generated_code_template', $formDefaults['generated_code_template'] ?? '{PREFIX}-{SUPPLIER_PRODUCT_CODE}') }}">
                    </div>
                    <div>
                        <label class="pd-label">Varyasyon Kodu Şablonu</label>
                        <input type="text" name="generated_variant_code_template" class="pd-input" value="{{ old('generated_variant_code_template', $formDefaults['generated_variant_code_template'] ?? '{PREFIX}-{SUPPLIER_GROUP_CODE}-{COLOR}') }}">
                    </div>
                </div>
            </div>
        </details>

        <details class="pd-card pd-form-card mb-6">
            <summary class="pd-card-header">
                <h3 class="pd-card-title">Gelişmiş Bağlantı, Auth ve Sync Policy</h3>
                <span class="pd-badge pd-badge-gray">Varsayılan kapalı</span>
            </summary>
            <div class="pd-card-body">
                <div class="pd-form-grid-2">
                    <div>
                        <label class="pd-label">HTTP Method</label>
                        <select name="http_method" class="pd-select">
                            <option value="GET" {{ old('http_method', $formDefaults['http_method'] ?? 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                            <option value="POST" {{ old('http_method', $formDefaults['http_method'] ?? 'GET') === 'POST' ? 'selected' : '' }}>POST</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Auth Tipi</label>
                        <select name="auth_type" class="pd-select">
                            <option value="none" {{ old('auth_type', $formDefaults['auth_type'] ?? 'none') === 'none' ? 'selected' : '' }}>Yok</option>
                            <option value="basic" {{ old('auth_type', $formDefaults['auth_type'] ?? 'none') === 'basic' ? 'selected' : '' }}>Basic Auth</option>
                            <option value="bearer" {{ old('auth_type', $formDefaults['auth_type'] ?? 'none') === 'bearer' ? 'selected' : '' }}>Bearer Token</option>
                            <option value="api_key" {{ old('auth_type', $formDefaults['auth_type'] ?? 'none') === 'api_key' ? 'selected' : '' }}>API Key</option>
                        </select>
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Basic Kullanıcı Adı</label>
                        <input type="text" name="auth_username" class="pd-input" value="{{ old('auth_username', $formDefaults['auth_username'] ?? '') }}">
                    </div>
                    <div>
                        <label class="pd-label">Basic Şifre</label>
                        <input type="password" name="auth_password" class="pd-input" value="">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Bearer Token</label>
                        <input type="password" name="auth_token" class="pd-input" value="">
                    </div>
                    <div>
                        <label class="pd-label">API Key Header / Parametre Adı</label>
                        <input type="text" name="api_key_name" class="pd-input" value="{{ old('api_key_name', $formDefaults['api_key_name'] ?? 'X-API-KEY') }}">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">API Key Değeri</label>
                        <input type="password" name="api_key_value" class="pd-input" value="">
                    </div>
                    <div>
                        <label class="pd-label">Eski API Key Placeholder</label>
                        <input type="text" name="api_key" class="pd-input" value="{{ old('api_key') }}">
                    </div>
                </div>

                <div class="mt-4">
                    <label class="pd-label">Header JSON</label>
                    <textarea name="request_headers" class="pd-textarea" rows="3" placeholder='{"Accept":"application/xml"}'>{{ old('request_headers', $formDefaults['request_headers'] ?? '') }}</textarea>
                </div>

                <div class="mt-4">
                    <label class="pd-label">Request Body</label>
                    <textarea name="request_body" class="pd-textarea" rows="3" placeholder='{"token":"ornek"}'>{{ old('request_body', $formDefaults['request_body'] ?? '') }}</textarea>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-checkbox">
                            <input type="checkbox" name="ip_whitelist_required" value="1" {{ old('ip_whitelist_required', $formDefaults['ip_whitelist_required'] ?? false) ? 'checked' : '' }}>
                            IP izni gerekiyor mu?
                        </label>
                    </div>
                    <div>
                        <label class="pd-label">Proxy Stratejisi</label>
                        <select name="proxy_strategy" class="pd-select">
                            <option value="none" {{ old('proxy_strategy', $formDefaults['proxy_strategy'] ?? 'none') === 'none' ? 'selected' : '' }}>Kullanma</option>
                            <option value="approved_server" {{ old('proxy_strategy', $formDefaults['proxy_strategy'] ?? 'none') === 'approved_server' ? 'selected' : '' }}>Onaylı sunucu IP’si</option>
                            <option value="manual_file_upload" {{ old('proxy_strategy', $formDefaults['proxy_strategy'] ?? 'none') === 'manual_file_upload' ? 'selected' : '' }}>Manuel dosya yükleme</option>
                            <option value="external_worker_placeholder" {{ old('proxy_strategy', $formDefaults['proxy_strategy'] ?? 'none') === 'external_worker_placeholder' ? 'selected' : '' }}>Harici worker placeholder</option>
                        </select>
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-checkbox">
                            <input type="checkbox" name="enrich_gallery_from_product_page" value="1" {{ old('enrich_gallery_from_product_page', $formDefaults['enrich_gallery_from_product_page'] ?? false) ? 'checked' : '' }}>
                            Ürün sayfasından galeri zenginleştirme yap
                        </label>
                    </div>
                    <div>
                        <label class="pd-label">Galeri Selector</label>
                        <input type="text" name="product_page_gallery_selector" class="pd-input" value="{{ old('product_page_gallery_selector', $formDefaults['product_page_gallery_selector'] ?? '') }}" placeholder="img, .gallery img">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Maksimum ürün sayfası kontrolü</label>
                        <input type="number" name="max_gallery_enrichment_products" class="pd-input" value="{{ old('max_gallery_enrichment_products', $formDefaults['max_gallery_enrichment_products'] ?? 5) }}" min="1" max="50">
                    </div>
                    <div>
                        <label class="pd-label">Maksimum galeri görseli</label>
                        <input type="number" name="max_gallery_images" class="pd-input" value="{{ old('max_gallery_images', $formDefaults['max_gallery_images'] ?? 10) }}" min="1" max="50">
                    </div>
                </div>

                <div class="mt-4">
                    <label class="pd-label">Notlar</label>
                    <textarea name="notes" class="pd-textarea" rows="4" placeholder="Kaynak ile ilgili notlar">{{ old('notes', $formDefaults['notes'] ?? '') }}</textarea>
                </div>

                <div class="pd-inline-section mt-5">
                    <div class="pd-inline-section-title">Senkron Politikası</div>
                    <div class="pd-form-grid-2">
                        <div>
                            <label class="pd-label">Güncelleme Sıklığı</label>
                            <select name="sync_frequency" class="pd-select">
                                @foreach(['manual' => 'Manuel', 'hourly' => 'Saatlik', 'daily' => 'Günlük', 'weekly' => 'Haftalık'] as $value => $label)
                                    <option value="{{ $value }}" {{ old('sync_frequency', $formDefaults['sync_frequency'] ?? 'manual') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="pd-label">XML’de Olmayan Ürün Davranışı</label>
                            <select name="missing_product_policy" class="pd-select">
                                <option value="never" {{ old('missing_product_policy', $formDefaults['missing_product_policy'] ?? 'manual_review') === 'never' ? 'selected' : '' }}>Asla pasifleştirme</option>
                                <option value="manual_review" {{ old('missing_product_policy', $formDefaults['missing_product_policy'] ?? 'manual_review') === 'manual_review' ? 'selected' : '' }}>Manuel onayla pasifleştir</option>
                                <option value="inactive_candidate" {{ old('missing_product_policy', $formDefaults['missing_product_policy'] ?? 'manual_review') === 'inactive_candidate' ? 'selected' : '' }}>Pasif adayı yap</option>
                                <option value="auto_inactive" {{ old('missing_product_policy', $formDefaults['missing_product_policy'] ?? 'manual_review') === 'auto_inactive' ? 'selected' : '' }}>Otomatik pasif</option>
                            </select>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-4">
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="update_stock" value="1" {{ old('update_stock', $formDefaults['update_stock'] ?? true) ? 'checked' : '' }}>
                                Stok güncellensin
                            </label>
                        </div>
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="update_price" value="1" {{ old('update_price', $formDefaults['update_price'] ?? true) ? 'checked' : '' }}>
                                Fiyat güncellensin
                            </label>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-3">
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="update_images" value="1" {{ old('update_images', $formDefaults['update_images'] ?? true) ? 'checked' : '' }}>
                                Görseller güncellensin
                            </label>
                        </div>
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="update_categories" value="1" {{ old('update_categories', $formDefaults['update_categories'] ?? true) ? 'checked' : '' }}>
                                Kategoriler güncellensin
                            </label>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-3">
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_auto_build" value="1" {{ old('sync_auto_build', $formDefaults['sync_auto_build'] ?? true) ? 'checked' : '' }}>
                                Sync sonrası standart ürün otomatik güncellensin
                            </label>
                        </div>
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_auto_project_to_tenant_catalog" value="1" {{ old('sync_auto_project_to_tenant_catalog', $formDefaults['sync_auto_project_to_tenant_catalog'] ?? true) ? 'checked' : '' }}>
                                Sync sonrası tenant kataloğa otomatik yansıt
                            </label>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-3">
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_block_on_missing_category" value="1" {{ old('sync_block_on_missing_category', $formDefaults['sync_block_on_missing_category'] ?? true) ? 'checked' : '' }}>
                                Kategori eksik ürünü katalog çıkışında beklet
                            </label>
                        </div>
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_block_on_missing_price" value="1" {{ old('sync_block_on_missing_price', $formDefaults['sync_block_on_missing_price'] ?? false) ? 'checked' : '' }}>
                                Fiyat eksik ürünü katalog çıkışında beklet
                            </label>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-3">
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_block_on_conflict_category" value="1" {{ old('sync_block_on_conflict_category', $formDefaults['sync_block_on_conflict_category'] ?? true) ? 'checked' : '' }}>
                                Conflict kategori ürününü kontrol kuyruğuna al
                            </label>
                        </div>
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_allow_warning_products_to_catalog" value="1" {{ old('sync_allow_warning_products_to_catalog', $formDefaults['sync_allow_warning_products_to_catalog'] ?? true) ? 'checked' : '' }}>
                                Uyarılı ürünleri tenant kataloga uyarıyla geçir
                            </label>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-4">
                        <div>
                            <label class="pd-label">Kaç sync sonra pasif adayı</label>
                            <input type="number" name="missing_product_grace_runs" class="pd-input" min="0" max="50" value="{{ old('missing_product_grace_runs', $formDefaults['missing_product_grace_runs'] ?? 1) }}">
                        </div>
                        <div>
                            <label class="pd-label">Rapor Kanalı</label>
                            <select name="report_channel" class="pd-select">
                                <option value="screen" {{ old('report_channel', $formDefaults['report_channel'] ?? 'screen') === 'screen' ? 'selected' : '' }}>Ekran</option>
                                <option value="email" {{ old('report_channel', $formDefaults['report_channel'] ?? 'screen') === 'email' ? 'selected' : '' }}>E-posta</option>
                                <option value="notification_center" {{ old('report_channel', $formDefaults['report_channel'] ?? 'screen') === 'notification_center' ? 'selected' : '' }}>Bildirim Merkezi</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="pd-checkbox">
                            <input type="checkbox" name="report_enabled" value="1" {{ old('report_enabled', $formDefaults['report_enabled'] ?? true) ? 'checked' : '' }}>
                            Her manuel/zamanlı senkron için rapor oluştur
                        </label>
                    </div>
                </div>

                <div class="pd-note mt-4">
                    Bu adım kaynağı kaydeder. Kaydetme sonrası <strong>Bağlantı Test Et</strong>, <strong>Preview</strong>, ilk 5 ürün ve kategori örnekleri canlı source ekranından kontrol edilir.
                </div>
            </div>
        </details>

        <div class="pd-form-actions-sticky">
            <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">İptal</a>
            <button type="submit" class="pd-btn pd-btn-primary">Kaynağı Kaydet</button>
        </div>
    </form>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Kaynak Profil Şablonu Özeti</div>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Hazır profil</span><strong>{{ count($profileTemplates) }}</strong></div>
            <div class="pd-status-row"><span>Kopyalanabilir kaynak</span><strong>{{ $copyableSources->count() }}</strong></div>
            <div class="pd-status-row"><span>Manual başlangıç</span><strong>CUSTOM</strong></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Yeni tedarikçi geldiğinde</h4>
            <div class="pd-summary-action-list">
                <span class="pd-summary-action"><span>Benzer XML ise mevcut profilden kopyala</span><span class="pd-badge pd-badge-blue">Kopya</span></span>
                <span class="pd-summary-action"><span>Alan adları farklıysa mapping düzenle</span><span class="pd-badge pd-badge-amber">Düzenle</span></span>
                <span class="pd-summary-action"><span>Tamamen yeni yapıysa CUSTOM ile başla</span><span class="pd-badge pd-badge-green">Manual</span></span>
                <span class="pd-summary-action"><span>Başarılı profil tekrar kütüphaneye eklenir</span><span class="pd-badge pd-badge-purple">Library</span></span>
            </div>
        </div>

        <div class="pd-side-note">Bu ekran 4 mevcut XML için özel bir Blade değildir. Hazır profiller config kütüphanesinden gelir ve yeni tedarikçiler aynı akışla tanımlanır.</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-profile-select]').forEach(function (button) {
        button.addEventListener('click', function () {
            var profileSelect = document.getElementById('profile_key');
            var sourceTypeSelect = document.getElementById('source_type');
            var supplierSelect = document.getElementById('supplier_id');

            if (profileSelect && button.dataset.profileKey) {
                profileSelect.value = button.dataset.profileKey;
            }

            if (sourceTypeSelect && button.dataset.sourceType) {
                sourceTypeSelect.value = button.dataset.sourceType;
            }

            if (supplierSelect && button.dataset.supplierId) {
                supplierSelect.value = button.dataset.supplierId;
            }
        });
    });
});
</script>
@endpush
