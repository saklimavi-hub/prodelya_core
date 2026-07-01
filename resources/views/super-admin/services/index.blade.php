@extends('layouts.prodelya-admin')

@section('title', 'Tenant Hizmetleri')
@section('page_title', 'Tenant Hizmetleri')
@section('page_subtitle', 'Abone firmalara yansıtılacak merkez hizmet kalemlerini buradan yönetin.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.super.services.create') }}" class="pd-btn pd-btn-primary">Yeni Hizmet Kalemi</a>
</div>
@endsection

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-body">
            <div class="pd-mini-kpi-strip">
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Toplam Kalem</div><div class="pd-mini-kpi-value">{{ $services->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Aktif</div><div class="pd-mini-kpi-value">{{ $services->where('is_active', true)->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Pasif</div><div class="pd-mini-kpi-value">{{ $services->where('is_active', false)->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Borç</div><div class="pd-mini-kpi-value">{{ $services->where('default_direction', 'debit')->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Alacak</div><div class="pd-mini-kpi-value">{{ $services->where('default_direction', 'credit')->count() }}</div></div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler</h3>
                <p class="pd-section-subtitle">Hizmet adı, kodu ve durumuna göre tenant hizmet kataloğunu daraltın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" action="{{ route('admin.super.services.index') }}" class="pd-filter-row pd-filter-row-3">
                <div>
                    <label class="pd-label" for="search">Arama</label>
                    <input id="search" type="text" name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Servis adı, kodu veya açıklama ara">
                </div>
                <div>
                    <label class="pd-label" for="status">Durum</label>
                    <select id="status" name="status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="active" @selected($filters['status'] === 'active')>Aktif</option>
                        <option value="passive" @selected($filters['status'] === 'passive')>Pasif</option>
                    </select>
                </div>
                <div class="pd-form-actions" style="align-items: end;">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.services.index') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Hizmet Listesi</h3>
                <p class="pd-section-subtitle">Kurulum, domain, entegrasyon, destek ve özel geliştirme gibi merkezi kalemleri tenant SaaS carisinde kullanın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Kod</th>
                            <th>Servis Adı</th>
                            <th>Kategori</th>
                            <th>Varsayılan Yön</th>
                            <th>Varsayılan Tutar</th>
                            <th>Durum</th>
                            <th class="text-right">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($services as $service)
                            <tr>
                                <td><span class="pd-badge pd-badge-gray">{{ $service->service_code }}</span></td>
                                <td>
                                    <div class="font-medium">{{ $service->service_name }}</div>
                                    <div class="text-sm text-gray-600">{{ $service->description ?: 'Açıklama girilmemiş.' }}</div>
                                </td>
                                <td>{{ $service->category ?: 'Genel' }}</td>
                                <td>
                                    <span class="pd-badge {{ $service->default_direction === 'credit' ? 'pd-badge-blue' : 'pd-badge-amber' }}">
                                        {{ $service->directionLabel() }}
                                    </span>
                                </td>
                                <td>{{ \App\Services\MoneyFormatter::format((float) ($service->default_amount ?? 0), $service->currency ?: 'TRY') }}</td>
                                <td>
                                    <span class="pd-badge {{ $service->is_active ? 'pd-badge-green' : 'pd-badge-gray' }}">
                                        {{ $service->statusLabel() }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    <div class="pd-row-actions">
                                        <a href="{{ route('admin.super.services.edit', $service) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-sm text-gray-500">Tenant hizmet kataloğunda henüz kayıt yok.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
