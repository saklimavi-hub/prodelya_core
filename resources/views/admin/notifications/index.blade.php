@extends('layouts.prodelya-admin')

@section('title', 'Bildirim Merkezi')
@section('page_title', 'Bildirim Merkezi')
@section('page_subtitle', 'Mail, WhatsApp, şablonlar ve bildirim geçmişini tek yerden yönetin.')
@section('hide_side_summary', true)

@section('page_actions')
    <div class="flex flex-wrap gap-2">
        @foreach($quickLinks as $link)
            <a href="{{ $link['route'] }}" class="pd-btn pd-btn-light">{{ $link['label'] }}</a>
        @endforeach
    </div>
@endsection

@php
    $statusBadgeMap = [
        'preview' => 'badge-blue',
        'sent' => 'badge-green',
        'failed' => 'badge-red',
        'skipped' => 'badge-gray',
        'link_created' => 'badge-amber',
        'pending' => 'badge-gray',
        'cancelled' => 'badge-gray',
    ];
@endphp

@section('content')
<style>
    .notification-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .notification-shell .pd-soft-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
</style>

<div class="notification-shell">
@if(session('success'))
    <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        {{ session('success') }}
    </div>
@endif

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <div class="xl:col-span-2 space-y-6">
        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">Acil Dikkat</h3>
                        <p class="mt-1 text-sm text-gray-500">Bugün müdahale gerektiren bildirim durumu ve ayar eksikleri burada görünür.</p>
                    </div>
                    <span class="badge {{ count($urgentItems) > 0 ? 'badge-red' : 'badge-green' }}">{{ count($urgentItems) > 0 ? count($urgentItems) . ' konu' : 'Sorun yok' }}</span>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse($urgentItems as $item)
                        <div class="rounded-lg border {{ $item['tone'] === 'red' ? 'border-red-200 bg-red-50' : ($item['tone'] === 'amber' ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-gray-50') }} px-4 py-4">
                            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">{{ $item['title'] }}</div>
                                    <p class="mt-1 text-sm text-gray-600">{{ $item['description'] }}</p>
                                </div>
                                <a href="{{ $item['action_route'] }}" class="inline-flex items-center justify-center rounded-md border border-transparent bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-800">
                                    {{ $item['action_label'] }}
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-4 text-sm text-green-800">
                            Bildirim tarafında acil dikkat gerektiren bir durum görünmüyor.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Bugün</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $todayLogCount }}</div>
                <div class="mt-1 text-sm text-gray-500">Oluşan bildirim kaydı</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Mail Gönderimi</div>
                <div class="mt-2">
                    <span class="badge {{ $smtpActive ? 'badge-green' : 'badge-gray' }}">{{ $smtpActive ? 'Aktif' : 'Pasif' }}</span>
                </div>
                <div class="mt-2 text-sm text-gray-500">Gerçek e-posta gönderimi için SMTP ayarı kullanılır.</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">WhatsApp</div>
                <div class="mt-2">
                    <span class="badge {{ $whatsappActive ? 'badge-green' : 'badge-gray' }}">{{ $whatsappActive ? 'Aktif' : 'Pasif' }}</span>
                </div>
                <div class="mt-2 text-sm text-gray-500">Bu özellik yalnız hazır mesaj ve link açar.</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Başarısız</div>
                <div class="mt-2 text-2xl font-semibold text-red-600">{{ $statusSummary['failed'] }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Atlanan</div>
                <div class="mt-2 text-2xl font-semibold text-gray-700">{{ $statusSummary['skipped'] }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-gray-500">Şablonlar</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $templateCount }}</div>
                <div class="mt-1 text-sm text-gray-500">Tenant ve sistem düzeyinde görünen toplam şablon</div>
            </div>
        </div>

        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">Son Bildirimler</h3>
                        <p class="mt-1 text-sm text-gray-500">Teknik detay yerine günlük operasyon takibini öne çıkaran sade görünüm.</p>
                    </div>
                    @if(Route::has('admin.notifications.logs.index'))
                        <a href="{{ route('admin.notifications.logs.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">Tümünü gör</a>
                    @endif
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Tarih</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Event</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Kanal</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Alici</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Durum</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @forelse($recentLogs as $log)
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ $log->created_at?->format('d.m.Y H:i') }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $eventLabels[$log->id] ?? $log->notification_key }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ $log->safeChannelLabel() }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ $log->recipient_name ?: ($log->recipient_email ?: ($log->recipient_phone ?: '—')) }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <span class="badge {{ $statusBadgeMap[$log->status] ?? 'badge-gray' }}">{{ $log->safeStatusLabel() }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">Henüz bildirim kaydı bulunmuyor.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <h3 class="text-lg font-medium text-gray-900">Durum Özeti</h3>
                <div class="mt-4 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-500">Gönderildi</span>
                        <span class="badge badge-green">{{ $statusSummary['sent'] }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-500">Önizleme</span>
                        <span class="badge badge-blue">{{ $statusSummary['preview'] }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-500">WhatsApp Linkleri</span>
                        <span class="badge badge-amber">{{ $statusSummary['link_created'] }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-500">Başarısız</span>
                        <span class="badge badge-red">{{ $statusSummary['failed'] }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-500">Atlandı</span>
                        <span class="badge badge-gray">{{ $statusSummary['skipped'] }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <h3 class="text-lg font-medium text-gray-900">Hızlı Bağlantılar</h3>
                <p class="mt-1 text-sm text-gray-500">En sık ihtiyaç duyulan filtre ve ayar ekranlarına kısayollar.</p>
                <div class="mt-4 space-y-3">
                    @forelse($quickLinks as $link)
                        <a href="{{ $link['route'] }}" class="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 text-sm font-medium text-gray-700 transition hover:border-blue-300 hover:text-blue-600">
                            <span>{{ $link['label'] }}</span>
                            <span aria-hidden="true">→</span>
                        </a>
                    @empty
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-500">
                            Bu tenant için açık bildirim ekranı bulunmuyor.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
</div>
@endsection
