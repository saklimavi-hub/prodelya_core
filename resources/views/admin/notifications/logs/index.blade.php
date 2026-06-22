@extends('layouts.prodelya-admin')

@section('title', 'Bildirim Gecmisi')
@section('page_title', 'Bildirim Gecmisi')
@section('page_subtitle', 'Son bildirimler, başarısızlar, WhatsApp linkleri ve mail önizlemelerini filtreleyin.')
@section('hide_side_summary', true)

@php
    $statusBadgeMap = [
        'pending' => 'badge-gray',
        'preview' => 'badge-blue',
        'sent' => 'badge-green',
        'failed' => 'badge-red',
        'skipped' => 'badge-gray',
        'link_created' => 'badge-amber',
        'cancelled' => 'badge-gray',
    ];
@endphp

@section('content')
<style>
    .notification-log-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .notification-log-shell .pd-soft-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
</style>

<div class="notification-log-shell">
<div class="mb-6 flex flex-wrap gap-3">
    <a href="{{ route('admin.notifications.logs.index') }}" class="inline-flex items-center justify-center rounded-full border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:border-gray-400 hover:text-gray-900">Son Bildirimler</a>
    <a href="{{ route('admin.notifications.logs.index', ['status' => 'failed']) }}" class="inline-flex items-center justify-center rounded-full border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">Başarısızlar</a>
    <a href="{{ route('admin.notifications.logs.index', ['channel' => 'whatsapp_link']) }}" class="inline-flex items-center justify-center rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-700 hover:bg-amber-100">WhatsApp Linkleri</a>
    <a href="{{ route('admin.notifications.logs.index', ['status' => 'preview', 'channel' => 'email']) }}" class="inline-flex items-center justify-center rounded-full border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">Mail Önizleme/Pending</a>
</div>

<div class="pd-soft-card">
    <div class="px-4 py-4 sm:px-5 sm:py-5">
        <form method="GET" action="{{ route('admin.notifications.logs.index') }}" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Event</label>
                <select name="event" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                    <option value="">Tum eventler</option>
                    @foreach($eventOptions as $event)
                        <option value="{{ $event['key'] }}" @selected($filters['event'] === $event['key'])>{{ $event['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Kanal</label>
                <select name="channel" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                    <option value="">Tum kanallar</option>
                    @foreach($channelOptions as $key => $label)
                        <option value="{{ $key }}" @selected($filters['channel'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Audience</label>
                <select name="audience" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                    <option value="">Tum audience</option>
                    @foreach($audienceOptions as $key => $label)
                        <option value="{{ $key }}" @selected($filters['audience'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Durum</label>
                <select name="status" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                    <option value="">Tum durumlar</option>
                    @foreach($statusOptions as $key => $label)
                        <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Alici</label>
                <input name="recipient" type="text" value="{{ $filters['recipient'] }}" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900" placeholder="isim / e-posta / telefon">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Kaynak Turu</label>
                <select name="source_type" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                    <option value="">Tum kaynaklar</option>
                    @foreach($sourceTypeOptions as $sourceType)
                        <option value="{{ $sourceType }}" @selected($filters['source_type'] === $sourceType)>{{ class_basename($sourceType) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Baslangic</label>
                <input name="date_from" type="date" value="{{ $filters['date_from'] }}" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Bitis</label>
                <input name="date_to" type="date" value="{{ $filters['date_to'] }}" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
            </div>
            <div class="md:col-span-2 xl:col-span-4 flex items-center gap-3">
                <button type="submit" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                    Filtrele
                </button>
                <a href="{{ route('admin.notifications.logs.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Temizle</a>
            </div>
        </form>
    </div>
</div>

<div class="pd-soft-card mt-6">
    <div class="px-4 py-4 sm:px-5 sm:py-5">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Tarih</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Event</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Kanal</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Audience</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Alici</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Konu</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Durum</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Kaynak</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Aksiyon</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($logs as $log)
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $log->created_at?->format('d.m.Y H:i') }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $eventLabels[$log->id] ?? $log->notification_key }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $log->safeChannelLabel() }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $log->safeAudienceLabel() }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $log->recipient_name ?: ($log->recipient_email ?: ($log->recipient_phone ?: '—')) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ \Illuminate\Support\Str::limit($log->safeDisplaySubject() ?: '—', 60) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <span class="badge {{ $statusBadgeMap[$log->status] ?? 'badge-gray' }}">{{ $log->safeStatusLabel() }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $log->related_type ? class_basename($log->related_type) : '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <a href="{{ route('admin.notifications.logs.show', $log) }}" class="text-blue-600 hover:text-blue-700">Ac</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-6 text-center text-sm text-gray-500">Secilen filtrelerle log bulunmuyor.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-5">
            {{ $logs->links() }}
        </div>
    </div>
</div>
</div>
@endsection
