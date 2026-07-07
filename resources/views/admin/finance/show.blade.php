@extends('layouts.prodelya-admin')

@section('title', 'Finans Özeti')
@section('page_title', 'Finans Özeti')
@section('page_subtitle', 'Finans Detayı görünümünde siparişin müşteri alacağını, tahsilat durumunu ve karşı borçlarını tek bakışta inceleyin.')

@section('content')
@php
    $currency = $summary['currency'] ?? ($order->currency ?: 'TL');
    $money = static fn ($amount, $rowCurrency = null) => number_format((float) $amount, 2, ',', '.') . ' ' . ($rowCurrency ?: $currency);
    $overview = $financeOverview;
    $customerReceivable = $overview['customer_receivable'];
    $supplierDebts = $overview['supplier_debts'];
    $subcontractorDebts = $overview['subcontractor_debts'];
    $overall = $overview['overall'];
    $toneClass = static fn (?string $tone): string => 'pd-badge-' . ($tone ?: 'gray');
    $paymentStatusTones = [
        'odeme_bekliyor' => 'amber',
        'kismi_odeme' => 'blue',
        'odendi' => 'green',
        'fazla_odeme' => 'purple',
        'vade_bekliyor' => 'gray',
        'tahsilat_uyarisi' => 'red',
        'iptal' => 'gray',
    ];
    $vatScopeLabels = [
        'product' => 'Ürün',
        'print' => 'Baskı',
        'general' => 'Genel',
    ];
    $vatBreakdownRows = $summary['vat_breakdown'] ?? [];
    if (empty($vatBreakdownRows) && is_array($order->vat_breakdown_json ?? null)) {
        $vatBreakdownRows = $order->vat_breakdown_json;
    }
@endphp

<style>
    .ofs-shell { display: grid; gap: 14px; }
    .ofs-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
    .ofs-card-body { padding: 16px; }
    .ofs-head { display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; flex-wrap: wrap; }
    .ofs-title { margin: 0; color: #111827; font-size: 17px; font-weight: 700; }
    .ofs-subtitle { margin-top: 4px; color: #6b7280; font-size: 12px; line-height: 1.45; }
    .ofs-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .ofs-top-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; }
    .ofs-summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    .ofs-main-grid { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(320px, .9fr); gap: 14px; }
    .ofs-stack { display: grid; gap: 14px; }
    .ofs-box { border: 1px solid #e5e7eb; border-radius: 6px; background: #f9fafb; padding: 12px; }
    .ofs-label { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 4px; }
    .ofs-value { color: #111827; font-size: 13px; font-weight: 600; line-height: 1.45; word-break: break-word; }
    .ofs-money { color: #111827; font-size: 18px; font-weight: 700; white-space: nowrap; }
    .ofs-note { border: 1px solid #dbeafe; border-radius: 6px; background: #f8fbff; padding: 10px 12px; color: #475569; font-size: 12px; line-height: 1.5; }
    .ofs-warning { border-color: #fecaca; background: #fff7f7; color: #991b1b; }
    .ofs-table-wrap { overflow: auto; }
    .ofs-table { width: 100%; border-collapse: collapse; }
    .ofs-table th, .ofs-table td { padding: 11px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
    .ofs-table th { background: #f9fafb; color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .ofs-table tr:last-child td { border-bottom: 0; }
    .ofs-dim { color: #6b7280; font-size: 12px; line-height: 1.45; }
    .ofs-inline-links { display: flex; flex-wrap: wrap; gap: 8px; }
    .ofs-list { display: grid; gap: 8px; }
    .ofs-list-row { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 10px 12px; }
    .ofs-list-row strong { color: #111827; display: block; margin-bottom: 4px; }
    .ofs-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; align-items: end; }
    .ofs-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 12px; font-weight: 600; }
    .ofs-subnote { margin-top: 4px; color: #6b7280; font-size: 11px; }
    .ofs-kpi-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    @media (max-width: 1180px) {
        .ofs-main-grid, .ofs-top-grid, .ofs-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 768px) {
        .ofs-main-grid, .ofs-top-grid, .ofs-summary-grid, .ofs-form-grid, .ofs-kpi-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="ofs-shell">
    @if(session('success'))
        <div class="pd-alert">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="pd-alert-warning">{{ $errors->first() }}</div>
    @endif

    <div class="ofs-actions">
        <a href="{{ route('admin.finance.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
        <a href="{{ route('admin.orders.show', $order) }}" class="pd-btn pd-btn-primary">Siparişi Aç</a>
        @if($customerReceivable['current_account_url'])
            <a href="{{ $customerReceivable['current_account_url'] }}" class="pd-btn pd-btn-light">Cari Ekstreye Git</a>
        @endif
        @if($workForm)
            <a href="{{ route('admin.work-forms.show', $workForm) }}" class="pd-btn pd-btn-light">İş Formu Aç</a>
        @endif
        @if($delivery)
            <a href="{{ route('admin.deliveries.show', $delivery) }}" class="pd-btn pd-btn-light">Teslimat Ekranı</a>
        @endif
    </div>

    <section class="ofs-card">
        <div class="ofs-card-body ofs-stack">
            <div class="ofs-head">
                <div>
                    <h2 class="ofs-title">{{ $order->document_number ?: 'Sipariş' }}</h2>
                    <div class="ofs-subtitle">
                        {{ $order->customer?->legal_name ?: 'Müşteri bilgisi yok' }} ·
                        {{ optional($order->quote_date ?: $order->created_at)->format('d.m.Y') ?: '-' }} ·
                        {{ $currency }}
                    </div>
                </div>
                <div class="ofs-actions">
                    <span class="pd-badge {{ $toneClass($overall['status_tone']) }}">{{ $overall['status_label'] }}</span>
                    <span class="pd-badge {{ $toneClass($paymentStatusTones[$summary['payment_status'] ?? 'odeme_bekliyor'] ?? 'gray') }}">{{ $summary['payment_status_label'] ?? 'Durum yok' }}</span>
                </div>
            </div>

            <div class="ofs-top-grid">
                <div class="ofs-box"><div class="ofs-label">Sipariş No</div><div class="ofs-value">{{ $order->document_number ?: '-' }}</div></div>
                <div class="ofs-box"><div class="ofs-label">Müşteri</div><div class="ofs-value">{{ $order->customer?->legal_name ?: '-' }}</div></div>
                <div class="ofs-box"><div class="ofs-label">Sipariş Tarihi</div><div class="ofs-value">{{ optional($order->quote_date ?: $order->created_at)->format('d.m.Y') ?: '-' }}</div></div>
                <div class="ofs-box"><div class="ofs-label">Sipariş Durumu</div><div class="ofs-value">{{ ucfirst(str_replace('_', ' ', (string) $order->status)) }}</div></div>
                <div class="ofs-box"><div class="ofs-label">Belge Türü</div><div class="ofs-value">{{ $summary['invoice_status_label'] ?? 'Fiş' }}</div></div>
                <div class="ofs-box"><div class="ofs-label">Para Birimi</div><div class="ofs-value">{{ $currency }}</div></div>
                <div class="ofs-box"><div class="ofs-label">Sipariş Toplamı</div><div class="ofs-money">{{ $customerReceivable['formatted_order_total'] }}</div></div>
            </div>

            <div class="ofs-box">
                <div class="ofs-label">Teslimat Finans Uyarısı</div>
                <div class="ofs-value">{{ $summary['delivery_financial_warning_label'] ?? 'Finans uyarısı yok' }}</div>
            </div>

            @if(!empty($overall['warnings']))
                <div class="ofs-note ofs-warning">
                    {{ implode(' ', $overall['warnings']) }}
                </div>
            @endif

            <div class="ofs-note">
                <strong>Karşı Borçlar</strong><br>
                Tedarikçi, fason ve kargo karşı borçları mevcut cari ekstrelerinde izlenir. Bu ekranda siparişe güvenilir şekilde bağlı tedarikçi ve fason borçları ayrıca özetlenir.
            </div>
        </div>
    </section>

    <div class="ofs-main-grid">
        <div class="ofs-stack">
            <section class="ofs-card">
                <div class="ofs-card-body ofs-stack">
                    <div class="ofs-head">
                        <div>
                            <h3 class="ofs-title">Müşteri Alacağı</h3>
                            <div class="ofs-subtitle">Siparişten oluşan müşteri borcu ve tahsilat akışı birlikte gösterilir.</div>
                        </div>
                        <span class="pd-badge {{ $toneClass($customerReceivable['status_tone']) }}">{{ $customerReceivable['status_label'] }}</span>
                    </div>

                    <div class="ofs-summary-grid">
                        <div class="ofs-box">
                            <div class="ofs-label">Sipariş Toplamı</div>
                            <div class="ofs-money">{{ $customerReceivable['formatted_order_total'] }}</div>
                        </div>
                        <div class="ofs-box">
                            <div class="ofs-label">Sipariş Müşteri Borcu</div>
                            <div class="ofs-value">{{ $customerReceivable['debit_transaction'] ? 'Oluşturuldu' : 'Henüz oluşmadı' }}</div>
                            <div class="ofs-subnote">{{ $customerReceivable['formatted_debit_amount'] }}</div>
                        </div>
                        <div class="ofs-box">
                            <div class="ofs-label">Tahsil Edilen</div>
                            <div class="ofs-money">{{ $customerReceivable['formatted_collected_amount'] }}</div>
                        </div>
                        <div class="ofs-box">
                            <div class="ofs-label">Kalan Bakiye</div>
                            <div class="ofs-money">{{ $customerReceivable['formatted_remaining_amount'] }}</div>
                            <div class="ofs-subnote">Kalan Alacak</div>
                        </div>
                    </div>

                    <div class="ofs-inline-links">
                        <a href="{{ $customerReceivable['collection_url'] }}" class="pd-btn pd-btn-primary">Tahsilat Gir</a>
                        @if($customerReceivable['current_account_url'])
                            <a href="{{ $customerReceivable['current_account_url'] }}" class="pd-btn pd-btn-light">Cari Ekstrede Gör</a>
                        @endif
                        <a href="{{ $customerReceivable['order_url'] }}" class="pd-btn pd-btn-light">Siparişi Aç</a>
                    </div>

                    @if($customerReceivable['missing_message'])
                        <div class="ofs-note ofs-warning">{{ $customerReceivable['missing_message'] }}</div>
                    @endif

                    <div class="ofs-table-wrap">
                        <table class="ofs-table">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Tahsilat</th>
                                    <th>Yöntem</th>
                                    <th>Tutar</th>
                                    <th>Belge No</th>
                                    <th>Açıklama</th>
                                    <th>Durum</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($payments as $payment)
                                    <tr id="tahsilat-{{ $payment->id }}">
                                        <td>{{ optional($payment->paid_at ?? $payment->created_at)->format('d.m.Y H:i') ?: '-' }}</td>
                                        <td>{{ $payment->safePaymentTypeLabel() }}</td>
                                        <td>{{ $payment->safePaymentMethodLabel() ?: '-' }}</td>
                                        <td>{{ $money($payment->amount, $payment->currency ?: $currency) }}</td>
                                        <td>{{ $payment->payment_reference ?: '-' }}</td>
                                        <td>
                                            {{ $payment->payment_note ?: '-' }}
                                            @if($payment->isCancelled())
                                                <div class="ofs-subnote">Summary hesabına dahil edilmez.</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($payment->isCancelled())
                                                <span class="pd-badge pd-badge-gray">İptal / Hesap dışı</span>
                                            @elseif($payment->paid_at)
                                                <span class="pd-badge pd-badge-green">İşlendi</span>
                                            @elseif($payment->due_date)
                                                <span class="pd-badge pd-badge-amber">Vade Bekliyor</span>
                                            @else
                                                <span class="pd-badge pd-badge-gray">Bekliyor</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="ofs-inline-links">
                                                <a href="{{ route('admin.finance.show', $order) }}#tahsilat-{{ $payment->id }}" class="pd-btn pd-btn-light pd-btn-sm">Tahsilatı Aç</a>
                                                @if(!$payment->isCancelled() && $canManagePayments)
                                                    <form method="POST" action="{{ route('admin.finance.payments.cancel', ['order' => $order, 'payment' => $payment]) }}">
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Tahsilatı İptal Et</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="ofs-dim">Siparişe bağlı tahsilat bulunamadı.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="ofs-card">
                <div class="ofs-card-body ofs-stack">
                    <div class="ofs-head">
                        <div>
                            <h3 class="ofs-title">Tedarikçi Borçları</h3>
                            <div class="ofs-subtitle">Siparişe bağlı tedarik borçları gerçek kaynak ilişkileriyle listelenir.</div>
                        </div>
                        <span class="pd-badge {{ $toneClass($supplierDebts['status_tone']) }}">{{ $supplierDebts['status_label'] }}</span>
                    </div>

                    <div class="ofs-kpi-grid">
                        <div class="ofs-box"><div class="ofs-label">Toplam Tedarik Borcu</div><div class="ofs-money">{{ $supplierDebts['formatted_total_debt'] }}</div></div>
                        <div class="ofs-box"><div class="ofs-label">Ödenen</div><div class="ofs-value">{{ $supplierDebts['formatted_paid_amount'] }}</div></div>
                        <div class="ofs-box"><div class="ofs-label">Kalan</div><div class="ofs-value">{{ $supplierDebts['formatted_remaining_amount'] }}</div></div>
                        <div class="ofs-box"><div class="ofs-label">Açık Tedarikçi Sayısı</div><div class="ofs-value">{{ $supplierDebts['open_party_count'] }}</div></div>
                    </div>

                    <div class="ofs-table-wrap">
                        <table class="ofs-table">
                            <thead>
                                <tr>
                                    <th>Tedarikçi</th>
                                    <th>Kaynak</th>
                                    <th>Borç</th>
                                    <th>Ödenen</th>
                                    <th>Kalan</th>
                                    <th>Durum</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($supplierDebts['items'] as $item)
                                    <tr>
                                        <td>{{ $item['supplier_name'] }}</td>
                                        <td>{{ $item['source_label'] }}</td>
                                        <td>{{ $item['formatted_debt_amount'] }}</td>
                                        <td>{{ $item['formatted_paid_amount'] }}</td>
                                        <td>{{ $item['formatted_remaining_amount'] }}</td>
                                        <td>
                                            <span class="pd-badge {{ $toneClass($item['status_tone']) }}">{{ $item['status_label'] }}</span>
                                            @if($item['tracking_note'])
                                                <div class="ofs-subnote">{{ $item['tracking_note'] }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="ofs-inline-links">
                                                @if($item['source_url'])
                                                    <a href="{{ $item['source_url'] }}" class="pd-btn pd-btn-light pd-btn-sm">Tedarik Kaydını Aç</a>
                                                @endif
                                                @if($item['current_account_url'])
                                                    <a href="{{ $item['current_account_url'] }}" class="pd-btn pd-btn-light pd-btn-sm">Cari Ekstreye Git</a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="ofs-dim">{{ $supplierDebts['empty_label'] }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="ofs-card">
                <div class="ofs-card-body ofs-stack">
                    <div class="ofs-head">
                        <div>
                            <h3 class="ofs-title">Fason / Üretim Borçları</h3>
                            <div class="ofs-subtitle">Siparişe bağlı fason ve üretim borçları aynı özet içinde gösterilir.</div>
                        </div>
                        <span class="pd-badge {{ $toneClass($subcontractorDebts['status_tone']) }}">{{ $subcontractorDebts['status_label'] }}</span>
                    </div>

                    <div class="ofs-kpi-grid">
                        <div class="ofs-box"><div class="ofs-label">Toplam Fason Borcu</div><div class="ofs-money">{{ $subcontractorDebts['formatted_total_debt'] }}</div></div>
                        <div class="ofs-box"><div class="ofs-label">Ödenen</div><div class="ofs-value">{{ $subcontractorDebts['formatted_paid_amount'] }}</div></div>
                        <div class="ofs-box"><div class="ofs-label">Kalan</div><div class="ofs-value">{{ $subcontractorDebts['formatted_remaining_amount'] }}</div></div>
                        <div class="ofs-box"><div class="ofs-label">Açık Fasoncu Sayısı</div><div class="ofs-value">{{ $subcontractorDebts['open_party_count'] }}</div></div>
                    </div>

                    <div class="ofs-table-wrap">
                        <table class="ofs-table">
                            <thead>
                                <tr>
                                    <th>Fasoncu / Üretim Kaynağı</th>
                                    <th>İş / Kaynak No</th>
                                    <th>Borç</th>
                                    <th>Ödenen</th>
                                    <th>Kalan</th>
                                    <th>Durum</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($subcontractorDebts['items'] as $item)
                                    <tr>
                                        <td>{{ $item['supplier_name'] }}</td>
                                        <td>{{ $item['source_label'] }}</td>
                                        <td>{{ $item['formatted_debt_amount'] }}</td>
                                        <td>{{ $item['formatted_paid_amount'] }}</td>
                                        <td>{{ $item['formatted_remaining_amount'] }}</td>
                                        <td>
                                            <span class="pd-badge {{ $toneClass($item['status_tone']) }}">{{ $item['status_label'] }}</span>
                                            @if($item['tracking_note'])
                                                <div class="ofs-subnote">{{ $item['tracking_note'] }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="ofs-inline-links">
                                                @if($item['source_url'])
                                                    <a href="{{ $item['source_url'] }}" class="pd-btn pd-btn-light pd-btn-sm">Üretim Kaydını Aç</a>
                                                @endif
                                                @if($item['current_account_url'])
                                                    <a href="{{ $item['current_account_url'] }}" class="pd-btn pd-btn-light pd-btn-sm">Cari Ekstreye Git</a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="ofs-dim">{{ $subcontractorDebts['empty_label'] }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="ofs-card">
                <div class="ofs-card-body ofs-stack">
                    <div class="ofs-head">
                        <div>
                            <h3 class="ofs-title">Finans Hareketleri</h3>
                            <div class="ofs-subtitle">Siparişe güvenilir şekilde bağlı hareketler kısa döküm halinde gösterilir.</div>
                        </div>
                    </div>

                    <div class="ofs-table-wrap">
                        <table class="ofs-table">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Hareket</th>
                                    <th>Kaynak</th>
                                    <th>Açıklama</th>
                                    <th>Borç</th>
                                    <th>Alacak</th>
                                    <th>Durum</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($overview['movements'] as $row)
                                    <tr>
                                        <td>{{ $row['date_label'] }}</td>
                                        <td>{{ $row['movement_label'] }}</td>
                                        <td>{{ $row['source_label'] }}</td>
                                        <td>{{ $row['description'] }}</td>
                                        <td>{{ $row['debit_label'] }}</td>
                                        <td>{{ $row['credit_label'] }}</td>
                                        <td><span class="pd-badge {{ $toneClass($row['status_tone']) }}">{{ $row['status_label'] }}</span></td>
                                        <td>
                                            @if($row['action_url'])
                                                <a href="{{ $row['action_url'] }}" class="pd-btn pd-btn-light pd-btn-sm">{{ $row['action_label'] }}</a>
                                            @else
                                                <span class="ofs-dim">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="ofs-dim">Gösterilecek sipariş hareketi bulunamadı.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <div class="ofs-stack">
            <section class="ofs-card">
                <div class="ofs-card-body ofs-stack">
                    <div class="ofs-head">
                        <div>
                            <h3 class="ofs-title">KDV ve Sipariş Toplamı</h3>
                            <div class="ofs-subtitle">Sipariş toplamı ve KDV kırılımı ayrı ama sade şekilde gösterilir.</div>
                        </div>
                    </div>

                    <div class="ofs-kpi-grid">
                        <div class="ofs-box"><div class="ofs-label">Ürün Toplamı</div><div class="ofs-value">{{ $money($summary['product_total'] ?? 0) }}</div></div>
                        <div class="ofs-box"><div class="ofs-label">Baskı Toplamı</div><div class="ofs-value">{{ $money($summary['print_total'] ?? 0) }}</div></div>
                        <div class="ofs-box"><div class="ofs-label">Ara Toplam</div><div class="ofs-value">{{ $money($summary['subtotal'] ?? 0) }}</div></div>
                        <div class="ofs-box"><div class="ofs-label">KDV</div><div class="ofs-value">{{ $money($summary['vat_total'] ?? 0) }}</div></div>
                    </div>

                    @if(($summary['invoice_status'] ?? 'fis') !== 'fatura')
                        <div class="ofs-note">Fiş seçildiği için KDV hesaplanmaz. Bu siparişte genel toplam, ara toplam ile aynıdır.</div>
                    @else
                        <div class="ofs-table-wrap">
                            <table class="ofs-table">
                                <thead>
                                    <tr>
                                        <th>Kapsam</th>
                                        <th>Oran</th>
                                        <th>Tutar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($vatBreakdownRows as $slice)
                                        <tr>
                                            <td>{{ $vatScopeLabels[$slice['scope'] ?? 'general'] ?? 'Genel' }}</td>
                                            <td>%{{ rtrim(rtrim(number_format((float) ($slice['rate'] ?? 0), 2, ',', '.'), '0'), ',') }}</td>
                                            <td>{{ $money($slice['total'] ?? 0) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="ofs-dim">KDV kırılımı bulunamadı.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>

            <section class="ofs-card" id="tahsilat-formu">
                <div class="ofs-card-body ofs-stack">
                    <div class="ofs-head">
                        <div>
                            <h3 class="ofs-title">Tahsilat Gir</h3>
                            <div class="ofs-subtitle">Tahsilat mevcut sipariş tahsilat akışı üzerinden kaydedilir.</div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.finance.payments.store', $order) }}" class="ofs-stack">
                        @csrf
                        <div class="ofs-form-grid">
                            <div class="ofs-field">
                                <label>Tahsilat Türü</label>
                                <select name="payment_type" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                                    @foreach($paymentTypeLabels as $key => $label)
                                        <option value="{{ $key }}" @selected(old('payment_type', 'tahsilat') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="ofs-field">
                                <label>Tutar</label>
                                <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" placeholder="0,00" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                            </div>
                            <div class="ofs-field">
                                <label>Para Birimi</label>
                                <input type="text" name="currency" value="{{ old('currency', $currency) }}" readonly>
                            </div>
                            <div class="ofs-field">
                                <label>Ödeme Yöntemi</label>
                                <select name="payment_method" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                                    <option value="">Seçiniz</option>
                                    @foreach($paymentMethodLabels as $key => $label)
                                        <option value="{{ $key }}" @selected(old('payment_method') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="ofs-field">
                                <label>Tahsilat Tarihi</label>
                                <input type="datetime-local" name="paid_at" value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                            </div>
                            <div class="ofs-field">
                                <label>Vade Tarihi</label>
                                <input type="datetime-local" name="due_date" value="{{ old('due_date') }}" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                            </div>
                            <div class="ofs-field">
                                <label>Referans No</label>
                                <input type="text" name="payment_reference" value="{{ old('payment_reference') }}" placeholder="EFT / POS / Evrak no" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                            </div>
                            <div class="ofs-field" style="grid-column: 1 / -1;">
                                <label>Açıklama</label>
                                <textarea name="payment_note" rows="3" placeholder="Tahsilat notu" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>{{ old('payment_note') }}</textarea>
                            </div>
                        </div>

                        <div class="ofs-note">
                            Bu form mevcut tahsilat akışını kullanır. Sipariş müşteri borcu ham cari hareket olarak iptal edilmez.
                        </div>

                        <div class="ofs-actions">
                            <button type="submit" class="pd-btn pd-btn-primary" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>Tahsilat Kaydet</button>
                        </div>
                    </form>

                    <div class="ofs-actions">
                        <form method="POST" action="{{ route('admin.finance.mark-paid', $order) }}">
                            @csrf
                            <input type="hidden" name="payment_method" value="diger">
                            <button
                                type="submit"
                                class="pd-btn pd-btn-success"
                                @disabled(!$canManagePayments && !$canMarkPaymentsReceived || (($summary['balance_due'] ?? 0) <= 0))
                            >
                                Ödendi İşaretle
                            </button>
                        </form>
                    </div>

                    @if(($summary['balance_due'] ?? 0) <= 0)
                        <div class="ofs-subnote">Sipariş zaten ödenmiş veya fazla ödemeli görünüyor; yeni “ödendi” kaydı oluşturulmaz.</div>
                    @endif
                </div>
            </section>

            <section class="ofs-card">
                <div class="ofs-card-body ofs-stack">
                    <div class="ofs-head">
                        <div>
                            <h3 class="ofs-title">Genel Değerlendirme</h3>
                            <div class="ofs-subtitle">Bir sonraki kontrol adımları kısa ve sade şekilde listelenir.</div>
                        </div>
                    </div>

                    <div class="ofs-list">
                        <div class="ofs-list-row">
                            <strong>Finans Durumu</strong>
                            <div class="ofs-dim">{{ $overall['status_label'] }}</div>
                        </div>
                        <div class="ofs-list-row">
                            <strong>Teslimat Finans Uyarısı</strong>
                            <div class="ofs-dim">{{ $summary['delivery_financial_warning_label'] ?? 'Finans uyarısı yok' }}</div>
                        </div>
                        @foreach($overall['next_actions'] as $nextAction)
                            <div class="ofs-list-row">
                                <strong>Sonraki Adım</strong>
                                <div class="ofs-dim">{{ $nextAction }}</div>
                            </div>
                        @endforeach
                        <div class="ofs-list-row">
                            <strong>Güvenlik Notu</strong>
                            <div class="ofs-dim">Bu ekranda yalnız finans özeti gösterilir. Teknik alanlar ve ham veriler kullanıcı yüzeyine açılmaz.</div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Sipariş Finans Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Sipariş No</span><strong>{{ $order->document_number ?: '-' }}</strong></div>
            <div class="pd-status-row"><span>Müşteri Borcu</span><strong>{{ $customerReceivable['formatted_debit_amount'] }}</strong></div>
            <div class="pd-status-row"><span>Tahsil Edilen</span><strong>{{ $customerReceivable['formatted_collected_amount'] }}</strong></div>
            <div class="pd-status-row"><span>Kalan Alacak</span><strong>{{ $customerReceivable['formatted_remaining_amount'] }}</strong></div>
            <div class="pd-status-row"><span>Toplam Tedarik Borcu</span><strong>{{ $supplierDebts['formatted_total_debt'] }}</strong></div>
            <div class="pd-status-row"><span>Toplam Fason Borcu</span><strong>{{ $subcontractorDebts['formatted_total_debt'] }}</strong></div>
            <div class="pd-status-row"><span>Finans Durumu</span><strong>{{ $overall['status_label'] }}</strong></div>
            <div class="pd-status-row"><span>Teslimat Uyarısı</span><strong>{{ $summary['delivery_financial_warning_label'] ?? 'Finans uyarısı yok' }}</strong></div>
        </div>
    </div>
</div>
@endsection
