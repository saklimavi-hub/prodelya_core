@extends('layouts.prodelya-admin')

@section('title', 'Ödeme Checkout Oturumları')
@section('page_title', 'Ödeme Checkout Oturumları')
@section('page_subtitle', 'Ortak ödeme omurgasında üretilen SaaS tahsilat oturumlarını filtreleyin, riskli kayıtları yönetin ve gerektiğinde yeni link üretin.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.super.payment-providers.index') }}" class="pd-btn pd-btn-light">Provider Altyapısı</a>
</div>
@endsection

@section('content')
<div class="pd-hub-family-shell">
    @include('super-admin.payment-partials.foundation-roadmap', ['compact' => true])

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-body">
            <div class="pd-mini-kpi-strip">
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Toplam Oturum</div><div class="pd-mini-kpi-value">{{ $stats['total'] }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Bekleyen</div><div class="pd-mini-kpi-value">{{ $stats['pending'] }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Tahsil Edilen</div><div class="pd-mini-kpi-value">{{ $stats['paid'] }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">İlgi Gerektiren</div><div class="pd-mini-kpi-value">{{ $stats['attention'] }}</div></div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler</h3>
                <p class="pd-section-subtitle">Durum, provider, tenant ve referans alanlarına göre checkout oturumlarını daraltın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" action="{{ route('admin.super.payment-checkouts.index') }}" class="pd-filter-row pd-filter-row-4">
                <div>
                    <label class="pd-label" for="status">Durum</label>
                    <select id="status" name="status" class="pd-select">
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label" for="payment_provider_id">Provider</label>
                    <select id="payment_provider_id" name="payment_provider_id" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($providers as $provider)
                            <option value="{{ $provider->id }}" @selected($filters['payment_provider_id'] === (string) $provider->id)>{{ $provider->display_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label" for="tenant">Tenant</label>
                    <input id="tenant" type="text" name="tenant" value="{{ $filters['tenant'] }}" class="pd-input" placeholder="Firma adı veya slug">
                </div>
                <div>
                    <label class="pd-label" for="q">Referans / Gateway Ref</label>
                    <input id="q" type="text" name="q" value="{{ $filters['q'] }}" class="pd-input" placeholder="PAY-..., gateway ref">
                </div>
                <div class="pd-form-actions">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.payment-checkouts.index') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-white">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Checkout Operasyon Listesi</h3>
                <p class="pd-section-subtitle">Paid kayıtlar SaaS cariye işler. Failed / cancelled / expired kayıtlar yeni link üretimi için takip edilir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Referans</th>
                            <th>Tenant</th>
                            <th>Provider</th>
                            <th>Durum</th>
                            <th>Tutar</th>
                            <th>Son Not</th>
                            <th class="text-right">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sessions as $session)
                            <tr>
                                <td>
                                    <div class="font-medium">{{ $session->reference_no }}</div>
                                    <div class="text-sm text-gray-600">{{ $session->gateway_reference ?: ($session->external_reference ?: '-') }}</div>
                                </td>
                                <td>{{ $session->tenant?->name ?: '-' }}</td>
                                <td>{{ $session->provider?->display_name ?: '-' }}</td>
                                <td><span class="pd-badge pd-badge-blue">{{ $session->statusLabel() }}</span></td>
                                <td>{{ \App\Services\MoneyFormatter::format((float) $session->amount, $session->currency ?: 'TRY') }}</td>
                                <td class="text-sm text-gray-600">{{ data_get($session->meta_json, 'last_status_note', '-') }}</td>
                                <td class="text-right">
                                    <div class="pd-row-actions">
                                        <a href="{{ route('admin.super.payment-checkouts.show', $session) }}" class="pd-btn pd-btn-sm pd-btn-light">Aç</a>
                                        @if($session->canBeRetried())
                                            <form method="POST" action="{{ route('admin.super.payment-checkouts.retry', $session) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="pd-btn pd-btn-sm pd-btn-primary">Yeni Link</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-sm text-gray-500">Henüz checkout oturumu yok.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">
                {{ $sessions->links() }}
            </div>
        </div>
    </section>
</div>
@endsection
