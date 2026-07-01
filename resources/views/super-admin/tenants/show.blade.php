@extends('layouts.prodelya-admin')

@section('title', 'Abone Firma Detayı')
@section('page_title', 'Abone Firma Operasyon Özeti')
@section('page_subtitle', 'Abone Firma, cari kimlik, lifecycle, ekip ve SaaS cari durumunu tek bakışta yönetin.')

@php
    $usageBadgeMap = [
        'ok' => 'pd-badge-green',
        'warning' => 'pd-badge-amber',
        'exceeded' => 'pd-badge-red',
        'unlimited' => 'pd-badge-blue',
    ];
    $readinessBadgeMap = [
        'Hazır' => 'pd-badge-green',
        'Eksik' => 'pd-badge-red',
        'Kontrol Edilmeli' => 'pd-badge-amber',
        'Sonraki Faz' => 'pd-badge-gray',
        'Opsiyonel' => 'pd-badge-blue',
        'Demo/Test' => 'pd-badge-blue',
        'Canlı Adayı' => 'pd-badge-green',
    ];
    $billingIdentityItems = [
        ['label' => 'Abone Firma Adı', 'value' => $tenant->name],
        ['label' => 'Yasal Ünvan', 'value' => $companyProfile['legal_name'] ?: ($tenant->legal_name ?: 'Eksik')],
        ['label' => 'Vergi Dairesi', 'value' => $companyProfile['tax_office'] ?: 'Eksik'],
        ['label' => 'Vergi No / TCKN', 'value' => $companyProfile['tax_number'] ?: 'Eksik'],
        ['label' => 'Fatura E-posta', 'value' => $companyProfile['email'] ?: 'Eksik'],
        ['label' => 'Telefon', 'value' => $companyProfile['phone'] ?: 'Eksik'],
        ['label' => 'Ülke', 'value' => $companyProfile['country'] ?: 'Türkiye'],
        ['label' => 'İl', 'value' => $companyProfile['city'] ?: 'Eksik'],
        ['label' => 'İlçe', 'value' => $companyProfile['district'] ?: 'Eksik'],
        ['label' => 'Posta Kodu', 'value' => $companyProfile['postal_code'] ?: 'Eksik'],
        ['label' => 'Açık Adres', 'value' => $companyProfile['address'] ?: 'Eksik'],
        ['label' => 'Fatura Hazırlığı', 'value' => collect([
            $companyProfile['legal_name'] ?? null,
            $companyProfile['tax_office'] ?? null,
            $companyProfile['tax_number'] ?? null,
            $companyProfile['email'] ?? null,
            $companyProfile['address'] ?? null,
            $companyProfile['city'] ?? null,
        ])->filter()->count() >= 6 ? 'Fatura kesmeye uygun' : 'Bilgiler tamamlanmalı'],
    ];
    $billingIdentityMissing = collect([
        'Yasal Ünvan' => $companyProfile['legal_name'] ?? null,
        'Vergi Dairesi' => $companyProfile['tax_office'] ?? null,
        'Vergi No / TCKN' => $companyProfile['tax_number'] ?? null,
        'Fatura E-posta' => $companyProfile['email'] ?? null,
        'Açık Adres' => $companyProfile['address'] ?? null,
        'İl' => $companyProfile['city'] ?? null,
    ])->filter(fn ($value) => blank($value))->keys()->values();
    $settingsBlocks = [
        ['label' => 'Panel Alt Alanı', 'value' => $tenant->panel_subdomain ?: 'Tanımlı değil'],
        ['label' => 'Panel Adresi', 'value' => $tenantAdminPreviewUrl],
        ['label' => 'Özel Domain', 'value' => $tenant->custom_domain ?: 'Tanımlı değil'],
        ['label' => 'Portal Domaini', 'value' => $tenant->portal_domain ?: 'Tanımlı değil'],
        ['label' => 'Portal Adresi', 'value' => $tenantPortalPreviewUrl ?: 'Tanımlı değil'],
        ['label' => 'Varsayılan Para Birimi', 'value' => $tenant->default_currency ?: 'Tanımlı değil'],
        ['label' => 'Varsayılan Ülke Kodu', 'value' => $tenantSettingsSummary['country_code'] ?: '+90'],
        ['label' => 'Settings Kayıt Sayısı', 'value' => (string) ($tenantSettingsSummary['settings_count'] ?? 0)],
    ];
    $domainStatusToneMap = [
        'Taslak' => 'pd-badge-gray',
        'DNS Bekliyor' => 'pd-badge-amber',
        'Yapılandırıldı' => 'pd-badge-blue',
        'Canlı' => 'pd-badge-green',
        'Sorunlu' => 'pd-badge-red',
        'Başlamadı' => 'pd-badge-gray',
        'Bekliyor' => 'pd-badge-amber',
        'Aktif' => 'pd-badge-green',
        'Hata' => 'pd-badge-red',
    ];
    $domainLifecycleBlocks = [
        ['label' => 'Panel Durumu', 'value' => $domainStatusOptions[$domainLifecycleSettings['panel_status']] ?? $domainLifecycleSettings['panel_status']],
        ['label' => 'Özel Domain Durumu', 'value' => $domainStatusOptions[$domainLifecycleSettings['custom_status']] ?? $domainLifecycleSettings['custom_status']],
        ['label' => 'Özel Domain SSL', 'value' => $sslStatusOptions[$domainLifecycleSettings['custom_ssl_status']] ?? $domainLifecycleSettings['custom_ssl_status']],
        ['label' => 'Portal Domain Durumu', 'value' => $domainStatusOptions[$domainLifecycleSettings['portal_status']] ?? $domainLifecycleSettings['portal_status']],
        ['label' => 'Portal Domain SSL', 'value' => $sslStatusOptions[$domainLifecycleSettings['portal_ssl_status']] ?? $domainLifecycleSettings['portal_ssl_status']],
        ['label' => 'Operasyon Notu', 'value' => $domainLifecycleSettings['operations_note'] ?: 'Not yok'],
    ];
    $notificationTeamBlocks = [
        ['label' => 'SMTP', 'value' => $notificationSummary['smtp']['status_label']],
        ['label' => 'WhatsApp', 'value' => $notificationSummary['whatsapp']['status_label']],
        ['label' => 'Şablonlar', 'value' => $notificationSummary['templates']['count'] . ' / ' . $notificationSummary['templates']['status_label']],
        ['label' => 'Bildirim Geçmişi', 'value' => $notificationSummary['logs']['count'] . ' / ' . $notificationSummary['logs']['status_label']],
        ['label' => 'SMTP Host', 'value' => $notificationSummary['smtp']['host']],
        ['label' => 'SMTP Kullanıcı', 'value' => $notificationSummary['smtp']['username_masked']],
        ['label' => 'WhatsApp Telefonu', 'value' => $notificationSummary['whatsapp']['test_phone_masked']],
        ['label' => 'Başarısız Kayıt', 'value' => (string) $notificationSummary['logs']['failed_count']],
        ['label' => 'Toplam Kullanıcı', 'value' => (string) ($teamSummary['total_users'] ?? 0)],
        ['label' => 'Aktif Kullanıcı', 'value' => (string) ($teamSummary['active_users'] ?? 0)],
        ['label' => 'Owner Hazır mı?', 'value' => ($teamSummary['owner_ready'] ?? false) ? 'Hazır' : 'Eksik'],
        ['label' => 'Finans Yetkili', 'value' => ($teamSummary['has_finance_user'] ?? false) ? 'Var' : 'Yok'],
    ];
    $subscriptionBlocks = [
        ['label' => 'Aktif Paket', 'value' => $packageRecord?->name ?? ($tenant->package_key ?: 'Core')],
        ['label' => 'Lifecycle', 'value' => $lifecycleSettings['effective_state_label']],
        ['label' => 'Kalan Gün', 'value' => $subscription['days_remaining'] ?? 'Takip edilmiyor'],
        ['label' => 'Durum Mesajı', 'value' => $subscription['message']],
        ['label' => 'Deneme Başlangıcı', 'value' => $lifecycleSettings['trial_starts_at_label'] ?: 'Takip edilmiyor'],
        ['label' => 'Deneme Bitişi', 'value' => $trialEndsAtLabel ?: 'Takip edilmiyor'],
        ['label' => 'Paket Başlangıcı', 'value' => $lifecycleSettings['package_starts_at_label'] ?: 'Takip edilmiyor'],
        ['label' => 'Paket Bitişi', 'value' => $packageEndDateLabel ?: 'Takip edilmiyor'],
        ['label' => 'Durum Notu', 'value' => $lifecycleSettings['status_note'] ?: 'Yok'],
        ['label' => 'Askı Nedeni', 'value' => $lifecycleSettings['suspended_reason'] ?: 'Yok'],
    ];
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

    @if(!$ownerExists)
        <div class="pd-alert pd-alert-warning pd-gap-bottom-md">
            Owner kullanıcı henüz oluşturulmadı.
        </div>
    @endif

    <div class="pd-tenant-content-stack">
        <section class="pd-section-card pd-section-card-soft-slate" id="genel-bilgiler">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Genel Bilgiler ve Panel Adresleri</h3>
                    <p class="pd-section-subtitle">Abone Firma kimliği, owner, panel erişimi ve domain görünümü tek blokta toplanır.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-tenant-info-grid">
                    <div class="pd-tenant-info-card">
                        <div class="pd-tenant-info-label">Abone Firma Adı</div>
                        <div class="pd-tenant-info-value">{{ $tenant->name }}</div>
                    </div>
                    <div class="pd-tenant-info-card">
                        <div class="pd-tenant-info-label">Slug</div>
                        <div class="pd-tenant-info-value">{{ $tenant->slug }}</div>
                    </div>
                    <div class="pd-tenant-info-card">
                        <div class="pd-tenant-info-label">Owner</div>
                        <div class="pd-tenant-info-value">{{ $ownerUser?->name ?: 'Owner kullanıcı eksik' }}</div>
                    </div>
                    <div class="pd-tenant-info-card">
                        <div class="pd-tenant-info-label">Owner E-posta</div>
                        <div class="pd-tenant-info-value">{{ $ownerUser?->email ?: 'Eksik' }}</div>
                    </div>
                    <div class="pd-tenant-info-card">
                        <div class="pd-tenant-info-label">Oluşturulma</div>
                        <div class="pd-tenant-info-value">{{ $createdAtLabel }}</div>
                    </div>
                    @foreach($settingsBlocks as $item)
                        <div class="pd-tenant-info-card">
                            <div class="pd-tenant-info-label">{{ $item['label'] }}</div>
                            <div class="pd-tenant-info-value">{{ $item['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-blue" id="cari-fatura-kimligi">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Abone Firma Cari / Fatura Bilgileri</h3>
                    <p class="pd-section-subtitle">SaaS cari, fatura kesimi ve abonelik tahsilatı için gereken firma kimliği burada tutulur.</p>
                </div>
                <div class="pd-hero-actions">
                    <span class="pd-badge {{ $billingIdentityMissing->isEmpty() ? 'pd-badge-green' : 'pd-badge-amber' }}">
                        {{ $billingIdentityMissing->isEmpty() ? 'Fatura Hazır' : 'Eksik Bilgi Var' }}
                    </span>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-tenant-info-grid">
                    @foreach($billingIdentityItems as $item)
                        <div class="pd-tenant-info-card {{ $item['label'] === 'Açık Adres' ? 'pd-tenant-info-card-wide' : '' }}">
                            <div class="pd-tenant-info-label">{{ $item['label'] }}</div>
                            <div class="pd-tenant-info-value">{{ $item['value'] }}</div>
                        </div>
                    @endforeach
                </div>

                @if($billingIdentityMissing->isNotEmpty())
                    <div class="pd-alert pd-alert-warning pd-gap-top-md">
                        Fatura kimliğinde tamamlanması gereken alanlar:
                        <strong>{{ $billingIdentityMissing->implode(', ') }}</strong>
                    </div>
                @endif
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate" id="domain-lifecycle">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Domain Yaşam Döngüsü / DNS-SSL Görünürlüğü</h3>
                    <p class="pd-section-subtitle">Otomasyon açılmadan önce panel, özel domain ve portal domainin operasyon durumu manuel ama görünür şekilde takip edilir.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-tenant-info-grid">
                    @foreach($domainLifecycleBlocks as $item)
                        <div class="pd-tenant-info-card {{ $item['label'] === 'Operasyon Notu' ? 'pd-tenant-info-card-wide' : '' }}">
                            <div class="pd-tenant-info-label">{{ $item['label'] }}</div>
                            @if($item['label'] === 'Operasyon Notu')
                                <div class="pd-tenant-info-value">{{ $item['value'] }}</div>
                            @else
                                <div class="pd-tenant-info-value">
                                    <span class="pd-badge {{ $domainStatusToneMap[$item['value']] ?? 'pd-badge-gray' }}">{{ $item['value'] }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="pd-alert pd-alert-warning pd-gap-top-md">
                    {{ $paymentArchitectureNote }}
                </div>
            </div>
        </section>

        @include('super-admin.tenants._onboarding-status')

        <section class="pd-section-card pd-section-card-soft-slate" id="bildirim-ekip-hazirligi">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Bildirim Hazırlığı ve Ekip Durumu</h3>
                    <p class="pd-section-subtitle">SMTP, WhatsApp, ekip, owner ve operasyon kullanıcısı tek bakışta izlenir.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-tenant-info-grid">
                    @foreach($notificationTeamBlocks as $item)
                        <div class="pd-tenant-info-card">
                            <div class="pd-tenant-info-label">{{ $item['label'] }}</div>
                            <div class="pd-tenant-info-value">{{ $item['value'] }}</div>
                        </div>
                    @endforeach
                    <div class="pd-tenant-info-card">
                        <div class="pd-tenant-info-label">Son Kullanıcı Oluşturma</div>
                        <div class="pd-tenant-info-value">{{ $teamSummary['last_user_created_at'] }}</div>
                    </div>
                    <div class="pd-tenant-info-card">
                        <div class="pd-tenant-info-label">Son Bildirim</div>
                        <div class="pd-tenant-info-value">{{ $notificationSummary['logs']['last_log_at'] }}</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate" id="abonelik-kullanim">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Abonelik / Paket ve Kullanım</h3>
                    <p class="pd-section-subtitle">Lifecycle, kullanım sinyalleri ve paket limit kararları kompakt şekilde sunulur.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-tenant-info-grid">
                    @foreach($subscriptionBlocks as $item)
                        <div class="pd-tenant-info-card">
                            <div class="pd-tenant-info-label">{{ $item['label'] }}</div>
                            <div class="pd-tenant-info-value">{{ $item['value'] }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="pd-tenant-usage-grid pd-gap-top-md">
                    @foreach($usageSnapshot as $usage)
                        <div class="pd-tenant-usage-card">
                            <div class="pd-flex-between-start">
                                <div class="pd-tenant-info-label">{{ $usage['label'] }}</div>
                                <span class="pd-badge {{ $usageBadgeMap[$usage['status']] ?? 'pd-badge-gray' }}">
                                    {{ match($usage['status']) {'ok' => 'Normal', 'warning' => 'Uyarı', 'exceeded' => 'Limit Aşıldı', default => 'Limitsiz'} }}
                                </span>
                            </div>
                            <div class="pd-tenant-usage-value">{{ $usage['current'] }} / {{ $usage['limit'] ?? 'Limitsiz' }}</div>
                            @if($usage['percentage'] !== null)
                                <div class="pd-tenant-usage-note">{{ $usage['percentage'] }}% kullanım</div>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if(!empty($usageWarnings))
                    <div class="pd-alert pd-alert-warning pd-gap-top-md">
                        Kullanım uyarısı olan alanlar: {{ collect($usageWarnings)->pluck('label')->implode(', ') }}
                    </div>
                @endif
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate" id="domain-lifecycle-history">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Domain / Abonelik Geçmişi</h3>
                    <p class="pd-section-subtitle">Lifecycle, domain ve paket operasyonlarının ne zaman değiştiğini merkezi audit iziyle takip edin.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-timeline-list">
                    @foreach($tenantAuditTimeline as $item)
                        <div class="pd-timeline-item">
                            <div class="flex items-center justify-between gap-2">
                                <div class="pd-timeline-item-title">{{ $item['title'] }}</div>
                                <span class="pd-badge {{ match($item['tone']) {
                                    'green' => 'pd-badge-green',
                                    'amber' => 'pd-badge-amber',
                                    'red' => 'pd-badge-red',
                                    default => 'pd-badge-blue',
                                } }}">{{ $item['at'] }}</span>
                            </div>
                            <div class="pd-timeline-item-copy">{{ $item['description'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate" id="modul-override-ozeti">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Modül / Override Özeti</h3>
                    <p class="pd-section-subtitle">Açık modüller, kapalı modüller ve tenant override etkileri aynı blokta görünür.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-tenant-usage-grid">
                    <div class="pd-tenant-usage-card">
                        <div class="pd-tenant-info-label">Core Modüller</div>
                        <div class="pd-tenant-usage-value">{{ count($moduleSummary['core']) }}</div>
                    </div>
                    <div class="pd-tenant-usage-card">
                        <div class="pd-tenant-info-label">Aktif Modüller</div>
                        <div class="pd-tenant-usage-value">{{ count($moduleSummary['enabled_optional']) }}</div>
                    </div>
                    <div class="pd-tenant-usage-card">
                        <div class="pd-tenant-info-label">Kapalı Modüller</div>
                        <div class="pd-tenant-usage-value">{{ count($moduleSummary['disabled_optional']) }}</div>
                    </div>
                    <div class="pd-tenant-usage-card">
                        <div class="pd-tenant-info-label">Override</div>
                        <div class="pd-tenant-usage-value">{{ $moduleSummary['overridden_modules_count'] + $moduleSummary['overridden_features_count'] }}</div>
                    </div>
                </div>

                <div class="pd-grid pd-grid-2 pd-gap-top-md">
                    <div class="pd-card">
                        <div class="pd-card-header"><div><h3 class="pd-card-title">Aktif Modüller</h3></div></div>
                        <div class="pd-card-body">
                            <div class="pd-inline-wrap-sm">
                                @foreach($moduleSummary['enabled_optional'] as $module)
                                    <span class="pd-badge pd-badge-blue">{{ $module['label'] }}</span>
                                @endforeach
                                @if(empty($moduleSummary['enabled_optional']))
                                    <span class="text-sm text-gray-500">Ek aktif modül görünmüyor.</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="pd-card">
                        <div class="pd-card-header"><div><h3 class="pd-card-title">Kapalı Modüller</h3></div></div>
                        <div class="pd-card-body">
                            <div class="pd-inline-wrap-sm">
                                @foreach($moduleSummary['disabled_optional'] as $module)
                                    <span class="pd-badge pd-badge-gray">{{ $module['label'] }}</span>
                                @endforeach
                                @if(empty($moduleSummary['disabled_optional']))
                                    <span class="text-sm text-gray-500">Kapalı opsiyonel modül görünmüyor.</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pd-grid pd-grid-2 pd-gap-top-md">
                    <div class="pd-card">
                        <div class="pd-card-header"><div><h3 class="pd-card-title">Özellik Özeti</h3><p class="pd-card-subtitle">Paket varsayılanı ve tenant override ile açık kalan yetenekler.</p></div></div>
                        <div class="pd-card-body">
                            @php
                                $enabledFeatures = collect($featureRows)->filter(fn (array $row) => (bool) ($row['effective_enabled'] ?? false))->values();
                            @endphp
                            <div class="pd-inline-wrap-sm">
                                @foreach($enabledFeatures->take(12) as $feature)
                                    <span class="pd-badge {{ $feature['override_state'] === 'default' ? 'pd-badge-blue' : 'pd-badge-amber' }}">
                                        {{ $feature['feature_label'] }}
                                    </span>
                                @endforeach
                                @if($enabledFeatures->isEmpty())
                                    <span class="text-sm text-gray-500">Aktif özellik görünmüyor.</span>
                                @endif
                            </div>
                            @if($enabledFeatures->count() > 12)
                                <div class="text-sm text-gray-500 pd-gap-top-md">+{{ $enabledFeatures->count() - 12 }} ek özellik tenant içinde açık.</div>
                            @endif
                        </div>
                    </div>
                    <div class="pd-card">
                        <div class="pd-card-header"><div><h3 class="pd-card-title">Limit Özeti</h3><p class="pd-card-subtitle">Paket varsayılanı ile tenant override ayrımı.</p></div></div>
                        <div class="pd-card-body">
                            @php
                                $statusLabels = [
                                    'ok' => 'Normal',
                                    'warning' => 'Uyarı',
                                    'exceeded' => 'Limit Aşıldı',
                                    'unlimited' => 'Limitsiz',
                                ];
                            @endphp
                            <div class="pd-table-wrap">
                                <table class="pd-table">
                                    <thead><tr><th>Alan</th><th>Limit</th><th>Kaynak</th><th>Durum</th></tr></thead>
                                    <tbody>
                                        @foreach($limitRows as $limit)
                                            <tr>
                                                <td>{{ $limit['label'] }}</td>
                                                <td>{{ $limit['effective_limit'] ?? 'Limitsiz' }}</td>
                                                <td>{{ ($limit['override_mode'] ?? 'default') === 'default' ? 'Paket varsayılanı' : 'Tenant override' }}</td>
                                                <td>{{ $statusLabels[$limit['effective_status'] ?? 'unlimited'] ?? 'Takip edilmiyor' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pd-gap-top-md">
                    <div class="pd-card">
                        <div class="pd-card-header"><div><h3 class="pd-card-title">Operasyon Checklist</h3><p class="pd-card-subtitle">Canlıya hazırlık ve tenant readiness satır bazlı izlenir.</p></div></div>
                        <div class="pd-card-body">
                            <div class="pd-tenant-health-list">
                                @foreach($tenantOperationalChecklist as $item)
                                    <div class="pd-tenant-health-row">
                                        <div>
                                            <div class="pd-tenant-health-title">{{ $item['label'] }}</div>
                                            <div class="pd-tenant-health-copy">{{ $item['message'] }}</div>
                                        </div>
                                        <div class="pd-inline-actions">
                                            <span class="pd-badge {{ $readinessBadgeMap[$item['status'] ?? ($item['is_ready'] ? 'Hazır' : 'Eksik')] ?? 'pd-badge-gray' }}">
                                                {{ $item['status'] ?? ($item['is_ready'] ? 'Hazır' : 'Eksik') }}
                                            </span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
@endsection

@section('side_summary')
    <div class="pd-card">
        <div class="pd-card-body">
            <h3 class="pd-summary-title">Abone Firma Operasyon Paneli</h3>

            <div class="pd-summary-section">
                <h4 class="pd-summary-section-title">Hızlı İşlemler</h4>
                <div class="pd-summary-action-list">
                    @if(!$ownerExists)
                        <a href="{{ route('admin.super.tenants.owner.create', $tenant) }}" class="pd-summary-action">
                            <span>Owner Oluştur</span>
                            <strong>eksik</strong>
                        </a>
                    @endif
                    @foreach($quickActions as $action)
                        <a href="{{ $action['url'] }}" @if(!empty($action['opens_in_new_tab'])) target="_blank" rel="noreferrer" @endif class="pd-summary-action">
                            <span>{{ $action['label'] }}</span>
                            <strong>{{ $action['style'] === 'primary' ? 'ana aksiyon' : 'geçiş' }}</strong>
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="pd-summary-section">
                <h4 class="pd-summary-section-title">SaaS Cari Özet</h4>
                <div class="pd-summary-info">
                    <div class="pd-summary-row"><span>Cari bakiye</span><strong>{{ \App\Services\MoneyFormatter::format((float) ($billingSummary['balance'] ?? 0)) }}</strong></div>
                    <div class="pd-summary-row"><span>Toplam borç</span><strong>{{ \App\Services\MoneyFormatter::format((float) ($billingSummary['total_debit'] ?? 0)) }}</strong></div>
                    <div class="pd-summary-row"><span>Toplam alacak</span><strong>{{ \App\Services\MoneyFormatter::format((float) ($billingSummary['total_credit'] ?? 0)) }}</strong></div>
                    <div class="pd-summary-row"><span>Hareket sayısı</span><strong>{{ $billingSummary['entry_count'] ?? 0 }}</strong></div>
                </div>
            </div>

            <div class="pd-summary-section">
                <h4 class="pd-summary-section-title">Canlıya Hazırlık Kısa Notu</h4>
                <div class="pd-summary-info">
                    <div class="pd-summary-row"><span>Eksik alan</span><strong>{{ count($usageWarnings) + $billingIdentityMissing->count() }}</strong></div>
                    <div class="pd-summary-row"><span>Panel erişimi</span><strong>{{ ($subscription['can_access_admin'] ?? false) ? 'Açık' : 'Kısıtlı' }}</strong></div>
                    <div class="pd-summary-row"><span>Bildirim</span><strong>{{ $notificationSummary['smtp']['status_label'] }} / {{ $notificationSummary['whatsapp']['status_label'] }}</strong></div>
                </div>
                <div class="pd-note pd-gap-top-md">
                    DNS ve SSL otomasyonu bu fazda manuel doğrulama ile izlenir. Online ödeme tarafında Super Admin ortak provider omurgası sabit olacak, tenant tarafında ise modül olarak açılacaktır.
                </div>
            </div>

            <div class="pd-summary-section">
                <h4 class="pd-summary-section-title">Owner / Ekip Durumu</h4>
                <div class="pd-summary-info">
                    <div class="pd-summary-row"><span>Owner</span><strong>{{ $ownerUser?->name ?: 'Eksik' }}</strong></div>
                    <div class="pd-summary-row"><span>Owner E-posta</span><strong>{{ $ownerUser?->email ?: 'Eksik' }}</strong></div>
                    <div class="pd-summary-row"><span>Aktif kullanıcı</span><strong>{{ $teamSummary['active_users'] }}</strong></div>
                    <div class="pd-summary-row"><span>Finans rolü</span><strong>{{ $teamSummary['has_finance_user'] ? 'Var' : 'Yok' }}</strong></div>
                    <div class="pd-summary-row"><span>Operasyon rolü</span><strong>{{ $teamSummary['has_operations_user'] ? 'Var' : 'Yok' }}</strong></div>
                </div>
            </div>
        </div>
    </div>
@endsection
