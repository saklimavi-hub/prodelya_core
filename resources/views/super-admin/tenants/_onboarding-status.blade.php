@php
    $statusItems = [
        'Paket atanmış' => $onboardingStatus['has_package'] ?? false,
        'Owner oluşturulmuş' => $onboardingStatus['has_owner'] ?? false,
        'Baskı ayarları hazır' => $onboardingStatus['has_print_settings'] ?? false,
        'Bildirim şablonları hazır' => $onboardingStatus['has_notification_templates'] ?? false,
        'Tenant varsayılanları hazır' => $onboardingStatus['has_tenant_settings_defaults'] ?? false,
        'Portal varsayılanları hazır' => $onboardingStatus['has_portal_defaults'] ?? false,
        'Çalışma klasörü kökü hazır' => $onboardingStatus['has_work_folder_root'] ?? false,
    ];
@endphp

<section class="pd-section-card pd-section-card-soft-slate" style="margin-bottom: 16px;">
    <div class="pd-section-header">
        <div>
            <h3 class="pd-section-title">Başlangıç Ayarları</h3>
            <p class="pd-section-subtitle">Yeni tenant kullanım öncesi güvenli varsayılanların ve eksik altyapının hazırlık durumu.</p>
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
            <div class="pd-alert pd-alert-success" style="margin-bottom: 16px;">{{ session('onboarding_defaults_summary') }}</div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($statusItems as $label => $isReady)
                <div class="pd-card pd-card-soft">
                    <div class="pd-card-body">
                        <div class="text-sm text-gray-600">{{ $label }}</div>
                        <div class="font-medium">
                            <span class="pd-badge {{ $isReady ? 'pd-badge-green' : 'pd-badge-amber' }}">
                                {{ $isReady ? 'Hazır' : 'Eksik' }}
                            </span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="pd-alert pd-alert-warning" style="margin-top: 16px;">
            SMTP ayar ekranı hazırdır ancak bu fazda SMTP kurulmuş sayılmaz. Bilgiler tenant admin tarafından girilecektir.
        </div>
        <div class="pd-alert pd-alert-warning" style="margin-top: 12px;">
            WhatsApp hazır mesaj ayar ekranı hazırdır ancak bu fazda aktif entegrasyon kurulmuş sayılmaz.
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4" style="margin-top: 16px;">
            <div>
                <div class="text-sm text-gray-600">SMTP Kurulumu</div>
                <div class="font-medium">{{ ($onboardingStatus['has_smtp_config'] ?? false) ? 'Bilgi girilmiş' : 'Henüz girilmedi' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600">WhatsApp Kurulumu</div>
                <div class="font-medium">{{ ($onboardingStatus['has_whatsapp_config'] ?? false) ? 'Bilgi girilmiş' : 'Henüz girilmedi' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600">İlk Firma / Cari</div>
                <div class="font-medium">{{ ($onboardingStatus['has_first_company_current_account'] ?? false) ? 'Hazır' : 'Henüz oluşturulmadı' }}</div>
            </div>
        </div>
    </div>
</section>
