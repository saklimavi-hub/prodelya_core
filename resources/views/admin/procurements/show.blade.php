@extends('layouts.prodelya-admin')

@section('title', 'Tedarik Detayı')
@section('page_title', 'Tedarik Detayı')
@section('page_subtitle', 'Tedarik kaydını tek bakışta izleyin, sıradaki işlemi seçin ve ilgili talep akışına geçin.')

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
    $unitLabel = $snapshot['unit'] ?? ($procurement->orderItem?->unit ?: 'Adet');
    $supplierDisplay = $snapshot['supplier_name'] ?? ($procurement->supplier?->name ?: $procurement->safeFulfillmentSourceLabel());
    $showUrl = route('admin.procurements.show', $procurement);
    $tabUrl = fn (string $tab) => $showUrl . '?tab=' . $tab;
    $requestedFormatted = rtrim(rtrim(number_format((float) $procurement->requested_quantity, 4, ',', '.'), '0'), ',');
    $receivedFormatted = rtrim(rtrim(number_format((float) $procurement->received_quantity, 4, ',', '.'), '0'), ',');
    $remainingFormatted = rtrim(rtrim(number_format((float) $procurement->remaining_quantity, 4, ',', '.'), '0'), ',');
    $receiptLabel = (float) $procurement->received_quantity <= 0.0001
        ? 'Henüz gelmedi'
        : ((float) $procurement->remaining_quantity <= 0.0001 ? 'Tamamı geldi' : 'Kısmi geldi');
    $requestStatusLabel = $linkedRequest?->safeStatusLabel() ?: 'Henüz talep açılmadı';
    $currentStatusLabel = $statusLabels[$procurement->procurement_status] ?? $procurement->procurement_status;
@endphp

