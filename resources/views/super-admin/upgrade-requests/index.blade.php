@extends('layouts.prodelya-admin')

@section('title', 'Başvurular / Abone Firma Talepleri')
@section('page_topbar_hidden', '1')

@php
    $statusBadgeClasses = [
        'pending' => 'pd-badge-amber',
        'in_review' => 'pd-badge-blue',
        'approved' => 'pd-badge-green',
        'rejected' => 'pd-badge-gray',
        'applied' => 'pd-badge-green',
        'cancelled' => 'pd-badge-gray',
    ];
@endphp

@section('content')
<div class="pd-hub-family-shell">
    <div class="pd-request-hub-stack">
        <section class="pd-hero-card">
            <div class="pd-card-body">
                <div class="pd-hero-main">
                    <div class="pd-hero-copy">
                        <h1 class="pd-hero-title">Abone Firma Talepleri</h1>
                        <p class="pd-hero-subtitle">Abone Firmalardan gelen paket, modül, özellik, limit, tedarikçi ve ek hizmet taleplerini tek karar ekranında yönetin.</p>
                    </div>
                </div>
            </div>
        </section>

        @include('super-admin.partials.request-hub-tabs')

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Kontrol Paneli</h3>
                    <p class="pd-section-subtitle">Açık talepleri, onay bekleyenleri ve uygulama fazına kalacak kayıtları tek ritimde özetleyin.</p>
                </div>
                <div class="pd-chip-group">
                    <a href="{{ route('admin.super.upgrade-requests.index', ['queue' => 'open']) }}" class="pd-chip {{ ($filters['queue'] ?? '') === 'open' ? 'is-active' : '' }}">Açık Talepler</a>
                    <a href="{{ route('admin.super.upgrade-requests.index', ['queue' => 'waiting']) }}" class="pd-chip {{ ($filters['queue'] ?? '') === 'waiting' ? 'is-active' : '' }}">Onay Bekleyenler</a>
                    <a href="{{ route('admin.super.upgrade-requests.index', ['queue' => 'awaiting_apply']) }}" class="pd-chip {{ ($filters['queue'] ?? '') === 'awaiting_apply' ? 'is-active' : '' }}">Uygulama Bekleyenler</a>
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
                    <p class="pd-section-subtitle">Abone Firma, talep tipi, durum ve tarih aralığına göre talepleri ayırın.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form method="GET" class="pd-grid pd-grid-4">
                    <div>
                        <label class="pd-label" for="search">Arama</label>
                        <input id="search" name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Abone Firma, kullanıcı veya not">
                    </div>
                    <div>
                        <label class="pd-label" for="request_type">Talep Tipi</label>
                        <select id="request_type" name="request_type" class="pd-input">
                            <option value="">Tümü</option>
                            @foreach($requestTypeOptions as $key => $label)
                                <option value="{{ $key }}" @selected($filters['request_type'] === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label" for="status">Durum</label>
                        <select id="status" name="status" class="pd-input">
                            <option value="">Tümü</option>
                            @foreach($statusOptions as $key => $label)
                                <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label" for="tenant_account_id">Abone Firma</label>
                        <select id="tenant_account_id" name="tenant_account_id" class="pd-input">
                            <option value="">Tümü</option>
                            @foreach($tenants as $tenant)
                                <option value="{{ $tenant->id }}" @selected((int) ($filters['tenant_account_id'] ?? 0) === $tenant->id)>{{ $tenant->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label" for="date_from">Başlangıç</label>
                        <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="pd-input">
                    </div>
                    <div>
                        <label class="pd-label" for="date_to">Bitiş</label>
                        <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="pd-input">
                    </div>
                    <div>
                        <label class="pd-label" for="queue">Hazır Filtre</label>
                        <select id="queue" name="queue" class="pd-input">
                            <option value="">Tümü</option>
                            <option value="open" @selected($filters['queue'] === 'open')>Açık Talepler</option>
                            <option value="waiting" @selected($filters['queue'] === 'waiting')>Onay Bekleyenler</option>
                            <option value="awaiting_apply" @selected($filters['queue'] === 'awaiting_apply')>Uygulama Bekleyenler</option>
                        </select>
                    </div>
                    <div class="pd-request-hub-form-actions">
                        <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                        <a href="{{ route('admin.super.upgrade-requests.index') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                    </div>
                </form>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Talep Listesi</h3>
                    <p class="pd-section-subtitle">Talep tipini, hedef etkisini ve son işlem zamanını tek satırda görün.</p>
                </div>
                <div class="pd-request-list-header-copy">Onay kararı detay ekranında verilir; bu fazda uygulama adımı açılmayacaktır.</div>
            </div>
            <div class="pd-section-body">
                <div class="pd-request-list-shell">
                    <div class="pd-request-list-legend pd-request-list-grid-package">
                        <span>Durum</span>
                        <span>Abone Firma / Talep</span>
                        <span>Talep Özeti</span>
                        <span>Talep Eden</span>
                        <span class="text-right">İşlem</span>
                    </div>

                    <div class="pd-request-row-list">
                        @forelse($requests as $item)
                            <article class="pd-request-row-card">
                                <div class="pd-request-row-grid pd-request-list-grid-package">
                                    <div class="pd-request-row-status">
                                        <span class="pd-badge {{ $statusBadgeClasses[$item->status] ?? 'pd-badge-gray' }}">{{ $item->statusLabel() }}</span>
                                        <div class="pd-request-row-time">{{ optional($item->created_at)->format('d.m.Y H:i') }}</div>
                                    </div>
                                    <div>
                                        <div class="pd-request-cell-title">{{ $item->tenantAccount?->name ?: '-' }}</div>
                                        <div class="pd-request-cell-copy">{{ $item->tenantAccount?->slug ?: 'Abone Firma kodu yok' }}</div>
                                        <div class="pd-request-cell-meta">{{ $item->requestTypeLabel() }}</div>
                                    </div>
                                    <div>
                                        <div class="pd-request-cell-title">
                                            @if($item->isPackageUpgrade())
                                                {{ $item->current_package_key ?: '-' }} → {{ $item->requested_package_key ?: '-' }}
                                            @elseif($item->isModuleAddon())
                                                {{ $item->requested_module_key ?: '-' }}
                                            @elseif($item->isFeatureAddon())
                                                {{ $item->requested_feature_key ?: '-' }}
                                            @elseif($item->isLimitIncrease())
                                                {{ $item->requested_limit_key ?: '-' }} → {{ $item->requested_limit_value ?: '-' }}
                                            @elseif($item->isSupplierAccess())
                                                {{ $item->requested_supplier_key ?: ('#' . $item->requested_supplier_id) }}
                                            @else
                                                {{ $item->requested_service_key ?: 'Özel hizmet' }}
                                            @endif
                                        </div>
                                        <div class="pd-request-cell-copy">{{ $item->requested_note ?: 'Ek not girilmedi.' }}</div>
                                        <div class="pd-request-cell-meta">Son işlem: {{ optional($item->reviewed_at ?? $item->updated_at)->format('d.m.Y H:i') ?: '-' }}</div>
                                    </div>
                                    <div>
                                        <div class="pd-request-cell-title">{{ $item->requestedBy?->name ?: '-' }}</div>
                                        <div class="pd-request-cell-meta">{{ $item->requestedBy?->email ?: '-' }}</div>
                                    </div>
                                    <div class="pd-request-actions">
                                        <a href="{{ route('admin.super.upgrade-requests.show', $item) }}" class="pd-btn pd-btn-light">Detay</a>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="pd-empty-card">
                                <div class="text-sm text-gray-500">Henüz generic Abone Firma talebi yok.</div>
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4">{{ $requests->links() }}</div>
            </div>
        </section>
    </div>
</div>
@endsection

@section('side_summary')
    <div class="pd-decision-panel-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Talep Karar Paneli</h3>
                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Açık Talepler</span>
                        <span class="pd-badge pd-badge-amber">{{ $sideSummary['open_count'] }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>İnceleme Kuyruğu</span>
                        <span class="pd-badge pd-badge-blue">{{ $sideSummary['pending_count'] + $sideSummary['review_count'] }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Uygulama Bekleyen</span>
                        <span class="pd-badge pd-badge-green">{{ $sideSummary['approved_count'] }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Filtre Sonucu</span>
                        <span class="pd-badge pd-badge-blue">{{ $sideSummary['filtered_count'] }} kayıt</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Hızlı İşlemler</h3>
                <div class="pd-summary-action-list">
                    <a href="{{ route('admin.super.upgrade-requests.index', ['queue' => 'open']) }}" class="pd-summary-action">
                        <span>Açık Talepleri Aç</span>
                        <span class="pd-badge pd-badge-amber">open</span>
                    </a>
                    <a href="{{ route('admin.super.upgrade-requests.index', ['status' => 'pending']) }}" class="pd-summary-action">
                        <span>Bekleyenler</span>
                        <span class="pd-badge pd-badge-amber">pending</span>
                    </a>
                    <a href="{{ route('admin.super.upgrade-requests.index', ['status' => 'approved']) }}" class="pd-summary-action">
                        <span>Uygulama Bekleyenler</span>
                        <span class="pd-badge pd-badge-green">approved</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Operasyon Notu</h3>
                <div class="pd-note">
                    <p class="text-sm text-gray-600">Onay kararı bu fazda yalnız talebin statüsünü değiştirir. Gerçek paket/modül/limit/tedarikçi uygulaması D5 fazında açılacaktır.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
