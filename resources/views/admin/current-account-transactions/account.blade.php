@extends('layouts.prodelya-admin')

@section('title', $account->safeDisplayName() . ' / Cari Hareketler')
@section('page_title', $account->safeDisplayName() . ' / Cari Hareketler')
@section('page_subtitle', 'Manuel cari hareket kayıtları ve bakiye özeti')

@section('page_actions')
<div class="flex gap-3">
    @php
        $linkedCompanyId = $account->primaryCompanyLink?->link_id;
        $accountDetailRoute = $linkedCompanyId
            ? route('admin.companies.show', $linkedCompanyId)
            : route('admin.current-accounts.show', $account);
        $accountDetailLabel = $linkedCompanyId ? 'Firma / Cari Kartı Aç' : 'Finansal Cari Hesabı Aç';
    @endphp
    <a href="{{ $accountDetailRoute }}" class="pd-btn pd-btn-light">{{ $accountDetailLabel }}</a>
    <a href="{{ route('admin.current-account-transactions.index') }}" class="pd-btn pd-btn-primary">Tüm Hareketler</a>
</div>
@endsection

@section('content')
<div class="pd-grid" style="grid-template-columns:minmax(0, 2fr) minmax(320px, 1fr);">
    <div>
        <div class="pd-card" style="margin-bottom:14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Cari Özeti</h3>
                <p class="pd-card-subtitle">İptal dışı hareketler para birimi bazlı hesaplanır.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-3">
                    @forelse($summary['currencies'] as $currencySummary)
                        <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                            <div class="text-sm text-gray-600">{{ $currencySummary['currency'] }}</div>
                            <div style="margin-top:8px;">Borç: <strong>{{ $currencySummary['debit_total_label'] }}</strong></div>
                            <div style="margin-top:6px;">Alacak: <strong>{{ $currencySummary['credit_total_label'] }}</strong></div>
                            <div style="margin-top:6px;">Bakiye: <strong>{{ $currencySummary['balance_label'] }}</strong></div>
                        </div>
                    @empty
                        <div class="pd-note">Henüz hareket kaydı yok.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Hareketler</h3>
                <p class="pd-card-subtitle">Bu cariye ait son hareketler ve iptal geçmişi.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Tür</th>
                                <th>Açıklama</th>
                                <th>Borç</th>
                                <th>Alacak</th>
                                <th>Durum</th>
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
                                <tr @if($transaction->isCancelled()) style="opacity:.65;" @endif>
                                    <td>{{ optional($transaction->transaction_date)->format('d.m.Y') ?: '-' }}</td>
                                    <td>{{ $transaction->safeTypeLabel() }}</td>
                                    <td>
                                        {{ $transaction->description ?: '-' }}
                                        @if($transaction->source_type === \App\Services\OrderPaymentCurrentAccountSyncService::SOURCE_TYPE && $sourcePayment)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Kaynak: Sipariş Tahsilatı / {{ $sourcePayment->order?->document_number ?: 'Sipariş #' . $sourcePayment->order_id }}</div>
                                        @endif
                                        @if($transaction->source_type === \App\Services\SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE && $sourceProcurementItem)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Kaynak: Tedarik Alış Borcu / {{ $sourceProcurementItem->request?->request_number ?: 'Talep #' . $sourceProcurementItem->supplier_procurement_request_id }}</div>
                                            <div class="text-xs text-gray-600">{{ $sourceProcurementItem->product_name ?: $sourceProcurementItem->product_code ?: 'Kalem #' . $sourceProcurementItem->id }}</div>
                                        @endif
                                        @if($transaction->source_type === \App\Services\SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE && $sourceProduction)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Kaynak: Fason Üretim Borcu / {{ $sourceProduction->order?->document_number ?: 'Sipariş #' . $sourceProduction->order_id }}</div>
                                            <div class="text-xs text-gray-600">{{ trim(($sourceProduction->orderItemPrint?->sequence_code ?: '') . ' ' . ($sourceProduction->orderItemPrint?->print_type ?: 'Baskı')) }}</div>
                                        @endif
                                        @if($transaction->isCancelled() && $transaction->cancellation_reason)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">İptal nedeni: {{ $transaction->cancellation_reason }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $transaction->isDebit() ? $transaction->formattedAmount() : '-' }}</td>
                                    <td>{{ $transaction->isCredit() ? $transaction->formattedAmount() : '-' }}</td>
                                    <td><span class="pd-badge pd-badge-{{ $statusTone }}">{{ $transaction->safeStatusLabel() }}</span></td>
                                    <td class="text-right">
                                        @if($canCancelTransactions && !$transaction->isCancelled())
                                            <form method="POST" action="{{ route('admin.current-account-transactions.cancel', $transaction) }}">
                                                @csrf
                                                <input type="hidden" name="cancellation_reason" value="Cari ekranından manuel iptal">
                                                <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">İptal Et</button>
                                            </form>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center">Cari hareket bulunamadı.</td>
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
    </div>

    <div>
        @if($canManageTransactions)
            <div class="pd-card" style="margin-bottom:14px;">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Yeni Hareket Ekle</h3>
                    <p class="pd-card-subtitle">Bu fazda yalnız manuel cari hareket girişi yapılır.</p>
                </div>
                <div class="pd-card-body">
                    <form method="POST" action="{{ route('admin.current-accounts.transactions.store', $account) }}">
                        @csrf
                        <div class="pd-grid">
                            <div>
                                <label class="text-sm font-medium">Tür</label>
                                <select name="transaction_type">
                                    @foreach($transactionTypeOptions as $value => $label)
                                        <option value="{{ $value }}" @selected(old('transaction_type') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium">Yön</label>
                                <select name="direction">
                                    @foreach($directionOptions as $value => $label)
                                        <option value="{{ $value }}" @selected(old('direction') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium">Tutar</label>
                                <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}">
                            </div>
                            <div>
                                <label class="text-sm font-medium">Para Birimi</label>
                                <select name="currency">
                                    @foreach(['TRY', 'USD', 'EUR'] as $currency)
                                        <option value="{{ $currency }}" @selected(old('currency', $account->default_currency ?: 'TRY') === $currency)>{{ $currency }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium">İşlem Tarihi</label>
                                <input type="date" name="transaction_date" value="{{ old('transaction_date', now()->toDateString()) }}">
                            </div>
                            <div>
                                <label class="text-sm font-medium">Vade Tarihi</label>
                                <input type="date" name="due_date" value="{{ old('due_date') }}">
                            </div>
                            <div>
                                <label class="text-sm font-medium">Açıklama</label>
                                <textarea name="description" rows="4">{{ old('description') }}</textarea>
                            </div>
                            <div>
                                <button type="submit" class="pd-btn pd-btn-primary">Yeni Hareket Ekle</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Hızlı Geçişler</h3>
                <p class="pd-card-subtitle">Cari ve finans ekranları arasında kısa geçişler.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-list">
                    <a href="{{ $accountDetailRoute }}" class="pd-summary-item">{{ $linkedCompanyId ? 'Firma / cari kartı aç' : 'Finansal cari hesabı aç' }}</a>
                    <a href="{{ route('admin.current-account-transactions.index') }}" class="pd-summary-item">Tüm hareketler</a>
                    <a href="{{ route('admin.current-accounts.index') }}" class="pd-summary-item">Cari listesi</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
