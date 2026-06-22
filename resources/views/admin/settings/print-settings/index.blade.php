@extends('layouts.prodelya-admin')

@section('title', 'Baskı Ayarları')
@section('page_title', 'Baskı Ayarları')
@section('page_subtitle', 'Tenant içinde kullanılacak baskı tiplerini, üretim modunu, grafik/üretim/setup gerekliliğini ve varsayılan değerleri yönetin.')

@section('page_actions')
<div class="flex gap-3">
    <form method="POST" action="{{ route('admin.settings.print-settings.sync') }}">
        @csrf
        <button type="submit" class="pd-btn pd-btn-light">Eksik Baskı Ayarlarını Tamamla</button>
    </form>
    <a href="{{ route('admin.settings') }}" class="pd-btn pd-btn-primary">Sistem Ayarları</a>
</div>
@endsection

@section('content')
<div class="pd-grid pd-grid-5" style="margin-bottom: 14px;">
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Aktif Baskılar</div><div class="text-2xl font-bold" style="margin-top: 6px;">{{ $stats['active'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">İç Üretim</div><div class="text-2xl font-bold" style="margin-top: 6px;">{{ $stats['internal'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Fason</div><div class="text-2xl font-bold" style="margin-top: 6px;">{{ $stats['outsourced'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Setup Gerektiren</div><div class="text-2xl font-bold" style="margin-top: 6px;">{{ $stats['requires_setup'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Pasif</div><div class="text-2xl font-bold" style="margin-top: 6px;">{{ $stats['passive'] }}</div></div></div>
</div>

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-body">
        @if(session('success'))
            <div class="pd-alert pd-alert-success" style="margin-bottom: 14px;">{{ session('success') }}</div>
        @endif

        <form method="GET" action="{{ route('admin.settings.print-settings.index') }}">
            <div class="pd-form-grid-3">
                <div>
                    <label class="text-sm font-medium">Arama</label>
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Baskı tipi veya tenant özel ad...">
                </div>
                <div>
                    <label class="text-sm font-medium">Durum</label>
                    <select name="status">
                        <option value="">Tümü</option>
                        <option value="active" @selected($filters['status'] === 'active')>Aktif</option>
                        <option value="passive" @selected($filters['status'] === 'passive')>Pasif</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">Üretim Modu</label>
                    <select name="production_mode">
                        <option value="">Tümü</option>
                        @foreach($productionModeOptions as $modeValue => $modeLabel)
                            <option value="{{ $modeValue }}" @selected($filters['production_mode'] === $modeValue)>{{ $modeLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">Setup Gerekli</label>
                    <select name="requires_setup">
                        <option value="">Tümü</option>
                        <option value="1" @selected($filters['requires_setup'] === '1')>Evet</option>
                        <option value="0" @selected($filters['requires_setup'] === '0')>Hayır</option>
                    </select>
                </div>
                <div style="display:flex; align-items:end; gap:8px;">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.settings.print-settings.index') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                </div>
            </div>
        </form>

        @if($missingCount > 0)
            <div class="pd-note" style="margin-top: 14px;">
                Bu tenant için henüz oluşturulmamış {{ $missingCount }} baskı ayarı var. “Eksik Baskı Ayarlarını Tamamla” butonu yalnız eksik kayıtları ekler, mevcut override’ları ezmez.
            </div>
        @endif
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Tenant Baskı Ayarları</h3>
        <p class="pd-card-subtitle">Bu ekran operasyon değil, ayar ekranıdır. Mevcut quote ve üretim akışlarını bu fazda değiştirmez.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Baskı Tipi</th>
                        <th>Tenant Adı</th>
                        <th>Durum</th>
                        <th>Üretim Modu</th>
                        <th>Grafik</th>
                        <th>Üretim</th>
                        <th>Setup / Ara Eleman</th>
                        <th>Varsayılan Fasoncu</th>
                        @if($canViewFinancialDefaults)
                            <th>Finansal Varsayılanlar</th>
                        @endif
                        <th class="text-right">Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($settings as $setting)
                        <tr>
                            <td>
                                <div class="font-medium">{{ $setting->standardPrintType?->safeName() ?? 'Baskı Tipi' }}</div>
                                <div class="text-sm text-gray-600">{{ $setting->standardPrintType?->safeCode() }}</div>
                            </td>
                            <td>
                                <div>{{ $tenant->name }}</div>
                                @if($setting->custom_name)
                                    <div class="text-sm text-gray-600">Özel Ad: {{ $setting->custom_name }}</div>
                                @else
                                    <div class="text-sm text-gray-600">Özel ad kullanılmıyor</div>
                                @endif
                            </td>
                            <td>
                                <span class="pd-badge pd-badge-{{ $setting->is_active ? 'green' : 'gray' }}">
                                    {{ $setting->is_active ? 'Aktif' : 'Pasif' }}
                                </span>
                            </td>
                            <td>{{ $setting->safeProductionModeLabel() }}</td>
                            <td>
                                <span class="pd-badge pd-badge-{{ $setting->effectiveRequiresGraphic() ? 'blue' : 'gray' }}">
                                    {{ $setting->effectiveRequiresGraphic() ? 'Gerekli' : 'Gerekmez' }}
                                </span>
                            </td>
                            <td>
                                <span class="pd-badge pd-badge-{{ $setting->effectiveRequiresProduction() ? 'green' : 'gray' }}">
                                    {{ $setting->effectiveRequiresProduction() ? 'Gerekli' : 'Gerekmez' }}
                                </span>
                            </td>
                            <td>
                                <div>
                                    <span class="pd-badge pd-badge-{{ $setting->effectiveRequiresSetup() ? 'amber' : 'gray' }}">
                                        {{ $setting->effectiveRequiresSetup() ? 'Gerekli' : 'Gerekmez' }}
                                    </span>
                                </div>
                                @if($setting->effectiveRequiresSetup() && $setting->effectiveSetupTypes())
                                    <div class="text-sm text-gray-600" style="margin-top: 6px;">
                                        {{ collect($setting->effectiveSetupTypes())->map(fn ($type) => \App\Models\StandardPrintType::setupTypeLabels()[$type] ?? $type)->implode(', ') }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                {{ $setting->defaultSubcontractorCompany?->short_name ?: $setting->defaultSubcontractorCompany?->legal_name ?: '-' }}
                            </td>
                            @if($canViewFinancialDefaults)
                                <td>
                                    <div>{{ $setting->default_currency ?: '-' }}</div>
                                    <div class="text-sm text-gray-600">
                                        Birim: {{ $setting->default_unit_price !== null ? number_format((float) $setting->default_unit_price, 2, ',', '.') : '-' }}
                                    </div>
                                    <div class="text-sm text-gray-600">
                                        Setup: {{ $setting->default_setup_cost !== null ? number_format((float) $setting->default_setup_cost, 2, ',', '.') : '-' }}
                                    </div>
                                </td>
                            @endif
                            <td class="text-right">
                                <a href="{{ route('admin.settings.print-settings.edit', $setting) }}" class="pd-btn pd-btn-primary pd-btn-sm">Düzenle</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewFinancialDefaults ? 10 : 9 }}" class="text-center">Tenant için baskı ayarı bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($settings->hasPages())
            <div style="margin-top: 14px;">{{ $settings->links() }}</div>
        @endif
    </div>
</div>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Baskı Ayarı Özeti</h3>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Eylemler</h4>
            <div class="pd-summary-list">
                <a href="{{ route('admin.settings.print-settings.index', ['status' => 'active']) }}" class="pd-summary-item">Aktif baskıları aç</a>
                <a href="{{ route('admin.settings.print-settings.index', ['production_mode' => \App\Models\StandardPrintType::MODE_OUTSOURCED]) }}" class="pd-summary-item">Fason baskıları aç</a>
                <form method="POST" action="{{ route('admin.settings.print-settings.sync') }}">
                    @csrf
                    <button type="submit" class="pd-summary-item" style="width:100%; text-align:left;">Eksik ayarları tamamla</button>
                </form>
            </div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Kural Notu</h4>
            <div class="pd-note">
                Varsayılan fiyat ve setup maliyeti finansal veri kabul edilir. Bu alanlar yalnız yetkili kullanıcıya gösterilir.
            </div>
        </div>
    </div>
</div>
@endsection
