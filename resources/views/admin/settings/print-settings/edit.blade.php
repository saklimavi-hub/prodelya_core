@extends('layouts.prodelya-admin')

@section('title', 'Baskı Ayarını Düzenle')
@section('page_title', $setting->displayName())
@section('page_subtitle', 'Standart baskı tipinin tenant içindeki operasyon ve varsayılan ayarlarını güncelleyin.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.settings.print-settings.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
</div>
@endsection

@section('content')
@if($errors->any())
    <div class="pd-card" style="margin-bottom: 14px;">
        <div class="pd-card-body">
            <div class="pd-alert pd-alert-warning">
                <strong>Kayıt kontrolü gerekiyor.</strong>
                <ul style="margin: 8px 0 0 18px;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('admin.settings.print-settings.update', $setting) }}">
    @csrf
    @method('PUT')

    <div class="pd-card" style="margin-bottom: 14px;">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Baskı Kimliği</h3>
            <p class="pd-card-subtitle">Standart baskı tipi sabittir; tenant bu kayıt üzerinde yalnız override yönetir.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-grid pd-grid-3">
                <div>
                    <label class="text-sm font-medium">Standart Baskı Tipi</label>
                    <input type="text" value="{{ $setting->standardPrintType?->safeName() }}" readonly>
                </div>
                <div>
                    <label class="text-sm font-medium">Kod</label>
                    <input type="text" value="{{ $setting->standardPrintType?->safeCode() }}" readonly>
                </div>
                <div>
                    <label style="display:flex; gap:10px; align-items:center; padding-top: 28px;">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $setting->is_active)) style="width:auto;">
                        <span>Aktif mi?</span>
                    </label>
                </div>
                <div class="pd-grid-span-2">
                    <label class="text-sm font-medium">Tenant Özel Adı</label>
                    <input type="text" name="custom_name" value="{{ old('custom_name', $setting->custom_name) }}" placeholder="Boş bırakırsanız standart ad kullanılır.">
                </div>
            </div>
        </div>
    </div>

    <div class="pd-card" style="margin-bottom: 14px;">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Operasyon Kuralları</h3>
            <p class="pd-card-subtitle">Grafik, üretim ve setup gerekliliğini tenant düzeyinde belirleyin.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-grid pd-grid-2">
                <div>
                    <label class="text-sm font-medium">Üretim Modu</label>
                    <select name="production_mode" required>
                        @foreach($productionModeOptions as $modeValue => $modeLabel)
                            <option value="{{ $modeValue }}" @selected(old('production_mode', $setting->production_mode) === $modeValue)>{{ $modeLabel }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="pd-grid pd-grid-3" style="margin-top: 14px;">
                <label style="display:flex; gap:10px; align-items:center; padding: 12px; border:1px solid var(--pd-line); border-radius:6px; background:#fbfcfe;">
                    <input type="hidden" name="requires_graphic" value="0">
                    <input type="checkbox" name="requires_graphic" value="1" @checked(old('requires_graphic', $setting->requires_graphic)) style="width:auto;">
                    <span>Grafik gerekli</span>
                </label>
                <label style="display:flex; gap:10px; align-items:center; padding: 12px; border:1px solid var(--pd-line); border-radius:6px; background:#fbfcfe;">
                    <input type="hidden" name="requires_production" value="0">
                    <input type="checkbox" name="requires_production" value="1" @checked(old('requires_production', $setting->requires_production)) style="width:auto;">
                    <span>Üretim gerekli</span>
                </label>
                <label style="display:flex; gap:10px; align-items:center; padding: 12px; border:1px solid var(--pd-line); border-radius:6px; background:#fbfcfe;">
                    <input type="hidden" name="requires_setup" value="0">
                    <input type="checkbox" id="requires_setup_checkbox" name="requires_setup" value="1" @checked(old('requires_setup', $setting->requires_setup)) style="width:auto;">
                    <span>Setup / hazırlık gerekli</span>
                </label>
            </div>
        </div>
    </div>

    <div class="pd-card" id="setup_types_card" style="margin-bottom: 14px;">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Setup Tipleri</h3>
            <p class="pd-card-subtitle">Bu baskı tipi için varsayılan ara eleman ihtiyaçlarını işaretleyin.</p>
        </div>
        <div class="pd-card-body">
            @php
                $selectedSetupTypes = collect(old('setup_types', $setting->setup_types ?? []))->filter()->all();
            @endphp
            <div class="pd-grid pd-grid-3">
                @foreach($setupTypeOptions as $setupTypeValue => $setupTypeLabel)
                    <label style="display:flex; gap:10px; align-items:center; padding: 12px; border:1px solid var(--pd-line); border-radius:6px; background:#fbfcfe;">
                        <input type="checkbox" name="setup_types[]" value="{{ $setupTypeValue }}" @checked(in_array($setupTypeValue, $selectedSetupTypes, true)) style="width:auto;">
                        <span>{{ $setupTypeLabel }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    <div class="pd-card" style="margin-bottom: 14px;">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Varsayılan Fasoncu</h3>
            <p class="pd-card-subtitle">V1’de operasyonel truth company kaydıdır. Finansal link current account üzerinden ayrıca çözülebilir.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-grid pd-grid-2">
                <div>
                    <label class="text-sm font-medium">Varsayılan Fasoncu</label>
                    <select name="default_subcontractor_company_id">
                        <option value="">Seçiniz</option>
                        @foreach($subcontractorOptions as $companyOption)
                            <option value="{{ $companyOption->id }}" @selected((string) old('default_subcontractor_company_id', $setting->default_subcontractor_company_id) === (string) $companyOption->id)>
                                {{ $companyOption->short_name ?: $companyOption->legal_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="pd-note">
                    Yalnız tenant içindeki fason şirket kayıtları listelenir. Global supplier bu ekranda fason default seçimi için kullanılmaz.
                </div>
            </div>
        </div>
    </div>

    @if($canViewFinancialDefaults)
        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Finansal Varsayılanlar</h3>
                <p class="pd-card-subtitle">Bu alanlar finansal veri kabul edilir ve yalnız yetkili kullanıcıya görünür.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-3">
                    <div>
                        <label class="text-sm font-medium">Varsayılan Para Birimi</label>
                        <select name="default_currency">
                            <option value="">Seçiniz</option>
                            @foreach($currencyOptions as $currencyValue => $currencyLabel)
                                <option value="{{ $currencyValue }}" @selected(old('default_currency', $setting->default_currency) === $currencyValue)>{{ $currencyLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium">Varsayılan Birim Baskı Fiyatı</label>
                        <input type="number" step="0.01" min="0" name="default_unit_price" value="{{ old('default_unit_price', $setting->default_unit_price) }}">
                    </div>
                    <div>
                        <label class="text-sm font-medium">Varsayılan Setup / Hazırlık Maliyeti</label>
                        <input type="number" step="0.01" min="0" name="default_setup_cost" value="{{ old('default_setup_cost', $setting->default_setup_cost) }}">
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pd-card" style="margin-bottom: 14px;">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Notlar</h3>
            <p class="pd-card-subtitle">Tenant özel kısa notlar ekleyebilirsiniz.</p>
        </div>
        <div class="pd-card-body">
            <textarea name="notes" rows="5">{{ old('notes', $setting->notes) }}</textarea>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <a href="{{ route('admin.settings.print-settings.index') }}" class="pd-btn pd-btn-light">İptal</a>
        <button type="submit" class="pd-btn pd-btn-primary">Baskı Ayarını Kaydet</button>
    </div>
</form>

<div class="pd-card" style="margin-top: 18px;">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Baskı Seçenekleri</h3>
        <p class="pd-card-subtitle">Bu baskı türüne bağlı seçenekleri, tek fiyat önerisini ve ara eleman varsayımlarını yönetin.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-card" style="margin-bottom: 14px; border:1px dashed var(--pd-line);">
            <div class="pd-card-body">
                <form method="POST" action="{{ route('admin.settings.print-settings.options.store', $setting) }}">
                    @csrf
                    <div class="pd-grid pd-grid-4">
                        <div>
                            <label class="text-sm font-medium">Seçenek Adı</label>
                            <input type="text" name="name" value="{{ old('name') }}" placeholder="Örn. Tek taraf UV baskı">
                        </div>
                        <div>
                            <label class="text-sm font-medium">Kod</label>
                            <input type="text" name="code" value="{{ old('code') }}" placeholder="uv-front">
                        </div>
                        <div>
                            <label class="text-sm font-medium">Sıra</label>
                            <input type="number" name="sort_order" value="{{ old('sort_order', 10) }}" min="0">
                        </div>
                        @if($canViewFinancialDefaults)
                            <div>
                                <label class="text-sm font-medium">Tek fiyat önerisi</label>
                                <input type="number" step="0.01" min="0" name="default_unit_price" value="{{ old('default_unit_price') }}">
                            </div>
                        @endif
                        <div class="pd-grid-span-2">
                            <label class="text-sm font-medium">Açıklama</label>
                            <input type="text" name="description" value="{{ old('description') }}" placeholder="Kısa not">
                        </div>
                        <div>
                            <label class="text-sm font-medium">Ara eleman tipi</label>
                            <select name="setup_type">
                                <option value="">Seçiniz</option>
                                @foreach($setupTypeOptions as $setupTypeValue => $setupTypeLabel)
                                    <option value="{{ $setupTypeValue }}" @selected(old('setup_type') === $setupTypeValue)>{{ $setupTypeLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Varsayılan durum</label>
                            <select name="setup_status_default">
                                <option value="">Seçiniz</option>
                                @foreach($setupStatusOptions as $statusOption)
                                    <option value="{{ $statusOption }}" @selected(old('setup_status_default') === $statusOption)>{{ $statusOption }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="pd-grid pd-grid-3" style="margin-top: 14px;">
                        <label style="display:flex; gap:10px; align-items:center; padding: 12px; border:1px solid var(--pd-line); border-radius:6px; background:#fbfcfe;">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true)) style="width:auto;">
                            <span>Aktif</span>
                        </label>
                        <label style="display:flex; gap:10px; align-items:center; padding: 12px; border:1px solid var(--pd-line); border-radius:6px; background:#fbfcfe;">
                            <input type="hidden" name="is_default" value="0">
                            <input type="checkbox" name="is_default" value="1" @checked(old('is_default', false)) style="width:auto;">
                            <span>Varsayılan seçenek</span>
                        </label>
                        <label style="display:flex; gap:10px; align-items:center; padding: 12px; border:1px solid var(--pd-line); border-radius:6px; background:#fbfcfe;">
                            <input type="hidden" name="requires_setup" value="0">
                            <input type="checkbox" name="requires_setup" value="1" @checked(old('requires_setup', false)) style="width:auto;">
                            <span>Ara eleman gerekir</span>
                        </label>
                    </div>

                    <div class="flex justify-end" style="margin-top: 14px;">
                        <button type="submit" class="pd-btn pd-btn-primary">Baskı Seçeneği Ekle</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Seçenek</th>
                        <th>Durum</th>
                        <th>Setup</th>
                        @if($canViewFinancialDefaults)
                            <th>Tek Fiyat Önerisi</th>
                        @endif
                        <th>Sıra</th>
                        <th class="text-right">Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($printOptions as $option)
                        <tr>
                            <td colspan="{{ $canViewFinancialDefaults ? 6 : 5 }}" style="padding: 0;">
                                <form method="POST" action="{{ route('admin.settings.print-settings.options.update', [$setting, $option]) }}" style="padding: 14px 16px;">
                                    @csrf
                                    @method('PUT')
                                    <div class="pd-grid pd-grid-4">
                                        <div>
                                            <label class="text-sm font-medium">Seçenek Adı</label>
                                            <input type="text" name="name" value="{{ old('name.' . $option->id, $option->name) }}">
                                        </div>
                                        <div>
                                            <label class="text-sm font-medium">Kod</label>
                                            <input type="text" name="code" value="{{ old('code.' . $option->id, $option->code) }}">
                                        </div>
                                        <div>
                                            <label class="text-sm font-medium">Sıra</label>
                                            <input type="number" name="sort_order" min="0" value="{{ old('sort_order.' . $option->id, $option->sort_order) }}">
                                        </div>
                                        @if($canViewFinancialDefaults)
                                            <div>
                                                <label class="text-sm font-medium">Tek fiyat önerisi</label>
                                                <input type="number" step="0.01" min="0" name="default_unit_price" value="{{ old('default_unit_price.' . $option->id, $option->default_unit_price) }}">
                                            </div>
                                        @endif
                                        <div class="pd-grid-span-2">
                                            <label class="text-sm font-medium">Açıklama</label>
                                            <input type="text" name="description" value="{{ old('description.' . $option->id, $option->description) }}">
                                        </div>
                                        <div>
                                            <label class="text-sm font-medium">Ara eleman tipi</label>
                                            <select name="setup_type">
                                                <option value="">Seçiniz</option>
                                                @foreach($setupTypeOptions as $setupTypeValue => $setupTypeLabel)
                                                    <option value="{{ $setupTypeValue }}" @selected(old('setup_type.' . $option->id, $option->setup_type) === $setupTypeValue)>{{ $setupTypeLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-sm font-medium">Varsayılan durum</label>
                                            <select name="setup_status_default">
                                                <option value="">Seçiniz</option>
                                                @foreach($setupStatusOptions as $statusOption)
                                                    <option value="{{ $statusOption }}" @selected(old('setup_status_default.' . $option->id, $option->setup_status_default) === $statusOption)>{{ $statusOption }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <div class="pd-grid pd-grid-3" style="margin-top: 14px;">
                                        <label style="display:flex; gap:10px; align-items:center; padding: 12px; border:1px solid var(--pd-line); border-radius:6px; background:#fbfcfe;">
                                            <input type="hidden" name="is_active" value="0">
                                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active.' . $option->id, $option->is_active)) style="width:auto;">
                                            <span>Aktif</span>
                                        </label>
                                        <label style="display:flex; gap:10px; align-items:center; padding: 12px; border:1px solid var(--pd-line); border-radius:6px; background:#fbfcfe;">
                                            <input type="hidden" name="is_default" value="0">
                                            <input type="checkbox" name="is_default" value="1" @checked(old('is_default.' . $option->id, $option->is_default)) style="width:auto;">
                                            <span>Varsayılan seçenek</span>
                                        </label>
                                        <label style="display:flex; gap:10px; align-items:center; padding: 12px; border:1px solid var(--pd-line); border-radius:6px; background:#fbfcfe;">
                                            <input type="hidden" name="requires_setup" value="0">
                                            <input type="checkbox" name="requires_setup" value="1" @checked(old('requires_setup.' . $option->id, $option->requires_setup)) style="width:auto;">
                                            <span>Ara eleman gerekir</span>
                                        </label>
                                    </div>

                                    <div class="flex justify-between items-center" style="margin-top: 14px;">
                                        <div class="text-sm text-gray-600">
                                            {{ $option->is_default ? 'Varsayılan seçenek' : 'Varsayılan değil' }}
                                        </div>
                                        <button type="submit" class="pd-btn pd-btn-primary pd-btn-sm">Güncelle</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewFinancialDefaults ? 6 : 5 }}" class="text-center">Bu baskı türü için seçenek bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Kural Özeti</h3>

        <div class="pd-summary-section">
            <div class="pd-summary-info">
                <div class="pd-summary-row"><span>Standart Tip</span><span class="font-medium">{{ $setting->standardPrintType?->safeName() }}</span></div>
                <div class="pd-summary-row"><span>Üretim Modu</span><span class="font-medium">{{ $setting->safeProductionModeLabel() }}</span></div>
                <div class="pd-summary-row"><span>Grafik</span><span class="font-medium">{{ $setting->effectiveRequiresGraphic() ? 'Gerekli' : 'Yok' }}</span></div>
                <div class="pd-summary-row"><span>Setup</span><span class="font-medium">{{ $setting->effectiveRequiresSetup() ? 'Gerekli' : 'Yok' }}</span></div>
            </div>
        </div>

        <div class="pd-note">
            Bu ekran teklif veya üretim akışını bu fazda doğrudan değiştirmez. Yalnız tenant varsayımlarını tanımlar.
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const setupCheckbox = document.getElementById('requires_setup_checkbox');
    const setupCard = document.getElementById('setup_types_card');

    if (!setupCheckbox || !setupCard) {
        return;
    }

    const toggleSetupCard = () => {
        setupCard.style.display = setupCheckbox.checked ? '' : 'none';
    };

    setupCheckbox.addEventListener('change', toggleSetupCard);
    toggleSetupCard();
});
</script>
@endpush
