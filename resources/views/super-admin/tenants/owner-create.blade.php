@extends('layouts.prodelya-admin')

@section('title', 'Abone Firma Owner Kullanıcısı Oluştur')

@section('content')
<div class="pd-hub-family-shell">
    @include('super-admin.tenants._overview')

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Owner Kullanıcısı Oluştur</h3>
                <p class="pd-section-subtitle">Bu kullanıcı Abone Firma günlük operasyonu için admin paneline tenant host üzerinden giriş yapacaktır.</p>
            </div>
        </div>
        <div class="pd-section-body">
            @if($ownerExists)
                <div class="pd-alert pd-alert-warning">
                    Bu tenant için owner kullanıcı zaten oluşturulmuş. Ek kullanıcı yönetimi tenant panelinde Kullanıcılar / Roller ekranından yürütülür.
                </div>
            @else
                <form method="POST" action="{{ route('admin.super.tenants.owner.store', $tenant) }}" class="pd-form-shell-grid pd-form-shell-grid-2">
                    @csrf

                    <div>
                        <label class="pd-label" for="name">Ad Soyad</label>
                        <input id="name" name="name" type="text" class="pd-input" value="{{ old('name') }}" required>
                        @error('name')<div class="pd-input-error">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="email">E-posta</label>
                        <input id="email" name="email" type="email" class="pd-input" value="{{ old('email') }}" required>
                        @error('email')<div class="pd-input-error">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="phone">Telefon</label>
                        <input id="phone" name="phone" type="text" class="pd-input" value="{{ old('phone') }}">
                        @error('phone')<div class="pd-input-error">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="password">Şifre</label>
                        <input id="password" name="password" type="password" class="pd-input">
                        <p class="pd-field-note">Boş bırakılırsa güvenli geçici şifre üretilir ve yalnızca başarı mesajında bir kez gösterilir.</p>
                        @error('password')<div class="pd-input-error">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label class="pd-label" for="role">Rol</label>
                        <input id="role" name="role" type="text" class="pd-input" value="{{ old('role', $defaultOwnerValues['role']) }}" readonly>
                        @error('role')<div class="pd-input-error">{{ $message }}</div>@enderror
                    </div>

                    <div class="pd-checkbox-inline gap-4">
                        <label class="pd-checkbox-inline"><input class="pd-inline-form-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $defaultOwnerValues['is_active']))> Aktif</label>
                        <label class="pd-checkbox-inline"><input class="pd-inline-form-input" type="checkbox" name="send_invite" value="1" @checked(old('send_invite', $defaultOwnerValues['send_invite']))> Invite gönder</label>
                    </div>

                    @error('owner')<div class="pd-input-error md:col-span-2">{{ $message }}</div>@enderror

                    <div class="pd-alert pd-alert-warning md:col-span-2">
                        Abone Firma panel girişi: {{ $tenantAdminPreviewUrl }}. Local host/vhost kaydı hazır değilse bu adres ayrıca yapılandırılmalıdır.
                    </div>

                    <div class="md:col-span-2">
                        <div class="pd-form-actions">
                            <button type="submit" class="pd-btn pd-btn-primary">Owner Kullanıcısını Oluştur</button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </section>
</div>
@endsection
