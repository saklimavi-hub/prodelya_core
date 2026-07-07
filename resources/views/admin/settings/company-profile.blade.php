@extends('layouts.prodelya-admin')

@section('title', 'Firma Bilgileri')
@section('page_title', 'Firma Bilgileri')
@section('page_subtitle', 'Abone firma kimliğinizi teklif PDF’i, iş formu ve müşteri ekranlarında kullanılacak şekilde yönetin.')
@section('hide_side_summary', true)

@php
    $statusPillMap = [
        'Hazır' => 'badge-green',
        'Tanımlı' => 'badge-green',
        'Eksik' => 'badge-red',
        'Sınırlı' => 'badge-amber',
        'Sonraki Faz' => 'badge-gray',
    ];
@endphp

@section('content')
<style>
    .company-profile-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .company-profile-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 328px;
        gap: 22px;
        align-items: start;
    }
    .company-profile-top-card,
    .company-profile-card,
    .company-profile-summary-card,
    .company-profile-status-card,
    .company-profile-bottom-bar {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.05);
    }
    .company-profile-top-card,
    .company-profile-card {
        padding: 20px;
    }
    .company-profile-summary-stack {
        position: sticky;
        top: 12px;
        display: grid;
        gap: 14px;
    }
    .company-profile-summary-card {
        overflow: hidden;
    }
    .company-profile-summary-head {
        padding: 15px 16px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .company-profile-summary-body {
        padding: 15px 16px;
    }
    .company-profile-kicker,
    .company-profile-breadcrumb {
        font-size: 12px;
        color: #64748b;
    }
    .company-profile-breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
    }
    .company-profile-title {
        font-size: 24px;
        line-height: 1.15;
        font-weight: 600;
        color: #111827;
        margin: 0;
    }
    .company-profile-lead,
    .company-profile-subtitle,
    .company-profile-note-text,
    .company-profile-hint {
        color: #64748b;
        line-height: 1.55;
    }
    .company-profile-lead {
        margin-top: 8px;
        font-size: 13px;
        max-width: 760px;
    }
    .company-profile-chip-row,
    .company-profile-status-strip,
    .company-profile-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    .company-profile-chip {
        display: inline-flex;
        align-items: center;
        min-height: 28px;
        padding: 0 10px;
        border-radius: 7px;
        border: 1px solid #dbe3ee;
        background: #fff;
        color: #475569;
        font-size: 12px;
    }
    .company-profile-chip-primary {
        border-color: #c7d8ff;
        background: #eff6ff;
        color: #1d4ed8;
    }
    .company-profile-chip-success {
        border-color: #bbf7d0;
        background: #ecfdf3;
        color: #15803d;
    }
    .company-profile-info-band {
        margin-top: 14px;
        border: 1px solid #bfdbfe;
        background: #eff6ff;
        border-radius: 10px;
        padding: 14px 16px;
        display: grid;
        grid-template-columns: 28px minmax(0, 1fr);
        gap: 12px;
    }
    .company-profile-info-icon {
        width: 28px;
        height: 28px;
        border-radius: 7px;
        background: #dbeafe;
        color: #1d4ed8;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 600;
    }
    .company-profile-info-title {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #1e3a8a;
        margin-bottom: 4px;
    }
    .company-profile-status-grid {
        margin-top: 14px;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }
    .company-profile-status-card {
        padding: 10px 12px;
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }
    .company-profile-status-mark {
        width: 10px;
        height: 10px;
        border-radius: 3px;
        background: #16a34a;
        box-shadow: 0 0 0 3px #dcfce7;
        flex: 0 0 auto;
    }
    .company-profile-status-mark.warn {
        background: #d97706;
        box-shadow: 0 0 0 3px #fef3c7;
    }
    .company-profile-status-mark.muted {
        background: #64748b;
        box-shadow: 0 0 0 3px #e2e8f0;
    }
    .company-profile-status-label {
        display: block;
        font-size: 10.5px;
        color: #8793a5;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .company-profile-status-value {
        display: block;
        margin-top: 2px;
        font-size: 13px;
        color: #1f2937;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .company-profile-form-grid {
        margin-top: 18px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .company-profile-card.full {
        grid-column: 1 / -1;
    }
    .company-profile-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 14px;
        border-bottom: 1px solid #e5e7eb;
    }
    .company-profile-card-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 15px;
        font-weight: 600;
        color: #111827;
    }
    .company-profile-mini-icon {
        width: 28px;
        height: 28px;
        border-radius: 7px;
        background: #eff6ff;
        color: #1d4ed8;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        font-weight: 600;
    }
    .company-profile-card-note {
        font-size: 12px;
        color: #64748b;
    }
    .company-profile-fields {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        margin-top: 16px;
    }
    .company-profile-field.full {
        grid-column: 1 / -1;
    }
    .company-profile-label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 6px;
        font-size: 12px;
        color: #475569;
    }
    .company-profile-required {
        color: #dc2626;
        font-size: 11px;
    }
    .company-profile-input,
    .company-profile-textarea {
        width: 100%;
        border: 1px solid #d9e0ea;
        border-radius: 7px;
        background: #fff;
        color: #182235;
        padding: 9px 10px;
        min-height: 38px;
        outline: none;
        transition: .15s;
        font: inherit;
    }
    .company-profile-textarea {
        min-height: 84px;
        resize: vertical;
    }
    .company-profile-input:focus,
    .company-profile-textarea:focus {
        border-color: #93b4ff;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.09);
    }
    .company-profile-brand-grid {
        display: grid;
        grid-template-columns: 260px minmax(0, 1fr);
        gap: 16px;
        margin-top: 16px;
    }
    .company-profile-brand-placeholder {
        border: 1px dashed #b8c5d8;
        border-radius: 8px;
        background: #fbfdff;
        padding: 16px;
        min-height: 150px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #64748b;
    }
    .company-profile-brand-logo {
        width: 96px;
        height: 52px;
        border: 1px solid #cbd5e1;
        border-radius: 7px;
        background: #fff;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 600;
    }
    .company-profile-brand-options {
        display: grid;
        gap: 12px;
    }
    .company-profile-static-list {
        display: grid;
        gap: 10px;
    }
    .company-profile-static-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #f8fafc;
        padding: 10px 12px;
        font-size: 13px;
        color: #334155;
    }
    .company-profile-bottom-bar {
        margin-top: 16px;
        padding: 12px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        position: sticky;
        bottom: 14px;
        z-index: 5;
    }
    .company-profile-bottom-note {
        margin: 0;
        color: #64748b;
        font-size: 12.5px;
    }
    .company-profile-score {
        display: grid;
        grid-template-columns: 72px 1fr;
        gap: 13px;
        align-items: center;
    }
    .company-profile-score-ring {
        width: 72px;
        height: 72px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        background: conic-gradient(#2563eb 0 {{ $profileSummary['completion_percent'] }}%, #e8eef7 {{ $profileSummary['completion_percent'] }}% 100%);
    }
    .company-profile-score-ring::after {
        content: '';
        position: absolute;
        width: 52px;
        height: 52px;
        border-radius: 10px;
        background: #fff;
    }
    .company-profile-score-value {
        position: relative;
        z-index: 1;
        color: #1d4ed8;
        font-size: 18px;
        font-weight: 600;
    }
    .company-profile-summary-list {
        display: grid;
        gap: 9px;
        margin-top: 15px;
    }
    .company-profile-summary-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #e5e7eb;
        padding-top: 9px;
        color: #425066;
        font-size: 12.5px;
    }
    .company-profile-summary-row:first-child {
        border-top: 0;
        padding-top: 0;
    }
    .company-profile-preview {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
    }
    .company-profile-preview-top {
        height: 58px;
        background: linear-gradient(90deg, #eff6ff, #fff);
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 12px;
        gap: 12px;
    }
    .company-profile-preview-logo {
        width: 82px;
        height: 34px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 600;
    }
    .company-profile-preview-company {
        text-align: right;
        font-size: 10px;
        color: #64748b;
        line-height: 1.35;
    }
    .company-profile-preview-company span {
        display: block;
        color: #223047;
        font-size: 11px;
        font-weight: 600;
    }
    .company-profile-preview-lines {
        padding: 12px;
        display: grid;
        gap: 8px;
    }
    .company-profile-preview-line {
        height: 8px;
        background: #edf2f7;
        border-radius: 4px;
    }
    .company-profile-preview-line.w80 { width: 80%; }
    .company-profile-preview-line.w60 { width: 60%; }
    .company-profile-preview-line.w45 { width: 45%; }
    .company-profile-usage-list {
        display: grid;
        gap: 8px;
    }
    .company-profile-usage-item {
        border: 1px solid #e5e7eb;
        background: #fbfdff;
        border-radius: 7px;
        padding: 9px 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        font-size: 12.5px;
        color: #475569;
    }
    .company-profile-usage-note {
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-size: 11px;
    }
    @media (max-width: 1180px) {
        .company-profile-layout {
            grid-template-columns: 1fr;
        }
        .company-profile-summary-stack {
            position: static;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .company-profile-bottom-bar {
            position: static;
        }
        .company-profile-brand-grid {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 860px) {
        .company-profile-status-grid,
        .company-profile-form-grid,
        .company-profile-fields,
        .company-profile-summary-stack {
            grid-template-columns: 1fr;
        }
        .company-profile-card.full {
            grid-column: auto;
        }
        .company-profile-top-card {
            display: block;
        }
        .company-profile-chip-row {
            margin-top: 14px;
        }
        .company-profile-bottom-bar {
            display: block;
        }
        .company-profile-actions {
            margin-top: 12px;
            justify-content: flex-end;
        }
    }
</style>

<div class="company-profile-shell">
    @if(session('success'))
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="company-profile-layout">
        <div>
            <div class="company-profile-top-card">
                <div class="company-profile-breadcrumb">
                    <span>Kurulum Merkezi</span>
                    <span>&rsaquo;</span>
                    <span>Firma Bilgileri</span>
                </div>
                <h1 class="company-profile-title">Firma Bilgileri</h1>
                <p class="company-profile-lead">Abone firma kimliğinizi, teklif PDF’i, iş formu ve müşteri ekranlarında kullanılacak şekilde tek yerden yönetin.</p>
                <div class="mt-4 company-profile-chip-row">
                    <span class="company-profile-chip company-profile-chip-primary">Para birimi: {{ $profileSummary['status_chips']['currency'] }}</span>
                    <span class="company-profile-chip company-profile-chip-success">Durum: {{ $profileSummary['status_chips']['tenant_status'] }}</span>
                </div>
            </div>

            <div class="company-profile-info-band">
                <div class="company-profile-info-icon">i</div>
                <div>
                    <span class="company-profile-info-title">Bu bilgiler cari kart değildir.</span>
                    <p class="company-profile-note-text">Buradaki kayıt yalnızca abone firmanın kendi profilini temsil eder. Müşteri, tedarikçi, fason ve diğer cari kayıtlar Cari Kartlar ekranından yönetilir.</p>
                </div>
            </div>

            <div class="company-profile-status-grid">
                @foreach($profileSummary['status_cards'] as $card)
                    @php
                        $markClass = in_array($card['status'], ['Eksik', 'Sınırlı'], true)
                            ? 'warn'
                            : ($card['status'] === 'Sonraki Faz' ? 'muted' : '');
                    @endphp
                    <div class="company-profile-status-card">
                        <span class="company-profile-status-mark {{ $markClass }}"></span>
                        <div>
                            <span class="company-profile-status-label">{{ $card['label'] }}</span>
                            <span class="company-profile-status-value">{{ $card['status'] }}</span>
                        </div>
                    </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('admin.settings.company-profile.update') }}">
                @csrf

                <div class="company-profile-form-grid">
                    <article class="company-profile-card">
                        <div class="company-profile-card-head">
                            <div class="company-profile-card-title">
                                <span class="company-profile-mini-icon">01</span>
                                <span>Firma Kimliği</span>
                            </div>
                            <span class="company-profile-card-note">PDF ve teklif başlığı</span>
                        </div>
                        <div class="company-profile-fields">
                            <div class="company-profile-field">
                                <label for="display_name" class="company-profile-label">
                                    <span>Görünen firma adı</span>
                                    <span class="company-profile-required">zorunlu</span>
                                </label>
                                <input id="display_name" name="display_name" type="text" value="{{ old('display_name', $profile['display_name']) }}" class="company-profile-input" required>
                                @error('display_name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="company-profile-field">
                                <label for="legal_name" class="company-profile-label">
                                    <span>Yasal unvan</span>
                                </label>
                                <input id="legal_name" name="legal_name" type="text" value="{{ old('legal_name', $profile['legal_name']) }}" class="company-profile-input">
                                @error('legal_name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="company-profile-field">
                                <label for="tax_office" class="company-profile-label">
                                    <span>Vergi dairesi</span>
                                </label>
                                <input id="tax_office" name="tax_office" type="text" value="{{ old('tax_office', $profile['tax_office']) }}" class="company-profile-input">
                                @error('tax_office')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="company-profile-field">
                                <label for="tax_number" class="company-profile-label">
                                    <span>Vergi numarası</span>
                                </label>
                                <input id="tax_number" name="tax_number" type="text" value="{{ old('tax_number', $profile['tax_number']) }}" class="company-profile-input">
                                @error('tax_number')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </article>

                    <article class="company-profile-card">
                        <div class="company-profile-card-head">
                            <div class="company-profile-card-title">
                                <span class="company-profile-mini-icon">02</span>
                                <span>İletişim</span>
                            </div>
                            <span class="company-profile-card-note">Müşteri ekranları</span>
                        </div>
                        <div class="company-profile-fields">
                            <div class="company-profile-field">
                                <label for="phone" class="company-profile-label">
                                    <span>Telefon</span>
                                </label>
                                <input id="phone" name="phone" type="text" value="{{ old('phone', $profile['phone']) }}" class="company-profile-input">
                                @error('phone')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="company-profile-field">
                                <label for="email" class="company-profile-label">
                                    <span>E-posta</span>
                                </label>
                                <input id="email" name="email" type="email" value="{{ old('email', $profile['email']) }}" class="company-profile-input">
                                @error('email')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="company-profile-field full">
                                <label for="website" class="company-profile-label">
                                    <span>Web sitesi</span>
                                </label>
                                <input id="website" name="website" type="text" value="{{ old('website', $profile['website']) }}" class="company-profile-input" placeholder="www.ornek.com">
                                <div class="company-profile-hint mt-1">Protokol yazılmazsa sistem güvenli şekilde https:// ile tamamlar.</div>
                                @error('website')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </article>

                    <article class="company-profile-card full">
                        <div class="company-profile-card-head">
                            <div class="company-profile-card-title">
                                <span class="company-profile-mini-icon">03</span>
                                <span>Adres ve Belge Bilgileri</span>
                            </div>
                            <span class="company-profile-card-note">Teklif, sipariş ve iş formu alt bilgileri</span>
                        </div>
                        <div class="company-profile-fields">
                            <div class="company-profile-field full">
                                <label for="address" class="company-profile-label">
                                    <span>Adres</span>
                                </label>
                                <textarea id="address" name="address" rows="4" class="company-profile-textarea">{{ old('address', $profile['address']) }}</textarea>
                                @error('address')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="company-profile-field">
                                <label for="district" class="company-profile-label">
                                    <span>İlçe</span>
                                </label>
                                <input id="district" name="district" type="text" value="{{ old('district', $profile['district']) }}" class="company-profile-input">
                                @error('district')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="company-profile-field">
                                <label for="city" class="company-profile-label">
                                    <span>İl</span>
                                </label>
                                <input id="city" name="city" type="text" value="{{ old('city', $profile['city']) }}" class="company-profile-input">
                                @error('city')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="company-profile-field">
                                <label for="country" class="company-profile-label">
                                    <span>Ülke</span>
                                </label>
                                <input id="country" name="country" type="text" value="{{ old('country', $profile['country']) }}" class="company-profile-input">
                                @error('country')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="company-profile-field">
                                <label for="postal_code" class="company-profile-label">
                                    <span>Posta kodu</span>
                                </label>
                                <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code', $profile['postal_code']) }}" class="company-profile-input">
                                @error('postal_code')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </article>

                    <article class="company-profile-card full">
                        <div class="company-profile-card-head">
                            <div class="company-profile-card-title">
                                <span class="company-profile-mini-icon">04</span>
                                <span>Logo ve Görsel Kimlik</span>
                            </div>
                            <span class="company-profile-card-note">Belge üst alanı</span>
                        </div>
                        <div class="company-profile-brand-grid">
                            <div class="company-profile-brand-placeholder">
                                <div class="company-profile-brand-logo">{{ $profileSummary['preview_brand'] }}</div>
                                <div class="text-sm text-gray-900">Logo yükleme sınırlı</div>
                                <div class="mt-1 text-sm text-gray-500">Bu fazda çalışan upload alanı açılmadı. Belgelerde güvenli şekilde firma adı kullanılır.</div>
                            </div>
                            <div class="company-profile-brand-options">
                                <div class="company-profile-static-list">
                                    <div class="company-profile-static-item">
                                        <span>Logo yükleme</span>
                                        <span class="badge badge-gray">Sonraki Faz</span>
                                    </div>
                                    <div class="company-profile-static-item">
                                        <span>Belge ana rengi</span>
                                        <span class="badge badge-gray">Sonraki Faz</span>
                                    </div>
                                    <div class="company-profile-static-item">
                                        <span>Belge kısa adı</span>
                                        <span class="badge badge-gray">Sonraki Faz</span>
                                    </div>
                                    <div class="company-profile-static-item">
                                        <span>Belge alt notu</span>
                                        <span class="badge badge-gray">Sonraki Faz</span>
                                    </div>
                                </div>
                                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    {{ $profileSummary['logo_note'] }}
                                </div>
                            </div>
                        </div>
                    </article>
                </div>

                <div class="company-profile-bottom-bar">
                    <p class="company-profile-bottom-note">Görünen ad boş kalmaz. Boş diğer alanlar güvenli yedek bilgi ile tamamlanır.</p>
                    <div class="company-profile-actions">
                        <a href="{{ route('admin.settings', ['tab' => 'company-profile']) }}" class="pd-btn pd-btn-light">Vazgeç</a>
                        <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
                    </div>
                </div>
            </form>
        </div>

        <aside class="company-profile-summary-stack">
            <div class="company-profile-summary-card">
                <div class="company-profile-summary-head">
                    <div>
                        <div class="text-[14px] font-semibold text-gray-900">Firma Profili Özeti</div>
                        <div class="text-[11.5px] text-slate-400">Eksik alan kontrolü</div>
                    </div>
                    <span class="badge badge-blue">Kurulum</span>
                </div>
                <div class="company-profile-summary-body">
                    <div class="company-profile-score">
                        <div class="company-profile-score-ring">
                            <span class="company-profile-score-value">{{ $profileSummary['completion_percent'] }}%</span>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-900">{{ $profileSummary['completion_message'] }}</div>
                            <p class="mt-1 text-[12.5px] text-gray-500">{{ $profileSummary['completion_note'] }}</p>
                        </div>
                    </div>
                    <div class="company-profile-summary-list">
                        @foreach($profileSummary['checklist'] as $item)
                            <div class="company-profile-summary-row">
                                <span>{{ $item['label'] }}</span>
                                <span class="badge {{ $statusPillMap[$item['status']] ?? 'badge-gray' }}">{{ $item['status'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="company-profile-summary-card">
                <div class="company-profile-summary-head">
                    <div>
                        <div class="text-[14px] font-semibold text-gray-900">Belge Başlığı Önizlemesi</div>
                        <div class="text-[11.5px] text-slate-400">Teklif PDF üst alanı</div>
                    </div>
                </div>
                <div class="company-profile-summary-body">
                    <div class="company-profile-preview">
                        <div class="company-profile-preview-top">
                            <div class="company-profile-preview-logo">{{ $profileSummary['preview_brand'] }}</div>
                            <div class="company-profile-preview-company">
                                <span>{{ $profileSummary['preview_title'] }}</span>
                                {{ $profileSummary['preview_website'] }}<br>
                                {{ $profileSummary['preview_location'] }}
                            </div>
                        </div>
                        <div class="company-profile-preview-lines">
                            <div class="company-profile-preview-line w80"></div>
                            <div class="company-profile-preview-line w60"></div>
                            <div class="company-profile-preview-line w45"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="company-profile-summary-card">
                <div class="company-profile-summary-head">
                    <div>
                        <div class="text-[14px] font-semibold text-gray-900">Bu Bilgiler Nerede Kullanılır?</div>
                        <div class="text-[11.5px] text-slate-400">Görünür alanlar</div>
                    </div>
                </div>
                <div class="company-profile-summary-body">
                    <div class="company-profile-usage-list">
                        @foreach($profileSummary['usage_areas'] as $area)
                            <div class="company-profile-usage-item">
                                <span>{{ $area['label'] }}</span>
                                <span class="company-profile-usage-note">{{ $area['note'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>
@endsection
