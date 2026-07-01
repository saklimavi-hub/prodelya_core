@extends('layouts.prodelya-admin')

@section('title', 'Abone Firma Operasyon Merkezi')
@section('page_title', 'Abone Firma Düzenle')
@section('page_subtitle', 'Cari kimlik, panel, abonelik, iletişim-belge ve override alanlarını tek operasyon formunda yönetin.')

@php
    $effectiveState = old('status', $lifecycleSettings['effective_state'] ?? ($tenant->status === 'inactive' ? 'passive' : $tenant->status));
@endphp

@section('content')
<div class="pd-hub-family-shell pd-tenant-hub-shell">
    @include('super-admin.tenants._overview')

    @if(session('success'))
        <div class="pd-alert pd-alert-success pd-gap-bottom-md">{{ session('success') }}</div>
    @endif

    @if(session('owner_temporary_password'))
        <div class="pd-alert pd-alert-warning pd-gap-bottom-md">
            Geçici giriş bilgisi güvenlik nedeniyle gösterilmez.
        </div>
    @endif

    @if(!$tenantHasUsers)
        <div class="pd-alert pd-alert-warning pd-gap-bottom-md">
            Owner kullanıcı henüz oluşturulmadı. Bu tenant için kullanıcı onboarding ve başlangıç ayarları sonraki adımda tamamlanmalıdır.
        </div>
    @endif

    <section class="pd-section-card pd-section-card-soft-blue pd-gap-bottom-md">
        <div class="pd-section-body">
            <div class="pd-tenant-edit-nav">
                <a href="#cari-kimlik" class="pd-btn pd-btn-light">Genel / Cari Kimlik</a>
                <a href="#panel-domain" class="pd-btn pd-btn-light">Panel ve Domain</a>
                <a href="#abonelik-durumu" class="pd-btn pd-btn-light">Abonelik Durumu</a>
                <a href="#iletisim-belge" class="pd-btn pd-btn-light">İletişim ve Belge</a>
                <a href="#saas-cari" class="pd-btn pd-btn-light">SaaS Cari</a>
                <a href="#modul-limit" class="pd-btn pd-btn-light">Modül / Limit</a>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate pd-gap-bottom-md">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Owner Durumu</h3>
                <p class="pd-section-subtitle">Abone Firma operasyonu mümkün olduğunca owner ve tenant ekipleri üzerinden yürütülmelidir.</p>
            </div>
            <div class="pd-hero-actions">
                @if(!$ownerExists)
                    <a href="{{ route('admin.super.tenants.owner.create', $tenant) }}" class="pd-btn pd-btn-primary">Owner Oluştur</a>
                @endif
            </div>
        </div>
        <div class="pd-section-body">
            @if($ownerExists)
                <div class="pd-tenant-info-grid">
                    <div class="pd-tenant-info-card"><div class="pd-tenant-info-label">Ad Soyad</div><div class="pd-tenant-info-value">{{ $ownerUser->name }}</div></div>
                    <div class="pd-tenant-info-card"><div class="pd-tenant-info-label">E-posta</div><div class="pd-tenant-info-value">{{ $ownerUser->email }}</div></div>
                    <div class="pd-tenant-info-card"><div class="pd-tenant-info-label">Rol</div><div class="pd-tenant-info-value">{{ $ownerRole?->name ?: 'Abone Firma Owner' }}</div></div>
                    <div class="pd-tenant-info-card"><div class="pd-tenant-info-label">Son Giriş</div><div class="pd-tenant-info-value">{{ $ownerUser->last_login_at?->format('d.m.Y H:i') ?: '-' }}</div></div>
                </div>
            @else
                <div class="pd-alert pd-alert-warning">Owner kullanıcı henüz oluşturulmadı.</div>
            @endif
        </div>
    </section>

    <form method="POST" action="{{ route('admin.super.tenants.update', $tenant) }}">
        @csrf
        @method('PUT')

        <section class="pd-section-card pd-section-card-soft-slate pd-gap-bottom-md" id="cari-kimlik">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Cari / Fatura Bilgileri</h3>
                    <p class="pd-section-subtitle">Abone Firma için fatura kesimi, SaaS cari ve resmi kimlik alanları bu bölümde tutulur.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-tenant-form-grid">
                    <div>
                        <label class="pd-label" for="name">Abone Firma Adı</label>
                        <input id="name" name="name" value="{{ old('name', $tenant->name) }}" class="pd-input">
                        @error('name')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="legal_name">Yasal Ünvan</label>
                        <input id="legal_name" name="legal_name" value="{{ old('legal_name', $tenant->legal_name) }}" class="pd-input">
                        @error('legal_name')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="company_display_name">Firma Görünen Adı</label>
                        <input id="company_display_name" name="company_display_name" value="{{ old('company_display_name', $companyProfile['display_name'] ?? $tenant->name) }}" class="pd-input">
                        @error('company_display_name')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="company_legal_name">Firma Profil Yasal Ünvanı</label>
                        <input id="company_legal_name" name="company_legal_name" value="{{ old('company_legal_name', $companyProfile['legal_name'] ?? $tenant->legal_name) }}" class="pd-input">
                        @error('company_legal_name')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="company_tax_office">Vergi Dairesi</label>
                        <input id="company_tax_office" name="company_tax_office" value="{{ old('company_tax_office', $companyProfile['tax_office'] ?? '') }}" class="pd-input">
                        @error('company_tax_office')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="company_tax_number">Vergi No / TCKN</label>
                        <input id="company_tax_number" name="company_tax_number" value="{{ old('company_tax_number', $companyProfile['tax_number'] ?? '') }}" class="pd-input">
                        @error('company_tax_number')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="company_email">Fatura E-posta</label>
                        <input id="company_email" name="company_email" value="{{ old('company_email', $companyProfile['email'] ?? '') }}" class="pd-input">
                        @error('company_email')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="company_phone">Telefon</label>
                        <input id="company_phone" name="company_phone" value="{{ old('company_phone', $companyProfile['phone'] ?? '') }}" class="pd-input">
                        @error('company_phone')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="company_country">Ülke</label>
                        <input id="company_country" name="company_country" value="{{ old('company_country', $companyProfile['country'] ?? 'Türkiye') }}" class="pd-input">
                        @error('company_country')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="company_city">İl</label>
                        <input id="company_city" name="company_city" value="{{ old('company_city', $companyProfile['city'] ?? '') }}" class="pd-input">
                        @error('company_city')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="company_district">İlçe</label>
                        <input id="company_district" name="company_district" value="{{ old('company_district', $companyProfile['district'] ?? '') }}" class="pd-input">
                        @error('company_district')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="company_postal_code">Posta Kodu</label>
                        <input id="company_postal_code" name="company_postal_code" value="{{ old('company_postal_code', $companyProfile['postal_code'] ?? '') }}" class="pd-input">
                        @error('company_postal_code')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div class="pd-form-full">
                        <label class="pd-label" for="company_address">Açık Adres</label>
                        <textarea id="company_address" name="company_address" rows="3" class="pd-input">{{ old('company_address', $companyProfile['address'] ?? '') }}</textarea>
                        @error('company_address')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate pd-gap-bottom-md" id="panel-domain">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Panel ve Domain</h3>
                    <p class="pd-section-subtitle">Panel alt alanı, portal erişimi ve özel domain alanları güvenli şekilde yönetilir.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-tenant-form-grid">
                    <div>
                        <label class="pd-label" for="panel_subdomain">Panel Alt Alanı</label>
                        <input id="panel_subdomain" name="panel_subdomain" value="{{ old('panel_subdomain', $tenant->panel_subdomain) }}" class="pd-input">
                        @error('panel_subdomain')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label">Panel Adresi Önizlemesi</label>
                        <div class="pd-tenant-static-box">http://{{ old('panel_subdomain', $tenant->panel_subdomain) ?: '{panel}' }}.{{ $centralPreviewHost }}/admin</div>
                    </div>
                    <div>
                        <label class="pd-label" for="custom_domain">Özel Domain</label>
                        <input id="custom_domain" name="custom_domain" value="{{ old('custom_domain', $tenant->custom_domain) }}" class="pd-input" placeholder="app.ornek.com">
                        @error('custom_domain')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="portal_domain">Portal Domaini</label>
                        <input id="portal_domain" name="portal_domain" value="{{ old('portal_domain', $tenant->portal_domain) }}" class="pd-input" placeholder="portal.ornek.com">
                        @error('portal_domain')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="pd-grid pd-grid-2 pd-gap-top-md">
                    <div class="pd-card pd-card-soft">
                        <div class="pd-card-body">
                            <div class="pd-tenant-form-grid">
                                <div>
                                    <label class="pd-label" for="domain_panel_status">Panel Durumu</label>
                                    <select id="domain_panel_status" name="domain_panel_status" class="pd-input">
                                        @foreach($domainStatusOptions as $key => $label)
                                            <option value="{{ $key }}" @selected(old('domain_panel_status', $domainLifecycleSettings['panel_status']) === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="pd-label" for="domain_custom_status">Özel Domain Durumu</label>
                                    <select id="domain_custom_status" name="domain_custom_status" class="pd-input">
                                        @foreach($domainStatusOptions as $key => $label)
                                            <option value="{{ $key }}" @selected(old('domain_custom_status', $domainLifecycleSettings['custom_status']) === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="pd-label" for="domain_custom_ssl_status">Özel Domain SSL</label>
                                    <select id="domain_custom_ssl_status" name="domain_custom_ssl_status" class="pd-input">
                                        @foreach($sslStatusOptions as $key => $label)
                                            <option value="{{ $key }}" @selected(old('domain_custom_ssl_status', $domainLifecycleSettings['custom_ssl_status']) === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="pd-label" for="domain_portal_status">Portal Domain Durumu</label>
                                    <select id="domain_portal_status" name="domain_portal_status" class="pd-input">
                                        @foreach($domainStatusOptions as $key => $label)
                                            <option value="{{ $key }}" @selected(old('domain_portal_status', $domainLifecycleSettings['portal_status']) === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="pd-label" for="domain_portal_ssl_status">Portal Domain SSL</label>
                                    <select id="domain_portal_ssl_status" name="domain_portal_ssl_status" class="pd-input">
                                        @foreach($sslStatusOptions as $key => $label)
                                            <option value="{{ $key }}" @selected(old('domain_portal_ssl_status', $domainLifecycleSettings['portal_ssl_status']) === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="pd-card pd-card-soft">
                        <div class="pd-card-body">
                            <label class="pd-label" for="domain_operations_note">Domain Operasyon Notu</label>
                            <textarea id="domain_operations_note" name="domain_operations_note" rows="7" class="pd-input">{{ old('domain_operations_note', $domainLifecycleSettings['operations_note']) }}</textarea>
                            <p class="text-sm text-gray-500 pd-subnote-top">Örnek: DNS kaydı beklendi, SSL aktifleşti, portal domain müşteride bekliyor.</p>
                            <div class="pd-alert pd-alert-warning pd-gap-top-md">
                                {{ $paymentArchitectureNote }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="pd-alert pd-alert-warning pd-gap-top-md">
                    DNS ve SSL otomasyonu bu fazda açılmadı. Burada güvenli alan doğrulaması, manuel lifecycle takibi ve operasyonel görünürlük sağlanır.
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate pd-gap-bottom-md" id="abonelik-durumu">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Abonelik Durumu</h3>
                    <p class="pd-section-subtitle">Lifecycle kartı, paket ataması ve operasyon notları tek uzun form yerine kompakt bloklar halinde sunulur.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-grid pd-grid-2">
                    <div class="pd-card pd-card-soft">
                        <div class="pd-card-body">
                            <div class="pd-tenant-form-grid">
                                <div>
                                    <label class="pd-label" for="subscription_trial_starts_at">Deneme Başlangıcı</label>
                                    <input id="subscription_trial_starts_at" name="subscription_trial_starts_at" type="date" value="{{ old('subscription_trial_starts_at', $lifecycleSettings['trial_starts_at'] ?? '') }}" class="pd-input">
                                    @error('subscription_trial_starts_at')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="pd-label" for="subscription_trial_ends_at">Deneme Bitişi</label>
                                    <input id="subscription_trial_ends_at" name="subscription_trial_ends_at" type="date" value="{{ old('subscription_trial_ends_at', $lifecycleSettings['trial_ends_at'] ?? '') }}" class="pd-input">
                                    @error('subscription_trial_ends_at')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="pd-label" for="subscription_package_starts_at">Paket Başlangıcı</label>
                                    <input id="subscription_package_starts_at" name="subscription_package_starts_at" type="date" value="{{ old('subscription_package_starts_at', $lifecycleSettings['package_starts_at'] ?? '') }}" class="pd-input">
                                    @error('subscription_package_starts_at')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="pd-label" for="subscription_package_ends_at">Paket Bitişi</label>
                                    <input id="subscription_package_ends_at" name="subscription_package_ends_at" type="date" value="{{ old('subscription_package_ends_at', $lifecycleSettings['package_ends_at'] ?? '') }}" class="pd-input">
                                    @error('subscription_package_ends_at')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="pd-card pd-card-soft">
                        <div class="pd-card-body">
                            <div class="pd-tenant-form-grid">
                                <div>
                                    <label class="pd-label" for="package_key">Paket</label>
                                    <select id="package_key" name="package_key" class="pd-input">
                                        <option value="">Core / Paket Yok</option>
                                        @foreach($packages as $package)
                                            <option value="{{ $package->key }}" @selected(old('package_key', $tenant->package_key) === $package->key)>{{ $package->name }} ({{ $package->key }})</option>
                                        @endforeach
                                    </select>
                                    @error('package_key')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="pd-label" for="status">Status</label>
                                    <select id="status" name="status" class="pd-input">
                                        <option value="active" @selected($effectiveState === 'active')>Aktif</option>
                                        <option value="trial" @selected($effectiveState === 'trial')>Deneme</option>
                                        <option value="suspended" @selected($effectiveState === 'suspended')>Askıda</option>
                                        <option value="passive" @selected($effectiveState === 'passive')>Pasif</option>
                                    </select>
                                    @error('status')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="pd-label" for="default_locale">Varsayılan Dil</label>
                                    <select id="default_locale" name="default_locale" class="pd-input">
                                        <option value="tr" @selected(old('default_locale', $tenant->default_locale) === 'tr')>Türkçe</option>
                                        <option value="en" @selected(old('default_locale', $tenant->default_locale) === 'en')>English</option>
                                    </select>
                                    @error('default_locale')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="pd-label" for="default_currency">Varsayılan Para Birimi</label>
                                    <input id="default_currency" name="default_currency" value="{{ old('default_currency', $tenant->default_currency) }}" class="pd-input">
                                    @error('default_currency')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="pd-label" for="timezone">Zaman Dilimi</label>
                                    <input id="timezone" name="timezone" value="{{ old('timezone', $tenant->timezone) }}" class="pd-input">
                                    @error('timezone')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pd-grid pd-grid-2 pd-gap-top-md">
                    <div>
                        <label class="pd-label" for="subscription_status_note">Abonelik Notu</label>
                        <textarea id="subscription_status_note" name="subscription_status_note" rows="3" class="pd-input">{{ old('subscription_status_note', $lifecycleSettings['status_note'] ?? '') }}</textarea>
                        @error('subscription_status_note')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="pd-label" for="subscription_suspended_reason">Askıya Alma Nedeni</label>
                        <textarea id="subscription_suspended_reason" name="subscription_suspended_reason" rows="3" class="pd-input">{{ old('subscription_suspended_reason', $lifecycleSettings['suspended_reason'] ?? '') }}</textarea>
                        <p class="text-sm text-gray-500 pd-subnote-top">Askıda statüsü seçildiyse kısa bir operasyon notu girin.</p>
                        @error('subscription_suspended_reason')<p class="text-sm text-red-600 pd-subnote-top">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="pd-gap-top-md">
                    <div class="pd-card pd-card-soft">
                        <div class="pd-card-body">
                            <div class="pd-card-title">Son Lifecycle / Domain Geçmişi</div>
                            <div class="pd-timeline-list pd-gap-top-md">
                                @foreach(collect($tenantAuditTimeline)->take(4) as $item)
                                    <div class="pd-timeline-item">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="pd-timeline-item-title">{{ $item['title'] }}</div>
                                            <span class="pd-badge pd-badge-gray">{{ $item['at'] }}</span>
                                        </div>
                                        <div class="pd-timeline-item-copy">{{ $item['description'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate pd-gap-bottom-md" id="iletisim-belge">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">İletişim ve Belge</h3>
                    <p class="pd-section-subtitle">Tenant tarafındaki ayar kaynakları korunur; Super Admin buradan operasyonel geçiş ve görünürlük yönetir.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-mini-grid">
                    @foreach($opsCenterSections as $section)
                        @if(in_array($section['id'], ['ops-iletisim', 'ops-domain', 'ops-genel'], true))
                            <div class="pd-mini-link-card">
                                <div class="pd-mini-link-title">{{ $section['title'] }}</div>
                                <div class="pd-mini-link-copy">{{ $section['subtitle'] }}</div>
                                <div class="pd-gap-top-md">
                                    @foreach($section['items'] as $item)
                                        <div class="pd-tenant-inline-row">
                                            <span>{{ $item['label'] }}</span>
                                            <strong>{{ $item['value'] }}</strong>
                                        </div>
                                    @endforeach
                                </div>
                                @if(!empty($section['actions']))
                                    <div class="pd-inline-wrap-sm pd-gap-top-md">
                                        @foreach($section['actions'] as $action)
                                            <a href="{{ $action['url'] }}" @if(!empty($action['new_tab'])) target="_blank" rel="noreferrer" @endif class="pd-btn pd-btn-light pd-btn-sm">{{ $action['label'] }}</a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-blue pd-gap-bottom-md" id="saas-cari">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">SaaS Cari</h3>
                    <p class="pd-section-subtitle">Tenant müşteri carisinden ayrı SaaS cari özetini görün; hareketleri ayrı ekranda yönetin.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.tenants.billing.index', $tenant) }}" class="pd-btn pd-btn-primary">Cari ekranını aç</a>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-mini-grid">
                    <div class="pd-mini-link-card"><div class="pd-mini-link-title">Cari Bakiye</div><div class="pd-mini-link-copy">{{ \App\Services\MoneyFormatter::format((float) ($billingSummary['balance'] ?? 0)) }}</div></div>
                    <div class="pd-mini-link-card"><div class="pd-mini-link-title">Toplam Borç</div><div class="pd-mini-link-copy">{{ \App\Services\MoneyFormatter::format((float) ($billingSummary['total_debit'] ?? 0)) }}</div></div>
                    <div class="pd-mini-link-card"><div class="pd-mini-link-title">Toplam Alacak</div><div class="pd-mini-link-copy">{{ \App\Services\MoneyFormatter::format((float) ($billingSummary['total_credit'] ?? 0)) }}</div></div>
                    <div class="pd-mini-link-card"><div class="pd-mini-link-title">Hareket Sayısı</div><div class="pd-mini-link-copy">{{ $billingSummary['entry_count'] ?? 0 }}</div></div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-body">
                <button type="submit" class="pd-btn pd-btn-primary">Abone Firma Ayarlarını Kaydet</button>
            </div>
        </section>
    </form>

    <section class="pd-section-card pd-section-card-soft-slate pd-gap-top-md" id="modul-limit">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Modül / Limit</h3>
                <p class="pd-section-subtitle">Davranış korunur; yalnız daha düzenli section-nav ve spacing ile aynı aileye taşınır.</p>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate pd-gap-bottom-md">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Modül Override</h3>
                <p class="pd-section-subtitle">Paket Varsayılanı / Açık / Kapalı seçimleri tenant bazlı override katmanını yönetir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="POST" action="{{ route('admin.super.tenants.modules.update', $tenant) }}">
                @csrf
                @method('PUT')
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Modül</th><th>Paket</th><th>Effective</th><th>Override</th></tr></thead>
                        <tbody>
                            @foreach($moduleRows as $row)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $row['label'] }}</div>
                                        <div class="text-sm text-gray-500">{{ $row['key'] }} · {{ $row['category'] }}</div>
                                        @if($row['is_locked'])
                                            <div class="pd-badge-stack-gap-sm"><span class="pd-badge {{ $row['is_core'] ? 'pd-badge-blue' : 'pd-badge-amber' }}">{{ $row['is_core'] ? 'Core kilitli' : 'Planlı/Pasif' }}</span></div>
                                        @endif
                                    </td>
                                    <td><span class="pd-badge {{ $row['package_enabled'] ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $row['package_enabled'] ? 'Açık' : 'Kapalı' }}</span></td>
                                    <td>
                                        <span class="pd-badge {{ $row['effective_enabled'] ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $row['effective_enabled'] ? 'Açık' : 'Kapalı' }}</span>
                                        <div class="text-sm text-gray-500 pd-subnote-top">{{ $row['effective_reason'] }}</div>
                                    </td>
                                    <td>
                                        <select name="overrides[{{ $row['key'] }}]" class="pd-input" @disabled($row['is_locked'])>
                                            <option value="default" @selected($row['override_state'] === 'default')>Paket Varsayılanı</option>
                                            <option value="enabled" @selected($row['override_state'] === 'enabled')>Açık</option>
                                            <option value="disabled" @selected($row['override_state'] === 'disabled')>Kapalı</option>
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="pd-gap-top-md">
                    <button type="submit" class="pd-btn pd-btn-primary">Modül Override Kaydet</button>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate pd-gap-bottom-md">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Feature Override</h3>
                <p class="pd-section-subtitle">Mevcut schema uygun olduğu için feature bazlı tenant override desteklenir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="POST" action="{{ route('admin.super.tenants.features.update', $tenant) }}">
                @csrf
                @method('PUT')
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Feature</th><th>Paket</th><th>Effective</th><th>Override</th></tr></thead>
                        <tbody>
                            @foreach($featureRows as $row)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $row['feature_label'] }}</div>
                                        <div class="text-sm text-gray-500">{{ $row['module_label'] }} · {{ $row['feature_key'] }}</div>
                                        @if($row['is_locked'])
                                            <div class="pd-badge-stack-gap-sm"><span class="pd-badge pd-badge-amber">Planlı/Pasif</span></div>
                                        @endif
                                    </td>
                                    <td><span class="pd-badge {{ $row['package_enabled'] ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $row['package_enabled'] ? 'Açık' : 'Kapalı' }}</span></td>
                                    <td>
                                        <span class="pd-badge {{ $row['effective_enabled'] ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $row['effective_enabled'] ? 'Açık' : 'Kapalı' }}</span>
                                        <div class="text-sm text-gray-500 pd-subnote-top">{{ $row['effective_reason'] }}</div>
                                    </td>
                                    <td>
                                        <select name="overrides[{{ $row['feature_key'] }}]" class="pd-input" @disabled($row['is_locked'])>
                                            <option value="default" @selected($row['override_state'] === 'default')>Paket Varsayılanı</option>
                                            <option value="enabled" @selected($row['override_state'] === 'enabled')>Açık</option>
                                            <option value="disabled" @selected($row['override_state'] === 'disabled')>Kapalı</option>
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="pd-gap-top-md">
                    <button type="submit" class="pd-btn pd-btn-primary">Feature Override Kaydet</button>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Limit Override</h3>
                <p class="pd-section-subtitle">Mevcut schema ölçüsünde limit override anahtarları tenant ayarları üzerinden tutulur.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="POST" action="{{ route('admin.super.tenants.limits.update', $tenant) }}">
                @csrf
                @method('PUT')
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Limit</th><th>Paket</th><th>Kullanım</th><th>Effective</th><th>Override</th></tr></thead>
                        <tbody>
                            @foreach($limitRows as $row)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $row['label'] }}</div>
                                        <div class="text-sm text-gray-500">{{ $row['key'] }}</div>
                                    </td>
                                    <td>
                                        @if(!$row['package_limit'])
                                            <span class="pd-badge pd-badge-gray">Tanımsız</span>
                                        @elseif($row['package_limit']['is_unlimited'])
                                            <span class="pd-badge pd-badge-blue">Limitsiz</span>
                                        @else
                                            <span class="pd-badge pd-badge-green">{{ $row['package_limit']['limit_value'] }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $row['current_usage'] }}</td>
                                    <td>
                                        <span class="pd-badge {{ match($row['effective_status']) {'ok' => 'pd-badge-green', 'warning' => 'pd-badge-amber', 'exceeded' => 'pd-badge-red', default => 'pd-badge-blue'} }}">
                                            {{ match($row['effective_status']) {'ok' => 'Normal', 'warning' => 'Uyarı', 'exceeded' => 'Limit Aşıldı', default => 'Limitsiz'} }}
                                        </span>
                                        <div class="text-sm text-gray-500 pd-subnote-top">{{ $row['effective_limit'] === null ? 'Limitsiz' : $row['effective_limit'] }}</div>
                                    </td>
                                    <td>
                                        <select name="limits[{{ $row['key'] }}][mode]" class="pd-input">
                                            <option value="default" @selected($row['override_mode'] === 'default')>Paket Varsayılanı</option>
                                            <option value="value" @selected($row['override_mode'] === 'value')>Değer Gir</option>
                                            <option value="unlimited" @selected($row['override_mode'] === 'unlimited')>Limitsiz</option>
                                        </select>
                                        <input type="number" min="0" name="limits[{{ $row['key'] }}][value]" value="{{ $row['override_value'] }}" class="pd-input pd-gap-top-md" placeholder="Override değer">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="pd-gap-top-md">
                    <button type="submit" class="pd-btn pd-btn-primary">Limit Override Kaydet</button>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection

@section('side_summary')
    <div class="pd-card">
        <div class="pd-card-body">
            <h3 class="pd-summary-title">Operasyon Kısayolları</h3>

            <div class="pd-summary-section">
                <h4 class="pd-summary-section-title">Blok Geçişleri</h4>
                <div class="pd-summary-list">
                    <a href="#cari-kimlik" class="pd-summary-item">Cari / Fatura Bilgileri</a>
                    <a href="#panel-domain" class="pd-summary-item">Panel ve Domain</a>
                    <a href="#abonelik-durumu" class="pd-summary-item">Abonelik Durumu</a>
                    <a href="#iletisim-belge" class="pd-summary-item">İletişim ve Belge</a>
                    <a href="#saas-cari" class="pd-summary-item">SaaS Cari</a>
                    <a href="#modul-limit" class="pd-summary-item">Modül / Limit</a>
                </div>
            </div>

            <div class="pd-summary-section">
                <h4 class="pd-summary-section-title">SaaS Cari Kısa Özet</h4>
                <div class="pd-summary-info">
                    <div class="pd-summary-row"><span>Cari bakiye</span><strong>{{ \App\Services\MoneyFormatter::format((float) ($billingSummary['balance'] ?? 0)) }}</strong></div>
                    <div class="pd-summary-row"><span>Toplam borç</span><strong>{{ \App\Services\MoneyFormatter::format((float) ($billingSummary['total_debit'] ?? 0)) }}</strong></div>
                    <div class="pd-summary-row"><span>Toplam alacak</span><strong>{{ \App\Services\MoneyFormatter::format((float) ($billingSummary['total_credit'] ?? 0)) }}</strong></div>
                </div>
                <div class="pd-gap-top-md">
                    <a href="{{ route('admin.super.tenants.billing.index', $tenant) }}" class="pd-btn pd-btn-light pd-btn-block">Cari ekranını aç</a>
                </div>
            </div>

            <div class="pd-summary-section">
                <h4 class="pd-summary-section-title">Durum Bilgisi</h4>
                <div class="pd-summary-info">
                    <div class="pd-summary-row"><span>Lifecycle</span><strong>{{ $lifecycleSettings['effective_state_label'] }}</strong></div>
                    <div class="pd-summary-row"><span>Paket</span><strong>{{ $packageRecord?->name ?? ($tenant->package_key ?: 'Core') }}</strong></div>
                    <div class="pd-summary-row"><span>Owner</span><strong>{{ $ownerUser?->name ?: 'Eksik' }}</strong></div>
                    <div class="pd-summary-row"><span>Aktif kullanıcı</span><strong>{{ $teamSummary['active_users'] }}</strong></div>
                </div>
            </div>
        </div>
    </div>
@endsection
