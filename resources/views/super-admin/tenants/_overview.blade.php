@php
    $statusBadge = match($subscription['severity'] ?? 'muted') {
        'success' => 'pd-badge-green',
        'info' => 'pd-badge-blue',
        'warning' => 'pd-badge-amber',
        'danger' => 'pd-badge-red',
        default => 'pd-badge-gray',
    };

    $companyProfileMissingCount = collect([
        $companyProfile['tax_number'] ?? null,
        $companyProfile['tax_office'] ?? null,
        $companyProfile['email'] ?? null,
        $companyProfile['address'] ?? null,
    ])->filter(fn ($value) => blank($value))->count();

    $warningCount = count($usageWarnings ?? []) + (int) (!$teamSummary['owner_ready'] ?? false) + (int) (!$notificationSummary['whatsapp']['is_ready'] ?? false) + $companyProfileMissingCount;
@endphp

<section class="pd-hero-card">
    <div class="pd-card-body">
        <div class="pd-hero-main">
            <div class="pd-hero-copy">
                <h1 class="pd-hero-title">{{ $tenant->name }}</h1>
                <p class="pd-hero-subtitle">{{ $tenant->legal_name ?: 'Abone Firma detay, lifecycle ve operasyon görünümü.' }}</p>
                <div class="pd-hero-badges">
                    <span class="pd-badge {{ $statusBadge }}">{{ $subscription['label'] ?? 'Bilinmiyor' }}</span>
                    @if($isDemoTenant)
                        <span class="pd-badge pd-badge-amber">Demo/Test</span>
                    @endif
                    @if(filled($tenant->package_key))
                        <span class="pd-badge pd-badge-blue">{{ $packageRecord?->name ?? $tenant->package_key }}</span>
                    @endif
                    <span class="pd-badge {{ ($teamSummary['owner_ready'] ?? false) ? 'pd-badge-green' : 'pd-badge-amber' }}">
                        {{ ($teamSummary['owner_ready'] ?? false) ? 'Owner Hazır' : 'Owner Eksik' }}
                    </span>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ $tenantAdminPreviewUrl }}" target="_blank" rel="noreferrer" class="pd-btn pd-btn-primary">Abone Firma Paneline Gir</a>
                    <a href="{{ route('admin.super.tenants.edit', $tenant) }}" class="pd-btn pd-btn-light">Düzenle</a>
                    <a href="{{ route('admin.super.tenants.edit', $tenant) }}#modul-limit-yonetimi" class="pd-btn pd-btn-light">Modül / Limit Yönet</a>
                    <a href="{{ route('admin.super.tenants.billing.index', $tenant) }}" class="pd-btn pd-btn-light">SaaS Cariyi Aç</a>
                </div>
            </div>
        </div>

        <div class="pd-tenant-summary-grid">
            <div class="pd-tenant-summary-card is-success">
                <div class="pd-tenant-summary-label">Lifecycle</div>
                <div class="pd-tenant-summary-value">{{ $lifecycleSettings['effective_state_label'] }}</div>
                <div class="pd-tenant-summary-note">{{ $subscription['message'] ?? 'Takip ediliyor' }}</div>
            </div>
            <div class="pd-tenant-summary-card is-info">
                <div class="pd-tenant-summary-label">Aktif Modül / Override</div>
                <div class="pd-tenant-summary-value">{{ count($moduleSummary['enabled_optional'] ?? []) }}</div>
                <div class="pd-tenant-summary-note">{{ ($moduleSummary['overridden_modules_count'] ?? 0) + ($moduleSummary['overridden_features_count'] ?? 0) }} override aktif</div>
            </div>
            <div class="pd-tenant-summary-card is-warning">
                <div class="pd-tenant-summary-label">Hazırlık Eksikleri</div>
                <div class="pd-tenant-summary-value">{{ $warningCount }}</div>
                <div class="pd-tenant-summary-note">Firma profili, bildirim ve ekip sinyalleri birlikte izlenir</div>
            </div>
            <div class="pd-tenant-summary-card is-slate">
                <div class="pd-tenant-summary-label">SaaS Cari Bakiye</div>
                <div class="pd-tenant-summary-value">{{ \App\Services\MoneyFormatter::format((float) ($billingSummary['balance'] ?? 0)) }}</div>
                <div class="pd-tenant-summary-note">{{ ($billingSummary['entry_count'] ?? 0) }} hareket / {{ \App\Services\MoneyFormatter::format((float) ($billingSummary['total_debit'] ?? 0)) }} borç</div>
            </div>
        </div>
    </div>
</section>
