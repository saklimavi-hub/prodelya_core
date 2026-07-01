@extends('layouts.prodelya-admin')

@section('title', 'Abone Firmalar')

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Abone Firmalar</h1>
                    <p class="pd-hero-subtitle">Paket, owner, panel adresi, onboarding ve kullanım uyarılarını tek listede izleyin.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.tenants.create') }}" class="pd-btn pd-btn-primary">Yeni Abone Firma</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-body">
            <div class="pd-mini-kpi-strip">
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Toplam</div><div class="pd-mini-kpi-value">{{ $tenants->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Aktif</div><div class="pd-mini-kpi-value">{{ $tenants->where('subscription_status', 'active')->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Deneme</div><div class="pd-mini-kpi-value">{{ $tenants->where('subscription_status', 'trial')->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Onboarding Eksik</div><div class="pd-mini-kpi-value">{{ $tenants->where('onboarding_ready', false)->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Usage Uyarı</div><div class="pd-mini-kpi-value">{{ $tenants->where('has_usage_warning', true)->count() }}</div></div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler</h3>
                <p class="pd-section-subtitle">Canlı operasyon için yalnız problemli veya belirli paket/durumdaki Abone Firmaları ayırın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" class="pd-stack-md">
                <div class="pd-filter-row pd-filter-row-4">
                <div>
                    <label class="pd-label" for="search">Arama</label>
                    <input id="search" name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Firma, owner, panel, domain">
                </div>
                <div>
                    <label class="pd-label" for="status">Durum</label>
                    <select id="status" name="status" class="pd-input">
                        @foreach($statusOptions as $key => $label)
                            <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label" for="package">Paket</label>
                    <select id="package" name="package" class="pd-input">
                        <option value="">Tüm paketler</option>
                        @foreach($packages as $package)
                            <option value="{{ $package->key }}" @selected($filters['package'] === $package->key)>{{ $package->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.tenants.index') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                </div>
                </div>
                <div class="pd-filter-toggle-grid">
                <label class="pd-filter-toggle-card">
                    <span>Kullanım uyarısı olanlar</span>
                    <input type="checkbox" name="usage_warning" value="1" @checked($filters['usage_warning'])>
                </label>
                <label class="pd-filter-toggle-card">
                    <span>Onboarding eksik</span>
                    <input type="checkbox" name="onboarding_incomplete" value="1" @checked($filters['onboarding_incomplete'])>
                </label>
                <label class="pd-filter-toggle-card">
                    <span>Domain / panel eksik</span>
                    <input type="checkbox" name="domain_missing" value="1" @checked($filters['domain_missing'])>
                </label>
                <label class="pd-filter-toggle-card">
                    <span>Bitişi yaklaşan</span>
                    <input type="checkbox" name="ending_soon" value="1" @checked($filters['ending_soon'])>
                </label>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Abone Firma Listesi</h3>
                <p class="pd-section-subtitle">Owner, onboarding ve paket uyarıları bir arada görünür.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Abone Firma</th>
                            <th>Durum</th>
                            <th>Paket</th>
                            <th>Deneme / Paket Bitişi</th>
                            <th>Kalan Gün</th>
                            <th>Uyarı</th>
                            <th>Kullanım / Limit</th>
                            <th>Modül Sayısı</th>
                            <th>Owner</th>
                            <th>Panel Adresi</th>
                            <th>Onboarding</th>
                            <th>Son Aktivite / Oluşturulma</th>
                            <th class="text-right">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tenants as $tenant)
                            <tr>
                                <td>
                                    <div class="font-medium">{{ $tenant->name }}</div>
                                    <div class="text-sm text-gray-500">{{ $tenant->legal_name ?: '-' }}</div>
                                    <div class="flex gap-2 mt-2">
                                        @if($tenant->is_demo_tenant)
                                            <span class="pd-badge pd-badge-amber">Demo</span>
                                        @endif
                                        @if($tenant->has_domain_gap)
                                            <span class="pd-badge pd-badge-amber">Panel / domain kontrolü</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span class="pd-badge {{ match($tenant->subscription_severity){'success'=>'pd-badge-green','info'=>'pd-badge-blue','warning'=>'pd-badge-amber','danger'=>'pd-badge-red',default=>'pd-badge-gray'} }}">
                                        {{ $tenant->subscription_label }}
                                    </span>
                                    <div class="text-sm text-gray-500 mt-2">{{ $tenant->subscription_message }}</div>
                                </td>
                                <td>
                                    <span class="pd-badge pd-badge-blue">{{ $tenant->package_key ?: 'core' }}</span>
                                    <div class="text-sm text-gray-500 mt-2">{{ $tenant->package_label }}</div>
                                </td>
                                <td>
                                    <div class="text-sm text-gray-900">Deneme: {{ $tenant->trial_ends_at_label ?: 'Takip edilmiyor' }}</div>
                                    <div class="text-sm text-gray-500 mt-2">Paket: {{ $tenant->package_ends_at_label ?: 'Takip edilmiyor' }}</div>
                                </td>
                                <td>
                                    <div class="font-medium">{{ $tenant->days_remaining ?? 'Takip edilmiyor' }}</div>
                                </td>
                                <td>
                                    @if($tenant->warning_label)
                                        <span class="pd-badge {{ $tenant->subscription_status === 'expired' ? 'pd-badge-red' : 'pd-badge-amber' }}">{{ $tenant->warning_label }}</span>
                                    @else
                                        <span class="pd-badge pd-badge-gray">Yok</span>
                                    @endif
                                </td>
                                <td>
                                    @if($tenant->has_usage_warning)
                                        <span class="pd-badge pd-badge-amber">Uyarı</span>
                                        <div class="text-sm text-gray-500 mt-2">{{ $tenant->usage_summary }}</div>
                                    @else
                                        <span class="pd-badge pd-badge-green">Normal</span>
                                    @endif
                                </td>
                                <td>{{ $tenant->active_module_count }}</td>
                                <td>
                                    <div class="font-medium">{{ $tenant->owner_name }}</div>
                                    <div class="text-sm text-gray-500">{{ $tenant->owner_email ?: 'Kullanıcı onboarding bekliyor' }}</div>
                                </td>
                                <td>
                                    <div class="text-sm text-gray-900">{{ $tenant->panel_subdomain ?: '-' }}</div>
                                    @if($tenant->admin_preview_url)
                                        <div class="text-sm text-gray-500">{{ $tenant->admin_preview_url }}</div>
                                    @endif
                                    @if($tenant->custom_domain)
                                        <div class="text-sm text-gray-500">{{ $tenant->custom_domain }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="pd-badge {{ $tenant->onboarding_ready ? 'pd-badge-green' : 'pd-badge-amber' }}">{{ $tenant->onboarding_label }}</span>
                                </td>
                                <td>{{ $tenant->last_activity_label }}</td>
                                <td class="text-right">
                                    <div class="pd-row-actions">
                                        <a href="{{ route('admin.super.tenants.show', $tenant) }}" class="pd-btn pd-btn-sm pd-btn-light">Aç</a>
                                        <a href="{{ route('admin.super.tenants.edit', $tenant) }}" class="pd-btn pd-btn-sm pd-btn-primary">Düzenle</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="text-center text-gray-500">Filtreye uyan Abone Firma bulunamadı.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Liste Özeti</h3>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Toplam</span><strong>{{ $tenants->count() }}</strong></div>
            <div class="pd-status-row"><span>Aktif</span><strong>{{ $tenants->where('subscription_status', 'active')->count() }}</strong></div>
            <div class="pd-status-row"><span>Deneme</span><strong>{{ $tenants->where('subscription_status', 'trial')->count() }}</strong></div>
            <div class="pd-status-row"><span>Süresi Dolmuş</span><strong>{{ $tenants->where('subscription_status', 'expired')->count() }}</strong></div>
            <div class="pd-status-row"><span>Askıda</span><strong>{{ $tenants->where('subscription_status', 'suspended')->count() }}</strong></div>
            <div class="pd-status-row"><span>Pasif</span><strong>{{ $tenants->where('subscription_status', 'passive')->count() }}</strong></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Operasyon Sinyali</h4>
            <div class="pd-summary-action-list">
                <span class="pd-summary-action"><span>Usage warning</span><span class="pd-badge pd-badge-amber">{{ $tenants->where('has_usage_warning', true)->count() }}</span></span>
                <span class="pd-summary-action"><span>Onboarding eksik</span><span class="pd-badge pd-badge-amber">{{ $tenants->where('onboarding_ready', false)->count() }}</span></span>
                <span class="pd-summary-action"><span>Owner eksik</span><span class="pd-badge pd-badge-red">{{ $tenants->where('owner_name', 'Owner eksik')->count() }}</span></span>
            </div>
        </div>
    </div>
</div>
@endsection
