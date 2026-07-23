@extends('layouts.prodelya-admin')

@section('title', 'Tedarik Talebi Hazırla')
@section('page_title', 'Tedarik Talebi Hazırla')
@section('page_subtitle', 'Yalnız gerçek açık ihtiyaçlardan aynı tedarikçiye ait talep taslağı oluşturun.')
@section('hide_side_summary', '1')

@section('content')
<style>
    .pd-page-stack,
    .pd-section-stack { display:grid; gap:14px; }
    .pd-card-stack { display:grid; gap:12px; }
    .pd-inline-stack { display:flex; gap:10px; flex-wrap:wrap; }
    .prc-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 12px 28px rgba(15,23,42,.05); }
    .prc-body { padding:14px; }
    .prc-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; }
    .prc-box { border:1px solid #e5e7eb; border-radius:8px; background:#fbfdff; padding:12px; }
    .prc-label { color:#6b7280; font-size:11px; font-weight:700; text-transform:uppercase; }
    .prc-value { margin-top:5px; color:#111827; font-size:18px; font-weight:700; }
    .prc-note { color:#475569; font-size:12px; line-height:1.55; }
    .prc-table-wrap { overflow:auto; }
    .prc-table { width:100%; border-collapse:collapse; }
    .prc-table th, .prc-table td { padding:11px 10px; border-bottom:1px solid #e5e7eb; text-align:left; vertical-align:top; }
    .prc-table th { background:#f8fafc; color:#6b7280; font-size:11px; font-weight:700; text-transform:uppercase; }
    @media (max-width: 1100px) { .prc-grid { grid-template-columns:1fr; } }
</style>

<div class="pd-page-stack" data-procurement-reference-family="request-create">
    <section class="prc-card">
        <div class="prc-body pd-card-stack">
            <div class="prc-note">Bu ekran bağımsız/orphan procurement oluşturmaz. Aşağıdaki satırlar aynı tedarikçiye ait gerçek açık ihtiyaçlardan gelir ve tek taslak talepte toplanır.</div>
            <div class="prc-grid">
                <div class="prc-box"><div class="prc-label">Tedarikçi</div><div class="prc-value">{{ $supplier->name }}</div></div>
                <div class="prc-box"><div class="prc-label">Açık Kalem</div><div class="prc-value">{{ $candidateCount }}</div></div>
                <div class="prc-box"><div class="prc-label">Eksik Toplam</div><div class="prc-value">{{ number_format($totalMissingQuantity, 2, ',', '.') }}</div></div>
                <div class="prc-box"><div class="prc-label">Akış</div><div class="prc-value">Talep Hazırlığı</div></div>
            </div>
        </div>
    </section>

    @if(!empty($catalogPrefill))
        <section class="prc-card">
            <div class="prc-body pd-card-stack">
                <div class="prc-note">Katalogdan başlatılan tedarik akışı. Sayfa açılışı yalnız prefill gösterir; stok veya cari kayıt oluşturmaz.</div>
                <div class="prc-grid">
                    <div class="prc-box"><div class="prc-label">Ürün / Varyant</div><div class="prc-value" style="font-size:16px;">{{ $catalogPrefill['selection_label'] }}</div></div>
                    <div class="prc-box"><div class="prc-label">Tedarikçi</div><div class="prc-value" style="font-size:16px;">{{ $catalogPrefill['supplier_name'] }}</div></div>
                    <div class="prc-box"><div class="prc-label">Önerilen Adet</div><div class="prc-value">{{ number_format((float) $catalogPrefill['requested_quantity'], 2, ',', '.') }}</div></div>
                    <div class="prc-box"><div class="prc-label">Kaynak</div><div class="prc-value">Katalog</div></div>
                </div>
            </div>
        </section>
    @endif

    <section class="prc-card">
        <div class="prc-body pd-card-stack">
            <div class="pd-inline-stack" style="justify-content:space-between; align-items:flex-start;">
                <div>
                    <h3 style="margin:0; font-size:15px; font-weight:700; color:#111827;">Aday Tedarik Kalemleri</h3>
                    <div class="prc-note">Seçilecek satırlar doğrudan mevcut tedarik ihtiyacına bağlı kalır.</div>
                </div>
                <a href="{{ route('admin.procurements.index', ['supplier_id' => $supplier->id]) }}" class="pd-btn pd-btn-light">Listeye Dön</a>
            </div>
            @if($candidates->isEmpty())
                <div class="prc-note">Bu tedarikçi için talebe alınabilecek açık procurement kalemi bulunamadı.</div>
            @else
                <form method="POST" action="{{ route('admin.procurements.supplier-requests.store') }}">
                    @csrf
                    <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
                    <div class="prc-table-wrap">
                        <table class="prc-table">
                            <thead>
                                <tr>
                                    <th>Dahil</th>
                                    <th>Sipariş No</th>
                                    <th>İş Formu No</th>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>İstenen</th>
                                    <th>Yerel Tahsis</th>
                                    <th>Tedarik Edilecek</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($candidates as $candidate)
                                    @php($snapshot = $candidate->snapshot ?? [])
                                    <tr>
                                        <td><input type="checkbox" name="procurement_ids[]" value="{{ $candidate->id }}" checked></td>
                                        <td>{{ $candidate->order?->document_number ?: '-' }}</td>
                                        <td>{{ $candidate->workForm?->work_form_number ?: '-' }}</td>
                                        <td>{{ data_get($snapshot, 'product_code', '-') }}</td>
                                        <td>{{ data_get($snapshot, 'product_name', '-') }}</td>
                                        <td>{{ number_format((float) $candidate->requested_quantity, 2, ',', '.') }}</td>
                                        <td>{{ number_format((float) $candidate->local_allocated_quantity, 2, ',', '.') }}</td>
                                        <td>{{ number_format((float) $candidate->remaining_quantity, 2, ',', '.') }}</td>
                                        <td>{{ $candidate->safeStatusLabel() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="pd-inline-stack" style="justify-content:flex-end;">
                        <button type="submit" class="pd-btn pd-btn-primary">Talebi Aç</button>
                    </div>
                </form>
            @endif
        </div>
    </section>
</div>
@endsection
