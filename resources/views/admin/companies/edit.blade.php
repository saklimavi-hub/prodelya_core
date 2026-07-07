@extends('layouts.prodelya-admin')

@section('title', 'Cari Kartı Düzenle')
@section('page_title', 'Cari Kartı Düzenle')
@section('page_subtitle', $company->legal_name . ' kaydının temel bilgilerini, rollerini ve iletişim ayarlarını güncelleyin.')
@section('hide_side_summary', '1')

@section('content')
<style>
    .company-edit-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .company-edit-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.8fr) minmax(290px, 360px);
        gap: 20px;
        align-items: start;
    }
    .company-edit-main {
        display: grid;
        gap: 18px;
    }
    .company-edit-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 18px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        overflow: hidden;
    }
    .company-edit-card-body {
        padding: 22px;
    }
    .company-edit-grid {
        display: grid;
        gap: 16px;
        grid-template-columns: repeat(12, minmax(0, 1fr));
    }
    .company-edit-field {
        grid-column: span 6;
    }
    .company-edit-third {
        grid-column: span 4;
    }
    .company-edit-half {
        grid-column: span 6;
    }
    .company-edit-full {
        grid-column: 1 / -1;
    }
    .company-edit-head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
        margin-bottom: 18px;
    }
    .company-edit-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        background: #eef2ff;
        color: #3730a3;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 700;
    }
    .company-edit-role-grid {
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .company-edit-role-card {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        min-height: 90px;
        padding: 14px;
        border: 1px solid #d7deea;
        border-radius: 14px;
        background: #f8fafc;
    }
    .company-edit-label {
        display: block;
        margin-bottom: 6px;
        font-size: 12px;
        font-weight: 700;
        color: #374151;
    }
    .company-edit-input,
    .company-edit-select,
    .company-edit-textarea {
        width: 100%;
        border: 1px solid #d1d5db;
        border-radius: 10px;
        background: #fff;
        color: #111827;
        padding: 10px 12px;
        font-size: 14px;
    }
    .company-edit-textarea {
        min-height: 100px;
        resize: vertical;
    }
    .company-edit-summary {
        position: sticky;
        top: 22px;
        display: grid;
        gap: 14px;
    }
    .company-edit-summary-list {
        display: grid;
        gap: 10px;
    }
    .company-edit-summary-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
        padding-bottom: 10px;
        border-bottom: 1px dashed #e5e7eb;
    }
    .company-edit-summary-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }
    .company-edit-status-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        padding: 5px 9px;
        font-size: 11px;
        font-weight: 700;
    }
    .company-edit-status-ok {
        background: #dcfce7;
        color: #166534;
    }
    .company-edit-status-wait {
        background: #f3f4f6;
        color: #475569;
    }
    .company-edit-help {
        margin-top: 6px;
        font-size: 12px;
        color: #6b7280;
    }
    .company-edit-error {
        margin-top: 6px;
        font-size: 12px;
        color: #b91c1c;
    }
    @media (max-width: 1024px) {
        .company-edit-layout {
            grid-template-columns: 1fr;
        }
        .company-edit-summary {
            position: static;
        }
        .company-edit-field,
        .company-edit-half,
        .company-edit-third,
        .company-edit-full {
            grid-column: 1 / -1;
        }
        .company-edit-role-grid {
            grid-template-columns: 1fr;
        }
        .company-edit-head {
            flex-direction: column;
        }
    }
</style>

