@extends('layouts.prodelya-admin')

@section('title', 'Talep Merkezi')
@section('page_title', 'Talep Merkezi')
@section('page_subtitle', 'Paket, modül, özellik, limit ve hizmet taleplerinizi güvenli şekilde oluşturun.')
@section('hide_side_summary', true)

@php
    $tenantSummary = $overview['tenant_summary'];
    $selectedType = $overview['selected_type'];
    $options = $overview['form_options'][$selectedType] ?? [];
    $statusClasses = [
        'pending' => 'badge-amber',
        'in_review' => 'badge-blue',
        'approved' => 'badge-green',
        'rejected' => 'badge-gray',
        'applied' => 'badge-green',
        'cancelled' => 'badge-gray',
    ];
@endphp

@section('content')
<style>
    .upgrade-shell { font-family: Arial, Helvetica, sans-serif; }
    .upgrade-grid { display: grid; gap: 16px; }
    .upgrade-card,
    .upgrade-compact-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .upgrade-card { padding: 18px; }
    .upgrade-compact-card { padding: 14px; }
    .upgrade-item {
        border: 1px solid #edf2f7;
        border-radius: 6px;
        background: #f8fafc;
        padding: 12px 14px;
    }
    .upgrade-mini-text {
        font-size: 12px;
        line-height: 1.45;
        color: #64748b;
    }
    @media (max-width: 1024px) {
        .upgrade-layout { grid-template-columns: 1fr !important; }
    }
</style>

