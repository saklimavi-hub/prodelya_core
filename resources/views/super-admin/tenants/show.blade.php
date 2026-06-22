@extends('layouts.prodelya-admin')

@section('title', 'Tenant Detayı')

@section('content')
<div class="pd-hub-family-shell">
    @include('super-admin.tenants._overview')

    @if(session('success'))
        <div class="pd-alert pd-alert-success" style="margin-bottom: 16px;">{{ session('success') }}</div>
    @endif

    @if(session('owner_temporary_password'))
        <div class="pd-alert pd-alert-warning" style="margin-bottom: 16px;">
            Geçici owner şifresi yalnızca bu ekranda bir kez gösterilir: <strong>{{ session('owner_temporary_password') }}</strong>
        </div>
    @endif

    @if(!$tenantHasUsers)
        <div class="pd-alert pd-alert-warning" style="margin-bottom: 16px;">
            Owner kullanıcı henüz oluşturulmadı. Tenant kullanıcı onboarding sonraki adımda tamamlanmalıdır.
        </div>
    @endif

    @include('super-admin.tenants._onboarding-status')

    <section class="pd-section-card pd-section-card-soft-slate" style="margin-bottom: 16px;">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Owner Durumu</h3>
                <p class="pd-section-subtitle">Tenant günlük operasyonu için kullanılacak admin hesabı bilgileri.</p>
            </div>
        </div>
        <div class="pd-section-body">
            @if($ownerExists)
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-sm text-gray-600">Ad Soyad</div>
                        <div class="font-medium">{{ $ownerUser->name }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-600">E-posta</div>
                        <div class="font-medium">{{ $ownerUser->email }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-600">Durum</div>
                        <div class="font-medium">Aktif</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-600">Son Giriş</div>
                        <div class="font-medium">{{ $ownerUser->last_login_at?->format('d.m.Y H:i') ?: '-' }}</div>
                    </div>
                </div>
            @else
                <div class="pd-alert pd-alert-warning">Owner kullanıcı henüz oluşturulmadı.</div>
            @endif
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Paket ve Override Özeti</h3>
                <p class="pd-section-subtitle">Tenant için effective paket, modül, feature ve limit görünümü.</p>
            </div>
            <div class="pd-hero-actions">
                <a href="{{ route('admin.super.tenants.edit', $tenant) }}" class="pd-btn pd-btn-primary">Düzenle</a>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="pd-card pd-card-soft">
                    <div class="pd-card-body">
                        <div class="text-sm text-gray-600">Tenant Paketi</div>
                        <div class="font-medium">{{ $packageRecord?->name ?? ($tenant->package_key ?: 'Core') }}</div>
                        <div class="text-sm text-gray-500">{{ $packageRecord?->description ?: 'Merkezi paket kaydı yok veya core fallback kullanılıyor.' }}</div>
                    </div>
                </div>
                <div class="pd-card pd-card-soft">
                    <div class="pd-card-body">
                        <div class="text-sm text-gray-600">Aktif Modül Etiketi</div>
                        <div class="font-medium">{{ count(array_filter($effectiveAccessSummary['modules'], fn ($row) => $row['enabled'])) }}</div>
                    </div>
                </div>
                <div class="pd-card pd-card-soft">
                    <div class="pd-card-body">
                        <div class="text-sm text-gray-600">Uyarı Sayısı</div>
                        <div class="font-medium">{{ count($effectiveAccessSummary['warnings'] ?? []) }}</div>
                    </div>
                </div>
            </div>

            <div class="pd-table-wrap" style="margin-top: 18px;">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Modül</th>
                            <th>Paket</th>
                            <th>Override</th>
                            <th>Effective</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($moduleRows as $row)
                            <tr>
                                <td>
                                    <div class="font-medium">{{ $row['label'] }}</div>
                                    <div class="text-sm text-gray-500">{{ $row['key'] }}</div>
                                </td>
                                <td><span class="pd-badge {{ $row['package_enabled'] ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $row['package_enabled'] ? 'Açık' : 'Kapalı' }}</span></td>
                                <td><span class="pd-badge pd-badge-blue">{{ match($row['override_state']) {'enabled' => 'Açık', 'disabled' => 'Kapalı', default => 'Paket Varsayılanı'} }}</span></td>
                                <td><span class="pd-badge {{ $row['effective_enabled'] ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $row['effective_enabled'] ? 'Açık' : 'Kapalı' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