<style>
    .prv-page { display: grid; grid-template-columns: minmax(0, 1fr) 296px; gap: 16px; align-items: start; }
    .prv-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
    .prv-card + .prv-card { margin-top: 14px; }
    .prv-body { padding: 14px; }
    .prv-title { margin: 0; font-size: 15px; font-weight: 700; color: #111827; }
    .prv-note { margin-top: 4px; color: #6b7280; font-size: 12px; line-height: 1.45; }
    .prv-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 12px; }
    .prv-tabs, .prv-steps, .prv-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .prv-tab, .prv-step { display: inline-flex; align-items: center; padding: 8px 11px; border-radius: 999px; border: 1px solid #dbe3ef; background: #f8fafc; color: #334155; text-decoration: none; font-size: 12px; font-weight: 700; }
    .prv-tab.is-active, .prv-step.is-active { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
    .prv-summary-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
    .prv-box { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 11px 12px; }
    .prv-label { color: #6b7280; font-size: 11px; font-weight: 700; margin-bottom: 4px; text-transform: uppercase; }
    .prv-value { color: #111827; font-size: 13px; font-weight: 700; line-height: 1.45; word-break: break-word; }
    .prv-subvalue { margin-top: 3px; color: #6b7280; font-size: 11px; }
    .prv-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .prv-list { display: grid; gap: 8px; }
    .prv-list-row { display: grid; grid-template-columns: 140px 1fr; gap: 12px; padding: 10px 0; border-bottom: 1px solid #e5e7eb; }
    .prv-list-row:last-child { border-bottom: 0; }
    .prv-stage-panel { border: 1px solid #e5e7eb; border-radius: 8px; padding: 13px; background: #fff; }
    .prv-stage-panel.is-request { background: #eefbf5; border-color: #cfeedd; }
    .prv-stage-panel.is-ordered { background: #f0f7ff; border-color: #d7e8fb; }
    .prv-stage-panel.is-partial { background: #fff8eb; border-color: #f4dfb7; }
    .prv-stage-panel.is-completed { background: #effaf1; border-color: #d4f0db; }
    .prv-stage-panel.is-closed { background: #f8fafc; border-color: #e5e7eb; }
    .prv-form-grid { display: grid; grid-template-columns: minmax(0, 1fr) 170px auto; gap: 10px; align-items: end; }
    .prv-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 12px; font-weight: 600; }
    .prv-empty, .prv-band { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 11px 12px; color: #475569; font-size: 12px; line-height: 1.45; }
    .prv-band.warn { border-color: #fde68a; background: #fffdf5; color: #92400e; }
    .prv-history { display: grid; gap: 8px; }
    .prv-history-row { display: grid; grid-template-columns: 130px 1fr; gap: 10px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; background: #fff; }
    .prv-side { position: sticky; top: 16px; display: grid; gap: 14px; }
    .prv-side-grid { display: grid; gap: 8px; }
    .prv-side-row { display: flex; justify-content: space-between; gap: 12px; color: #475569; font-size: 12px; }
    .prv-side-row strong { color: #111827; }
    @media (max-width: 1180px) {
        .prv-page, .prv-summary-grid { grid-template-columns: 1fr; }
        .prv-side { position: static; }
    }
    @media (max-width: 760px) {
        .prv-grid-2, .prv-form-grid, .prv-list-row, .prv-history-row { grid-template-columns: 1fr; }
        .prv-header { flex-direction: column; }
    }
</style>

@if(session('success'))
    <div class="pd-alert">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="pd-alert-warning">{{ $errors->first() }}</div>
@endif

<div class="prv-card" data-testid="procurement-show-tabs-card">
    <div class="prv-body">
        <div class="prv-header">
            <div>
                <h3 class="prv-title">Tedarik Sekmeleri</h3>
                <div class="prv-note">Uzun akış yerine ilgili konuya odaklanın ve yalnız o bölümün bilgisini görün.</div>
            </div>
            <span class="pd-badge pd-badge-{{ $statusTones[$procurement->procurement_status] ?? 'gray' }}">{{ $currentStatusLabel }}</span>
        </div>
        <div class="prv-tabs">
            @foreach($tabItems as $tab)
                <a href="{{ $tabUrl($tab['key']) }}" class="prv-tab {{ $tab['is_active'] ? 'is-active' : '' }}">{{ $tab['label'] }}</a>
            @endforeach
        </div>
    </div>
</div>

<div class="prv-page">
    <div>
        @if($activeTab === 'genel')
            <div class="prv-card">
                <div class="prv-body">
                    <div class="prv-header">
                        <div>
                            <h3 class="prv-title">Genel Özet</h3>
                            <div class="prv-note">Bu kayıt hangi aşamada, ne kadar ürün eksik ve sıradaki işlem ne, hızlıca görün.</div>
                        </div>
                        <span class="pd-badge pd-badge-{{ $statusTones[$procurement->procurement_status] ?? 'gray' }}">{{ $nextActionLabel }}</span>
                    </div>
                    <div class="prv-summary-grid">
                        <div class="prv-box"><div class="prv-label">Sipariş No</div><div class="prv-value">{{ $snapshot['order_number'] ?? ($procurement->order?->document_number ?: '-') }}</div></div>
                        <div class="prv-box"><div class="prv-label">İş Formu No</div><div class="prv-value">{{ $snapshot['work_form_number'] ?? ($procurement->workForm?->work_form_number ?: '-') }}</div></div>
                        <div class="prv-box"><div class="prv-label">Müşteri</div><div class="prv-value">{{ $procurement->order?->customer?->legal_name ?: '-' }}</div></div>
                        <div class="prv-box"><div class="prv-label">Ürün</div><div class="prv-value">{{ $snapshot['product_name'] ?? ($procurement->orderItem?->product_name ?: '-') }}</div></div>
                        <div class="prv-box"><div class="prv-label">Ürün Kodu</div><div class="prv-value">{{ $snapshot['product_code'] ?? ($procurement->orderItem?->product_code ?: '-') }}</div></div>
                        <div class="prv-box"><div class="prv-label">İstenen</div><div class="prv-value">{{ $requestedFormatted }} {{ $unitLabel }}</div></div>
                        <div class="prv-box"><div class="prv-label">Alınan</div><div class="prv-value">{{ $receivedFormatted }} {{ $unitLabel }}</div></div>
                        <div class="prv-box"><div class="prv-label">Eksik</div><div class="prv-value">{{ $remainingFormatted }} {{ $unitLabel }}</div></div>
                        <div class="prv-box"><div class="prv-label">Gelen Durumu</div><div class="prv-value">{{ $receiptLabel }}</div></div>
                        <div class="prv-box"><div class="prv-label">Tedarikçi</div><div class="prv-value">{{ $supplierDisplay }}</div><div class="prv-subvalue">Eşleşen Cari: {{ $supplierCompanyMatch['company_name'] ?? 'Yok' }}</div></div>
                    </div>
                </div>
            </div>
        @elseif($activeTab === 'urun')
            <div class="prv-card">
                <div class="prv-body">
                    <h3 class="prv-title">Ürün ve Sipariş</h3>
                    <div class="prv-note">Sipariş ve ürün referansını, miktar takibini ve iç notları tek bölümde görün.</div>
                    <div class="prv-list" style="margin-top:12px;">
                        <div class="prv-list-row"><div class="prv-label">Ürün Adı</div><div class="prv-value">{{ $snapshot['product_name'] ?? ($procurement->orderItem?->product_name ?: '-') }}</div></div>
                        <div class="prv-list-row"><div class="prv-label">Ürün Kodu</div><div class="prv-value">{{ $snapshot['product_code'] ?? ($procurement->orderItem?->product_code ?: '-') }}</div></div>
                        <div class="prv-list-row"><div class="prv-label">Sipariş No</div><div class="prv-value">{{ $snapshot['order_number'] ?? ($procurement->order?->document_number ?: '-') }}</div></div>
                        <div class="prv-list-row"><div class="prv-label">İş Formu No</div><div class="prv-value">{{ $snapshot['work_form_number'] ?? ($procurement->workForm?->work_form_number ?: '-') }}</div></div>
                        <div class="prv-list-row"><div class="prv-label">Sipariş Müşterisi</div><div class="prv-value">{{ $procurement->order?->customer?->legal_name ?: '-' }}</div></div>
                        <div class="prv-list-row"><div class="prv-label">İstenen / Alınan / Eksik</div><div class="prv-value">{{ $requestedFormatted }} / {{ $receivedFormatted }} / {{ $remainingFormatted }} {{ $unitLabel }}</div></div>
                        <div class="prv-list-row"><div class="prv-label">Notlar</div><div class="prv-value">{{ $procurement->orderItem?->note ?: 'Henüz not yok' }}</div></div>
                    </div>
                </div>
            </div>
        @elseif($activeTab === 'tedarikci')
            <div class="prv-card">
                <div class="prv-body">
                    <h3 class="prv-title">Tedarikçi ve Cari</h3>
                    <div class="prv-note">Eşleşen cari kaydı, eşleşme güveni ve tedarik akışında kullanılabilirlik bilgisi burada görünür.</div>
                    <div class="prv-grid-2" style="margin-top:12px;">
                        <div class="prv-box"><div class="prv-label">Tedarikçi</div><div class="prv-value">{{ $supplierDisplay }}</div></div>
                        <div class="prv-box"><div class="prv-label">Eşleşen Cari</div><div class="prv-value">{{ $supplierCompanyMatch['company_name'] ?? 'Yok' }}</div></div>
                        <div class="prv-box"><div class="prv-label">Eşleşme Durumu</div><div class="prv-value">{{ $supplierCompanyMatch ? 'Güvenli eşleşme var' : 'Henüz eşleşme yok' }}</div></div>
                        <div class="prv-box"><div class="prv-label">Kullanılabilirlik</div><div class="prv-value">{{ $supplierCompanyMatch ? 'Tedarik işlemlerinde kullanılabilir' : 'Eşleşme tamamlanınca kullanılabilir' }}</div></div>
                    </div>
                    @if($supplierCompanyMatch)
                        <div class="prv-actions" style="margin-top:12px;">
                            <a href="{{ route('admin.companies.show', $supplierCompanyMatch['company_id']) }}" class="pd-btn pd-btn-light">Cari Kartı Aç</a>
                        </div>
                    @else
                        <div class="prv-band warn" style="margin-top:12px;">Bu tedarikçi için eşleşen cari bulunamadı. Tedarik akışı devam eder, ancak cari eşleşmesi tamamlanırsa takip kolaylaşır.</div>
                    @endif
                </div>
            </div>
        @elseif($activeTab === 'talep')
            <div class="prv-card">
                <div class="prv-body">
                    <h3 class="prv-title">Talep / Form</h3>
                    <div class="prv-note">Talep açıldı mı, fiyatsız form hazır mı ve ilgili kalemler neler, buradan yönetin.</div>
                    <div class="prv-grid-2" style="margin-top:12px;">
                        <div class="prv-box"><div class="prv-label">Talep Durumu</div><div class="prv-value">{{ $requestStatusLabel }}</div></div>
                        <div class="prv-box"><div class="prv-label">Talep No</div><div class="prv-value">{{ $linkedRequest?->request_number ?: '-' }}</div></div>
                    </div>
                    <div class="prv-actions" style="margin-top:12px;">
                        @if($linkedRequest)
                            <a href="{{ route('admin.procurements.supplier-requests.edit', $linkedRequest) }}" class="pd-btn pd-btn-primary">Talebi Düzenle</a>
                            <a href="{{ route('admin.procurements.supplier-requests.print', $linkedRequest) }}" target="_blank" rel="noopener" class="pd-btn pd-btn-light">Fiyatsız Talep Formunu Aç</a>
                        @elseif($procurement->supplier_id)
                            <a href="{{ route('admin.procurements.supplier-requests.create', ['supplier_id' => $procurement->supplier_id]) }}" class="pd-btn pd-btn-primary">Talep Aç</a>
                        @endif
                    </div>
                    <div class="prv-band" style="margin-top:12px;">Bu bölümde fiyat, maliyet ve toplam tutar gösterilmez. Talep formu fiyatsız akışla korunur.</div>
                </div>
            </div>
        @elseif($activeTab === 'islemler')
            <div class="prv-card">
                <div class="prv-body">
                    <div class="prv-header">
                        <div>
                            <h3 class="prv-title">İşlemler</h3>
                            <div class="prv-note">Tüm butonlar aynı anda açılmaz. Yalnız mevcut duruma uygun sonraki aksiyon öne çıkar.</div>
                        </div>
                        <span class="pd-badge pd-badge-gray">{{ $nextActionLabel }}</span>
                    </div>
                    <div class="prv-steps" data-testid="procurement-action-steps">
                        @foreach($actionStages as $stage)
                            <span class="prv-step {{ $stage['is_active'] ? 'is-active' : '' }}">{{ $stage['label'] }}</span>
                        @endforeach
                    </div>
                    <div class="prv-stage-panel is-{{ $actionStage }}" style="margin-top:12px;" data-testid="procurement-action-panel">
                        <div class="prv-title">{{ $nextActionLabel }}</div>
                        <div class="prv-note">
                            @if($actionStage === 'request')
                                Talep açılmadıysa ilk işlem bu kaydı talep akışına sokmaktır.
                            @elseif($actionStage === 'ordered')
                                Talep açıldıktan sonra siparişin verildiği işaretlenir.
                            @elseif($actionStage === 'partial')
                                Gelen miktar varsa kısmi, tamamı geldiyse tam giriş işlenir.
                            @elseif($actionStage === 'completed')
                                Tedarik tamamlandı. Bu kayıt için yeni işlem gerekmiyor.
                            @else
                                Bu kayıt kapalı veya geri dönüşlü durumdadır.
                            @endif
                        </div>
                        <div class="prv-actions" style="margin-top:12px;">
                            @foreach($actionOptions as $action)
                                @if($action['action'] === 'partially_received')
                                    <form method="POST" action="{{ route('admin.procurements.update-status', $procurement) }}" class="prv-form-grid">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="action" value="partially_received">
                                        <div class="prv-field">
                                            <label>Gelen Miktar</label>
                                            <input type="number" step="0.0001" min="0.0001" max="{{ number_format((float) $procurement->remaining_quantity, 4, '.', '') }}" name="received_quantity" placeholder="Örn: 25">
                                        </div>
                                        <div class="prv-field">
                                            <label>Kısa Not</label>
                                            <input type="text" name="note" placeholder="Opsiyonel not">
                                        </div>
                                        <button type="submit" class="pd-btn pd-btn-light">{{ $action['label'] }}</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.procurements.update-status', $procurement) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="action" value="{{ $action['action'] }}">
                                        <input type="hidden" name="note" value="">
                                        <button type="submit" class="pd-btn {{ $action['tone'] === 'success' ? 'pd-btn-success' : ($action['tone'] === 'primary' ? 'pd-btn-primary' : 'pd-btn-light') }}">{{ $action['label'] }}</button>
                                    </form>
                                @endif
                            @endforeach
                            <a href="{{ route('admin.orders.show', $procurement->order) }}" class="pd-btn pd-btn-light">Siparişe Git</a>
                            <a href="{{ route('admin.procurements.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                        </div>
                    </div>
                </div>
            </div>
        @elseif($activeTab === 'gelen')
            <div class="prv-card">
                <div class="prv-body">
                    <h3 class="prv-title">Gelen / Miktar</h3>
                    <div class="prv-note">Miktar takibini ve kısmi geliş bilgisini bu alanda izleyin.</div>
                    <div class="prv-summary-grid" style="margin-top:12px; grid-template-columns: repeat(3, minmax(0, 1fr));">
                        <div class="prv-box"><div class="prv-label">İstenen</div><div class="prv-value">{{ $requestedFormatted }} {{ $unitLabel }}</div></div>
                        <div class="prv-box"><div class="prv-label">Alınan</div><div class="prv-value">{{ $receivedFormatted }} {{ $unitLabel }}</div></div>
                        <div class="prv-box"><div class="prv-label">Eksik</div><div class="prv-value">{{ $remainingFormatted }} {{ $unitLabel }}</div></div>
                    </div>
                    <div class="prv-band" style="margin-top:12px;">Gelen durumu: {{ $receiptLabel }}. Kısmi giriş ve tamamı geldi işlemleri yalnız ilgili durumda açılır.</div>
                </div>
            </div>
        @elseif($activeTab === 'gecmis')
            <div class="prv-card">
                <div class="prv-body">
                    <h3 class="prv-title">Geçmiş</h3>
                    <div class="prv-note">Talep, sipariş ve gelen akışında oluşan kullanıcıya açık geçmiş kayıtları.</div>
                    <div class="prv-history" style="margin-top:12px;">
                        @forelse($history as $log)
                            <div class="prv-history-row">
                                <div class="prv-value">{{ optional($log->created_at)->format('d.m.Y H:i') ?: '-' }}</div>
                                <div>
                                    <div class="prv-value">{{ $log->note ?: 'İşlem kaydı' }}</div>
                                    <div class="prv-subvalue">{{ $log->visibility === 'customer_visible' ? 'Müşteriye açık kayıt' : 'İç kayıt' }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="prv-empty">Henüz geçmiş kaydı yok.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </div>

    <aside class="prv-side">
        <div class="prv-card">
            <div class="prv-body">
                <h3 class="prv-title">Kısa Özet</h3>
                <div class="prv-side-grid" style="margin-top:12px;">
                    <div class="prv-side-row"><span>Sipariş</span><strong>{{ $snapshot['order_number'] ?? ($procurement->order?->document_number ?: '-') }}</strong></div>
                    <div class="prv-side-row"><span>İş Formu</span><strong>{{ $snapshot['work_form_number'] ?? ($procurement->workForm?->work_form_number ?: '-') }}</strong></div>
                    <div class="prv-side-row"><span>Ürün</span><strong>{{ $snapshot['product_code'] ?? '-' }}</strong></div>
                    <div class="prv-side-row"><span>Tedarikçi</span><strong>{{ $supplierDisplay }}</strong></div>
                    <div class="prv-side-row"><span>Eşleşen Cari</span><strong>{{ $supplierCompanyMatch['company_name'] ?? 'Yok' }}</strong></div>
                    <div class="prv-side-row"><span>Durum</span><strong>{{ $currentStatusLabel }}</strong></div>
                    <div class="prv-side-row"><span>Sıradaki İş</span><strong>{{ $nextActionLabel }}</strong></div>
                </div>
                <div class="prv-actions" style="margin-top:12px;">
                    <a href="{{ route('admin.procurements.index') }}" class="pd-btn pd-btn-light">Tedarik Listesine Dön</a>
                    <a href="{{ route('admin.orders.show', $procurement->order) }}" class="pd-btn pd-btn-light">Siparişe Git</a>
                    @if($procurement->workForm)
                        <a href="{{ route('admin.work-forms.show', $procurement->workForm) }}" class="pd-btn pd-btn-light">İş Formu Aç</a>
                    @endif
                </div>
            </div>
        </div>
    </aside>
</div>
@endsection
