@extends('layouts.prodelya-admin')

@section('title', 'Fiyatsız Talep Formu')
@section('page_title', 'Fiyatsız Talep Formu')
@section('page_subtitle', 'Tedarikçiye gönderilecek fiyat içermeyen A4 talep formu.')
@section('hide_side_summary', '1')
@section('page_topbar_hidden', '1')

@php
    $requestDate = !empty($printData['request_date']) ? \Illuminate\Support\Carbon::parse($printData['request_date'])->format('d.m.Y') : '-';
@endphp

@section('content')
<style>
    .prp-shell { display:grid; gap:14px; }
    .prp-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:14px 16px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 12px 28px rgba(15,23,42,.05); }
    .prp-toolbar-note { color:#475569; font-size:13px; }
    .prp-toolbar-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .prp-sheet { width:210mm; min-height:297mm; margin:0 auto; background:#fff; border:1px solid #dbe2ea; border-radius:10px; box-shadow:0 18px 40px rgba(15,23,42,.08); padding:16mm 14mm; color:#111827; }
    .prp-headline { text-align:center; font-size:28px; font-weight:700; letter-spacing:.03em; }
    .prp-subline { margin-top:6px; text-align:center; color:#64748b; font-size:12px; }
    .prp-grid { margin-top:18px; display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .prp-box { border:1px solid #dbe2ea; border-radius:8px; padding:12px 14px; background:#fff; }
    .prp-box-title { margin-bottom:10px; color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .prp-row { display:grid; grid-template-columns:120px 1fr; gap:8px; font-size:13px; line-height:1.45; }
    .prp-row-label { color:#475569; font-weight:700; }
    .prp-table-wrap { margin-top:18px; }
    .prp-table { width:100%; border-collapse:collapse; table-layout:fixed; }
    .prp-table th, .prp-table td { border:1px solid #dbe2ea; padding:9px 8px; text-align:left; vertical-align:top; font-size:12px; word-break:break-word; }
    .prp-table th { background:#f8fafc; color:#475569; font-weight:700; text-transform:uppercase; font-size:11px; }
    .prp-qty { text-align:right; white-space:nowrap; }
    .prp-summary { margin-top:14px; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .prp-summary-note { color:#64748b; font-size:12px; line-height:1.5; max-width:60%; }
    .prp-summary-total { font-size:13px; font-weight:700; color:#111827; text-align:right; }
    .prp-signatures { margin-top:30px; display:grid; grid-template-columns:1fr 1fr; gap:28px; }
    .prp-signature { border-top:1px solid #cbd5e1; padding-top:10px; min-height:72px; }
    .prp-footnote { margin-top:24px; font-size:12px; line-height:1.6; color:#475569; }
    @media (max-width:1100px) {
        .prp-sheet { width:100%; min-height:auto; padding:20px 16px; }
        .prp-grid, .prp-signatures { grid-template-columns:1fr; }
        .prp-summary-note { max-width:none; }
    }
    @media print {
        @page { size:A4; margin:10mm; }
        html, body { background:#fff !important; }
        .pd-mobilebar, .pd-sidebar, .pd-topbar, .print-toolbar, .no-print { display:none !important; }
        .pd-page, .pd-layout, .pd-main, .pd-content { display:block !important; width:100% !important; max-width:none !important; margin:0 !important; padding:0 !important; }
        .prp-sheet { width:100% !important; min-height:auto !important; margin:0 !important; border:0 !important; border-radius:0 !important; box-shadow:none !important; padding:0 !important; }
    }
</style>

<div class="prp-shell" data-procurement-reference-family="price-free-print">
    <div class="prp-toolbar print-toolbar no-print">
        <div class="prp-toolbar-note">Bu belge tedarikçiye gönderilen fiyatsız talep formudur. Alış, satış, KDV, toplam veya cari bakiye alanları bulunmaz.</div>
        <div class="prp-toolbar-actions">
            <button type="button" class="pd-btn pd-btn-primary" onclick="window.print()">Yazdır</button>
            <a href="{{ route('admin.procurements.supplier-requests.edit', $requestRecord) }}" class="pd-btn pd-btn-light">Talebi Düzenle</a>
            <a href="{{ route('admin.procurements.index', ['supplier_id' => $requestRecord->supplier_id]) }}" class="pd-btn pd-btn-light">Listeye Dön</a>
        </div>
    </div>

    <section class="prp-sheet">
        <div class="prp-headline">TEDARİKÇİ TALEP FORMU</div>
        <div class="prp-subline">Bu form yalnız tedarik talebi içindir. Fiyat bilgileri içermez.</div>

        <div class="prp-grid">
            <div class="prp-box">
                <div class="prp-box-title">Tedarikçi Bilgileri</div>
                <div class="prp-row"><div class="prp-row-label">Tedarikçi:</div><div>{{ $printData['supplier_name'] ?: '-' }}</div></div>
                <div class="prp-row"><div class="prp-row-label">Tarih:</div><div>{{ $requestDate }}</div></div>
                <div class="prp-row"><div class="prp-row-label">Telefon:</div><div>{{ $printData['supplier_phone'] ?: '-' }}</div></div>
                <div class="prp-row"><div class="prp-row-label">E-posta:</div><div>{{ $printData['supplier_email'] ?: '-' }}</div></div>
                <div class="prp-row"><div class="prp-row-label">Yetkili:</div><div>{{ $printData['supplier_contact_name'] ?: '-' }}</div></div>
            </div>
            <div class="prp-box">
                <div class="prp-box-title">Talep Bilgileri</div>
                <div class="prp-row"><div class="prp-row-label">Talep No:</div><div>{{ $printData['request_number'] ?: '-' }}</div></div>
                <div class="prp-row"><div class="prp-row-label">Durum:</div><div>{{ $printData['status_label'] ?: '-' }}</div></div>
                <div class="prp-row"><div class="prp-row-label">Hazırlayan:</div><div>{{ $printData['prepared_by'] ?: '-' }}</div></div>
                <div class="prp-row"><div class="prp-row-label">İç Not:</div><div>{{ $requestRecord->note ?: '-' }}</div></div>
            </div>
        </div>

        <div class="prp-table-wrap">
            <table class="prp-table">
                <thead>
                    <tr>
                        <th style="width:16%;">Sipariş No</th>
                        <th style="width:18%;">Ürün Kodu</th>
                        <th style="width:32%;">Ürün Adı</th>
                        <th style="width:18%;">Not</th>
                        <th style="width:9%;">İstenen</th>
                        <th style="width:7%;">Birim</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($printData['items'] as $item)
                        <tr>
                            <td>{{ $item['order_number'] ?: '-' }}</td>
                            <td>{{ $item['product_code'] ?: '-' }}</td>
                            <td>{{ $item['product_name'] ?: '-' }}</td>
                            <td>{{ $item['note'] ?: '-' }}</td>
                            <td class="prp-qty">{{ number_format((float) $item['requested_quantity'], 2, ',', '.') }}</td>
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

        <div class="prp-summary">
            <div class="prp-summary-note">
                Kalem sayısı: {{ number_format((float) ($printData['item_count'] ?? 0), 0, ',', '.') }}<br>
                @if($printData['has_multiple_units'] ?? false)
                    Toplamlar birim bazında değerlendirilmelidir.
                @else
                    Toplam miktar tek birim üzerinden özetlenmiştir.
                @endif
            </div>
            <div class="prp-summary-total">
                @if(($printData['has_multiple_units'] ?? false) && !empty($printData['unit_totals']))
                    @foreach($printData['unit_totals'] as $unitTotal)
                        <div>Toplam: {{ number_format((float) $unitTotal['quantity'], 2, ',', '.') }} {{ $unitTotal['unit'] }}</div>
                    @endforeach
                @else
                    <div>Toplam: {{ number_format((float) ($printData['total_quantity'] ?? 0), 2, ',', '.') }} {{ $printData['unit_totals'][0]['unit'] ?? 'Adet' }}</div>
                @endif
            </div>
        </div>

        <div class="prp-signatures">
            <div class="prp-signature">
                <strong>Tedarikçi Yetkilisi</strong>
                <div>İmza / Tarih</div>
            </div>
            <div class="prp-signature">
                <strong>Firma Yetkilisi</strong>
                <div>İmza / Tarih</div>
            </div>
        </div>

        <div class="prp-footnote">Bu belge yalnız talep formudur. Alış fiyatı, iskonto, KDV, genel toplam, supplier cost, cari bakiye, token veya ham kaynak alanları formda gösterilmez.</div>
    </section>
</div>
@endsection
