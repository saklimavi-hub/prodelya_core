@extends('layouts.prodelya-admin')

@section('title', 'Yeni Abone Firma Oluştur')

@php
    $selectedPackageKey = old('package_key', $defaultValues['package_key'] ?? '');
@endphp

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Yeni Abone Firma Oluştur</h1>
                    <p class="pd-hero-subtitle">Abone Firma, panel yetkilisi ve başlangıç ayarlarını tek akışta hazırlayın. Mail gönderimi bu fazda zorunlu değildir.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.tenants.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                </div>
            </div>
        </div>
    </section>

    <form method="POST" action="{{ route('admin.super.tenants.store') }}" class="pd-stack-lg">
        @csrf
        @if($signupRequest)
            <input type="hidden" name="signup_request_id" value="{{ $signupRequest->id }}">
        @endif

        @if($signupRequest)
            <section class="pd-section-card pd-section-card-soft-slate">
                <div class="pd-section-body">
                    <div class="pd-alert pd-alert-warning">
                        Bu form public başvurudan dolduruldu. Kaydetmeden önce paket, panel adresi ve panel yetkilisi bilgilerini kontrol edin.
                    </div>
                    <div class="pd-mini-kpi-strip mt-4">
                        <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Başvuru Tipi</div><div class="font-medium">{{ $signupRequestPrefill['request_type_label'] ?? '-' }}</div></div></div>
                        <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Şehir / Sektör</div><div class="font-medium">{{ ($signupRequestPrefill['city'] ?? 'Belirtilmedi') }} / {{ ($signupRequestPrefill['sector'] ?? 'Belirtilmedi') }}</div></div></div>
                        <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Beklenen Kullanıcı</div><div class="font-medium">{{ $signupRequestPrefill['expected_user_count'] ?: 'Belirtilmedi' }}</div></div></div>
                    </div>
                    @if(!empty($signupRequestPrefill['demo_topic']))
                        <div class="pd-alert pd-alert-warning mt-3">
                            Demo konusu: {{ $signupRequestPrefill['demo_topic'] }}
                        </div>
                        <div class="pd-field-note mt-2">
                            Demo başvurusu create akışında otomatik trial başlatmaz; son tenant durumu Super Admin kontrolüyle belirlenir.
                        </div>
                    @endif
                    @if(!empty($signupRequestPrefill['package_warning']))
                        <div class="pd-alert pd-alert-warning mt-3">{{ $signupRequestPrefill['package_warning'] }}</div>
                    @endif
                </div>
            </section>
        @endif

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Abone Firma Bilgileri</h3>
                    <p class="pd-section-subtitle">Firma adı, paket, durum ve temel locale bilgileri tenant kaydının omurgasını oluşturur.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-form-shell-grid pd-form-shell-grid-2">
                    <div>
                        <label class="pd-label" for="name">Firma Adı</label>
                        <input id="name" name="name" class="pd-input" value="{{ old('name', $defaultValues['name'] ?? '') }}" required>
                        @error('name')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="legal_name">Yasal Ünvan</label>
                        <input id="legal_name" name="legal_name" class="pd-input" value="{{ old('legal_name', $defaultValues['legal_name'] ?? '') }}">
                        @error('legal_name')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="slug">Slug / Kısa Kod</label>
                        <input id="slug" name="slug" class="pd-input" value="{{ old('slug', $defaultValues['slug'] ?? '') }}" required>
                        <p class="pd-field-note">Hesap kodu, panel subdomain ve operasyon kimliği için güvenli kısa ad kullanın.</p>
                        @error('slug')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="status">Durum</label>
                        <select id="status" name="status" class="pd-input" required>
                            @foreach($statusOptions as $key => $label)
                                <option value="{{ $key }}" @selected(old('status', $defaultValues['status']) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="pd-field-note">Deneme seçeneği görünürdür. Ayrı billing lifecycle bu fazın kapsamı dışındadır.</p>
                        @error('status')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="package_key">Paket</label>
                        <select id="package_key" name="package_key" class="pd-input" required data-package-select>
                            <option value="">Paket seçin</option>
                            @foreach($packages as $package)
                                <option value="{{ $package->key }}" @selected($selectedPackageKey === $package->key)>{{ $package->name }} ({{ $package->key }})</option>
                            @endforeach
                        </select>
                        @error('package_key')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="default_currency">Varsayılan Para Birimi</label>
                        <input id="default_currency" name="default_currency" class="pd-input" value="{{ old('default_currency', $defaultValues['default_currency']) }}" required>
                        @error('default_currency')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="default_locale">Varsayılan Dil</label>
                        <select id="default_locale" name="default_locale" class="pd-input" required>
                            @foreach($localeOptions as $key => $label)
                                <option value="{{ $key }}" @selected(old('default_locale', $defaultValues['default_locale']) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_locale')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="timezone">Timezone</label>
                        <select id="timezone" name="timezone" class="pd-input" required>
                            @foreach($timezoneOptions as $key => $label)
                                <option value="{{ $key }}" @selected(old('timezone', $defaultValues['timezone']) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('timezone')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Panel Bilgileri</h3>
                    <p class="pd-section-subtitle">Panel subdomain bu fazda mevcut tenant alanlarıyla yönetilir. Ayrı domain otomasyonu sonraki fazdadır.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-form-shell-grid pd-form-shell-grid-2">
                    <div>
                        <label class="pd-label" for="panel_subdomain">Panel Adresi</label>
                        <input id="panel_subdomain" name="panel_subdomain" class="pd-input" value="{{ old('panel_subdomain', $defaultValues['panel_subdomain'] ?? '') }}" required>
                        <p class="pd-field-note">Panel Adresi: <span class="font-medium">http://<span data-subdomain-preview>{{ old('panel_subdomain', $defaultValues['panel_subdomain'] ?? 'tenant-kodu') }}</span>.{{ $centralPreviewHost }}/admin</span></p>
                        <p class="pd-field-note">{{ $localHostPreviewNote }}</p>
                        @error('panel_subdomain')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="custom_domain">Custom Domain</label>
                        <input id="custom_domain" name="custom_domain" class="pd-input" value="{{ old('custom_domain', $defaultValues['custom_domain'] ?? '') }}" placeholder="app.customer-example.com">
                        <p class="pd-field-note">DNS / domain otomasyonu bu fazda yok. Gerekirse daha sonra tanımlanabilir.</p>
                        @error('custom_domain')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="portal_domain">Portal Domain</label>
                        <input id="portal_domain" name="portal_domain" class="pd-input" value="{{ old('portal_domain', $defaultValues['portal_domain'] ?? '') }}" placeholder="portal.customer-example.com">
                        <p class="pd-field-note">Müşteri portalı alanı varsa burada saklanır. Domain lifecycle Super Admin operasyon ekranında manuel takip edilir.</p>
                        @error('portal_domain')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <div class="pd-alert pd-alert-warning">
                            Reserved host değerleri, duplicate subdomain ve central host çakışmaları güvenli validation ile engellenir.
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Yönetici Kullanıcı</h3>
                    <p class="pd-section-subtitle">Panel yetkilisi bilgileri girilirse Abone Firma oluşturma sırasında güvenli şekilde oluşturulur veya boş rolü olmayan mevcut kullanıcı bağlanır.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-form-shell-grid pd-form-shell-grid-2">
                    <div>
                        <label class="pd-label" for="owner_name">Panel Yetkilisi Adı</label>
                        <input id="owner_name" name="owner_name" class="pd-input" value="{{ old('owner_name', $defaultValues['owner_name'] ?? '') }}">
                        <p class="pd-field-note">Boş bırakılırsa onboarding ekranında `Panel Yetkilisi eksik` görünür.</p>
                        @error('owner_name')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="owner_email">Panel Yetkilisi E-posta</label>
                        <input id="owner_email" name="owner_email" type="email" class="pd-input" value="{{ old('owner_email', $defaultValues['owner_email'] ?? '') }}">
                        <p class="pd-field-note">Platform admin ve başka tenant kullanıcısına bağlı e-postalar güvenli şekilde engellenir.</p>
                        @error('owner_email')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="owner_phone">Panel Yetkilisi Telefon</label>
                        <input id="owner_phone" name="owner_phone" class="pd-input" value="{{ old('owner_phone', $defaultValues['owner_phone'] ?? '') }}">
                        @error('owner_phone')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="owner_password">Geçici Şifre</label>
                        <input id="owner_password" name="owner_password" type="password" class="pd-input">
                        <p class="pd-field-note">Boş bırakılırsa sistem tek seferlik geçici şifre üretir ve create sonrası yalnız ekranda gösterir.</p>
                        @error('owner_password')<p class="pd-input-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Paket / Modül Başlangıcı</h3>
                    <p class="pd-section-subtitle">Seçilen paket bilgisi, modül ve limit görünürlüğü create öncesi özet olarak gösterilir.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-mini-kpi-strip">
                    <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Paket</div><div class="font-medium" data-package-name>{{ $selectedPackageKey && isset($packageSummaries[$selectedPackageKey]) ? $packageSummaries[$selectedPackageKey]['name'] : 'Henüz seçilmedi' }}</div></div></div>
                    <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Modül</div><div class="font-medium" data-package-modules>{{ $selectedPackageKey && isset($packageSummaries[$selectedPackageKey]) ? $packageSummaries[$selectedPackageKey]['module_count'] : '-' }}</div></div></div>
                    <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Feature</div><div class="font-medium" data-package-features>{{ $selectedPackageKey && isset($packageSummaries[$selectedPackageKey]) ? $packageSummaries[$selectedPackageKey]['feature_count'] : '-' }}</div></div></div>
                    <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Limit</div><div class="font-medium" data-package-limits>{{ $selectedPackageKey && isset($packageSummaries[$selectedPackageKey]) ? $packageSummaries[$selectedPackageKey]['limit_count'] : '-' }}</div></div></div>
                </div>

                <div class="pd-alert pd-alert-warning mt-4">
                    Paket seçildiğinde modül/feature/limit erişimleri create sonrası mevcut servislerle hesaplanır. Override yönetimi tenant detay ekranından devam eder.
                </div>

                @if($signupRequest)
                    <div class="pd-alert pd-alert-warning mt-3">
                        Public başvurudaki modül tercihleri yalnız bilgi olarak saklanır. Otomatik tenant modül override uygulanmaz.
                    </div>
                    <div class="flex flex-wrap gap-2 mt-3">
                        @forelse(($signupRequestPrefill['requested_modules'] ?? []) as $moduleKey)
                            <span class="pd-badge pd-badge-blue">{{ $moduleKey }}</span>
                        @empty
                            <span class="text-sm text-gray-600">Başvuruda modül tercihi yok.</span>
                        @endforelse
                    </div>
                @endif
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Varsayılan Ayarlar</h3>
                    <p class="pd-section-subtitle">Abone Firma ayarları, portal varsayılanları, bildirim şablonları ve çalışma klasörü güvenli şekilde hazırlanır.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-alert pd-alert-success">
                    Abone Firma oluşturulduğunda mevcut onboarding defaults servisi otomatik çalışır. SMTP ve WhatsApp entegrasyonu bu fazda gerçek gönderim başlatmaz.
                </div>
                <div class="pd-alert pd-alert-warning mt-3">
                    Demo / gerçek tenant ayrımı mevcut flag ve slug görünürlüğüyle korunur. Ayrı domain lifecycle ve billing bu fazın kapsamı dışındadır.
                </div>
                <div class="pd-alert pd-alert-warning mt-3">
                    Online ödeme tarafında Super Admin ortak provider omurgası sabit olacak; tenant tarafında ise modül olarak açılacaktır.
                </div>
                @if($signupRequest)
                    <div class="pd-alert pd-alert-warning mt-3">
                        Başvuru bağlamı olarak şehir, sektör, beklenen kullanıcı sayısı, demo konusu ve not bilgileri tenant ayarlarında metadata olarak saklanacaktır.
                    </div>
                @endif
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-body">
                <div class="pd-form-actions">
                    <button type="submit" class="pd-btn pd-btn-primary">Abone Firma Oluştur ve Onboarding Hazırla</button>
                    <a href="{{ route('admin.super.tenants.index') }}" class="pd-btn pd-btn-light">İptal</a>
                </div>
            </div>
        </section>
    </form>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const nameField = document.getElementById('name');
    const slugField = document.getElementById('slug');
    const subdomainField = document.getElementById('panel_subdomain');
    const preview = document.querySelector('[data-subdomain-preview]');
    const packageSelect = document.querySelector('[data-package-select]');
    const packageData = @json($packageSummaries);

    const slugify = (value) => {
        return value
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            || 'tenant-kodu';
    };

    const syncPreview = () => {
        if (preview && subdomainField) {
            preview.textContent = slugify(subdomainField.value);
        }
    };

    const fillFromName = () => {
        if (!nameField) {
            return;
        }

        if (slugField && slugField.value.trim() === '') {
            slugField.value = slugify(nameField.value);
        }

        if (subdomainField && subdomainField.value.trim() === '') {
            subdomainField.value = slugify(nameField.value);
        }

        syncPreview();
    };

    const syncPackageSummary = () => {
        const selected = packageSelect ? packageSelect.value : '';
        const summary = packageData[selected] || null;

        document.querySelector('[data-package-name]').textContent = summary ? summary.name : 'Henüz seçilmedi';
        document.querySelector('[data-package-modules]').textContent = summary ? summary.module_count : '-';
        document.querySelector('[data-package-features]').textContent = summary ? summary.feature_count : '-';
        document.querySelector('[data-package-limits]').textContent = summary ? summary.limit_count : '-';
    };

    nameField && nameField.addEventListener('blur', fillFromName);
    slugField && slugField.addEventListener('input', () => {
        if (subdomainField && subdomainField.value.trim() === '') {
            subdomainField.value = slugify(slugField.value);
        }
        syncPreview();
    });
    subdomainField && subdomainField.addEventListener('input', syncPreview);
    packageSelect && packageSelect.addEventListener('change', syncPackageSummary);

    fillFromName();
    syncPreview();
    syncPackageSummary();
});
</script>
@endpush
