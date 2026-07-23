@extends('layouts.prodelya-admin')

@section('title', 'Global Tedarikçi Kaynağını Düzenle')

@php
    $profileOptions = collect($sourceProfiles)->mapWithKeys(fn ($profile, $key) => [$key => $profile['display_name'] ?? $key])->all();
@endphp

@section('content')
<div class="pd-form-shell">
<section class="pd-card pd-section-card pd-product-hub__setup-flow mb-6">
    <div class="pd-card-body">
        <div class="pd-hub-section-head">
            <div>
                <div class="pd-hub-section-title">Kaynak Kurulum Durumu</div>
                <div class="pd-hub-section-copy">Bu ekran kaynak bilgisi ve otomatik güncelleme ayarlarını düzenler. Ön kontrol, alan eşleme ve kategori eşleme bu kaynağın kurulum zincirinin devamıdır.</div>
            </div>
        </div>
        <div class="pd-grid pd-grid-3">
            <div class="pd-note"><strong>1. Kaynak Bilgisi</strong><br>Bağlantı, format ve güncelleme sıklığını doğrulayın.</div>
            <div class="pd-note"><strong>2. Ön Kontrol</strong><br>Örnek ürünleri açıp veri kalitesini yeniden kontrol edin.</div>
            <div class="pd-note"><strong>3. Alan Eşleme</strong><br>Zorunlu alanlar tamam değilse önce onları kapatın.</div>
            <div class="pd-note"><strong>4. İlk Kategori Eşleme</strong><br>Yeni kategori gelirse akış burada karar bekler.</div>
            <div class="pd-note"><strong>5. Bekleyen Kontroller</strong><br>Şüpheli fiyat, eksik kategori ve kaybolan ürünler karar kuyruğuna düşer.</div>
            <div class="pd-note"><strong>6. Otomatik Senkron</strong><br>Kaynak aktif kaldığı sürece uygun ürünler normal akışta otomatik yansır.</div>
        </div>
    </div>
</section>

<section class="pd-hero-card">
    <div class="pd-card-body">
        <div class="pd-hero-main">
            <div class="pd-hero-copy">
                <h1 class="pd-hero-title">Hazır Tedarikçi Kaynağını Düzenle</h1>
                    <p class="pd-hero-subtitle">{{ $source->source_name }} - {{ $source->supplier->name }}. Kaynak aktif kaldığında uygun ürünler Abone Firma ürün listesine ve teklif/sipariş ürün seçimine otomatik yansır; yalnız sorunlu kayıtlar Bekleyen Kontroller alanına düşer.</p>
                <div class="pd-hero-badges">
                    <span class="pd-badge pd-badge-blue">Kaynak düzenleme</span>
                    <span class="pd-badge pd-badge-green">{{ strtoupper($selectedSourceType) }}</span>
                    <span class="pd-badge pd-badge-gray">{{ $source->config['profile_key'] ?? $source->supplier->code }}</span>
                </div>
            </div>
            <div class="pd-hero-actions">
                <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Kaynaklara Dön</a>
                <a href="{{ route('admin.super.product-data-hub.sources.preview', $source) }}" class="pd-btn pd-btn-light">Önizlemeyi Aç</a>
                <form action="{{ route('admin.super.product-data-hub.sources.test', $source) }}" method="POST">
                    @csrf
                    <button type="submit" class="pd-btn pd-btn-primary">Bağlantı Test Et</button>
                </form>
            </div>
            </div>
            <div class="pd-note mt-4 pd-product-hub__auto-note">Ana kullanıcı akışında öne çıkan işlemler: <strong>Ön Kontrol Yap</strong>, <strong>Eşlemeyi Kaydet</strong>, <strong>Kategorileri Eşle</strong>, <strong>Kaynağı Aktif Et</strong>, <strong>Ürünleri Senkronize Et</strong>, <strong>Bekleyen Kontrolleri Aç</strong>.</div>
        </div>
</section>

