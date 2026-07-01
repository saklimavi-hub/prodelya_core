@extends('layouts.prodelya-admin')

@section('title', 'Dönüşüm Önizleme')
@section('page_topbar_hidden', '1')

@php
    $cta = $next_action ?? ['state' => 'ready', 'label' => 'Tenant Create Formuna Devam Et', 'enabled' => true];
    $packageSummary = $package_summary ?? [];
    $ownerSummary = $owner_summary ?? [];
    $tenantSummary = $tenant_summary ?? [];
@endphp

@section('content')
<div class="pd-hub-family-shell">
    <div class="pd-request-hub-stack">
        @if(session('success'))
            <div class="pd-alert pd-alert-success">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div class="pd-alert pd-alert-danger">{{ session('error') }}</div>
        @endif

        <section class="pd-hero-card">
            <div class="pd-card-body">
                <div class="pd-hero-main">
                    <div class="pd-hero-copy">
                        <h1 class="pd-hero-title">Dönüşüm Önizleme</h1>
                        <p class="pd-hero-subtitle">Abone Firma oluşturma ekranına geçmeden önce ne oluşturulacağını, riskleri ve panel yetkilisi kararını tek ekranda doğrulayın.</p>
                    </div>
                    <div class="pd-hero-actions">
                        <a href="{{ route('admin.super.signup-requests.show', $requestItem) }}" class="pd-btn pd-btn-light">Başvuru Detayına Dön</a>
                        @if(($cta['state'] ?? null) === 'converted' && $requestItem->convertedTenant)
                            <a href="{{ route('admin.super.tenants.show', $requestItem->convertedTenant) }}" class="pd-btn pd-btn-primary">Abone Firma Aç</a>
                        @elseif(($cta['enabled'] ?? false) === true)
                            <a href="{{ $cta['continue_url'] ?? route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]) }}" class="pd-btn pd-btn-primary">{{ $cta['label'] }}</a>
                        @else
                            <span class="pd-btn pd-btn-light" aria-disabled="true">{{ $cta['label'] }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        @include('super-admin.partials.request-hub-tabs')

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Başvuru Özeti</h3>
                    <p class="pd-section-subtitle">Public satış hunisinden gelen kayıt, paket ve tercih bilgileri.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-detail-grid">
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Başvuru Bilgileri</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Başvuru Tipi</span><strong>{{ $typeOptions[$requestItem->request_type] ?? $requestItem->request_type }}</strong></div>
                            <div class="pd-detail-row"><span>Firma</span><strong>{{ $requestItem->company_name }}</strong></div>
                            <div class="pd-detail-row"><span>Firma Yetkilisi</span><strong>{{ $requestItem->contact_name }}</strong></div>
                            <div class="pd-detail-row"><span>Telefon</span><strong>{{ $requestItem->phone }}</strong></div>
                            <div class="pd-detail-row"><span>E-posta</span><strong>{{ $requestItem->email }}</strong></div>
                            <div class="pd-detail-row"><span>Şehir</span><strong>{{ $requestItem->city ?: 'Belirtilmedi' }}</strong></div>
                            <div class="pd-detail-row"><span>Sektör</span><strong>{{ $requestItem->sector ?: 'Belirtilmedi' }}</strong></div>
                        </div>
                    </div>
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Tercihler</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Seçilen Paket</span><strong>{{ $packageSummary['name'] ?? ($requestItem->requestedPackage?->name ?: 'Belirtilmedi') }}</strong></div>
                            <div class="pd-detail-row"><span>Paket Durumu</span><strong>{{ $packageSummary['status_label'] ?? ($readiness['package_status']['label'] ?? 'Belirtilmedi') }}</strong></div>
                            <div class="pd-detail-row"><span>Beklenen Kullanıcı</span><strong>{{ $requestItem->expected_user_count ?: 'Belirtilmedi' }}</strong></div>
                            <div class="pd-detail-row"><span>Demo Konusu</span><strong>{{ $requestItem->demo_topic ?: 'Belirtilmedi' }}</strong></div>
                        </div>
                        <div class="mt-4">
                            <div class="pd-label">Seçilen Modüller</div>
                            <div class="flex flex-wrap gap-2 mt-2">
                                @forelse(($requested_modules_summary ?? []) as $moduleKey)
                                    <span class="pd-badge pd-badge-blue">{{ $moduleKey }}</span>
                                @empty
                                    <span class="text-sm text-gray-600">Modül tercihi yok.</span>
                                @endforelse
                            </div>
                            <p class="text-sm text-gray-500 mt-2">Bu modüller başvuru tercihi olarak taşınır. Paket/modül erişimi Abone Firma ayarlarında ayrıca yönetilir. Bu adım otomatik modül override oluşturmaz.</p>
                        </div>
                        <div class="mt-4">
                            <div class="pd-label">Başvuru Notu</div>
                            <div class="text-sm text-gray-700 mt-1">{{ $requestItem->note ?: 'Not yok.' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Oluşturulacak Abone Firma</h3>
                    <p class="pd-section-subtitle">Create formuna taşınacak öneriler, trial/demo başlangıç kararı ve paket özeti.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-detail-grid">
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Abone Firma Özeti</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Abone Firma Adı</span><strong>{{ $tenantSummary['name'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Legal Name</span><strong>{{ $tenantSummary['legal_name'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Slug / Kısa Kod</span><strong>{{ $tenantSummary['slug'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Panel Adresi</span><strong>{{ $tenantSummary['panel_subdomain'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Başlangıç Durumu</span><strong>{{ $tenantSummary['status_label'] ?? 'Super Admin kararına bağlı' }}</strong></div>
                            @if($requestItem->request_type === \App\Models\TenantSignupRequest::TYPE_TRIAL)
                                <div class="pd-detail-row"><span>Trial Gün Sayısı</span><strong>{{ $tenantSummary['trial_days'] ?? ($packageSummary['trial_days'] ?? 30) }}</strong></div>
                                <div class="pd-detail-row"><span>Trial Bitiş Önerisi</span><strong>{{ $tenantSummary['trial_ends_at'] ?? '-' }}</strong></div>
                            @endif
                        </div>
                    </div>
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Paket Özeti</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Paket</span><strong>{{ $packageSummary['name'] ?? 'Belirtilmedi' }}</strong></div>
                            <div class="pd-detail-row"><span>Modül</span><strong>{{ $packageSummary['module_count'] ?? 0 }}</strong></div>
                            <div class="pd-detail-row"><span>Feature</span><strong>{{ $packageSummary['feature_count'] ?? 0 }}</strong></div>
                            <div class="pd-detail-row"><span>Limit</span><strong>{{ $packageSummary['limit_count'] ?? 0 }}</strong></div>
                        </div>
                        @if(!empty($packageSummary['limits']))
                            <div class="mt-4">
                                <div class="pd-label">Paket Limit Özeti</div>
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @foreach(array_slice($packageSummary['limits'], 0, 6) as $limit)
                                        <span class="pd-badge pd-badge-blue">{{ $limit['label'] }}: {{ $limit['value'] }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Panel Yetkilisi</h3>
                    <p class="pd-section-subtitle">Bu fazda panel yetkilisi merge/attach akışı yok; yalnız hangi panel yetkilisi senaryosunun oluşacağı görünür.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-detail-grid">
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Yetkili Özeti</div>
                        <div class="pd-detail-list">
                            <div class="pd-detail-row"><span>Ad Soyad</span><strong>{{ $ownerSummary['name'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>E-posta</span><strong>{{ $ownerSummary['email'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>Telefon</span><strong>{{ $ownerSummary['phone'] ?? '-' }}</strong></div>
                            <div class="pd-detail-row"><span>E-posta Durumu</span><strong>{{ $ownerSummary['status']['label'] ?? 'Hazır' }}</strong></div>
                        </div>
                    </div>
                    <div class="pd-detail-card">
                        <div class="pd-detail-card-title">Owner Kararı</div>
                        <div class="text-sm text-gray-700">
                            @if(($ownerSummary['will_create_new_user'] ?? false) === true)
                                Yeni panel yetkilisi oluşturulacak.
                            @elseif(!empty($ownerSummary['existing_user_warning']))
                                {{ $ownerSummary['existing_user_warning'] }}
                            @else
                                {{ $ownerSummary['status']['message'] ?? 'Firma yetkilisi bilgisi create akışında tekrar kontrol edilir.' }}
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Risk ve Hazırlık</h3>
                    <p class="pd-section-subtitle">Warning ve blocker’lar tenant create formuna geçmeden önce burada görünür.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-grid pd-grid-2">
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <div class="pd-label">Readiness Sonucu</div>
                            <div class="mt-2">
                                <span class="pd-badge {{ match($readiness['severity'] ?? 'ready') {
                                    'blocker' => 'pd-badge-red',
                                    'warning' => 'pd-badge-amber',
                                    default => 'pd-badge-green',
                                } }}">{{ ($readiness['can_convert'] ?? false) ? 'Hazır' : 'Dönüştürülemez' }}</span>
                            </div>
                            <div class="text-sm text-gray-500 mt-2">
                                @if(($readiness['severity'] ?? 'ready') === 'blocker')
                                    Devam etmeden önce blocker kayıtları çözülmeli.
                                @elseif(($readiness['severity'] ?? 'ready') === 'warning')
                                    Uyarılarla devam edilebilir, ancak karar Super Admin’de.
                                @else
                                    Mevcut başvuru create formuna güvenli prefill ile taşınabilir.
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="pd-card">
                        <div class="pd-card-body">
                            <div class="pd-label">Panel Adresi / Paket / Modül Notu</div>
                            <div class="text-sm text-gray-700 mt-2">Panel adresi: {{ $tenantSummary['panel_subdomain'] ?? '-' }}</div>
                            <div class="text-sm text-gray-700 mt-1">Paket: {{ $packageSummary['name'] ?? 'Belirtilmedi' }}</div>
                            <div class="text-sm text-gray-500 mt-2">Modül tercihleri bilgi olarak taşınır; otomatik modül override yapılmaz.</div>
                        </div>
                    </div>
                </div>

                @if(!empty($readiness['blockers']))
                    <div class="mt-4">
                        <div class="pd-label">Blocker Listesi</div>
                        <div class="flex flex-wrap gap-2 mt-2">
                            @foreach($readiness['blockers'] as $item)
                                <span class="pd-badge pd-badge-red">{{ $item }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if(!empty($readiness['warnings']))
                    <div class="mt-4">
                        <div class="pd-label">Warning Listesi</div>
                        <div class="flex flex-wrap gap-2 mt-2">
                            @foreach($readiness['warnings'] as $item)
                                <span class="pd-badge pd-badge-amber">{{ $item }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if(!empty($readiness['duplicate_company_matches']))
                    <div class="mt-4">
                        <div class="pd-label">Benzer Abone Firma Kayıtları</div>
                        <div class="flex flex-wrap gap-2 mt-2">
                            @foreach($readiness['duplicate_company_matches'] as $match)
                                <span class="pd-badge pd-badge-amber">{{ $match['name'] }} / {{ $match['panel_subdomain'] }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if(!empty($conversion_notes))
                    <div class="mt-4">
                        <div class="pd-label">Bilgi Notları</div>
                        <div class="flex flex-wrap gap-2 mt-2">
                            @foreach($conversion_notes as $item)
                                <span class="pd-badge pd-badge-blue">{{ $item }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Sonraki Adım</h3>
                    <p class="pd-section-subtitle">Preview tamamlandıktan sonra create formuna güvenli şekilde ilerleyin.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-form-actions">
                    @if(($cta['state'] ?? null) === 'converted' && $requestItem->convertedTenant)
                        <a href="{{ route('admin.super.tenants.show', $requestItem->convertedTenant) }}" class="pd-btn pd-btn-primary">Abone Firma Aç</a>
                    @elseif(($cta['enabled'] ?? false) === true)
                        <a href="{{ $cta['continue_url'] ?? route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]) }}" class="pd-btn pd-btn-primary">{{ $cta['label'] }}</a>
                    @else
                        <span class="pd-btn pd-btn-light" aria-disabled="true">{{ $cta['label'] }}</span>
                    @endif
                    <a href="{{ route('admin.super.signup-requests.show', $requestItem) }}" class="pd-btn pd-btn-light">Başvuru Detayına Dön</a>
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
                <h3 class="pd-summary-title">Karar Özeti</h3>
                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Başvuru Durumu</span>
                        <span class="pd-badge {{ match($requestItem->status) {
                            'converted' => 'pd-badge-green',
                            'contacted' => 'pd-badge-amber',
                            'rejected' => 'pd-badge-red',
                            'archived' => 'pd-badge-gray',
                            default => 'pd-badge-blue',
                        } }}">{{ $statusOptions[$requestItem->status] ?? $requestItem->status }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Hazırlık</span>
                        <span class="pd-badge {{ match($readiness['severity'] ?? 'ready') {
                            'blocker' => 'pd-badge-red',
                            'warning' => 'pd-badge-amber',
                            default => 'pd-badge-green',
                        } }}">{{ ($readiness['can_convert'] ?? false) ? 'Hazır' : 'Dönüştürülemez' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Panel Adresi</span>
                        <span class="font-medium">{{ $tenantSummary['panel_subdomain'] ?? '-' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Paket</span>
                        <span class="font-medium">{{ $packageSummary['name'] ?? 'Belirtilmedi' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Ana Aksiyon</h3>
                <div class="pd-summary-action-list">
                    @if(($cta['state'] ?? null) === 'converted' && $requestItem->convertedTenant)
                        <a href="{{ route('admin.super.tenants.show', $requestItem->convertedTenant) }}" class="pd-summary-action">
                            <span>Abone Firma Aç</span>
                            <span class="pd-badge pd-badge-green">open</span>
                        </a>
                    @elseif(($cta['enabled'] ?? false) === true)
                        <a href="{{ $cta['continue_url'] ?? route('admin.super.tenants.create', ['signup_request_id' => $requestItem->id]) }}" class="pd-summary-action">
                            <span>{{ $cta['label'] }}</span>
                            <span class="pd-badge {{ ($cta['state'] ?? 'ready') === 'warning' ? 'pd-badge-amber' : 'pd-badge-green' }}">next</span>
                        </a>
                    @else
                        <div class="pd-summary-action">
                            <span>{{ $cta['label'] }}</span>
                            <span class="pd-badge pd-badge-red">blocked</span>
                        </div>
                    @endif
                    <a href="{{ route('admin.super.signup-requests.show', $requestItem) }}" class="pd-summary-action">
                        <span>Başvuru Detayına Dön</span>
                        <span class="pd-badge pd-badge-blue">back</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Dönüşüm Logu</h3>
                <div class="pd-timeline-list">
                    @foreach(array_slice($activityTimeline, 0, 6) as $item)
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
        </div>
    </div>
@endsection
