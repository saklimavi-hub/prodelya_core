@extends('layouts.prodelya-admin')

@section('title', $account->safeDisplayName() . ' / Cari Ekstre')
@section('page_title', 'Cari Ekstre')
@section('page_subtitle', $account->safeDisplayName() . ' için finansal ekstre, filtre ve yaşlandırma görünümü')

@section('page_actions')
<div class="flex gap-3">
    @php
        $linkedCompanyId = $account->primaryCompanyLink?->link_id;
        $accountDetailRoute = $linkedCompanyId
            ? route('admin.companies.show', $linkedCompanyId)
            : route('admin.current-accounts.show', $account);
        $accountDetailLabel = $linkedCompanyId ? 'Cari Kartı Aç' : 'Finans Detayını Aç';
        $manualBaseRoute = route('admin.current-accounts.transactions.index', $account);
        $quickPanelReturnUrl = request()->fullUrl();
        $quickPanelIsOpen = old() !== [] || request()->boolean('quick_panel');
        $statementExportFilters = array_filter([
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'transaction_type' => $filters['type'] ?? null,
            'status' => $filters['status'] ?? 'all',
            'search' => $filters['search'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
        $summaryPdfRoute = route('admin.current-accounts.transactions.export.pdf', array_merge(['currentAccount' => $account, 'mode' => 'summary'], $statementExportFilters));
        $detailedPdfRoute = route('admin.current-accounts.transactions.export.pdf', array_merge(['currentAccount' => $account, 'mode' => 'detailed'], $statementExportFilters));
        $summaryExcelRoute = route('admin.current-accounts.transactions.export.excel', array_merge(['currentAccount' => $account, 'mode' => 'summary'], $statementExportFilters));
        $detailedExcelRoute = route('admin.current-accounts.transactions.export.excel', array_merge(['currentAccount' => $account, 'mode' => 'detailed'], $statementExportFilters));
    @endphp
    <a href="{{ $accountDetailRoute }}" class="pd-btn pd-btn-light">{{ $accountDetailLabel }}</a>
    @if($canManageTransactions)
        <button type="button" class="pd-btn pd-btn-light" data-quick-panel-open="{{ $manualQuickActionDefaults['collection'] ?? \App\Models\CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT }}">Tahsilat Gir</button>
        <button type="button" class="pd-btn pd-btn-light" data-quick-panel-open="{{ $manualQuickActionDefaults['payment'] ?? \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}">Ödeme Yap</button>
        <button type="button" class="pd-btn pd-btn-light" data-quick-panel-open="{{ $manualQuickActionDefaults['debit'] ?? \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}">Yeni Hareket</button>
    @endif
    <a href="{{ route('admin.current-account-transactions.index') }}" class="pd-btn pd-btn-primary">Tüm Cari Hareketleri</a>
</div>
@endsection

@section('content')
<style>
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
    @media (max-width: 900px) {
        .pd-quick-panel__summary { grid-template-columns:1fr; }
    }
</style>
<div class="pd-grid" style="grid-template-columns:minmax(0, 2fr) minmax(320px, 1fr);">
    <div>
        <div class="pd-card" style="margin-bottom:14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Cari Ekstre Özeti</h3>
                <p class="pd-card-subtitle">Güncel bakiye, açık hareketler, yaşlandırma ve dışa aktarma aksiyonları aynı alanda toplanır.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-4">
                    <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                        <div class="text-sm text-gray-600">Güncel Bakiye</div>
                        <div style="margin-top:8px;">@include('admin.current-accounts._money-display', ['label' => $summary['formatted_balance'] ?? '0,00 TL', 'amount' => $summary['balance'] ?? 0, 'hasMultipleCurrencies' => $summary['has_multiple_currencies'] ?? false])</div>
                    </div>
                    <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                        <div class="text-sm text-gray-600">Bakiye Durumu</div>
                        <div style="margin-top:8px;">
                            @php
                                $summaryTone = match($summary['balance_direction'] ?? 'closed') {
                                    'receivable' => 'green',
                                    'payable' => 'amber',
                                    'mixed' => 'gray',
                                    default => 'gray',
                                };
                            @endphp
                            <span class="pd-badge pd-badge-{{ $summaryTone }}">{{ $summary['balance_direction_label'] ?? 'Kapalı' }}</span>
                        </div>
                    </div>
                    <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                        <div class="text-sm text-gray-600">Toplam Borç</div>
                        <div style="margin-top:8px;">@include('admin.current-accounts._money-display', ['label' => $summary['formatted_total_debit'] ?? '0,00 TL', 'amount' => $summary['total_debit'] ?? 0, 'hasMultipleCurrencies' => $summary['has_multiple_currencies'] ?? false])</div>
                    </div>
                    <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                        <div class="text-sm text-gray-600">Toplam Alacak</div>
                        <div style="margin-top:8px;">@include('admin.current-accounts._money-display', ['label' => $summary['formatted_total_credit'] ?? '0,00 TL', 'amount' => $summary['total_credit'] ?? 0, 'hasMultipleCurrencies' => $summary['has_multiple_currencies'] ?? false])</div>
                    </div>
                </div>

                <div class="pd-grid pd-grid-3" style="margin-top:14px;">
                    <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                        <div class="text-sm text-gray-600">Filtreli Hareket Toplamı</div>
                        <div style="margin-top:8px;">@include('admin.current-accounts._money-display', ['label' => $filteredSummary['formatted_balance'] ?? '0,00 TL', 'amount' => $filteredSummary['balance'] ?? 0, 'hasMultipleCurrencies' => $filteredSummary['has_multiple_currencies'] ?? false])</div>
                        <div class="text-sm text-gray-600" style="margin-top:6px;">{{ $filteredSummary['transaction_count'] ?? 0 }} kayıt</div>
                    </div>
                    <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                        <div class="text-sm text-gray-600">Açık Hareket</div>
                        <div style="margin-top:8px;">{{ $summary['open_transaction_count'] ?? 0 }}</div>
                    </div>
                    <div style="padding:14px; border:1px solid var(--pd-line); border-radius:8px;">
                        <div class="text-sm text-gray-600">Vadesi Geçen</div>
                        <div style="margin-top:8px;">@include('admin.current-accounts._money-display', ['label' => $summary['formatted_overdue_amount'] ?? 'Yok', 'amount' => $summary['overdue_amount'] ?? 0, 'hasMultipleCurrencies' => $summary['has_multiple_currencies'] ?? false])</div>
                    </div>
                </div>
                <div class="pd-quick-action-row">
                    @if($canManageTransactions)
                        <button type="button" class="pd-btn pd-btn-primary pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['collection'] ?? \App\Models\CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT }}">Tahsilat Gir</button>
                        <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['payment'] ?? \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}">Ödeme Yap</button>
                        <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['debit'] ?? \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}">Yeni Hareket</button>
                    @endif
                    <a href="{{ $summaryPdfRoute }}" class="pd-btn pd-btn-light pd-btn-sm">Ekstre PDF</a>
                    <a href="{{ $summaryExcelRoute }}" class="pd-btn pd-btn-light pd-btn-sm">Ekstre Excel</a>
                    <a href="{{ $detailedPdfRoute }}" class="pd-btn pd-btn-light pd-btn-sm">Detaylı PDF</a>
                    <a href="{{ $detailedExcelRoute }}" class="pd-btn pd-btn-light pd-btn-sm">Detaylı Excel</a>
                </div>
            </div>
        </div>

        <div class="pd-card" style="margin-bottom:14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Ekstre Filtreleri</h3>
                <p class="pd-card-subtitle">Tarih, hareket türü, durum ve arama alanları ile ekstreyi daraltın.</p>
            </div>
            <div class="pd-card-body">
                <form method="GET" action="{{ route('admin.current-accounts.transactions.index', $account) }}">
                    <div class="pd-form-grid-3">
                        <div>
                            <label class="text-sm font-medium">Tarih Başlangıç</label>
                            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}">
                        </div>
                        <div>
                            <label class="text-sm font-medium">Tarih Bitiş</label>
                            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}">
                        </div>
                        <div>
                            <label class="text-sm font-medium">Hareket Türü</label>
                            <select name="transaction_type">
                                <option value="">Tümü</option>
                                @foreach($transactionTypeOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Durum</label>
                            <select name="status">
                                @foreach($statusOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(($filters['status'] ?? 'all') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Belge / Sipariş / Açıklama</label>
                            <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Sipariş no, talep no, açıklama...">
                        </div>
                        <div style="display:flex; align-items:end; gap:8px;">
                            <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                            <a href="{{ route('admin.current-accounts.transactions.index', $account) }}" class="pd-btn pd-btn-light">Sıfırla</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Ekstre Hareketleri</h3>
                <p class="pd-card-subtitle">Borç, alacak ve işaretli bakiye görünümü aynı cari semantiğiyle hesaplanır.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    @if($openingBalance)
                        <div class="pd-note" style="margin-bottom:12px;">
                            <strong>Önceden Devreden:</strong> {{ $openingBalance['label'] }}
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
                                <th class="text-right">Aksiyon</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($transactions as $transaction)
                                @php
                                    $sourcePayment = $sourcePayments->get($transaction->source_id);
                                    $sourceOrder = $sourceOrders->get($transaction->source_id);
                                    $sourceProcurementItem = $sourceProcurementItems->get($transaction->source_id);
                                    $sourceProduction = $sourceProductions->get($transaction->source_id);
                                    $isManualStatementCancel = $transaction->isManuallyCancellableFromStatement();
                                    $cancelContextParts = array_filter([
                                        'Cari: ' . $account->safeDisplayName(),
                                        'Hareket: ' . $transaction->safeTypeLabel(),
                                        'Tutar: ' . $transaction->formattedAmount(),
                                        'Tarih: ' . (optional($transaction->transaction_date)->format('d.m.Y') ?: '-'),
                                        'Belge / Sipariş: ' . (
                                            $transaction->safeManualOrderNumber()
                                            ?: $transaction->safeManualDocumentNumber()
                                            ?: ($sourceOrder?->document_number)
                                            ?: ($sourcePayment?->order?->document_number)
                                            ?: ($sourceProcurementItem?->request?->request_number)
                                            ?: ($sourceProduction?->order?->document_number)
                                            ?: '-'
                                        ),
                                        'Açıklama: ' . ($transaction->description ?: '-'),
                                    ]);
                                    $cancelConfirmMessage = "Bu cari hareketi iptal etmek üzeresiniz. İşlem bakiyeyi etkiler. Devam etmek istiyor musunuz?\n\n"
                                        . implode("\n", $cancelContextParts);
                                    $paymentCancelConfirmMessage = "Tahsilat İptal Edilsin mi?\n\n"
                                        . "Bu işlem sipariş tahsilatını iptal eder ve cari bakiyeyi yeniden hesaplar. Cari hareket doğrudan silinmez; kaynak tahsilat kaydı iptal edilir.";
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
                                        @if($transaction->source_type === \App\Services\OrderPaymentCurrentAccountSyncService::SOURCE_TYPE && $sourcePayment)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Kaynak: Sipariş Tahsilatı / {{ $sourcePayment->order?->document_number ?: 'Sipariş #' . $sourcePayment->order_id }}</div>
                                        @endif
                                        @if($transaction->source_type === \App\Services\OrderCurrentAccountDebitSyncService::SOURCE_TYPE && $sourceOrder)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Kaynak: Sipariş Müşteri Borcu / {{ $sourceOrder->document_number ?: 'Sipariş #' . $sourceOrder->id }}</div>
                                        @endif
                                        @if($transaction->source_type === \App\Services\SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE && $sourceProcurementItem)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Kaynak: Tedarik Alış Borcu / {{ $sourceProcurementItem->request?->request_number ?: 'Talep #' . $sourceProcurementItem->supplier_procurement_request_id }}</div>
                                            <div class="text-xs text-gray-600">{{ $sourceProcurementItem->product_name ?: $sourceProcurementItem->product_code ?: 'Kalem #' . $sourceProcurementItem->id }}</div>
                                        @endif
                                        @if($transaction->source_type === \App\Services\SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE && $sourceProduction)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Kaynak: Fason Üretim Borcu / {{ $sourceProduction->order?->document_number ?: 'Sipariş #' . $sourceProduction->order_id }}</div>
                                            <div class="text-xs text-gray-600">{{ trim(($sourceProduction->orderItemPrint?->sequence_code ?: '') . ' ' . ($sourceProduction->orderItemPrint?->print_type ?: 'Baskı')) }}</div>
                                        @endif
                                        @if($transaction->safeManualPaymentMethodLabel())
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Ödeme yöntemi: {{ $transaction->safeManualPaymentMethodLabel() }}</div>
                                        @endif
                                        @if($transaction->isCancelled() && $transaction->source_type === \App\Models\CurrentAccountTransaction::SOURCE_TYPE_MANUAL && $transaction->cancellation_reason)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">İptal nedeni: {{ $transaction->cancellation_reason }}</div>
                                        @elseif($transaction->isCancelled() && $transaction->source_type === \App\Services\OrderCurrentAccountDebitSyncService::SOURCE_TYPE)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Sipariş iptal edildi.</div>
                                        @elseif($transaction->isCancelled() && $transaction->source_type !== \App\Models\CurrentAccountTransaction::SOURCE_TYPE_MANUAL)
                                            <div class="text-xs text-gray-600" style="margin-top:4px;">Kaynak kayıt iptal edildi.</div>
                                        @endif
                                    </td>
                                    <td>{{ optional($transaction->due_date)->format('d.m.Y') ?: '-' }}</td>
                                    <td><span class="pd-badge pd-badge-{{ $statusTone }}">{{ $transaction->safeStatusLabel() }}</span></td>
                                    <td>{{ $transaction->isDebit() ? $transaction->formattedAmount() : '-' }}</td>
                                    <td>{{ $transaction->isCredit() ? $transaction->formattedAmount() : '-' }}</td>
                                    <td>
                                        @php $rowBalance = ($runningBalances ?? [])[$transaction->id] ?? null; @endphp
                                        @if($rowBalance)
                                            <div>@include('admin.current-accounts._money-display', ['label' => $rowBalance['label'], 'amount' => $rowBalance['amount'] ?? 0])</div>
                                            @if($rowBalance['direction_label'] === 'Kapalı')
                                                <div class="text-xs text-gray-600" style="margin-top:4px;">Kapalı</div>
                                            @endif
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if($transaction->isCancelled())
                                            <span class="text-xs text-gray-600">İptal edildi</span>
                                        @elseif($canCancelTransactions && $isManualStatementCancel)
                                            <form method="POST" action="{{ route('admin.current-account-transactions.cancel', $transaction) }}" onsubmit="return window.confirm({{ \Illuminate\Support\Js::from($cancelConfirmMessage) }});">
                                                @csrf
                                                <input type="hidden" name="cancellation_reason" value="Cari ekranından manuel iptal">
                                                <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">İptal Et</button>
                                            </form>
                                        @elseif($transaction->source_type === \App\Services\OrderCurrentAccountDebitSyncService::SOURCE_TYPE)
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <span class="text-xs text-gray-600">Sipariş kaynaklı</span>
                                                @if($sourceOrder)
                                                    <a href="{{ route('admin.orders.show', $sourceOrder) }}" class="pd-btn pd-btn-light pd-btn-sm">Siparişi Aç</a>
                                                @endif
                                            </div>
                                        @elseif($transaction->source_type === \App\Services\OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
                                            <div class="flex flex-wrap justify-end gap-2">
                                                @if($canViewPaymentActions && $sourcePayment && $sourcePayment->order)
                                                    <a href="{{ route('admin.finance.show', $sourcePayment->order) }}" class="pd-btn pd-btn-light pd-btn-sm">Tahsilatı Aç</a>
                                                @else
                                                    <span class="text-xs text-gray-600">Tahsilat kaynağı</span>
                                                @endif
                                                @if($canManagePayments && $sourcePayment && $sourcePayment->order && !$sourcePayment->isCancelled())
                                                    <form
                                                        method="POST"
                                                        action="{{ route('admin.finance.payments.cancel', ['order' => $sourcePayment->order, 'payment' => $sourcePayment]) }}"
                                                        onsubmit="return window.confirm({{ \Illuminate\Support\Js::from($paymentCancelConfirmMessage) }});"
                                                    >
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Tahsilatı İptal Et</button>
                                                    </form>
                                                @endif
                                            </div>
                                        @elseif($transaction->source_type === \App\Services\SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <span class="text-xs text-gray-600">Tedarik kaynaklı</span>
                                                @if($sourceProcurementItem?->request)
                                                    <a href="{{ route('admin.procurements.supplier-requests.edit', $sourceProcurementItem->request) }}" class="pd-btn pd-btn-light pd-btn-sm">Tedarik Kaydını Aç</a>
                                                @endif
                                            </div>
                                        @elseif($transaction->source_type === \App\Services\SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE)
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <span class="text-xs text-gray-600">Fason / Üretim kaynaklı</span>
                                                @if($sourceProduction)
                                                    <a href="{{ route('admin.productions.show', $sourceProduction) }}" class="pd-btn pd-btn-light pd-btn-sm">Üretimi Aç</a>
                                                @endif
                                            </div>
                                        @else
                                            -
                                        @endif
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

                @if($transactions->hasPages())
                    <div style="margin-top:14px;">{{ $transactions->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    <div>
        @if($canManageTransactions)
            <div class="pd-card" id="hizli-islem-paneli" style="margin-bottom:14px;">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Borç / Alacak / Tahsilat / Ödeme Fişi</h3>
                    <p class="pd-card-subtitle">Tahsilat, ödeme ve yeni hareket kayıtları bu ekrandan ayrılmadan hızlı panel ile açılır.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-note">
                        Kullanılabilir fiş türleri:
                        {{ implode(' • ', array_values($manualTransactionTypeOptions)) }}
                    </div>
                    <div class="pd-quick-action-row">
                        <button type="button" class="pd-btn pd-btn-primary pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['collection'] ?? \App\Models\CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT }}">Tahsilat Gir</button>
                        <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['payment'] ?? \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}">Ödeme Yap</button>
                        <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-quick-panel-open="{{ $manualQuickActionDefaults['debit'] ?? \App\Models\CurrentAccountTransaction::TYPE_ADJUSTMENT }}">Yeni Hareket</button>
                    </div>
                </div>
            </div>
        @endif

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Yaşlandırma ve Geçişler</h3>
                <p class="pd-card-subtitle">Açık hareketlerin vade kırılımı ve ekstre aksiyonları.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-info" style="margin-bottom: 14px;">
                    @foreach(($agingSummary['buckets'] ?? []) as $bucket)
                        <div class="pd-summary-row">
                            <span>{{ $bucket['label'] }}</span>
                            <span class="font-medium">{{ $bucket['formatted_amount'] }} / {{ $bucket['count'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="pd-summary-list">
                    <a href="{{ $accountDetailRoute }}" class="pd-summary-item">{{ $linkedCompanyId ? 'Cari kartı aç' : 'Finans detayını aç' }}</a>
                    <a href="{{ route('admin.current-account-transactions.index') }}" class="pd-summary-item">Tüm cari hareketleri</a>
                    <a href="{{ route('admin.companies.index') }}" class="pd-summary-item">Cari kartlara dön</a>
                    <a href="{{ $summaryPdfRoute }}" class="pd-summary-item">Genel PDF</a>
                    <a href="{{ $detailedPdfRoute }}" class="pd-summary-item">Detaylı PDF</a>
                    <a href="{{ $summaryExcelRoute }}" class="pd-summary-item">Genel Excel</a>
                    <a href="{{ $detailedExcelRoute }}" class="pd-summary-item">Detaylı Excel</a>
                </div>
            </div>
        </div>
    </div>
</div>

@if($canManageTransactions)
    @include('admin.current-account-transactions._quick-panel', [
        'quickPanelId' => 'hizli-islem-paneli',
        'quickPanelIsOpen' => $quickPanelIsOpen,
        'quickPanelFormAction' => route('admin.current-accounts.transactions.store', $account),
        'quickPanelReturnUrl' => $quickPanelReturnUrl,
        'quickPanelTransactionTypeOptions' => $manualTransactionTypeOptions,
    ])
@endif
@endsection

@push('scripts')
<script>
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
                submitActionInput.value = button.dataset.submitMode || 'save';
            });
        });

        panel.addEventListener('click', (event) => {
            if (event.target === panel) {
                closePanel();
            }
        });

        typeSelect.addEventListener('change', syncForm);
        syncForm();

        if (panel.dataset.open === '1') {
            openPanel(typeSelect.value);
        }
    }());
</script>
@endpush
