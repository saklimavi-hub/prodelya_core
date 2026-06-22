@extends('layouts.prodelya-admin')

@section('title', 'Tedarik Detayı')
@section('page_title', 'Tedarik Detayı')
@section('page_subtitle', 'Tedarik kaydını hızlıca kontrol edin, status aksiyonlarını yönetin ve ilgili iş formuna geçin.')

@section('content')
@php
    $snapshot = $procurement->snapshot ?? [];
    $workFormSnapshot = $procurement->procurement_snapshot ?? [];
    $statusLabels = \App\Models\OrderItemProcurement::statusLabels();
    $statusTones = [
        'tedarik_bekliyor' => 'amber',
        'tedarik_talebi_acildi' => 'blue',
        'siparis_verildi' => 'blue',
        'kismi_geldi' => 'amber',
        'tamami_geldi' => 'green',
        'iptal' => 'red',
        'tedarik_gerekmiyor' => 'gray',
        'musteri_urunu_bekleniyor' => 'purple',
        'musteri_urunu_geldi' => 'green',
    ];
    $linkedRequest = $procurement->supplierRequestItems->first()?->request;
    $unitLabel = $snapshot['unit'] ?? ($procurement->orderItem?->unit ?: 'Adet');
    $supplierDisplay = $snapshot['supplier_name'] ?? ($procurement->supplier?->name ?: $procurement->safeFulfillmentSourceLabel());
    $historyItems = $history->take(5);
@endphp

