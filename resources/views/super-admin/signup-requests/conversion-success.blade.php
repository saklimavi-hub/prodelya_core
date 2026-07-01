@extends('layouts.prodelya-admin')

@section('title', 'Dönüşüm Başarı Özeti')
@section('page_topbar_hidden', '1')

@php
    $tenant = $converted_tenant ?? [];
    $owner = $owner_summary ?? [];
    $package = $package_summary ?? [];
    $trial = $trial_summary ?? [];
    $actions = $next_actions ?? [];
@endphp

@section('content')
<div class="pd-hub-family-shell">
    <div class="pd-request-hub-stack">
        @if(session('success'))
            <div class="pd-alert pd-alert-success">{{ session('success') }}</div>
        @endif

        <section class="pd-hero-card">
            <div class="pd-card-body">
                <div class="pd-hero-main">
                    <div class="pd-hero-copy">
                        <h1 class="pd-hero-title">Abone Firma Oluşturuldu</h1>
                        <p class="pd-hero-subtitle">Dönüşüm tamamlandı. Oluşturulan Abone Firma, panel yetkilisi, paket/trial durumu ve onboarding hazırlığı aşağıda özetlenir.</p>
                    </div>
                    <div class="pd-hero-actions">
                        <a href="{{ $actions['tenant_show'] ?? route('admin.super.tenants.show', $requestItem->convertedTenant) }}" class="pd-btn pd-btn-primary">Abone Firma Detayına Git</a>
                        <a href="{{ $actions['signup_show'] ?? route('admin.super.signup-requests.show', $requestItem) }}" class="pd-btn pd-btn-light">Başvuru Detayına Dön</a>
                    </div>
                </div>
            </div>
        </section>

        @include('super-admin.partials.request-hub-tabs')

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Dönüşüm Özeti</h3>
                    <p class="pd-section-subtitle">Abone Firma, paket, trial ve panel yetkilisi bilgileri başarıyla bağlandı.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-detail-grid">
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Abone Firma</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Adı</span><strong>{{ $tenant['name'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Panel Adresi</span><strong>{{ $tenant['panel_subdomain'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Durum</span><strong>{{ $tenant['status_label'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Paket</span><strong>{{ $package['name'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Başvuru Tipi</span><strong>{{ $typeOptions[$requestItem->request_type] ?? $requestItem->request_type }}</strong></div>
                            <div class="pd-detail-row"><span>Dönüşüm Tarihi</span><strong>{{ $converted_at ?: '-' }}</strong></div>
                        </div>
                    </div>
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Panel Yetkilisi</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Ad Soyad</span><strong>{{ $owner['name'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>E-posta</span><strong>{{ $owner['email'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Telefon</span><strong>{{ $owner['phone'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Durum</span><strong>{{ ($owner['exists'] ?? false) ? 'Hazır' : 'Kontrol Gerekir' }}</strong></div>
                        </div>
                    </div>
                </div>

                @if(filled($trial['starts_at'] ?? null) || filled($trial['ends_at'] ?? null))
                    <div class="pd-grid pd-grid-3 mt-4">
                        <div class="pd-tenant-info-card">
                            <div class="pd-tenant-info-label">Trial Başlangıç</div>
                            <div class="pd-tenant-info-value">{{ $trial['starts_at'] ?: 'Belirtilmedi' }}</div>
                        </div>
                        <div class="pd-tenant-info-card">
                            <div class="pd-tenant-info-label">Trial Bitiş</div>
                            <div class="pd-tenant-info-value">{{ $trial['ends_at'] ?: 'Belirtilmedi' }}</div>
                        </div>
                        <div class="pd-tenant-info-card">
                            <div class="pd-tenant-info-label">Trial Gün</div>
                            <div class="pd-tenant-info-value">{{ $trial['days'] ?: 'Belirtilmedi' }}</div>
                        </div>
                    </div>
                @endif

                @if(filled($requestItem->demo_topic))
                    <div class="pd-alert pd-alert-warning mt-4">Demo konusu: {{ $requestItem->demo_topic }}</div>
                @endif
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Modül Tercihleri</h3>
                    <p class="pd-section-subtitle">Başvurudan gelen modül tercihleri bilgi olarak taşınır; otomatik module override yapılmaz.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="flex flex-wrap gap-2">
                    @forelse(($requested_modules_summary ?? []) as $moduleKey)
                        <span class="pd-badge pd-badge-blue">{{ $moduleKey }}</span>
                    @empty
                        <span class="text-sm text-gray-600">Başvuruda modül tercihi yok.</span>
                    @endforelse
                </div>
                <div class="pd-alert pd-alert-warning mt-3">Bu modüller başvuru tercihi olarak taşındı. Otomatik module override oluşturulmadı.</div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Onboarding Hazırlığı</h3>
                    <p class="pd-section-subtitle">Başlangıç ayarları ve kurulum görünürlüğü kompakt checklist olarak gösterilir.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-tenant-health-list">
                    @foreach($onboarding_checklist ?? [] as $item)
                        <div class="pd-tenant-health-row">
                            <div class="pd-tenant-health-title">{{ $item['label'] }}</div>
                            <span class="pd-badge {{ match($item['tone']) {
                                'green' => 'pd-badge-green',
                                'amber' => 'pd-badge-amber',
                                default => 'pd-badge-blue',
                            } }}">{{ $item['state'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    </div>
</div>
@endsection

@section('side_summary')
    <div class="pd-decision-panel-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Sonraki Adımlar</h3>
                <div class="pd-summary-action-list">
                    <a href="{{ $actions['tenant_show'] ?? '#' }}" class="pd-summary-action">
                        <span>Abone Firma Detayına Git</span>
                        <span class="pd-badge pd-badge-green">open</span>
                    </a>
                    <a href="{{ $actions['tenant_panel'] ?? '#' }}" class="pd-summary-action">
                        <span>Abone Firma Panelini Aç</span>
                        <span class="pd-badge pd-badge-blue">panel</span>
                    </a>
                    <a href="{{ $actions['tenant_show'] ?? '#' }}" class="pd-summary-action">
                        <span>Onboarding Hazırlığını Gör</span>
                        <span class="pd-badge pd-badge-amber">check</span>
                    </a>
                    <a href="{{ $actions['signup_show'] ?? '#' }}" class="pd-summary-action">
                        <span>Başvuru Detayına Dön</span>
                        <span class="pd-badge pd-badge-blue">back</span>
                    </a>
                    <a href="{{ $actions['owner_create'] ?? '#' }}" class="pd-summary-action">
                        <span>Panel Yetkilisini Gör</span>
                        <span class="pd-badge pd-badge-blue">owner</span>
                    </a>
                    <a href="{{ $actions['tenant_edit'] ?? '#' }}" class="pd-summary-action">
                        <span>Paket / Limit Ayarlarını Aç</span>
                        <span class="pd-badge pd-badge-blue">plan</span>
                    </a>
                    <div class="pd-summary-action">
                        <span>Hoş Geldiniz E-postası Hazırla</span>
                        <span class="pd-badge pd-badge-gray">yakında</span>
                    </div>
                    <div class="pd-summary-action">
                        <span>Giriş Bilgisi Gönder</span>
                        <span class="pd-badge pd-badge-gray">sonraki faz</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Dönüşüm Kaydı</h3>
                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Dönüşüm Event</span>
                        <span class="font-medium">{{ $conversion_audit_reference ?? 'signup_request_conversion_completed' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Dönüştürülme</span>
                        <span class="font-medium">{{ $converted_at ?: '-' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
