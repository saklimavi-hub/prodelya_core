@extends('layouts.prodelya-admin')

@section('title', 'Paketim ve Kullanımım')
@section('page_title', 'Paketim ve Kullanımım')
@section('page_subtitle', 'Mevcut paketiniz, kullanım limitleriniz ve yükseltme yönlendirmeleri tek ekranda.')
@section('hide_side_summary', true)

@php
    $package = $overview['package_summary'];
    $subscription = $overview['subscription_summary'];
    $requests = $overview['package_requests'];
    $toneMap = [
        'green' => 'badge-green',
        'amber' => 'badge-amber',
        'red' => 'badge-red',
        'blue' => 'badge-blue',
        'gray' => 'badge-gray',
    ];
    $statusMap = [
        'ok' => ['label' => 'Normal', 'class' => 'badge-green'],
        'warning' => ['label' => 'Yaklaşıyor', 'class' => 'badge-amber'],
        'exceeded' => ['label' => 'Aşıldı', 'class' => 'badge-red'],
        'unlimited' => ['label' => 'Limitsiz', 'class' => 'badge-blue'],
    ];
@endphp

@section('content')
<style>
    .package-shell { font-family: Arial, Helvetica, sans-serif; }
    .package-grid { display: grid; gap: 16px; }
    .package-card,
    .package-mini-card,
    .package-progress-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .package-card { padding: 18px; }
    .package-mini-card { padding: 14px; }
    .package-progress-bar {
        width: 100%;
        height: 8px;
        border-radius: 999px;
        background: #e5e7eb;
        overflow: hidden;
    }
    .package-progress-bar > span { display: block; height: 100%; }
    .package-list-item {
        border: 1px solid #edf2f7;
        border-radius: 6px;
        background: #f8fafc;
        padding: 12px 14px;
    }
    .package-note {
        font-size: 12px;
        line-height: 1.45;
        color: #64748b;
    }
    @media (max-width: 1024px) {
        .package-layout { grid-template-columns: 1fr !important; }
    }
</style>

