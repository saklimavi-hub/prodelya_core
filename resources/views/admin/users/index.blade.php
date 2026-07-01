@extends('layouts.prodelya-admin')

@section('title', 'Kullanıcılar / Roller')
@section('page_title', 'Kullanıcılar / Roller')
@section('page_subtitle', 'Abone Firma ekibini, rollerini ve temel yetki görünürlüğünü yönetin.')

@section('content')
<div class="space-y-6">
    @if(session('success'))
        <div class="pd-alert pd-alert-success">{{ session('success') }}</div>
    @endif

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Kullanıcı ve Ekip Durumu</h3>
                <p class="pd-section-subtitle">Owner sonrası ekip kurulumu, finans ve operasyon yetkileri tek bakışta görünür.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('admin.users.create') }}" class="pd-btn pd-btn-primary">Yeni Kullanıcı</a>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-grid pd-grid-4">
                <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Toplam Kullanıcı</div><div class="font-medium">{{ $summary['total'] }}</div></div></div>
                <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Aktif Kullanıcı</div><div class="font-medium">{{ $summary['active'] }}</div></div></div>
                <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Owner Durumu</div><div class="font-medium">{{ $summary['owner_count'] > 0 ? 'Hazır' : 'Eksik' }}</div></div></div>
                <div class="pd-card pd-card-soft"><div class="pd-card-body"><div class="text-sm text-gray-600">Ekip Kurulumu</div><div class="font-medium">{{ $summary['status'] }}</div></div></div>
            </div>
            <div class="pd-grid pd-grid-4" style="margin-top: 16px;">
                <div><div class="text-sm text-gray-600">Finans Yetkili</div><div class="font-medium">{{ $summary['has_finance'] ? 'Var' : 'Yok' }}</div></div>
                <div><div class="text-sm text-gray-600">Operasyon Yetkili</div><div class="font-medium">{{ $summary['has_operations'] ? 'Var' : 'Yok' }}</div></div>
                <div><div class="text-sm text-gray-600">Son Kullanıcı Oluşturma</div><div class="font-medium">{{ $summary['last_user_created_at'] }}</div></div>
                <div><div class="text-sm text-gray-600">Yetki Notu</div><div class="font-medium">Finans ve kritik işlemler rol bazında korunur</div></div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Abone Firma Kullanıcıları</h3>
                <p class="pd-section-subtitle">Platform admin hesapları bu listeye karıştırılmaz. Ayrı pasif statü olmadığı için yalnız aktif tenant üyelikleri gösterilir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" action="{{ route('admin.users.index') }}" class="pd-grid pd-grid-4" style="margin-bottom: 16px;">
                <div>
                    <label class="pd-label" for="search">Arama</label>
                    <input id="search" name="search" type="text" class="pd-input" value="{{ $filters['search'] }}">
                </div>
                <div>
                    <label class="pd-label" for="role">Rol</label>
                    <select id="role" name="role" class="pd-input">
                        <option value="">Tümü</option>
                        @foreach($roleOptions as $role)
                            <option value="{{ $role['key'] }}" @selected($filters['role'] === $role['key'])>{{ $role['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-3">
                    <button type="submit" class="pd-btn pd-btn-light">Filtrele</button>
                    <a href="{{ route('admin.users.index') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                </div>
            </form>

            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Kullanıcı</th>
                            <th>E-posta</th>
                            <th>Rol</th>
                            <th>Durum</th>
                            <th>Owner mı?</th>
                            <th>Yetki Özeti</th>
                            <th>Son Giriş / Oluşturulma</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($memberships as $member)
                            <tr>
                                <td>
                                    <div class="font-medium">{{ $member['name'] }}</div>
                                    <div class="text-sm text-gray-500">{{ $member['phone'] ?: 'Telefon yok' }}</div>
                                </td>
                                <td>{{ $member['email'] }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($member['role_labels'] as $label)
                                            <span class="pd-badge pd-badge-blue">{{ $label }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="pd-badge pd-badge-green">{{ $member['status_label'] }}</span>
                                        @if($member['has_finance'])
                                            <span class="pd-badge pd-badge-amber">Finans Yetkili</span>
                                        @endif
                                        @if($member['has_operations'])
                                            <span class="pd-badge pd-badge-gray">Operasyon Yetkili</span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $member['owner_label'] }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach(array_slice($member['permission_summary'], 0, 4) as $permissionLabel)
                                            <span class="pd-badge pd-badge-gray">{{ $permissionLabel }}</span>
                                        @endforeach
                                    </div>
                                    @if(count($member['permission_summary']) > 4)
                                        <div class="text-sm text-gray-500" style="margin-top: 6px;">+{{ count($member['permission_summary']) - 4 }} ek yetki</div>
                                    @endif
                                </td>
                                <td>
                                    <div>{{ $member['last_login_at'] }}</div>
                                    <div class="text-sm text-gray-500">{{ $member['created_at'] }}</div>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-2">
                                        <a href="{{ route('admin.users.edit', $member['user']) }}" class="pd-btn pd-btn-light">Düzenle</a>
                                        <form method="POST" action="{{ route('admin.users.destroy', $member['user']) }}" onsubmit="return confirm('Bu kullanıcının Abone Firma erişimi kaldırılsın mı?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="pd-btn pd-btn-light">Erişimi Kaldır</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-gray-500">Bu Abone Firma için kullanıcı görünmüyor.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
