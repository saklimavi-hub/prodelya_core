@php
    $account = $account ?? null;
    $selectedRoles = collect(old('roles', $selectedRoles ?? ($account?->roles?->pluck('role')->all() ?? [])))->filter()->all();
    $selectedSupplierId = old('supplier_id');
    if ($selectedSupplierId === null && $account?->relationLoaded('links')) {
        $selectedSupplierId = optional($account->links->firstWhere('link_type', \App\Models\CurrentAccountLink::LINK_SUPPLIER))->link_id;
    }
    $showSupplierLinkFields = in_array(\App\Models\CurrentAccountRole::ROLE_SUPPLIER, $selectedRoles, true);
@endphp

@if($errors->any())
    <div class="pd-card" style="margin-bottom: 14px;">
        <div class="pd-card-body">
            <div class="pd-alert pd-alert-warning">
                <strong>Kayit kontrolü gerekiyor.</strong>
                <ul style="margin: 8px 0 0 18px;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endif

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Temel Bilgi</h3>
        <p class="pd-card-subtitle">Cari kartın görünen adı, resmi ünvanı ve temel durum bilgisi.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-2">
            <div>
                <label class="text-sm font-medium">Görünen Ad</label>
                <input type="text" name="display_name" value="{{ old('display_name', $account?->display_name) }}" required>
            </div>
            <div>
                <label class="text-sm font-medium">Resmi Ünvan</label>
                <input type="text" name="legal_name" value="{{ old('legal_name', $account?->legal_name) }}">
            </div>
            <div>
                <label class="text-sm font-medium">Kısa Ad</label>
                <input type="text" name="short_name" value="{{ old('short_name', $account?->short_name) }}">
            </div>
            <div>
                <label class="text-sm font-medium">Cari Kod</label>
                <input type="text" name="account_code" value="{{ old('account_code', $account?->account_code) }}">
            </div>
            <div>
                <label class="text-sm font-medium">Durum</label>
                <select name="status" required>
                    @foreach($statusOptions as $statusValue => $statusLabel)
                        <option value="{{ $statusValue }}" @selected(old('status', $account?->status ?? 'active') === $statusValue)>{{ $statusLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">Risk Durumu</label>
                <select name="risk_status">
                    <option value="">Seçiniz</option>
                    @foreach($riskStatusOptions as $riskValue => $riskLabel)
                        <option value="{{ $riskValue }}" @selected(old('risk_status', $account?->risk_status) === $riskValue)>{{ $riskLabel }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Roller</h3>
        <p class="pd-card-subtitle">Bir cari birden fazla role sahip olabilir. En az bir rol önerilir, ama zorunlu değildir.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-note" style="margin-bottom: 14px;">Bu cariye en az bir rol atamanız önerilir.</div>
        <div class="pd-grid pd-grid-3">
            @foreach($roleOptions as $roleValue => $roleLabel)
                <label style="display: flex; gap: 10px; align-items: start; padding: 12px; border: 1px solid var(--pd-line); border-radius: 6px; background: #fbfcfe;">
                    <input type="checkbox" name="roles[]" value="{{ $roleValue }}" @checked(in_array($roleValue, $selectedRoles, true)) style="width: auto; margin-top: 2px;">
                    <span>
                        <strong>{{ $roleLabel }}</strong>
                        <span class="block text-sm text-gray-600" style="margin-top: 4px;">
                            @switch($roleValue)
                                @case(\App\Models\CurrentAccountRole::ROLE_CUSTOMER)
                                    Sipariş veren müşteri kaydı
                                    @break
                                @case(\App\Models\CurrentAccountRole::ROLE_SUPPLIER)
                                    Ticari tedarikçi carisi. Ürün/Data Kaynağı bağlantısı ayrıca seçilebilir.
                                    @break
                                @case(\App\Models\CurrentAccountRole::ROLE_SUBCONTRACTOR)
                                    Fason baskı/üretim hizmeti alınan cari.
                                    @break
                                @case(\App\Models\CurrentAccountRole::ROLE_CARRIER)
                                    Kargo, kurye, ambar veya teslimat partneri.
                                    @break
                                @case(\App\Models\CurrentAccountRole::ROLE_SERVICE_PROVIDER)
                                    Operasyonel hizmet sağlayıcı
                                    @break
                                @default
                                    Diğer iş ortaklığı rolü
                            @endswitch
                        </span>
                    </span>
                </label>
            @endforeach
        </div>
    </div>
</div>

    <div id="currentAccountSupplierLinkCard" class="pd-card" style="margin-bottom: 14px; {{ $showSupplierLinkFields ? '' : 'display: none;' }}">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Ürün/Data Kaynağı Bağlantısı</h3>
            <p class="pd-card-subtitle">Global supplier bağlantısı opsiyoneldir. Supplier rolü tenant ticari tedarikçisini temsil eder.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-grid pd-grid-2">
                <div>
                    <label class="text-sm font-medium">Global Supplier</label>
                    <select name="supplier_id">
                        <option value="">Bağlantı yok</option>
                        @foreach($supplierOptions as $supplierOption)
                            <option value="{{ $supplierOption->id }}" @selected((string) $selectedSupplierId === (string) $supplierOption->id)>{{ $supplierOption->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pd-note">
                    Global supplier Product Data Hub / feed kaynağıdır. Cari kart ekranında yalnız güvenli bağlantı etiketi tutulur.
                </div>
            </div>
        </div>
    </div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const supplierCheckbox = document.querySelector('input[name="roles[]"][value="supplier"]');
    const supplierCard = document.getElementById('currentAccountSupplierLinkCard');

    if (!supplierCheckbox || !supplierCard) {
        return;
    }

    const toggleSupplierCard = () => {
        supplierCard.style.display = supplierCheckbox.checked ? '' : 'none';
    };

    supplierCheckbox.addEventListener('change', toggleSupplierCard);
    toggleSupplierCard();
});
</script>
@endpush

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-header">
        <h3 class="pd-card-title">İletişim</h3>
        <p class="pd-card-subtitle">Telefon, e-posta ve web bilgilerini güncel tutun.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-2">
            <div>
                <label class="text-sm font-medium">Telefon</label>
                <input type="text" name="phone" value="{{ old('phone', $account?->phone) }}">
            </div>
            <div>
                <label class="text-sm font-medium">Mobil</label>
                <input type="text" name="mobile" value="{{ old('mobile', $account?->mobile) }}">
            </div>
            <div>
                <label class="text-sm font-medium">E-posta</label>
                <input type="email" name="email" value="{{ old('email', $account?->email) }}">
            </div>
            <div>
                <label class="text-sm font-medium">Web Sitesi</label>
                <input type="url" name="website" value="{{ old('website', $account?->website) }}">
            </div>
        </div>
    </div>
</div>

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Vergi / Kimlik</h3>
        <p class="pd-card-subtitle">Faturalama ve kimlik doğrulama için temel alanlar.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-3">
            <div>
                <label class="text-sm font-medium">Vergi Dairesi</label>
                <input type="text" name="tax_office" value="{{ old('tax_office', $account?->tax_office) }}">
            </div>
            <div>
                <label class="text-sm font-medium">Vergi No</label>
                <input type="text" name="tax_number" value="{{ old('tax_number', $account?->tax_number) }}">
            </div>
            <div>
                <label class="text-sm font-medium">TC No</label>
                <input type="text" name="tc_no" value="{{ old('tc_no', $account?->tc_no) }}">
            </div>
        </div>
    </div>
</div>

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Finansal Temel Ayarlar</h3>
        <p class="pd-card-subtitle">Bu fazda yalnız temel ayarlar tutulur; finansal hareket ekranı daha sonra eklenecek.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-3">
            <div>
                <label class="text-sm font-medium">Varsayılan Para Birimi</label>
                <select name="default_currency">
                    <option value="">Seçiniz</option>
                    @foreach($currencyOptions as $currencyValue => $currencyLabel)
                        <option value="{{ $currencyValue }}" @selected(old('default_currency', $account?->default_currency) === $currencyValue)>{{ $currencyLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">Ödeme Vadesi Gün</label>
                <input type="number" min="0" name="payment_terms_days" value="{{ old('payment_terms_days', $account?->payment_terms_days) }}">
            </div>
            <div>
                <label class="text-sm font-medium">Risk Limiti</label>
                <input type="number" min="0" step="0.01" name="risk_limit" value="{{ old('risk_limit', $account?->risk_limit) }}">
            </div>
        </div>
    </div>
</div>

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Notlar</h3>
        <p class="pd-card-subtitle">Operasyon ekibi için kısa notlar ekleyebilirsiniz.</p>
    </div>
    <div class="pd-card-body">
        <textarea name="notes" rows="5">{{ old('notes', $account?->notes) }}</textarea>
    </div>
</div>
