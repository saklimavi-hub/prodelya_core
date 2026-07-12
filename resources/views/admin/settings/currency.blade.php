@extends('layouts.prodelya-admin')

@section('title', 'Para Birimi ve Kur Ayarları')
@section('page_title', 'Para Birimi ve Kur Ayarları')
@section('page_subtitle', 'Abone Firma ana para birimini, teklif para birimlerini ve kur davranışını tek merkezden yönetin.')

@section('page_actions')
<div class="flex gap-3">
    @if($canManageCurrencySettings)
        <form method="POST" action="{{ route('admin.settings.currency.refresh-rates') }}" class="inline">
            @csrf
            <button type="submit" class="pd-btn pd-btn-primary">Kurları Güncelle</button>
        </form>
    @endif
    <a href="{{ route('admin.settings') }}" class="pd-btn pd-btn-light">Ayarlar Ana Sayfa</a>
</div>
@endsection

@section('content')
<style>
    .currency-settings-layout { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(300px, 0.95fr); gap: 18px; align-items: start; }
    .currency-settings-stack { display: grid; gap: 18px; }
    .currency-settings-hero { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
    .currency-settings-stat { border: 1px solid var(--pd-line, #e5e7eb); border-radius: 16px; padding: 16px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04); }
    .currency-settings-stat-label { font-size: 12px; color: #64748b; margin-bottom: 8px; }
    .currency-settings-stat-value { font-size: 22px; font-weight: 700; color: #0f172a; }
    .currency-settings-intro { display: grid; gap: 8px; padding: 18px; border-radius: 16px; border: 1px solid #dbeafe; background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%); color: #1e3a8a; }
    .currency-settings-intro-title { font-size: 16px; font-weight: 700; color: #1e3a8a; }
    .currency-settings-intro-text { font-size: 13px; line-height: 1.6; color: #334155; }
    .currency-settings-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .currency-settings-field { display: grid; gap: 7px; }
    .currency-settings-field-full { grid-column: 1 / -1; }
    .currency-settings-select { width: 100%; min-height: 44px; border: 1px solid var(--pd-line, #d1d5db); border-radius: 10px; padding: 10px 12px; background: #fff; color: #0f172a; }
    .currency-settings-help { font-size: 12px; color: #64748b; line-height: 1.5; }
    .currency-settings-checkboxes { display: flex; flex-wrap: wrap; gap: 10px; }
    .currency-settings-checkbox { display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--pd-line, #d1d5db); border-radius: 999px; padding: 8px 12px; background: #fff; font-size: 13px; color: #334155; }
    .currency-settings-actions { display: flex; gap: 10px; justify-content: flex-start; }
    .currency-settings-sidebar { display: grid; gap: 18px; position: sticky; top: 18px; }
    .currency-settings-summary-list { display: grid; gap: 10px; }
    .currency-settings-summary-item { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--pd-line, #e5e7eb); font-size: 13px; }
    .currency-settings-summary-item:last-child { border-bottom: 0; padding-bottom: 0; }
    .currency-settings-link-list { display: grid; gap: 8px; }
    .currency-settings-link { display: block; padding: 10px 12px; border-radius: 10px; background: #f8fafc; color: #334155; text-decoration: none; border: 1px solid transparent; }
    .currency-settings-link:hover { border-color: #cbd5e1; background: #fff; }
    .currency-settings-table-wrap { overflow-x: auto; }
    .currency-settings-badge { display: inline-flex; align-items: center; padding: 4px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .currency-settings-badge-green { background: #dcfce7; color: #166534; }
    .currency-settings-badge-amber { background: #fef3c7; color: #92400e; }
    .currency-settings-badge-slate { background: #e2e8f0; color: #334155; }
    .currency-settings-alert { margin-bottom: 14px; padding: 12px 14px; border-radius: 12px; font-size: 13px; }
    .currency-settings-alert-success { background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; }
    .currency-settings-alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    @media (max-width: 1180px) { .currency-settings-layout { grid-template-columns: 1fr; } .currency-settings-sidebar { position: static; } }
    @media (max-width: 860px) { .currency-settings-hero, .currency-settings-form-grid { grid-template-columns: 1fr; } }
</style>

<div class="currency-settings-hero">
    <div class="currency-settings-stat"><div class="currency-settings-stat-label">Ana Para Birimi</div><div class="currency-settings-stat-value">{{ $currencySettings['base_currency_label'] ?? 'TL' }}</div></div>
    <div class="currency-settings-stat"><div class="currency-settings-stat-label">Varsayılan Teklif</div><div class="currency-settings-stat-value">{{ $currencySettings['default_quote_currency_label'] ?? 'TL' }}</div></div>
    <div class="currency-settings-stat"><div class="currency-settings-stat-label">Kur Kaynağı</div><div class="currency-settings-stat-value">{{ $currencySettings['rate_source_label'] ?? 'TCMB' }}</div></div>
    <div class="currency-settings-stat"><div class="currency-settings-stat-label">Yönetim Modu</div><div class="currency-settings-stat-value" style="font-size: 16px; line-height: 1.35;">{{ $currencySettings['refresh_policy_label'] ?? 'Teklif sırasında kullanıcı yenilesin' }}</div></div>
</div>

<div class="currency-settings-layout">
    <div class="currency-settings-stack">
        <div class="currency-settings-intro">
            <div class="currency-settings-intro-title">Kur yönetim merkeziniz</div>
            <div class="currency-settings-intro-text">Bu ekran firma ana para birimini, teklifte açık olacak para birimlerini ve kur kullanım davranışını birlikte yönetir.</div>
            <div class="currency-settings-intro-text">Ana para birimi değiştiğinde Abone Firma varsayılanı da buna göre güncellenir.</div>
        </div>

        @if(session('success'))
            <div class="currency-settings-alert currency-settings-alert-success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="currency-settings-alert currency-settings-alert-error">{{ $errors->first() }}</div>
        @endif

        @if($canManageCurrencySettings)
            <form method="POST" action="{{ route('admin.settings.currency.update') }}" class="currency-settings-stack">
                @csrf
                @method('PUT')
        @else
            <div class="currency-settings-stack">
        @endif

            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Genel Para Birimi Ayarları</h3>
                    <p class="pd-card-subtitle">Abone Firma temel para birimini, varsayılan teklif para birimini ve açık teklif seçeneklerini yönetin.</p>
                </div>
                <div class="pd-card-body">
                    <div class="currency-settings-form-grid">
                        <div class="currency-settings-field">
                            <label class="pd-label" for="base_currency">Firma Ana Para Birimi</label>
                            <select id="base_currency" name="base_currency" class="currency-settings-select" @if(!$canManageCurrencySettings) disabled @endif>
                                @foreach($availableCurrencies as $value => $label)
                                    <option value="{{ $value }}" @selected(($currencySettings['base_currency'] ?? 'TRY') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="currency-settings-help">Ana birim fiyat, ana para sözleşmesi ve kur dönüşüm omurgası bu seçim üzerinden ilerler.</div>
                            @error('base_currency')<div class="pd-input-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="currency-settings-field">
                            <label class="pd-label" for="default_quote_currency">Varsayılan Teklif Para Birimi</label>
                            <select id="default_quote_currency" name="default_quote_currency" class="currency-settings-select" @if(!$canManageCurrencySettings) disabled @endif>
                                @foreach($availableCurrencies as $value => $label)
                                    <option value="{{ $value }}" @selected(($currencySettings['default_quote_currency'] ?? 'TRY') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="currency-settings-help">Yeni teklif ilk açıldığında seçili gelecek para birimi budur. Açık para birimleri içinde yer almalıdır.</div>
                            @error('default_quote_currency')<div class="pd-input-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="currency-settings-field currency-settings-field-full">
                            <label class="pd-label">Kullanılabilir Teklif Para Birimleri</label>
                            <div class="currency-settings-checkboxes">
                                @foreach($availableCurrencies as $value => $label)
                                    <label class="currency-settings-checkbox" for="currency_{{ $value }}">
                                        <input type="checkbox" name="enabled_quote_currencies[]" value="{{ $value }}" id="currency_{{ $value }}" @if(!$canManageCurrencySettings) disabled @endif @checked(in_array($value, $currencySettings['enabled_quote_currencies'] ?? ['TRY']))>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="currency-settings-help">Kullanıcı teklif ekranında yalnız burada açık olan para birimlerini görür.</div>
                            @error('enabled_quote_currencies')<div class="pd-input-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Kur Politikası</h3>
                    <p class="pd-card-subtitle">Kaynak, kur türü, uyarı eşiği ve Kur Güncelleme Yaklaşımı birlikte yönetilir.</p>
                </div>
                <div class="pd-card-body">
                    <div class="currency-settings-form-grid">
                        <div class="currency-settings-field">
                            <label class="pd-label" for="currency_rate_source">Kur Kaynağı</label>
                            <select id="currency_rate_source" name="currency_rate_source" class="currency-settings-select" @if(!$canManageCurrencySettings) disabled @endif>
                                @foreach($availableRateSources as $value => $label)
                                    <option value="{{ $value }}" @selected(($currencySettings['rate_source'] ?? 'tcmb') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="currency-settings-field">
                            <label class="pd-label" for="currency_rate_type">Kur Türü</label>
                            <select id="currency_rate_type" name="currency_rate_type" class="currency-settings-select" @if(!$canManageCurrencySettings) disabled @endif>
                                @foreach($availableRateTypes as $value => $label)
                                    <option value="{{ $value }}" @selected(($currencySettings['rate_type'] ?? 'forex_selling') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="currency-settings-field">
                            <label class="pd-label" for="currency_stale_after_days">Eski Kur Uyarısı</label>
                            <select id="currency_stale_after_days" name="currency_stale_after_days" class="currency-settings-select" @if(!$canManageCurrencySettings) disabled @endif>
                                @foreach($availableStaleDays as $value => $label)
                                    <option value="{{ $value }}" @selected((int) ($currencySettings['stale_after_days'] ?? 2) === (int) $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="currency-settings-field">
                            <label class="pd-label" for="currency_refresh_policy">Kur Güncelleme Yaklaşımı</label>
                            <select id="currency_refresh_policy" name="currency_refresh_policy" class="currency-settings-select" @if(!$canManageCurrencySettings) disabled @endif>
                                @foreach($availableRefreshPolicies as $value => $label)
                                    <option value="{{ $value }}" @selected(($currencySettings['refresh_policy'] ?? 'manual') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="currency-settings-help" style="margin-top: 12px;">Ayarları Kaydet işlemi yalnız sözleşmeyi günceller. Kurları Güncelle aksiyonu ayrı POST formundan çalışır.</div>
                </div>
            </div>

        @if($canManageCurrencySettings)
                <div class="currency-settings-actions">
                    <button type="submit" class="pd-btn pd-btn-primary">Ayarları Kaydet</button>
                    <a href="{{ route('admin.settings') }}" class="pd-btn pd-btn-light">İptal</a>
                </div>
            </form>
        @else
            </div>
        @endif

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Son Kayıtlı Kurlar</h3>
                <p class="pd-card-subtitle">Son başarılı kayıtlar burada görünür. Başarısız yenilemede mevcut veriler korunur.</p>
            </div>
            <div class="pd-card-body">
                <div class="currency-settings-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Para Birimi</th><th>Kur Değeri</th><th>Kaynak</th><th>Kur Tarihi</th><th>Durum</th></tr></thead>
                        <tbody>
                            @foreach($latestRates as $rate)
                                @php
                                    $badgeClass = match ($rate['status_tone'] ?? 'green') {
                                        'amber' => 'currency-settings-badge-amber',
                                        'slate' => 'currency-settings-badge-slate',
                                        default => 'currency-settings-badge-green',
                                    };
                                @endphp
                                <tr>
                                    <td>{{ $rate['pair'] ?? '-' }}</td>
                                    <td>{{ $rate['value_label'] ?? '-' }}</td>
                                    <td>{{ $rate['provider_label'] ?? '-' }}</td>
                                    <td>{{ $rate['rate_date_label'] ?? '-' }}</td>
                                    <td><span class="currency-settings-badge {{ $badgeClass }}">{{ $rate['status_label'] ?? '-' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="currency-settings-sidebar">
        <div class="pd-card">
            <div class="pd-card-header"><h3 class="pd-card-title">Mevcut Durum</h3><p class="pd-card-subtitle">Kaydedilecek sözleşmenin hızlı özeti.</p></div>
            <div class="pd-card-body">
                <div class="currency-settings-summary-list">
                    <div class="currency-settings-summary-item"><span>Ana Para Birimi</span><strong>{{ $availableCurrencies[$currencySettings['base_currency'] ?? 'TRY'] ?? 'TL' }}</strong></div>
                    <div class="currency-settings-summary-item"><span>Varsayılan Teklif</span><strong>{{ $availableCurrencies[$currencySettings['default_quote_currency'] ?? 'TRY'] ?? 'TL' }}</strong></div>
                    <div class="currency-settings-summary-item"><span>Açık Para Birimleri</span><strong>{{ implode(', ', $currencySettings['enabled_quote_currency_labels'] ?? ['TL']) }}</strong></div>
                    <div class="currency-settings-summary-item"><span>Kur Kaynağı</span><strong>{{ $currencySettings['rate_source_label'] ?? 'TCMB' }}</strong></div>
                    <div class="currency-settings-summary-item"><span>Kur Türü</span><strong>{{ $currencySettings['rate_type_label'] ?? 'Döviz Satış' }}</strong></div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header"><h3 class="pd-card-title">Hızlı Eylemler</h3><p class="pd-card-subtitle">İlgili ayar yüzeylerine kısa geçişler.</p></div>
            <div class="pd-card-body">
                <div class="currency-settings-link-list">
                    @if($canManageCurrencySettings)
                        <a href="{{ route('admin.settings.company-profile.edit') }}" class="currency-settings-link">Abone Firma Profilini Düzenle</a>
                    @endif
                    <a href="{{ route('admin.settings') }}" class="currency-settings-link">Ayarlar Ana Sayfa</a>
                    <a href="{{ route('admin.promotion-quotes.index') }}" class="currency-settings-link">Teklif Listesi</a>
                </div>
            </div>
        </div>

        @if(!$canManageCurrencySettings)
            <div class="pd-card"><div class="pd-card-header"><h3 class="pd-card-title">Yetki Bilgisi</h3></div><div class="pd-card-body"><div class="currency-settings-help">Para birimi ve kur ayarlarını yönetmek için Abone Firma yöneticisi olmanız gerekir.</div></div></div>
        @endif
    </div>
</div>
@endsection
