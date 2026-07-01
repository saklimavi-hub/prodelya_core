@extends('layouts.prodelya-admin')

@section('title', 'Başvurular')
@section('page_topbar_hidden', '1')

@section('content')
<div class="pd-hub-family-shell">
    <div class="pd-request-hub-stack">
        <section class="pd-hero-card">
            <div class="pd-card-body">
                <div class="pd-hero-main">
                    <div class="pd-hero-copy">
                        <h1 class="pd-hero-title">Başvurular</h1>
                        <p class="pd-hero-subtitle">Public satış hunisinden gelen başvurular ile tenant sonrası paket taleplerini aynı operasyon çatısında yönetin.</p>
                    </div>
                </div>
            </div>
        </section>

        @include('super-admin.partials.request-hub-tabs')

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Kontrol Paneli</h3>
                    <p class="pd-section-subtitle">Canlı lead akışını hızlı karar verecek şekilde özetleyin.</p>
                </div>
                <div class="pd-chip-group">
                    <a href="{{ route('admin.super.signup-requests.index', ['status' => 'new']) }}" class="pd-chip {{ $status === 'new' ? 'is-active' : '' }}">Yeni</a>
                    <a href="{{ route('admin.super.signup-requests.index', ['status' => 'contacted']) }}" class="pd-chip {{ $status === 'contacted' ? 'is-active' : '' }}">İletişime Geçildi</a>
                    <a href="{{ route('admin.super.signup-requests.index', ['status' => 'converted']) }}" class="pd-chip {{ $status === 'converted' ? 'is-active' : '' }}">Dönüştürülen</a>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-mini-kpi-grid">
                    @foreach($summaryCards as $card)
                        <div class="pd-mini-kpi-card">
                            <div class="pd-mini-kpi-label">{{ $card['label'] }}</div>
                            <div class="pd-mini-kpi-value">{{ $card['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Filtreler</h3>
                    <p class="pd-section-subtitle">Firma, istek tipi, durum ve paket kırılımında başvuruları ayırın.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form method="GET" class="pd-grid pd-grid-4">
                    <div>
                        <label class="pd-label" for="search">Arama</label>
                        <input id="search" name="search" value="{{ $search }}" class="pd-input" placeholder="Firma, yetkili, telefon, e-posta">
                    </div>
                    <div>
                        <label class="pd-label" for="type">İstek Tipi</label>
                        <select id="type" name="type" class="pd-input">
                            <option value="">Tümü</option>
                            @foreach($typeOptions as $value => $label)
                                <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label" for="status">Durum</label>
                        <select id="status" name="status" class="pd-input">
                            <option value="">Tümü</option>
                            @foreach($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label" for="package">Paket</label>
                        <select id="package" name="package" class="pd-input">
                            <option value="">Tüm paketler</option>
                            @foreach($packages as $packageOption)
                                <option value="{{ $packageOption->key }}" @selected($package === $packageOption->key)>{{ $packageOption->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pd-request-hub-form-actions">
                        <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                        <a href="{{ route('admin.super.signup-requests.index') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                    </div>
                </form>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">1. Başvuru Listesi / Hızlı Önceliklendirme</h3>
                    <p class="pd-section-subtitle">Public satış hunisinden gelen trial ve demo taleplerini karar önceliğine göre sıralayın.</p>
                </div>
                <div class="pd-request-list-header-copy">
                    Durum, firma, paket ve kaynak bilgisini tek satırda karşılaştırın.
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-request-list-shell">
                    <div class="pd-request-list-legend pd-request-list-grid-signup">
                        <span>Durum</span>
                        <span>Firma / Talep</span>
                        <span>Paket</span>
                        <span>Hazırlık</span>
                        <span>Kaynak</span>
                        <span class="text-right">İşlem</span>
                    </div>

                    <div class="pd-request-row-list">
                        @forelse($requests as $item)
                            <article class="pd-request-row-card">
                                <div class="pd-request-row-grid pd-request-list-grid-signup">
                                    <div class="pd-request-row-status">
                                        <span class="pd-badge {{ match($item->status) {'new' => 'pd-badge-blue', 'contacted' => 'pd-badge-amber', 'converted' => 'pd-badge-green', 'rejected' => 'pd-badge-red', 'archived' => 'pd-badge-gray', default => 'pd-badge-gray'} }}">{{ $statusOptions[$item->status] ?? $item->status }}</span>
                                        <div class="pd-request-row-time">{{ optional($item->created_at)->format('d.m.Y H:i') }}</div>
                                    </div>
                                    <div>
                                        <div class="pd-request-cell-title">{{ $item->company_name }}</div>
                                        <div class="pd-request-cell-copy">{{ $typeOptions[$item->request_type] ?? $item->request_type }}</div>
                                        <div class="pd-request-cell-meta">{{ $item->contact_name }} · {{ $item->phone ?: 'Telefon yok' }} · {{ $item->email }}</div>
                                    </div>
                                    <div>
                                        <div class="pd-request-cell-title">{{ $item->requestedPackage?->name ?: ($item->requested_package_key ?: '-') }}</div>
                                        <div class="pd-request-cell-copy">{{ $item->city ?: 'Şehir bilgisi yok' }}</div>
                                    </div>
                                    <div>
                                        @php($readiness = $readinessById[$item->id] ?? null)
                                        @php($summaryBadge = $readiness['summary_badge'] ?? ['label' => 'Hazır', 'tone' => 'green'])
                                        <div class="pd-request-cell-title">
                                            <span class="pd-badge {{ match($summaryBadge['tone'] ?? 'green') {
                                                'red' => 'pd-badge-red',
                                                'amber' => 'pd-badge-amber',
                                                'blue' => 'pd-badge-blue',
                                                default => 'pd-badge-green',
                                            } }}">{{ $summaryBadge['label'] ?? 'Hazır' }}</span>
                                        </div>
                                        <div class="pd-request-cell-meta">
                                            @if(($readiness['severity'] ?? 'ready') === 'blocker')
                                                Dönüşüm öncesi kontrol gerekli
                                            @elseif(($readiness['severity'] ?? 'ready') === 'warning')
                                                Uyarıları açıp kontrol edin
                                            @else
                                                Dönüşüme hazır
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <div class="pd-request-cell-title">{{ $item->source ?: 'public_landing' }}</div>
                                        @if($item->convertedTenant)
                                            <div class="pd-request-cell-copy">
                                                <a href="{{ route('admin.super.tenants.show', $item->convertedTenant) }}" class="text-blue-700 hover:underline">Abone Firma Kaydını Aç</a>
                                            </div>
                                        @else
                                            <div class="pd-request-cell-meta">Henüz tenant kaydı açılmadı</div>
                                        @endif
                                    </div>
                                    <div class="pd-request-actions">
                                        <a href="{{ route('admin.super.signup-requests.show', $item) }}" class="pd-btn pd-btn-light">Detay</a>
                                        @if(!$item->converted_tenant_account_id && in_array($item->status, ['new', 'contacted'], true))
                                            <a href="{{ route('admin.super.signup-requests.conversion-preview', $item) }}" class="pd-btn pd-btn-primary">Dönüştür</a>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="pd-empty-card">
                                <div class="text-sm text-gray-500">Henüz public başvuru bulunmuyor.</div>
                            </div>
                        @endforelse
                    </div>
                </div>

                @if(method_exists($requests, 'links'))
                    <div class="mt-4">{{ $requests->links() }}</div>
                @endif
            </div>
        </section>
    </div>
</div>
@endsection

@section('side_summary')
    <div class="pd-decision-panel-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Başvuru Karar Paneli</h3>

                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Dönüşebilir Aday</span>
                        <span class="pd-badge pd-badge-green">{{ $conversionCandidatesCount }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Eksik İletişim</span>
                        <span class="pd-badge {{ $missingContactCount > 0 ? 'pd-badge-red' : 'pd-badge-green' }}">{{ $missingContactCount }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Filtre Durumu</span>
                        <span class="pd-badge pd-badge-blue">{{ $requests->total() }} kayıt</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Hızlı İşlemler</h3>

                <div class="pd-summary-action-list">
                    <a href="{{ route('admin.super.signup-requests.index', ['status' => 'new']) }}" class="pd-summary-action">
                        <span>Yeni Başvurular</span>
                        <span class="pd-badge pd-badge-blue">new</span>
                    </a>
                    <a href="{{ route('admin.super.signup-requests.index', ['status' => 'contacted']) }}" class="pd-summary-action">
                        <span>İletişime Geçilenler</span>
                        <span class="pd-badge pd-badge-amber">contacted</span>
                    </a>
                    <a href="{{ route('admin.super.signup-requests.index', ['status' => 'converted']) }}" class="pd-summary-action">
                        <span>Dönüştürülenler</span>
                        <span class="pd-badge pd-badge-green">converted</span>
                    </a>
                    <a href="{{ route('admin.super.signup-requests.index', ['type' => 'demo']) }}" class="pd-summary-action">
                        <span>Demo Talepleri</span>
                        <span class="pd-badge pd-badge-gray">demo</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Kural Notu</h3>

                <div class="pd-note">
                    <p class="text-sm text-gray-600">
                        Başvuru ekranı dönüşüm öncesi karar ve filtre yüzeyidir. Gerçek tenant oluşturma, mevcut create/onboarding akışında yürür.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
