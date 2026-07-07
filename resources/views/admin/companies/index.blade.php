@extends('layouts.prodelya-admin')

@section('title', 'Cari Kartlar')
@section('page_title', 'Cari Kartlar')
@section('page_subtitle', 'Müşteri, tedarikçi, fasoncu, kargo firması ve diğer carileri tek kart üzerinden yönetin.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.companies.import.index') }}" class="pd-btn pd-btn-light">
        <svg class="pd-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
        İçe Aktarma Bilgisi
    </a>
    <a href="{{ route('admin.companies.create') }}" class="pd-btn pd-btn-primary">
        <svg class="pd-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
        Yeni Cari Oluştur
    </a>
</div>
@endsection

@section('content')
@php
    $phoneFormatter = app(\App\Services\PhoneNumberNormalizer::class);
@endphp
<div class="pd-grid pd-grid-4" style="margin-bottom: 14px;">
    <div class="pd-card"><div class="pd-card-body"><div class="flex items-center"><div class="pd-stat-icon bg-blue-100"><svg class="pd-icon-lg text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg></div><div class="ml-4"><div class="text-sm text-gray-600">Toplam Cari Kart</div><div class="text-2xl font-bold">{{ $stats['total'] ?? 0 }}</div></div></div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="flex items-center"><div class="pd-stat-icon bg-green-100"><svg class="pd-icon-lg text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg></div><div class="ml-4"><div class="text-sm text-gray-600">Müşteri</div><div class="text-2xl font-bold">{{ $stats['customers'] ?? 0 }}</div></div></div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="flex items-center"><div class="pd-stat-icon bg-blue-100"><svg class="pd-icon-lg text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg></div><div class="ml-4"><div class="text-sm text-gray-600">Tedarikçi</div><div class="text-2xl font-bold">{{ $stats['suppliers'] ?? 0 }}</div></div></div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="flex items-center"><div class="pd-stat-icon bg-purple-100"><svg class="pd-icon-lg text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg></div><div class="ml-4"><div class="text-sm text-gray-600">Fason / Baskı</div><div class="text-2xl font-bold">{{ $stats['print_fason'] ?? 0 }}</div></div></div></div></div>
</div>

@if($canViewFinancialData ?? false)
<div class="pd-grid pd-grid-4" style="margin-bottom: 14px;">
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Toplam Borç</div><div class="text-2xl font-bold" style="margin-top: 6px;">@include('admin.current-accounts._money-display', ['label' => $financeDashboard['formatted_total_receivable'] ?? '0,00 TL', 'amount' => $financeDashboard['total_receivable'] ?? 0])</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Toplam Alacak</div><div class="text-2xl font-bold" style="margin-top: 6px;">@include('admin.current-accounts._money-display', ['label' => $financeDashboard['formatted_total_payable'] ?? '0,00 TL', 'amount' => $financeDashboard['total_payable'] ?? 0])</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Vadesi Geçen</div><div class="text-2xl font-bold" style="margin-top: 6px;">@include('admin.current-accounts._money-display', ['label' => $financeDashboard['formatted_overdue_total'] ?? '0,00 TL', 'amount' => $financeDashboard['overdue_total'] ?? 0])</div></div></div>
    <div class="pd-card"><div class="pd-card-body"><div class="text-sm text-gray-600">Açık Hareket</div><div class="text-2xl font-bold" style="margin-top: 6px;">{{ $financeDashboard['open_transaction_count'] ?? 0 }}</div></div></div>
