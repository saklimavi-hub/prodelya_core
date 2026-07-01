@extends('layouts.prodelya-admin')

@section('title', 'Modül ve Özellik Kataloğu')
@section('page_title', 'Modül ve Özellik Kataloğu')
@section('page_subtitle', 'Paket erişimi, tenant override ve menü/route etkisini aynı ekranda görün.')

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-body">
            <div class="pd-mini-kpi-strip">
                <div class="pd-mini-link-card"><div class="pd-mini-link-title">Core Modüller</div><div class="pd-mini-link-copy">{{ $stats['core'] }}</div></div>
                <div class="pd-mini-link-card"><div class="pd-mini-link-title">Opsiyonel Aktif</div><div class="pd-mini-link-copy">{{ $stats['active_optional'] }}</div></div>
                <div class="pd-mini-link-card"><div class="pd-mini-link-title">Planlanan / Pasif</div><div class="pd-mini-link-copy">{{ $stats['planned_passive'] }}</div></div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Karar Kuralları</h3>
                <p class="pd-section-subtitle">Tenant menüsü, route guard ve paket erişimi bu kurallarla birlikte değerlendirilir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-mini-grid">
                <div class="pd-mini-link-card"><div class="pd-mini-link-title">Core</div><div class="pd-mini-link-copy">Aktif ve deneme Abone Firmalarda varsayılan açık.</div></div>
                <div class="pd-mini-link-card"><div class="pd-mini-link-title">Opsiyonel</div><div class="pd-mini-link-copy">Paket veya tenant override ile görünür.</div></div>
                <div class="pd-mini-link-card"><div class="pd-mini-link-title">Planned / Pasif</div><div class="pd-mini-link-copy">Menü ve route erişimi üretmez.</div></div>
            </div>
        </div>
    </section>

    @php
        $groupLabels = [
            'core' => 'Core / Zorunlu Modüller',
            'operations' => 'Operasyon Modülleri',
            'product_data' => 'Product Hub / Katalog Modülleri',
            'optional' => 'Ek Opsiyonel Modüller',
            'planned_passive' => 'Planlanan / Pasif Modüller',
        ];
    @endphp

    @foreach($moduleGroups as $groupKey => $modules)
        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">{{ $groupLabels[$groupKey] ?? $groupKey }}</h3>
                    <p class="pd-section-subtitle">Paket kapsami, override uygunluğu ve menü etkisi tek listede görünür.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr>
                                <th>Modül</th>
                                <th>Durum</th>
                                <th>Paketlerde</th>
                                <th>Override</th>
                                <th>Menü / Route Etkisi</th>
                                <th>Açıklama</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($modules as $module)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $module['label'] }}</div>
                                        <div class="text-sm text-gray-500">{{ $module['key'] }}</div>
                                    </td>
                                    <td>
                                        <span class="pd-badge {{ $module['is_core'] ? 'pd-badge-green' : (in_array($module['status'], ['planned', 'passive'], true) ? 'pd-badge-amber' : 'pd-badge-blue') }}">
                                            {{ $module['is_core'] ? 'Core' : (match($module['status']) {'active' => 'Opsiyonel Aktif', 'planned' => 'Planlandı', 'passive' => 'Pasif', default => ucfirst($module['status'])}) }}
                                        </span>
                                    </td>
                                    <td>{{ $module['package_count'] }} paket</td>
                                    <td>{{ $module['override_allowed'] ? 'Yapılabilir' : 'Kapalı' }}</td>
                                    <td>{{ $module['menu_effect'] }}</td>
                                    <td>{{ $module['description'] ?: 'Veri yok' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-sm text-gray-500">Bu grupta modül görünmüyor.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endforeach

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Özellik Kataloğu</h3>
                <p class="pd-section-subtitle">Müşteri, entegrasyon ve raporlama odaklı alt yeteneklerin görünümü.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-grid pd-grid-3">
                @foreach($featureGroups as $groupKey => $features)
                    <div class="pd-card pd-card-soft">
                        <div class="pd-card-body">
                            <div class="font-medium">
                                {{ match($groupKey) {'customer' => 'Müşteri Yüzeyleri', 'integrations' => 'Entegrasyonlar', default => 'Raporlama ve Bildirim'} }}
                            </div>
                            <div class="text-sm text-gray-600 mt-2">{{ count($features) }} özellik</div>
                            <div class="flex flex-wrap gap-2 mt-3">
                                @foreach($features as $feature)
                                    <span class="pd-badge {{ in_array($feature['status'], ['core', 'active'], true) ? 'pd-badge-blue' : 'pd-badge-amber' }}">
                                        {{ $feature['label'] }}
                                    </span>
                                @endforeach
                                @if(count($features) === 0)
                                    <span class="text-sm text-gray-500">Veri yok</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</div>
@endsection