<div class="pd-note">Bu kaynak Super Admin tarafından yönetilir. Abone Firma bu URL’yi değiştiremez. Abone Firma erişimi Abone Firma Tedarikçi Erişimleri ekranından verilir. Şifre, token ve header değerleri düzenleme ekranında maskeli ve güvenli tutulur.</div>
@if(\Illuminate\Support\Str::startsWith($source->supplier->code, 'TMP-') || \Illuminate\Support\Str::startsWith((string) ($source->config['profile_key'] ?? ''), 'TMP-'))
    <div class="pd-warn mt-3">Bu kaynak geçici/test profilidir. Gerçek tedarikçi profili olarak kullanılmamalıdır.</div>
@endif

<form action="{{ route('admin.super.product-data-hub.sources.update', $source) }}" method="POST" id="sourceForm">
    @csrf
    @method('PUT')

    <div class="pd-card pd-form-card mb-6">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Temel Kaynak Bilgileri</h3><div class="pd-note mt-2">Kaynak Kimliği alanları bu bölümde toplanır.</div>
        </div>
        <div class="pd-card-body">
            <div class="pd-note mb-4">Kaynağın temel kimliği, bağlı tedarikçi ve veri biçimi burada tutulur.</div>
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
                    <input type="text" name="product_node_path" class="pd-input" value="{{ old('product_node_path', $source->config['product_node_path'] ?? '') }}" placeholder="RECORD / urunler / Ürün">
                </div>
                <div>
                    <label class="pd-label">JSON Items Path</label>
                    <input type="text" name="items_path" class="pd-input" value="{{ old('items_path', $source->config['items_path'] ?? '') }}" placeholder="items / data / products / urunler">
                </div>
            </div>
        </div>
    </div>

    <details class="pd-card pd-form-card mb-6">
        <summary class="pd-card-header">
            <h3 class="pd-card-title">Profil ve Format</h3><div class="pd-note mt-2">Profil ve Parsing standardı bu bölümde korunur.</div>
            <span class="pd-badge pd-badge-gray">Gelişmiş</span>
        </summary>
        <div class="pd-card-body">
            <div class="pd-note mb-4">Kod üretim şablonları ve profil detayları burada kalır. Günlük kullanımda yalnız gerektiğinde açılması önerilir.</div>
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
    </details>

    <details class="pd-card pd-form-card mb-6">
        <summary class="pd-card-header">
            <h3 class="pd-card-title">Bağlantı</h3><div class="pd-note mt-2">Bağlantı ve Güvenlik ayarları burada yönetilir.</div>
            <span class="pd-badge pd-badge-gray">Gelişmiş</span>
        </summary>
        <div class="pd-card-body">
            <div class="pd-note mb-4">Bağlantı tipi, yetkilendirme ve erişim güvenliği ayarları burada tutulur.</div>
            <div class="pd-form-grid-2">
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
                    <label class="pd-label">HTTP Method</label>
                    <select name="http_method" class="pd-select">
                        <option value="GET" {{ old('http_method', $source->config['http_method'] ?? 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                        <option value="POST" {{ old('http_method', $source->config['http_method'] ?? 'GET') === 'POST' ? 'selected' : '' }}>POST</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Auth Tipi</label>
                    <select name="auth_type" class="pd-select">
                        <option value="none" {{ old('auth_type', $source->config['auth_type'] ?? 'none') === 'none' ? 'selected' : '' }}>Yok</option>
                        <option value="basic" {{ old('auth_type', $source->config['auth_type'] ?? 'none') === 'basic' ? 'selected' : '' }}>Basic Auth</option>
                        <option value="bearer" {{ old('auth_type', $source->config['auth_type'] ?? 'none') === 'bearer' ? 'selected' : '' }}>Bearer Token</option>
                        <option value="api_key" {{ old('auth_type', $source->config['auth_type'] ?? 'none') === 'api_key' ? 'selected' : '' }}>API Key</option>
                    </select>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">HTTP User-Agent</label>
                    <input type="text" name="user_agent" class="pd-input" value="{{ old('user_agent', $source->config['user_agent'] ?? '') }}" placeholder="prodelya.com">
                    <div class="pd-profile-note mt-2">Bazı tedarikçiler XML/API erişimi için User-Agent zorunlu tutar. Yeni Nesil gibi kaynaklarda bu alana web site adresinizi veya platform adresinizi yazın. Ornek: prodelya.com</div>
                </div>
                <div>
                    <label class="pd-label">Bağlantı Zaman Aşımı (sn)</label>
                    <input type="number" name="timeout_seconds" class="pd-input" value="{{ old('timeout_seconds', $source->config['timeout_seconds'] ?? 25) }}" min="1" max="120">
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Basic Kullanıcı Adı</label>
                    <input type="text" name="auth_username" class="pd-input" value="{{ old('auth_username', $source->config['auth_username'] ?? $source->config['username'] ?? '') }}">
                </div>
                <div>
                    <label class="pd-label">Basic Şifre</label>
                    <input type="password" name="auth_password" class="pd-input" value="" placeholder="Değiştirmek için yeniden girin">
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Bearer Token</label>
                    <input type="password" name="auth_token" class="pd-input" value="" placeholder="Değiştirmek için yeniden girin">
                </div>
                <div>
                    <label class="pd-label">API Key Değeri</label>
                    <input type="password" name="api_key_value" class="pd-input" value="" placeholder="Değiştirmek için yeniden girin">
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">API Key Header/Parametre Adı</label>
                    <input type="text" name="api_key_name" class="pd-input" value="{{ old('api_key_name', $source->config['api_key_name'] ?? 'X-API-KEY') }}">
                </div>
                <div>
                    <label class="pd-label">Eski API Key Placeholder</label>
                    <input type="text" name="api_key" class="pd-input" value="" placeholder="{{ filled($source->config['api_key'] ?? null) ? 'Kayıtlı API key var' : 'Gerekirse eski API key alanı' }}">
                </div>
            </div>

            <div class="mt-4">
                <label class="pd-label">Ozel HTTP Header'lari</label>
                <textarea name="request_headers" class="pd-textarea" rows="3" placeholder='{"Accept":"application/xml","X-Client":"Prodelya"}'>{{ old('request_headers', $source->getAttribute('masked_request_headers_display') ?? '') }}</textarea>
                <div class="pd-profile-note mt-2">User-Agent alani doluysa burada tanimli User-Agent header'i yerine bu deger kullanilir. Diger header'lar korunur.</div>
            </div>

            <div class="mt-4">
                <label class="pd-label">Request Body</label>
                <textarea name="request_body" class="pd-textarea" rows="3" placeholder='{"token":"ornek","format":"xml"}'>{{ old('request_body', $source->config['request_body'] ?? '') }}</textarea>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-checkbox">
                        <input type="checkbox" name="ip_whitelist_required" value="1" {{ old('ip_whitelist_required', $source->config['ip_whitelist_required'] ?? false) ? 'checked' : '' }}>
                        IP izni gerekiyor mu?
                    </label>
                </div>
                <div>
                    <label class="pd-label">Proxy Stratejisi</label>
                    <select name="proxy_strategy" class="pd-select">
                        <option value="none" {{ old('proxy_strategy', $source->config['proxy_strategy'] ?? 'none') === 'none' ? 'selected' : '' }}>Kullanma</option>
                        <option value="approved_server" {{ old('proxy_strategy', $source->config['proxy_strategy'] ?? 'none') === 'approved_server' ? 'selected' : '' }}>Onaylı sunucu IP’si</option>
                        <option value="manual_file_upload" {{ old('proxy_strategy', $source->config['proxy_strategy'] ?? 'none') === 'manual_file_upload' ? 'selected' : '' }}>Manuel dosya yükleme</option>
                        <option value="external_worker_placeholder" {{ old('proxy_strategy', $source->config['proxy_strategy'] ?? 'none') === 'external_worker_placeholder' ? 'selected' : '' }}>Harici worker placeholder</option>
                    </select>
                </div>
            </div>

            <div class="pd-note mt-3">
                Bazı tedarikçiler XML/API erişimini sadece izinli IP adreslerine açar. Bu durumda canlı sunucu IP’si tedarikçiye bildirilmelidir. Local geliştirmede onaylı IP’den indirilen XML dosyası yerel dosya olarak okutulabilir.
            </div>

            <div class="pd-note mt-3">
                Yerel dosya örneği: C:\laragon\www\prodelya_core\storage\app\supplier-feeds\akdeniz.xml
            </div>

            @if(($source->config['profile_key'] ?? $source->supplier->code) === 'AKDENIZ' && (($source->config['ip_whitelist_required'] ?? false) || $source->status === 'error'))
                <div class="pd-warn mt-3">
                    Akdeniz kaynağı IP izinli olabilir. Canlı sistemde sabit sunucu IP’si Akdeniz’e bildirilmelidir. Local geliştirmede onaylı IP’den indirilen XML dosyasını Yerel Dosya Yolu alanına ekleyerek preview alabilirsiniz.
                </div>
            @endif
        </div>
    </details>

    <details class="pd-card pd-form-card mb-6">
        <summary class="pd-card-header">
            <h3 class="pd-card-title">Gelişmiş Teknik Ayarlar</h3><div class="pd-note mt-2">Gelişmiş Ayarlar bu bölümde korunur.</div>
            <span class="pd-badge pd-badge-gray">Gelişmiş</span>
        </summary>
        <div class="pd-card-body">
            <div class="pd-note mb-4">Ek galeri zenginleştirme, ürün sayfası seçicileri ve daha nadir kullanılan teknik ayarlar burada yer alır.</div>
            <label class="pd-checkbox">
                <input type="checkbox" name="enrich_gallery_from_product_page" value="1" {{ old('enrich_gallery_from_product_page', $source->config['enrich_gallery_from_product_page'] ?? false) ? 'checked' : '' }}>
                Ürün sayfasından ek galeri görsellerini çek
            </label>

            <div class="pd-note mt-3">
                Feed içinde tek görsel varsa, product_url üzerinden ürün sayfası açılarak ek galeri görselleri aranır.
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Maksimum ürün sayfası kontrolü</label>
                    <input type="number" name="max_gallery_enrichment_products" class="pd-input" value="{{ old('max_gallery_enrichment_products', $source->config['max_gallery_enrichment_products'] ?? 5) }}" min="1" max="50">
                </div>
                <div>
                    <label class="pd-label">Maksimum galeri görseli</label>
                    <input type="number" name="max_gallery_images" class="pd-input" value="{{ old('max_gallery_images', $source->config['max_gallery_images'] ?? 10) }}" min="1" max="50">
                </div>
            </div>

            <div class="mt-4">
                <label class="pd-label">Galeri Selector / Otomatik</label>
                <input type="text" name="product_page_gallery_selector" class="pd-input" value="{{ old('product_page_gallery_selector', $source->config['product_page_gallery_selector'] ?? '') }}" placeholder="img, .gallery img, .product-images img">
            </div>

            <div class="pd-note mt-3">
                Bu işlem preview sırasında ürün sayfalarına ek HTTP istekleri yapar. Büyük feed’lerde queue/job aşamasında çalıştırılmalıdır.
            </div>
        </div>
    </details>

    <div class="pd-card pd-form-card mb-6">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Notlar</h3>
        </div>
        <div class="pd-card-body">
            <textarea name="notes" class="pd-textarea" rows="4" placeholder="Kaynak ile ilgili notlar">{{ old('notes', $source->config['notes'] ?? '') }}</textarea>
        </div>
    </div>

    <div class="pd-card pd-form-card mb-6">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Kurulum Zincirinde Sonraki Adımlar</h3>
        </div>
        <div class="pd-card-body">
            <div class="pd-grid pd-grid-3">
                <div class="pd-note"><strong>Ön Kontrol Yap</strong><br>Ürün adı, ürün kodu, fiyat, stok, görsel ve varyant örneklerini kontrol edin.</div>
                <div class="pd-note"><strong>Alan Eşleme</strong><br>Zorunlu alanlar eksikse sistem uygun ürünleri otomatik akışa tam açmaz.</div>
                <div class="pd-note"><strong>Kategori Eşleme</strong><br>Eşlenmeyen kategoriler tekrar sorulmadan ilerlemez; Bekleyen Kontroller tarafına düşer.</div>
            </div>
        </div>
    </div>

    <details class="pd-card pd-form-card mb-6">
        <summary class="pd-card-header">
            <h3 class="pd-card-title">Güncelleme Ayarları</h3><div class="pd-note mt-2">Sync Davranışı seçenekleri bu bölümde korunur.</div>
            <span class="pd-badge pd-badge-gray">Gelişmiş</span>
        </summary>
        <div class="pd-card-body">
            <div class="pd-note mb-4">Fiyat, stok, kategori ve katalog yayını davranışlarını bu bölümden yönetin.</div>
            <div class="pd-form-grid-2">
                <div>
                    <label class="pd-label">Güncelleme Sıklığı</label>
                    <select name="sync_frequency" class="pd-select" required>
                        @foreach(['manual' => 'Manuel', 'hourly' => 'Saatlik', 'daily' => 'Günlük', 'weekly' => 'Haftalık'] as $value => $label)
                            <option value="{{ $value }}" {{ old('sync_frequency', data_get($source->config, 'sync_policy.sync_frequency', $source->config['sync_frequency'] ?? 'manual')) === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">XML’de Olmayan Ürün Davranışı</label>
                    <select name="missing_product_policy" class="pd-select">
                        <option value="never" {{ old('missing_product_policy', data_get($source->config, 'sync_policy.missing_product_policy', 'manual_review')) === 'never' ? 'selected' : '' }}>Asla pasifleştirme</option>
                        <option value="manual_review" {{ old('missing_product_policy', data_get($source->config, 'sync_policy.missing_product_policy', 'manual_review')) === 'manual_review' ? 'selected' : '' }}>Manuel onayla pasifleştir</option>
                        <option value="inactive_candidate" {{ old('missing_product_policy', data_get($source->config, 'sync_policy.missing_product_policy', 'manual_review')) === 'inactive_candidate' ? 'selected' : '' }}>Pasif adayı yap</option>
                        <option value="auto_inactive" {{ old('missing_product_policy', data_get($source->config, 'sync_policy.missing_product_policy', 'manual_review')) === 'auto_inactive' ? 'selected' : '' }}>Otomatik pasif</option>
                    </select>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-checkbox"><input type="checkbox" name="update_stock" value="1" {{ old('update_stock', data_get($source->config, 'sync_policy.update_stock', true)) ? 'checked' : '' }}> Stok güncellensin</label>
                </div>
                <div>
                    <label class="pd-checkbox"><input type="checkbox" name="update_price" value="1" {{ old('update_price', data_get($source->config, 'sync_policy.update_price', true)) ? 'checked' : '' }}> Fiyat güncellensin</label>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-3">
                <div>
                    <label class="pd-checkbox"><input type="checkbox" name="update_images" value="1" {{ old('update_images', data_get($source->config, 'sync_policy.update_images', true)) ? 'checked' : '' }}> Görseller güncellensin</label>
                </div>
                <div>
                    <label class="pd-checkbox"><input type="checkbox" name="update_categories" value="1" {{ old('update_categories', data_get($source->config, 'sync_policy.update_categories', true)) ? 'checked' : '' }}> Kategoriler güncellensin</label>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-3">
                <div>
                    <label class="pd-checkbox"><input type="checkbox" name="sync_auto_build" value="1" {{ old('sync_auto_build', data_get($source->config, 'sync_policy.sync_auto_build', $source->config['sync_auto_build'] ?? true)) ? 'checked' : '' }}> Sync sonrası standart ürün otomatik güncellensin</label>
                </div>
                <div>
                    <label class="pd-checkbox"><input type="checkbox" name="sync_auto_project_to_tenant_catalog" value="1" {{ old('sync_auto_project_to_tenant_catalog', data_get($source->config, 'sync_policy.sync_auto_project_to_tenant_catalog', $source->config['sync_auto_project_to_tenant_catalog'] ?? true)) ? 'checked' : '' }}> Sync sonrası Abone Firma kataloğuna otomatik yansıt</label>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-3">
                <div>
                    <label class="pd-checkbox"><input type="checkbox" name="sync_block_on_missing_category" value="1" {{ old('sync_block_on_missing_category', data_get($source->config, 'sync_policy.sync_block_on_missing_category', $source->config['sync_block_on_missing_category'] ?? true)) ? 'checked' : '' }}> Kategori eksik ürünü katalog çıkışında beklet</label>
                </div>
                <div>
                    <label class="pd-checkbox"><input type="checkbox" name="sync_block_on_missing_price" value="1" {{ old('sync_block_on_missing_price', data_get($source->config, 'sync_policy.sync_block_on_missing_price', $source->config['sync_block_on_missing_price'] ?? false)) ? 'checked' : '' }}> Fiyat eksik ürünü katalog çıkışında beklet</label>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-3">
                <div>
                    <label class="pd-checkbox"><input type="checkbox" name="sync_block_on_conflict_category" value="1" {{ old('sync_block_on_conflict_category', data_get($source->config, 'sync_policy.sync_block_on_conflict_category', $source->config['sync_block_on_conflict_category'] ?? true)) ? 'checked' : '' }}> Conflict kategori ürününü kontrol kuyruğuna al</label>
                </div>
                <div>
                    <label class="pd-checkbox"><input type="checkbox" name="sync_allow_warning_products_to_catalog" value="1" {{ old('sync_allow_warning_products_to_catalog', data_get($source->config, 'sync_policy.sync_allow_warning_products_to_catalog', $source->config['sync_allow_warning_products_to_catalog'] ?? true)) ? 'checked' : '' }}> Uyarılı ürünleri Abone Firma kataloğuna uyarıyla geçir</label>
                </div>
            </div>

            <div class="pd-form-grid-2 mt-4">
                <div>
                    <label class="pd-label">Kaç sync sonra pasif adayı</label>
                    <input type="number" name="missing_product_grace_runs" class="pd-input" min="0" max="50" value="{{ old('missing_product_grace_runs', data_get($source->config, 'sync_policy.missing_product_grace_runs', 1)) }}">
                </div>
                <div>
                    <label class="pd-label">Rapor Kanalı</label>
                    <select name="report_channel" class="pd-select">
                        <option value="screen" {{ old('report_channel', data_get($source->config, 'sync_policy.report_channel', 'screen')) === 'screen' ? 'selected' : '' }}>Ekran</option>
                        <option value="email" {{ old('report_channel', data_get($source->config, 'sync_policy.report_channel', 'screen')) === 'email' ? 'selected' : '' }}>E-posta</option>
                        <option value="notification_center" {{ old('report_channel', data_get($source->config, 'sync_policy.report_channel', 'screen')) === 'notification_center' ? 'selected' : '' }}>Bildirim Merkezi</option>
                    </select>
                </div>
            </div>

            <div class="mt-3">
                <label class="pd-checkbox"><input type="checkbox" name="report_enabled" value="1" {{ old('report_enabled', data_get($source->config, 'sync_policy.report_enabled', true)) ? 'checked' : '' }}> Her senkron için rapor oluşturulsun</label>
            </div>
        </div>
    </details>

    <div class="pd-form-actions-sticky">
        <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">İptal</a>
        <button type="submit" class="pd-btn pd-btn-primary">Kaynağı Güncelle</button>
    </div>
</form>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Global Kaynak Özeti</div>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Tedarikçi</span><strong>{{ $source->supplier->name }}</strong></div>
            <div class="pd-status-row"><span>Kaynak tipi</span><strong>{{ strtoupper($selectedSourceType) }}</strong></div>
            <div class="pd-status-row"><span>Profil</span><strong>{{ $source->config['profile_key'] ?? $source->supplier->code }}</strong></div>
            <div class="pd-status-row"><span>Prefix</span><strong>{{ $source->config['supplier_prefix'] ?? '-' }}</strong></div>
        </div>
    </div>
</div>
@endsection
