@php
    $statusBadge = match($subscription['severity'] ?? 'muted') {
        'success' => 'pd-badge-green',
        'info' => 'pd-badge-blue',
        'warning' => 'pd-badge-amber',
        'danger' => 'pd-badge-red',
        default => 'pd-badge-gray',
    };
@endphp

<section class="pd-hero-card">
    <div class="pd-card-body">
        <div class="pd-hero-main">
            <div class="pd-hero-copy">
                <h1 class="pd-hero-title">{{ $tenant->name }}</h1>
                <p class="pd-hero-subtitle">{{ $tenant->legal_name ?: 'Tenant detay ve paket override yönetimi.' }}</p>
            </div>
            <div class="pd-hero-actions">
                <span class="pd-badge {{ $statusBadge }}">{{ $subscription['label'] ?? 'Bilinmiyor' }}</span>
                @if(filled($tenant->package_key))
                    <span class="pd-badge pd-badge-blue">{{ $tenant->package_key }}</span>
                @endif
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4" style="margin-top: 18px;">
            <div>
                <div class="text-sm text-gray-600">Panel</div>
                <div class="font-medium">{{ $tenant->panel_subdomain ?: '-' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600">Paket</div>
                <div class="font-medium">{{ $packageRecord?->name ?? ($tenant->package_key ?: 'Core') }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600">Durum Mesajı</div>
                <div class="text-sm text-gray-900">{{ $subscription['message'] ?? '-' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600">Kalan Gün</div>
                <div class="font-medium">{{ $subscription['days_remaining'] ?? '-' }}</div>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4" style="margin-top: 18px;">
            <div>
                <div class="text-sm text-gray-600">Owner</div>
                <div class="font-medium">{{ $ownerUser?->name ?: 'Henüz oluşturulmadı' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600">Owner E-posta</div>
                <div class="font-medium">{{ $ownerUser?->email ?: '-' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600">Rol</div>
                <div class="font-medium">{{ $ownerRole?->name ?: '-' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600">Tenant Admin Girişi</div>
                <div class="font-medium"><a href="{{ $tenantAdminPreviewUrl }}" target="_blank" rel="noreferrer">{{ $tenantAdminPreviewUrl }}</a></div>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4" style="margin-top: 18px;">
            <div>
                <div class="text-sm text-gray-600">Local Panel Host</div>
                <div class="font-medium">{{ $tenantPanelPreviewHost ?: 'Henüz tanımlanmadı' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600">Custom Domain Admin</div>
                <div class="font-medium">{{ $tenantCustomDomainPreview ?: 'Henüz tanımlanmadı' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600">Portal Domain</div>
                <div class="font-medium">{{ $tenantPortalDomainPreview ?: 'Henüz tanımlanmadı' }}</div>
            </div>
        </div>
        <div class="pd-alert pd-alert-warning" style="margin-top: 16px;">
            Local test için tenant panel adresi: {{ $tenantAdminPreviewUrl }}. {{ $localHostPreviewNote }} Windows/Laragon için gerekirse <code>C:\Windows\System32\drivers\etc\hosts</code> dosyasına
            <code>127.0.0.1 {{ $tenantPanelPreviewHost }}</code> satırı eklenebilir.
        </div>
        <div class="pd-hero-actions" style="margin-top: 16px;">
            @if($ownerExists)
                <span class="pd-badge pd-badge-green">Owner Hazır</span>
                <span class="pd-badge pd-badge-gray">Ek kullanıcı yönetimi sonraki fazda</span>
            @else
                <a href="{{ route('admin.super.tenants.owner.create', $tenant) }}" class="pd-btn pd-btn-primary">Owner Oluştur</a>
            @endif
        </div>
        @if($unknownPackageKey ?? false)
            <div class="pd-alert pd-alert-warning" style="margin-top: 16px;">Eski/Geçersiz paket anahtarı algılandı. Bu tenant geçerli bir package kaydıyla eşleşmiyor.</div>
        @endif
    </div>
</section>
