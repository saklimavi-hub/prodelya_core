@csrf
@if($formMethod !== 'POST')
    @method($formMethod)
@endif

<div class="pd-form-shell">
    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Ortak Ödeme Omurgası</h3>
                <p class="pd-section-subtitle">Super Admin tarafında ortak provider tanımlanır. Tenant tarafı ileride modül olarak bu omurgaya bağlanır.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-form-grid pd-form-grid-3">
                <div>
                    <label class="pd-label" for="provider_key">Provider Anahtarı</label>
                    <input id="provider_key" type="text" name="provider_key" value="{{ old('provider_key', $provider->provider_key) }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="display_name">Görünen Ad</label>
                    <input id="display_name" type="text" name="display_name" value="{{ old('display_name', $provider->display_name) }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="driver_key">Driver</label>
                    <select id="driver_key" name="driver_key" class="pd-select">
                        @foreach($driverOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('driver_key', $provider->driver_key) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label" for="status">Durum</label>
                    <select id="status" name="status" class="pd-select">
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $provider->status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label" for="checkout_mode">Checkout Modu</label>
                    <select id="checkout_mode" name="checkout_mode" class="pd-select">
                        @foreach($checkoutModeOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('checkout_mode', $provider->checkout_mode) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pd-field-note">
                    Bu fazda gerçek tahsilat yerine provider-agnostic omurga, checkout oturumu ve webhook log temeli hazırlanır.
                </div>
            </div>
            <div class="pd-form-grid pd-form-grid-2 mt-4">
                <label class="pd-checkbox-row">
                    <input type="hidden" name="supports_shared_saas_payments" value="0">
                    <input type="checkbox" name="supports_shared_saas_payments" value="1" @checked(old('supports_shared_saas_payments', $provider->supports_shared_saas_payments ?? true))>
                    <span>Super Admin ortak SaaS tahsilatını destekler</span>
                </label>
                <label class="pd-checkbox-row">
                    <input type="hidden" name="supports_tenant_module" value="0">
                    <input type="checkbox" name="supports_tenant_module" value="1" @checked(old('supports_tenant_module', $provider->supports_tenant_module ?? false))>
                    <span>Tenant tarafında modül olarak açılmaya uygundur</span>
                </label>
            </div>
            <div class="mt-4">
                <label class="pd-label" for="notes">Operasyon Notu</label>
                <textarea id="notes" name="notes" class="pd-textarea" rows="3">{{ old('notes', $provider->notes) }}</textarea>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Ortak Credential Store</h3>
                <p class="pd-section-subtitle">Buradaki bilgiler yalnız Super Admin ortak ödeme omurgası içindir. Tenant kendi hesabını ileride modül ekranından tanımlar.</p>
            </div>
        </div>
        <div class="pd-section-body">
            @php($credential = $provider->sharedCredential)
            <div class="pd-form-grid pd-form-grid-3">
                <div>
                    <label class="pd-label" for="shared_api_key">API Key</label>
                    <input id="shared_api_key" type="text" name="shared_api_key" value="{{ old('shared_api_key', data_get($credential?->credentials_json, 'api_key')) }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="shared_secret_key">Secret Key</label>
                    <input id="shared_secret_key" type="text" name="shared_secret_key" value="{{ old('shared_secret_key', data_get($credential?->credentials_json, 'secret_key')) }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="shared_merchant_key">Merchant Key</label>
                    <input id="shared_merchant_key" type="text" name="shared_merchant_key" value="{{ old('shared_merchant_key', data_get($credential?->credentials_json, 'merchant_key')) }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="shared_base_url">Base URL</label>
                    <input id="shared_base_url" type="text" name="shared_base_url" value="{{ old('shared_base_url', data_get($credential?->settings_json, 'base_url')) }}" class="pd-input" placeholder="https://sandbox-api...">
                </div>
                <div>
                    <label class="pd-label" for="shared_webhook_secret">Webhook Secret</label>
                    <input id="shared_webhook_secret" type="text" name="shared_webhook_secret" value="{{ old('shared_webhook_secret', data_get($credential?->settings_json, 'webhook_secret')) }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="shared_checkout_initialize_path">Checkout Initialize Path</label>
                    <input id="shared_checkout_initialize_path" type="text" name="shared_checkout_initialize_path" value="{{ old('shared_checkout_initialize_path', data_get($credential?->settings_json, 'checkout_initialize_path', '/payment/iyzipos/checkoutform/initialize/auth/ecom')) }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="shared_timeout_seconds">Timeout (sn)</label>
                    <input id="shared_timeout_seconds" type="number" min="5" max="120" name="shared_timeout_seconds" value="{{ old('shared_timeout_seconds', data_get($credential?->settings_json, 'timeout_seconds', 20)) }}" class="pd-input">
                </div>
                <div class="flex items-end">
                    <label class="pd-checkbox-row">
                        <input type="hidden" name="shared_sandbox_mode" value="0">
                        <input type="checkbox" name="shared_sandbox_mode" value="1" @checked(old('shared_sandbox_mode', data_get($credential?->settings_json, 'sandbox_mode', true)))>
                        <span>Sandbox Modu</span>
                    </label>
                </div>
            </div>
            <div class="pd-form-grid pd-form-grid-2 mt-4">
                <label class="pd-checkbox-row">
                    <input type="hidden" name="shared_credential_is_active" value="0">
                    <input type="checkbox" name="shared_credential_is_active" value="1" @checked(old('shared_credential_is_active', $credential?->is_active ?? true))>
                    <span>Ortak credential aktif</span>
                </label>
                <label class="pd-checkbox-row">
                    <input type="hidden" name="shared_use_live_api" value="0">
                    <input type="checkbox" name="shared_use_live_api" value="1" @checked(old('shared_use_live_api', data_get($credential?->settings_json, 'use_live_api', false)))>
                    <span>Gerçek API initialize çağrısını kullan</span>
                </label>
            </div>
            <div class="pd-field-note mt-4">
                Bu seçenek açıksa Iyzico driver placeholder URL üretmek yerine gerçek checkout initialize isteği atar. Resmi provider-specific imza/header sertleştirmesi sonraki fazda tamamlanacaktır.
            </div>
            <div class="mt-4">
                <label class="pd-label" for="shared_credential_notes">Credential Notu</label>
                <textarea id="shared_credential_notes" name="shared_credential_notes" class="pd-textarea" rows="3">{{ old('shared_credential_notes', $credential?->notes) }}</textarea>
            </div>
        </div>
    </section>

    <div class="pd-form-actions">
        <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
        <a href="{{ route('admin.super.payment-providers.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
    </div>
</div>
