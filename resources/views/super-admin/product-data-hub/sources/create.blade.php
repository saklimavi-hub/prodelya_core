@extends('layouts.prodelya-admin')

@section('title', 'Yeni Global TedarikÃ§i KaynaÄŸÄ±')

@php
    $profileOptions = collect($sourceProfiles)->mapWithKeys(fn ($profile, $key) => [$key => $profile['display_name'] ?? $key])->all();
    $profileOptions['CUSTOM'] = 'BoÅŸ Profil / Manuel Alan EÅŸleme';
    $oldTemplateKey = old('source_profile_template');
    if (!$oldTemplateKey) {
        $legacyOldProfileKey = old('profile_key');
        $oldTemplateKey = ($legacyOldProfileKey && (array_key_exists($legacyOldProfileKey, $profileTemplates) || $legacyOldProfileKey === 'CUSTOM'))
            ? $legacyOldProfileKey
            : null;
    }
    $selectedProfileTemplateKey = $oldTemplateKey ?? $formDefaults['source_profile_template'] ?? array_key_first($sourceProfiles);
    $selectedProfileTemplate = $profileTemplates[$selectedProfileTemplateKey] ?? null;
    $selectedProfileKey = old('profile_key', $formDefaults['profile_key'] ?? ($selectedProfileTemplate['profile_identity_key'] ?? $selectedProfileTemplateKey));
    $selectedSourceType = old('source_type', $formDefaults['source_type'] ?? ($selectedProfileTemplate['source_type'] ?? 'xml'));
    $supplierLookup = $suppliers->mapWithKeys(fn ($supplier) => [$supplier->code => $supplier->id])->all();
@endphp

