@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Talebi Hazırla')
@section('page_title', 'Tedarikçi Talebi Hazırla')
@section('page_subtitle', 'Seçili tedarikçi için açık procurement kalemlerini tek draft talepte toplayın.')

@section('content')
<style>
    .spr-create { display: grid; gap: 14px; }
    .spr-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05); overflow: hidden; }
    .spr-body { padding: 16px; }
    .spr-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    .spr-metric { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 12px; }
    .spr-label { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .spr-value { margin-top: 6px; color: #111827; font-size: 20px; font-weight: 700; }
    .spr-table-wrap { overflow-x: auto; }
    .spr-table { width: 100%; border-collapse: collapse; }
    .spr-table th, .spr-table td { padding: 11px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
    .spr-table th { background: #fbfcfe; color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .spr-note { border: 1px solid #dbeafe; border-radius: 6px; background: #f8fbff; padding: 10px 12px; color: #475569; font-size: 12px; }
    .spr-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    @media (max-width: 900px) { .spr-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 640px) { .spr-grid { grid-template-columns: 1fr; } }
</style>

<div class="spr-create">
    <section class="spr-card">
        <div class="spr-body">
            <div class="spr-note">
                Bu ekran yalnız seçili tenant'ın açık procurement kayıtlarından aynı tedarikçiye ait aday kalemleri gösterir. Siparişi olmayan veya açık tedarik ihtiyacı bulunmayan tedarikçiler burada görünmez.
            </div>
        </div>
    </section>

    <section class="spr-grid">
        <div class="spr-metric">
            <div class="spr-label">Tedarikçi</div>
            <div class="spr-value">{{ $supplier->name }}</div>
        </div>
        <div class="spr-metric">
            <div class="spr-label">Açık Kalem</div>
            <div class="spr-value">{{ $candidateCount }}</div>
        </div>
        <div class="spr-metric">
            <div class="spr-label">Eksik Toplam</div>
            <div class="spr-value">{{ number_format($totalMissingQuantity, 2, ',', '.') }}</div>
        </div>
        <div class="spr-metric">
            <div class="spr-label">Akış</div>
            <div class="spr-value" style="font-size:16px;">Taslak Talep</div>
        </div>
    </section>

    <section class="spr-card">
        <div class="spr-body">
            <div class="spr-actions" style="justify-content: space-between; margin-bottom: 12px;">
                <strong>Aday Tedarik Kalemleri</strong>
                <div class="spr-actions">
                    <a href="{{ route('admin.procurements.index', ['supplier_id' => $supplier->id]) }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                </div>
            </div>

            @if($candidates->isEmpty())
                <div class="spr-note">Bu tedarikçi için talebe alınabilecek açık procurement kalemi bulunamadı.</div>
            @else
                <form method="POST" action="{{ route('admin.procurements.supplier-requests.store') }}">
                    @csrf
                    <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">

                    <div class="spr-table-wrap">
                        <table class="spr-table">
                            <thead>
                                <tr>
                                    <th>Dahil</th>
                                    <th>Sipariş No</th>
                                    <th>İş Formu No</th>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>İstenen</th>
                                    <th>Alınan</th>
                                    <th>Eksik</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($candidates as $candidate)
                                    @php($snapshot = $candidate->snapshot ?? [])
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="procurement_ids[]" value="{{ $candidate->id }}" checked>
                                        </td>
                                        <td>{{ $candidate->order?->document_number ?: '-' }}</td>
                                        <td>{{ $candidate->workForm?->work_form_number ?: '-' }}</td>
                                        <td>{{ data_get($snapshot, 'product_code', '-') }}</td>
                                        <td>{{ data_get($snapshot, 'product_name', '-') }}</td>
                                        <td>{{ number_format((float) $candidate->requested_quantity, 2, ',', '.') }}</td>
                                        <td>{{ number_format((float) $candidate->received_quantity, 2, ',', '.') }}</td>
                                        <td>{{ number_format((float) $candidate->remaining_quantity, 2, ',', '.') }}</td>
                                        <td>{{ $candidate->safeStatusLabel() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="spr-actions" style="margin-top: 14px; justify-content: flex-end;">
                        <button type="submit" class="pd-btn pd-btn-primary">Toplu Talep Hazırla</button>
                    </div>
                </form>
            @endif
        </div>
    </section>
</div>
@endsection
