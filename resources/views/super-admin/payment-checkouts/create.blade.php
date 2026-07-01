@extends('layouts.prodelya-admin')

@section('title', 'Ödeme Checkout Oluştur')
@section('page_title', $tenant->name . ' - Ortak Ödeme Checkout')
@section('page_subtitle', 'Super Admin ortak ödeme omurgası ile SaaS cari için ödeme checkout oturumu oluşturun. Tenant tarafı bu fazda modül olarak açılmaz.')

@section('content')
<form method="POST" action="{{ $formAction }}" class="pd-form-shell">
    @csrf
    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Checkout Bilgileri</h3>
                <p class="pd-section-subtitle">İlk kullanım alanı tenant SaaS cari tahsilatıdır. Müşteriden ödeme alma altyapısı daha sonra tenant modülü olarak açılacaktır.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-form-grid pd-form-grid-3">
                <div>
                    <label class="pd-label" for="payment_provider_id">Provider</label>
                    <select id="payment_provider_id" name="payment_provider_id" class="pd-select">
                        @foreach($providers as $provider)
                            <option value="{{ $provider->id }}" @selected(old('payment_provider_id') == $provider->id)>{{ $provider->display_name }} ({{ $provider->provider_key }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label" for="amount">Tutar</label>
                    <input id="amount" type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount', $billingEntry?->amount) }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="currency">Para Birimi</label>
                    <select id="currency" name="currency" class="pd-select">
                        @foreach(['TRY' => 'TRY', 'USD' => 'USD', 'EUR' => 'EUR'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('currency', $billingEntry?->currency ?: 'TRY') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label" for="title">Başlık</label>
                    <input id="title" type="text" name="title" value="{{ old('title', $billingEntry?->title ?: 'SaaS tahsilat oturumu') }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="expires_at">Geçerlilik</label>
                    <input id="expires_at" type="date" name="expires_at" value="{{ old('expires_at', now()->addDays(7)->toDateString()) }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="billing_entry_id">Bağlı SaaS Cari Kaydı</label>
                    <input id="billing_entry_id" type="text" value="{{ $billingEntry ? ('#' . $billingEntry->id . ' - ' . $billingEntry->title) : 'Bağlı kayıt yok' }}" class="pd-input" readonly>
                    @if($billingEntry)
                        <input type="hidden" name="billing_entry_id" value="{{ $billingEntry->id }}">
                    @endif
                </div>
            </div>
            <div class="mt-4">
                <label class="pd-label" for="note">Not</label>
                <textarea id="note" name="note" rows="4" class="pd-textarea">{{ old('note', $billingEntry?->note) }}</textarea>
            </div>
        </div>
    </section>

    <div class="pd-form-actions">
        <button type="submit" class="pd-btn pd-btn-primary">Checkout Oluştur</button>
        <a href="{{ route('admin.super.tenants.billing.index', $tenant) }}" class="pd-btn pd-btn-light">SaaS Cariye Dön</a>
    </div>
</form>
@endsection