<div class="upgrade-shell">
    @if(session('success'))
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    <div class="upgrade-grid upgrade-layout" style="grid-template-columns: minmax(0, 1.35fr) minmax(300px, 0.65fr);">
        <div class="space-y-6">
            <section class="upgrade-card">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Talep Merkezi</h2>
                        <p class="mt-1 text-sm text-gray-600">Paket yükseltme, modül, özellik, limit ve hizmet taleplerinizi buradan yönetin.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="badge badge-blue">{{ $tenantSummary['package_name'] }}</span>
                        <span class="badge {{ match($tenantSummary['subscription']['severity']) {'success' => 'badge-green', 'info' => 'badge-blue', 'warning' => 'badge-amber', 'danger' => 'badge-red', default => 'badge-gray'} }}">{{ $tenantSummary['subscription']['label'] }}</span>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-4">
                    <div class="upgrade-item">
                        <div class="upgrade-mini-text">Mevcut Paket</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantSummary['package_name'] }}</div>
                    </div>
                    <div class="upgrade-item">
                        <div class="upgrade-mini-text">Kullanım Uyarısı</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantSummary['usage_warning_count'] }}</div>
                    </div>
                    <div class="upgrade-item">
                        <div class="upgrade-mini-text">Açık Talep</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantSummary['open_request_count'] }}</div>
                    </div>
                    <div class="upgrade-item">
                        <div class="upgrade-mini-text">Son Talep</div>
                        <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantSummary['latest_request'] ? $tenantSummary['latest_request']->statusLabel() : 'Henüz yok' }}</div>
                    </div>
                </div>
            </section>

            <section id="create-request" class="upgrade-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-[15px] font-semibold text-gray-900">Yeni Talep Oluştur</h3>
                        <p class="mt-1 text-sm text-gray-600">Talep tipini seçin, gerekli alanları doldurun ve inceleme kuyruğuna gönderin.</p>
                    </div>
                </div>

                <form method="GET" action="{{ route('admin.upgrade-requests.index') }}" class="mt-4">
                    <label for="type" class="block text-sm font-medium text-gray-700">Talep Tipi</label>
                    <select id="type" name="type" onchange="this.form.submit()" class="mt-1 block w-full rounded-md border-gray-300">
                        @foreach($overview['request_type_options'] as $typeOption)
                            <option value="{{ $typeOption['value'] }}" @selected($selectedType === $typeOption['value'])>{{ $typeOption['label'] }}</option>
                        @endforeach
                    </select>
                </form>

                <form method="POST" action="{{ route('admin.upgrade-requests.store') }}" class="mt-5 space-y-4">
                    @csrf
                    <input type="hidden" name="request_type" value="{{ $selectedType }}">

                    @if($selectedType === \App\Models\TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE)
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Mevcut Paket</label>
                                <input type="text" value="{{ $options['current_package'] ?? '-' }}" class="mt-1 block w-full rounded-md border-gray-300 bg-gray-50" readonly>
                            </div>
                            <div>
                                <label for="requested_package_key" class="block text-sm font-medium text-gray-700">Talep Edilen Paket</label>
                                <select id="requested_package_key" name="requested_package_key" class="mt-1 block w-full rounded-md border-gray-300">
                                    <option value="">Paket seçin</option>
                                    @foreach($options['packages'] ?? [] as $item)
                                        <option value="{{ $item['value'] }}" @selected(old('requested_package_key') === $item['value'])>{{ $item['label'] }} - {{ $item['description'] }}</option>
                                    @endforeach
                                </select>
                                @error('requested_package_key')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @elseif($selectedType === \App\Models\TenantUpgradeRequest::TYPE_MODULE_ADDON)
                        <div>
                            <label for="requested_module_key" class="block text-sm font-medium text-gray-700">Talep Edilen Modül</label>
                            <select id="requested_module_key" name="requested_module_key" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">Modül seçin</option>
                                @foreach($options['modules'] ?? [] as $item)
                                    <option value="{{ $item['value'] }}" @selected(old('requested_module_key') === $item['value'])>{{ $item['label'] }}</option>
                                @endforeach
                            </select>
                            @error('requested_module_key')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                            {{ collect($options['modules'] ?? [])->firstWhere('value', old('requested_module_key'))['description'] ?? 'Seçtiğiniz modül paketinize ek erişim olarak talep edilir.' }}
                        </div>
                    @elseif($selectedType === \App\Models\TenantUpgradeRequest::TYPE_FEATURE_ADDON)
                        <div>
                            <label for="requested_feature_key" class="block text-sm font-medium text-gray-700">Talep Edilen Özellik</label>
                            <select id="requested_feature_key" name="requested_feature_key" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">Özellik seçin</option>
                                @foreach($options['features'] ?? [] as $item)
                                    <option value="{{ $item['value'] }}" @selected(old('requested_feature_key') === $item['value'])>{{ $item['label'] }} - {{ $item['module_label'] }}</option>
                                @endforeach
                            </select>
                            @error('requested_feature_key')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @elseif($selectedType === \App\Models\TenantUpgradeRequest::TYPE_LIMIT_INCREASE)
                        <div class="grid gap-4 md:grid-cols-3">
                            <div>
                                <label for="requested_limit_key" class="block text-sm font-medium text-gray-700">Limit Türü</label>
                                <select id="requested_limit_key" name="requested_limit_key" class="mt-1 block w-full rounded-md border-gray-300">
                                    <option value="">Limit seçin</option>
                                    @foreach($options['limits'] ?? [] as $item)
                                        <option value="{{ $item['value'] }}" @selected(old('requested_limit_key') === $item['value'])>{{ $item['label'] }}</option>
                                    @endforeach
                                </select>
                                @error('requested_limit_key')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Mevcut Limit</label>
                                @php $selectedLimit = collect($options['limits'] ?? [])->firstWhere('value', old('requested_limit_key')); @endphp
                                <input type="text" value="{{ $selectedLimit ? number_format((int) $selectedLimit['current_limit'], 0, ',', '.') : '-' }}" class="mt-1 block w-full rounded-md border-gray-300 bg-gray-50" readonly>
                            </div>
                            <div>
                                <label for="requested_limit_value" class="block text-sm font-medium text-gray-700">Talep Edilen Yeni Limit</label>
                                <input id="requested_limit_value" name="requested_limit_value" type="number" min="1" value="{{ old('requested_limit_value') }}" class="mt-1 block w-full rounded-md border-gray-300">
                                @error('requested_limit_value')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @elseif($selectedType === \App\Models\TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS)
                        <div>
                            <label for="requested_supplier_id" class="block text-sm font-medium text-gray-700">Talep Edilen Tedarikçi</label>
                            <select id="requested_supplier_id" name="requested_supplier_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">Tedarikçi seçin</option>
                                @foreach($options['suppliers'] ?? [] as $item)
                                    <option value="{{ $item['value'] }}" @selected((string) old('requested_supplier_id') === (string) $item['value'])>{{ $item['label'] }} - {{ $item['code'] }}</option>
                                @endforeach
                            </select>
                            @error('requested_supplier_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                            Zaten aktif tedarikçi erişimleri bu listede tekrar gösterilmez.
                        </div>
                    @elseif($selectedType === \App\Models\TenantUpgradeRequest::TYPE_SERVICE_REQUEST)
                        <div>
                            <label for="requested_service_key" class="block text-sm font-medium text-gray-700">Hizmet Türü</label>
                            <select id="requested_service_key" name="requested_service_key" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">Hizmet seçin</option>
                                @foreach($options['services'] ?? [] as $item)
                                    <option value="{{ $item['value'] }}" @selected(old('requested_service_key') === $item['value'])>{{ $item['label'] }}</option>
                                @endforeach
                            </select>
                            @error('requested_service_key')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    <div>
                        <label for="requested_note" class="block text-sm font-medium text-gray-700">Not</label>
                        <textarea id="requested_note" name="requested_note" rows="4" class="mt-1 block w-full rounded-md border-gray-300" placeholder="İhtiyacınızı veya operasyon notunuzu kısaca yazın.">{{ old('requested_note') }}</textarea>
                        @error('requested_note')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        Bu fazda talepler yalnız kayıt altına alınır. Paket, modül, özellik, limit veya tedarikçi erişimi otomatik uygulanmaz.
                    </div>

                    <div class="flex flex-wrap justify-end gap-3">
                        @if($overview['old_package_request_route'])
                            <a href="{{ $overview['old_package_request_route'] }}" class="pd-btn pd-btn-light">Eski Paket Talep Akışı</a>
                        @endif
                        <button type="submit" class="pd-btn pd-btn-primary">Talebi Gönder</button>
                    </div>
                </form>
            </section>

            <section class="upgrade-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-[15px] font-semibold text-gray-900">Taleplerim</h3>
                        <p class="mt-1 text-sm text-gray-600">Yalnız size ait talepler burada görünür.</p>
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-gray-600">
                            <tr>
                                <th class="px-3 py-2">Talep Tarihi</th>
                                <th class="px-3 py-2">Talep Tipi</th>
                                <th class="px-3 py-2">Talep Özeti</th>
                                <th class="px-3 py-2">Durum</th>
                                <th class="px-3 py-2">Son İşlem</th>
                                <th class="px-3 py-2">Not</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse($overview['requests'] as $item)
                                <tr>
                                    <td class="px-3 py-2">{{ optional($item->created_at)->format('d.m.Y H:i') }}</td>
                                    <td class="px-3 py-2">{{ $item->requestTypeLabel() }}</td>
                                    <td class="px-3 py-2 text-gray-700">
                                        @if($item->requested_package_key)
                                            Paket: {{ $item->requested_package_key }}
                                        @elseif($item->requested_module_key)
                                            Modül: {{ $item->requested_module_key }}
                                        @elseif($item->requested_feature_key)
                                            Özellik: {{ $item->requested_feature_key }}
                                        @elseif($item->requested_limit_key)
                                            Limit: {{ $item->requested_limit_key }} → {{ $item->requested_limit_value }}
                                        @elseif($item->requested_supplier_key)
                                            Tedarikçi: {{ $item->requested_supplier_key }}
                                        @elseif($item->requested_service_key)
                                            Hizmet: {{ $item->requested_service_key }}
                                        @else
                                            Genel talep
                                        @endif
                                    </td>
                                    <td class="px-3 py-2"><span class="badge {{ $statusClasses[$item->status] ?? 'badge-gray' }}">{{ $overview['status_labels'][$item->status] ?? $item->status }}</span></td>
                                    <td class="px-3 py-2">{{ optional($item->reviewed_at ?? $item->applied_at ?? $item->updated_at)->format('d.m.Y H:i') ?: '-' }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $item->requested_note ?: 'Not yok' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-3 py-6 text-center text-gray-500">Henüz talep oluşturulmadı.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="space-y-6 lg:sticky lg:top-6 lg:self-start">
            <section class="upgrade-card">
                <h3 class="text-[15px] font-semibold text-gray-900">Hızlı Yönlendirme</h3>
                <div class="mt-4 space-y-3">
                    <a href="{{ route('admin.upgrade-requests.index', ['type' => 'package_upgrade']) }}#create-request" class="pd-btn pd-btn-primary w-full">Paket Yükseltme Talebi Oluştur</a>
                    <a href="{{ route('admin.upgrade-requests.index', ['type' => 'limit_increase']) }}#create-request" class="pd-btn pd-btn-light w-full">Limit Artırma Talebi Oluştur</a>
                    <a href="{{ route('admin.upgrade-requests.index', ['type' => 'module_addon']) }}#create-request" class="pd-btn pd-btn-light w-full">Ek Modül Talep Et</a>
                </div>
            </section>

            <section class="upgrade-card">
                <h3 class="text-[15px] font-semibold text-gray-900">Bilmeniz Gerekenler</h3>
                <div class="mt-4 space-y-3">
                    <div class="upgrade-item">
                        <div class="text-sm font-medium text-gray-900">Açık duplicate talepler engellenir</div>
                        <div class="mt-1 upgrade-mini-text">Aynı hedef için bekleyen, incelemede veya onaylı talep varken yenisi açılamaz.</div>
                    </div>
                    <div class="upgrade-item">
                        <div class="text-sm font-medium text-gray-900">Talep kaydı oluşturulur</div>
                        <div class="mt-1 upgrade-mini-text">Bu ekrandan paket, modül, limit veya tedarikçi erişimi doğrudan uygulanmaz.</div>
                    </div>
                    @if($overview['old_package_request_route'])
                        <div class="upgrade-item">
                            <div class="text-sm font-medium text-gray-900">Eski paket akışı korunuyor</div>
                            <div class="mt-1 upgrade-mini-text">İsterseniz mevcut package-only akışını da kullanabilirsiniz.</div>
                        </div>
                    @endif
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection
