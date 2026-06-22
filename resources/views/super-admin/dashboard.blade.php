@extends('layouts.prodelya-admin')

@section('title', 'Super Admin Paneli')

@section('content')
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
    <!-- Toplam Tenant -->
    <div class="card p-6">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-blue-100 rounded-lg p-3">
                <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
            </div>
            <div class="ml-5 w-0 flex-1">
                <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Toplam Tenant</dt>
                    <dd class="text-lg font-medium text-gray-900">{{ $totalTenants }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Aktif Tenant -->
    <div class="card p-6">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-green-100 rounded-lg p-3">
                <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div class="ml-5 w-0 flex-1">
                <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Aktif Tenant</dt>
                    <dd class="text-lg font-medium text-gray-900">{{ $activeTenants }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Deneme Tenant -->
    <div class="card p-6">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-amber-100 rounded-lg p-3">
                <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div class="ml-5 w-0 flex-1">
                <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Deneme Tenant</dt>
                    <dd class="text-lg font-medium text-gray-900">{{ $trialTenants }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Modül Uyarıları -->
    <div class="card p-6">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-red-100 rounded-lg p-3">
                <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
            </div>
            <div class="ml-5 w-0 flex-1">
                <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Modül Uyarıları</dt>
                    <dd class="text-lg font-medium text-gray-900">{{ $moduleWarnings }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- Recent Tenants -->
<div class="mt-8">
    <div class="card">
        <div class="px-4 py-5 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg leading-6 font-medium text-gray-900">Son Eklenen Tenantlar</h3>
                <a href="{{ route('admin.super.tenants.index') }}" class="text-sm text-blue-600 hover:text-blue-500">
                    Tümünü Gör →
                </a>
            </div>
            <div class="overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Firma</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durum</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Oluşturulma</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Modüller</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <!-- TODO: Implement real data -->
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Demo Şirketi</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="badge badge-green">Aktif</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Bugün</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">4 Aktif</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- System Health -->
<div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="card">
        <div class="px-4 py-5 sm:p-6">
            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Sistem Sağlığı</h3>
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-500">Veritabanı Bağlantısı</span>
                    <span class="badge badge-green">Sağlıklı</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-500">Cache Durumu</span>
                    <span class="badge badge-green">Aktif</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-500">Queue Sistemi</span>
                    <span class="badge badge-green">Çalışıyor</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-500">Disk Alanı</span>
                    <span class="badge badge-green">Yeterli</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="px-4 py-5 sm:p-6">
            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Son Audit Loglar</h3>
            <div class="space-y-2">
                <!-- TODO: Implement real audit log data -->
                <div class="text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Sistem başlatıldı</span>
                        <span class="text-gray-400 text-xs">şimdi</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('side_summary')
<div class="space-y-4">
    <!-- Quick Actions -->
    <div>
        <h3 class="text-sm font-medium text-gray-900 mb-3">Hızlı Eylemler</h3>
        <div class="space-y-2">
            <a href="{{ route('admin.super.tenants.index') }}" class="block w-full text-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                Tenant Yönetimi
            </a>
            <a href="{{ route('admin.super.modules') }}" class="block w-full text-center px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                Modül Yönetimi
            </a>
            <a href="{{ route('admin.super.settings') }}" class="block w-full text-center px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700">
                Sistem Ayarları
            </a>
        </div>
    </div>

    <!-- System Statistics -->
    <div>
        <h3 class="text-sm font-medium text-gray-900 mb-3">Sistem İstatistikleri</h3>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-500">Toplam Kullanıcı</span>
                <span class="font-medium">1</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Aktif Modüller</span>
                <span class="font-medium">13</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Toplam Sipariş</span>
                <span class="font-medium">0</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Sistem Sürümü</span>
                <span class="font-medium">v1.0.0</span>
            </div>
        </div>
    </div>

    <!-- Package Summary -->
    <div>
        <h3 class="text-sm font-medium text-gray-900 mb-3">Paket Özeti</h3>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-500">Core Paket</span>
                <span class="font-medium">{{ $totalTenants }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Profesyonel</span>
                <span class="font-medium">0</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Enterprise</span>
                <span class="font-medium">0</span>
            </div>
        </div>
    </div>
</div>
@endsection
