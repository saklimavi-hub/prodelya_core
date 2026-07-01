@extends('layouts.prodelya-admin')

@section('title', $provider->display_name . ' - Ödeme Provider')
@section('page_title', $provider->display_name)
@section('page_subtitle', 'Provider, shared credential, checkout ve webhook omurgasını tek yerden yönetin.')

@section('page_actions')
<div class="flex gap-3">
    <form method="POST" action="{{ route('payment-webhooks.receive', $provider) }}" target="_blank">
        @csrf
        <input type="hidden" name="event" value="foundation_ping">
        <input type="hidden" name="reference" value="MANUAL-TEST">
        <input type="hidden" name="webhook_secret" value="{{ data_get($provider->sharedCredential?->settings_json, 'webhook_secret') }}">
        <button type="submit" class="pd-btn pd-btn-light">Webhook Pingi Gönder</button>
    </form>
    <a href="{{ route('admin.super.payment-checkouts.index', ['payment_provider_id' => $provider->id]) }}" class="pd-btn pd-btn-light">Checkout Oturumları</a>
</div>
@endsection

@section('content')
<div class="pd-hub-family-shell">
    @include('super-admin.payment-partials.foundation-roadmap', ['compact' => true])

    <form method="POST" action="{{ $formAction }}">
        @include('super-admin.payment-providers._form')
    </form>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Son Checkout Oturumları</h3>
                <p class="pd-section-subtitle">Super Admin ortak SaaS ödeme omurgasından üretilen son checkout kayıtları.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-mini-kpi-strip mb-4">
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Canlı API</div><div class="pd-mini-kpi-value">{{ app(\App\Services\Payments\PaymentProviderConfigService::class)->liveApiEnabled($provider) ? 'Açık' : 'Kapalı' }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Webhook Secret</div><div class="pd-mini-kpi-value">{{ app(\App\Services\Payments\PaymentProviderConfigService::class)->webhookSecretConfigured($provider) ? 'Hazır' : 'Eksik' }}</div></div>
            </div>
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Referans</th>
                            <th>Tenant</th>
                            <th>Durum</th>
                            <th>Tutar</th>
                            <th>Checkout URL</th>
                            <th class="text-right">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($provider->checkoutSessions as $session)
                            <tr>
                                <td>{{ $session->reference_no }}</td>
                                <td>{{ $session->tenant?->name ?: '-' }}</td>
                                <td><span class="pd-badge pd-badge-blue">{{ $session->statusLabel() }}</span></td>
                                <td>{{ \App\Services\MoneyFormatter::format((float) $session->amount, $session->currency ?: 'TRY') }}</td>
                                <td class="text-sm text-gray-600 break-all">{{ $session->checkout_url ?: '-' }}</td>
                                <td class="text-right"><a href="{{ route('admin.super.payment-checkouts.show', $session) }}" class="pd-btn pd-btn-sm pd-btn-light">Aç</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-sm text-gray-500">Henüz checkout oturumu yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Webhook Logları</h3>
                <p class="pd-section-subtitle">Webhook artık doğrulanır, checkout eşleştirilir ve ödeme durumu işlenir. İmza doğrulaması için canonical HMAC mantığı da hazırdır.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Event</th>
                            <th>Durum</th>
                            <th>Referans</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($provider->webhookLogs as $log)
                            <tr>
                                <td>{{ optional($log->created_at)->format('d.m.Y H:i') }}</td>
                                <td>{{ $log->event_key }}</td>
                                <td><span class="pd-badge pd-badge-gray">{{ $log->status }}</span></td>
                                <td>{{ $log->external_reference ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-sm text-gray-500">Henüz webhook logu yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
