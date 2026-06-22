@extends('layouts.prodelya-admin')

@section('title', 'Yeni Tenant')

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Yeni Tenant</h1>
                    <p class="pd-hero-subtitle">Temel tenant kaydını şimdi oluşturun. Owner kullanıcı ve başlangıç onboarding adımları sonraki fazda tamamlanacaktır.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.tenants.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tenant Oluşturma Formu</h3>
                <p class="pd-section-subtitle">Paket, tenant kimliği ve temel panel/domain ayarlarını güvenli şekilde tanımlayın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="POST" action="{{ route('admin.super.tenants.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @csrf

                <div>
                    <label class="pd-label" for="name">Tenant Adı</label>
                    <input id="name" name="name" class="pd-input" value="{{ old('name') }}" required>
                    @error('name')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="pd-label" for="legal_name">Yasal Ünvan</label>
                    <input id="legal_name" name="legal_name" class="pd-input" value="{{ old('legal_name') }}">
                    @error('legal_name')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="pd-label" for="slug">Slug / Hesap Kodu</label>
                    <input id="slug" name="slug" class="pd-input" value="{{ old('slug') }}" required>
                    <p class="text-sm text-gray-500" style="margin-top: 6px;">Bu alan mevcut schema’da hesap kodu yerine kullanılır.</p>
                    @error('slug')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="pd-label" for="package_key">Paket</label>
                    <select id="package_key" name="package_key" class="pd-input" required>
                        <option value="">Paket seçin</option>
                        @foreach($packages as $package)
                            <option value="{{ $package->key }}" @selected(old('package_key') === $package->key)>{{ $package->name }} ({{ $package->key }})</option>
                        @endforeach
                    </select>
                    @error('package_key')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="pd-label" for="status">Tenant Durumu</label>
                    <select id="status" name="status" class="pd-input" required>
                        @foreach($statusOptions as $key => $label)
                            <option value="{{ $key }}" @selected(old('status', $defaultValues['status']) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="pd-label" for="panel_subdomain">Panel Subdomain</label>
                    <input id="panel_subdomain" name="panel_subdomain" class="pd-input" value="{{ old('panel_subdomain') }}" required>
                    <p class="text-sm text-gray-500" style="margin-top: 6px;">Local panel preview: http://<span data-subdomain-preview>{{ old('panel_subdomain', 'tenant-kodu') }}</span>.{{ $centralPreviewHost }}/admin</p>
                    <p class="text-sm text-gray-500" style="margin-top: 6px;">{{ $localHostPreviewNote }}</p>
                    <p class="text-sm text-gray-500" style="margin-top: 6px;">Windows/Laragon için gerekirse: <code>C:\Windows\System32\drivers\etc\hosts</code></p>
                    @error('panel_subdomain')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="pd-label" for="default_locale">Varsayılan Dil</label>
                    <select id="default_locale" name="default_locale" class="pd-input" required>
                        @foreach($localeOptions as $key => $label)
                            <option value="{{ $key }}" @selected(old('default_locale', $defaultValues['default_locale']) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('default_locale')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="pd-label" for="default_currency">Varsayılan Para</label>
                    <input id="default_currency" name="default_currency" class="pd-input" value="{{ old('default_currency', $defaultValues['default_currency']) }}" required>
                    @error('default_currency')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="pd-label" for="timezone">Timezone</label>
                    <select id="timezone" name="timezone" class="pd-input" required>
                        @foreach($timezoneOptions as $key => $label)
                            <option value="{{ $key }}" @selected(old('timezone', $defaultValues['timezone']) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('timezone')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="pd-label" for="custom_domain">Custom Domain</label>
                    <input id="custom_domain" name="custom_domain" class="pd-input" value="{{ old('custom_domain') }}" placeholder="app.customer-example.com">
                    <p class="text-sm text-gray-500" style="margin-top: 6px;">İsterseniz daha sonra yapılandırabilirsiniz.</p>
                    @error('custom_domain')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="pd-label" for="portal_domain">Portal Domain</label>
                    <input id="portal_domain" name="portal_domain" class="pd-input" value="{{ old('portal_domain') }}" placeholder="portal.customer-example.com">
                    <p class="text-sm text-gray-500" style="margin-top: 6px;">İsterseniz daha sonra yapılandırabilirsiniz.</p>
                    @error('portal_domain')<p class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</p>@enderror
                </div>

                <div style="grid-column: 1 / -1;">
                    <div class="pd-alert pd-alert-warning">
                        Owner kullanıcı bu adımda oluşturulmaz. Tenant kullanıcı onboarding, print settings sync ve notification defaults sonraki fazda tamamlanacaktır.
                    </div>
                </div>

                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="pd-btn pd-btn-primary">Tenant Oluştur</button>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const field = document.getElementById('panel_subdomain');
    const preview = document.querySelector('[data-subdomain-preview]');

    if (!field || !preview) {
        return;
    }

    const syncPreview = () => {
        const normalized = field.value.trim().toLowerCase().replace(/[^a-z0-9-]/g, '-') || 'tenant-kodu';
        preview.textContent = normalized;
    };

    field.addEventListener('input', syncPreview);
    syncPreview();
});
</script>
@endpush
