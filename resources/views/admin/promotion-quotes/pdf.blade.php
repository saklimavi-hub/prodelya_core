<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>{{ $quote->document_number }} Teklif</title>
    <style>
        @page { margin: 18px 20px; }
        body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color: #1f2937; font-size: 10.5px; line-height: 1.32; margin: 0; }
        .sheet { width: 100%; }
        .header { border-bottom: 1px solid #d8e1eb; padding-bottom: 8px; margin-bottom: 10px; }
        .header-table, .meta-table, .item-table, .totals-table, .sign-table { width: 100%; border-collapse: collapse; }
        .header-title { font-size: 19px; font-weight: 700; color: #111827; margin: 0 0 2px; }
        .header-subtitle { font-size: 10px; color: #64748b; }
        .header-right { text-align: right; white-space: nowrap; }
        .mini-meta { margin-top: 4px; color: #64748b; font-size: 10px; }
        .meta-table { margin-bottom: 8px; }
        .meta-table td { border: 1px solid #e7edf4; padding: 5px 7px; vertical-align: top; }
        .meta-label { width: 18%; background: #f8fafc; color: #64748b; font-weight: 700; }
        .meta-value { width: 32%; }
        .section-title { font-size: 11.5px; font-weight: 700; color: #111827; margin: 10px 0 5px; }
        .item-table th, .item-table td { border: 1px solid #e7edf4; padding: 5px 6px; vertical-align: top; }
        .item-table th { background: #f8fafc; color: #475569; font-size: 10px; font-weight: 700; letter-spacing: .02em; }
        .cell-no { width: 28px; text-align: right; }
        .cell-qty { width: 80px; text-align: right; white-space: nowrap; }
        .cell-money { width: 92px; text-align: right; white-space: nowrap; }
        .item-title { font-weight: 700; color: #111827; margin: 0 0 1px; }
        .item-note { color: #64748b; font-size: 9.5px; margin-top: 2px; }
        .print-inline { margin-top: 2px; color: #475569; font-size: 9.5px; line-height: 1.28; }
        .print-inline .segment { display: inline; }
        .totals-wrap { margin-top: 8px; width: 40%; margin-left: auto; }
        .totals-table td { padding: 4px 0; }
        .totals-label { color: #64748b; }
        .totals-value { text-align: right; font-weight: 700; color: #111827; white-space: nowrap; }
        .grand td { border-top: 1px solid #d8e1eb; padding-top: 8px; font-size: 12px; font-weight: 700; color: #111827; }
        .note-box { margin-top: 8px; border: 1px solid #e7edf4; padding: 7px 9px; color: #475569; }
        .approval-box { margin-top: 8px; border: 1px solid #d7e3f4; padding: 7px 9px; background: #fafcff; }
        .approval-box a { color: #1d4ed8; text-decoration: none; word-break: break-all; }
        .sign-table { margin-top: 12px; }
        .sign-table td { width: 50%; padding: 8px 10px 14px 0; vertical-align: top; }
        .sign-title { font-size: 10px; color: #64748b; font-weight: 700; margin-bottom: 16px; }
        .sign-line { border-top: 1px solid #d8e1eb; padding-top: 5px; color: #475569; font-size: 10px; }
        .footer { margin-top: 8px; border-top: 1px solid #e7edf4; padding-top: 6px; color: #64748b; font-size: 9px; line-height: 1.35; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="header">
        <table class="header-table">
            <tr>
                <td>
                    <div class="header-title">Promosyon Teklifi</div>
                    <div class="header-subtitle">Müşteri teklif özeti</div>
                </td>
                <td class="header-right">
                    <div class="header-title">{{ $quote->document_number }}</div>
                    <div class="mini-meta">{{ $quoteDate ?: '-' }} · {{ $currency }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Hazırlayan</td>
            <td class="meta-value">{{ $tenantName }}</td>
            <td class="meta-label">Müşteri</td>
            <td class="meta-value">{{ $customerName }}</td>
        </tr>
        <tr>
            <td class="meta-label">Belge Tipi</td>
            <td class="meta-value">{{ $documentTypeLabel }}</td>
            <td class="meta-label">Teklif Durumu</td>
            <td class="meta-value">{{ $quoteStatusLabel }}</td>
        </tr>
        <tr>
            <td class="meta-label">Fatura Durumu</td>
            <td class="meta-value">{{ $invoiceStatusLabel }}</td>
            <td class="meta-label">Müşteri Onayı</td>
            <td class="meta-value">{{ $customerApprovalStatusLabel }}</td>
        </tr>
        <tr>
            <td class="meta-label">Teslimat Tipi</td>
            <td class="meta-value">{{ $deliveryTypeLabel ?: 'Belirtilmedi' }}</td>
            <td class="meta-label">Geçerlilik</td>
            <td class="meta-value">{{ $validUntil ?: '-' }}</td>
        </tr>
        @if($customerEmail || $customerPhone)
            <tr>
                <td class="meta-label">İletişim</td>
                <td class="meta-value" colspan="3">
                    {{ $customerEmail ?: '' }}
                    @if($customerEmail && $customerPhone)
                        ·
                    @endif
                    {{ $customerPhone ?: '' }}
                </td>
            </tr>
        @endif
    </table>

    <div class="section-title">Ürün ve Baskı Kalemleri</div>
    <table class="item-table">
        <thead>
        <tr>
            <th class="cell-no">#</th>
            <th>Ürün / Baskı Bilgisi</th>
            <th class="cell-qty">Miktar</th>
            <th class="cell-money">Müşteri Fiyatı</th>
            <th class="cell-money">Müşteri Toplamı</th>
        </tr>
        </thead>
        <tbody>
        @foreach($items as $item)
            <tr>
                <td class="cell-no">{{ $item['index'] }}</td>
                <td>
                    <div class="item-title">{{ $item['product_name'] }}</div>
                    @if($item['customer_prints'])
                        <div class="print-inline">
                            @foreach($item['customer_prints'] as $print)
                                @php
                                    $segments = collect([
                                        $print['print_type'],
                                        $print['print_option'],
                                        $print['quantity_label'],
                                        $print['note'],
                                        $print['show_price_details'] ? 'Baskı Birim Fiyatı: ' . $print['unit_price_label'] : null,
                                        $print['show_price_details'] ? 'Baskı Toplamı: ' . $print['total_label'] : null,
                                    ])->filter(fn ($value) => filled($value))->values();
                                @endphp
                                <span class="segment">{{ $segments->implode(' · ') }}</span>@if(! $loop->last) <span class="segment"> | </span>@endif
                            @endforeach
                        </div>
                    @endif
                    @if($item['show_commercial_total'])
                        <div class="item-note">{{ $item['commercial_total_label'] }}: {{ number_format($item['commercial_line_total'], 2, ',', '.') }} {{ $currency }}</div>
                    @endif
                    @if($item['description'])
                        <div class="item-note">{{ $item['description'] }}</div>
                    @endif
                </td>
                <td class="cell-qty">{{ number_format($item['quantity'], 2, ',', '.') }} {{ $item['unit'] }}</td>
                <td class="cell-money">
                    <div>{{ number_format($item['customer_main_unit_price'], 2, ',', '.') }} {{ $currency }}</div>
                    <div class="item-note">{{ $item['main_unit_label'] }}</div>
                </td>
                <td class="cell-money">
                    <div>{{ number_format($item['customer_main_total'], 2, ',', '.') }} {{ $currency }}</div>
                    <div class="item-note">{{ $item['main_total_label'] }}</div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="totals-wrap">
        <table class="totals-table">
            <tr>
                <td class="totals-label">Ara Toplam</td>
                <td class="totals-value">{{ number_format((float) $quote->subtotal, 2, ',', '.') }} {{ $currency }}</td>
            </tr>
            @foreach($vatRows as $vatRow)
                <tr>
                    <td class="totals-label">{{ $vatRow['label'] }}</td>
                    <td class="totals-value">{{ number_format((float) $vatRow['amount'], 2, ',', '.') }} {{ $currency }}</td>
                </tr>
            @endforeach
            @if((float) $quote->vat_total > 0)
                <tr>
                    <td class="totals-label">KDV Toplamı</td>
                    <td class="totals-value">{{ number_format((float) $quote->vat_total, 2, ',', '.') }} {{ $currency }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td>Genel Toplam</td>
                <td class="totals-value">{{ number_format((float) $quote->grand_total, 2, ',', '.') }} {{ $currency }}</td>
            </tr>
        </table>
    </div>

    @if(filled($notes))
        <div class="note-box">
            <strong>Teklif Notu:</strong> {!! nl2br(e($notes)) !!}
        </div>
    @endif

    @if($approvalUrl)
        <div class="approval-box">
            <strong>Online Onay:</strong> Teklifinizi online inceleyip yanıtlamak için aşağıdaki bağlantıyı kullanabilirsiniz.<br>
            <a href="{{ $approvalUrl }}">{{ $approvalUrl }}</a>
        </div>
    @endif

    <table class="sign-table">
        <tr>
            <td>
                <div class="sign-title">Hazırlayan</div>
                <div class="sign-line">{{ $tenantName }}</div>
            </td>
            <td>
                <div class="sign-title">Müşteri Onayı</div>
                <div class="sign-line">Ad / İmza</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Bu belge müşteri teklif çıktısıdır. İç maliyet, teknik dosya yolları, tedarikçi alış fiyatı ve QR kod bu dokümanda yer almaz.
    </div>
</div>
</body>
</html>
