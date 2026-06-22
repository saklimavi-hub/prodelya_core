@extends('layouts.prodelya-admin')

@section('title', 'Promosyon Teklifleri')
@section('page_title', 'Promosyon Teklifleri')
@section('page_subtitle', 'Teklifleri hazırlayın, müşteri onayını takip edin ve uygun kayıtları siparişe çevirin.')
@section('hide_side_summary', '1')

@section('page_actions')
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('admin.promotion-quotes.create') }}" class="pd-btn pd-btn-primary" data-testid="promotion-quote-create-button">
            Yeni Promosyon Teklifi
        </a>
        <a href="{{ route('admin.promotion-quotes.index', array_merge(request()->query(), ['status' => 'waiting'])) }}" class="pd-btn pd-btn-light">
            Müşteri Onayı Bekleyenler
        </a>
        <a href="{{ route('admin.promotion-quotes.index', array_merge(request()->query(), ['status' => 'approved'])) }}" class="pd-btn pd-btn-light">
            Siparişe Çevrilebilir
        </a>
    </div>
@endsection

@section('content')
@php
    $summaryCards = [
        [
            'label' => 'Hazırlanan Teklifler',
            'value' => $stats['total'],
            'note' => 'Bu tenant için kayıtlı tüm satış teklifleri',
            'tone' => 'blue',
        ],
        [
            'label' => 'Müşteri Onayı Bekleyen',
            'value' => $stats['waiting'],
            'note' => 'Yanıt veya görüntülenme bekleyen kayıtlar',
            'tone' => 'amber',
        ],
        [
            'label' => 'Revize İstenen',
            'value' => $stats['revision_requested'],
            'note' => 'Güncellenip tekrar gönderilecek teklifler',
            'tone' => 'red',
        ],
        [
            'label' => 'Siparişe Dönüşenler',
            'value' => $stats['converted'],
            'note' => 'Operasyon süreci başlamış teklifler',
            'tone' => 'green',
        ],
        [
            'label' => 'Onaylananlar',
            'value' => $stats['approved'],
            'note' => 'Onayı tamamlanmış ve işleme hazır kayıtlar',
            'tone' => 'purple',
        ],
    ];
@endphp

