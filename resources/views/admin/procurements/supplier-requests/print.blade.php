@extends('layouts.prodelya-admin')

@section('title', 'Fiyatsız Tedarikçi Talep Formu')
@section('page_title', 'Fiyatsız Tedarikçi Talep Formu')
@section('page_subtitle', 'Dış tedarikçiye gönderilecek A4 talep formu.')
@section('hide_side_summary', '1')
@section('page_topbar_hidden', '1')

@php
    $requestDate = !empty($printData['request_date'])
        ? \Illuminate\Support\Carbon::parse($printData['request_date'])->format('d.m.Y')
        : '-';
@endphp

@section('content')
<style>
    .spr-print-shell {
        display: grid;
        gap: 14px;
        font-family: Arial, Helvetica, sans-serif;
    }
    .spr-print-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        padding: 14px 16px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    }
    .spr-print-toolbar-meta {
        color: #475569;
        font-size: 13px;
    }
    .spr-print-toolbar-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .a4-sheet {
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        background: #fff;
        border: 1px solid #dbe2ea;
        border-radius: 8px;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        padding: 16mm 14mm;
        color: #111827;
    }
    .spr-print-title {
        text-align: center;
        font-size: 28px;
        font-weight: 700;
        letter-spacing: 0.04em;
    }
    .spr-print-subtitle {
        margin-top: 6px;
        text-align: center;
        font-size: 12px;
        color: #64748b;
    }
    .spr-print-head {
        margin-top: 18px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
    }
    .spr-print-box {
        border: 1px solid #dbe2ea;
        border-radius: 6px;
        padding: 12px 14px;
        background: #fff;
    }
    .spr-print-box-title {
        margin-bottom: 10px;
        color: #64748b;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .spr-print-grid {
        display: grid;
        gap: 8px;
    }
    .spr-print-row {
        display: grid;
        grid-template-columns: 110px 1fr;
        gap: 8px;
        font-size: 13px;
        line-height: 1.45;
    }
    .spr-print-row-label {
        color: #475569;
        font-weight: 700;
    }
    .spr-print-row-value {
        color: #111827;
    }
    .spr-print-table-wrap {
        margin-top: 18px;
    }
    .spr-print-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .spr-print-table th,
    .spr-print-table td {
        border: 1px solid #dbe2ea;
        padding: 9px 8px;
        text-align: left;
        vertical-align: top;
        font-size: 12px;
        word-break: break-word;
    }
    .spr-print-table th {
        background: #f8fafc;
        color: #475569;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.03em;
    }
    .spr-print-qty {
        text-align: right;
        white-space: nowrap;
    }
    .spr-print-summary {
        margin-top: 14px;
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .spr-print-summary-note {
        color: #64748b;
        font-size: 12px;
        line-height: 1.5;
    }
    .spr-print-summary-total {
        font-size: 13px;
        font-weight: 700;
        color: #111827;
        text-align: right;
    }
    .spr-print-signatures {
        margin-top: 30px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 28px;
    }
    .spr-print-signature {
        border-top: 1px solid #cbd5e1;
        padding-top: 10px;
        min-height: 72px;
    }
    .spr-print-signature-title {
        font-size: 13px;
        font-weight: 700;
        color: #111827;
    }
    .spr-print-signature-subtitle {
        margin-top: 4px;
        color: #64748b;
        font-size: 12px;
    }
    .spr-print-footnote {
        margin-top: 24px;
        font-size: 12px;
        line-height: 1.6;
        color: #475569;
    }
    @media (max-width: 1100px) {
        .a4-sheet {
            width: 100%;
            min-height: auto;
            padding: 20px 16px;
        }
        .spr-print-head,
        .spr-print-signatures {
            grid-template-columns: 1fr;
        }
    }
    @media print {
        @page {
            size: A4;
            margin: 10mm;
        }
        html,
        body {
            background: #fff !important;
        }
        .pd-mobilebar,
        .pd-sidebar,
        .pd-topbar,
        .print-toolbar,
        .no-print {
            display: none !important;
        }
        .pd-page,
        .pd-layout,
        .pd-main,
        .pd-content {
            display: block !important;
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .a4-sheet {
            width: 100% !important;
            min-height: auto !important;
            margin: 0 !important;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
        }
    }
</style>

<div class="spr-print-shell">
    <div class="spr-print-toolbar print-toolbar no-print">
        <div class="spr-print-toolbar-meta">
            Tedarikçiye gönderilecek dış formda fiyat bilgileri yer almaz.
        </div>
        <div class="spr-print-toolbar-actions">
            <button type="button" class="pd-btn pd-btn-primary" onclick="window.print()">Yazdır</button>
            <a href="{{ route('admin.procurements.supplier-requests.edit', $requestRecord) }}" class="pd-btn pd-btn-light">Talebi Düzenle</a>
            <a href="{{ route('admin.procurements.index', ['supplier_id' => $requestRecord->supplier_id]) }}" class="pd-btn pd-btn-light">Tedarik Listesine Dön</a>
        </div>
    </div>

    <section class="a4-sheet">
        <div class="spr-print-title">TEDARİKÇİ TALEP FORMU</div>
        <div class="spr-print-subtitle">Bu form yalnız tedarik talebi içindir. Fiyat bilgileri içermez.</div>

        <div class="spr-print-head">
            <div class="spr-print-box">
                <div class="spr-print-box-title">Tedarikçi Bilgileri</div>
                <div class="spr-print-grid">
                    <div class="spr-print-row">
                        <div class="spr-print-row-label">Tedarikçi:</div>
                        <div class="spr-print-row-value">{{ $printData['supplier_name'] ?: '-' }}</div>
                    </div>
                    <div class="spr-print-row">
                        <div class="spr-print-row-label">Tarih:</div>
                        <div class="spr-print-row-value">{{ $requestDate }}</div>
                    </div>
                    <div class="spr-print-row">
                        <div class="spr-print-row-label">Telefon:</div>
                        <div class="spr-print-row-value">{{ $printData['supplier_phone'] ?: '-' }}</div>
                    </div>
                    <div class="spr-print-row">
                        <div class="spr-print-row-label">E-posta:</div>
                        <div class="spr-print-row-value">{{ $printData['supplier_email'] ?: '-' }}</div>
                    </div>
                </div>
            </div>

            <div class="spr-print-box">
                <div class="spr-print-box-title">Talep Bilgileri</div>
                <div class="spr-print-grid">
                    <div class="spr-print-row">
                        <div class="spr-print-row-label">Talep No:</div>
                        <div class="spr-print-row-value">{{ $printData['request_number'] ?: '-' }}</div>
                    </div>
                    <div class="spr-print-row">
                        <div class="spr-print-row-label">Yetkili:</div>
                        <div class="spr-print-row-value">{{ $printData['supplier_contact_name'] ?: '-' }}</div>
                    </div>
                    <div class="spr-print-row">
                        <div class="spr-print-row-label">Durum:</div>
                        <div class="spr-print-row-value">{{ $printData['status_label'] ?: '-' }}</div>
                    </div>
                    <div class="spr-print-row">
                        <div class="spr-print-row-label">Hazırlayan:</div>
                        <div class="spr-print-row-value">{{ $printData['prepared_by'] ?: '-' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="spr-print-table-wrap">
            <table class="spr-print-table">
                <thead>
                    <tr>
                        <th style="width: 16%;">Sipariş No</th>
                        <th style="width: 18%;">Ürün Kodu</th>
                        <th style="width: 32%;">Ürün Adı</th>
                        <th style="width: 18%;">Not</th>
                        <th style="width: 9%;">İstenen Adet</th>
                        <th style="width: 7%;">Birim</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($printData['items'] as $item)
                        <tr>
                            <td>{{ $item['order_number'] ?: '-' }}</td>
                            <td>{{ $item['product_code'] ?: '-' }}</td>
                            <td>{{ $item['product_name'] ?: '-' }}</td>
                            <td>{{ $item['note'] ?: '-' }}</td>
                            <td class="spr-print-qty">{{ number_format((float) $item['requested_quantity'], 2, ',', '.') }}</td>
                            <td>{{ $item['unit'] ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">Talep formunda gösterilecek kalem bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="spr-print-summary">
            <div class="spr-print-summary-note">
                Talep edilen kalem sayısı: {{ number_format((float) ($printData['item_count'] ?? 0), 0, ',', '.') }}<br>
                @if($printData['has_multiple_units'] ?? false)
                    Toplamlar birim bazında değerlendirilmelidir.
                @else
                    Toplam miktar tek birim üzerinden özetlenmiştir.
                @endif
            </div>
            <div class="spr-print-summary-total">
                @if(($printData['has_multiple_units'] ?? false) && !empty($printData['unit_totals']))
                    @foreach($printData['unit_totals'] as $unitTotal)
                        <div>Toplam: {{ number_format((float) $unitTotal['quantity'], 2, ',', '.') }} {{ $unitTotal['unit'] }}</div>
                    @endforeach
                @else
                    <div>Toplam: {{ number_format((float) ($printData['total_quantity'] ?? 0), 2, ',', '.') }} {{ $printData['unit_totals'][0]['unit'] ?? 'Adet' }}</div>
                @endif
            </div>
        </div>

        <div class="spr-print-signatures">
            <div class="spr-print-signature">
                <div class="spr-print-signature-title">Tedarikçi Yetkilisi</div>
                <div class="spr-print-signature-subtitle">İmza / Tarih</div>
            </div>
            <div class="spr-print-signature">
                <div class="spr-print-signature-title">Firma Yetkilisi</div>
                <div class="spr-print-signature-subtitle">İmza / Tarih</div>
            </div>
        </div>

        <div class="spr-print-footnote">
            Bu form, belirtilen ürünlerin tedarik edilmesi için hazırlanmış bir talep formudur.
            Teslim süresi ve koşullar için tedarikçi ile iletişime geçiniz.
        </div>
    </section>
</div>
@endsection