<div class="package-shell">
    @if(session('success'))
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    <div class="package-grid package-layout" style="grid-template-columns: minmax(0, 1.45fr) minmax(300px, 0.55fr);">
        <div class="space-y-6">
            <section class="package-card">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Mevcut Paketim</h2>
                        <p class="mt-1 text-sm text-gray-600">Abone Firma paketiniz, abonelik durumu ve temel erişim bilgileri.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="badge badge-blue">{{ $package['name'] }}</span>
                        <span class="badge {{ match($subscription['severity']) {'success' => 'badge-green', 'info' => 'badge-blue', 'warning' => 'badge-amber', 'danger' => 'badge-red', default => 'badge-gray'} }}">{{ $subscription['label'] }}</span>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="package-list-item">
                        <div class="package-note">Paket</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $package['name'] }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ $package['key'] ?: 'Kod yok' }}</div>
                    </div>
                    <div class="package-list-item">
                        <div class="package-note">Durum</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $subscription['label'] }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ $subscription['message'] }}</div>
                    </div>
                    <div class="package-list-item">
                        <div class="package-note">Aylık / Yıllık</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $package['monthly_price'] ?: 'Tanımlı değil' }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ $package['yearly_price'] ?: 'Yıllık fiyat yok' }}</div>
                    </div>
                    <div class="package-list-item">
                        <div class="package-note">Panel Adresi</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900 break-all">{{ $overview['tenant_summary']['panel_host'] ?: 'Tanımlı değil' }}</div>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-3">
                    <div class="package-list-item">
                        <div class="package-note">Trial Bitişi</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ optional($subscription['trial_ends_at'])->format('d.m.Y') ?: 'Yok' }}</div>
                    </div>
                    <div class="package-list-item">
                        <div class="package-note">Abonelik Bitişi</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ optional($subscription['package_ends_at'])->format('d.m.Y') ?: 'Yok' }}</div>
                    </div>
                    <div class="package-list-item">
                        <div class="package-note">Paket Durumu</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $package['status_label'] }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ $package['is_public'] ? 'Public paket' : 'Super Admin ataması olabilir' }}</div>
                    </div>
                </div>

                @if($package['warning'])
                    <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $package['warning'] }}</div>
                @endif

                @if($package['limit_summary'] !== [])
                    <div class="mt-4 rounded-md border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-sm font-medium text-gray-900">Paket özet limitleri</div>
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            @foreach($package['limit_summary'] as $limit)
                                <div class="package-list-item">
                                    <div class="package-note">{{ $limit['label'] }}</div>
                                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $limit['value'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            <section class="package-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-[15px] font-semibold text-gray-900">Kullanım Limitleri</h3>
                        <p class="mt-1 text-sm text-gray-600">Mevcut kullanım ve limit durumunuzu izleyin.</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach($overview['usage_snapshots'] as $item)
                        <div class="package-progress-card p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-sm font-medium text-gray-900">{{ $item['label'] }}</div>
                                <span class="badge {{ $statusMap[$item['status']]['class'] ?? 'badge-gray' }}">{{ $statusMap[$item['status']]['label'] ?? 'Bilinmiyor' }}</span>
                            </div>
                            <div class="mt-2 text-sm text-gray-700">
                                {{ number_format((int) $item['current'], 0, ',', '.') }}
                                /
                                {{ $item['limit'] === null ? 'Limitsiz' : number_format((int) $item['limit'], 0, ',', '.') }}
                            </div>
                            <div class="mt-3 package-progress-bar">
                                <span style="width: {{ $item['percentage'] ?? 100 }}%; background: {{ match($item['status']) {'ok' => '#16a34a', 'warning' => '#f59e0b', 'exceeded' => '#dc2626', default => '#2563eb'} }};"></span>
                            </div>
                            <div class="mt-2 package-note">
                                {{ $item['limit'] === null ? 'Bu alan limitsizdir.' : (($item['percentage'] ?? 0) . '% kullanıldı') }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="package-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-[15px] font-semibold text-gray-900">Aktif Modüller</h3>
                        <p class="mt-1 text-sm text-gray-600">Paket veya Super Admin erişimiyle şu anda kullanabildiğiniz modüller.</p>
                    </div>
                    <span class="badge badge-blue">{{ count($overview['active_modules']) }} aktif modül</span>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @forelse($overview['active_modules'] as $module)
                        <div class="package-list-item">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">{{ $module['label'] }}</div>
                                    <div class="mt-1 package-note">{{ $module['description'] ?: 'Bu modül Abone Firma operasyonunda kullanılabilir.' }}</div>
                                </div>
                                <span class="badge {{ $toneMap[$module['status_tone']] ?? 'badge-gray' }}">{{ $module['status_label'] }}</span>
                            </div>
                            @if($module['feature_summary']['items'] !== [])
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach($module['feature_summary']['items'] as $feature)
                                        <span class="badge badge-gray">{{ $feature }}</span>
                                    @endforeach
                                    @if($module['feature_summary']['extra_count'] > 0)
                                        <span class="badge badge-gray">+{{ $module['feature_summary']['extra_count'] }} özellik</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-6 text-sm text-gray-500 md:col-span-2">Aktif modül bulunamadı.</div>
                    @endforelse
                </div>
            </section>

            <section class="package-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-[15px] font-semibold text-gray-900">Yükseltilebilir Modüller</h3>
                        <p class="mt-1 text-sm text-gray-600">Kapalı veya planlı modüller için hangi yolun gerektiğini görün.</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach($overview['upgradeable_modules'] as $module)
                        <div class="package-list-item">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">{{ $module['label'] }}</div>
                                    <div class="mt-1 package-note">{{ $module['description'] ?: 'Bu modül mevcut pakette açık değildir.' }}</div>
                                </div>
                                <span class="badge {{ $toneMap[$module['status_tone']] ?? 'badge-gray' }}">{{ $module['status_label'] }}</span>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-3">
                                <span class="text-xs font-medium text-gray-700">{{ $module['unlock_label'] }}</span>
                                <button type="button" class="pd-btn pd-btn-light opacity-60 cursor-not-allowed" disabled>Sonraki faz</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="package-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-[15px] font-semibold text-gray-900">Paket Taleplerim</h3>
                        <p class="mt-1 text-sm text-gray-600">Gönderdiğiniz paket talepleri ve son durumları.</p>
                    </div>
                    @if($requests['route'])
                        <a href="{{ $requests['route'] }}" class="pd-btn pd-btn-light">Talep Detayına Git</a>
                    @endif
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-3 py-2">Talep Tarihi</th>
                                <th class="px-3 py-2">Mevcut Paket</th>
                                <th class="px-3 py-2">Talep Edilen Paket</th>
                                <th class="px-3 py-2">Durum</th>
                                <th class="px-3 py-2">Not</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse($requests['items'] as $item)
                                <tr>
                                    <td class="px-3 py-2">{{ optional($item->created_at)->format('d.m.Y H:i') }}</td>
                                    <td class="px-3 py-2">{{ $item->currentPackage?->name ?? ($item->current_package_key ?: '-') }}</td>
                                    <td class="px-3 py-2">{{ $item->requestedPackage?->name ?? ($item->requested_package_key ?: '-') }}</td>
                                    <td class="px-3 py-2"><span class="badge {{ match($item->status) {'new' => 'badge-amber', 'approved' => 'badge-blue', 'rejected' => 'badge-gray', 'completed' => 'badge-green', default => 'badge-gray'} }}">{{ $requests['status_labels'][$item->status] ?? $item->status }}</span></td>
                                    <td class="px-3 py-2 text-gray-600">{{ $item->request_note ?: 'Not yok' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-6 text-center text-gray-500">Henüz paket talebi oluşturulmadı.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="space-y-6 lg:sticky lg:top-6 lg:self-start">
            <section class="package-card">
                <h3 class="text-[15px] font-semibold text-gray-900">Paket Durumu</h3>
                <div class="mt-4 space-y-3">
                    <div class="package-list-item">
                        <div class="package-note">Açık Paket Talebi</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $requests['open_count'] }}</div>
                    </div>
                    <div class="package-list-item">
                        <div class="package-note">Kritik Uyarı</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ count($overview['warnings']) > 0 ? count($overview['warnings']) . ' uyarı' : 'Uyarı yok' }}</div>
                    </div>
                    <div class="package-list-item">
                        <div class="package-note">Ana İşlem</div>
                        <div class="mt-2 space-y-2">
                            @if(Route::has('admin.upgrade-requests.index'))
                                <a href="{{ route('admin.upgrade-requests.index', ['type' => 'package_upgrade']) }}#create-request" class="pd-btn pd-btn-primary w-full">Paket Yükseltme Talebi Oluştur</a>
                                <a href="{{ route('admin.upgrade-requests.index', ['type' => 'module_addon']) }}#create-request" class="pd-btn pd-btn-light w-full">Ek Modül Talep Et</a>
                                <a href="{{ route('admin.upgrade-requests.index', ['type' => 'limit_increase']) }}#create-request" class="pd-btn pd-btn-light w-full">Limit Artırma Talebi Oluştur</a>
                            @endif
                        </div>
                    </div>
                </div>
                @if($overview['warnings'] !== [])
                    <div class="mt-4 space-y-2">
                        @foreach($overview['warnings'] as $warning)
                            <div class="rounded-md border px-3 py-2 text-sm {{ match($warning['tone']) {'red' => 'border-red-200 bg-red-50 text-red-800', 'amber' => 'border-amber-200 bg-amber-50 text-amber-900', 'blue' => 'border-blue-100 bg-blue-50 text-blue-800', default => 'border-gray-200 bg-gray-50 text-gray-700'} }}">
                                {{ $warning['label'] }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="package-card">
                <h3 class="text-[15px] font-semibold text-gray-900">Yakında Gelecek Talepler</h3>
                <div class="mt-4 space-y-3">
                    @foreach($overview['upcoming_request_types'] as $item)
                        <div class="package-list-item flex items-center justify-between gap-3">
                            <span class="text-sm font-medium text-gray-900">{{ $item['label'] }}</span>
                            <span class="badge badge-gray">{{ $item['status'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    Bu alan bilgilendirme amaçlıdır. Ek modül, limit ve hizmet talepleri sonraki fazlarda açılacaktır.
                </div>
                @if(Route::has('admin.upgrade-requests.index'))
                    <div class="mt-4">
                        <a href="{{ route('admin.upgrade-requests.index') }}" class="pd-btn pd-btn-light w-full">Talep Merkezi</a>
                    </div>
                @endif
            </section>
        </aside>
    </div>
</div>
@endsection