@section('content')
<div class="pd-form-shell">
    <section class="pd-card pd-section-card pd-product-hub__setup-flow mb-6">
        <div class="pd-card-body">
            <div class="pd-hub-section-head">
                <div>
                    <div class="pd-hub-section-title">BirleÅŸik Kurulum AkÄ±ÅŸÄ±</div>
                    <div class="pd-hub-section-copy">Yeni kaynak kurulumunda teknik pipeline yerine yalnÄ±z karar adÄ±mlarÄ±nÄ± takip edin. Kaynak aktif olduktan sonra uygun Ã¼rÃ¼nler otomatik senkronizasyonla satÄ±ÅŸ listesine ve teklif Ã¼rÃ¼n seÃ§imine yansÄ±r.</div>
                </div>
            </div>
            <div class="pd-grid pd-grid-3">
                <div class="pd-note"><strong>1. Kaynak Bilgisi</strong><br>Kaynak adÄ±, tedarikÃ§i, format ve baÄŸlantÄ± ayarÄ±nÄ± kaydedin.</div>
                <div class="pd-note"><strong>2. Ã–n Kontrol</strong><br>KaydÄ± oluÅŸturduktan sonra 5-10 Ã¶rnek Ã¼rÃ¼nÃ¼ kontrol edin.</div>
                <div class="pd-note"><strong>3. Alan EÅŸleme</strong><br>Zorunlu alanlarÄ± aynÄ± akÄ±ÅŸta tamamlayÄ±n.</div>
                <div class="pd-note"><strong>4. Ä°lk Kategori EÅŸleme</strong><br>Kaynak kategorilerini Prodelya genel kategorisine baÄŸlayÄ±n.</div>
                <div class="pd-note"><strong>5. Gerekirse Toplu DeÄŸiÅŸtir</strong><br>Benzer kategoriler iÃ§in tek seferde karar verin.</div>
                <div class="pd-note"><strong>6. KaynaÄŸÄ± Aktif Et</strong><br>Bundan sonra sistem normal senkronlarda uygun Ã¼rÃ¼nleri otomatik yansÄ±tÄ±r; yalnÄ±z sorunlu kayÄ±tlar Bekleyen Kontroller alanÄ±na dÃ¼ÅŸer.</div>
            </div>
        </div>
    </section>

    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Yeni HazÄ±r TedarikÃ§i KaynaÄŸÄ±</h1>
                    <p class="pd-hero-subtitle">Ã–nce kaynak bilgisini kaydedin. ArdÄ±ndan Ã¶n kontrol, alan eÅŸleme ve ilk kategori eÅŸleme aynÄ± kurulum zincirinde tamamlanÄ±r; ekstra havuza aktarma veya teklife gÃ¶nderme adÄ±mÄ± beklenmez.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Super Admin</span>
                        <span class="pd-badge pd-badge-green">{{ count($profileTemplates) }} hazÄ±r profil</span>
                        <span class="pd-badge pd-badge-amber">Ã–n kontrol sonrasÄ± eÅŸleme</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Kaynaklara DÃ¶n</a>
                </div>
            </div>
            <div class="pd-note mt-4 pd-product-hub__auto-note">Bu ekranda yalnÄ±z baÅŸlangÄ±Ã§ kaydÄ± oluÅŸturulur. Kaydettikten sonra <strong>Ã–n Kontrol Yap</strong>, <strong>EÅŸlemeyi Kaydet</strong>, <strong>Kategorileri EÅŸle</strong> ve gerekirse <strong>Bekleyen Kontrolleri AÃ§</strong> adÄ±mlarÄ±yla devam edin.</div>
        </div>
    </section>

    @if($templateSource)
        <div class="pd-note">
            Kopya baÅŸlangÄ±cÄ± seÃ§ildi: <strong>{{ $templateSource->source_name }}</strong> / {{ $templateSource->supplier->name }}.
            Bu ekranda baÄŸlantÄ± bilgilerini ve alanlarÄ± dÃ¼zenleyerek yeni bir kaynak oluÅŸturabilirsiniz.
        </div>
    @endif

    <form action="{{ route('admin.super.product-data-hub.sources.store') }}" method="POST" id="sourceForm">
        @csrf
        <input type="hidden" name="template_source_id" value="{{ old('template_source_id', $templateSource?->id) }}">

        <div class="pd-card pd-form-card mb-6">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Kaynak KimliÄŸi</h3>
            </div>
            <div class="pd-card-body">
                <div class="pd-note mb-4">HazÄ±r profil, kopya kaynak veya manuel baÅŸlangÄ±Ã§ yÃ¶ntemini seÃ§in. Bu seÃ§im yalnÄ±z formun baÅŸlangÄ±Ã§ dÃ¼zenini belirler.</div>
                <div class="pd-template-choice-grid">
                    <div class="pd-template-choice {{ $selectedProfileKey !== 'CUSTOM' && in_array($selectedSourceType, ['xml', 'api'], true) ? 'is-selected' : '' }}">
                        <div class="pd-template-choice-title">HazÄ±r Profil Kullan</div>
                        <div class="pd-template-choice-copy">Bilinen XML/API yapÄ±larÄ± iÃ§in config tabanlÄ± tedarikÃ§i ÅŸablonunu seÃ§in.</div>
                    </div>
                    <div class="pd-template-choice {{ $templateSource ? 'is-selected' : '' }}">
                        <div class="pd-template-choice-title">Mevcut Profilden Kopyala</div>
                        <div class="pd-template-choice-copy">Benzer bir tedarikÃ§iden baÅŸlayÄ±n, sonra URL ve mapping alanlarÄ±nÄ± gÃ¼ncelleyin.</div>
                    </div>
                    <div class="pd-template-choice {{ $selectedProfileKey === 'CUSTOM' ? 'is-selected' : '' }}">
                        <div class="pd-template-choice-title">BoÅŸ Profil / Manuel Alan EÅŸleme</div>
                        <div class="pd-template-choice-copy">Yeni gelen 5., 6., 7. tedarikÃ§i iÃ§in profile library dÄ±ÅŸÄ±nda manuel baÅŸlangÄ±Ã§ yapÄ±n.</div>
                    </div>
                    <div class="pd-template-choice {{ $selectedSourceType === 'excel' ? 'is-selected' : '' }}">
                        <div class="pd-template-choice-title">CSV / Excel KaynaÄŸÄ±</div>
                        <div class="pd-template-choice-copy">Kolon bazlÄ± iÃ§e alma ve manuel eÅŸleme ile ilerleyin.</div>
                    </div>
                    <div class="pd-template-choice {{ $selectedSourceType === 'json' ? 'is-selected' : '' }}">
                        <div class="pd-template-choice-title">JSON / API KaynaÄŸÄ±</div>
                        <div class="pd-template-choice-copy">Items path, header ve auth bilgileriyle API beslemesini yÃ¶netin.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card pd-form-card mb-6">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Profil ve Parsing</h3>
            </div>
            <div class="pd-card-body">
                <div class="pd-note mb-4">KaynaÄŸÄ±n veri biÃ§imine en yakÄ±n profili seÃ§in. Kaynaktaki alanlar farklÄ±ysa eÅŸlemeler daha sonra alan eÅŸleme ekranÄ±nda tamamlanÄ±r.</div>
                <div class="pd-profile-grid">
                    @foreach($profileTemplates as $profile)
                        <div class="pd-profile-card {{ $selectedProfileTemplateKey === $profile['key'] ? 'pd-profile-card-selected' : '' }}">
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
                                    Desteklenen alan Ã¶rnekleri:
                                    {{ implode(', ', array_slice($profile['field_counts']['required'], 0, 4)) }}
                                    @if(count($profile['field_counts']['optional']) > 0)
                                        ... +{{ count($profile['field_counts']['optional']) }} opsiyonel alan
                                    @endif
                                </div>

                                <div class="pd-profile-warnings">
                                    <div class="pd-profile-warning {{ $profile['features']['variants'] ? 'pd-profile-warning-ok' : '' }}">
                                        Varyant desteÄŸi: {{ $profile['features']['variants'] ? 'Var' : 'Yok / sÄ±nÄ±rlÄ±' }}
                                    </div>
                                    <div class="pd-profile-warning {{ $profile['features']['warning_flags'] || $profile['features']['net_price_warning'] ? '' : 'pd-profile-warning-ok' }}">
                                        UyarÄ± desteÄŸi: {{ $profile['features']['warning_flags'] || $profile['features']['net_price_warning'] ? 'Ã–zel fiyat / net fiyat iÅŸaretleri okunur' : 'Standart uyarÄ± alanÄ± yok' }}
                                    </div>
                                </div>

                                <div class="pd-template-card-actions">
                                    <button
                                        type="button"
                                        class="pd-btn pd-btn-sm pd-btn-primary"
                                        data-profile-select
                                        data-profile-key="{{ $profile['key'] }}"
                                        data-profile-identity-key="{{ $profile['profile_identity_key'] ?? $profile['key'] }}"
                                        data-source-type="{{ $profile['source_type'] ?? 'xml' }}"
                                        data-supplier-id="{{ $supplierLookup[$profile['profile_identity_key'] ?? $profile['key']] ?? '' }}"
                                        data-source-name="{{ $profile['suggested_source_name'] ?? '' }}"
                                        data-supplier-name="{{ $profile['suggested_supplier_name'] ?? '' }}"
                                        data-source-url="{{ $profile['default_url'] ?? '' }}"
                                        data-format="{{ $profile['source_type'] ?? 'xml' }}"
                                    >
                                        Bu profili kullan
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <div class="pd-profile-card {{ $selectedProfileTemplateKey === 'CUSTOM' ? 'pd-profile-card-selected' : '' }}">
                        <div class="pd-profile-head">
                            <div>
                                <h4 class="pd-profile-name">BoÅŸ Profil / Manuel Alan EÅŸleme</h4>
                                <div class="pd-profile-sub">Tamamen yeni XML veya farklÄ± JSON yapÄ±larÄ± iÃ§in node path ve alan eÅŸlemelerini elle baÅŸlatÄ±n.</div>
                            </div>
                            <span class="pd-badge pd-badge-gray">CUSTOM</span>
                        </div>
                        <div class="pd-profile-body">
                            <div class="pd-profile-note-box">
                                Yeni tedarikÃ§i bilinen profile benzemiyorsa Ã¶nce boÅŸ profil ile baÅŸla, mapping oturduÄŸunda profile libraryâ€™ye ekle.
                            </div>
                            <div class="pd-template-card-actions">
                                <button type="button" class="pd-btn pd-btn-sm pd-btn-light" data-profile-select data-profile-key="CUSTOM" data-profile-identity-key="CUSTOM" data-source-type="manual" data-format="manual">BoÅŸ profil ile baÅŸla</button>
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
                <h3 class="pd-card-title">BaÄŸlantÄ± ve GÃ¼venlik</h3>
            </div>
            <div class="pd-card-body">
                <div class="pd-note mb-4">Kaynak adÄ±, tedarikÃ§i, URL veya dosya yolu ve temel eriÅŸim bilgilerini burada tanÄ±mlayÄ±n.</div>
                <div class="pd-form-grid-2">
                    <div>
                        <label class="pd-label">TedarikÃ§i *</label>
                        <select name="supplier_id" id="supplier_id" class="pd-select">
                            <option value="">Yeni tedarikÃ§i iÃ§in boÅŸ bÄ±rakÄ±n</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" {{ (int) old('supplier_id', $formDefaults['supplier_id'] ?? 0) === $supplier->id ? 'selected' : '' }}>{{ $supplier->name }} ({{ $supplier->code }})</option>
                            @endforeach
                        </select>
                        <div class="pd-profile-note mt-2">Yeni XML geldiÄŸinde mevcut tedarikÃ§i seÃ§ebilir veya aÅŸaÄŸÄ±dan yeni tedarikÃ§i adÄ± girerek yeni source aÃ§abilirsiniz.</div>
                    </div>
                    <div>
                        <label class="pd-label">Kaynak AdÄ± *</label>
                        <input type="text" name="source_name" id="source_name" class="pd-input" value="{{ old('source_name', $formDefaults['source_name'] ?? '') }}" required placeholder="Ã–rn: Etkin XML Feed">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Yeni TedarikÃ§i AdÄ±</label>
                        <input type="text" name="supplier_name" id="supplier_name" class="pd-input" value="{{ old('supplier_name') }}" placeholder="Ã–rn: X TedarikÃ§i">
                    </div>
                    <div>
                        <label class="pd-label">Profil Key</label>
                        <input type="text" name="profile_key" id="profile_key" class="pd-input" value="{{ old('profile_key', $selectedProfileKey) }}" readonly>
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
                        <label class="pd-label">Kaynak Profil Åablonu *</label>
                        <select name="source_profile_template" id="source_profile_template" class="pd-select">
                            @foreach($profileOptions as $value => $label)
                                <option value="{{ $value }}" {{ $selectedProfileTemplateKey === $value ? 'selected' : '' }}>{{ $value }} - {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">XML / API URL</label>
                        <input type="url" name="url" id="url" class="pd-input" value="{{ old('url', $formDefaults['url'] ?? '') }}" placeholder="https://example.com/feed.xml">
                    </div>
                    <div>
                        <label class="pd-label">Yerel Dosya Yolu</label>
                        <input type="text" name="source_file_path" class="pd-input" value="{{ old('source_file_path', $formDefaults['source_file_path'] ?? '') }}" placeholder="C:\laragon\www\feeds\supplier.xml">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">ÃœrÃ¼n Node Path / Items Path</label>
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

        <div class="pd-card pd-form-card mb-6">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Kaydetme SonrasÄ± Ana Aksiyonlar</h3>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-3">
                    <div class="pd-note"><strong>Ã–n Kontrol Yap</strong><br>5-10 Ã¶rnek Ã¼rÃ¼n Ã¼zerinden Ã¼rÃ¼n adÄ±, kod, fiyat, stok, gÃ¶rsel ve varyant okumasÄ±nÄ± doÄŸrulayÄ±n.</div>
                    <div class="pd-note"><strong>EÅŸlemeyi Kaydet</strong><br>ÃœrÃ¼n kodu, Ã¼rÃ¼n adÄ±, kategori, fiyat, stok, gÃ¶rsel ve aÃ§Ä±klama alanlarÄ±nÄ± netleÅŸtirin.</div>
                    <div class="pd-note"><strong>Kategorileri EÅŸle</strong><br>EÅŸlenmeyen kategoriler satÄ±ÅŸ listesine otomatik aÃ§Ä±lmaz; Bekleyen Kontroller alanÄ±na dÃ¼ÅŸer.</div>
                </div>
                <div class="pd-note mt-4">Kaynak hazÄ±r olduktan sonra uygun Ã¼rÃ¼nler Abone Firma Ã¼rÃ¼n listesine ve teklif/sipariÅŸ Ã¼rÃ¼n seÃ§imine otomatik yansÄ±r. Ekstra â€œkataloÄŸa aktarâ€ veya â€œteklife aÃ§â€ adÄ±mÄ± normal kullanÄ±mda gerekmez.</div>
            </div>
        </div>

        <details class="pd-card pd-form-card mb-6">
            <summary class="pd-card-header">
                <h3 class="pd-card-title">Ã–nizleme ve Alan EÅŸleme HazÄ±rlÄ±ÄŸÄ±</h3>
                <span class="pd-badge pd-badge-gray">GeliÅŸmiÅŸ</span>
            </summary>
            <div class="pd-card-body">
                <div class="pd-note mb-4">Bu bÃ¶lÃ¼m yalnÄ±z hazÄ±r profilin hangi alanlarÄ± beklediÄŸini gÃ¶sterir. Kesin eÅŸleme kaydÄ± kaynak oluÅŸturulduktan sonra yapÄ±lÄ±r.</div>
                @if($selectedProfileTemplate)
                    <div class="pd-note mb-4">{{ $selectedProfileTemplate['display_name'] }} profili iÃ§in otomatik gelen alan gruplarÄ±. Gerekirse kayÄ±t sonrasÄ± field mapping ekranÄ±nda ince ayar yapÄ±labilir.</div>
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
                                    <div class="pd-muted">Bu grupta Ã¶rnek alan bulunmuyor.</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="pd-note">BoÅŸ profil veya manuel mapping seÃ§ildi. Kaynak kaydedildikten sonra field mapping ekranÄ±nda Ã¼rÃ¼n, kategori, varyant, fiyat, stok, gÃ¶rsel ve uyarÄ± alanlarÄ±nÄ± siz tanÄ±mlayacaksÄ±nÄ±z.</div>
                @endif

                <div class="pd-form-grid-2 mt-5">
                    <div>
                        <label class="pd-label">TedarikÃ§i Prefix</label>
                        <input type="text" name="supplier_prefix" class="pd-input" value="{{ old('supplier_prefix', $formDefaults['supplier_prefix'] ?? '') }}" placeholder="ET / AK / IL / YN">
                    </div>
                    <div>
                        <label class="pd-label">Format</label>
                        <input type="text" name="format" id="format" class="pd-input" value="{{ old('format', $formDefaults['format'] ?? '') }}" placeholder="xml / json / csv">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">ÃœrÃ¼n Kodu Åablonu</label>
                        <input type="text" name="generated_code_template" class="pd-input" value="{{ old('generated_code_template', $formDefaults['generated_code_template'] ?? '{PREFIX}-{SUPPLIER_PRODUCT_CODE}') }}">
                    </div>
                    <div>
                        <label class="pd-label">Varyasyon Kodu Åablonu</label>
                        <input type="text" name="generated_variant_code_template" class="pd-input" value="{{ old('generated_variant_code_template', $formDefaults['generated_variant_code_template'] ?? '{PREFIX}-{SUPPLIER_GROUP_CODE}-{COLOR}') }}">
                    </div>
                </div>
            </div>
        </details>

        <details class="pd-card pd-form-card mb-6">
            <summary class="pd-card-header">
                <h3 class="pd-card-title">GeliÅŸmiÅŸ BaÄŸlantÄ±, Auth ve Sync Policy</h3>
                <span class="pd-badge pd-badge-gray">VarsayÄ±lan kapalÄ±</span>
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
                        <label class="pd-label">HTTP User-Agent</label>
                        <input type="text" name="user_agent" class="pd-input" value="{{ old('user_agent', $formDefaults['user_agent'] ?? '') }}" placeholder="prodelya.com">
                        <div class="pd-profile-note mt-2">BazÄ± tedarikÃ§iler XML/API eriÅŸimi iÃ§in User-Agent zorunlu tutar. Yeni Nesil gibi kaynaklarda bu alana web site adresinizi veya platform adresinizi yazÄ±n. Ornek: prodelya.com</div>
                    </div>
                    <div>
                        <label class="pd-label">BaÄŸlantÄ± Zaman AÅŸÄ±mÄ± (sn)</label>
                        <input type="number" name="timeout_seconds" class="pd-input" value="{{ old('timeout_seconds', $formDefaults['timeout_seconds'] ?? 25) }}" min="1" max="120">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Basic KullanÄ±cÄ± AdÄ±</label>
                        <input type="text" name="auth_username" class="pd-input" value="{{ old('auth_username', $formDefaults['auth_username'] ?? '') }}">
                    </div>
                    <div>
                        <label class="pd-label">Basic Åifre</label>
                        <input type="password" name="auth_password" class="pd-input" value="">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Bearer Token</label>
                        <input type="password" name="auth_token" class="pd-input" value="">
                    </div>
                    <div>
                        <label class="pd-label">API Key Header / Parametre AdÄ±</label>
                        <input type="text" name="api_key_name" class="pd-input" value="{{ old('api_key_name', $formDefaults['api_key_name'] ?? 'X-API-KEY') }}">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">API Key DeÄŸeri</label>
                        <input type="password" name="api_key_value" class="pd-input" value="">
                    </div>
                    <div>
                        <label class="pd-label">Eski API Key Placeholder</label>
                        <input type="text" name="api_key" class="pd-input" value="{{ old('api_key') }}">
                    </div>
                </div>

                <div class="mt-4">
                    <label class="pd-label">Ozel HTTP Header'lari</label>
                    <textarea name="request_headers" class="pd-textarea" rows="3" placeholder='{"Accept":"application/xml"}'>{{ old('request_headers', $formDefaults['request_headers_display'] ?? $formDefaults['request_headers'] ?? '') }}</textarea>
                    <div class="pd-profile-note mt-2">User-Agent alani doluysa burada tanimli User-Agent header'i yerine bu deger kullanilir. Diger header'lar korunur.</div>
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
                            <option value="approved_server" {{ old('proxy_strategy', $formDefaults['proxy_strategy'] ?? 'none') === 'approved_server' ? 'selected' : '' }}>OnaylÄ± sunucu IPâ€™si</option>
                            <option value="manual_file_upload" {{ old('proxy_strategy', $formDefaults['proxy_strategy'] ?? 'none') === 'manual_file_upload' ? 'selected' : '' }}>Manuel dosya yÃ¼kleme</option>
                            <option value="external_worker_placeholder" {{ old('proxy_strategy', $formDefaults['proxy_strategy'] ?? 'none') === 'external_worker_placeholder' ? 'selected' : '' }}>Harici worker placeholder</option>
                        </select>
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-checkbox">
                            <input type="checkbox" name="enrich_gallery_from_product_page" value="1" {{ old('enrich_gallery_from_product_page', $formDefaults['enrich_gallery_from_product_page'] ?? false) ? 'checked' : '' }}>
                            ÃœrÃ¼n sayfasÄ±ndan galeri zenginleÅŸtirme yap
                        </label>
                    </div>
                    <div>
                        <label class="pd-label">Galeri Selector</label>
                        <input type="text" name="product_page_gallery_selector" class="pd-input" value="{{ old('product_page_gallery_selector', $formDefaults['product_page_gallery_selector'] ?? '') }}" placeholder="img, .gallery img">
                    </div>
                </div>

                <div class="pd-form-grid-2 mt-4">
                    <div>
                        <label class="pd-label">Maksimum Ã¼rÃ¼n sayfasÄ± kontrolÃ¼</label>
                        <input type="number" name="max_gallery_enrichment_products" class="pd-input" value="{{ old('max_gallery_enrichment_products', $formDefaults['max_gallery_enrichment_products'] ?? 5) }}" min="1" max="50">
                    </div>
                    <div>
                        <label class="pd-label">Maksimum galeri gÃ¶rseli</label>
                        <input type="number" name="max_gallery_images" class="pd-input" value="{{ old('max_gallery_images', $formDefaults['max_gallery_images'] ?? 10) }}" min="1" max="50">
                    </div>
                </div>

                <div class="mt-4">
                    <label class="pd-label">Notlar</label>
                    <textarea name="notes" class="pd-textarea" rows="4" placeholder="Kaynak ile ilgili notlar">{{ old('notes', $formDefaults['notes'] ?? '') }}</textarea>
                </div>

                <div class="pd-inline-section mt-5">
                    <div class="pd-inline-section-title">Sync DavranÄ±ÅŸÄ±</div>
                    <div class="pd-form-grid-2">
                        <div>
                            <label class="pd-label">GÃ¼ncelleme SÄ±klÄ±ÄŸÄ±</label>
                            <select name="sync_frequency" class="pd-select">
                                @foreach(['manual' => 'Manuel', 'hourly' => 'Saatlik', 'daily' => 'GÃ¼nlÃ¼k', 'weekly' => 'HaftalÄ±k'] as $value => $label)
                                    <option value="{{ $value }}" {{ old('sync_frequency', $formDefaults['sync_frequency'] ?? 'manual') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="pd-label">XMLâ€™de Olmayan ÃœrÃ¼n DavranÄ±ÅŸÄ±</label>
                            <select name="missing_product_policy" class="pd-select">
                                <option value="never" {{ old('missing_product_policy', $formDefaults['missing_product_policy'] ?? 'manual_review') === 'never' ? 'selected' : '' }}>Asla pasifleÅŸtirme</option>
                                <option value="manual_review" {{ old('missing_product_policy', $formDefaults['missing_product_policy'] ?? 'manual_review') === 'manual_review' ? 'selected' : '' }}>Manuel onayla pasifleÅŸtir</option>
                                <option value="inactive_candidate" {{ old('missing_product_policy', $formDefaults['missing_product_policy'] ?? 'manual_review') === 'inactive_candidate' ? 'selected' : '' }}>Pasif adayÄ± yap</option>
                                <option value="auto_inactive" {{ old('missing_product_policy', $formDefaults['missing_product_policy'] ?? 'manual_review') === 'auto_inactive' ? 'selected' : '' }}>Otomatik pasif</option>
                            </select>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-4">
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="update_stock" value="1" {{ old('update_stock', $formDefaults['update_stock'] ?? true) ? 'checked' : '' }}>
                                Stok gÃ¼ncellensin
                            </label>
                        </div>
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="update_price" value="1" {{ old('update_price', $formDefaults['update_price'] ?? true) ? 'checked' : '' }}>
                                Fiyat gÃ¼ncellensin
                            </label>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-3">
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="update_images" value="1" {{ old('update_images', $formDefaults['update_images'] ?? true) ? 'checked' : '' }}>
                                GÃ¶rseller gÃ¼ncellensin
                            </label>
                        </div>
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="update_categories" value="1" {{ old('update_categories', $formDefaults['update_categories'] ?? true) ? 'checked' : '' }}>
                                Kategoriler gÃ¼ncellensin
                            </label>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-3">
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_auto_build" value="1" {{ old('sync_auto_build', $formDefaults['sync_auto_build'] ?? true) ? 'checked' : '' }}>
                                Sync sonrasÄ± standart Ã¼rÃ¼n otomatik gÃ¼ncellensin
                            </label>
                        </div>
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_auto_project_to_tenant_catalog" value="1" {{ old('sync_auto_project_to_tenant_catalog', $formDefaults['sync_auto_project_to_tenant_catalog'] ?? true) ? 'checked' : '' }}>
                                Sync sonrasÄ± Abone Firma kataloÄŸuna otomatik yansÄ±t
                            </label>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-3">
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_block_on_missing_category" value="1" {{ old('sync_block_on_missing_category', $formDefaults['sync_block_on_missing_category'] ?? true) ? 'checked' : '' }}>
                                Kategori eksik Ã¼rÃ¼nÃ¼ katalog Ã§Ä±kÄ±ÅŸÄ±nda beklet
                            </label>
                        </div>
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_block_on_missing_price" value="1" {{ old('sync_block_on_missing_price', $formDefaults['sync_block_on_missing_price'] ?? false) ? 'checked' : '' }}>
                                Fiyat eksik Ã¼rÃ¼nÃ¼ katalog Ã§Ä±kÄ±ÅŸÄ±nda beklet
                            </label>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-3">
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_block_on_conflict_category" value="1" {{ old('sync_block_on_conflict_category', $formDefaults['sync_block_on_conflict_category'] ?? true) ? 'checked' : '' }}>
                                Conflict kategori Ã¼rÃ¼nÃ¼nÃ¼ kontrol kuyruÄŸuna al
                            </label>
                        </div>
                        <div>
                            <label class="pd-checkbox">
                                <input type="checkbox" name="sync_allow_warning_products_to_catalog" value="1" {{ old('sync_allow_warning_products_to_catalog', $formDefaults['sync_allow_warning_products_to_catalog'] ?? true) ? 'checked' : '' }}>
                                UyarÄ±lÄ± Ã¼rÃ¼nleri Abone Firma kataloÄŸuna uyarÄ±yla geÃ§ir
                            </label>
                        </div>
                    </div>

                    <div class="pd-form-grid-2 mt-4">
                        <div>
                            <label class="pd-label">KaÃ§ sync sonra pasif adayÄ±</label>
                            <input type="number" name="missing_product_grace_runs" class="pd-input" min="0" max="50" value="{{ old('missing_product_grace_runs', $formDefaults['missing_product_grace_runs'] ?? 1) }}">
                        </div>
                        <div>
                            <label class="pd-label">Rapor KanalÄ±</label>
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
                            Her manuel/zamanlÄ± senkron iÃ§in rapor oluÅŸtur
                        </label>
                    </div>
                </div>

                <div class="pd-note mt-4">
                    Bu adÄ±m kaynaÄŸÄ± kaydeder. Kaydetme sonrasÄ± <strong>BaÄŸlantÄ± Test Et</strong>, <strong>Preview</strong>, ilk 5 Ã¼rÃ¼n ve kategori Ã¶rnekleri canlÄ± source ekranÄ±ndan kontrol edilir.
                </div>
            </div>
        </details>

        <div class="pd-form-actions-sticky">
            <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Ä°ptal</a>
            <button type="submit" class="pd-btn pd-btn-primary">KaynaÄŸÄ± Kaydet</button>
        </div>
    </form>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Kaynak Profil Åablonu Ã–zeti</div>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>HazÄ±r profil</span><strong>{{ count($profileTemplates) }}</strong></div>
            <div class="pd-status-row"><span>Kopyalanabilir kaynak</span><strong>{{ $copyableSources->count() }}</strong></div>
            <div class="pd-status-row"><span>Manual baÅŸlangÄ±Ã§</span><strong>CUSTOM</strong></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Yeni tedarikÃ§i geldiÄŸinde</h4>
            <div class="pd-summary-action-list">
                <span class="pd-summary-action"><span>Benzer XML ise mevcut profilden kopyala</span><span class="pd-badge pd-badge-blue">Kopya</span></span>
                <span class="pd-summary-action"><span>Alan adlarÄ± farklÄ±ysa mapping dÃ¼zenle</span><span class="pd-badge pd-badge-amber">DÃ¼zenle</span></span>
                <span class="pd-summary-action"><span>Tamamen yeni yapÄ±ysa CUSTOM ile baÅŸla</span><span class="pd-badge pd-badge-green">Manual</span></span>
                <span class="pd-summary-action"><span>BaÅŸarÄ±lÄ± profil tekrar kÃ¼tÃ¼phaneye eklenir</span><span class="pd-badge pd-badge-purple">Library</span></span>
            </div>
        </div>

        <div class="pd-side-note">Bu ekran 4 mevcut XML iÃ§in Ã¶zel bir Blade deÄŸildir. HazÄ±r profiller config kÃ¼tÃ¼phanesinden gelir ve yeni tedarikÃ§iler aynÄ± akÄ±ÅŸla tanÄ±mlanÄ±r.</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-profile-select]').forEach(function (button) {
        button.addEventListener('click', function () {
            var profileSelect = document.getElementById('source_profile_template');
            var profileKeyInput = document.getElementById('profile_key');
            var sourceTypeSelect = document.getElementById('source_type');
            var supplierSelect = document.getElementById('supplier_id');
            var sourceNameInput = document.getElementById('source_name');
            var supplierNameInput = document.getElementById('supplier_name');
            var urlInput = document.getElementById('url');
            var formatInput = document.getElementById('format');

            if (profileSelect && button.dataset.profileKey) {
                profileSelect.value = button.dataset.profileKey;
            }

            if (profileKeyInput && button.dataset.profileIdentityKey) {
                profileKeyInput.value = button.dataset.profileIdentityKey;
            }

            if (sourceTypeSelect && button.dataset.sourceType) {
                sourceTypeSelect.value = button.dataset.sourceType;
            }

            if (supplierSelect) {
                supplierSelect.value = button.dataset.supplierId || '';
            }

            if (sourceNameInput && button.dataset.sourceName) {
                sourceNameInput.value = button.dataset.sourceName;
            }

            if (supplierNameInput) {
                supplierNameInput.value = button.dataset.supplierName || '';
            }

            if (urlInput && button.dataset.sourceUrl) {
                urlInput.value = button.dataset.sourceUrl;
            }

            if (formatInput && button.dataset.format) {
                formatInput.value = button.dataset.format;
            }
        });
    });
});
</script>
@endpush

