@extends('layouts.prodelya-admin')

@section('title', $company->legal_name)
@section('page_title', $company->legal_name)
@section('page_subtitle', 'Cari Kart Detayı')

@section('page_actions')
    <div class="flex gap-3">
        <a href="{{ route('admin.companies.edit', $company) }}" class="pd-btn pd-btn-light">
            <svg class="pd-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Düzenle
        </a>
        <a href="{{ route('admin.companies.index') }}" class="pd-btn pd-btn-primary">Listeye Dön</a>
    </div>
@endsection

@section('content')
@php
    $phoneFormatter = app(\App\Services\PhoneNumberNormalizer::class);
    $quickPanelReturnUrl = request()->fullUrl();
    $quickPanelIsOpen = old() !== [] || request()->boolean('quick_panel');
    $companyRoles = $company->getRoleNames();
    $companyRoleBadgeColors = $company->getRoleBadgeColors();
    $roleSummary = count($companyRoles) > 0 ? implode(', ', $companyRoles) : 'Rol tanımlı değil';

    $companyTabs = [
        'genel' => 'Genel Özet',
        'ekstre' => 'Ekstre ve Ön Muhasebe',
        'yetkililer' => 'Yetkili ve Adresler',
        'portal' => 'Portal Kullanıcıları',
        'tedarikci' => 'Tedarikçi Eşleşme',
        'siparisler' => 'Siparişler',
        'benzer-cari' => 'Benzer Cari Kontrolü',
    ];
    $requestedTab = (string) request()->query('tab', 'genel');
    $activeCompanyTab = array_key_exists($requestedTab, $companyTabs) ? $requestedTab : 'genel';

    $balanceTone = match($linkedCurrentAccountSummary['balance_direction'] ?? 'closed') {
        'receivable' => 'green',
        'payable' => 'amber',
        'mixed' => 'gray',
        default => 'gray',
    };

    $accountStatusTone = match($linkedCurrentAccount->status ?? null) {
        \App\Models\CurrentAccount::STATUS_ACTIVE => 'green',
        \App\Models\CurrentAccount::STATUS_BLOCKED => 'red',
        \App\Models\CurrentAccount::STATUS_ARCHIVED => 'gray',
        default => 'amber',
    };

    $supplierStatusLabel = 'Tedarikçi rolü yok';
    $supplierStatusTone = 'gray';
    if ($company->hasRole('supplier') && $supplierMapping) {
        $supplierStatusLabel = ($duplicateSupplierSummary['status_label'] ?? null)
            ?: ($supplierMapping['is_active'] ? 'Güvenli eşleşme var' : 'Hazır ürün kaynağı pasif');
        $supplierStatusTone = $duplicateSupplierSummary['status_tone']
            ?? ($supplierMapping['is_active'] ? 'green' : 'amber');
    } elseif ($company->hasRole('supplier')) {
        $supplierStatusLabel = 'Hazır ürün kaynağı eşleşmesi bekliyor';
        $supplierStatusTone = 'amber';
    }

    $hasDuplicateCandidates = (bool) ($duplicateSupplierSummary['has_similar_companies'] ?? false);
    $duplicateCurrentCompany = $duplicateSupplierSummary['current_company'] ?? [];
    $mainDuplicateCompany = $duplicateSupplierSummary['main_company'] ?? null;
    $similarCompanies = $duplicateSupplierSummary['similar_companies'] ?? [];
    $duplicateChecklist = $duplicateSupplierSummary['checklist'] ?? [];
    $canArchiveDuplicate = (bool) ($duplicateSupplierSummary['can_archive'] ?? false);
    $archiveBlockedMessage = $duplicateSupplierSummary['archive_blocked_message'] ?? null;
    $isArchivedDuplicate = (bool) ($duplicateSupplierSummary['is_archived'] ?? false);
    $companyStatusLabel = $isArchivedDuplicate ? 'Arşivlendi' : ($company->status === 'active' ? 'Aktif' : 'Pasif');
    $companyStatusBadgeClass = $company->status === 'active' ? 'pd-badge-green' : 'pd-badge-gray';
    $archiveConfirmMessage = 'Bu işlem boş benzer cari kartını arşivler. Cari silinmez, finans geçmişi taşınmaz. Arşivlenen cari varsayılan listelerde aktif kayıt gibi görünmez. Devam etmek istiyor musunuz?';
@endphp

