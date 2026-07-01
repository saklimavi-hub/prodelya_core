@extends('layouts.prodelya-admin')

@section('title', 'Paket Talepleri')
@section('page_title', 'Paket Talepleri')
@section('page_subtitle', 'Paket yükseltme veya değişim taleplerinizi Super Admin onayına gönderin.')
@section('hide_side_summary', true)

@php
    $statusClasses = [
        'new' => 'badge-amber',
        'approved' => 'badge-blue',
        'rejected' => 'badge-gray',
        'completed' => 'badge-green',
    ];
@endphp

@section('content')
<div style="font-family: Arial, Helvetica, sans-serif;">
    @if(session('success'))
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[1.15fr,0.85fr]">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Paket Talebi Oluştur</h2>
                    <p class="mt-1 text-sm text-gray-600">Talebiniz manuel onayla değerlendirilir. Bu fazda ödeme ve otomatik uygulama yapılmaz.</p>
                </div>
                <span class="badge badge-blue">{{ $tenant->package?->name ?? ($tenant->package_key ?: 'Core') }}</span>
            </div>

            <form method="POST" action="{{ route('admin.package-requests.store') }}" class="mt-5 space-y-4">
                @csrf
                <div>
                    <label for="requested_package_key" class="block text-sm font-medium text-gray-700">Talep Edilen Paket</label>
                    <select id="requested_package_key" name="requested_package_key" class="mt-1 block w-full rounded-md border-gray-300">
                        <option value="">Paket seçin</option>
                        @foreach($packages as $package)
                            <option value="{{ $package->key }}" @selected(old('requested_package_key') === $package->key)>{{ $package->name }}</option>
                        @endforeach
                    </select>
                    @error('requested_package_key')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="request_note" class="block text-sm font-medium text-gray-700">Talep Notu</label>
                    <textarea id="request_note" name="request_note" rows="4" class="mt-1 block w-full rounded-md border-gray-300" placeholder="Beklenen kullanıcı artışı, limit ihtiyacı veya operasyon notu ekleyin.">{{ old('request_note') }}</textarea>
                    @error('request_note')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    Super Admin onayından sonra paket değişimi uygulanır. Access ve limit kararları mevcut paket servisleri üzerinden yeniden hesaplanır.
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="pd-btn pd-btn-primary">Paket Talebi Gönder</button>
                </div>
            </form>
        </section>

        <aside class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Talep Özeti</h3>
            <div class="mt-4 space-y-3">
                @foreach($summaryCards as $card)
                    <div class="rounded-md border border-gray-100 bg-gray-50 px-4 py-3">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $card['label'] }}</div>
                        <div class="mt-1 text-lg font-semibold text-gray-900">{{ $card['value'] }}</div>
                    </div>
                @endforeach
            </div>
        </aside>
    </div>

    <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <h3 class="text-lg font-semibold text-gray-900">Talep Geçmişi</h3>
        <p class="mt-1 text-sm text-gray-600">Açık ve tamamlanan tüm paket taleplerinizi burada izleyin.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-3 py-2">Tarih</th>
                        <th class="px-3 py-2">Mevcut Paket</th>
                        <th class="px-3 py-2">İstenen Paket</th>
                        <th class="px-3 py-2">Durum</th>
                        <th class="px-3 py-2">Super Admin Notu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($requests as $item)
                        <tr>
                            <td class="px-3 py-2">{{ optional($item->created_at)->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-2">{{ $item->currentPackage?->name ?? ($item->current_package_key ?: '-') }}</td>
                            <td class="px-3 py-2">{{ $item->requestedPackage?->name ?? $item->requested_package_key }}</td>
                            <td class="px-3 py-2"><span class="badge {{ $statusClasses[$item->status] ?? 'badge-gray' }}">{{ \App\Models\TenantPackageUpgradeRequest::statusOptions()[$item->status] ?? $item->status }}</span></td>
                            <td class="px-3 py-2 text-gray-600">{{ $item->admin_note ?: 'Henüz not yok' }}</td>
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
@endsection
