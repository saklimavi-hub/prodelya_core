@extends('layouts.prodelya-admin')

@section('title', 'Tenant Owner Oluştur')

@section('content')
<div class="pd-hub-family-shell">
    @include('super-admin.tenants._overview')

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tenant Owner Oluştur</h3>
                <p class="pd-section-subtitle">Bu kullanıcı tenant günlük operasyonu için admin paneline tenant host üzerinden giriş yapacaktır.</p>
            </div>
        </div>
        <div class="pd-section-body">
            @if($ownerExists)
                <div class="pd-alert pd-alert-warning">
                    Bu tenant için owner kullanıcı zaten oluşturulmuş. Ek kullanıcı yönetimi sonraki fazda açılacaktır.
                </div>
            @else
                <form method="POST" action="{{ route('admin.super.tenants.owner.store', $tenant) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf

                    <div>
                        <label class="pd-label" for="name">Ad Soyad</label>
                        <input id="name" name="name" type="text" class="pd-input" value="{{ old('name') }}" required>
                        @error('name')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="email">E-posta</label>
                        <input id="email" name="email" type="email" class="pd-input" value="{{ old('email') }}" required>
                        @error('email')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="phone">Telefon</label>
                        <input id="phone" name="phone" type="text" class="pd-input" value="{{ old('phone') }}">
                        @error('phone')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="password">Şifre</label>
                        <input id="password" name="password" type="password" class="pd-input">
                        <p class="text-sm text-gray-500" style="margin-top: 6px;">Boş bırakılırsa güvenli geçici şifre üretilir ve yalnızca başarı mesajında bir kez gösterilir.</p>
                        @error('password')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="role">Rol</label>
                        <input id="role" name="role" type="text" class="pd-input" value="{{ old('role', $defaultOwnerValues['role']) }}" readonly>
                        @error('role')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
                    </div>

                    <div class="flex items-center" style="gap: 12px;">
                        <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $defaultOwnerValues['is_active']))> Aktif</label>
                        <label><input type="checkbox" name="send_invite" value="1" @checked(old('send_invite', $defaultOwnerValues['send_invite']))> Invite gönder</label>
                    </div>

                    @error('owner')<div class="text-sm text-red-600" style="grid-column: 1 / -1;">{{ $message }}</div>@enderror

                    <div class="pd-alert pd-alert-warning" style="grid-column: 1 / -1;">
                        Tenant admin girişi: {{ $tenantAdminPreviewUrl }}. Local host/vhost kaydı hazır değilse bu adres ayrıca yapılandırılmalıdır.
                    </div>

                    <div style="grid-column: 1 / -1;">
                        <button type="submit" class="pd-btn pd-btn-primary">Owner Kullanıcısını Oluştur</button>
                    </div>
                </form>
            @endif
        </div>
    </section>
</div>
@endsection
