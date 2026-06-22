@extends('layouts.prodelya-admin')

@section('title', 'Yeni Cari / Firma')
@section('page_title', 'Yeni Cari / Firma')
@section('page_subtitle', 'Müşteri, tedarikçi veya fason firma kaydı oluşturun.')
@section('hide_side_summary', '1')

@section('content')
<style>
    .company-create-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .company-create-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(250px, 300px);
        gap: 18px;
        align-items: start;
    }
    .company-create-main {
        display: grid;
        gap: 14px;
    }
    .company-create-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
    }
    .company-create-card-body {
        padding: 18px;
    }
    .company-create-section-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 14px;
    }
    .company-create-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 12px;
    }
    .company-create-col-3 { grid-column: span 3; }
    .company-create-col-4 { grid-column: span 4; }
    .company-create-col-6 { grid-column: span 6; }
    .company-create-col-8 { grid-column: span 8; }
    .company-create-col-12 { grid-column: 1 / -1; }
    .company-create-label {
        display: block;
        margin-bottom: 6px;
        color: #334155;
        font-size: 11px;
        font-weight: 700;
    }
    .company-create-help {
        margin-top: 4px;
        color: #64748b;
        font-size: 11px;
        line-height: 1.45;
    }
    .company-create-error {
        margin-top: 4px;
        color: #b91c1c;
        font-size: 12px;
    }
    .company-create-input,
    .company-create-select,
    .company-create-textarea {
        width: 100%;
        min-height: 40px;
        border: 1px solid #d1d5db;
        border-radius: 10px;
        background: #fff;
        color: #111827;
        padding: 9px 11px;
        font-size: 13px;
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .company-create-input:focus,
    .company-create-select:focus,
    .company-create-textarea:focus {
        outline: none;
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .company-create-textarea {
        min-height: 82px;
        resize: vertical;
    }
    .company-create-role-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 10px;
    }
    .company-create-role-chip,
    .company-create-toggle-chip {
        display: flex;
        align-items: center;
        gap: 8px;
        min-height: 40px;
        border: 1px solid #d7deea;
        border-radius: 10px;
        background: #f8fafc;
        padding: 8px 10px;
    }
    .company-create-optional-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .company-create-toggle {
        border: 1px dashed #cbd5e1;
        border-radius: 12px;
        background: #f8fafc;
        padding: 12px 14px;
    }
    .company-create-inline-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #334155;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 700;
    }
    .company-create-summary {
        position: sticky;
        top: 22px;
    }
    .company-create-summary-list {
        display: grid;
        gap: 8px;
        margin-top: 12px;
    }
    .company-create-summary-item,
    .company-create-summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    .company-create-summary-item {
        border-bottom: 1px dashed #e5e7eb;
        padding-bottom: 8px;
    }
    .company-create-summary-item:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }
    .company-create-status-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        padding: 4px 8px;
        font-size: 11px;
        font-weight: 700;
    }
    .company-create-status-ok {
        background: #dcfce7;
        color: #166534;
    }
    .company-create-status-wait {
        background: #f3f4f6;
        color: #475569;
    }
    .company-create-alert {
        border: 1px solid #fee2e2;
        border-radius: 12px;
        background: #fef2f2;
        color: #991b1b;
        padding: 12px 14px;
        font-size: 13px;
    }
    @media (max-width: 1280px) {
        .company-create-layout {
            grid-template-columns: 1fr;
        }
        .company-create-summary {
            position: static;
        }
    }
    @media (max-width: 1024px) {
        .company-create-col-3,
        .company-create-col-4,
        .company-create-col-6,
        .company-create-col-8,
        .company-create-col-12 {
            grid-column: 1 / -1;
        }
        .company-create-role-grid,
        .company-create-optional-grid {
            grid-template-columns: 1fr;
        }
        .company-create-section-head {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

@php
    $tenantCurrency = $currentTenantForLayout?->default_currency ?? 'TL';
    $selectedRoles = old('roles', ['customer']);
    $selectedIdentity = old('identity_type', $identityType ?? 'company');
    $namePreview = old('legal_name') ?: 'Henüz girilmedi';
    $contactReady = filled(old('primary_contact_name')) || filled(old('primary_contact_email')) || filled(old('primary_contact_phone'));
    $addressReady = filled(old('billing_address')) || filled(old('billing_city')) || filled(old('billing_district')) || filled(old('billing_postal_code'));
    $taxReady = filled(old('tax_number'));
    $showSupplierMapping = in_array('supplier', $selectedRoles, true);
@endphp

<div class="company-create-shell">
    @if($errors->any())
        <div class="company-create-alert" style="margin-bottom: 16px;">
            Formu kaydetmeden önce işaretli alanları kontrol ediniz.
        </div>
    @endif

    <form method="POST" action="{{ route('admin.companies.store') }}">
        @csrf

        <div class="company-create-layout">
            <div class="company-create-main">
                <section class="company-create-card">
                    <div class="company-create-card-body">
                        <div class="company-create-section-head" style="margin-bottom: 0;">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-900">Yeni Cari / Firma</h2>
                                <p class="mt-1 text-sm text-slate-600">Müşteri, tedarikçi veya fason firma kaydı oluşturun.</p>
                            </div>
                            <div class="flex gap-2">
                                <a href="{{ route('admin.companies.index') }}" class="pd-btn pd-btn-light">İptal</a>
                                <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="company-create-card">
                    <div class="company-create-card-body">
                        <div class="company-create-section-head">
                            <h3 class="text-base font-semibold text-slate-900">Temel Bilgiler</h3>
                            <span class="company-create-inline-badge">{{ $tenantCurrency }}</span>
                        </div>

                        <div class="company-create-grid">
                            <div class="company-create-col-4">
                                <label for="identity_type" class="company-create-label">Cari Tipi</label>
                                <select id="identity_type" name="identity_type" class="company-create-select">
                                    <option value="company" {{ $selectedIdentity === 'company' ? 'selected' : '' }}>Kurumsal Firma</option>
                                    <option value="person" {{ $selectedIdentity === 'person' ? 'selected' : '' }}>Bireysel Kişi</option>
                                    <option value="sole_trader" {{ $selectedIdentity === 'sole_trader' ? 'selected' : '' }}>Şahıs İşletmesi</option>
                                </select>
                                @error('identity_type')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-8">
                                <label for="legal_name" class="company-create-label">Cari / Firma Adı</label>
                                <input id="legal_name" name="legal_name" type="text" value="{{ old('legal_name') }}" class="company-create-input" required>
                                @error('legal_name')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-4">
                                <label for="short_name" class="company-create-label">Kısa Ad</label>
                                <input id="short_name" name="short_name" type="text" value="{{ old('short_name') }}" class="company-create-input">
                                @error('short_name')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-4">
                                <label for="status" class="company-create-label">Durum</label>
                                <select id="status" name="status" class="company-create-select">
                                    <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Aktif</option>
                                    <option value="passive" {{ old('status') === 'passive' ? 'selected' : '' }}>Pasif</option>
                                </select>
                                @error('status')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-4">
                                <label for="risk_status" class="company-create-label">Risk Durumu</label>
                                <select id="risk_status" name="risk_status" class="company-create-select">
                                    <option value="">Seçiniz</option>
                                    <option value="low" {{ old('risk_status') === 'low' ? 'selected' : '' }}>Düşük</option>
                                    <option value="medium" {{ old('risk_status') === 'medium' ? 'selected' : '' }}>Orta</option>
                                    <option value="high" {{ old('risk_status') === 'high' ? 'selected' : '' }}>Yüksek</option>
                                    <option value="critical" {{ old('risk_status') === 'critical' ? 'selected' : '' }}>Kritik</option>
                                </select>
                                @error('risk_status')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section class="company-create-card">
                    <div class="company-create-card-body">
                        <div class="company-create-section-head">
                            <h3 class="text-base font-semibold text-slate-900">İletişim ve Resmi Bilgiler</h3>
                        </div>

                        <div class="company-create-grid">
                            <div class="company-create-col-4">
                                <label for="phone" class="company-create-label">Telefon</label>
                                <input id="phone" name="phone" type="text" value="{{ old('phone') }}" class="company-create-input">
                                @error('phone')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-4">
                                <label for="mobile" class="company-create-label">Mobil / WhatsApp</label>
                                <input id="mobile" name="mobile" type="text" value="{{ old('mobile') }}" class="company-create-input">
                                @error('mobile')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-4">
                                <label for="email" class="company-create-label">E-posta</label>
                                <input id="email" name="email" type="email" value="{{ old('email') }}" class="company-create-input">
                                @error('email')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-4">
                                <label for="website" class="company-create-label">Web Sitesi</label>
                                <input id="website" name="website" type="text" value="{{ old('website') }}" class="company-create-input" placeholder="www.firma.com.tr">
                                @error('website')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-4">
                                <label for="tax_number" class="company-create-label">VKN / TCKN</label>
                                <input id="tax_number" name="tax_number" type="text" value="{{ old('tax_number') }}" class="company-create-input" inputmode="numeric">
                                <div class="company-create-help">VKN 10, TCKN 11 hane olmalıdır.</div>
                                @error('tax_number')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-4">
                                <label for="tax_office" class="company-create-label">Vergi Dairesi</label>
                                <input id="tax_office" name="tax_office" type="text" value="{{ old('tax_office') }}" class="company-create-input">
                                @error('tax_office')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-4">
                                <label class="company-create-label">Fatura Ünvanı</label>
                                <div class="company-create-input" style="display:flex;align-items:center;background:#f8fafc;">Cari adı kullanılır</div>
                            </div>
                            <div class="company-create-col-4">
                                <label class="company-create-label">Portal</label>
                                <label class="company-create-toggle-chip">
                                    <input type="checkbox" name="portal_enabled" value="1" {{ old('portal_enabled') ? 'checked' : '' }}>
                                    <span class="text-sm text-slate-800">Portal erişimi hazır</span>
                                </label>
                            </div>
                            <div class="company-create-col-12">
                                <label for="billing_address" class="company-create-label">Açık Fatura Adresi</label>
                                <textarea id="billing_address" name="billing_address" class="company-create-textarea">{{ old('billing_address') }}</textarea>
                                @error('billing_address')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-3">
                                <label for="billing_district" class="company-create-label">İlçe</label>
                                <input id="billing_district" name="billing_district" type="text" value="{{ old('billing_district') }}" class="company-create-input">
                                @error('billing_district')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-3">
                                <label for="billing_city" class="company-create-label">İl</label>
                                <input id="billing_city" name="billing_city" type="text" value="{{ old('billing_city') }}" class="company-create-input">
                                @error('billing_city')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-3">
                                <label for="billing_postal_code" class="company-create-label">Posta Kodu</label>
                                <input id="billing_postal_code" name="billing_postal_code" type="text" value="{{ old('billing_postal_code') }}" class="company-create-input">
                                @error('billing_postal_code')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-3">
                                <label for="billing_country" class="company-create-label">Ülke</label>
                                <input id="billing_country" name="billing_country" type="text" value="{{ old('billing_country', 'Türkiye') }}" class="company-create-input">
                                @error('billing_country')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-create-col-12">
                                <label for="notes" class="company-create-label">Resmi / İç Not</label>
                                <textarea id="notes" name="notes" class="company-create-textarea">{{ old('notes') }}</textarea>
                                @error('notes')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section class="company-create-card">
                    <div class="company-create-card-body">
                        <div class="company-create-section-head">
                            <h3 class="text-base font-semibold text-slate-900">Roller</h3>
                        </div>

                        <div class="company-create-role-grid">
                            <label class="company-create-role-chip"><input type="checkbox" name="roles[]" value="customer" {{ in_array('customer', $selectedRoles, true) ? 'checked' : '' }}><span>Müşteri</span></label>
                            <label class="company-create-role-chip"><input type="checkbox" name="roles[]" value="supplier" {{ in_array('supplier', $selectedRoles, true) ? 'checked' : '' }}><span>Tedarikçi</span></label>
                            <label class="company-create-role-chip"><input type="checkbox" name="roles[]" value="print_fason" {{ in_array('print_fason', $selectedRoles, true) ? 'checked' : '' }}><span>Fason Baskı Firması</span></label>
                            <label class="company-create-role-chip"><input type="checkbox" name="roles[]" value="delivery_partner" {{ in_array('delivery_partner', $selectedRoles, true) ? 'checked' : '' }}><span>Nakliye / Kargo</span></label>
                            <label class="company-create-role-chip"><input type="checkbox" name="roles[]" value="production_partner" {{ in_array('production_partner', $selectedRoles, true) ? 'checked' : '' }}><span>Fason Üretim Firması</span></label>
                            <label class="company-create-role-chip"><input type="checkbox" name="roles[]" value="other" {{ in_array('other', $selectedRoles, true) ? 'checked' : '' }}><span>Diğer</span></label>
                        </div>
                        @error('roles')<div class="company-create-error">{{ $message }}</div>@enderror
                    </div>
                </section>

                <section
                    class="company-create-card"
                    id="supplier-source-mapping-section"
                    data-supplier-mapping-visible="{{ $showSupplierMapping ? '1' : '0' }}"
                    @if(!$showSupplierMapping) style="display: none;" @endif
                >
                    <div class="company-create-card-body">
                        <div class="company-create-section-head">
                            <h3 class="text-base font-semibold text-slate-900">Tedarikçi Ürün Kaynağı Eşleştirme</h3>
                        </div>

                        <div class="company-create-grid">
                            <div class="company-create-col-12">
                                <label for="supplier_id" class="company-create-label">Hazır ürün kaynağı</label>
                                <select id="supplier_id" name="supplier_id" class="company-create-select">
                                    <option value="">Yok</option>
                                    @foreach($supplierOptions as $supplierOption)
                                        <option value="{{ $supplierOption['supplier_id'] }}" {{ (string) $selectedSupplierId === (string) $supplierOption['supplier_id'] ? 'selected' : '' }}>
                                            {{ $supplierOption['name'] }}{{ $supplierOption['is_purchase_ready'] ? '' : ' (Sınırlı erişim)' }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="company-create-help">Tedarikçi rolü seçiliyse bu cari, hazır ürün kaynağıyla eşleştirilir ve tedarik ekranında kullanılabilir.</div>
                                @error('supplier_id')<div class="company-create-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section class="company-create-card">
                    <div class="company-create-card-body">
                        <div class="company-create-section-head">
                            <h3 class="text-base font-semibold text-slate-900">Opsiyonel: Yetkili ve Ek Adres</h3>
                        </div>

                        <div class="company-create-optional-grid">
                            <details class="company-create-toggle" {{ $contactReady ? 'open' : '' }}>
                                <summary class="cursor-pointer text-sm font-semibold text-slate-800">+ Yetkili Ekle</summary>
                                <div class="company-create-grid" style="margin-top: 12px;">
                                    <div class="company-create-col-6">
                                        <label for="primary_contact_name" class="company-create-label">Ad Soyad</label>
                                        <input id="primary_contact_name" name="primary_contact_name" type="text" value="{{ old('primary_contact_name') }}" class="company-create-input">
                                        @error('primary_contact_name')<div class="company-create-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="company-create-col-6">
                                        <label for="primary_contact_note" class="company-create-label">Görev</label>
                                        <input id="primary_contact_note" name="primary_contact_note" type="text" value="{{ old('primary_contact_note') }}" class="company-create-input">
                                        @error('primary_contact_note')<div class="company-create-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="company-create-col-6">
                                        <label for="primary_contact_phone" class="company-create-label">Telefon</label>
                                        <input id="primary_contact_phone" name="primary_contact_phone" type="text" value="{{ old('primary_contact_phone') }}" class="company-create-input">
                                        @error('primary_contact_phone')<div class="company-create-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="company-create-col-6">
                                        <label for="primary_contact_email" class="company-create-label">E-posta</label>
                                        <input id="primary_contact_email" name="primary_contact_email" type="email" value="{{ old('primary_contact_email') }}" class="company-create-input">
                                        @error('primary_contact_email')<div class="company-create-error">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </details>

                            <details class="company-create-toggle">
                                <summary class="cursor-pointer text-sm font-semibold text-slate-800">+ Adres Ekle</summary>
                                <p class="company-create-help" style="margin-top: 10px;">Ek adresler kayıt sonrası firma detay ekranından eklenir.</p>
                            </details>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="company-create-summary">
                <section class="company-create-card">
                    <div class="company-create-card-body">
                        <h3 class="text-base font-semibold text-slate-900">Kayıt Özeti</h3>

                        <div class="company-create-summary-list">
                            <div class="company-create-summary-item">
                                <span class="text-sm text-slate-500">Cari adı</span>
                                <strong class="text-sm text-slate-900">{{ $namePreview }}</strong>
                            </div>
                            <div class="company-create-summary-item">
                                <span class="text-sm text-slate-500">Vergi bilgisi</span>
                                <span class="company-create-status-chip {{ $taxReady ? 'company-create-status-ok' : 'company-create-status-wait' }}">{{ $taxReady ? 'Var' : 'Yok' }}</span>
                            </div>
                            <div class="company-create-summary-item">
                                <span class="text-sm text-slate-500">Adres</span>
                                <span class="company-create-status-chip {{ $addressReady ? 'company-create-status-ok' : 'company-create-status-wait' }}">{{ $addressReady ? 'Var' : 'Yok' }}</span>
                            </div>
                            <div class="company-create-summary-row">
                                <span class="text-sm text-slate-500">Yetkili</span>
                                <span class="company-create-status-chip {{ $contactReady ? 'company-create-status-ok' : 'company-create-status-wait' }}">{{ $contactReady ? 'Var' : 'Yok' }}</span>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-2">
                            <button type="submit" class="pd-btn pd-btn-primary w-full justify-center">Cari Kartı Kaydet</button>
                            <a href="{{ route('admin.companies.index') }}" class="pd-btn pd-btn-light w-full justify-center">İptal ve Listeye Dön</a>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const supplierRoleInput = document.querySelector('input[name="roles[]"][value="supplier"]');
    const supplierSection = document.getElementById('supplier-source-mapping-section');

    if (!supplierRoleInput || !supplierSection) {
        return;
    }

    const syncSupplierSection = () => {
        supplierSection.style.display = supplierRoleInput.checked ? '' : 'none';
        supplierSection.dataset.supplierMappingVisible = supplierRoleInput.checked ? '1' : '0';
    };

    supplierRoleInput.addEventListener('change', syncSupplierSection);
    syncSupplierSection();
});
</script>
@endsection
