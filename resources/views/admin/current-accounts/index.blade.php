@extends('layouts.prodelya-admin')

@section('title', 'Cari Kartlar')
@section('page_title', 'Cari Kartlar')
@section('page_subtitle', 'Firma, müşteri, tedarikçi ve operasyon carilerini tek listede yönetin.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.companies.create') }}" class="pd-btn pd-btn-primary">Yeni Cari Kart</a>
</div>
@endsection

@section('content')
<div class="pd-grid pd-grid-4" style="margin-bottom: 14px;">
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Tüm Cariler</div><div class="text-2xl font-bold" style="margin-top: 6px;">{{ $stats['total'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Müşteriler</div><div class="text-2xl font-bold" style="margin-top: 6px;">{{ $stats['customer'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Tedarikçiler</div><div class="text-2xl font-bold" style="margin-top: 6px;">{{ $stats['supplier'] }}</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Pasif / Blokeli</div><div class="text-2xl font-bold" style="margin-top: 6px;">{{ $stats['inactive'] }}</div></div></div>
</div>

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-body">
        <div class="pd-chip-group" style="margin-bottom: 14px;">
            @foreach($roleTabs as $tab)
                @php
                    $tabQuery = array_merge(request()->except('page'), ['role' => $tab['value']]);
                    if ($tab['value'] === '') {
                        unset($tabQuery['role']);
                    }
                    $active = ($filters['role'] ?? '') === $tab['value'];
                @endphp
                <a href="{{ route('admin.current-accounts.index', $tabQuery) }}" class="pd-chip {{ $active ? 'is-active' : '' }}">{{ $tab['label'] }}</a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.current-accounts.index') }}">
            <div class="pd-form-grid-3">
                <div>
                    <label class="text-sm font-medium">Arama</label>
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari adı, vergi no, e-posta...">
                </div>
                <div>
                    <label class="text-sm font-medium">Rol</label>
                    <select name="role">
                        <option value="">Tümü</option>
                        @foreach(\App\Models\CurrentAccountRole::roleLabels() as $roleValue => $roleLabel)
                            <option value="{{ $roleValue }}" @selected($filters['role'] === $roleValue)>{{ $roleLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">Durum</label>
                    <select name="status">
                        <option value="">Tümü</option>
                        @foreach(\App\Models\CurrentAccount::statusLabels() as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}" @selected($filters['status'] === $statusValue)>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">Risk Durumu</label>
                    <select name="risk_status">
                        <option value="">Tümü</option>
                        @foreach(\App\Models\CurrentAccount::riskStatusLabels() as $riskValue => $riskLabel)
                            <option value="{{ $riskValue }}" @selected($filters['risk_status'] === $riskValue)>{{ $riskLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="display: flex; align-items: end; gap: 8px;">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.current-accounts.index') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Tüm Cari Kartlar</h3>
        <p class="pd-card-subtitle">Ana firma kayıtları burada görünür. Gerekirse finans omurgasına bağlı teknik cari hesaplara erişim korunur.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Cari</th>
                        <th>Roller</th>
                        <th>İletişim</th>
                        <th>VKN / TCKN</th>
                        <th>Durum</th>
                        <th class="text-right">Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                        @forelse($accounts as $account)
                            @php
                                $roleValues = $account->roles->pluck('role')->all();
                                $linkedCompanyId = $account->primaryCompanyLink?->link_id;
                                $showRoute = $linkedCompanyId
                                    ? route('admin.companies.show', $linkedCompanyId)
                                    : route('admin.current-accounts.show', $account);
                                $editRoute = $linkedCompanyId
                                    ? route('admin.companies.edit', $linkedCompanyId)
                                    : route('admin.current-accounts.edit', $account);
                                $taxIdentity = $account->tax_number ?: $account->tc_no;
                            @endphp
                        <tr>
                            <td>
                                <div class="font-medium">{{ $account->safeDisplayName() }}</div>
                                @if($account->legal_name && $account->legal_name !== $account->display_name)
                                    <div class="text-sm text-gray-600">{{ $account->legal_name }}</div>
                                @endif
                                @if($account->account_code)
                                    <div class="text-sm text-gray-600">Kod: {{ $account->account_code }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    @forelse($account->roles as $role)
                                        @php
                                            $roleTone = match($role->role) {
                                                \App\Models\CurrentAccountRole::ROLE_CUSTOMER => 'green',
                                                \App\Models\CurrentAccountRole::ROLE_SUPPLIER => 'blue',
                                                \App\Models\CurrentAccountRole::ROLE_SUBCONTRACTOR => 'purple',
                                                \App\Models\CurrentAccountRole::ROLE_CARRIER => 'amber',
                                                \App\Models\CurrentAccountRole::ROLE_SERVICE_PROVIDER => 'indigo',
                                                default => 'gray',
                                            };
                                        @endphp
                                        <span class="pd-badge pd-badge-{{ $roleTone }}">{{ $role->safeRoleLabel() }}</span>
                                    @empty
                                        <span class="pd-badge pd-badge-gray">Rol Yok</span>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                @if($account->email)
                                    <div>{{ $account->email }}</div>
                                @endif
                                @if($account->phone)
                                    <div>{{ $account->phone }}</div>
                                @endif
                                @if($account->mobile)
                                    <div>{{ $account->mobile }}</div>
                                @endif
                                @if(!$account->email && !$account->phone && !$account->mobile)
                                    <span class="text-gray-600">İletişim bilgisi yok</span>
                                @endif
                            </td>
                            <td>
                                @if($taxIdentity)
                                    <div>{{ $taxIdentity }}</div>
                                @else
                                    <span class="text-gray-600">Kimlik bilgisi yok</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    @php
                                        $statusTone = match($account->status) {
                                            \App\Models\CurrentAccount::STATUS_ACTIVE => 'green',
                                            \App\Models\CurrentAccount::STATUS_BLOCKED => 'red',
                                            \App\Models\CurrentAccount::STATUS_ARCHIVED => 'gray',
                                            default => 'amber',
                                        };
                                    @endphp
                                    <span class="pd-badge pd-badge-{{ $statusTone }}">{{ $account->safeStatusLabel() }}</span>
                                    @if($account->risk_status)
                                        @php
                                            $riskTone = match($account->risk_status) {
                                                'critical', 'high' => 'red',
                                                'medium' => 'amber',
                                                default => 'green',
                                            };
                                        @endphp
                                        <span class="pd-badge pd-badge-{{ $riskTone }}">{{ $account->safeRiskStatusLabel() }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="pd-actions-wrap">
                                    <a href="{{ $showRoute }}" class="pd-btn pd-btn-light pd-btn-sm">Aç</a>
                                    <a href="{{ $editRoute }}" class="pd-btn pd-btn-primary pd-btn-sm">Düzenle</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center">Cari kart bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($accounts->hasPages())
            <div style="margin-top: 14px;">{{ $accounts->links() }}</div>
        @endif
    </div>
</div>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Cari Kart Özeti</h3>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Eylemler</h4>
            <div class="pd-summary-list">
                <a href="{{ route('admin.companies.create') }}" class="pd-summary-item">Yeni cari kart</a>
                <a href="{{ route('admin.current-accounts.index', ['role' => \App\Models\CurrentAccountRole::ROLE_CUSTOMER]) }}" class="pd-summary-item">Müşterileri aç</a>
                <a href="{{ route('admin.current-accounts.index', ['role' => \App\Models\CurrentAccountRole::ROLE_SUPPLIER]) }}" class="pd-summary-item">Tedarikçileri aç</a>
            </div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Rol Dağılımı</h4>
            <div class="pd-summary-info">
                <div class="pd-summary-row"><span>Müşteri</span><span class="font-medium">{{ $stats['customer'] }}</span></div>
                <div class="pd-summary-row"><span>Tedarikçi</span><span class="font-medium">{{ $stats['supplier'] }}</span></div>
                <div class="pd-summary-row"><span>Fasoncu</span><span class="font-medium">{{ $stats['subcontractor'] }}</span></div>
                <div class="pd-summary-row"><span>Kargo / Kurye</span><span class="font-medium">{{ $stats['carrier'] }}</span></div>
            </div>
        </div>

        <div class="pd-note">Cari hareket ve finansal toplam ekranı bu fazda kapalı tutulur.</div>
    </div>
</div>
@endsection
