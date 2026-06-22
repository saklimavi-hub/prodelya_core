@extends('layouts.prodelya-admin')

@section('title', 'Cari Hareketler')
@section('page_title', 'Cari Hareketler')
@section('page_subtitle', 'Tenant cari hareketlerini yetkili kullanıcı görünümünde takip edin.')

@section('content')
<div class="pd-grid pd-grid-4" style="margin-bottom: 14px;">
    @foreach($summaryCards as $card)
        <div class="pd-card">
            <div class="pd-card-body">
                <div class="text-sm text-gray-600">{{ $card['label'] }}</div>
                <div class="text-2xl font-bold" style="margin-top: 6px;">{{ $card['count'] }}</div>
            </div>
        </div>
    @endforeach
</div>

<div class="pd-card" style="margin-bottom: 14px;">
    <div class="pd-card-body">
        <form method="GET" action="{{ route('admin.current-account-transactions.index') }}">
            <div class="pd-form-grid-3">
                <div>
                    <label class="text-sm font-medium">Cari</label>
                    <select name="current_account_id">
                        <option value="">Tümü</option>
                        @foreach($currentAccounts as $currentAccount)
                            <option value="{{ $currentAccount->id }}" @selected(($filters['current_account_id'] ?? null) == $currentAccount->id)>{{ $currentAccount->display_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">Tür</label>
                    <select name="transaction_type">
                        <option value="">Tümü</option>
                        @foreach(\App\Models\CurrentAccountTransaction::typeLabels() as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['transaction_type'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">Yön</label>
                    <select name="direction">
                        <option value="">Tümü</option>
                        @foreach(\App\Models\CurrentAccountTransaction::directionLabels() as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['direction'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">Durum</label>
                    <select name="status">
                        <option value="">Tümü</option>
                        @foreach(\App\Models\CurrentAccountTransaction::statusLabels() as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">Para Birimi</label>
                    <select name="currency">
                        <option value="">Tümü</option>
                        @foreach(['TRY', 'USD', 'EUR'] as $currency)
                            <option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">Tarih Başlangıç</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div>
                    <label class="text-sm font-medium">Tarih Bitiş</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div style="display:flex; align-items:end; gap:8px;">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.current-account-transactions.index') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="pd-card">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Hareket Listesi</h3>
        <p class="pd-card-subtitle">İptal edilen hareketler listede görünür ancak bakiye özetine dahil edilmez.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tarih</th>
                        <th>Cari</th>
                        <th>Tür</th>
                        <th>Açıklama</th>
                        <th>Borç</th>
                        <th>Alacak</th>
                        <th>Para Birimi</th>
                        <th>Durum</th>
                        <th>Kaynak</th>
                        <th class="text-right">Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $transaction)
                        @php
                            $sourcePayment = $sourcePayments->get($transaction->source_id);
                            $sourceProcurementItem = $sourceProcurementItems->get($transaction->source_id);
                            $sourceProduction = $sourceProductions->get($transaction->source_id);
                            $statusTone = match($transaction->status) {
                                \App\Models\CurrentAccountTransaction::STATUS_CANCELLED => 'gray',
                                \App\Models\CurrentAccountTransaction::STATUS_PAID,
                                \App\Models\CurrentAccountTransaction::STATUS_CLOSED => 'green',
                                \App\Models\CurrentAccountTransaction::STATUS_PARTIALLY_PAID => 'amber',
                                default => 'blue',
                            };
                        @endphp
                        <tr @if($transaction->isCancelled()) style="opacity: .65;" @endif>
                            <td>{{ optional($transaction->transaction_date)->format('d.m.Y') ?: '-' }}</td>
                            <td>{{ $transaction->currentAccount?->safeDisplayName() ?: '-' }}</td>
                            <td>{{ $transaction->safeTypeLabel() }}</td>
                            <td>{{ $transaction->description ?: '-' }}</td>
                            <td>{{ $transaction->isDebit() ? $transaction->formattedAmount() : '-' }}</td>
                            <td>{{ $transaction->isCredit() ? $transaction->formattedAmount() : '-' }}</td>
                            <td>{{ $transaction->currency }}</td>
                            <td><span class="pd-badge pd-badge-{{ $statusTone }}">{{ $transaction->safeStatusLabel() }}</span></td>
                            <td>
                                @if($transaction->source_type === 'manual')
                                    Manuel
                                @elseif($transaction->source_type === \App\Services\OrderPaymentCurrentAccountSyncService::SOURCE_TYPE && $sourcePayment)
                                    Sipariş Tahsilatı
                                    <div class="text-xs text-gray-600" style="margin-top:4px;">{{ $sourcePayment->order?->document_number ?: 'Sipariş #' . $sourcePayment->order_id }}</div>
                                @elseif($transaction->source_type === \App\Services\SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE && $sourceProcurementItem)
                                    Tedarik Alış Borcu
                                    <div class="text-xs text-gray-600" style="margin-top:4px;">{{ $sourceProcurementItem->request?->request_number ?: 'Talep #' . $sourceProcurementItem->supplier_procurement_request_id }}</div>
                                    <div class="text-xs text-gray-600">{{ $sourceProcurementItem->product_name ?: $sourceProcurementItem->product_code ?: 'Kalem #' . $sourceProcurementItem->id }}</div>
                                @elseif($transaction->source_type === \App\Services\SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE && $sourceProduction)
                                    Fason Üretim Borcu
                                    <div class="text-xs text-gray-600" style="margin-top:4px;">{{ $sourceProduction->order?->document_number ?: 'Sipariş #' . $sourceProduction->order_id }}</div>
                                    <div class="text-xs text-gray-600">{{ trim(($sourceProduction->orderItemPrint?->sequence_code ?: '') . ' ' . ($sourceProduction->orderItemPrint?->print_type ?: 'Baskı')) }}</div>
                                @else
                                    {{ $transaction->source_type ?: '-' }}
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="pd-actions-wrap">
                                    @if($transaction->currentAccount)
                                        <a href="{{ route('admin.current-accounts.transactions.index', $transaction->currentAccount) }}" class="pd-btn pd-btn-light pd-btn-sm">İncele</a>
                                    @endif
                                    @if($canCancelTransactions && !$transaction->isCancelled())
                                        <form method="POST" action="{{ route('admin.current-account-transactions.cancel', $transaction) }}">
                                            @csrf
                                            <input type="hidden" name="cancellation_reason" value="Manuel iptal">
                                            <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">İptal Et</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center">Cari hareket bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($transactions->hasPages())
            <div style="margin-top:14px;">{{ $transactions->links() }}</div>
        @endif
    </div>
</div>
@endsection
