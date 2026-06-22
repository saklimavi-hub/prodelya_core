@extends('layouts.prodelya-admin')

@section('title', 'Tenant Paket ve Override Yönetimi')

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
            Owner kullanıcı henüz oluşturulmadı. Bu tenant için kullanıcı onboarding ve başlangıç ayarları sonraki adımda tamamlanmalıdır.
        </div>
    @endif

    @include('super-admin.tenants._onboarding-status')

    <section class="pd-section-card pd-section-card-soft-slate" style="margin-bottom: 16px;">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Owner Durumu</h3>
                <p class="pd-section-subtitle">Tenant operasyonu superadmin yerine bu kullanıcıyla yürütülmelidir.</p>
            </div>
            <div class="pd-hero-actions">
                @if(!$ownerExists)
                    <a href="{{ route('admin.super.tenants.owner.create', $tenant) }}" class="pd-btn pd-btn-primary">Owner Oluştur</a>
                @endif
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
                        <div class="text-sm text-gray-600">Rol</div>
                        <div class="font-medium">{{ $ownerRole?->name ?: 'Tenant Owner' }}</div>
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
                <h3 class="pd-section-title">Paket Bölümü</h3>
                <p class="pd-section-subtitle">Tenant paket ataması ve yazılabilir temel durum alanları.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="POST" action="{{ route('admin.super.tenants.update', $tenant) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="pd-label" for="package_key">Paket</label>
                    <select id="package_key" name="package_key" class="pd-input">
                        <option value="">Core / Paket Yok</option>
                        @foreach($packages as $package)
                            <option value="{{ $package->key }}" @selected(old('package_key', $tenant->package_key) === $package->key)>
                                {{ $package->name }} ({{ $package->key }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="pd-label" for="status">Status</label>
                    <select id="status" name="status" class="pd-input">
                        <option value="active" @selected(old('status', $tenant->status) === 'active')>Aktif</option>
                        <option value="trial" @selected(old('status', $tenant->status) === 'trial')>Trial</option>
                        <option value="inactive" @selected(old('status', $tenant->status) === 'inactive')>Pasif</option>
                        <option value="suspended" @selected(old('status', $tenant->status) === 'suspended')>Askıya Alındı</option>
                    </select>
                    <p class="text-sm text-gray-500" style="margin-top: 6px;">Trial / expired lifecycle alanları mevcut schema’da kalıcı değil; bu ekranda hesaplanan durum ayrıca gösterilir.</p>
                </div>

                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="pd-btn pd-btn-primary">Paket Bilgisini Kaydet</button>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Modül Override</h3>
                <p class="pd-section-subtitle">Paket Varsayılanı / Açık / Kapalı seçimleri tenant bazlı override katmanını yönetir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="POST" action="{{ route('admin.super.tenants.modules.update', $tenant) }}">
                @csrf
                @method('PUT')
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr>
                                <th>Modül</th>
                                <th>Paket</th>
                                <th>Effective</th>
                                <th>Override</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($moduleRows as $row)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $row['label'] }}</div>
                                        <div class="text-sm text-gray-500">{{ $row['key'] }} · {{ $row['category'] }}</div>
                                        @if($row['is_locked'])
                                            <div style="margin-top: 6px;"><span class="pd-badge {{ $row['is_core'] ? 'pd-badge-blue' : 'pd-badge-amber' }}">{{ $row['is_core'] ? 'Core kilitli' : 'Planlı/Pasif' }}</span></div>
                                        @endif
                                    </td>
                                    <td><span class="pd-badge {{ $row['package_enabled'] ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $row['package_enabled'] ? 'Açık' : 'Kapalı' }}</span></td>
                                    <td>
                                        <span class="pd-badge {{ $row['effective_enabled'] ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $row['effective_enabled'] ? 'Açık' : 'Kapalı' }}</span>
                                        <div class="text-sm text-gray-500" style="margin-top: 6px;">{{ $row['effective_reason'] }}</div>
                                    </td>
                                    <td>
                                        <select name="overrides[{{ $row['key'] }}]" class="pd-input" @disabled($row['is_locked'])>
                                            <option value="default" @selected($row['override_state'] === 'default')>Paket Varsayılanı</option>
                                            <option value="enabled" @selected($row['override_state'] === 'enabled')>Açık</option>
                                            <option value="disabled" @selected($row['override_state'] === 'disabled')>Kapalı</option>
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 16px;">
                    <button type="submit" class="pd-btn pd-btn-primary">Modül Override Kaydet</button>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Feature Override</h3>
                <p class="pd-section-subtitle">Mevcut schema uygun olduğu için feature bazlı tenant override desteklenir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="POST" action="{{ route('admin.super.tenants.features.update', $tenant) }}">
                @csrf
                @method('PUT')
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr>
                                <th>Feature</th>
                                <th>Paket</th>
                                <th>Effective</th>
                                <th>Override</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($featureRows as $row)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $row['feature_label'] }}</div>
                                        <div class="text-sm text-gray-500">{{ $row['module_label'] }} · {{ $row['feature_key'] }}</div>
                                        @if($row['is_locked'])
                                            <div style="margin-top: 6px;"><span class="pd-badge pd-badge-amber">Planlı/Pasif</span></div>
                                        @endif
                                    </td>
                                    <td><span class="pd-badge {{ $row['package_enabled'] ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $row['package_enabled'] ? 'Açık' : 'Kapalı' }}</span></td>
                                    <td>
                                        <span class="pd-badge {{ $row['effective_enabled'] ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $row['effective_enabled'] ? 'Açık' : 'Kapalı' }}</span>
                                        <div class="text-sm text-gray-500" style="margin-top: 6px;">{{ $row['effective_reason'] }}</div>
                                    </td>
                                    <td>
                                        <select name="overrides[{{ $row['feature_key'] }}]" class="pd-input" @disabled($row['is_locked'])>
                                            <option value="default" @selected($row['override_state'] === 'default')>Paket Varsayılanı</option>
                                            <option value="enabled" @selected($row['override_state'] === 'enabled')>Açık</option>
                                            <option value="disabled" @selected($row['override_state'] === 'disabled')>Kapalı</option>
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 16px;">
                    <button type="submit" class="pd-btn pd-btn-primary">Feature Override Kaydet</button>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Limit Override</h3>
                <p class="pd-section-subtitle">Mevcut schema ölçüsünde limit override anahtarları TenantSetting `limit_*` üzerinden tutulur.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="POST" action="{{ route('admin.super.tenants.limits.update', $tenant) }}">
                @csrf
                @method('PUT')
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr>
                                <th>Limit</th>
                                <th>Paket</th>
                                <th>Kullanım</th>
                                <th>Effective</th>
                                <th>Override</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($limitRows as $row)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $row['label'] }}</div>
                                        <div class="text-sm text-gray-500">{{ $row['key'] }}</div>
                                    </td>
                                    <td>
                                        @if(!$row['package_limit'])
                                            <span class="pd-badge pd-badge-gray">Tanımsız</span>
                                        @elseif($row['package_limit']['is_unlimited'])
                                            <span class="pd-badge pd-badge-blue">Limitsiz</span>
                                        @else
                                            <span class="pd-badge pd-badge-green">{{ $row['package_limit']['limit_value'] }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $row['current_usage'] }}</td>
                                    <td>
                                        <span class="pd-badge {{ match($row['effective_status']) {'ok' => 'pd-badge-green', 'warning' => 'pd-badge-amber', 'exceeded' => 'pd-badge-red', default => 'pd-badge-blue'} }}">
                                            {{ match($row['effective_status']) {'ok' => 'Normal', 'warning' => 'Uyarı', 'exceeded' => 'Limit Aşıldı', default => 'Limitsiz'} }}
                                        </span>
                                        <div class="text-sm text-gray-500" style="margin-top: 6px;">{{ $row['effective_limit'] === null ? 'Limitsiz' : $row['effective_limit'] }}</div>
                                    </td>
                                    <td>
                                        <select name="limits[{{ $row['key'] }}][mode]" class="pd-input">
                                            <option value="default" @selected($row['override_mode'] === 'default')>Paket Varsayılanı</option>
                                            <option value="value" @selected($row['override_mode'] === 'value')>Değer Gir</option>
                                            <option value="unlimited" @selected($row['override_mode'] === 'unlimited')>Limitsiz</option>
                                        </select>
                                        <input type="number" min="0" name="limits[{{ $row['key'] }}][value]" value="{{ $row['override_value'] }}" class="pd-input" style="margin-top: 8px;" placeholder="Override değer">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 16px;">
                    <button type="submit" class="pd-btn pd-btn-primary">Limit Override Kaydet</button>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
