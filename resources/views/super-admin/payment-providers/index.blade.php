@extends('layouts.prodelya-admin')

@section('title', 'Ödeme Altyapısı')
@section('page_title', 'Ödeme Altyapısı')
@section('page_subtitle', 'Super Admin ortak payment backbone burada yönetilir. Tenant tarafı ileride modül olarak bu omurgaya bağlanır.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.super.payment-providers.create') }}" class="pd-btn pd-btn-primary">Yeni Provider</a>
    <a href="{{ route('admin.super.payment-checkouts.index') }}" class="pd-btn pd-btn-light">Checkout Oturumları</a>
</div>
@endsection

@section('content')
<div class="pd-hub-family-shell">
    @include('super-admin.payment-partials.foundation-roadmap', ['compact' => true])

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-body">
            <div class="pd-mini-kpi-strip">
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Toplam</div><div class="pd-mini-kpi-value">{{ $stats['total'] }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Aktif</div><div class="pd-mini-kpi-value">{{ $stats['active'] }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Ortak Hazır</div><div class="pd-mini-kpi-value">{{ $stats['shared_ready'] }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Tenant Modül Uyumlu</div><div class="pd-mini-kpi-value">{{ $stats['tenant_module_ready'] }}</div></div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Provider Listesi</h3>
                <p class="pd-section-subtitle">İlk canlı faz Super Admin ortak SaaS tahsilatıdır. Tenant customer payment ileride modül olarak açılır.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th>Driver</th>
                            <th>Durum</th>
                            <th>Ortak SaaS</th>
                            <th>Tenant Modül</th>
                            <th>Credential</th>
                            <th>Canlı API</th>
                            <th>Checkout</th>
                            <th>Webhook</th>
                            <th class="text-right">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($providers as $provider)
                            <tr>
                                <td>
                                    <div class="font-medium">{{ $provider->display_name }}</div>
                                    <div class="text-sm text-gray-600">{{ $provider->provider_key }}</div>
                                </td>
                                <td><span class="pd-badge pd-badge-blue">{{ $provider->driver_key }}</span></td>
                                <td><span class="pd-badge {{ $provider->status === 'active' ? 'pd-badge-green' : ($provider->status === 'passive' ? 'pd-badge-gray' : 'pd-badge-amber') }}">{{ $provider->statusLabel() }}</span></td>
                                <td>{{ $provider->supports_shared_saas_payments ? 'Hazır' : 'Kapalı' }}</td>
                                <td>{{ $provider->supports_tenant_module ? 'Modül Uyumlu' : 'Sonraki Faz' }}</td>
                                <td>
                                    <span class="pd-badge {{ $configService->sharedCredentialReady($provider) ? 'pd-badge-green' : 'pd-badge-amber' }}">
                                        {{ $configService->sharedCredentialReady($provider) ? 'Hazır' : 'Eksik' }}
                                    </span>
                                </td>
                                <td>{{ $configService->liveApiEnabled($provider) ? 'Açık' : 'Kapalı' }}</td>
                                <td>{{ $provider->checkout_sessions_count }}</td>
                                <td>{{ $provider->webhook_logs_count }}</td>
                                <td class="text-right">
                                    <div class="pd-row-actions">
                                        <a href="{{ route('admin.super.payment-providers.edit', $provider) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-sm text-gray-500">Henüz ortak ödeme provider kaydı yok.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
