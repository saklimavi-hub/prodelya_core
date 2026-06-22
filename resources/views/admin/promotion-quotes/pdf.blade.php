<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>{{ $quote->document_number }} Teklif</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.45; margin: 0; }
        .sheet { width: 100%; }
        .header { border-bottom: 2px solid #d9e2ec; padding-bottom: 12px; margin-bottom: 18px; }
        .header-table, .meta-table, .totals-table, .item-table { width: 100%; border-collapse: collapse; }
        .header-title { font-size: 24px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .header-subtitle { font-size: 12px; color: #64748b; }
        .box-grid { margin-bottom: 16px; }
        .box-grid td { width: 50%; vertical-align: top; padding-right: 10px; }
        .panel { border: 1px solid #e4e8ef; border-radius: 10px; padding: 12px; background: #ffffff; }
        .panel-title { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }
        .panel-value { font-size: 13px; font-weight: 700; color: #111827; margin-bottom: 4px; }
        .panel-line { color: #475569; font-size: 12px; margin-bottom: 3px; }
        .meta-table td { padding: 8px 10px; border: 1px solid #e7edf4; }
        .meta-label { width: 25%; background: #f8fafc; color: #64748b; font-weight: 700; }
        .section-title { font-size: 14px; font-weight: 700; color: #0f172a; margin: 18px 0 8px; }
        .item-table th, .item-table td { border: 1px solid #e7edf4; padding: 8px 9px; vertical-align: top; }
        .item-table th { background: #f8fafc; color: #475569; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
        .item-title { font-weight: 700; color: #111827; margin-bottom: 4px; }
        .item-sub { color: #64748b; font-size: 11px; }
        .item-note { color: #475569; font-size: 11px; margin-top: 5px; }
        .print-list { margin-top: 6px; }
        .print-row { padding: 6px 8px; border: 1px solid #edf2f7; background: #fbfcfe; border-radius: 8px; margin-top: 6px; }
        .print-title { font-weight: 700; color: #111827; }
        .print-meta { color: #64748b; font-size: 11px; margin-top: 2px; }
        .print-note { color: #475569; font-size: 11px; margin-top: 4px; }
        .text-right { text-align: right; }
        .totals-wrap { margin-top: 16px; }
        .totals-table td { padding: 7px 0; }
        .totals-label { color: #64748b; }
        .totals-value { text-align: right; font-weight: 700; color: #111827; }
        .grand td { padding-top: 10px; border-top: 1px solid #dce5ef; font-size: 14px; font-weight: 700; color: #0f172a; }
        .note-box { margin-top: 16px; border: 1px solid #e7edf4; background: #fbfcfe; border-radius: 10px; padding: 12px; }
        .approval-box { margin-top: 16px; border: 1px solid #d7e3f4; background: #f8fbff; border-radius: 10px; padding: 12px; }
        .approval-box a { color: #1d4ed8; text-decoration: none; word-break: break-all; }
        .footer { margin-top: 18px; font-size: 11px; color: #64748b; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="header">
        <table class="header-table">
            <tr>
                <td>
                    <div class="header-title">Promosyon Teklifi</div>
                    <div class="header-subtitle">Müşteriye iletilebilecek satış teklif çıktısı</div>
                </td>
                <td class="text-right">
                    <div class="header-title">{{ $quote->document_number }}</div>
                    <div class="header-subtitle">{{ $quoteDate ?: '-' }} · {{ $currency }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="box-grid">
        <tr>
            <td>
                <div class="panel">
                    <div class="panel-title">Teklifi Hazırlayan</div>
                    <div class="panel-value">{{ $tenantName }}</div>
                    <div class="panel-line">Teklif No: {{ $quote->document_number }}</div>
                    <div class="panel-line">Teklif Tarihi: {{ $quoteDate ?: '-' }}</div>
                    <div class="panel-line">Geçerlilik: {{ $validUntil ?: '-' }}</div>
                </div>
            </td>
            <td>
                <div class="panel">
                    <div class="panel-title">Müşteri</div>
                    <div class="panel-value">{{ $customerName }}</div>
                    @if($customerEmail)
                        <div class="panel-line">{{ $customerEmail }}</div>
                    @endif
                    @if($customerPhone)
                        <div class="panel-line">{{ $customerPhone }}</div>
                    @endif
                    <div class="panel-line">Para Birimi: {{ $currency }}</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Belge Tipi</td>
            <td>Promosyon Teklifi</td>
            <td class="meta-label">Durum</td>
            <td>{{ $quote->displayQuoteStatusLabel() }}</td>
        </tr>
        <tr>
            <td class="meta-label">Fatura Durumu</td>
            <td>{{ $quote->invoice_status === 'fatura' ? 'Faturalı' : 'Fiş / KDV Hariç' }}</td>
            <td class="meta-label">Müşteri Onayı</td>
            <td>{{ $approvalStatusLabel ?: 'Bağlantı oluşturulmadı' }}</td>
        </tr>
    </table>

    <div class="section-title">Ürün ve Baskı Kalemleri</div>
    <table class="item-table">
        <thead>
        <tr>
            <th style="width: 42px;">#</th>
            <th>Ürün</th>
            <th style="width: 90px;" class="text-right">Miktar</th>
            <th style="width: 110px;" class="text-right">Birim Fiyat</th>
            <th style="width: 120px;" class="text-right">Toplam</th>
        </tr>
        </thead>
        <tbody>
        @foreach($items as $item)
            <tr>
                <td class="text-right">{{ $item['index'] }}</td>
                <td>
                    <div class="item-title">{{ $item['product_name'] }}</div>
                    <div class="item-sub">{{ $item['product_code'] }}</div>
                    @if($item['description'])
                        <div class="item-note">{{ $item['description'] }}</div>
                    @endif
                    @if(! empty($item['prints']))
                        <div class="print-list">
                            @foreach($item['prints'] as $print)
                                <div class="print-row">
                                    <div class="print-title">{{ $print['title'] ?: 'Baskı' }}</div>
                                    <div class="print-meta">
                                        {{ number_format($print['quantity'], 2, ',', '.') }} adet
                                        · {{ number_format($print['unit_price'], 2, ',', '.') }} {{ $currency }}
                                        · {{ number_format($print['total'], 2, ',', '.') }} {{ $currency }}
                                    </div>
                                    @if($print['note'])
                                        <div class="print-note">{{ $print['note'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </td>
                <td class="text-right">{{ number_format($item['quantity'], 2, ',', '.') }} {{ $item['unit'] }}</td>
                <td class="text-right">{{ number_format($item['unit_price'], 2, ',', '.') }} {{ $currency }}</td>
                <td class="text-right">{{ number_format($item['line_total'] + $item['print_total'], 2, ',', '.') }} {{ $currency }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="totals-wrap">
        <table class="totals-table">
            <tr>
                <td class="totals-label">Ürün Toplamı</td>
                <td class="totals-value">{{ number_format((float) $quote->product_total, 2, ',', '.') }} {{ $currency }}</td>
            </tr>
            <tr>
                <td class="totals-label">Baskı Toplamı</td>
                <td class="totals-value">{{ number_format((float) $quote->print_total, 2, ',', '.') }} {{ $currency }}</td>
            </tr>
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
                <td class="text-right">{{ number_format((float) $quote->grand_total, 2, ',', '.') }} {{ $currency }}</td>
            </tr>
        </table>
    </div>

    @if(filled($notes))
        <div class="note-box">
            <div class="panel-title">Teklif Notu</div>
            <div>{!! nl2br(e($notes)) !!}</div>
        </div>
    @endif

    @if($approvalUrl)
        <div class="approval-box">
            <div class="panel-title">Online Onay</div>
            <div>Teklifinizi online inceleyip yanıtlamak için aşağıdaki bağlantıyı kullanabilirsiniz:</div>
            <div style="margin-top: 8px;">
                <a href="{{ $approvalUrl }}">{{ $approvalUrl }}</a>
            </div>
        </div>
    @endif

    <div class="footer">
        Bu belge müşteri teklif çıktısıdır. İş formu, maliyet ve iç operasyon detayları bu dokümana dahil edilmez.
    </div>
</div>
</body>
</html>