</div>
@endif

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-header"><h3 class="pd-card-title">Filtreler</h3><p class="pd-card-subtitle">Cari tipleri, durum ve risk seviyesine göre listeyi daraltın.</p></div>
    <div class="pd-card-body">
        <form method="GET" action="{{ route('admin.companies.index') }}">
            <div class="pd-grid" style="grid-template-columns: repeat(5, minmax(0, 1fr));">
                <div><label class="text-sm font-medium">Arama</label><input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Firma adı, vergi no, e-posta..."></div>
                <div><label class="text-sm font-medium">Cari Tipi</label><select name="role"><option value="">Tümü</option><option value="customer" {{ ($filters['role'] ?? '') === 'customer' ? 'selected' : '' }}>Müşteri</option><option value="supplier" {{ ($filters['role'] ?? '') === 'supplier' ? 'selected' : '' }}>Tedarikçi</option><option value="print_fason" {{ ($filters['role'] ?? '') === 'print_fason' ? 'selected' : '' }}>Fason Baskı Firması</option><option value="production_partner" {{ ($filters['role'] ?? '') === 'production_partner' ? 'selected' : '' }}>Fason Üretim Firması</option><option value="delivery_partner" {{ ($filters['role'] ?? '') === 'delivery_partner' ? 'selected' : '' }}>Nakliye / Kargo</option></select></div>
                <div><label class="text-sm font-medium">Durum</label><select name="status"><option value="">Tümü</option><option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Aktif</option><option value="passive" {{ ($filters['status'] ?? '') === 'passive' ? 'selected' : '' }}>Pasif</option></select></div>
                <div><label class="text-sm font-medium">Risk Durumu</label><select name="risk_status"><option value="">Tümü</option><option value="low" {{ ($filters['risk_status'] ?? '') === 'low' ? 'selected' : '' }}>Düşük</option><option value="medium" {{ ($filters['risk_status'] ?? '') === 'medium' ? 'selected' : '' }}>Orta</option><option value="high" {{ ($filters['risk_status'] ?? '') === 'high' ? 'selected' : '' }}>Yüksek</option><option value="critical" {{ ($filters['risk_status'] ?? '') === 'critical' ? 'selected' : '' }}>Kritik</option></select></div>
                <div style="display: flex; align-items: end;"><button type="submit" class="pd-btn pd-btn-primary pd-btn-block">Filtrele</button></div>
            </div>
        </form>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-header"><h3 class="pd-card-title">Cari Kart Listesi</h3><p class="pd-card-subtitle">Cari kimliği, roller ve temel iletişim bilgilerini tek listede yönetin. Finansal ekstre bağlantıları ilgili cari kart üzerinden açılır.</p></div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead><tr><th>Cari Adı</th><th>Roller</th><th>İletişim</th>@if($canViewFinancialData ?? false)<th>Güncel Bakiye</th><th>Bakiye Durumu</th><th>Açık Hareket</th><th>Son Hareket</th>@else<th>Durum</th>@endif<th class="text-right">Aksiyon</th></tr></thead>
                <tbody>
                    @forelse($companies as $company)
                    @php
                        $summary = ($balanceSummaries ?? [])[$company->id] ?? null;
                        $formattedWhatsapp = $company->mobile ? $phoneFormatter->formatTurkishPhoneForDisplay($company->mobile) : '';
                        $formattedPhone = $company->phone ? $phoneFormatter->formatTurkishPhoneForDisplay($company->phone) : '';
                        $whatsappDial = $company->mobile ? $phoneFormatter->toWhatsappDialString($company->mobile) : null;
                        $directionTone = match($summary['balance_direction'] ?? 'closed') {
                            'receivable' => 'green',
                            'payable' => 'amber',
                            'mixed' => 'gray',
                            default => 'gray',
                        };
                    @endphp
                    <tr>
                        <td>
                            <div class="font-medium">{{ $company->legal_name }}</div>
                            @if($company->short_name)
                                <div class="text-sm text-gray-600">{{ $company->short_name }}</div>
                            @endif
                            @if($company->status !== 'active')
                                <div style="margin-top: 6px;">
                                    <span class="pd-badge {{ $company->status === 'inactive' ? 'pd-badge-gray' : 'pd-badge-amber' }}">{{ $company->status === 'inactive' ? 'Pasif' : 'Durum değişti' }}</span>
                                </div>
                            @endif
                        </td>
                        <td><div class="flex flex-wrap gap-2">@foreach($company->getRoleNames() as $index => $roleName)<span class="pd-badge pd-badge-{{ $company->getRoleBadgeColors()[$index] }}">{{ $roleName }}</span>@endforeach</div></td>
                        <td>
                            @if($formattedWhatsapp)
                                <div>
                                    <strong>WhatsApp:</strong> {{ $formattedWhatsapp }}
                                    @if($whatsappDial)
                                        <a href="https://wa.me/{{ $whatsappDial }}" target="_blank" rel="noopener noreferrer" style="margin-left: 6px; color: var(--pd-blue); text-decoration: none;">Aç</a>
                                    @endif
                                </div>
                            @endif
                            @if($formattedPhone)
                                <div><strong>Telefon:</strong> {{ $formattedPhone }}</div>
                            @endif
                            @if($company->email)
                                <div class="text-sm text-gray-600">{{ $company->email }}</div>
                            @endif
                            @if(!$formattedWhatsapp && !$formattedPhone)
                                <span class="text-gray-600">İletişim bilgisi yok</span>
                            @endif
                        </td>
                        @if($canViewFinancialData ?? false)
                            <td>
                                @if(!empty(($linkedCurrentAccountIds ?? [])[$company->id]))
                                    <div>@include('admin.current-accounts._money-display', ['label' => $summary['formatted_balance'] ?? '0,00 TL', 'amount' => $summary['balance'] ?? 0, 'hasMultipleCurrencies' => $summary['has_multiple_currencies'] ?? false])</div>
                                    @if(($summary['has_transactions'] ?? false) === false)
                                        <div class="text-sm text-gray-600">Hareket yok</div>
                                    @endif
                                @else
                                    <span class="text-gray-600">Finans hesabı yok</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty(($linkedCurrentAccountIds ?? [])[$company->id]))
                                    <span class="pd-badge pd-badge-{{ $directionTone }}">{{ $summary['balance_direction_label'] ?? 'Kapalı' }}</span>
                                @else
                                    <span class="text-gray-600">Ekstre yok</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty(($linkedCurrentAccountIds ?? [])[$company->id]))
                                    <div>{{ $summary['open_transaction_count'] ?? 0 }}</div>
                                @else
                                    <span class="text-gray-600">-</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty(($linkedCurrentAccountIds ?? [])[$company->id]))
                                    <div>{{ $summary['last_transaction_label'] ?? 'Hareket yok' }}</div>
                                @else
                                    <span class="text-gray-600">-</span>
                                @endif
                            </td>
                        @else
                            <td><span class="pd-badge {{ $company->status === 'active' ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $company->status === 'active' ? 'Aktif' : 'Pasif' }}</span></td>
                        @endif
                        <td class="text-right">
                            <div class="pd-actions-wrap">
                                <a href="{{ route('admin.companies.show', $company) }}" class="pd-btn pd-btn-light pd-btn-sm">Cari Kartı Aç</a>
                                @if(($canViewCurrentAccountTransactions ?? false) && !empty(($linkedCurrentAccountIds ?? [])[$company->id]))
                                    <a href="{{ route('admin.current-accounts.transactions.index', $linkedCurrentAccountIds[$company->id]) }}" class="pd-btn pd-btn-primary pd-btn-sm">Ekstre</a>
                                @endif
                                <a href="{{ route('admin.companies.edit', $company) }}" class="pd-btn pd-btn-light pd-btn-sm">Düzenle</a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="{{ ($canViewFinancialData ?? false) ? 8 : 5 }}" class="text-center">Cari kart bulunamadı.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($companies->hasPages())
            <div style="margin-top: 14px;">{{ $companies->links() }}</div>
        @endif
    </div>
