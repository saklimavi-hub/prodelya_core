@extends('layouts.prodelya-admin')

@section('title', $company->legal_name)
@section('page_title', $company->legal_name)
@section('page_subtitle')
    {{ $company->short_name ?: 'Cari kart detayları ve operasyonel özet' }}
@endsection

@section('page_actions')
    <div class="flex gap-3">
        <a href="{{ route('admin.companies.edit', $company) }}" class="pd-btn pd-btn-light">
            <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Düzenle
        </a>
        <a href="{{ route('admin.companies.index') }}" class="pd-btn pd-btn-primary">Listeye Dön</a>
    </div>
@endsection

@section('content')
<div class="pd-grid" style="grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);">
    <div>
        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Firma Bilgileri</h3>
                <p class="pd-card-subtitle">Cari kartin temel kimlik, iletisim ve vergi bilgileri.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-2">
                    <div>
                        <div style="margin-bottom: 16px;">
                            <div class="text-xs" style="color: var(--pd-muted);">Firma Unvani</div>
                            <div style="margin-top: 6px; font-weight: 600;">{{ $company->legal_name }}</div>
                        </div>

                        @if($company->tax_office || $company->tax_number)
                            <div style="margin-bottom: 16px;">
                                <div class="text-xs" style="color: var(--pd-muted);">Vergi Bilgileri</div>
                                <div style="margin-top: 6px;">
                                    @if($company->tax_office)
                                        <div>Vergi Dairesi: {{ $company->tax_office }}</div>
                                    @endif
                                    @if($company->tax_number)
                                        <div>VKN / TCKN: {{ $company->tax_number }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Web Sitesi</div>
                            <div style="margin-top: 6px;">
                                @if($company->website)
                                    <a href="{{ $company->website }}" target="_blank" rel="noreferrer" style="color: var(--pd-blue); text-decoration: none;">{{ $company->website }}</a>
                                @else
                                    <span style="color: #98a2b3;">Belirtilmemis</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div>
                        <div style="margin-bottom: 16px;">
                            <div class="text-xs" style="color: var(--pd-muted);">Iletisim</div>
                            <div style="margin-top: 6px;">
                                @if($company->email)
                                    <div>E-posta: <a href="mailto:{{ $company->email }}" style="color: var(--pd-blue); text-decoration: none;">{{ $company->email }}</a></div>
                                @endif
                                @if($company->phone)
                                    <div>Telefon: {{ $company->phone }}</div>
                                @endif
                                @if($company->mobile)
                                    <div>Mobil: {{ $company->mobile }}</div>
                                @endif
                                @if(!$company->email && !$company->phone && !$company->mobile)
                                    <span style="color: #98a2b3;">Iletisim bilgisi yok</span>
                                @endif
                            </div>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <div class="text-xs" style="color: var(--pd-muted);">Durum</div>
                            <div style="margin-top: 6px; display: flex; gap: 8px; flex-wrap: wrap;">
                                <span class="pd-badge {{ $company->status === 'active' ? 'pd-badge-green' : 'pd-badge-gray' }}">
                                    {{ $company->status === 'active' ? 'Aktif' : 'Pasif' }}
                                </span>
                                @if($company->risk_status)
                                    <span class="pd-badge pd-badge-amber">Risk: {{ $company->risk_status }}</span>
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Portal Erişimi</div>
                            <div style="margin-top: 6px;">
                                <span class="pd-badge {{ $company->portal_enabled ? 'pd-badge-green' : 'pd-badge-gray' }}">
                                    {{ $company->portal_enabled ? 'Aktif' : 'Pasif' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                @if($company->notes)
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--pd-line);">
                        <div class="text-xs" style="color: var(--pd-muted);">Notlar</div>
                        <div style="margin-top: 6px;">{{ $company->notes }}</div>
                    </div>
                @endif
            </div>
        </div>

        @if($company->hasRole('print_fason') || $company->hasRole('production_partner'))
            <div class="pd-card" style="margin-bottom: 14px;">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Fason Bilgisi</h3>
                    <p class="pd-card-subtitle">Bu firma, üretim / baskı-fason operasyonlarında seçilebilecek operasyonel cari rolüne sahiptir.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-grid pd-grid-2">
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Cari Rolleri</div>
                            <div style="margin-top: 6px; display: flex; gap: 8px; flex-wrap: wrap;">
                                @if($company->hasRole('print_fason'))
                                    <span class="pd-badge pd-badge-purple">Fason Baskı Firması</span>
                                @endif
                                @if($company->hasRole('production_partner'))
                                    <span class="pd-badge pd-badge-amber">Fason Üretim Firması</span>
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Operasyon Kullanımı</div>
                            <div style="margin-top: 6px; font-weight: 600;">Bu cari üretim / baskı-fason aşamalarında seçilebilir.</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($linkedCurrentAccount)
            <div class="pd-card" style="margin-bottom: 14px;">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Cari Omurga Özeti</h3>
                    <p class="pd-card-subtitle">Firma kaydına bağlı finansal cari hesap durumu ve güvenli bağlantılar.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-grid pd-grid-2">
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Bağlı Finansal Hesap</div>
                            <div style="margin-top: 6px; font-weight: 600;">{{ $linkedCurrentAccount->safeDisplayName() }}</div>
                        </div>
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Durum</div>
                            <div style="margin-top: 6px;">
                                @php
                                    $accountStatusTone = match($linkedCurrentAccount->status) {
                                        \App\Models\CurrentAccount::STATUS_ACTIVE => 'green',
                                        \App\Models\CurrentAccount::STATUS_BLOCKED => 'red',
                                        \App\Models\CurrentAccount::STATUS_ARCHIVED => 'gray',
                                        default => 'amber',
                                    };
                                @endphp
                                <span class="pd-badge pd-badge-{{ $accountStatusTone }}">{{ $linkedCurrentAccount->safeStatusLabel() }}</span>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Cari Rolleri</div>
                            <div style="margin-top: 6px; display: flex; gap: 8px; flex-wrap: wrap;">
                                @forelse($linkedCurrentAccount->roles as $role)
                                    @php
                                        $accountRoleTone = match($role->role) {
                                            \App\Models\CurrentAccountRole::ROLE_CUSTOMER => 'green',
                                            \App\Models\CurrentAccountRole::ROLE_SUPPLIER => 'blue',
                                            \App\Models\CurrentAccountRole::ROLE_SUBCONTRACTOR => 'purple',
                                            \App\Models\CurrentAccountRole::ROLE_CARRIER => 'amber',
                                            \App\Models\CurrentAccountRole::ROLE_SERVICE_PROVIDER => 'indigo',
                                            default => 'gray',
                                        };
                                    @endphp
                                    <span class="pd-badge pd-badge-{{ $accountRoleTone }}">{{ $role->safeRoleLabel() }}</span>
                                @empty
                                    <span class="pd-badge pd-badge-gray">Rol Yok</span>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Portal Durumu</div>
                            <div style="margin-top: 6px;">
                                <span class="pd-badge {{ $company->portal_enabled ? 'pd-badge-green' : 'pd-badge-gray' }}">
                                    {{ $company->portal_enabled ? 'Portal Açık' : 'Portal Kapalı' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="pd-actions-wrap" style="margin-top: 16px;">
                        @if($canViewFinancialData)
                            <a href="{{ route('admin.current-accounts.transactions.index', $linkedCurrentAccount) }}" class="pd-btn pd-btn-light pd-btn-sm">Finansal Cari Hareketleri</a>
                        @endif
                        <a href="{{ route('admin.current-accounts.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Cari Listesini Aç</a>
                    </div>
                </div>
            </div>
        @endif

        @if($company->hasRole('supplier'))
            <div class="pd-card" style="margin-bottom: 14px;">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Tedarikçi Kaynak Eşleştirme</h3>
                    <p class="pd-card-subtitle">Hazır ürün kaynağı ile bu ticari cari kart arasındaki güvenli eşleşme durumu.</p>
                </div>
                <div class="pd-card-body">
                    @if($supplierMapping)
                        <div class="pd-grid pd-grid-2">
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Hazır Ürün Kaynağı</div>
                                <div style="margin-top: 6px; font-weight: 600;">{{ $supplierMapping['supplier_name'] }}</div>
                            </div>
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Durum</div>
                                <div style="margin-top: 6px; display: flex; gap: 8px; flex-wrap: wrap;">
                                    <span class="pd-badge {{ $supplierMapping['is_active'] ? 'pd-badge-green' : 'pd-badge-gray' }}">
                                        {{ $supplierMapping['is_active'] ? 'Aktif' : 'Pasif' }}
                                    </span>
                                    <span class="pd-badge {{ $supplierMapping['can_request_purchase'] ? 'pd-badge-blue' : 'pd-badge-amber' }}">
                                        {{ $supplierMapping['can_request_purchase'] ? 'Tedarik ekranında kullanılabilir' : 'Satın alma yetkisi sınırlı' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="pd-note" style="border-color: #fde68a; background: #fffbeb; color: #92400e;">
                            Bu cari tedarikçi olarak işaretli fakat hazır ürün kaynağı eşleşmemiş.
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                    <div>
                        <h3 class="pd-card-title">Yetkili Kisiler</h3>
                        <p class="pd-card-subtitle">Firma ile ilgili operasyonel ve satis iletisimleri.</p>
                    </div>
                    <a href="#contact-create-form" class="pd-btn pd-btn-light pd-btn-sm">
                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Yetkili Ekle
                    </a>
                </div>
            </div>
            <div class="pd-card-body" id="company-contacts">
                @if($company->contacts->count() > 0)
                    <div class="pd-table-wrap">
                        <table class="pd-table">
                            <thead>
                                <tr>
                                    <th>Ad Soyad</th>
                                    <th>Unvan</th>
                                    <th>Iletisim</th>
                                    <th class="text-right">Aksiyon</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($company->contacts as $contact)
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600;">{{ $contact->name }}</div>
                                            @if($contact->is_primary)
                                                <span class="pd-badge pd-badge-blue" style="margin-top: 6px;">Birincil</span>
                                            @endif
                                        </td>
                                        <td>{{ $contact->title ?: '-' }}</td>
                                        <td>
                                            @if($contact->email)
                                                <div>{{ $contact->email }}</div>
                                            @endif
                                            @if($contact->phone)
                                                <div>{{ $contact->phone }}</div>
                                            @endif
                                            @if($contact->mobile)
                                                <div>{{ $contact->mobile }}</div>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            <span class="text-xs" style="color: var(--pd-muted);">İletişim kaydı</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="pd-note">Henüz yetkili kişi eklenmemiş.</div>
                @endif

                <div id="contact-create-form" style="margin-top: 18px; padding: 16px; border: 1px solid var(--pd-line); border-radius: 10px; background: #fafafa;">
                    <h4 style="margin: 0 0 8px; font-size: 15px;">Yeni Yetkili Kişi</h4>
                    <p style="margin: 0 0 14px; color: var(--pd-muted); font-size: 13px;">Firma iletişim kayıtlarını burada yönetin. Bu alan portal kullanıcısı oluşturmaz.</p>

                    <form method="POST" action="{{ route('admin.companies.contacts.store', $company) }}" style="display: grid; gap: 12px;">
                        @csrf
                        <div class="pd-grid pd-grid-2">
                            <div>
                                <label for="contact_name" class="text-xs" style="color: var(--pd-muted);">Ad Soyad</label>
                                <input id="contact_name" name="contact_name" type="text" value="{{ old('contact_name') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('contact_name')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="contact_title" class="text-xs" style="color: var(--pd-muted);">Ünvan / Görev</label>
                                <input id="contact_title" name="contact_title" type="text" value="{{ old('contact_title') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('contact_title')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="pd-grid pd-grid-2">
                            <div>
                                <label for="contact_email" class="text-xs" style="color: var(--pd-muted);">E-posta</label>
                                <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('contact_email')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="contact_phone" class="text-xs" style="color: var(--pd-muted);">Telefon</label>
                                <input id="contact_phone" name="contact_phone" type="text" value="{{ old('contact_phone') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('contact_phone')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="pd-grid pd-grid-2">
                            <div>
                                <label for="contact_mobile" class="text-xs" style="color: var(--pd-muted);">Mobil</label>
                                <input id="contact_mobile" name="contact_mobile" type="text" value="{{ old('contact_mobile') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('contact_mobile')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                            <label style="display: flex; align-items: center; gap: 8px; margin-top: 22px; font-size: 13px; color: var(--pd-muted);">
                                <input type="checkbox" name="contact_is_primary" value="1" {{ old('contact_is_primary') ? 'checked' : '' }}>
                                Varsayılan yetkili olarak işaretle
                            </label>
                        </div>

                        <div>
                            <button type="submit" class="pd-btn pd-btn-primary">Yetkiliyi Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                    <div>
                        <h3 class="pd-card-title">Adresler</h3>
                        <p class="pd-card-subtitle">Fatura, teslimat ve varsayilan adres kayitlari.</p>
                    </div>
                    <a href="#address-create-form" class="pd-btn pd-btn-light pd-btn-sm">
                        <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Adres Ekle
                    </a>
                </div>
            </div>
            <div class="pd-card-body" id="company-addresses">
                @if($company->addresses->count() > 0)
                    <div class="pd-grid">
                        @foreach($company->addresses as $address)
                            <div style="padding: 16px; border: 1px solid var(--pd-line); border-left: 4px solid var(--pd-blue); border-radius: 8px;">
                                <div style="display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                                    <div>
                                        <div style="font-weight: 600;">{{ $address->title ?: 'Adres' }}</div>
                                        <div style="margin-top: 8px; color: var(--pd-muted);">{{ $address->full_address }}</div>
                                        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;">
                                            <span class="pd-badge pd-badge-blue">
                                                {{ match($address->address_type) {
                                                    'billing' => 'Fatura',
                                                    'delivery' => 'Teslimat',
                                                    'invoice' => 'Resmi Fatura',
                                                    'shipping' => 'Sevkiyat',
                                                    default => 'Genel',
                                                } }}
                                            </span>
                                            @if($address->is_default)
                                                <span class="pd-badge pd-badge-amber">Varsayilan</span>
                                            @endif
                                        </div>
                                    </div>
                                    <span class="text-xs" style="color: var(--pd-muted);">Adres kaydı</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="pd-note">Henüz adres eklenmemiş.</div>
                @endif

                <div id="address-create-form" style="margin-top: 18px; padding: 16px; border: 1px solid var(--pd-line); border-radius: 10px; background: #fafafa;">
                    <h4 style="margin: 0 0 8px; font-size: 15px;">Yeni Adres</h4>
                    <p style="margin: 0 0 14px; color: var(--pd-muted); font-size: 13px;">Fatura, teslimat veya genel kullanım adreslerini aynı ekrandan ekleyin.</p>

                    <form method="POST" action="{{ route('admin.companies.addresses.store', $company) }}" style="display: grid; gap: 12px;">
                        @csrf
                        <div class="pd-grid pd-grid-2">
                            <div>
                                <label for="address_title" class="text-xs" style="color: var(--pd-muted);">Adres Başlığı</label>
                                <input id="address_title" name="address_title" type="text" value="{{ old('address_title') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('address_title')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="address_type" class="text-xs" style="color: var(--pd-muted);">Adres Tipi</label>
                                <select id="address_type" name="address_type" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    <option value="other" {{ old('address_type', 'other') === 'other' ? 'selected' : '' }}>Genel</option>
                                    <option value="billing" {{ old('address_type') === 'billing' ? 'selected' : '' }}>Fatura</option>
                                    <option value="delivery" {{ old('address_type') === 'delivery' ? 'selected' : '' }}>Teslimat</option>
                                    <option value="invoice" {{ old('address_type') === 'invoice' ? 'selected' : '' }}>Resmi Fatura</option>
                                    <option value="shipping" {{ old('address_type') === 'shipping' ? 'selected' : '' }}>Sevkiyat</option>
                                </select>
                                @error('address_type')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div>
                            <label for="address_body" class="text-xs" style="color: var(--pd-muted);">Adres</label>
                            <textarea id="address_body" name="address_body" class="pd-input" style="width: 100%; margin-top: 6px; min-height: 92px;">{{ old('address_body') }}</textarea>
                            @error('address_body')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                        </div>

                        <div class="pd-grid pd-grid-2">
                            <div>
                                <label for="address_district" class="text-xs" style="color: var(--pd-muted);">İlçe</label>
                                <input id="address_district" name="address_district" type="text" value="{{ old('address_district') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('address_district')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="address_city" class="text-xs" style="color: var(--pd-muted);">İl</label>
                                <input id="address_city" name="address_city" type="text" value="{{ old('address_city') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('address_city')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="pd-grid pd-grid-2">
                            <div>
                                <label for="address_country" class="text-xs" style="color: var(--pd-muted);">Ülke</label>
                                <input id="address_country" name="address_country" type="text" value="{{ old('address_country', 'Türkiye') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('address_country')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="address_postal_code" class="text-xs" style="color: var(--pd-muted);">Posta Kodu</label>
                                <input id="address_postal_code" name="address_postal_code" type="text" value="{{ old('address_postal_code') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('address_postal_code')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--pd-muted);">
                            <input type="checkbox" name="address_is_default" value="1" {{ old('address_is_default') ? 'checked' : '' }}>
                            Varsayılan adres olarak işaretle
                        </label>

                        <div>
                            <button type="submit" class="pd-btn pd-btn-primary">Adresi Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                    <div>
                        <h3 class="pd-card-title">Portal Kullanıcıları</h3>
                        <p class="pd-card-subtitle">Müşteri portalına giriş yapacak kullanıcıları yönetin.</p>
                    </div>
                    <span class="pd-badge {{ $company->portal_enabled ? 'pd-badge-green' : 'pd-badge-amber' }}">
                        {{ $company->portal_enabled ? 'Portal Açık' : 'Portal Kapalı' }}
                    </span>
                </div>
            </div>
            <div class="pd-card-body">
                @if(session('portal_invite_link'))
                    <div class="pd-note" style="margin-bottom: 16px;">
                        <strong>Güvenli davet bağlantısı:</strong>
                        <div style="margin-top: 8px; word-break: break-all;">{{ session('portal_invite_link') }}</div>
                    </div>
                @endif

                @if(!$company->portal_enabled)
                    <div class="pd-note" style="margin-bottom: 16px;">Firma portalı kapalıysa davet edilen kullanıcı giriş yapamaz. Giriş açmak için firma portal erişimini aktif hale getirin.</div>
                @endif

                <div class="pd-grid pd-grid-2" style="margin-bottom: 20px;">
                    <div style="padding: 16px; border: 1px solid var(--pd-line); border-radius: 10px; background: #fafafa;">
                        <h4 style="margin: 0 0 8px; font-size: 15px;">Yeni Portal Kullanıcısı</h4>
                        <p style="margin: 0 0 14px; color: var(--pd-muted); font-size: 13px;">Firma yetkilisi seçildiğinde ad, e-posta ve telefon alanları otomatik doldurulur. İsterseniz yine düzenleyebilirsiniz.</p>

                        <form method="POST" action="{{ route('admin.companies.portal-users.store', $company) }}" style="display: grid; gap: 12px;">
                            @csrf
                            <div>
                                <label for="portal_user_name" class="text-xs" style="color: var(--pd-muted);">Ad Soyad</label>
                                <input id="portal_user_name" name="name" type="text" value="{{ old('name') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('name')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="portal_user_email" class="text-xs" style="color: var(--pd-muted);">E-posta</label>
                                <input id="portal_user_email" name="email" type="email" value="{{ old('email') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('email')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="portal_user_phone" class="text-xs" style="color: var(--pd-muted);">Telefon</label>
                                <input id="portal_user_phone" name="phone" type="text" value="{{ old('phone') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                @error('phone')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="portal_user_contact" class="text-xs" style="color: var(--pd-muted);">Firma Yetkilisi</label>
                                <select id="portal_user_contact" name="company_contact_id" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    <option value="">Yetkili seçmeden devam et</option>
                                    @foreach($company->contacts as $contact)
                                        <option
                                            value="{{ $contact->id }}"
                                            data-contact-name="{{ $contact->name }}"
                                            data-contact-email="{{ $contact->email }}"
                                            data-contact-phone="{{ $contact->mobile ?: $contact->phone }}"
                                            {{ (string) old('company_contact_id') === (string) $contact->id ? 'selected' : '' }}>
                                            {{ $contact->name }}{{ $contact->email ? ' - ' . $contact->email : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('company_contact_id')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>
                            <div id="portal-user-contact-warning" class="pd-note" style="display: none; margin: 0; border-color: #fde68a; background: #fffbeb; color: #92400e;">
                                Seçili yetkilinin e-posta adresi yok. Portal daveti için e-posta girmeniz gerekir.
                            </div>
                            <button type="submit" class="pd-btn pd-btn-primary">Kullanıcı Oluştur ve Davet Et</button>
                        </form>
                    </div>

                    <div style="padding: 16px; border: 1px solid var(--pd-line); border-radius: 10px; background: #fafafa;">
                        <h4 style="margin: 0 0 8px; font-size: 15px;">Akış Özeti</h4>
                        <div class="pd-summary-info">
                            <div class="pd-summary-row">
                                <span>Toplam kullanıcı</span>
                                <span class="font-medium">{{ $company->portalUsers->count() }}</span>
                            </div>
                            <div class="pd-summary-row">
                                <span>Aktif</span>
                                <span class="font-medium">{{ $company->portalUsers->where('status', \App\Models\CustomerPortalUser::STATUS_ACTIVE)->count() }}</span>
                            </div>
                            <div class="pd-summary-row">
                                <span>Davet bekleyen</span>
                                <span class="font-medium">{{ $company->portalUsers->where('status', \App\Models\CustomerPortalUser::STATUS_INVITED)->count() }}</span>
                            </div>
                            <div class="pd-summary-row">
                                <span>Şifre kuruldu</span>
                                <span class="font-medium">{{ $company->portalUsers->filter(fn ($user) => $user->password_set_at !== null)->count() }}</span>
                            </div>
                        </div>
                        <p style="margin: 14px 0 0; color: var(--pd-muted); font-size: 13px;">
                            Portal erişimi açık olan firmalara müşteri portal kullanıcısı tanımlanabilir. Şifreler hiçbir zaman admin ekranında görünmez.
                        </p>
                    </div>
                </div>

                @if($company->portalUsers->count() > 0)
                    <div class="pd-table-wrap">
                        <table class="pd-table">
                            <thead>
                                <tr>
                                    <th>Ad</th>
                                    <th>E-posta</th>
                                    <th>Yetkili</th>
                                    <th>Durum</th>
                                    <th>Son Giriş</th>
                                    <th class="text-right">Aksiyon</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($company->portalUsers as $portalUser)
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600;">{{ $portalUser->safeDisplayName() }}</div>
                                            <div class="text-xs" style="color: var(--pd-muted); margin-top: 4px;">
                                                {{ $portalUser->password_set_at ? 'Şifre belirlendi' : 'Şifre bekleniyor' }}
                                            </div>
                                        </td>
                                        <td>{{ $portalUser->email }}</td>
                                        <td>{{ $portalUser->companyContact?->name ?: '-' }}</td>
                                        <td>
                                            <span class="pd-badge {{
                                                $portalUser->status === \App\Models\CustomerPortalUser::STATUS_ACTIVE ? 'pd-badge-green' :
                                                ($portalUser->status === \App\Models\CustomerPortalUser::STATUS_INVITED ? 'pd-badge-blue' :
                                                ($portalUser->status === \App\Models\CustomerPortalUser::STATUS_SUSPENDED ? 'pd-badge-amber' : 'pd-badge-gray'))
                                            }}">
                                                {{ $portalUser->safeStatusLabel() }}
                                            </span>
                                        </td>
                                        <td>{{ $portalUser->last_login_at?->format('d.m.Y H:i') ?: '-' }}</td>
                                        <td class="text-right">
                                            <div style="display: inline-flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">
                                                <form method="POST" action="{{ route('admin.companies.portal-users.resend-invite', [$company, $portalUser]) }}">
                                                    @csrf
                                                    <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Davet Gönder</button>
                                                </form>
                                                @if($portalUser->status !== \App\Models\CustomerPortalUser::STATUS_ACTIVE)
                                                    <form method="POST" action="{{ route('admin.companies.portal-users.toggle-status', [$company, $portalUser]) }}">
                                                        @csrf
                                                        <input type="hidden" name="status" value="{{ \App\Models\CustomerPortalUser::STATUS_ACTIVE }}">
                                                        <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Aktif Et</button>
                                                    </form>
                                                @endif
                                                @if($portalUser->status !== \App\Models\CustomerPortalUser::STATUS_PASSIVE)
                                                    <form method="POST" action="{{ route('admin.companies.portal-users.toggle-status', [$company, $portalUser]) }}">
                                                        @csrf
                                                        <input type="hidden" name="status" value="{{ \App\Models\CustomerPortalUser::STATUS_PASSIVE }}">
                                                        <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Pasifleştir</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="pd-note">Bu firma için henüz portal kullanıcısı oluşturulmamış.</div>
                @endif
            </div>
        </div>

        @if($canViewFinancialData)
            <div class="pd-card" style="margin-bottom: 14px;">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Finans Özeti</h3>
                    <p class="pd-card-subtitle">Siparis adedi, son siparis ve risk limiti ozeti.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-grid pd-grid-3">
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Toplam Siparis</div>
                            <div style="margin-top: 6px; font-size: 22px; font-weight: 700;">{{ $company->customerOrders->count() }}</div>
                        </div>
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Son Siparis</div>
                            <div style="margin-top: 6px;">
                                @if($lastOrder = $company->customerOrders->first())
                                    {{ $lastOrder->document_number }} ({{ $lastOrder->created_at->format('d.m.Y') }})
                                @else
                                    Henuz siparis yok
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Risk Limiti</div>
                            <div style="margin-top: 6px; font-size: 18px; font-weight: 600;">-</div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="pd-card" style="margin-bottom: 14px;">
                <div class="pd-card-body text-center">
                    <svg class="pd-icon-lg" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #98a2b3;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                    <h3 style="margin: 12px 0 6px;">Finans Bilgileri</h3>
                    <p style="margin: 0; color: var(--pd-muted);">Bu alani goruntuleme yetkiniz yok.</p>
                </div>
            </div>
        @endif

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Son Siparisler</h3>
                <p class="pd-card-subtitle">Cari karta ait son hareketler.</p>
            </div>
            <div class="pd-card-body">
                @if($company->customerOrders->count() > 0)
                    <div class="pd-table-wrap">
                        <table class="pd-table">
                            <thead>
                                <tr>
                                    <th>Siparis No</th>
                                    <th>Tarih</th>
                                    <th>Durum</th>
                                    <th>Tutar</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($company->customerOrders as $order)
                                    <tr>
                                        <td>{{ $order->document_number }}</td>
                                        <td>{{ $order->created_at->format('d.m.Y') }}</td>
                                        <td><span class="pd-badge pd-badge-gray">{{ $order->status }}</span></td>
                                        <td>
                                            @if($canViewFinancialData)
                                                -
                                            @else
                                                Gizli
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="pd-note">Henuz siparis bulunmuyor.</div>
                @endif
            </div>
        </div>
    </div>

    <div>
        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Operasyon Özeti</h3>
                <p class="pd-card-subtitle">Cari kartin hizli okunabilir durum ozeti.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Kisa Ad</span>
                        <span class="font-medium">{{ $company->short_name ?: '-' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Durum</span>
                        <span class="pd-badge {{ $company->status === 'active' ? 'pd-badge-green' : 'pd-badge-gray' }}">
                            {{ $company->status === 'active' ? 'Aktif' : 'Pasif' }}
                        </span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Portal</span>
                        <span class="pd-badge {{ $company->portal_enabled ? 'pd-badge-green' : 'pd-badge-gray' }}">
                            {{ $company->portal_enabled ? 'Acik' : 'Kapali' }}
                        </span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Yetkili</span>
                        <span class="font-medium">{{ $company->contacts->count() }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Adres</span>
                        <span class="font-medium">{{ $company->addresses->count() }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Siparis</span>
                        <span class="font-medium">{{ $company->customerOrders->count() }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Roller</h3>
                <p class="pd-card-subtitle">Firma uzerinde aktif rol dagilimi.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-list">
                    @forelse($company->getRoleNames() as $index => $roleName)
                        <div class="pd-summary-row">
                            <span>{{ $roleName }}</span>
                            <span class="pd-badge pd-badge-{{ $company->getRoleBadgeColors()[$index] }}">Aktif</span>
                        </div>
                    @empty
                        <div class="pd-note">Tanimli rol bulunmuyor.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const contactSelect = document.getElementById('portal_user_contact');
        const nameInput = document.getElementById('portal_user_name');
        const emailInput = document.getElementById('portal_user_email');
        const phoneInput = document.getElementById('portal_user_phone');
        const warningBox = document.getElementById('portal-user-contact-warning');

        if (!contactSelect || !nameInput || !emailInput || !phoneInput || !warningBox) {
            return;
        }

        const applyContact = () => {
            const selected = contactSelect.options[contactSelect.selectedIndex];

            if (!selected || !selected.value) {
                warningBox.style.display = 'none';
                return;
            }

            nameInput.value = selected.dataset.contactName || '';
            emailInput.value = selected.dataset.contactEmail || '';
            phoneInput.value = selected.dataset.contactPhone || '';

            if (!selected.dataset.contactEmail) {
                warningBox.style.display = 'block';
                return;
            }

            warningBox.style.display = 'none';
        };

        contactSelect.addEventListener('change', applyContact);
        applyContact();
    }());
</script>
@endpush

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Cari Kart Özeti</h3>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı İşlemler</h4>
            <div class="pd-summary-list">
                <a href="{{ route('admin.companies.edit', $company) }}" class="pd-summary-item">Kaydi duzenle</a>
                <a href="{{ route('admin.companies.index') }}" class="pd-summary-item">Listeye don</a>
                <span class="pd-summary-item">Firma bilgilerini ve iletişim kayıtlarını yönetin.</span>
            </div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Durum Bilgisi</h4>
            <div class="pd-summary-info">
                <div class="pd-summary-row">
                    <span>Risk</span>
                    <span class="font-medium">{{ $company->risk_status ?: 'Yok' }}</span>
                </div>
                <div class="pd-summary-row">
                    <span>E-posta</span>
                    <span class="font-medium">{{ $company->email ?: '-' }}</span>
                </div>
                <div class="pd-summary-row">
                    <span>Telefon</span>
                    <span class="font-medium">{{ $company->phone ?: ($company->mobile ?: '-') }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
