@extends('layouts.prodelya-admin')

@section('title', 'Checkout Oturumu')
@section('page_title', 'Checkout Oturumu')
@section('page_subtitle', 'Provider-agnostic ödeme omurgasının oluşturduğu checkout kaydını ve hazırlık detaylarını inceleyin.')

@section('page_actions')
<div class="pd-hero-actions">
    <a href="{{ route('admin.super.payment-checkouts.index') }}" class="pd-btn pd-btn-light">Checkout Listesi</a>
    @if($session->canBeRetried())
        <form method="POST" action="{{ route('admin.super.payment-checkouts.retry', $session) }}">
            @csrf
            <button type="submit" class="pd-btn pd-btn-primary">Yeni Link Üret</button>
        </form>
    @endif
    @if($session->canBeCancelled())
        <form method="POST" action="{{ route('admin.super.payment-checkouts.cancel', $session) }}">
            @csrf
            <button type="submit" class="pd-btn pd-btn-danger" onclick="return confirm('Bu checkout oturumu iptal edilsin mi?')">İptal Et</button>
        </form>
    @endif
    @if($session->canBeExpired())
        <form method="POST" action="{{ route('admin.super.payment-checkouts.expire', $session) }}">
            @csrf
            <button type="submit" class="pd-btn pd-btn-light" onclick="return confirm('Bu checkout oturumu süresi doldu olarak işaretlensin mi?')">Süreyi Sonlandır</button>
        </form>
    @endif
</div>
@endsection

@section('content')
<div class="pd-layout">
    <div class="pd-main">
        @include('super-admin.payment-partials.foundation-roadmap')

        <section class="pd-section-card pd-section-card-soft-blue">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">{{ $session->reference_no }}</h3>
                    <p class="pd-section-subtitle">Bu kayıt canlı tahsilat değil; ortak ödeme omurgasının checkout/session hazırlık katmanıdır.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-field-grid pd-field-grid-2">
                    <div><span class="pd-field-label">Tenant</span><div class="pd-field-value">{{ $session->tenant?->name ?: '-' }}</div></div>
                    <div><span class="pd-field-label">Provider</span><div class="pd-field-value">{{ $session->provider?->display_name ?: '-' }}</div></div>
                    <div><span class="pd-field-label">Durum</span><div class="pd-field-value">{{ $session->statusLabel() }}</div></div>
                    <div><span class="pd-field-label">Kapsam</span><div class="pd-field-value">{{ $session->scope_type }}</div></div>
                    <div><span class="pd-field-label">Tutar</span><div class="pd-field-value">{{ \App\Services\MoneyFormatter::format((float) $session->amount, $session->currency ?: 'TRY') }}</div></div>
                    <div><span class="pd-field-label">Bağlı Kayıt</span><div class="pd-field-value">{{ $session->subject?->title ?? '-' }}</div></div>
                    <div><span class="pd-field-label">Checkout URL</span><div class="pd-field-value break-all">{{ $session->checkout_url ?: 'Hazırlanmadı' }}</div></div>
                    <div><span class="pd-field-label">Gateway Ref</span><div class="pd-field-value">{{ $session->gateway_reference ?: '-' }}</div></div>
                    <div><span class="pd-field-label">Başarı Callback</span><div class="pd-field-value break-all">{{ data_get($session->provider_payload_json, 'success_callback_url', '-') }}</div></div>
                    <div><span class="pd-field-label">Webhook URL</span><div class="pd-field-value break-all">{{ data_get($session->provider_payload_json, 'webhook_url', '-') }}</div></div>
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Provider Hazırlık Yükü</h3>
                    <p class="pd-section-subtitle">Gerçek hosted checkout ve webhook doğrulaması bir sonraki ödeme fazında tamamlanacaktır.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <pre class="pd-code-block">{{ json_encode($session->provider_payload_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </section>
    </div>

    <aside class="pd-side-summary">
        <div class="pd-panel-card">
            <div class="pd-panel-card-title">Mimari Not</div>
            <div class="pd-panel-card-copy">Super Admin ödeme omurgası ortaktır. Tenant tarafında ise ödeme yeteneği ileride modül olarak açılır.</div>
        </div>
        <div class="pd-panel-card">
            <div class="pd-panel-card-title">Operasyon Durumu</div>
            <div class="pd-panel-card-copy">
                @if($session->canBeCancelled())
                    Oturum halen açık. Gerekirse manuel iptal veya süre sonlandırma yapılabilir.
                @elseif($session->canBeRetried())
                    Bu kayıt yeni link üretimine uygundur.
                @elseif($session->status === \App\Models\PaymentCheckoutSession::STATUS_PAID)
                    Tahsilat tamamlandı. Yeni link üretimine gerek yok.
                @else
                    Bu kayıt yalnız arşiv ve operasyon referansı olarak tutulur.
                @endif
            </div>
        </div>
        <div class="pd-panel-card">
            <div class="pd-panel-card-title">Son İşlem</div>
            <div class="pd-panel-card-copy">{{ optional($session->transactions->first()?->processed_at)->format('d.m.Y H:i') ?: 'İşlenmedi' }}</div>
        </div>
        <div class="pd-panel-card">
            <div class="pd-panel-card-title">Tahsilat Senkronu</div>
            <div class="pd-panel-card-copy">
                @if(data_get($session->meta_json, 'applied_collection_entry_id'))
                    SaaS cariye tahsilat işlendi. Kayıt #{{ data_get($session->meta_json, 'applied_collection_entry_id') }}
                @else
                    Henüz SaaS cariye otomatik tahsilat senkronu oluşmadı.
                @endif
            </div>
        </div>
        <div class="pd-summary-action-list">
            <a href="{{ route('admin.super.payment-checkouts.index') }}" class="pd-btn pd-btn-light">Checkout Hub</a>
            @if($session->tenant)
                <a href="{{ route('admin.super.tenants.billing.index', $session->tenant) }}" class="pd-btn pd-btn-light">SaaS Cariye Dön</a>
            @endif
            @if($session->provider)
                <a href="{{ route('admin.super.payment-providers.edit', $session->provider) }}" class="pd-btn pd-btn-light">Providerı Aç</a>
            @endif
        </div>
    </aside>
</div>
@endsection
