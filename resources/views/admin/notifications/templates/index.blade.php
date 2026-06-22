@extends('layouts.prodelya-admin')

@section('title', 'Bildirim Şablonları')
@section('page_title', 'Bildirim Şablonları')
@section('page_subtitle', 'Hazır şablonları gözden geçirin, eksikleri tamamlayın ve tenant dilinize göre düzenleyin.')
@section('hide_side_summary', true)

@section('page_actions')
    <div class="flex flex-wrap items-center gap-2">
        <form method="POST" action="{{ route('admin.notifications.templates.sync-defaults') }}">
            @csrf
            <button type="submit" class="pd-btn pd-btn-light">Varsayılanları Oluştur / Eksikleri Tamamla</button>
        </form>
        <a href="{{ route('admin.notifications.templates.create') }}" class="pd-btn pd-btn-primary">Yeni Şablon</a>
    </div>
@endsection

@section('content')
@php
    $categoryLabels = [
        'quote' => 'Teklif',
        'graphic' => 'Grafik',
        'procurement' => 'Tedarik',
        'production' => 'Üretim',
        'delivery' => 'Teslimat',
        'finance' => 'Finans',
        'tracking' => 'Takip',
    ];
    $eventOptionMap = collect($eventOptions)->keyBy('key');
@endphp

<style>
    .notification-template-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .notification-template-shell .pd-soft-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .notification-template-shell .pd-chip {
        display: inline-flex;
        align-items: center;
        border: 1px solid #e5e7eb;
        border-radius: 999px;
        background: #f8fafc;
        color: #475569;
        font-size: 12px;
        font-weight: 600;
        padding: 7px 12px;
    }
    .notification-template-shell .pd-muted {
        color: #64748b;
        font-size: 12px;
    }
</style>

<div class="notification-template-shell">
    @if(session('success'))
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="pd-soft-card mb-6">
        <div class="px-4 py-4 sm:px-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-[15px] font-semibold text-gray-900">Hazır şablonlarla başlayın.</h2>
                    <p class="mt-1 text-sm text-gray-600">Sistem temel süreçler için varsayılan şablonları hazırlayabilir. Eksik olanları tek tıkla oluşturup sonra kendi dilinize göre düzenleyebilirsiniz.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach($categoryLabels as $label)
                        <span class="pd-chip">{{ $label }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="pd-soft-card">
        <div class="px-4 py-4 sm:px-5">
            <form method="GET" action="{{ route('admin.notifications.templates.index') }}" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Olay</label>
                    <select name="event" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                        <option value="">Tüm olaylar</option>
                        @foreach($eventOptions as $event)
                            <option value="{{ $event['key'] }}" @selected($filters['event'] === $event['key'])>{{ $event['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Kanal</label>
                    <select name="channel" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                        <option value="">Tüm kanallar</option>
                        @foreach($channelOptions as $key => $label)
                            <option value="{{ $key }}" @selected($filters['channel'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Kime</label>
                    <select name="audience" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                        <option value="">Tüm hedef kitleler</option>
                        @foreach($audienceOptions as $key => $label)
                            <option value="{{ $key }}" @selected($filters['audience'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Durum</label>
                    <select name="active" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                        <option value="">Tüm durumlar</option>
                        <option value="active" @selected($filters['active'] === 'active')>Aktif</option>
                        <option value="passive" @selected($filters['active'] === 'passive')>Pasif</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Kaynak</label>
                    <select name="scope" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                        <option value="all" @selected($filters['scope'] === 'all')>Tümü</option>
                        <option value="tenant" @selected($filters['scope'] === 'tenant')>Tenant kayıtları</option>
                        <option value="system" @selected($filters['scope'] === 'system')>Sistem fallback</option>
                    </select>
                </div>
                <div class="md:col-span-2 xl:col-span-5 flex items-center gap-3">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.notifications.templates.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Temizle</a>
                </div>
            </form>
        </div>
    </div>

    <div class="pd-soft-card mt-6">
        <div class="px-4 py-4 sm:px-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Olay Grubu</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Olay Adı</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Kanal</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Kime</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Durum</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Kaynak</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @forelse($templates as $template)
                            @php
                                $eventMeta = $eventOptionMap->get($template->notification_key);
                                $sourceLabel = $template->isSystemTemplate()
                                    ? 'Varsayılan'
                                    : (($template->created_by || $template->updated_by) ? 'Tenant düzenledi' : 'Varsayılan');
                                $sourceBadge = $sourceLabel === 'Tenant düzenledi' ? 'badge-blue' : 'badge-gray';
                            @endphp
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ $categoryLabels[data_get($eventMeta, 'category')] ?? 'Diğer' }}</td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-semibold text-gray-900">{{ $eventLabels[$template->id] ?? $template->notification_key }}</div>
                                    <div class="pd-muted mt-1">{{ $template->notification_key }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ $template->safeChannelLabel() === 'WhatsApp Link' ? 'WhatsApp Hazır Mesaj' : $template->safeChannelLabel() }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ $template->safeAudienceLabel() }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    <span class="badge {{ $template->is_active ? 'badge-green' : 'badge-gray' }}">{{ $template->is_active ? 'Aktif' : 'Pasif' }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    <span class="badge {{ $sourceBadge }}">{{ $sourceLabel }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    @if(!$template->isSystemTemplate())
                                        <a href="{{ route('admin.notifications.templates.edit', $template) }}" class="text-blue-600 hover:text-blue-700">Düzenle</a>
                                    @else
                                        <a href="{{ route('admin.notifications.templates.create', ['event' => $template->notification_key, 'channel' => $template->channel, 'audience' => $template->audience_type]) }}" class="text-blue-600 hover:text-blue-700">Tenant kopyası oluştur</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">Şablon bulunmuyor.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-5">
                {{ $templates->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
