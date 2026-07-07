@extends('layouts.prodelya-admin')

@section('title', 'Cari Bakiyeler')
@section('page_title', 'Cari Bakiyeler')
@section('page_subtitle', 'Bu ekran cari kartların finansal bakiye ve hareket özetidir. Cari kimlik ve iletişim bilgileri Cari Kartlar ekranından yönetilir.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.companies.index') }}" class="pd-btn pd-btn-light">Cari Kartlar</a>
    <a href="{{ route('admin.companies.create') }}" class="pd-btn pd-btn-primary">Yeni Cari Oluştur</a>
</div>
@endsection

@section('content')
@php
    $currentFilters = array_filter([
        'search' => $filters['search'] ?? '',
        'role' => $filters['role'] ?? '',
        'status' => $filters['status'] ?? '',
        'risk_status' => $filters['risk_status'] ?? '',
        'balance_status' => $filters['balance_status'] ?? '',
        'movement_status' => $filters['movement_status'] ?? '',
    ], fn ($value) => $value !== '');
@endphp

<style>
    .pd-account-tabs { display:flex; gap:8px; flex-wrap:wrap; }
    .pd-account-tab {
        display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:0 14px;
        border:1px solid var(--pd-line); border-radius:8px; background:#fff; color:#344054; text-decoration:none; font-size:13px; font-weight:600;
    }
    .pd-account-tab.is-active { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
    .pd-account-tab:hover { border-color:#bfd4ef; color:#1d4ed8; }
    .pd-account-layout { display:grid; grid-template-columns:minmax(0, 2fr) minmax(280px, 1fr); gap:16px; align-items:start; }
    .pd-account-panel-stack { display:grid; gap:14px; }
    .pd-account-summary-panel { position:sticky; top:16px; display:grid; gap:14px; }
    @media (max-width: 1100px) {
        .pd-account-layout { grid-template-columns:1fr; }
        .pd-account-summary-panel { position:static; }
    }
</style>

<div class="pd-account-layout">
    <div class="pd-account-panel-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <div>
                        <div class="text-xs" style="color: var(--pd-muted);">Sekmeli Görünüm</div>
                        <div style="margin-top:6px; font-weight:700;">Finansal odaklı cari bakiyeleri izleyin</div>
                        <div style="margin-top:6px; color: var(--pd-muted);">Aktif, açık, vadesi geçen ve arşivlenen kayıtları ayrı sekmelerde izleyin.</div>
                    </div>
                    <div class="pd-account-tabs" role="tablist" aria-label="Cari bakiye sekmeleri">
                        @foreach($listTabs as $tabKey => $tabLabel)
                            <a
                                href="{{ route('admin.current-accounts.index', array_merge($currentFilters, ['tab' => $tabKey])) }}"
                                class="pd-account-tab {{ $activeTab === $tabKey ? 'is-active' : '' }}"
                                aria-current="{{ $activeTab === $tabKey ? 'page' : 'false' }}"
                            >
                                {{ $tabLabel }} <span style="margin-left:6px;">{{ $tabStats[$tabKey]['count'] ?? 0 }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        @if(!empty($filterNotice))
            <div class="pd-card">
                <div class="pd-card-body">
                    <div class="pd-note">{{ $filterNotice }}</div>
                </div>
            </div>
        @endif

        <div class="pd-card">
            <div class="pd-card-body">
                <form method="GET" action="{{ route('admin.current-accounts.index') }}">
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
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
                        @if($showBalanceFilter ?? false)
                            <div>
                                <label class="text-sm font-medium">Bakiye Durumu</label>
                                <select name="balance_status">
                                    @foreach(($balanceStatusOptions ?? []) as $balanceValue => $balanceLabel)
                                        <option value="{{ $balanceValue }}" @selected(($filters['balance_status'] ?? '') === $balanceValue)>{{ $balanceLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div>
                            <label class="text-sm font-medium">Hareket Durumu</label>
                            <select name="movement_status">
                                @foreach(($movementStatusOptions ?? []) as $movementValue => $movementLabel)
                                    <option value="{{ $movementValue }}" @selected(($filters['movement_status'] ?? '') === $movementValue)>{{ $movementLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($showStatusFilter ?? false)
                            <div>
                                <label class="text-sm font-medium">Cari Durumu</label>
                                <select name="status">
                                    <option value="">Tümü</option>
                                    @foreach(($statusFilterOptions ?? []) as $statusValue => $statusLabel)
                                        <option value="{{ $statusValue }}" @selected($filters['status'] === $statusValue)>{{ $statusLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
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
                            <a href="{{ route('admin.current-accounts.index', ['tab' => $activeTab]) }}" class="pd-btn pd-btn-light">Sıfırla</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">{{ $selectedTabSummary['label'] ?? 'Aktif Bakiyeler' }}</h3>
                <p class="pd-card-subtitle">
                    @if($activeTab === 'aktif')
                        Aktif ve finansal anlamı olan cari bakiyeleri izleyin.
                    @elseif($activeTab === 'acik')
                        Yalnız açık hareketi olan cariler listelenir.
                    @elseif($activeTab === 'vadesi-gecen')
                        Yalnız vadesi geçen açık hareketler listelenir.
                    @elseif($activeTab === 'arsiv')
                        Pasif veya arşivlenen cari kartlar burada görünür. Bu kayıtlar silinmez; geçmiş kontrolü için saklanır.
                    @else
                        Aktif, pasif ve arşivlenen tüm cari bakiyeler aynı listede görünür.
                    @endif
                </p>
            </div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr>
                                <th>Cari</th>
                                @if($activeTab === 'arsiv')
                                    <th>Ana Cari Kart</th>
                                @endif
                                <th>Roller</th>
                                <th>İletişim</th>
                                <th>VKN / TCKN</th>
                                <th>Durum</th>
                                @if($canViewFinancialData ?? false)
                                    <th>Güncel Bakiye</th>
                                    <th>Bakiye Durumu</th>
                                    <th>Açık Hareket</th>
                                    <th>Son Hareket</th>
                                @endif
                                <th class="text-right">Aksiyon</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($accounts as $account)
                            @php
                                $linkedCompanyId = $account->primaryCompanyLink?->link_id;
                                $linkedCompany = $linkedCompanyId ? ($linkedCompanies[$linkedCompanyId] ?? null) : null;
                                $companyRoute = $linkedCompanyId
                                    ? route('admin.companies.show', $linkedCompany)
                                    : route('admin.current-accounts.show', $account);
                                $duplicateRoute = $linkedCompanyId
                                    ? route('admin.companies.show', ['company' => $linkedCompany, 'tab' => 'benzer-cari'])
                                    : null;
                                $statementRoute = route('admin.current-accounts.transactions.index', $account);
                                $taxIdentity = $account->tax_number ?: $account->tc_no;
                                $summary = ($balanceSummaries ?? [])[$account->id] ?? null;
                                $directionTone = match($summary['balance_direction'] ?? 'closed') {
                                    'receivable' => 'green',
                                    'payable' => 'amber',
                                    'mixed' => 'gray',
                                    default => 'gray',
                                };
                                $duplicateSummary = $linkedCompanyId ? ($duplicateSummaries[$linkedCompanyId] ?? null) : null;
                                $mainCompany = $duplicateSummary['main_company'] ?? null;
                                $isArchivedLike = in_array($account->status, [\App\Models\CurrentAccount::STATUS_PASSIVE, \App\Models\CurrentAccount::STATUS_ARCHIVED, \App\Models\CurrentAccount::STATUS_BLOCKED], true);
                                $statusLabel = match($account->status) {
                                    \App\Models\CurrentAccount::STATUS_ARCHIVED => 'Arşivlendi',
                                    \App\Models\CurrentAccount::STATUS_PASSIVE => 'Pasif',
                                    \App\Models\CurrentAccount::STATUS_BLOCKED => 'Bloklu',
                                    default => 'Aktif',
                                };
                                $statusTone = match($account->status) {
                                    \App\Models\CurrentAccount::STATUS_ACTIVE => 'green',
                                    \App\Models\CurrentAccount::STATUS_BLOCKED => 'red',
                                    \App\Models\CurrentAccount::STATUS_ARCHIVED => 'gray',
                                    default => 'amber',
                                };
                                $showStatementAction = ($canViewFinancialData ?? false) && (($summary['has_transactions'] ?? false) || ! $isArchivedLike);
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
                                    @if($isArchivedLike && $mainCompany && (int) $mainCompany['id'] !== (int) $linkedCompanyId)
                                        <div class="text-sm text-gray-600">
                                            Ana Cari Kart:
                                            <a href="{{ route('admin.companies.show', $mainCompany['id']) }}" style="color: var(--pd-blue); text-decoration:none;">{{ $mainCompany['name'] }}</a>
                                        </div>
                                    @endif
                                </td>
                                @if($activeTab === 'arsiv')
                                    <td>
                                        @if($mainCompany && (int) $mainCompany['id'] !== (int) $linkedCompanyId)
                                            <div class="font-medium">Ana Cari Kart</div>
                                            <a href="{{ route('admin.companies.show', $mainCompany['id']) }}" style="color: var(--pd-blue); text-decoration:none;">{{ $mainCompany['name'] }}</a>
                                        @else
                                            <span class="text-gray-600">Aynı kayıt üzerinde</span>
                                        @endif
                                    </td>
                                @endif
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
                                    {{ $taxIdentity ?: 'Kimlik bilgisi yok' }}
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="pd-badge pd-badge-{{ $statusTone }}">{{ $statusLabel }}</span>
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
                                @if($canViewFinancialData ?? false)
                                    <td>
                                        <div>@include('admin.current-accounts._money-display', ['label' => $summary['formatted_balance'] ?? '0,00 TL', 'amount' => $summary['balance'] ?? 0, 'hasMultipleCurrencies' => $summary['has_multiple_currencies'] ?? false])</div>
                                        @if(($summary['has_transactions'] ?? false) === false)
                                            <div class="text-sm text-gray-600">Hareket yok</div>
                                        @endif
                                    </td>
                                    <td><span class="pd-badge pd-badge-{{ $directionTone }}">{{ $summary['balance_direction_label'] ?? 'Kapalı' }}</span></td>
                                    <td>{{ $summary['open_transaction_count'] ?? 0 }}</td>
                                    <td>{{ $summary['last_transaction_label'] ?? 'Hareket yok' }}</td>
                                @endif
                                <td class="text-right">
                                    <div class="pd-actions-wrap">
                                        <a href="{{ $companyRoute }}" class="pd-btn pd-btn-light pd-btn-sm">{{ $linkedCompanyId ? 'Cari Kartı Aç' : 'Finans Detayı' }}</a>
                                        @if($showStatementAction)
                                            <a href="{{ $statementRoute }}" class="pd-btn pd-btn-primary pd-btn-sm">Ekstre</a>
                                        @endif
                                        @if($isArchivedLike && $duplicateRoute)
                                            <a href="{{ $duplicateRoute }}" class="pd-btn pd-btn-light pd-btn-sm">Benzer Cari Kontrolü</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ (($canViewFinancialData ?? false) ? 10 : 6) + ($activeTab === 'arsiv' ? 1 : 0) }}" class="text-center">Cari bakiye kaydı bulunamadı.</td>
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
    </div>

    <div class="pd-account-summary-panel">
        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Seçili Sekme Özeti</h3>
                <p class="pd-card-subtitle">Cari bakiye görünümünü sekmelere göre sade takip edin.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-info">
                    <div class="pd-summary-row"><span>Seçili Sekme</span><span class="pd-badge pd-badge-blue">{{ $selectedTabSummary['label'] ?? 'Aktif Bakiyeler' }}</span></div>
                    <div class="pd-summary-row"><span>Bu Sekmedeki Kayıt</span><span class="font-medium">{{ $selectedTabSummary['count'] ?? 0 }}</span></div>
                    <div class="pd-summary-row"><span>Aktif Bakiyeler</span><span class="font-medium">{{ $tabStats['aktif']['count'] ?? 0 }}</span></div>
                    <div class="pd-summary-row"><span>Açık Hareketler</span><span class="font-medium">{{ $tabStats['acik']['count'] ?? 0 }}</span></div>
                    <div class="pd-summary-row"><span>Vadesi Geçenler</span><span class="font-medium">{{ $tabStats['vadesi-gecen']['count'] ?? 0 }}</span></div>
                    <div class="pd-summary-row"><span>Pasif / Arşivlenenler</span><span class="font-medium">{{ $tabStats['arsiv']['count'] ?? 0 }}</span></div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Hızlı Geçişler</h3>
                <p class="pd-card-subtitle">Kimlik düzenleme ve rapor ekranlarına hızlı geçişler.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-list">
                    <a href="{{ route('admin.companies.index') }}" class="pd-summary-item">Cari Kartlar</a>
                    <a href="{{ route('admin.companies.create') }}" class="pd-summary-item">Yeni Cari Oluştur</a>
                    <a href="{{ route('admin.current-accounts.index', ['tab' => 'arsiv']) }}" class="pd-summary-item">Pasif / Arşivlenenler</a>
                    <a href="{{ route('admin.current-accounts.index', ['tab' => 'tumu']) }}" class="pd-summary-item">Tüm Cari Bakiyeler</a>
                </div>
                <div class="pd-note" style="margin-top: 14px;">Bu ekran cari kimlik düzenleme alanı değildir; finansal bakiye ve ekstre takibi için kullanılır.</div>
            </div>
        </div>
    </div>
</div>
@endsection
