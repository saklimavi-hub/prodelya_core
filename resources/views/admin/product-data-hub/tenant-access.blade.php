@extends('layouts.prodelya-admin')

@section('title', 'Tenant Tedarikçi Erişimi')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Tenant Tedarikçi Erişimi</h1>
        <p class="pd-muted mt-1">Tenant'ların tedarikçilere erişimini yönetin.</p>
    </div>
    <div>
        <button class="pd-btn pd-btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Yeni Erişim
        </button>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-body">
        <div class="overflow-x-auto">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tedarikçi</th>
                        <th>Erişim Seviyesi</th>
                        <th>Erişim Tarihi</th>
                        <th>Veren</th>
                        <th>İzinler</th>
                        <th>Durum</th>
                        <th>Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($accessList as $access)
                    <tr>
                        <td>
                            <div class="font-medium">{{ $access['supplier'] }}</div>
                        </td>
                        <td>
                            <span class="pd-badge pd-badge-{{ $access['access_level'] === 'admin' ? 'red' : ($access['access_level'] === 'write' ? 'yellow' : 'green') }}">
                                {{ $access['access_level'] === 'admin' ? 'Yönetici' : ($access['access_level'] === 'write' ? 'Yazma' : 'Okuma') }}
                            </span>
                        </td>
                        <td>
                            <span class="text-sm text-gray-600">
                                {{ $access['access_granted_at'] ? $access['access_granted_at']->format('d.m.Y H:i') : '-' }}
                            </span>
                        </td>
                        <td>
                            <span class="text-sm text-gray-600">{{ $access['granted_by'] ?: '-' }}</span>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                @foreach($access['permissions'] as $permission)
                                <span class="pd-badge pd-badge-sm pd-badge-blue">{{ $permission }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td>
                            <span class="pd-badge pd-badge-{{ $access['status'] === 'active' ? 'green' : 'yellow' }}">
                                {{ $access['status'] === 'active' ? 'Aktif' : 'Bekliyor' }}
                            </span>
                        </td>
                        <td>
                            <div class="flex items-center space-x-2">
                                @if($access['status'] === 'pending')
                                    <button class="pd-btn pd-btn-sm pd-btn-primary">Onayla</button>
                                @endif
                                <button class="pd-btn pd-btn-sm pd-btn-light">Düzenle</button>
                                <button class="pd-btn pd-btn-sm pd-btn-danger">İptal</button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-quote-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Erişim Özeti</div>
        
        <div class="space-y-3">
            <div class="pd-summary-row">
                <span>Toplam Erişim:</span>
                <span class="font-medium">{{ count($accessList) }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Aktif:</span>
                <span class="font-medium text-green-600">{{ collect($accessList)->where('status', 'active')->count() }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Bekleyen:</span>
                <span class="font-medium text-yellow-600">{{ collect($accessList)->where('status', 'pending')->count() }}</span>
            </div>
            <div class="pd-summary-row">
                <span>Admin:</span>
                <span class="font-medium text-red-600">{{ collect($accessList)->where('access_level', 'admin')->count() }}</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <button class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">
                Toplu Onay
            </button>
            <button class="pd-btn pd-btn-light pd-btn-sm pd-btn-block">
                Erişim Şablonları
            </button>
        </div>
    </div>
</div>
@endsection