<style>
    .prd-page { display: grid; gap: 14px; }
    .prd-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05); overflow: hidden; }
    .prd-body { padding: 16px; }
    .prd-summary-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
    .prd-box { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 12px; }
    .prd-label { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 4px; }
    .prd-value { color: #111827; font-size: 13px; font-weight: 600; line-height: 1.45; word-break: break-word; }
    .prd-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 14px; align-items: start; }
    .prd-stack { display: grid; gap: 14px; }
    .prd-section-title { margin: 0 0 10px; font-size: 16px; font-weight: 700; color: #111827; }
    .prd-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .prd-form-grid { display: grid; grid-template-columns: minmax(0, 1fr) 170px auto; gap: 10px; align-items: end; }
    .prd-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 12px; font-weight: 600; }
    .prd-note { border: 1px solid #dbeafe; border-radius: 6px; background: #f8fbff; padding: 10px 12px; color: #475569; font-size: 12px; line-height: 1.45; }
    .prd-history { display: grid; gap: 8px; }
    .prd-history-row { display: grid; grid-template-columns: 120px 1fr; gap: 10px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 5px; background: #fff; }
    .prd-side { position: sticky; top: 16px; display: grid; gap: 14px; }
    @media (max-width: 1180px) {
        .prd-grid { grid-template-columns: 1fr; }
        .prd-side { position: static; }
        .prd-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 760px) {
        .prd-summary-grid, .prd-form-grid { grid-template-columns: 1fr; }
        .prd-history-row { grid-template-columns: 1fr; }
    }
</style>

@if(session('success'))
    <div class="pd-alert">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="pd-alert-warning">{{ $errors->first() }}</div>
@endif

<div class="prd-page">
    <section class="prd-card">
        <div class="prd-body">
            <h3 class="prd-section-title">Tedarik Özeti</h3>
            <div class="prd-summary-grid">
                <div class="prd-box">
                    <div class="prd-label">Sipariş No</div>
                    <div class="prd-value">{{ $snapshot['order_number'] ?? ($procurement->order?->document_number ?: '-') }}</div>
                </div>
                <div class="prd-box">
                    <div class="prd-label">İş Formu No</div>
                    <div class="prd-value">{{ $snapshot['work_form_number'] ?? ($procurement->workForm?->work_form_number ?: '-') }}</div>
                </div>
                <div class="prd-box">
                    <div class="prd-label">Müşteri</div>
                    <div class="prd-value">{{ $procurement->order?->customer?->legal_name ?: '-' }}</div>
                </div>
                <div class="prd-box">
                    <div class="prd-label">Tedarikçi</div>
                    <div class="prd-value">{{ $supplierDisplay }}</div>
                </div>
                <div class="prd-box">
                    <div class="prd-label">Eşleşen Cari</div>
                    <div class="prd-value">
                        @if($supplierCompanyMatch)
                            {{ $supplierCompanyMatch['company_name'] }}
                        @elseif($procurement->supplier_id)
                            <span style="color:#b45309;">Yok</span>
                        @else
                            -
                        @endif
                    </div>
                </div>
                <div class="prd-box">
                    <div class="prd-label">Durum</div>
                    <div class="prd-value"><span class="pd-badge pd-badge-{{ $statusTones[$procurement->procurement_status] ?? 'gray' }}">{{ $statusLabels[$procurement->procurement_status] ?? $procurement->procurement_status }}</span></div>
                </div>
                <div class="prd-box" style="grid-column: span 2;">
                    <div class="prd-label">Ürün</div>
                    <div class="prd-value">{{ $snapshot['product_name'] ?? ($procurement->orderItem?->product_name ?: '-') }}</div>
                </div>
                <div class="prd-box">
                    <div class="prd-label">Ürün Kodu</div>
                    <div class="prd-value">{{ $snapshot['product_code'] ?? ($procurement->orderItem?->product_code ?: '-') }}</div>
                </div>
                <div class="prd-box">
                    <div class="prd-label">Talep No</div>
                    <div class="prd-value">{{ $linkedRequest?->request_number ?: '-' }}</div>
                </div>
                <div class="prd-box">
                    <div class="prd-label">Kaynak Tipi</div>
                    <div class="prd-value">{{ $procurement->safeFulfillmentSourceLabel() }}</div>
                </div>
                <div class="prd-box">
                    <div class="prd-label">Müşteriye Açık Durum</div>
                    <div class="prd-value">{{ $workFormSnapshot['public_status_label'] ?? '-' }}</div>
                </div>
            </div>
        </div>
    </section>

    <div class="prd-grid">
        <div class="prd-stack">
            <section class="prd-card">
                <div class="prd-body">
                    <h3 class="prd-section-title">Adet Takibi</h3>
                    <div class="prd-summary-grid" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
                        <div class="prd-box">
                            <div class="prd-label">İstenen</div>
                            <div class="prd-value">{{ rtrim(rtrim(number_format((float) $procurement->requested_quantity, 4, ',', '.'), '0'), ',') }} {{ $unitLabel }}</div>
                        </div>
                        <div class="prd-box">
                            <div class="prd-label">Gelen</div>
                            <div class="prd-value">{{ rtrim(rtrim(number_format((float) $procurement->received_quantity, 4, ',', '.'), '0'), ',') }} {{ $unitLabel }}</div>
                        </div>
                        <div class="prd-box">
                            <div class="prd-label">Kalan</div>
                            <div class="prd-value">{{ rtrim(rtrim(number_format((float) $procurement->remaining_quantity, 4, ',', '.'), '0'), ',') }} {{ $unitLabel }}</div>
                        </div>
                    </div>
                    <div class="prd-note" style="margin-top: 12px;">
                        Tedarikçi stok bilgisi sipariş anı referansıdır. Bu ekran yalnız tedarik operasyonunu yönetir; stok ve genel sistem hareketleri burada tutulmaz.
                    </div>
                    @if($procurement->supplier_id && !$supplierCompanyMatch)
                        <div class="prd-note" style="margin-top: 12px; border-color:#fde68a; background:#fffbeb; color:#92400e;">
                            Bu ürün kaynağı için cari kart eşleştirilmemiş. Tedarik akışı devam eder; isterseniz ilgili tedarikçi cari kartında hazır ürün kaynağı eşleştirmesini tamamlayın.
                        </div>
                    @endif
                </div>
            </section>

            <section class="prd-card">
                <div class="prd-body">
                    <h3 class="prd-section-title">Hızlı Aksiyonlar</h3>
                    <div class="prd-stack">
                        @if($linkedRequest)
                            <div class="prd-note">
                                Açık talep: <strong>{{ $linkedRequest->request_number }}</strong>
                            </div>
                        @endif

                        @if($linkedRequest)
                            <div class="prd-actions">
                                <a href="{{ route('admin.procurements.supplier-requests.edit', $linkedRequest) }}" class="pd-btn pd-btn-primary">Talebi Aç</a>
                                <a href="{{ route('admin.procurements.supplier-requests.print', $linkedRequest) }}" class="pd-btn pd-btn-light" target="_blank" rel="noopener">Formu Aç / Yazdır</a>
                            </div>
                        @elseif($procurement->supplier_id)
                            <a href="{{ route('admin.procurements.supplier-requests.create', ['supplier_id' => $procurement->supplier_id]) }}" class="pd-btn pd-btn-primary">Tedarik Talebi Aç</a>
                        @endif

                        @foreach($actionOptions as $action)
                            <form method="POST" action="{{ route('admin.procurements.update-status', $procurement) }}" class="prd-stack">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="action" value="{{ $action['action'] }}">

                                @if($action['action'] === 'partially_received')
                                    <div class="prd-form-grid">
                                        <div class="prd-field">
                                            <label>Gelen Miktar</label>
                                            <input type="number" step="0.0001" min="0.0001" name="received_quantity" placeholder="Örn: 25">
                                        </div>
                                        <div class="prd-field">
                                            <label>Kısa Not</label>
                                            <input type="text" name="note" placeholder="Opsiyonel not">
                                        </div>
                                        <button type="submit" class="pd-btn pd-btn-light">Kısmi Geldi</button>
                                    </div>
                                @else
                                    <div class="prd-form-grid">
                                        <div class="prd-field" style="grid-column: span 2;">
                                            <label>Kısa Not</label>
                                            <input type="text" name="note" placeholder="Opsiyonel not">
                                        </div>
                                        <button type="submit" class="pd-btn {{ in_array($action['action'], ['fully_received', 'customer_received'], true) ? 'pd-btn-success' : ($action['action'] === 'cancel' ? 'pd-btn-danger' : ($action['action'] === 'not_required' ? 'pd-btn-light' : 'pd-btn-primary')) }}">
                                            {{ $action['label'] }}
                                        </button>
                                    </div>
                                @endif
                            </form>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="prd-card">
                <div class="prd-body">
                    <h3 class="prd-section-title">Kısa Workflow Geçmişi</h3>
                    <div class="prd-history">
                        @forelse($historyItems as $log)
                            <div class="prd-history-row">
                                <div class="prd-value">{{ optional($log->created_at)->format('d.m.Y H:i') ?: '-' }}</div>
                                <div class="prd-value">{{ $log->note ?: ($log->action_type ?? '-') }}</div>
                            </div>
                        @empty
                            <div class="prd-note">Henüz tedarik workflow kaydı yok.</div>
                        @endforelse
                    </div>
                </div>
            </section>
        </div>

        <aside class="prd-side">
            <section class="prd-card">
                <div class="prd-body">
                    <h3 class="prd-section-title">Bağlantılar</h3>
                    <div class="prd-actions">
                        <a href="{{ route('admin.procurements.index') }}" class="pd-btn pd-btn-light">Tedarik Listesine Dön</a>
                        @if($procurement->workForm)
                            <a href="{{ route('admin.work-forms.show', $procurement->workForm) }}" class="pd-btn pd-btn-light">İş Formu Aç</a>
                        @endif
                        @if($procurement->order)
                            <a href="{{ route('admin.orders.show', $procurement->order) }}" class="pd-btn pd-btn-light">Sipariş Detayına Git</a>
                        @endif
                        @if($linkedRequest)
                            <a href="{{ route('admin.procurements.supplier-requests.edit', $linkedRequest) }}" class="pd-btn pd-btn-light">Talep No: {{ $linkedRequest->request_number }}</a>
                        @endif
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection
