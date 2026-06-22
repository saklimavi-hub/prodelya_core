@extends('layouts.prodelya-admin')

@section('title', 'Finans / Tahsilat')
@section('page_title', 'Finans / Tahsilat')
@section('page_subtitle', 'Sipariş snapshot toplamlarını, belge türünü, KDV kırılımını ve tahsilat özetini yönetin.')

@section('content')
@php
    $money = static fn ($amount, $currency) => number_format((float) $amount, 2, ',', '.') . ' ' . $currency;
    $paymentStatusTones = [
        'odeme_bekliyor' => 'amber',
        'kismi_odeme' => 'blue',
        'odendi' => 'green',
        'fazla_odeme' => 'purple',
        'vade_bekliyor' => 'gray',
        'tahsilat_uyarisi' => 'red',
        'iptal' => 'gray',
    ];
    $invoiceStatusTones = [
        'fis' => 'gray',
        'fatura' => 'blue',
    ];
@endphp

<style>
    .fin-page { display: grid; gap: 14px; }
    .fin-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05); overflow: hidden; }
    .fin-body { padding: 16px; }
    .fin-summary { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 10px; }
    .fin-metric { border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; background: #fbfcfe; }
    .fin-metric-label { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .fin-metric-value { margin-top: 6px; font-size: 22px; font-weight: 700; color: #111827; }
    .fin-metric-sub { margin-top: 4px; color: #6b7280; font-size: 12px; }
    .fin-filters { display: grid; grid-template-columns: 1.4fr repeat(5, minmax(0, 1fr)) auto; gap: 10px; align-items: end; }
    .fin-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 12px; font-weight: 600; }
    .fin-table-wrap { overflow: auto; }
    .fin-table { width: 100%; border-collapse: collapse; }
    .fin-table th, .fin-table td { padding: 11px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
    .fin-table th { background: #fbfcfe; color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .fin-table tr:last-child td { border-bottom: 0; }
    .fin-stack { display: grid; gap: 4px; }
    .fin-code { color: #111827; font-weight: 700; }
    .fin-muted { color: #6b7280; }
    .fin-money { white-space: nowrap; font-weight: 600; color: #111827; }
    .fin-note { border: 1px solid #dbeafe; border-radius: 6px; background: #f8fbff; padding: 10px 12px; color: #6b7280; font-size: 12px; line-height: 1.45; }
    .fin-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    @media (max-width: 1500px) { .fin-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); } .fin-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 760px) { .fin-summary, .fin-filters { grid-template-columns: 1fr; } }
</style>

<div class="fin-page">
    <section class="fin-summary">
        @foreach($summaryCards as $card)
            <div class="fin-metric">
                <div class="fin-metric-label">{{ $card['label'] }}</div>
                <div class="fin-metric-value">{{ $card['count'] }}</div>
                <div class="fin-metric-sub">{{ $card['amount'] ?? 'Çoklu para birimi' }}</div>
            </div>
        @endforeach
    </section>

    <section class="fin-card">
        <div class="fin-body">
            <form method="GET" action="{{ route('admin.finance.index') }}" class="fin-filters">
                <div class="fin-field">
                    <label>Arama</label>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Sipariş no, teklif no, müşteri">
                </div>
                <div class="fin-field">
                    <label>Finans Durumu</label>
                    <select name="payment_status">
                        <option value="">Tümü</option>
                        @foreach($paymentStatusLabels as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['payment_status'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fin-field">
                    <label>Belge Türü</label>
                    <select name="invoice_status">
                        <option value="">Tümü</option>
                        @foreach($invoiceStatusLabels as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['invoice_status'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fin-field">
                    <label>Para Birimi</label>
                    <select name="currency">
                        <option value="">Tümü</option>
                        @foreach(['TL', 'USD', 'EUR'] as $currency)
                            <option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fin-field">
                    <label>Teslimat Uyarısı</label>
                    <select name="delivery_warning">
                        <option value="">Tümü</option>
                        @foreach($deliveryWarningLabels as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['delivery_warning'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fin-field">
                    <label>Limit</label>
                    <div class="fin-actions">
                        <select name="limit">
                            @foreach([25, 50, 100, 250] as $limitOption)
                                <option value="{{ $limitOption }}" @selected((int) ($filters['limit'] ?? 50) === $limitOption)>{{ $limitOption }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                        <a href="{{ route('admin.finance.index') }}" class="pd-btn pd-btn-light">Temizle</a>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <section class="fin-card">
        <div class="fin-body">
            <div class="fin-note">
                Bu ekran yalnız finans yetkili kullanıcılar içindir. Sipariş snapshot toplamları canlı yeniden hesaplanmaz; tahsil edilen, kalan ve finans durumu yalnız payment summary servisinden türetilir.
            </div>
        </div>
        <div class="fin-table-wrap">
            <table class="fin-table">
                <thead>
                    <tr>
                        <th>Sipariş</th>
                        <th>Müşteri</th>
                        <th>Belge</th>
                        <th>Ürün</th>
                        <th>Baskı</th>
                        <th>Ara Toplam</th>
                        <th>KDV</th>
                        <th>Genel Toplam</th>
                        <th>Tahsil Edilen</th>
                        <th>Kalan</th>
                        <th>Finans Durumu</th>
                        <th>Teslimat Uyarısı</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        @php
                            $order = $row['order'];
                            $summary = $row['summary'];
                            $currency = $summary['currency'] ?? ($order->currency ?: 'TL');
                        @endphp
                        <tr>
                            <td>
                                <div class="fin-stack">
                                    <span class="fin-code">{{ $summary['order_number'] ?? $order->document_number }}</span>
                                    <span class="fin-muted">{{ $summary['source_quote_number'] ?? ($order->source_quote_number ?: '-') }}</span>
                                    <span class="fin-muted">{{ $currency }}</span>
                                </div>
                            </td>
                            <td>{{ $summary['customer_name'] ?? ($order->customer?->legal_name ?: '-') }}</td>
                            <td>
                                <div class="fin-stack">
                                    <span class="pd-badge pd-badge-{{ $invoiceStatusTones[$summary['invoice_status'] ?? 'fis'] ?? 'gray' }}">{{ $summary['invoice_status_label'] ?? 'Fiş' }}</span>
                                    <span class="fin-muted">Ödeme: {{ $summary['payment_status_label'] ?? '-' }}</span>
                                </div>
                            </td>
                            <td><span class="fin-money">{{ $money($summary['product_total'] ?? 0, $currency) }}</span></td>
                            <td><span class="fin-money">{{ $money($summary['print_total'] ?? 0, $currency) }}</span></td>
                            <td><span class="fin-money">{{ $money($summary['subtotal'] ?? 0, $currency) }}</span></td>
                            <td><span class="fin-money">{{ $money($summary['vat_total'] ?? 0, $currency) }}</span></td>
                            <td><span class="fin-money">{{ $money($summary['grand_total'] ?? 0, $currency) }}</span></td>
                            <td><span class="fin-money">{{ $money($summary['net_paid_total'] ?? 0, $currency) }}</span></td>
                            <td><span class="fin-money">{{ $money($summary['balance_due'] ?? 0, $currency) }}</span></td>
                            <td><span class="pd-badge pd-badge-{{ $paymentStatusTones[$summary['payment_status'] ?? 'odeme_bekliyor'] ?? 'gray' }}">{{ $summary['payment_status_label'] ?? '-' }}</span></td>
                            <td><span class="pd-badge pd-badge-gray">{{ $summary['delivery_financial_warning_label'] ?? 'Finans uyarısı yok' }}</span></td>
                            <td>
                                <div class="fin-actions">
                                    <a href="{{ route('admin.finance.show', $order) }}" class="pd-btn pd-btn-sm pd-btn-primary">Detay</a>
                                    <a href="{{ route('admin.orders.show', $order) }}" class="pd-btn pd-btn-sm pd-btn-light">Sipariş</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="13" class="fin-muted">Filtreye uygun finans kaydı bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Tahsilat Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Listelenen sipariş</span><strong>{{ $rows->count() }}</strong></div>
            <div class="pd-status-row"><span>Limit</span><strong>{{ (int) ($filters['limit'] ?? 50) }}</strong></div>
            <div class="pd-status-row"><span>Finans durumu</span><strong>{{ ($filters['payment_status'] ?? '') !== '' ? ($paymentStatusLabels[$filters['payment_status']] ?? $filters['payment_status']) : 'Tümü' }}</strong></div>
            <div class="pd-status-row"><span>Belge türü</span><strong>{{ ($filters['invoice_status'] ?? '') !== '' ? ($invoiceStatusLabels[$filters['invoice_status']] ?? $filters['invoice_status']) : 'Tümü' }}</strong></div>
            <div class="pd-status-row"><span>Teslimat uyarısı</span><strong>{{ ($filters['delivery_warning'] ?? '') !== '' ? ($deliveryWarningLabels[$filters['delivery_warning']] ?? $filters['delivery_warning']) : 'Tümü' }}</strong></div>
        </div>
        <div class="pd-side-note">
            İş Formu ve public takip ekranı finansal tutar göstermez. Teslimat modülüne yalnız güvenli uyarı etiketi aktarılır.
        </div>
    </div>
</div>
@endsection
