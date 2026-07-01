@php
    $selectedRoleKeys = collect(old('role_keys', $selectedRoleKeys ?? []))->filter()->values()->all();
@endphp

<div class="pd-section-card pd-section-card-soft-slate">
    <div class="pd-section-header">
        <div>
            <h3 class="pd-section-title">Kullanıcı Bilgileri</h3>
            <p class="pd-section-subtitle">Abone Firma içinde çalışacak ekip kullanıcısını oluşturun veya düzenleyin.</p>
        </div>
    </div>
    <div class="pd-section-body">
        <div class="pd-grid pd-grid-2">
            <div>
                <label class="pd-label" for="name">Ad Soyad</label>
                <input id="name" name="name" type="text" class="pd-input" value="{{ old('name', $userRecord?->name) }}" required>
                @error('name')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="pd-label" for="email">E-posta</label>
                <input id="email" name="email" type="email" class="pd-input" value="{{ old('email', $userRecord?->email) }}" required>
                @error('email')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="pd-label" for="phone">Telefon</label>
                <input id="phone" name="phone" type="text" class="pd-input" value="{{ old('phone', $userRecord?->phone) }}">
                @error('phone')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
            </div>
            @if($userRecord === null)
                <div>
                    <label class="pd-label" for="password">Geçici Şifre</label>
                    <input id="password" name="password" type="text" class="pd-input" value="{{ old('password') }}" required>
                    <div class="text-sm text-gray-500" style="margin-top: 6px;">Bu fazda davet e-postası zorunlu değildir. Şifre güvenli biçimde manuel paylaşılabilir.</div>
                    @error('password')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
                </div>
            @endif
        </div>
    </div>
</div>

<div class="pd-section-card pd-section-card-soft-slate" style="margin-top: 16px;">
    <div class="pd-section-header">
        <div>
            <h3 class="pd-section-title">Rol</h3>
            <p class="pd-section-subtitle">Var olan rol modeli korunur; yeni sahte rol üretilmez.</p>
        </div>
    </div>
    <div class="pd-section-body">
        <div class="pd-grid pd-grid-2">
            @foreach($roleOptions as $role)
                <label class="pd-card pd-card-soft" style="cursor: pointer;">
                    <div class="pd-card-body" style="display: flex; gap: 10px; align-items: flex-start;">
                        <input type="checkbox" name="role_keys[]" value="{{ $role['key'] }}" @checked(in_array($role['key'], $selectedRoleKeys, true)) style="margin-top: 4px;">
                        <div>
                            <div class="font-medium">{{ $role['label'] }}</div>
                            <div class="text-sm text-gray-500">{{ $role['description'] ?: 'Açıklama yok' }}</div>
                        </div>
                    </div>
                </label>
            @endforeach
        </div>
        @error('role_keys')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
        @error('role_keys.*')<div class="text-sm text-red-600" style="margin-top: 6px;">{{ $message }}</div>@enderror
    </div>
</div>

<div class="pd-section-card pd-section-card-soft-slate" style="margin-top: 16px;">
    <div class="pd-section-header">
        <div>
            <h3 class="pd-section-title">Yetki Özeti</h3>
            <p class="pd-section-subtitle">Finans ve kritik işlemler yalnız ilgili rol izinleriyle açılır.</p>
        </div>
    </div>
    <div class="pd-section-body">
        <div class="flex flex-wrap gap-2">
            @forelse($permissionSummary as $permissionLabel)
                <span class="pd-badge pd-badge-blue">{{ $permissionLabel }}</span>
            @empty
                <span class="text-sm text-gray-500">Yetki özeti oluşturulamadı.</span>
            @endforelse
        </div>
    </div>
</div>

<div class="pd-section-card pd-section-card-soft-slate" style="margin-top: 16px;">
    <div class="pd-section-header">
        <div>
            <h3 class="pd-section-title">Güvenlik</h3>
            <p class="pd-section-subtitle">Son owner ve kendi hesabını kilitleme riskleri bu fazda guard ile korunur.</p>
        </div>
    </div>
    <div class="pd-section-body">
        @if(!empty($guardSummary))
            <div class="pd-alert pd-alert-warning" style="margin-bottom: 12px;">
                @if($guardSummary['is_owner'])
                    Bu kullanıcı owner rolünde. @if(($guardSummary['owner_count'] ?? 0) <= 1) Son owner koruması aktif. @endif
                @elseif($guardSummary['is_self_manage_lock_risk'])
                    Kendi hesabınızı düzenliyorsunuz. Kullanıcı yönetimi yetkisini tamamen kaldırmayın.
                @else
                    Kritik guard görünmüyor.
                @endif
            </div>
        @endif
        <div class="text-sm text-gray-600">
            Platform admin kullanıcıları tenant ekip listesine karıştırılmaz. Ayrı pasif alanı olmadığı için erişim kaldırma, tenant üyeliğini kaldırır.
        </div>
    </div>
</div>