<style>
    .pql-page{display:grid;gap:14px;padding-bottom:24px;font-family:Arial,Helvetica,sans-serif;color:#17233c}
    .pql-card,.pql-stat,.pql-table-card{background:#fff;border:1px solid #e4e8ef;border-radius:8px;box-shadow:0 8px 24px rgba(16,24,40,.055)}
    .pql-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
    .pql-stat{padding:12px 13px;min-height:76px}
    .pql-stat-label{font-size:11px;color:#66728a;font-weight:700}
    .pql-stat-value{margin-top:5px;font-size:22px;font-weight:700;letter-spacing:-.03em}
    .pql-stat-note{margin-top:2px;font-size:11px;color:#66728a}
    .pql-stat.blue .pql-stat-value{color:#2f6fed}
    .pql-stat.green .pql-stat-value{color:#17a55b}
    .pql-stat.amber .pql-stat-value{color:#d98207}
    .pql-stat.red .pql-stat-value{color:#d14343}
    .pql-stat.purple .pql-stat-value{color:#6f50d8}
    .pql-filters{padding:14px 16px}
    .pql-filters-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:10px}
    .pql-filters-top h3{margin:0;font-size:13px;font-weight:700}
    .pql-filters-top p{margin:3px 0 0;font-size:11px;color:#66728a}
    .pql-filter-grid{display:grid;grid-template-columns:1.4fr .9fr .9fr .85fr .85fr auto;gap:10px;align-items:end}
    .pql-field label{display:block;margin-bottom:5px;font-size:11px;color:#59657a;font-weight:700}
    .pql-field input,.pql-field select{width:100%;height:34px;border:1px solid #cfd7e3;border-radius:5px;background:#fff;padding:0 10px;color:#24324a;font-size:12px}
    .pql-filter-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
    .pql-table-card{overflow:hidden}
    .pql-table-toolbar{padding:12px 14px;border-bottom:1px solid #edf1f6;display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fff}
    .pql-table-toolbar h3{margin:0;font-size:13px;font-weight:700}
    .pql-table-toolbar p{margin:3px 0 0;font-size:11px;color:#66728a}
    .pql-chips{display:flex;flex-wrap:wrap;gap:6px}
    .pql-chip{display:inline-flex;align-items:center;gap:6px;min-height:28px;padding:0 10px;border:1px solid #dbe2ec;border-radius:5px;background:#fff;color:#475467;font-size:12px;font-weight:700}
    .pql-chip.is-active{background:#eaf1ff;border-color:#bfd2ff;color:#245bc7}
    .pql-chip em{font-style:normal;font-size:11px;padding:1px 5px;border-radius:3px;background:#eef2f7;color:#59657a}
    .pql-table-wrap{overflow-x:auto}
    .pql-table{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed}
    .pql-table th{height:40px;padding:0 10px;text-align:left;background:#f8fafc;border-bottom:1px solid #edf1f6;color:#59657a;font-size:11px;font-weight:700;white-space:nowrap}
    .pql-table td{padding:11px 10px;border-bottom:1px solid #edf1f6;vertical-align:middle;color:#24324a;font-size:12px}
    .pql-table tr:hover{background:#fbfdff}
    .pql-code{font-size:13px;font-weight:700;color:#21426f}
    .pql-sub{margin-top:3px;color:#66728a;font-size:11px;line-height:1.45}
    .pql-customer b,.pql-last b,.pql-next b{display:block;font-size:12px;font-weight:700;color:#24324a}
    .pql-customer span,.pql-last span,.pql-next span{display:block;margin-top:2px;color:#66728a;font-size:11px;line-height:1.4}
    .pql-badge{display:inline-flex;align-items:center;justify-content:center;min-height:21px;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:700;white-space:nowrap}
    .pql-badge-gray{background:#f3f4f6;color:#6b7280}
    .pql-badge-blue{background:#eaf1ff;color:#245bc7}
    .pql-badge-green{background:#e9f8ef;color:#127a46}
    .pql-badge-amber{background:#fff4db;color:#a15d00}
    .pql-badge-red{background:#fff0f0;color:#bd3333}
    .pql-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e4e8ef;color:#475467;font-size:11px;font-weight:700}
    .pql-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
    .pql-actions form{display:inline-flex}
    .pql-empty{padding:24px;text-align:center}
    .pql-empty b{display:block;font-size:15px}
    .pql-empty span{display:block;margin-top:4px;color:#66728a;font-size:12px}
    .pql-col-quote{width:160px}
    .pql-col-customer{width:240px}
    .pql-col-status{width:142px}
    .pql-col-last{width:146px}
    .pql-col-next{width:150px}
    .pql-col-total{width:120px}
    .pql-col-actions{width:250px}
    @media (max-width:1200px){.pql-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.pql-filter-grid{grid-template-columns:1fr 1fr 1fr}.pql-filter-actions{grid-column:1/-1}}
    @media (max-width:860px){.pql-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.pql-table{table-layout:auto}}
    @media (max-width:720px){.pql-stats,.pql-filter-grid{grid-template-columns:1fr}.pql-table,.pql-table thead,.pql-table tbody,.pql-table tr,.pql-table th,.pql-table td{display:block}.pql-table thead{display:none}.pql-table tr{padding:10px 0;border-bottom:1px solid #edf1f6}.pql-table td{border-bottom:0;padding:6px 10px}.pql-table td::before{content:attr(data-label);display:block;margin-bottom:4px;color:#66728a;font-size:10px;font-weight:700;text-transform:uppercase}.pql-actions{padding-top:4px}.pql-actions .pd-btn{flex:1 1 auto;justify-content:center}}
</style>

<div class="pql-page">
    <section class="pql-stats" aria-label="Teklif özeti">
        @foreach($summaryCards as $card)
            <article class="pql-stat {{ $card['tone'] }}">
                <div class="pql-stat-label">{{ $card['label'] }}</div>
                <div class="pql-stat-value">{{ $card['value'] }}</div>
                <div class="pql-stat-note">{{ $card['note'] }}</div>
            </article>
        @endforeach
    </section>

    <section class="pql-card pql-filters">
        <div class="pql-filters-top">
            <div>
                <h3>Filtreler</h3>
                <p>Arama ve müşteri onayı durumuna göre listeyi hızlıca daraltın.</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.promotion-quotes.index') }}" class="pql-filter-grid">
            <div class="pql-field">
                <label>Arama</label>
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Teklif no veya müşteri">
            </div>
            <div class="pql-field">
                <label>Müşteri Onayı / Durum</label>
                <select name="status">
                    <option value="">Tümü</option>
                    <option value="not_sent" @selected($filters['status'] === 'not_sent')>Teklif</option>
                    <option value="waiting" @selected($filters['status'] === 'waiting')>Onay Bekliyor</option>
                    <option value="revision_requested" @selected($filters['status'] === 'revision_requested')>Revize İstendi</option>
                    <option value="approved" @selected($filters['status'] === 'approved')>Onaylandı</option>
                    <option value="rejected" @selected($filters['status'] === 'rejected')>Reddedildi</option>
                    <option value="quote_converted" @selected($filters['status'] === 'quote_converted')>Siparişe Dönüştü</option>
                </select>
            </div>
            <div class="pql-field">
                <label>Müşteri</label>
                <select name="customer">
                    <option value="">Tümü</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((string) $filters['customer'] === (string) $customer->id)>{{ $customer->legal_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pql-field">
                <label>Tarih Başlangıç</label>
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}">
            </div>
            <div class="pql-field">
                <label>Tarih Bitiş</label>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}">
            </div>
            <div class="pql-filter-actions">
                <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                <a href="{{ route('admin.promotion-quotes.index') }}" class="pd-btn pd-btn-light">Temizle</a>
            </div>
        </form>
    </section>

    <section class="pql-table-card">
        <div class="pql-table-toolbar">
            <div>
                <h3>Teklif Listesi</h3>
                <p>Karar verilecek kayıtlar, müşteri yanıtı ve siparişe dönüş hazırlığı tek bakışta görünür.</p>
            </div>
            <div class="pql-chips">
                <a href="{{ route('admin.promotion-quotes.index') }}" class="pql-chip {{ ($filters['status'] ?? '') === '' ? 'is-active' : '' }}">Tümü <em>{{ $stats['total'] }}</em></a>
                <a href="{{ route('admin.promotion-quotes.index', array_merge(request()->query(), ['status' => 'waiting'])) }}" class="pql-chip {{ ($filters['status'] ?? '') === 'waiting' ? 'is-active' : '' }}">Onay Bekliyor <em>{{ $stats['waiting'] }}</em></a>
                <a href="{{ route('admin.promotion-quotes.index', array_merge(request()->query(), ['status' => 'revision_requested'])) }}" class="pql-chip {{ ($filters['status'] ?? '') === 'revision_requested' ? 'is-active' : '' }}">Revize <em>{{ $stats['revision_requested'] }}</em></a>
                <a href="{{ route('admin.promotion-quotes.index', array_merge(request()->query(), ['status' => 'approved'])) }}" class="pql-chip {{ ($filters['status'] ?? '') === 'approved' ? 'is-active' : '' }}">Çevrilebilir <em>{{ $stats['approved'] }}</em></a>
            </div>
        </div>

        <div class="pql-table-wrap">
            @if($quotes->isEmpty())
                <div class="pql-empty">
                    <b>Henüz promosyon teklifi yok.</b>
                    <span>Yeni teklif oluşturup müşteri onayı akışını buradan izleyebilirsiniz.</span>
                </div>
            @else
                <table class="pql-table">
                    <thead>
                        <tr>
                            <th class="pql-col-quote">Teklif No</th>
                            <th class="pql-col-customer">Müşteri</th>
                            <th class="pql-col-status">Durum</th>
                            <th class="pql-col-last">Son Müşteri Hareketi</th>
                            <th class="pql-col-next">Siparişe Dönüş</th>
                            @if($canViewFinancialData)
                                <th class="pql-col-total">Toplam</th>
                            @endif
                            <th class="pql-col-actions">Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($quotes as $quote)
                            @php
                                $connectedOrder = $quote->connectedOrder;
                                $statusClass = match($quote->display_status_badge_class) {
                                    'badge-blue' => 'pql-badge-blue',
                                    'badge-green' => 'pql-badge-green',
                                    'badge-amber' => 'pql-badge-amber',
                                    'badge-red' => 'pql-badge-red',
                                    default => 'pql-badge-gray',
                                };
                                $approvalStatus = $quote->customer_approval_status ?: \App\Models\Order::CUSTOMER_APPROVAL_NOT_SENT;
                                $approvalLabel = match ($approvalStatus) {
                                    \App\Models\Order::CUSTOMER_APPROVAL_WAITING => 'Onay Bekliyor',
                                    \App\Models\Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'Revize İstendi',
                                    \App\Models\Order::CUSTOMER_APPROVAL_APPROVED => 'Onaylandı',
                                    \App\Models\Order::CUSTOMER_APPROVAL_REJECTED => 'Reddedildi',
                                    default => 'Gönderilmedi',
                                };
                                $approvalClass = match ($approvalStatus) {
                                    \App\Models\Order::CUSTOMER_APPROVAL_WAITING => 'pql-badge-blue',
                                    \App\Models\Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'pql-badge-amber',
                                    \App\Models\Order::CUSTOMER_APPROVAL_APPROVED => 'pql-badge-green',
                                    \App\Models\Order::CUSTOMER_APPROVAL_REJECTED => 'pql-badge-red',
                                    default => 'pql-badge-gray',
                                };
                            @endphp
                            <tr data-testid="quote-row-{{ $quote->id }}">
                                <td data-label="Teklif No">
                                    <div class="pql-code">{{ $quote->document_number }}</div>
                                    <div class="pql-sub">{{ optional($quote->quote_date)->format('d.m.Y') ?: optional($quote->created_at)->format('d.m.Y') }}</div>
                                    @if($connectedOrder)
                                        <div class="pql-sub" data-testid="quote-{{ $quote->id }}-connected-order">Bağlı sipariş: {{ $connectedOrder->document_number }}</div>
                                    @endif
                                </td>
                                <td data-label="Müşteri">
                                    <div class="pql-customer">
                                        <b>{{ $quote->customer?->legal_name ?: '-' }}</b>
                                        <span>{{ $quote->customer_response_summary }}</span>
                                    </div>
                                </td>
                                <td data-label="Durum">
                                    <div class="flex flex-col gap-2">
                                        <span class="pql-badge {{ $statusClass }}">{{ $quote->display_status_label }}</span>
                                        <span class="pql-badge {{ $approvalClass }}">{{ $approvalLabel }}</span>
                                    </div>
                                </td>
                                <td data-label="Son Müşteri Hareketi">
                                    <div class="pql-last">
                                        <b>{{ $quote->last_action_label }}</b>
                                        <span>{{ optional($quote->updated_at)->format('d.m.Y H:i') }}</span>
                                    </div>
                                </td>
                                <td data-label="Siparişe Dönüş">
                                    <div class="pql-next">
                                        <b data-testid="quote-{{ $quote->id }}-process-status">{{ $quote->next_action_label }}</b>
                                        <span>{{ $connectedOrder ? 'Operasyon başladı' : ($quote->can_convert_from_index ? 'Siparişe çevrilebilir' : 'Karar bekliyor') }}</span>
                                    </div>
                                </td>
                                @if($canViewFinancialData)
                                    <td data-label="Toplam">
                                        <span class="pql-pill">{{ number_format((float) $quote->grand_total, 2, ',', '.') }} {{ $quote->currency }}</span>
                                    </td>
                                @endif
                                <td data-label="Aksiyon">
                                    <div class="pql-actions">
                                        @if($connectedOrder)
                                            <a href="{{ route('admin.orders.show', $connectedOrder) }}" class="pd-btn pd-btn-success pd-btn-sm" data-testid="quote-{{ $quote->id }}-action-open-order">Siparişi Aç</a>
                                        @else
                                            <a href="{{ route('admin.promotion-quotes.show', $quote) }}" class="pd-btn pd-btn-light pd-btn-sm" data-testid="quote-{{ $quote->id }}-action-show">Aç</a>

                                            @if($quote->is_editable_for_index)
                                                <a href="{{ route('admin.promotion-quotes.edit', $quote) }}" class="pd-btn pd-btn-light pd-btn-sm" data-testid="quote-{{ $quote->id }}-action-edit">Düzenle</a>
                                            @endif

                                            @if($canApproveQuotes && !$quote->is_converted && !$quote->can_convert_from_index)
                                                <form method="POST" action="{{ route('admin.promotion-quotes.mark-approved', $quote) }}">
                                                    @csrf
                                                    <button type="submit" class="pd-btn pd-btn-success pd-btn-sm" data-testid="quote-{{ $quote->id }}-action-mark-approved">Onaylandı İşaretle</button>
                                                </form>
                                            @endif

                                            @if($customerQuoteApprovalEnabled && $canApproveQuotes && in_array($quote->customer_approval_status ?: 'not_sent', ['not_sent', 'waiting', 'revision_requested', 'rejected'], true))
                                                <form method="POST" action="{{ route('admin.promotion-quotes.send-to-customer', $quote) }}">
                                                    @csrf
                                                    <input type="hidden" name="sent_channel" value="manual">
                                                    <button type="submit" class="pd-btn pd-btn-primary pd-btn-sm" data-testid="quote-{{ $quote->id }}-action-send-customer">
                                                        {{ in_array($quote->customer_approval_status, ['waiting', 'revision_requested'], true) ? 'Tekrar Gönder' : 'Müşteriye Gönder' }}
                                                    </button>
                                                </form>
                                            @elseif(!$customerQuoteApprovalEnabled && $canApproveQuotes && ($quote->customer_approval_status ?: 'not_sent') === 'not_sent')
                                                <span class="pd-btn pd-btn-light pd-btn-disabled pd-btn-sm" data-testid="quote-{{ $quote->id }}-action-send-customer-disabled">Modül Gerekli</span>
                                            @endif

                                            @if($quote->can_convert_from_index)
                                                <a href="{{ route('admin.promotion-quotes.show', $quote) }}" class="pd-btn pd-btn-primary pd-btn-sm" data-testid="quote-{{ $quote->id }}-action-convert">Siparişe Çevir</a>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if($quotes->hasPages())
            <div class="px-4 py-3 bg-white border-t border-gray-200">
                {{ $quotes->links() }}
            </div>
        @endif
    </section>
</div>
@endsection
