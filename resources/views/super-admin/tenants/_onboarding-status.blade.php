@php
    $statusItems = [
        'Paket atanmış' => $onboardingStatus['has_package'] ?? false,
        'Owner oluşturulmuş' => $onboardingStatus['has_owner'] ?? false,
        'Aktif ekip kullanıcısı var' => $onboardingStatus['has_active_user'] ?? false,
        'Finans yetkili kullanıcı var' => $onboardingStatus['has_finance_user'] ?? false,
        'Operasyon kullanıcısı var' => $onboardingStatus['has_operations_user'] ?? false,
        'Panel adresi hazır' => $onboardingStatus['has_panel_domain'] ?? false,
        'Firma profili hazır' => $onboardingStatus['has_company_profile'] ?? false,
        'Baskı ayarları hazır' => $onboardingStatus['has_print_settings'] ?? false,
        'SMTP ayarı hazır' => $onboardingStatus['has_smtp_config'] ?? false,
        'WhatsApp ayarı hazır' => $onboardingStatus['has_whatsapp_config'] ?? false,
        'Bildirim şablonları hazır' => $onboardingStatus['has_notification_templates'] ?? false,
        'Tenant ayarları varsayılanları' => $onboardingStatus['has_tenant_settings_defaults'] ?? false,
        'Portal varsayılanları hazır' => $onboardingStatus['has_portal_defaults'] ?? false,
        'Çalışma klasörü kökü hazır' => $onboardingStatus['has_work_folder_root'] ?? false,
    ];
@endphp

<section class="pd-section-card pd-section-card-soft-slate">
    <div class="pd-section-header">
        <div>
            <h3 class="pd-section-title">Abone Firma Hazırlık Durumu</h3>
            <p class="pd-section-subtitle">Yeni Abone Firma kullanım öncesi güvenli varsayılanların ve eksik altyapının hazırlık durumu.</p>
        </div>
        <div class="pd-hero-actions">
            <form method="POST" action="{{ route('admin.super.tenants.prepare-defaults', $tenant) }}">
                @csrf
                <button type="submit" class="pd-btn pd-btn-primary">Başlangıç Ayarlarını Hazırla</button>
            </form>
        </div>
    </div>
    <div class="pd-section-body">
        @if(session('onboarding_defaults_summary'))
            <div class="pd-alert pd-alert-success pd-gap-bottom-md">{{ session('onboarding_defaults_summary') }}</div>
        @endif

        <div class="pd-tenant-health-list">
            @foreach($statusItems as $label => $isReady)
                <div class="pd-tenant-health-row">
                    <div>
                        <div class="pd-tenant-health-title">{{ $label }}</div>
                        <div class="pd-tenant-health-copy">{{ $isReady ? 'Başlangıç kullanımında hazır görünüyor.' : 'Operasyon öncesi kontrol edilmesi gerekiyor.' }}</div>
                    </div>
                    <span class="pd-badge {{ $isReady ? 'pd-badge-green' : 'pd-badge-amber' }}">
                        {{ $isReady ? 'Hazır' : 'Eksik' }}
                    </span>
                </div>
            @endforeach
        </div>

        <div class="pd-grid pd-grid-3 pd-gap-top-md">
            <div class="pd-tenant-info-card">
                <div class="pd-tenant-info-label">SMTP Kurulumu</div>
                <div class="pd-tenant-info-value">{{ ($onboardingStatus['has_smtp_config'] ?? false) ? 'Bilgi girilmiş' : 'Henüz girilmedi' }}</div>
            </div>
            <div class="pd-tenant-info-card">
                <div class="pd-tenant-info-label">WhatsApp Kurulumu</div>
                <div class="pd-tenant-info-value">{{ ($onboardingStatus['has_whatsapp_config'] ?? false) ? 'Bilgi girilmiş' : 'Henüz girilmedi' }}</div>
            </div>
            <div class="pd-tenant-info-card">
                <div class="pd-tenant-info-label">İlk Firma / Cari</div>
                <div class="pd-tenant-info-value">{{ ($onboardingStatus['has_first_company_current_account'] ?? false) ? 'Hazır' : 'Henüz oluşturulmadı' }}</div>
            </div>
        </div>

        <div class="pd-alert pd-alert-warning pd-gap-top-md">
            SMTP ve WhatsApp ekranları hazırdır; gerçek entegrasyon ve aktif gönderim bu fazda otomatik kurulmuş sayılmaz.
        </div>
    </div>
</section>