</div>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Cari Kart Özeti</h3>
        <div class="pd-summary-section"><h4 class="pd-summary-section-title">Hızlı Eylemler</h4><div class="pd-summary-list"><a href="{{ route('admin.companies.create') }}" class="pd-summary-item">Yeni Cari Oluştur</a><a href="{{ route('admin.companies.import.index') }}" class="pd-summary-item">İçe Aktarma Durumu</a></div></div>
        <div class="pd-summary-section"><h4 class="pd-summary-section-title">Rol Dağılımı</h4><div class="pd-summary-info"><div class="pd-summary-row"><span>Müşteri</span><span class="font-medium">{{ $stats['customers'] ?? 0 }}</span></div><div class="pd-summary-row"><span>Tedarikçi</span><span class="font-medium">{{ $stats['suppliers'] ?? 0 }}</span></div><div class="pd-summary-row"><span>Fason Baskı Firması</span><span class="font-medium">{{ $stats['print_fason'] ?? 0 }}</span></div></div></div>
        @if($canViewFinancialData ?? false)
        <div class="pd-summary-section"><h4 class="pd-summary-section-title">En Yüksek Borç Bakiyesi</h4><div class="pd-summary-info">@forelse(($topReceivableCompanies ?? []) as $summary)<div class="pd-summary-row"><span><a href="{{ route('admin.companies.show', $summary['company_id']) }}">{{ \Illuminate\Support\Str::limit($summary['company_name'], 22) }}</a></span><span>@include('admin.current-accounts._money-display', ['label' => $summary['formatted_balance'], 'amount' => $summary['balance'] ?? 0, 'hasMultipleCurrencies' => $summary['has_multiple_currencies'] ?? false, 'class' => 'font-medium'])</span></div>@empty<div class="pd-note">Borç bakiyesi görünmüyor.</div>@endforelse</div></div>
        <div class="pd-summary-section"><h4 class="pd-summary-section-title">En Yüksek Alacak Bakiyesi</h4><div class="pd-summary-info">@forelse(($topPayableCompanies ?? []) as $summary)<div class="pd-summary-row"><span><a href="{{ route('admin.companies.show', $summary['company_id']) }}">{{ \Illuminate\Support\Str::limit($summary['company_name'], 22) }}</a></span><span>@include('admin.current-accounts._money-display', ['label' => $summary['formatted_balance'], 'amount' => $summary['balance'] ?? 0, 'hasMultipleCurrencies' => $summary['has_multiple_currencies'] ?? false, 'class' => 'font-medium'])</span></div>@empty<div class="pd-note">Alacak bakiyesi görünmüyor.</div>@endforelse</div></div>
        <div class="pd-summary-section"><h4 class="pd-summary-section-title">Vadesi Geçenler</h4><div class="pd-summary-info">@forelse(($topOverdueCompanies ?? []) as $summary)<div class="pd-summary-row"><span><a href="{{ route('admin.companies.show', $summary['company_id']) }}">{{ \Illuminate\Support\Str::limit($summary['company_name'], 22) }}</a></span><span>@include('admin.current-accounts._money-display', ['label' => $summary['formatted_overdue_amount'], 'amount' => $summary['overdue_amount'] ?? 0, 'hasMultipleCurrencies' => $summary['has_multiple_currencies'] ?? false, 'class' => 'font-medium'])</span></div>@empty<div class="pd-note">Vadesi geçen kayıt yok.</div>@endforelse</div></div>
        @endif
        @if(array_filter($filters))
        <div class="pd-summary-section"><h4 class="pd-summary-section-title">Aktif Filtreler</h4><div class="pd-summary-info">@foreach($filters as $key => $value)@if($value)<div class="pd-summary-row"><span>{{ ucfirst(str_replace('_', ' ', $key)) }}</span><span class="font-medium">{{ $value }}</span></div>@endif @endforeach</div></div>
        @endif
        <div class="pd-note">Toplu içe aktarma modülü hazırlık aşamasındadır. Manuel cari kart ekleme aktif olarak kullanılabilir.</div>
    </div>
</div>
@endsection