<div class="company-edit-shell space-y-6">
    @php
        $selectedRoles = old('roles', $company->getRoleKeys());
        $hasTaxInfo = filled(old('tax_number', $company->tax_number));
        $hasContactInfo = filled(old('phone', $company->phone)) || filled(old('email', $company->email)) || filled(old('mobile', $company->mobile));
        $showSupplierMapping = in_array('supplier', $selectedRoles, true);
        $fieldMeta = [
            'identity_type' => ['section' => 'Cari Kimliği', 'label' => 'Cari Tipi'],
            'legal_name' => ['section' => 'Cari Kimliği', 'label' => 'Cari / Firma Adı'],
            'short_name' => ['section' => 'Cari Kimliği', 'label' => 'Kısa Ad'],
            'status' => ['section' => 'Cari Kimliği', 'label' => 'Durum'],
            'risk_status' => ['section' => 'Cari Kimliği', 'label' => 'Risk Durumu'],
            'tax_number' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'VKN / TCKN'],
            'tax_office' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'Vergi Dairesi'],
            'email' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'E-posta'],
            'phone' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'Normal Telefon'],
            'mobile' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'WhatsApp Cep Telefonu'],
            'website' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'Web Sitesi'],
            'notes' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'Resmi / İç Not'],
            'roles' => ['section' => 'Cari Rolleri', 'label' => 'Cari Rolleri'],
            'supplier_id' => ['section' => 'Cari Rolleri', 'label' => 'Hazır ürün kaynağı'],
            'portal_enabled' => ['section' => 'Durum ve Ayarlar', 'label' => 'Portal erişimi'],
        ];
        $errorSummaryLines = collect($errors->getMessages())
            ->except('error')
            ->flatMap(function (array $messages, string $field) use ($fieldMeta) {
                $meta = $fieldMeta[\Illuminate\Support\Str::before($field, '.')] ?? ['section' => 'Form', 'label' => 'Bu alan'];
                $message = trim((string) ($messages[0] ?? ''));

                if ($message === '') {
                    return [];
                }

                return [$meta['section'] . ': ' . $meta['label'] . ' alanı eksik veya hatalı.'];
            })
            ->unique()
            ->values();
    @endphp

    @if($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <div class="font-semibold">Lütfen aşağıdaki alanları kontrol edin:</div>
            <ul class="mt-2 list-disc pl-5">
                @foreach($errorSummaryLines as $line)
                    <li>{{ $line }}</li>
                @endforeach
                @foreach($errors->get('error') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $roleMeta = [
            'customer' => ['title' => 'Müşteri', 'copy' => 'Sipariş ve teklif alır.'],
            'supplier' => ['title' => 'Tedarikçi', 'copy' => 'Malzeme veya hizmet sağlar.'],
            'print_fason' => ['title' => 'Fason Baskı Firması', 'copy' => 'Dış baskı operasyonlarında seçilebilir.'],
            'production_partner' => ['title' => 'Fason Üretim Firması', 'copy' => 'Dış üretim ve teknik operasyonlarda seçilebilir.'],
            'delivery_partner' => ['title' => 'Nakliye / Kargo', 'copy' => 'Teslimat ve kurye operasyonlarında kullanılır.'],
            'other' => ['title' => 'Diğer', 'copy' => 'Özel operasyonel kullanım.'],
        ];
        $selectedRoles = old('roles', $company->getRoleKeys());
        $hasTaxInfo = filled(old('tax_number', $company->tax_number));
        $hasContactInfo = filled(old('phone', $company->phone)) || filled(old('email', $company->email)) || filled(old('mobile', $company->mobile));
        $showSupplierMapping = in_array('supplier', $selectedRoles, true);
        $fieldMeta = [
            'identity_type' => ['section' => 'Cari Kimliği', 'label' => 'Cari Tipi'],
            'legal_name' => ['section' => 'Cari Kimliği', 'label' => 'Cari / Firma Adı'],
            'short_name' => ['section' => 'Cari Kimliği', 'label' => 'Kısa Ad'],
            'status' => ['section' => 'Cari Kimliği', 'label' => 'Durum'],
            'risk_status' => ['section' => 'Cari Kimliği', 'label' => 'Risk Durumu'],
            'tax_number' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'VKN / TCKN'],
            'tax_office' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'Vergi Dairesi'],
            'email' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'E-posta'],
            'phone' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'Normal Telefon'],
            'mobile' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'WhatsApp Cep Telefonu'],
            'website' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'Web Sitesi'],
            'notes' => ['section' => 'İletişim ve Resmi Bilgiler', 'label' => 'Resmi / İç Not'],
            'roles' => ['section' => 'Cari Rolleri', 'label' => 'Cari Rolleri'],
            'supplier_id' => ['section' => 'Cari Rolleri', 'label' => 'Hazır ürün kaynağı'],
            'portal_enabled' => ['section' => 'Durum ve Ayarlar', 'label' => 'Portal erişimi'],
        ];
        $errorSummaryLines = collect($errors->getMessages())
            ->except('error')
            ->flatMap(function (array $messages, string $field) use ($fieldMeta) {
                $meta = $fieldMeta[\Illuminate\Support\Str::before($field, '.')] ?? ['section' => 'Form', 'label' => 'Bu alan'];
                $message = trim((string) ($messages[0] ?? ''));

                if ($message === '') {
                    return [];
                }

                return [$meta['section'] . ': ' . $meta['label'] . ' alanı eksik veya hatalı.'];
            })
            ->unique()
            ->values();
        $sectionErrors = [
            'identity' => $errors->hasAny(['identity_type', 'legal_name', 'short_name', 'status', 'risk_status']),
            'contact' => $errors->hasAny(['tax_number', 'tax_office', 'email', 'phone', 'mobile', 'website', 'notes']),
            'roles' => $errors->hasAny(['roles', 'roles.*', 'supplier_id']),
            'settings' => $errors->has('portal_enabled'),
        ];
        $summaryMissingDetails = collect([
            ! $hasTaxInfo ? 'Vergi bilgisi: VKN / TCKN eksik' : null,
            ! filled(old('tax_office', $company->tax_office)) && $showSupplierMapping ? 'Vergi bilgisi: Vergi Dairesi eksik' : null,
            ! $hasContactInfo ? 'İletişim durumu: E-posta veya telefon bilgisi eksik' : null,
        ])->filter()->values();
    @endphp

    <form method="POST" action="{{ route('admin.companies.update', $company) }}">
        @csrf
        @method('PUT')

        <div class="company-edit-layout">
            <div class="company-edit-main">
                <div class="company-edit-card">
                    <div class="company-edit-card-body">
                        <div class="company-edit-head">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Cari Kimliği</h3>
                                <p class="mt-1 text-sm text-gray-600">Cari adı, resmi unvan görünümü ve sınıflandırma bilgilerini kompakt düzende güncelleyin.</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if($sectionErrors['identity'])
                                    <span class="company-edit-chip" style="background:#fef3c7;color:#92400e;">Eksik bilgi var</span>
                                @endif
                                <span class="company-edit-chip">Aktif Çalışma Alanı: {{ $currentTenantForLayout?->name ?? 'Aktif Tenant' }}</span>
                            </div>
                        </div>

                        <div class="company-edit-grid">
                            <div class="company-edit-third">
                                <label for="identity_type" class="company-edit-label">Cari Tipi</label>
                                <select id="identity_type" name="identity_type" class="company-edit-select">
                                    <option value="company" {{ old('identity_type', $identityType) === 'company' ? 'selected' : '' }}>Tüzel Kişi</option>
                                    <option value="person" {{ old('identity_type', $identityType) === 'person' ? 'selected' : '' }}>Gerçek Kişi</option>
                                    <option value="sole_trader" {{ old('identity_type', $identityType) === 'sole_trader' ? 'selected' : '' }}>Şahıs İşletmesi</option>
                                </select>
                                @error('identity_type')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-edit-field">
                                <label for="legal_name" class="company-edit-label">Cari / Firma Adı</label>
                                <input id="legal_name" name="legal_name" type="text" value="{{ old('legal_name', $company->legal_name) }}" class="company-edit-input" required>
                                <div class="company-edit-help">Bu alan resmi/fatura unvanı görünümü için de temel kaynak olarak kullanılır.</div>
                                @error('legal_name')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-edit-field">
                                <label for="short_name" class="company-edit-label">Kısa Ad</label>
                                <input id="short_name" name="short_name" type="text" value="{{ old('short_name', $company->short_name) }}" class="company-edit-input">
                                @error('short_name')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-edit-third">
                                <label for="status" class="company-edit-label">Durum</label>
                                <select id="status" name="status" class="company-edit-select">
                                    <option value="active" {{ old('status', $company->status) === 'active' ? 'selected' : '' }}>Aktif</option>
                                    <option value="passive" {{ old('status', $company->status) === 'passive' ? 'selected' : '' }}>Pasif</option>
                                </select>
                            </div>
                            <div class="company-edit-third">
                                <label for="risk_status" class="company-edit-label">Risk Durumu</label>
                                <select id="risk_status" name="risk_status" class="company-edit-select">
                                    <option value="">Seçiniz</option>
                                    <option value="low" {{ old('risk_status', $company->risk_status) === 'low' ? 'selected' : '' }}>Düşük</option>
                                    <option value="medium" {{ old('risk_status', $company->risk_status) === 'medium' ? 'selected' : '' }}>Orta</option>
                                    <option value="high" {{ old('risk_status', $company->risk_status) === 'high' ? 'selected' : '' }}>Yüksek</option>
                                    <option value="critical" {{ old('risk_status', $company->risk_status) === 'critical' ? 'selected' : '' }}>Kritik</option>
                                </select>
                            </div>
                            <div class="company-edit-third">
                                <label class="company-edit-label">Para Birimi</label>
                                <div class="company-edit-input" style="display:flex;align-items:center;background:#f8fafc;">{{ $currentTenantForLayout?->default_currency ?? 'TL' }} <span class="ml-2 text-xs text-slate-500">Tenant varsayılanı</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="company-edit-card">
                    <div class="company-edit-card-body">
                        <div class="company-edit-head">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">İletişim ve Resmi Bilgiler</h3>
                                <p class="mt-1 text-sm text-gray-600">İletişim alanlarını ve vergi kimliğini daha sade, resmi kullanım odaklı blokta yönetin.</p>
                            </div>
                            @if($sectionErrors['contact'])
                                <span class="company-edit-chip" style="background:#fef3c7;color:#92400e;">Eksik bilgi var</span>
                            @endif
                        </div>

                        <div class="company-edit-grid">
                            <div class="company-edit-third">
                                <label for="tax_number" class="company-edit-label">VKN / TCKN</label>
                                <input id="tax_number" name="tax_number" type="text" value="{{ old('tax_number', $company->tax_number) }}" class="company-edit-input" inputmode="numeric">
                                <div class="company-edit-help">VKN 10 hane, TCKN 11 hane olmalıdır.</div>
                                @error('tax_number')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-edit-third">
                                <label for="tax_office" class="company-edit-label">Vergi Dairesi</label>
                                <input id="tax_office" name="tax_office" type="text" value="{{ old('tax_office', $company->tax_office) }}" class="company-edit-input">
                                @error('tax_office')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-edit-third">
                                <label for="email" class="company-edit-label">E-posta</label>
                                <input id="email" name="email" type="email" value="{{ old('email', $company->email) }}" class="company-edit-input">
                                @error('email')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-edit-third">
                                <label for="phone" class="company-edit-label">Normal Telefon</label>
                                <input id="phone" name="phone" type="text" value="{{ old('phone', $company->phone) }}" class="company-edit-input" placeholder="0212 xxx xx xx">
                                <div class="company-edit-help">Sabit hat veya WhatsApp dışı iletişim numarası.</div>
                                @error('phone')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-edit-third">
                                <label for="mobile" class="company-edit-label">WhatsApp Cep Telefonu</label>
                                <div style="display:flex; align-items:center; border:1px solid #d0d5dd; border-radius:10px; overflow:hidden; background:#fff;">
                                    <span style="display:inline-flex; align-items:center; gap:8px; padding:0 12px; min-height:44px; background:#f8fafc; border-right:1px solid #e4e7ec; color:#344054; font-size:13px; white-space:nowrap;">🇹🇷 +90</span>
                                    <input id="mobile" name="mobile" type="text" value="{{ app(\App\Services\PhoneNumberNormalizer::class)->formatTurkishPhoneForDisplay(old('mobile', $company->mobile)) ?: old('mobile', $company->mobile) }}" class="company-edit-input" placeholder="5xx xxx xx xx" style="border:0; border-radius:0; box-shadow:none;">
                                </div>
                                <div class="company-edit-help">WhatsApp gönderimleri için kullanılır. Türkiye numarası 05xx veya 5xx xxx xx xx formatında girilebilir.</div>
                                @error('mobile')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-edit-third">
                                <label for="website" class="company-edit-label">Web Sitesi</label>
                                <input id="website" name="website" type="text" value="{{ old('website', $company->website) }}" class="company-edit-input" placeholder="www.firma.com.tr">
                                @error('website')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="company-edit-full">
                                <label for="notes" class="company-edit-label">Resmi / İç Not</label>
                                <textarea id="notes" name="notes" class="company-edit-textarea">{{ old('notes', $company->notes) }}</textarea>
                                @error('notes')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="company-edit-card">
                    <div class="company-edit-card-body">
                        <div class="company-edit-head">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Cari Rolleri</h3>
                                <p class="mt-1 text-sm text-gray-600">Rol kartları daha kompakt ve hizalı görünür. Birden fazla rol aktif olabilir.</p>
                            </div>
                            @if($sectionErrors['roles'])
                                <span class="company-edit-chip" style="background:#fef3c7;color:#92400e;">Eksik bilgi var</span>
                            @endif
                        </div>

                        <div class="company-edit-role-grid">
                            @foreach($roleMeta as $roleKey => $meta)
                                <label class="company-edit-role-card">
                                    <input type="checkbox" name="roles[]" value="{{ $roleKey }}" {{ in_array($roleKey, $selectedRoles, true) ? 'checked' : '' }}>
                                    <span>
                                        <span class="font-semibold text-gray-900">{{ $meta['title'] }}</span>
                                        <span class="mt-1 block text-xs text-gray-500">{{ $meta['copy'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('roles')<div class="company-edit-error">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="company-edit-card" id="supplier-source-mapping-section" data-supplier-mapping-visible="{{ $showSupplierMapping ? '1' : '0' }}" @if(!$showSupplierMapping) style="display: none;" @endif>
                    <div class="company-edit-card-body">
                        <div class="company-edit-head">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Tedarikçi Ürün Kaynağı Eşleştirme</h3>
                                <p class="mt-1 text-sm text-gray-600">Tedarikçi rolündeki cari kartı hazır ürün kaynağıyla eşleştirerek tedarik ekranında doğru ticari cariyle çalışın.</p>
                            </div>
                        </div>

                        <div class="company-edit-grid">
                            <div class="company-edit-full">
                                <label for="supplier_id" class="company-edit-label">Hazır ürün kaynağı</label>
                                <select id="supplier_id" name="supplier_id" class="company-edit-select">
                                    <option value="">Yok</option>
                                    @foreach($supplierOptions as $supplierOption)
                                        <option value="{{ $supplierOption['supplier_id'] }}" {{ (string) $selectedSupplierId === (string) $supplierOption['supplier_id'] ? 'selected' : '' }}>
                                            {{ $supplierOption['name'] }}{{ $supplierOption['is_purchase_ready'] ? '' : ' (Sınırlı erişim)' }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="company-edit-help">Kaynak eşleşmesi varsa tedarik ekranında bu kaynağın karşılık geldiği ticari cari gösterilir.</div>
                                @error('supplier_id')<div class="company-edit-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="company-edit-card">
                    <div class="company-edit-card-body">
                        <div class="company-edit-head">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Durum ve Ayarlar</h3>
                                <p class="mt-1 text-sm text-gray-600">Portal erişimi, operasyon notları ve kayıt durumunu tek blokta yönetin.</p>
                            </div>
                            @if($sectionErrors['settings'])
                                <span class="company-edit-chip" style="background:#fef3c7;color:#92400e;">Eksik bilgi var</span>
                            @endif
                        </div>

                        <div class="company-edit-grid">
                            <div class="company-edit-full">
                                <label class="company-edit-role-card">
                                    <input type="checkbox" name="portal_enabled" value="1" {{ old('portal_enabled', $company->portal_enabled) ? 'checked' : '' }}>
                                    <span>
                                        <span class="font-semibold text-gray-900">Portal erişimi aktif</span>
                                        <span class="mt-1 block text-xs text-gray-500">Firma detay ekranından portal kullanıcısı oluşturulabilir.</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <a href="{{ route('admin.companies.show', $company) }}" class="pd-btn pd-btn-light">İptal</a>
                    <button type="submit" class="pd-btn pd-btn-primary">Değişiklikleri Kaydet</button>
                </div>
            </div>

            <aside class="company-edit-summary">
                <div class="company-edit-card">
                    <div class="company-edit-card-body">
                        <h3 class="text-lg font-semibold text-slate-900">Kayıt Özeti</h3>
                        <p class="mt-1 text-sm text-slate-600">Düzenleme öncesi mevcut durumun hızlı okunabilir özeti.</p>

                        <div class="company-edit-summary-list" style="margin-top: 16px;">
                            <div class="company-edit-summary-row">
                                <span class="text-sm text-slate-500">Cari adı</span>
                                <strong class="text-sm text-slate-900">{{ old('legal_name', $company->legal_name) }}</strong>
                            </div>
                            <div class="company-edit-summary-row">
                                <span class="text-sm text-slate-500">Cari tipi</span>
                                <span class="text-sm font-medium text-slate-900">{{ old('identity_type', $identityType) === 'company' ? 'Tüzel Kişi' : (old('identity_type', $identityType) === 'sole_trader' ? 'Şahıs İşletmesi' : 'Gerçek Kişi') }}</span>
                            </div>
                            <div class="company-edit-summary-row">
                                <span class="text-sm text-slate-500">Vergi bilgisi</span>
                                <span class="company-edit-status-chip {{ $hasTaxInfo ? 'company-edit-status-ok' : 'company-edit-status-wait' }}">{{ $hasTaxInfo ? 'Hazır' : 'Eksik' }}</span>
                            </div>
                            <div class="company-edit-summary-row">
                                <span class="text-sm text-slate-500">İletişim durumu</span>
                                <span class="company-edit-status-chip {{ $hasContactInfo ? 'company-edit-status-ok' : 'company-edit-status-wait' }}">{{ $hasContactInfo ? 'Hazır' : 'Eksik' }}</span>
                            </div>
                            <div class="company-edit-summary-row">
                                <span class="text-sm text-slate-500">Portal</span>
                                <span class="company-edit-status-chip {{ old('portal_enabled', $company->portal_enabled) ? 'company-edit-status-ok' : 'company-edit-status-wait' }}">{{ old('portal_enabled', $company->portal_enabled) ? 'Açık' : 'Kapalı' }}</span>
                            </div>
                            <div class="company-edit-summary-row">
                                <span class="text-sm text-slate-500">Rol sayısı</span>
                                <strong class="text-sm text-slate-900">{{ count($selectedRoles) }}</strong>
                            </div>
                        </div>

                        @if($summaryMissingDetails->isNotEmpty())
                            <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                <div class="font-semibold">Eksik alan özeti</div>
                                <ul class="mt-2 list-disc pl-5">
                                    @foreach($summaryMissingDetails as $detail)
                                        <li>{{ $detail }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                            Bu ekran müşteri/cari kart içindir. Tenant firma profili ayrı ayarlar ekranında yönetilir.
                        </div>

                        <div class="mt-5 grid gap-3">
                            <button type="submit" class="pd-btn pd-btn-primary w-full justify-center">Değişiklikleri Kaydet</button>
                            <a href="{{ route('admin.companies.show', $company) }}" class="pd-btn pd-btn-light w-full justify-center">Detaya Dön</a>
                        </div>
                    </div>
                </div>
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