<style>
    .pd-company-layout { display:grid; grid-template-columns:minmax(0, 2fr) minmax(280px, 1fr); gap:16px; align-items:start; }
    .pd-company-tabs { display:flex; gap:8px; flex-wrap:wrap; }
    .pd-company-tab {
        display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:0 14px;
        border:1px solid var(--pd-line); border-radius:8px; background:#fff; color:#344054; text-decoration:none; font-size:13px; font-weight:600;
    }
    .pd-company-tab.is-active { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
    .pd-company-tab:hover { border-color:#bfd4ef; color:#1d4ed8; }
    .pd-section-stack { display:grid; gap:14px; }
    .pd-kpi-grid { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:12px; }
    .pd-kpi-card { padding:14px; border:1px solid var(--pd-line); border-radius:8px; background:#fff; }
    .pd-kpi-label { color:var(--pd-muted); font-size:12px; }
    .pd-kpi-value { margin-top:6px; font-weight:700; }
    .pd-summary-panel { position:sticky; top:16px; display:grid; gap:14px; }
    .pd-quick-action-row { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
    .pd-quick-panel { position:fixed; inset:0; z-index:60; }
    .pd-quick-panel__backdrop { position:absolute; inset:0; background:rgba(15, 23, 42, 0.38); }
    .pd-quick-panel__dialog {
        position:relative; width:min(980px, calc(100% - 24px)); max-height:calc(100vh - 32px); overflow:auto;
        margin:16px auto; background:#fff; border:1px solid var(--pd-line); border-radius:10px; padding:18px; box-shadow:0 22px 48px rgba(15, 23, 42, 0.18);
    }
    .pd-quick-panel__header { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px; }
    .pd-quick-panel__summary {
        display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:12px; margin-bottom:14px;
        padding:14px; border:1px solid var(--pd-line); border-radius:8px; background:#f8fafc;
    }
    .pd-quick-panel__label { color:var(--pd-muted); font-size:12px; }
    .pd-quick-panel__value { margin-top:6px; font-weight:700; }
    .pd-quick-panel__badges { margin-top:6px; display:flex; gap:8px; flex-wrap:wrap; }
    .pd-quick-panel__hint-box { padding:14px; border:1px dashed var(--pd-line); border-radius:8px; background:#fbfcfe; }
    .pd-quick-panel__footer { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; margin-top:16px; }
    @media (max-width: 1100px) {
        .pd-company-layout { grid-template-columns:1fr; }
        .pd-summary-panel { position:static; }
        .pd-kpi-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 900px) {
        .pd-quick-panel__summary { grid-template-columns:1fr; }
    }
    @media (max-width: 640px) {
        .pd-kpi-grid { grid-template-columns:1fr; }
    }
</style>

<div class="pd-company-layout">
    <div class="pd-section-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <div>
                        <div class="text-xs" style="color: var(--pd-muted);">Sekmeli Görünüm</div>
                        <div style="margin-top:6px; font-weight:700;">Sekmelerden hızlı geçiş yapın</div>
                        <div style="margin-top:6px; color: var(--pd-muted);">Yoğun bilgileri, Cari Ekstreyi ve diğer özetleri tek sayfada daha sade izlemek için sekmeleri kullanın.</div>
                    </div>
                    <div class="pd-company-tabs" role="tablist" aria-label="Cari kart sekmeleri">
                        @foreach($companyTabs as $tabKey => $tabLabel)
                            <a
                                href="{{ route('admin.companies.show', ['company' => $company, 'tab' => $tabKey]) }}"
                                class="pd-company-tab {{ $activeCompanyTab === $tabKey ? 'is-active' : '' }}"
                                data-company-tab="{{ $tabKey }}"
                                aria-current="{{ $activeCompanyTab === $tabKey ? 'page' : 'false' }}"
                            >
                                {{ $tabLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        @if($activeCompanyTab === 'genel')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Genel Özet</h3>
                    <p class="pd-card-subtitle">Cari kartın kimlik, iletişim, rol ve kısa finans görünümü tek alanda toplanır.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-grid pd-grid-2">
                        <div>
                            <div style="margin-bottom: 16px;">
                                <div class="text-xs" style="color: var(--pd-muted);">Cari Adı</div>
                                <div style="margin-top: 6px; font-weight: 600;">{{ $company->legal_name }}</div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <div class="text-xs" style="color: var(--pd-muted);">Kısa Ad</div>
                                <div style="margin-top: 6px;">{{ $company->short_name ?: '-' }}</div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <div class="text-xs" style="color: var(--pd-muted);">Cari Tipi</div>
                                <div style="margin-top: 6px;">{{ $roleSummary }}</div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <div class="text-xs" style="color: var(--pd-muted);">Rol Dağılımı</div>
                                <div style="margin-top: 6px; display:flex; gap:8px; flex-wrap:wrap;">
                                    @forelse($companyRoles as $index => $roleName)
                                        <span class="pd-badge pd-badge-{{ $companyRoleBadgeColors[$index] ?? 'gray' }}">{{ $roleName }}</span>
                                    @empty
                                        <span class="pd-badge pd-badge-gray">Rol tanımlı değil</span>
                                    @endforelse
                                </div>
                            </div>

                            @if($company->notes)
                                @php
                                    $companyNotes = str_contains($company->notes, 'Current account UI kaydından senkronlandı.')
                                        ? 'Cari kart mevcut finans kaydından eşitlendi.'
                                        : $company->notes;
                                @endphp
                                <div>
                                    <div class="text-xs" style="color: var(--pd-muted);">Not</div>
                                    <div style="margin-top: 6px;">{{ $companyNotes }}</div>
                                </div>
                            @endif
                        </div>

                        <div>
                            <div style="margin-bottom: 16px;">
                                <div class="text-xs" style="color: var(--pd-muted);">Durum</div>
                                <div style="margin-top: 6px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <span class="pd-badge {{ $companyStatusBadgeClass }}">
                                        {{ $companyStatusLabel }}
                                    </span>
                                    @if($company->risk_status)
                                        <span class="pd-badge pd-badge-amber">Risk: {{ $company->risk_status }}</span>
                                    @endif
                                    <span class="pd-badge {{ $company->portal_enabled ? 'pd-badge-blue' : 'pd-badge-gray' }}">
                                        {{ $company->portal_enabled ? 'Portal Açık' : 'Portal Kapalı' }}
                                    </span>
                                </div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <div class="text-xs" style="color: var(--pd-muted);">WhatsApp / Telefon</div>
                                <div style="margin-top: 6px;">
                                    @if($company->mobile)
                                        <div>WhatsApp: {{ $phoneFormatter->formatTurkishPhoneForDisplay($company->mobile) }}</div>
                                    @endif
                                    @if($company->phone)
                                        <div>Telefon: {{ $phoneFormatter->formatTurkishPhoneForDisplay($company->phone) }}</div>
                                    @endif
                                    @if(!$company->mobile && !$company->phone)
                                        <span style="color: #98a2b3;">İletişim bilgisi yok</span>
                                    @endif
                                </div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <div class="text-xs" style="color: var(--pd-muted);">E-posta</div>
                                <div style="margin-top: 6px;">
                                    @if($company->email)
                                        <a href="mailto:{{ $company->email }}" style="color: var(--pd-blue); text-decoration: none;">{{ $company->email }}</a>
                                    @else
                                        <span style="color: #98a2b3;">Belirtilmemiş</span>
                                    @endif
                                </div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <div class="text-xs" style="color: var(--pd-muted);">VKN / TCKN</div>
                                <div style="margin-top: 6px;">{{ $company->tax_number ?: '-' }}</div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <div class="text-xs" style="color: var(--pd-muted);">Vergi Dairesi</div>
                                <div style="margin-top: 6px;">{{ $company->tax_office ?: '-' }}</div>
                            </div>

                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Web Sitesi</div>
                                <div style="margin-top: 6px;">
                                    @if($company->website)
                                        <a href="{{ $company->website }}" target="_blank" rel="noreferrer" style="color: var(--pd-blue); text-decoration: none;">{{ $company->website }}</a>
                                    @else
                                        <span style="color: #98a2b3;">Belirtilmemiş</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    @if($linkedCurrentAccount)
                        <div style="margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--pd-line);">
                            <div class="pd-kpi-grid">
                                <div class="pd-kpi-card">
                                    <div class="pd-kpi-label">Güncel Bakiye</div>
                                    <div class="pd-kpi-value">@include('admin.current-accounts._money-display', ['label' => $linkedCurrentAccountSummary['formatted_balance'] ?? '0,00 TL', 'amount' => $linkedCurrentAccountSummary['balance'] ?? 0, 'hasMultipleCurrencies' => $linkedCurrentAccountSummary['has_multiple_currencies'] ?? false])</div>
                                </div>
                                <div class="pd-kpi-card">
                                    <div class="pd-kpi-label">Toplam Borç</div>
                                    <div class="pd-kpi-value">@include('admin.current-accounts._money-display', ['label' => $linkedCurrentAccountSummary['formatted_total_debit'] ?? '0,00 TL', 'amount' => $linkedCurrentAccountSummary['total_debit'] ?? 0, 'hasMultipleCurrencies' => $linkedCurrentAccountSummary['has_multiple_currencies'] ?? false])</div>
                                </div>
                                <div class="pd-kpi-card">
                                    <div class="pd-kpi-label">Toplam Alacak</div>
                                    <div class="pd-kpi-value">@include('admin.current-accounts._money-display', ['label' => $linkedCurrentAccountSummary['formatted_total_credit'] ?? '0,00 TL', 'amount' => $linkedCurrentAccountSummary['total_credit'] ?? 0, 'hasMultipleCurrencies' => $linkedCurrentAccountSummary['has_multiple_currencies'] ?? false])</div>
                                </div>
                                <div class="pd-kpi-card">
                                    <div class="pd-kpi-label">Açık Hareket</div>
                                    <div class="pd-kpi-value">{{ $linkedCurrentAccountSummary['open_transaction_count'] ?? 0 }}</div>
                                </div>
                                <div class="pd-kpi-card">
                                    <div class="pd-kpi-label">Son Hareket</div>
                                    <div class="pd-kpi-value">{{ $linkedCurrentAccountSummary['last_transaction_label'] ?? 'Hareket yok' }}</div>
                                </div>
                            </div>

                            <div class="pd-actions-wrap" style="margin-top: 16px;">
                                @if($canViewCurrentAccountTransactions ?? false)
                                    <a href="{{ route('admin.current-accounts.transactions.index', $linkedCurrentAccount) }}" class="pd-btn pd-btn-light pd-btn-sm">Ekstreye Git</a>
                                @endif
                                @if(($canManageCurrentAccountTransactions ?? false) && $linkedCurrentAccount)
                                    <button type="button" class="pd-btn pd-btn-primary pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['collection'] ?? \App\Models\CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT }}">Tahsilat Gir</button>
                                    <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['payment'] ?? \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}">Ödeme Yap</button>
                                    <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['debit'] ?? \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}">Yeni Hareket</button>
                                @endif
                            </div>
                        </div>
                    @elseif($canViewFinancialData)
                        <div class="pd-note" style="margin-top: 18px;">Bu cari kart için henüz finans hareketi bulunmuyor.</div>
                    @endif
                </div>
            </div>

            @if($company->hasRole('print_fason') || $company->hasRole('production_partner'))
                <div class="pd-card">
                    <div class="pd-card-header">
                        <h3 class="pd-card-title">Üretim ve Fason Bilgisi</h3>
                        <p class="pd-card-subtitle">Bu cari üretim ve fason süreçlerinde kullanılabilir.</p>
                    </div>
                    <div class="pd-card-body">
                        <div class="pd-grid pd-grid-2">
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Cari Rolleri</div>
                                <div style="margin-top: 6px; display: flex; gap: 8px; flex-wrap: wrap;">
                                    @if($company->hasRole('print_fason'))
                                        <span class="pd-badge pd-badge-purple">Fason Baskı Firması</span>
                                    @endif
                                    @if($company->hasRole('production_partner'))
                                        <span class="pd-badge pd-badge-amber">Fason Üretim Firması</span>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Kullanım</div>
                                <div style="margin-top: 6px; font-weight: 600;">Bu cari üretim ve baskı akışlarında seçilebilir.</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        @if($activeCompanyTab === 'ekstre')
            @if($linkedCurrentAccount)
                <div class="pd-card">
                    <div class="pd-card-header">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                            <div>
                                <h3 class="pd-card-title">Ekstre ve Ön Muhasebe</h3>
                                <p class="pd-card-subtitle">Gerçek cari hareketlerini filtreleyin, kısa ekstreyi inceleyin ve vade yaşlandırmasını takip edin.</p>
                            </div>
                            <div class="pd-actions-wrap">
                                @if($canViewCurrentAccountTransactions ?? false)
                                    <a href="{{ route('admin.current-accounts.transactions.index', $linkedCurrentAccount) }}" class="pd-btn pd-btn-light pd-btn-sm">Ekstreye Git</a>
                                @endif
                                <a href="{{ route('admin.current-accounts.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Cari Bakiyeler</a>
                                @if($canManageCurrentAccountTransactions ?? false)
                                    <button type="button" class="pd-btn pd-btn-primary pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['collection'] ?? \App\Models\CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT }}">Tahsilat Gir</button>
                                    <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['payment'] ?? \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}">Ödeme Yap</button>
                                    <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['debit'] ?? \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}">Yeni Hareket</button>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="pd-card-body">
                        @if($canViewFinancialData)
                            @php
                                $companyStatementExportFilters = array_filter([
                                    'from' => $statementFilters['from'] ?? null,
                                    'to' => $statementFilters['to'] ?? null,
                                    'transaction_type' => $statementFilters['type'] ?? null,
                                    'status' => $statementFilters['status'] ?? 'all',
                                    'search' => $statementFilters['search'] ?? null,
                                ], fn ($value) => $value !== null && $value !== '');
                                $companySummaryPdfRoute = route('admin.current-accounts.transactions.export.pdf', array_merge(['currentAccount' => $linkedCurrentAccount, 'mode' => 'summary'], $companyStatementExportFilters));
                                $companyDetailedPdfRoute = route('admin.current-accounts.transactions.export.pdf', array_merge(['currentAccount' => $linkedCurrentAccount, 'mode' => 'detailed'], $companyStatementExportFilters));
                                $companySummaryExcelRoute = route('admin.current-accounts.transactions.export.excel', array_merge(['currentAccount' => $linkedCurrentAccount, 'mode' => 'summary'], $companyStatementExportFilters));
                                $companyDetailedExcelRoute = route('admin.current-accounts.transactions.export.excel', array_merge(['currentAccount' => $linkedCurrentAccount, 'mode' => 'detailed'], $companyStatementExportFilters));
                            @endphp

                            <div class="pd-kpi-grid" style="margin-bottom: 16px;">
                                <div class="pd-kpi-card">
                                    <div class="pd-kpi-label">Güncel Bakiye</div>
                                    <div class="pd-kpi-value">@include('admin.current-accounts._money-display', ['label' => $linkedCurrentAccountSummary['formatted_balance'] ?? '0,00 TL', 'amount' => $linkedCurrentAccountSummary['balance'] ?? 0, 'hasMultipleCurrencies' => $linkedCurrentAccountSummary['has_multiple_currencies'] ?? false])</div>
                                </div>
                                <div class="pd-kpi-card">
                                    <div class="pd-kpi-label">Toplam Borç</div>
                                    <div class="pd-kpi-value">@include('admin.current-accounts._money-display', ['label' => $linkedCurrentAccountSummary['formatted_total_debit'] ?? '0,00 TL', 'amount' => $linkedCurrentAccountSummary['total_debit'] ?? 0, 'hasMultipleCurrencies' => $linkedCurrentAccountSummary['has_multiple_currencies'] ?? false])</div>
                                </div>
                                <div class="pd-kpi-card">
                                    <div class="pd-kpi-label">Toplam Alacak</div>
                                    <div class="pd-kpi-value">@include('admin.current-accounts._money-display', ['label' => $linkedCurrentAccountSummary['formatted_total_credit'] ?? '0,00 TL', 'amount' => $linkedCurrentAccountSummary['total_credit'] ?? 0, 'hasMultipleCurrencies' => $linkedCurrentAccountSummary['has_multiple_currencies'] ?? false])</div>
                                </div>
                                <div class="pd-kpi-card">
                                    <div class="pd-kpi-label">Vadesi Geçen</div>
                                    <div class="pd-kpi-value">@include('admin.current-accounts._money-display', ['label' => $linkedCurrentAccountSummary['formatted_overdue_amount'] ?? 'Yok', 'amount' => $linkedCurrentAccountSummary['overdue_amount'] ?? 0, 'hasMultipleCurrencies' => $linkedCurrentAccountSummary['has_multiple_currencies'] ?? false])</div>
                                </div>
                                <div class="pd-kpi-card">
                                    <div class="pd-kpi-label">Açık Hareket</div>
                                    <div class="pd-kpi-value">{{ $linkedCurrentAccountSummary['open_transaction_count'] ?? 0 }}</div>
                                </div>
                            </div>

                            <div class="pd-card" style="margin-bottom: 16px; border: 1px dashed var(--pd-line); box-shadow:none;">
                                <div class="pd-card-body">
                                    <form method="GET" action="{{ route('admin.companies.show', $company) }}">
                                        <input type="hidden" name="tab" value="ekstre">
                                        <div class="pd-form-grid-3">
                                            <div>
                                                <label class="text-sm font-medium">Tarih Başlangıç</label>
                                                <input type="date" name="statement_from" value="{{ $statementFilters['statement_from'] ?? '' }}">
                                            </div>
                                            <div>
                                                <label class="text-sm font-medium">Tarih Bitiş</label>
                                                <input type="date" name="statement_to" value="{{ $statementFilters['statement_to'] ?? '' }}">
                                            </div>
                                            <div>
                                                <label class="text-sm font-medium">Hareket Türü</label>
                                                <select name="statement_type">
                                                    <option value="">Tümü</option>
                                                    @foreach(\App\Models\CurrentAccountTransaction::typeLabels() as $value => $label)
                                                        <option value="{{ $value }}" @selected(($statementFilters['statement_type'] ?? '') === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="text-sm font-medium">Durum</label>
                                                <select name="statement_status">
                                                    <option value="all" @selected(($statementFilters['statement_status'] ?? 'all') === 'all')>Tümü</option>
                                                    <option value="open" @selected(($statementFilters['statement_status'] ?? '') === 'open')>Açık</option>
                                                    <option value="closed" @selected(($statementFilters['statement_status'] ?? '') === 'closed')>Kapalı / İşlendi</option>
                                                    <option value="overdue" @selected(($statementFilters['statement_status'] ?? '') === 'overdue')>Vadesi Geçen</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="text-sm font-medium">Belge / Sipariş / Açıklama</label>
                                                <input type="text" name="statement_search" value="{{ $statementFilters['statement_search'] ?? '' }}" placeholder="Sipariş no, talep no, açıklama...">
                                            </div>
                                            <div style="display:flex; align-items:end; gap:8px;">
                                                <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                                                <a href="{{ route('admin.companies.show', ['company' => $company, 'tab' => 'ekstre']) }}" class="pd-btn pd-btn-light">Sıfırla</a>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="pd-grid pd-grid-3" style="margin-bottom: 16px;">
                                <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                                    <div class="text-xs" style="color: var(--pd-muted);">Filtreli Hareket Toplamı</div>
                                    <div style="margin-top:6px;">@include('admin.current-accounts._money-display', ['label' => $statementFilteredSummary['formatted_balance'] ?? '0,00 TL', 'amount' => $statementFilteredSummary['balance'] ?? 0, 'hasMultipleCurrencies' => $statementFilteredSummary['has_multiple_currencies'] ?? false])</div>
                                    <div class="text-sm text-gray-600" style="margin-top:6px;">{{ $statementFilteredSummary['transaction_count'] ?? 0 }} kayıt</div>
                                </div>
                                <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                                    <div class="text-xs" style="color: var(--pd-muted);">Filtreli Borç</div>
                                    <div style="margin-top:6px;">@include('admin.current-accounts._money-display', ['label' => $statementFilteredSummary['formatted_total_debit'] ?? '0,00 TL', 'amount' => $statementFilteredSummary['total_debit'] ?? 0, 'hasMultipleCurrencies' => $statementFilteredSummary['has_multiple_currencies'] ?? false])</div>
                                </div>
                                <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                                    <div class="text-xs" style="color: var(--pd-muted);">Filtreli Alacak</div>
                                    <div style="margin-top:6px;">@include('admin.current-accounts._money-display', ['label' => $statementFilteredSummary['formatted_total_credit'] ?? '0,00 TL', 'amount' => $statementFilteredSummary['total_credit'] ?? 0, 'hasMultipleCurrencies' => $statementFilteredSummary['has_multiple_currencies'] ?? false])</div>
                                </div>
                            </div>

                            <div class="pd-grid pd-grid-2" style="margin-bottom: 16px;">
                                <div class="pd-card" style="border:1px dashed var(--pd-line); box-shadow:none;">
                                    <div class="pd-card-header">
                                        <h4 class="pd-card-title">Vade Yaşlandırma</h4>
                                        <p class="pd-card-subtitle">Yalnız açık hareketler dahil edilir.</p>
                                    </div>
                                    <div class="pd-card-body">
                                        <div class="pd-summary-info">
                                            @foreach(($statementAging['buckets'] ?? []) as $bucket)
                                                <div class="pd-summary-row">
                                                    <span>{{ $bucket['label'] }}</span>
                                                    <span class="font-medium">{{ $bucket['formatted_amount'] }} / {{ $bucket['count'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div class="pd-card" style="border:1px dashed var(--pd-line); box-shadow:none;">
                                    <div class="pd-card-header">
                                        <h4 class="pd-card-title">Ekstre Dışa Aktarma</h4>
                                        <p class="pd-card-subtitle">Filtrelenen hareketleri genel veya detaylı modda dışa aktarın.</p>
                                    </div>
                                    <div class="pd-card-body">
                                        <div class="pd-actions-wrap">
                                            <a href="{{ $companySummaryPdfRoute }}" class="pd-btn pd-btn-light pd-btn-sm">Ekstre PDF</a>
                                            <a href="{{ $companySummaryExcelRoute }}" class="pd-btn pd-btn-light pd-btn-sm">Ekstre Excel</a>
                                            <a href="{{ $companyDetailedPdfRoute }}" class="pd-btn pd-btn-light pd-btn-sm">Detaylı PDF</a>
                                            <a href="{{ $companyDetailedExcelRoute }}" class="pd-btn pd-btn-light pd-btn-sm">Detaylı Excel</a>
                                        </div>
                                        <div class="pd-note" style="margin-top: 12px;">Aktif filtreler export'a aynen taşınır.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="pd-table-wrap">
                                @if($statementOpeningBalance)
                                    <div class="pd-note" style="margin-bottom:12px;">
                                        <strong>Önceden Devreden:</strong> {{ $statementOpeningBalance['label'] }}
                                    </div>
                                @endif
                                <table class="pd-table">
                                    <thead>
                                        <tr>
                                            <th>İşlem Tarihi</th>
                                            <th>Hareket</th>
                                            <th>Belge / Sipariş</th>
                                            <th>Açıklama</th>
                                            <th>Vade</th>
                                            <th>Durum</th>
                                            <th>Borç</th>
                                            <th>Alacak</th>
                                            <th>Bakiye</th>
                                            <th class="text-right">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($statementTransactions as $transaction)
                                            @php
                                                $sourcePayment = $statementSourcePayments->get($transaction->source_id);
                                                $sourceOrder = $statementSourceOrders->get($transaction->source_id);
                                                $sourceProcurementItem = $statementSourceProcurementItems->get($transaction->source_id);
                                                $sourceProduction = $statementSourceProductions->get($transaction->source_id);
                                                $statementStatusTone = match($transaction->status) {
                                                    \App\Models\CurrentAccountTransaction::STATUS_CANCELLED => 'gray',
                                                    \App\Models\CurrentAccountTransaction::STATUS_PAID,
                                                    \App\Models\CurrentAccountTransaction::STATUS_CLOSED => 'green',
                                                    \App\Models\CurrentAccountTransaction::STATUS_PARTIALLY_PAID => 'amber',
                                                    default => 'blue',
                                                };
                                                $statementRowBalance = ($statementRunningBalances ?? [])[$transaction->id] ?? null;
                                            @endphp
                                            <tr @if($transaction->isCancelled()) style="opacity:.65;" @endif>
                                                <td>{{ optional($transaction->transaction_date)->format('d.m.Y') ?: '-' }}</td>
                                                <td>{{ $transaction->safeTypeLabel() }}</td>
                                                <td>
                                                    @if($transaction->source_type === \App\Services\OrderPaymentCurrentAccountSyncService::SOURCE_TYPE && $sourcePayment)
                                                        {{ $sourcePayment->order?->document_number ?: 'Sipariş #' . $sourcePayment->order_id }}
                                                    @elseif($transaction->source_type === \App\Services\OrderCurrentAccountDebitSyncService::SOURCE_TYPE && $sourceOrder)
                                                        {{ $sourceOrder->document_number ?: 'Sipariş #' . $sourceOrder->id }}
                                                    @elseif($transaction->source_type === \App\Services\SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE && $sourceProcurementItem)
                                                        {{ $sourceProcurementItem->request?->request_number ?: 'Talep #' . $sourceProcurementItem->supplier_procurement_request_id }}
                                                    @elseif($transaction->source_type === \App\Services\SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE && $sourceProduction)
                                                        {{ $sourceProduction->order?->document_number ?: 'Sipariş #' . $sourceProduction->order_id }}
                                                    @elseif($transaction->safeManualOrderNumber() || $transaction->safeManualDocumentNumber())
                                                        <div>{{ $transaction->safeManualOrderNumber() ?: '-' }}</div>
                                                        @if($transaction->safeManualDocumentNumber())
                                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Belge: {{ $transaction->safeManualDocumentNumber() }}</div>
                                                        @endif
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                                <td>
                                                    {{ $transaction->description ?: '-' }}
                                                    @if($transaction->source_type === \App\Services\OrderCurrentAccountDebitSyncService::SOURCE_TYPE && $sourceOrder)
                                                        <div class="text-xs text-gray-600" style="margin-top:4px;">Kaynak: Sipariş Müşteri Borcu / {{ $sourceOrder->document_number ?: 'Sipariş #' . $sourceOrder->id }}</div>
                                                    @endif
                                                    @if($transaction->safeManualPaymentMethodLabel())
                                                        <div class="text-xs text-gray-600" style="margin-top:4px;">Ödeme yöntemi: {{ $transaction->safeManualPaymentMethodLabel() }}</div>
                                                    @endif
                                                </td>
                                                <td>{{ optional($transaction->due_date)->format('d.m.Y') ?: '-' }}</td>
                                                <td><span class="pd-badge pd-badge-{{ $statementStatusTone }}">{{ $transaction->safeStatusLabel() }}</span></td>
                                                <td>{{ $transaction->isDebit() ? $transaction->formattedAmount() : '-' }}</td>
                                                <td>{{ $transaction->isCredit() ? $transaction->formattedAmount() : '-' }}</td>
                                                <td>
                                                    @if($statementRowBalance)
                                                        <div>{{ $statementRowBalance['label'] }}</div>
                                                        @if($statementRowBalance['direction_label'] === 'Kapalı')
                                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Kapalı</div>
                                                        @endif
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                                <td class="text-right">
                                                    <a href="{{ route('admin.current-accounts.transactions.index', $linkedCurrentAccount) }}" class="pd-btn pd-btn-light pd-btn-sm">Ekstre</a>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="10" class="text-center">Henüz cari hareket bulunmuyor.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="pd-note">Finans bilgileri yalnız yetkili kullanıcılar için görünür.</div>
                        @endif
                    </div>
                </div>
            @else
                <div class="pd-card">
                    <div class="pd-card-body">
                        <div class="pd-note">Bu cari kart için bağlı cari ekstre kaydı bulunmuyor.</div>
                    </div>
                </div>
            @endif
        @endif

        @if($activeCompanyTab === 'yetkililer')
            <div class="pd-card">
                <div class="pd-card-header">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                        <div>
                            <h3 class="pd-card-title">Yetkili Kişiler</h3>
                            <p class="pd-card-subtitle">Firma ile ilgili operasyonel ve satış iletişimleri.</p>
                        </div>
                        <a href="#contact-create-form" class="pd-btn pd-btn-light pd-btn-sm">Yetkili Ekle</a>
                    </div>
                </div>
                <div class="pd-card-body" id="company-contacts">
                    @if($company->contacts->count() > 0)
                        <div class="pd-table-wrap">
                            <table class="pd-table">
                                <thead>
                                    <tr>
                                        <th>Ad Soyad</th>
                                        <th>Unvan</th>
                                        <th>İletişim</th>
                                        <th class="text-right">Aksiyon</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($company->contacts as $contact)
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600;">{{ $contact->name }}</div>
                                                @if($contact->is_primary)
                                                    <span class="pd-badge pd-badge-blue" style="margin-top: 6px;">Birincil</span>
                                                @endif
                                            </td>
                                            <td>{{ $contact->title ?: '-' }}</td>
                                            <td>
                                                @if($contact->email)
                                                    <div>{{ $contact->email }}</div>
                                                @endif
                                                @if($contact->phone)
                                                    <div>{{ $contact->phone }}</div>
                                                @endif
                                                @if($contact->mobile)
                                                    <div>{{ $contact->mobile }}</div>
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                <span class="text-xs" style="color: var(--pd-muted);">İletişim kaydı</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="pd-note">Henüz yetkili kişi eklenmemiş.</div>
                    @endif

                    <div id="contact-create-form" style="margin-top: 18px; padding: 16px; border: 1px solid var(--pd-line); border-radius: 10px; background: #fafafa;">
                        <h4 style="margin: 0 0 8px; font-size: 15px;">Yeni Yetkili Kişi</h4>
                        <p style="margin: 0 0 14px; color: var(--pd-muted); font-size: 13px;">Firma iletişim kayıtlarını burada yönetin. Bu alan portal kullanıcısı oluşturmaz.</p>

                        <form method="POST" action="{{ route('admin.companies.contacts.store', $company) }}" style="display: grid; gap: 12px;">
                            @csrf
                            <div class="pd-grid pd-grid-2">
                                <div>
                                    <label for="contact_name" class="text-xs" style="color: var(--pd-muted);">Ad Soyad</label>
                                    <input id="contact_name" name="contact_name" type="text" value="{{ old('contact_name') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('contact_name')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label for="contact_title" class="text-xs" style="color: var(--pd-muted);">Ünvan / Görev</label>
                                    <input id="contact_title" name="contact_title" type="text" value="{{ old('contact_title') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('contact_title')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="pd-grid pd-grid-2">
                                <div>
                                    <label for="contact_email" class="text-xs" style="color: var(--pd-muted);">E-posta</label>
                                    <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('contact_email')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label for="contact_phone" class="text-xs" style="color: var(--pd-muted);">Normal Telefon</label>
                                    <input id="contact_phone" name="contact_phone" type="text" value="{{ old('contact_phone') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('contact_phone')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="pd-grid pd-grid-2">
                                <div>
                                    <label for="contact_mobile" class="text-xs" style="color: var(--pd-muted);">WhatsApp Cep Telefonu</label>
                                    <div style="display:flex; align-items:center; border:1px solid #d0d5dd; border-radius:10px; overflow:hidden; background:#fff; margin-top: 6px;">
                                        <span style="display:inline-flex; align-items:center; gap:8px; padding:0 12px; min-height:42px; background:#f8fafc; border-right:1px solid #e4e7ec; color:#344054; font-size:13px; white-space:nowrap;">🇹🇷 +90</span>
                                        <input id="contact_mobile" name="contact_mobile" type="text" value="{{ old('contact_mobile') }}" class="pd-input" style="width: 100%; border:0; border-radius:0; margin-top: 0;">
                                    </div>
                                    @error('contact_mobile')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                                <label style="display: flex; align-items: center; gap: 8px; margin-top: 22px; font-size: 13px; color: var(--pd-muted);">
                                    <input type="checkbox" name="contact_is_primary" value="1" {{ old('contact_is_primary') ? 'checked' : '' }}>
                                    Varsayılan yetkili olarak işaretle
                                </label>
                            </div>

                            <div>
                                <button type="submit" class="pd-btn pd-btn-primary">Yetkiliyi Kaydet</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="pd-card">
                <div class="pd-card-header">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                        <div>
                            <h3 class="pd-card-title">Adresler</h3>
                            <p class="pd-card-subtitle">Fatura, teslimat ve varsayılan adres kayıtları.</p>
                        </div>
                        <a href="#address-create-form" class="pd-btn pd-btn-light pd-btn-sm">Adres Ekle</a>
                    </div>
                </div>
                <div class="pd-card-body" id="company-addresses">
                    @if($company->addresses->count() > 0)
                        <div class="pd-grid">
                            @foreach($company->addresses as $address)
                                <div style="padding: 16px; border: 1px solid var(--pd-line); border-left: 4px solid var(--pd-blue); border-radius: 8px;">
                                    <div style="display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                                        <div>
                                            <div style="font-weight: 600;">{{ $address->title ?: 'Adres' }}</div>
                                            <div style="margin-top: 8px; color: var(--pd-muted);">{{ $address->full_address }}</div>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;">
                                                <span class="pd-badge pd-badge-blue">
                                                    {{ match($address->address_type) {
                                                        'billing' => 'Fatura',
                                                        'delivery' => 'Teslimat',
                                                        'invoice' => 'Resmi Fatura',
                                                        'shipping' => 'Sevkiyat',
                                                        default => 'Genel',
                                                    } }}
                                                </span>
                                                @if($address->is_default)
                                                    <span class="pd-badge pd-badge-amber">Varsayılan</span>
                                                @endif
                                            </div>
                                        </div>
                                        <span class="text-xs" style="color: var(--pd-muted);">Adres kaydı</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="pd-note">Henüz adres eklenmemiş.</div>
                    @endif

                    <div id="address-create-form" style="margin-top: 18px; padding: 16px; border: 1px solid var(--pd-line); border-radius: 10px; background: #fafafa;">
                        <h4 style="margin: 0 0 8px; font-size: 15px;">Yeni Adres</h4>
                        <p style="margin: 0 0 14px; color: var(--pd-muted); font-size: 13px;">Fatura, teslimat veya genel kullanım adreslerini aynı ekrandan ekleyin.</p>

                        <form method="POST" action="{{ route('admin.companies.addresses.store', $company) }}" style="display: grid; gap: 12px;">
                            @csrf
                            <div class="pd-grid pd-grid-2">
                                <div>
                                    <label for="address_title" class="text-xs" style="color: var(--pd-muted);">Adres Başlığı</label>
                                    <input id="address_title" name="address_title" type="text" value="{{ old('address_title') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('address_title')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label for="address_type" class="text-xs" style="color: var(--pd-muted);">Adres Tipi</label>
                                    <select id="address_type" name="address_type" class="pd-input" style="width: 100%; margin-top: 6px;">
                                        <option value="other" {{ old('address_type', 'other') === 'other' ? 'selected' : '' }}>Genel</option>
                                        <option value="billing" {{ old('address_type') === 'billing' ? 'selected' : '' }}>Fatura</option>
                                        <option value="delivery" {{ old('address_type') === 'delivery' ? 'selected' : '' }}>Teslimat</option>
                                        <option value="invoice" {{ old('address_type') === 'invoice' ? 'selected' : '' }}>Resmi Fatura</option>
                                        <option value="shipping" {{ old('address_type') === 'shipping' ? 'selected' : '' }}>Sevkiyat</option>
                                    </select>
                                    @error('address_type')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div>
                                <label for="address_body" class="text-xs" style="color: var(--pd-muted);">Adres</label>
                                <textarea id="address_body" name="address_body" class="pd-input" style="width: 100%; margin-top: 6px; min-height: 92px;">{{ old('address_body') }}</textarea>
                                @error('address_body')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                            </div>

                            <div class="pd-grid pd-grid-2">
                                <div>
                                    <label for="address_district" class="text-xs" style="color: var(--pd-muted);">İlçe</label>
                                    <input id="address_district" name="address_district" type="text" value="{{ old('address_district') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('address_district')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label for="address_city" class="text-xs" style="color: var(--pd-muted);">İl</label>
                                    <input id="address_city" name="address_city" type="text" value="{{ old('address_city') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('address_city')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="pd-grid pd-grid-2">
                                <div>
                                    <label for="address_country" class="text-xs" style="color: var(--pd-muted);">Ülke</label>
                                    <input id="address_country" name="address_country" type="text" value="{{ old('address_country', 'Türkiye') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('address_country')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label for="address_postal_code" class="text-xs" style="color: var(--pd-muted);">Posta Kodu</label>
                                    <input id="address_postal_code" name="address_postal_code" type="text" value="{{ old('address_postal_code') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('address_postal_code')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--pd-muted);">
                                <input type="checkbox" name="address_is_default" value="1" {{ old('address_is_default') ? 'checked' : '' }}>
                                Varsayılan adres olarak işaretle
                            </label>

                            <div>
                                <button type="submit" class="pd-btn pd-btn-primary">Adresi Kaydet</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        @if($activeCompanyTab === 'portal')
            <div class="pd-card">
                <div class="pd-card-header">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                        <div>
                            <h3 class="pd-card-title">Portal Kullanıcıları</h3>
                            <p class="pd-card-subtitle">Müşteri portalına giriş yapacak kullanıcıları yönetin.</p>
                        </div>
                        <span class="pd-badge {{ $company->portal_enabled ? 'pd-badge-green' : 'pd-badge-amber' }}">
                            {{ $company->portal_enabled ? 'Portal Açık' : 'Portal Kapalı' }}
                        </span>
                    </div>
                </div>
                <div class="pd-card-body">
                    @if(session('portal_invite_link'))
                        <div class="pd-note" style="margin-bottom: 16px;">
                            <strong>Güvenli davet bağlantısı:</strong>
                            <div style="margin-top: 8px; word-break: break-all;">{{ session('portal_invite_link') }}</div>
                        </div>
                    @endif

                    @if(!$company->portal_enabled)
                        <div class="pd-note" style="margin-bottom: 16px;">Portal kapalıysa davet edilen kullanıcı giriş yapamaz.</div>
                    @endif

                    <div class="pd-grid pd-grid-2" style="margin-bottom: 20px;">
                        <div style="padding: 16px; border: 1px solid var(--pd-line); border-radius: 10px; background: #fafafa;">
                            <h4 style="margin: 0 0 8px; font-size: 15px;">Yeni Portal Kullanıcısı</h4>
                            <p style="margin: 0 0 14px; color: var(--pd-muted); font-size: 13px;">Firma yetkilisi seçildiğinde ad, e-posta ve telefon alanları otomatik doldurulur.</p>

                            <form method="POST" action="{{ route('admin.companies.portal-users.store', $company) }}" style="display: grid; gap: 12px;">
                                @csrf
                                <div>
                                    <label for="portal_user_name" class="text-xs" style="color: var(--pd-muted);">Ad Soyad</label>
                                    <input id="portal_user_name" name="name" type="text" value="{{ old('name') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('name')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label for="portal_user_email" class="text-xs" style="color: var(--pd-muted);">E-posta</label>
                                    <input id="portal_user_email" name="email" type="email" value="{{ old('email') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('email')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label for="portal_user_phone" class="text-xs" style="color: var(--pd-muted);">Telefon</label>
                                    <input id="portal_user_phone" name="phone" type="text" value="{{ old('phone') }}" class="pd-input" style="width: 100%; margin-top: 6px;">
                                    @error('phone')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label for="portal_user_contact" class="text-xs" style="color: var(--pd-muted);">Firma Yetkilisi</label>
                                    <select id="portal_user_contact" name="company_contact_id" class="pd-input" style="width: 100%; margin-top: 6px;">
                                        <option value="">Yetkili seçmeden devam et</option>
                                        @foreach($company->contacts as $contact)
                                            <option
                                                value="{{ $contact->id }}"
                                                data-contact-name="{{ $contact->name }}"
                                                data-contact-email="{{ $contact->email }}"
                                                data-contact-phone="{{ $contact->mobile ?: $contact->phone }}"
                                                {{ (string) old('company_contact_id') === (string) $contact->id ? 'selected' : '' }}>
                                                {{ $contact->name }}{{ $contact->email ? ' - ' . $contact->email : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('company_contact_id')<div class="text-xs" style="color: #b42318; margin-top: 6px;">{{ $message }}</div>@enderror
                                </div>
                                <div id="portal-user-contact-warning" class="pd-note" style="display: none; margin: 0; border-color: #fde68a; background: #fffbeb; color: #92400e;">
                                    Seçili yetkilinin e-posta adresi yok. Portal daveti için e-posta girmeniz gerekir.
                                </div>
                                <button type="submit" class="pd-btn pd-btn-primary">Kullanıcı Oluştur ve Davet Et</button>
                            </form>
                        </div>

                        <div style="padding: 16px; border: 1px solid var(--pd-line); border-radius: 10px; background: #fafafa;">
                            <h4 style="margin: 0 0 8px; font-size: 15px;">Portal Özeti</h4>
                            <div class="pd-summary-info">
                                <div class="pd-summary-row">
                                    <span>Toplam kullanıcı</span>
                                    <span class="font-medium">{{ $company->portalUsers->count() }}</span>
                                </div>
                                <div class="pd-summary-row">
                                    <span>Aktif</span>
                                    <span class="font-medium">{{ $company->portalUsers->where('status', \App\Models\CustomerPortalUser::STATUS_ACTIVE)->count() }}</span>
                                </div>
                                <div class="pd-summary-row">
                                    <span>Davet bekleyen</span>
                                    <span class="font-medium">{{ $company->portalUsers->where('status', \App\Models\CustomerPortalUser::STATUS_INVITED)->count() }}</span>
                                </div>
                                <div class="pd-summary-row">
                                    <span>Şifre kuruldu</span>
                                    <span class="font-medium">{{ $company->portalUsers->filter(fn ($user) => $user->password_set_at !== null)->count() }}</span>
                                </div>
                            </div>
                            <p style="margin: 14px 0 0; color: var(--pd-muted); font-size: 13px;">
                                Portal erişimi açık olan firmalara müşteri portal kullanıcısı tanımlanabilir.
                            </p>
                        </div>
                    </div>

                    @if($company->portalUsers->count() > 0)
                        <div class="pd-table-wrap">
                            <table class="pd-table">
                                <thead>
                                    <tr>
                                        <th>Ad</th>
                                        <th>E-posta</th>
                                        <th>Yetkili</th>
                                        <th>Durum</th>
                                        <th>Son Giriş</th>
                                        <th class="text-right">Aksiyon</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($company->portalUsers as $portalUser)
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600;">{{ $portalUser->safeDisplayName() }}</div>
                                                <div class="text-xs" style="color: var(--pd-muted); margin-top: 4px;">
                                                    {{ $portalUser->password_set_at ? 'Şifre belirlendi' : 'Şifre bekleniyor' }}
                                                </div>
                                            </td>
                                            <td>{{ $portalUser->email }}</td>
                                            <td>{{ $portalUser->companyContact?->name ?: '-' }}</td>
                                            <td>
                                                <span class="pd-badge {{
                                                    $portalUser->status === \App\Models\CustomerPortalUser::STATUS_ACTIVE ? 'pd-badge-green' :
                                                    ($portalUser->status === \App\Models\CustomerPortalUser::STATUS_INVITED ? 'pd-badge-blue' :
                                                    ($portalUser->status === \App\Models\CustomerPortalUser::STATUS_SUSPENDED ? 'pd-badge-amber' : 'pd-badge-gray'))
                                                }}">
                                                    {{ $portalUser->safeStatusLabel() }}
                                                </span>
                                            </td>
                                            <td>{{ $portalUser->last_login_at?->format('d.m.Y H:i') ?: '-' }}</td>
                                            <td class="text-right">
                                                <div style="display: inline-flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">
                                                    <form method="POST" action="{{ route('admin.companies.portal-users.resend-invite', [$company, $portalUser]) }}">
                                                        @csrf
                                                        <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Davet Gönder</button>
                                                    </form>
                                                    @if($portalUser->status !== \App\Models\CustomerPortalUser::STATUS_ACTIVE)
                                                        <form method="POST" action="{{ route('admin.companies.portal-users.toggle-status', [$company, $portalUser]) }}">
                                                            @csrf
                                                            <input type="hidden" name="status" value="{{ \App\Models\CustomerPortalUser::STATUS_ACTIVE }}">
                                                            <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Aktif Et</button>
                                                        </form>
                                                    @endif
                                                    @if($portalUser->status !== \App\Models\CustomerPortalUser::STATUS_PASSIVE)
                                                        <form method="POST" action="{{ route('admin.companies.portal-users.toggle-status', [$company, $portalUser]) }}">
                                                            @csrf
                                                            <input type="hidden" name="status" value="{{ \App\Models\CustomerPortalUser::STATUS_PASSIVE }}">
                                                            <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Pasifleştir</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="pd-note">Bu firma için henüz portal kullanıcısı oluşturulmamış.</div>
                    @endif
                </div>
            </div>
        @endif

        @if($activeCompanyTab === 'tedarikci')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Tedarikçi Eşleşme</h3>
                    <p class="pd-card-subtitle">Bu cari kartın tedarikçi işlemlerinde nasıl kullanılacağını sade dille gösterir.</p>
                </div>
                <div class="pd-card-body">
                    @if($company->hasRole('supplier'))
                        <div class="pd-grid pd-grid-2" style="margin-bottom: 16px;">
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Hazır Ürün Kaynağı</div>
                                <div style="margin-top: 6px; font-weight: 600;">{{ $supplierMapping['supplier_name'] ?? 'Henüz seçilmedi' }}</div>
                            </div>
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Eşleşme Durumu</div>
                                <div style="margin-top: 6px;">
                                    <span class="pd-badge pd-badge-{{ $supplierStatusTone }}">{{ $supplierStatusLabel }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Tedarik Ekranı Kullanımı</div>
                                <div style="margin-top: 6px;">
                                    @if($isArchivedDuplicate)
                                        Arşivlenen benzer kayıt tedarik ekranında aktif seçim olarak kullanılmaz
                                    @elseif($supplierMapping && $supplierMapping['can_request_purchase'])
                                        Tedarik ekranında kullanılabilir
                                    @elseif($supplierMapping)
                                        Satın alma yetkisi sınırlı
                                    @else
                                        Hazır ürün kaynağı eşleştiğinde kullanılabilir
                                    @endif
                                </div>
                            </div>
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Kullanım Özeti</div>
                                <div style="margin-top: 6px;">
                                    {{ $isArchivedDuplicate ? 'Bu cari arşivlenen benzer kayıt olarak tutulur.' : 'Bu cari tedarikçi işlemlerinde kullanılacak.' }}
                                </div>
                            </div>
                        </div>

                        @if($isArchivedDuplicate)
                            <div class="pd-note">Bu benzer cari arşivlendiği için aktif tedarikçi eşleşmesi olarak kullanılmaz.</div>
                        @elseif($supplierMapping)
                            <div class="pd-note">
                                @if($supplierMapping['is_active'])
                                    Hazır ürün kaynağı bu Abone Firmada aktif durumdadır.
                                @else
                                    Hazır ürün kaynağı eşleşmiş ancak erişim pasif görünüyor.
                                @endif
                            </div>
                        @else
                            <div class="pd-note" style="border-color: #fde68a; background: #fffbeb; color: #92400e;">
                                Bu cari tedarikçi olarak işaretli fakat hazır ürün kaynağı eşleşmemiş.
                            </div>
                        @endif
                    @else
                        <div class="pd-note">Bu cari kartta tedarikçi rolü bulunmuyor.</div>
                    @endif
                </div>
            </div>
        @endif

        @if($activeCompanyTab === 'siparisler')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Siparişler</h3>
                    <p class="pd-card-subtitle">Cari karta bağlı sipariş yoğunluğunu kısa listede izleyin.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-grid pd-grid-3" style="margin-bottom: 16px;">
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Toplam Sipariş</div>
                            <div style="margin-top: 6px; font-size: 22px; font-weight: 700;">{{ $company->customerOrders->count() }}</div>
                        </div>
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Açık Sipariş</div>
                            <div style="margin-top: 6px;">{{ $company->customerOrders->where('status', '!=', 'cancelled')->count() }}</div>
                        </div>
                        <div>
                            <div class="text-xs" style="color: var(--pd-muted);">Son Sipariş</div>
                            <div style="margin-top: 6px;">
                                @if($lastOrder = $company->customerOrders->first())
                                    {{ $lastOrder->document_number }} ({{ $lastOrder->created_at->format('d.m.Y') }})
                                @else
                                    Henüz sipariş yok
                                @endif
                            </div>
                        </div>
                    </div>

                    @if($company->customerOrders->count() > 0)
                        <div class="pd-table-wrap">
                            <table class="pd-table">
                                <thead>
                                    <tr>
                                        <th>Sipariş No</th>
                                        <th>Tarih</th>
                                        <th>Durum</th>
                                        <th>Tutar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($company->customerOrders as $order)
                                        <tr>
                                            <td>{{ $order->document_number }}</td>
                                            <td>{{ $order->created_at->format('d.m.Y') }}</td>
                                            <td><span class="pd-badge pd-badge-gray">{{ $order->status }}</span></td>
                                            <td>{{ $canViewFinancialData ? '-' : 'Gizli' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="pd-note">Henüz sipariş bulunmuyor.</div>
                    @endif
                </div>
            </div>
        @endif

        @if($activeCompanyTab === 'benzer-cari')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Benzer Cari Kontrolü</h3>
                    <p class="pd-card-subtitle">Benzer kayıt ihtimali varsa güvenli değerlendirme notları burada görünür.</p>
                </div>
                <div class="pd-card-body">
                    @if($duplicateSupplierSummary)
                        <div class="pd-grid pd-grid-2" style="margin-bottom: 16px;">
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Bu Kayıt</div>
                                <div style="margin-top: 6px; display:flex; gap:8px; flex-wrap:wrap;">
                                    @if($duplicateCurrentCompany['is_main_company'] ?? false)
                                        <span class="pd-badge pd-badge-blue">Ana Cari Kart</span>
                                    @elseif($duplicateCurrentCompany['is_similar_company'] ?? false)
                                        <span class="pd-badge pd-badge-amber">Benzer Cari</span>
                                        <span class="pd-badge pd-badge-gray">Çift kayıt adayı</span>
                                    @else
                                        <span class="pd-badge pd-badge-green">Güvenli kayıt</span>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Ana Cari Kart</div>
                                <div style="margin-top: 6px;">
                                    @if($mainDuplicateCompany)
                                        <a href="{{ route('admin.companies.show', $mainDuplicateCompany['id']) }}" style="color: var(--pd-blue); text-decoration:none;">{{ $mainDuplicateCompany['name'] }}</a>
                                    @else
                                        Belirlenemedi
                                    @endif
                                </div>
                            </div>
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Finans Geçmişi</div>
                                <div style="margin-top: 6px;">
                                    {{ ($duplicateCurrentCompany['has_financial_history'] ?? false) ? 'Finans hareketi var' : 'Finans hareketi yok' }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Operasyon Bağlantısı</div>
                                <div style="margin-top: 6px;">
                                    {{ ($duplicateCurrentCompany['has_operational_links'] ?? false) ? 'Sipariş veya tedarik bağlantısı var' : 'Tedarik bağlantısı yok' }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Arşivleme Uygunluğu</div>
                                <div style="margin-top: 6px;">
                                    {{ $duplicateSupplierSummary['status_label'] ?? (($duplicateCurrentCompany['is_archive_candidate'] ?? false) ? 'Arşivlemeye uygun' : 'Silme yapılmaz') }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs" style="color: var(--pd-muted);">Kısa Risk Özeti</div>
                                <div style="margin-top: 6px;">
                                    {{ ($duplicateCurrentCompany['transaction_count'] ?? 0) > 0 ? 'Finans geçmişi nedeniyle dikkatli değerlendirme gerekir.' : 'Yalnız link kontrolü gerektiren sade kayıt.' }}
                                </div>
                            </div>
                        </div>

                        @if(count($duplicateChecklist) > 0)
                            <div class="pd-card" style="border:1px dashed var(--pd-line); box-shadow:none; margin-bottom: 16px;">
                                <div class="pd-card-header">
                                    <h4 class="pd-card-title">Güvenlik Kontrol Listesi</h4>
                                    <p class="pd-card-subtitle">Yalnız boş ve bağlantısız benzer kayıtlar arşivlenebilir.</p>
                                </div>
                                <div class="pd-card-body">
                                    <div class="pd-summary-list">
                                        @foreach($duplicateChecklist as $checkItem)
                                            <div class="pd-summary-row" style="align-items:flex-start; gap:16px;">
                                                <span>
                                                    <strong>{{ $checkItem['label'] }}</strong>
                                                    <div class="text-xs" style="margin-top:4px; color: var(--pd-muted);">{{ $checkItem['detail'] }}</div>
                                                </span>
                                                <span class="pd-badge {{ $checkItem['is_clear'] ? 'pd-badge-green' : 'pd-badge-amber' }}">{{ $checkItem['status_label'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if(count($similarCompanies) > 0)
                            <div class="pd-card" style="border:1px dashed var(--pd-line); box-shadow:none; margin-bottom: 16px;">
                                <div class="pd-card-header">
                                    <h4 class="pd-card-title">Benzer Kayıtlar</h4>
                                    <p class="pd-card-subtitle">Aynı tedarikçiyle ilişkili olabilecek diğer cari kartlar.</p>
                                </div>
                                <div class="pd-card-body">
                                    <div class="pd-summary-list">
                                        @foreach($similarCompanies as $similarCompany)
                                            <div class="pd-summary-row">
                                                <span>
                                                    <a href="{{ route('admin.companies.show', ['company' => $similarCompany['id'], 'tab' => 'benzer-cari']) }}" style="color: var(--pd-blue); text-decoration:none;">
                                                        {{ $similarCompany['name'] }}
                                                    </a>
                                                </span>
                                                <span class="pd-badge {{ $similarCompany['is_archive_candidate'] ? 'pd-badge-amber' : 'pd-badge-gray' }}">
                                                    {{ ($similarCompany['is_archived'] ?? false) ? 'Arşivlendi' : ($similarCompany['is_archive_candidate'] ? 'Boş duplicate adayı' : 'İnceleme gerekli') }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="pd-card" style="border:1px solid var(--pd-line); box-shadow:none; margin-bottom: 16px;">
                            <div class="pd-card-header">
                                <h4 class="pd-card-title">Aksiyon</h4>
                                <p class="pd-card-subtitle">Yalnız boş benzer cari kayıtları kullanıcı onayıyla arşivlenir.</p>
                            </div>
                            <div class="pd-card-body">
                                @if($canArchiveDuplicate)
                                    <form
                                        method="POST"
                                        action="{{ route('admin.companies.archive-duplicate', $company) }}"
                                        onsubmit="return confirm(@js($archiveConfirmMessage));"
                                    >
                                        @csrf
                                        <button type="submit" class="pd-btn pd-btn-light">Boş Benzer Cariyi Arşivle</button>
                                    </form>
                                @else
                                    <div class="pd-note" style="border-color: #fde68a; background: #fffbeb; color: #92400e;">
                                        {{ $archiveBlockedMessage ?: 'Bu kayıt otomatik arşivlenemez. Manuel inceleme gerekir.' }}
                                        @if(count($duplicateSupplierSummary['blocking_reasons'] ?? []) > 0)
                                            @foreach($duplicateSupplierSummary['blocking_reasons'] as $blockingReason)
                                                <div style="margin-top:6px;">- {{ $blockingReason }}</div>
                                            @endforeach
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if(count($duplicateSupplierSummary['warnings'] ?? []) > 0)
                            <div class="pd-note" style="border-color: #fde68a; background: #fffbeb; color: #92400e;">
                                @foreach($duplicateSupplierSummary['warnings'] as $warning)
                                    <div>{{ $warning }}</div>
                                @endforeach
                            </div>
                        @elseif(! $hasDuplicateCandidates)
                            <div class="pd-note">Bu cari için benzer kayıt uyarısı bulunmuyor.</div>
                        @endif
                    @else
                        <div class="pd-note">Bu cari için benzer kayıt uyarısı bulunmuyor.</div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div class="pd-summary-panel">
        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Kısa Bilgi</h3>
                <p class="pd-card-subtitle">Cari kartın temel durum ve özet görünümü.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Durum</span>
                        <span class="pd-badge {{ $companyStatusBadgeClass }}">
                            {{ $companyStatusLabel }}
                        </span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Roller</span>
                        <span class="font-medium">{{ count($companyRoles) > 0 ? count($companyRoles) . ' rol' : 'Yok' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Rol Dağılımı</span>
                        <span class="font-medium">{{ count($companyRoles) > 0 ? implode(', ', $companyRoles) : 'Rol tanımlı değil' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Portal</span>
                        <span class="pd-badge {{ $company->portal_enabled ? 'pd-badge-green' : 'pd-badge-gray' }}">
                            {{ $company->portal_enabled ? 'Açık' : 'Kapalı' }}
                        </span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Güncel Bakiye</span>
                        <span>@include('admin.current-accounts._money-display', ['label' => $linkedCurrentAccountSummary['formatted_balance'] ?? '-', 'amount' => $linkedCurrentAccountSummary['balance'] ?? 0, 'hasMultipleCurrencies' => $linkedCurrentAccountSummary['has_multiple_currencies'] ?? false, 'class' => 'font-medium'])</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Tedarikçi Eşleşme</span>
                        <span class="pd-badge pd-badge-{{ $supplierStatusTone }}">{{ $supplierStatusLabel }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Cari ve Hızlı İşlemler</h3>
                <p class="pd-card-subtitle">Cari rolü, bakiye yönü ve Cari Ekstre erişimleri.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Cari Durumu</span>
                        <span class="pd-badge pd-badge-{{ $accountStatusTone }}">{{ $linkedCurrentAccount?->safeStatusLabel() ?? 'Bağlı değil' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Cari Kodu</span>
                        <span class="font-medium">{{ $linkedCurrentAccount?->account_code ?: '-' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Bakiye Yönü</span>
                        <span class="pd-badge pd-badge-{{ $balanceTone }}">{{ $linkedCurrentAccountSummary['balance_direction_label'] ?? 'Kapalı' }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Açık Hareket</span>
                        <span class="font-medium">{{ $linkedCurrentAccountSummary['open_transaction_count'] ?? 0 }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Son Hareket</span>
                        <span class="font-medium">{{ $linkedCurrentAccountSummary['last_transaction_label'] ?? 'Hareket yok' }}</span>
                    </div>
                </div>

                <div class="text-xs" style="color: var(--pd-muted); margin-top: 14px;">Hızlı İşlemler</div>
                <div class="pd-actions-wrap" style="margin-top: 14px;">
                    @if($linkedCurrentAccount && ($canViewCurrentAccountTransactions ?? false))
                        <a href="{{ route('admin.current-accounts.transactions.index', $linkedCurrentAccount) }}" class="pd-btn pd-btn-light pd-btn-sm">Ekstreye Git</a>
                    @endif
                    <a href="{{ route('admin.companies.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Listeye Dön</a>
                </div>
            </div>
        </div>
    </div>
</div>

@if(($canManageCurrentAccountTransactions ?? false) && $linkedCurrentAccount)
    @include('admin.current-account-transactions._quick-panel', [
        'account' => $linkedCurrentAccount,
        'quickPanelId' => 'hizli-islem-paneli',
        'quickPanelIsOpen' => $quickPanelIsOpen,
        'quickPanelFormAction' => route('admin.current-accounts.transactions.store', $linkedCurrentAccount),
        'quickPanelReturnUrl' => $quickPanelReturnUrl,
        'quickPanelTransactionTypeOptions' => $manualTransactionTypeOptions,
    ])
@endif
@endsection

@push('scripts')
<script>
    (function () {
        const contactSelect = document.getElementById('portal_user_contact');
        const nameInput = document.getElementById('portal_user_name');
        const emailInput = document.getElementById('portal_user_email');
        const phoneInput = document.getElementById('portal_user_phone');
        const warningBox = document.getElementById('portal-user-contact-warning');

        if (!contactSelect || !nameInput || !emailInput || !phoneInput || !warningBox) {
            return;
        }

        const applyContact = () => {
            const selected = contactSelect.options[contactSelect.selectedIndex];

            if (!selected || !selected.value) {
                warningBox.style.display = 'none';
                return;
            }

            nameInput.value = selected.dataset.contactName || '';
            emailInput.value = selected.dataset.contactEmail || '';
            phoneInput.value = selected.dataset.contactPhone || '';

            if (!selected.dataset.contactEmail) {
                warningBox.style.display = 'block';
                return;
            }

            warningBox.style.display = 'none';
        };

        contactSelect.addEventListener('change', applyContact);
        applyContact();
    }());

    (function () {
        const panel = document.querySelector('[data-quick-panel]');

        if (!panel) {
            return;
        }

        const typeSelect = panel.querySelector('[data-transaction-type-select]');
        const directionWrap = panel.querySelector('[data-manual-direction-wrap]');
        const dueDateWrap = panel.querySelector('[data-due-date-wrap]');
        const statusSelect = panel.querySelector('[data-status-select]');
        const submitActionInput = panel.querySelector('[data-submit-action]');
        const openButtons = document.querySelectorAll('[data-quick-panel-open]');
        const closeButtons = panel.querySelectorAll('[data-quick-panel-close]');
        const submitButtons = panel.querySelectorAll('[data-submit-mode]');

        const creditTypes = new Set([
            '{{ \App\Models\CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT }}',
            '{{ \App\Models\CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT }}',
            '{{ \App\Models\CurrentAccountTransaction::TYPE_SUBCONTRACTOR_PAYMENT }}',
            '{{ \App\Models\CurrentAccountTransaction::TYPE_CARRIER_PAYMENT }}',
            '{{ \App\Models\CurrentAccountTransaction::TYPE_REFUND }}',
        ]);

        const manualDirectionTypes = new Set([
            '{{ \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}',
            '{{ \App\Models\CurrentAccountTransaction::TYPE_OPENING_BALANCE }}',
            '{{ \App\Models\CurrentAccountTransaction::TYPE_OTHER }}',
        ]);

        const syncForm = () => {
            const type = typeSelect.value;
            directionWrap.style.display = manualDirectionTypes.has(type) ? '' : 'none';
            dueDateWrap.style.display = creditTypes.has(type) ? 'none' : '';

            if (!manualDirectionTypes.has(type) && statusSelect.dataset.userChanged !== '1') {
                statusSelect.value = creditTypes.has(type)
                    ? '{{ \App\Models\CurrentAccountTransaction::STATUS_CLOSED }}'
                    : '{{ \App\Models\CurrentAccountTransaction::STATUS_OPEN }}';
            }
        };

        const openPanel = (type) => {
            if (typeSelect && type) {
                typeSelect.value = type;
                statusSelect.dataset.userChanged = '0';
                syncForm();
            }

            panel.style.display = '';
            document.body.style.overflow = 'hidden';
        };

        const closePanel = () => {
            panel.style.display = 'none';
            document.body.style.overflow = '';
        };

        statusSelect.addEventListener('change', () => {
            statusSelect.dataset.userChanged = '1';
        });

        openButtons.forEach((button) => {
            button.addEventListener('click', () => openPanel(button.dataset.quickPanelOpen));
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', closePanel);
        });

        submitButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (submitActionInput) {
                    submitActionInput.value = button.dataset.submitMode || 'save';
                }
            });
        });

        panel.addEventListener('click', (event) => {
            if (event.target.matches('[data-quick-panel]')) {
                closePanel();
            }
        });

        if (typeSelect) {
            typeSelect.addEventListener('change', () => {
                statusSelect.dataset.userChanged = '0';
                syncForm();
            });
            syncForm();
        }
    }());
</script>
@endpush
