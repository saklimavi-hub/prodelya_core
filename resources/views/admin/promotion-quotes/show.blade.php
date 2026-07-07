@extends('layouts.prodelya-admin')

@section('title', $quote->document_number)
@section('hide_side_summary', '1')
@section('page_title', 'Promosyon Teklif Detayı')
@section('page_subtitle', 'Karar bandı, müşteri onayı ve ürün özeti')

@section('page_actions')
<div class="flex gap-2">
    <a href="{{ route('admin.promotion-quotes.index') }}" class="pd-btn pd-btn-light">Teklifleri Listele</a>
    @if($isConverted && $linkedOrder)
        <a href="{{ route('admin.orders.show', $linkedOrder) }}" class="pd-btn pd-btn-primary" data-testid="quote-open-order-button">Siparişi Aç</a>
    @endif
</div>
@endsection

@section('content')
@php
    use App\Models\Order;
    use Illuminate\Support\Str;

    $statusClass = match($quote->display_status_badge_class ?? 'badge-gray') {
        'badge-blue' => 'pd-badge-blue',
        'badge-green' => 'pd-badge-green',
        'badge-amber' => 'pd-badge-amber',
        'badge-red' => 'pd-badge-red',
        default => 'pd-badge-slate',
    };

    $approvalState = $quote->customer_approval_status ?: Order::CUSTOMER_APPROVAL_NOT_SENT;
    $approvalStateClass = match ($approvalState) {
        Order::CUSTOMER_APPROVAL_WAITING => 'pd-badge-blue',
        Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'pd-badge-amber',
        Order::CUSTOMER_APPROVAL_APPROVED => 'pd-badge-green',
        Order::CUSTOMER_APPROVAL_REJECTED => 'pd-badge-red',
        default => 'pd-badge-slate',
    };

    $approvalStateLabel = match ($approvalState) {
        Order::CUSTOMER_APPROVAL_WAITING => 'Onay Bekliyor',
        Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'Revize İstendi',
        Order::CUSTOMER_APPROVAL_APPROVED => 'Onaylandı',
        Order::CUSTOMER_APPROVAL_REJECTED => 'Reddedildi',
        default => 'Gönderilmedi',
    };

    $approvalCardStatusLabel = $latestApprovalRequest?->safeStatusLabel() ?? 'Gönderilmedi';
    $approvalCardStatusClass = match ($latestApprovalRequest?->status) {
        'waiting', 'viewed' => 'pd-badge-blue',
        'approved' => 'pd-badge-green',
        'revision_requested' => 'pd-badge-amber',
        'rejected' => 'pd-badge-red',
        'expired', 'cancelled' => 'pd-badge-slate',
        default => 'pd-badge-slate',
    };

    $latestCustomerNote = $latestApprovalRequest?->customer_note;
    $lastSentChannel = $latestApprovalRequest?->sendSnapshot?->safeSendLabel();
    $whatsappActionLabel = match (true) {
        ! $whatsappAvailable => 'WhatsApp kapalı',
        ! $approvalHelperUrl => 'Önce gönderim oluşturun',
        ! $recipientPhoneDisplay => 'Telefon yok',
        ! $whatsappReady => 'Geçerli WhatsApp cep telefonu yok',
        default => 'Hazır mesaj oluşturulabilir',
    };
    $pdfActionLabel = $quotePdfAvailable ? 'Hazır' : 'Ayrı fazda bağlanacak';
    $customerPrintVisibilityLabel = $quote->shouldShowPrintPriceDetailsToCustomer()
        ? 'Baskı detayları müşteriye görünür'
        : 'Baskı detayları müşteriye gizli';

    $decisionHeadline = match (true) {
        $isConverted => 'Bu teklif siparişe dönüştü.',
        $quote->customer_approval_status === Order::CUSTOMER_APPROVAL_APPROVED => 'Teklif onaylandı, siparişe çevrilebilir.',
        $quote->customer_approval_status === Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'Müşteri revize istedi.',
        $quote->customer_approval_status === Order::CUSTOMER_APPROVAL_REJECTED => 'Teklif reddedildi.',
        $latestApprovalRequest?->isViewed() => 'Müşteri teklifi görüntüledi.',
        $quote->last_sent_at => 'Bu teklif müşteri yanıtı bekliyor.',
        default => 'Bu teklif henüz müşteriye gönderilmedi.',
    };

    $decisionSupport = match (true) {
        $isConverted => 'İşlemler artık sipariş ve operasyon ekranlarından takip edilir.',
        $canConvert => 'Teklif onay akışını tamamladı. Siparişe çevirerek süreci başlatabilirsiniz.',
        $quote->customer_approval_status === Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'Teklifi güncelleyip tekrar göndermeniz bekleniyor.',
        $quote->customer_approval_status === Order::CUSTOMER_APPROVAL_REJECTED => 'Müşteriye yeni bir revizyon veya alternatif göndermeniz gerekebilir.',
        $latestApprovalRequest?->isViewed() => 'Müşteri yanıtı henüz gelmedi; gerekirse tekrar gönderim yapabilirsiniz.',
        $quote->last_sent_at => 'Gönderim durumu aşağıdaki müşteri onayı kartından izlenebilir.',
        default => 'Önce müşteriye gönderin veya gerekiyorsa iç onay verin.',
    };

    $convertSummary = match (true) {
        $isConverted => 'Sipariş süreci başladı.',
        $canConvert => 'Teklif onaylandı, siparişe çevrilebilir.',
        ! empty($convertIssues) => $convertIssues[0],
        default => 'Müşteri onayı bekleniyor.',
    };
@endphp

<style>
    .pqux-page{font-family:Arial,Helvetica,sans-serif;color:#172033}
    .pqux-flash{padding:12px 14px;border-radius:10px;border:1px solid;margin-bottom:14px;font-size:13px;line-height:1.5}
    .pqux-flash-success{background:#effaf3;border-color:#b8e3c5;color:#166534}
    .pqux-flash-error{background:#fff3f3;border-color:#f3c7c7;color:#991b1b}
    .pqux-page .pqux-band,.pqux-page .pqux-card,.pqux-page .pqux-summary-card{background:#fff;border:1px solid #e4e8ef;border-radius:12px;box-shadow:0 4px 14px rgba(15,23,42,.04)}
    .pqux-band{padding:18px 20px;margin-bottom:14px}
    .pqux-band-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
    .pqux-band-title{font-size:24px;line-height:1.1;font-weight:700;margin:0 0 6px}
    .pqux-band-subtitle{margin:0;color:#657184;font-size:12px;line-height:1.5;max-width:760px}
    .pqux-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
    .pqux-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;align-items:center}
    .pqux-actions .pd-btn{min-height:36px}
    .pqux-decision{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-top:14px;padding-top:14px;border-top:1px solid #edf1f6}
    .pqux-decision h2{margin:0 0 4px;font-size:17px;font-weight:700}
    .pqux-decision p{margin:0;color:#657184;font-size:12px;line-height:1.5}
    .pqux-top-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}
    .pqux-layout{display:grid;grid-template-columns:minmax(0,1.35fr) 360px;gap:14px;align-items:start}
    .pqux-stack{display:flex;flex-direction:column;gap:14px}
    .pqux-card{padding:16px}
    .pqux-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}
    .pqux-card-head h3{margin:0 0 4px;font-size:16px;font-weight:700}
    .pqux-card-head p{margin:0;color:#657184;font-size:12px;line-height:1.45}
    .pqux-summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .pqux-summary-card{padding:13px}
    .pqux-summary-label{font-size:11px;color:#657184;font-weight:700;margin-bottom:6px}
    .pqux-summary-value{font-size:16px;font-weight:700;line-height:1.35}
    .pqux-approval-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .pqux-info-box{padding:12px;border:1px solid #eef2f7;border-radius:10px;background:#f8fafc}
    .pqux-info-label{font-size:11px;font-weight:700;color:#64748b;margin-bottom:5px}
    .pqux-info-value{font-size:13px;font-weight:600;line-height:1.45;color:#172033}
    .pqux-info-value.muted{font-weight:500;color:#657184}
    .pqux-note{margin-top:12px;padding:12px;border-radius:10px;background:#f7faff;border:1px dashed #d7e4f6;color:#506175;font-size:12px;line-height:1.5}
    .pqux-cta-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
    .pqux-convert{padding:14px;border:1px solid #eef2f7;border-radius:10px;background:#fbfcfe}
    .pqux-convert strong{display:block;font-size:13px;font-weight:700;margin-bottom:4px}
    .pqux-convert span{display:block;color:#657184;font-size:12px;line-height:1.45}
    .pqux-action-list{display:grid;gap:10px}
    .pqux-action-box{padding:12px;border:1px solid #eef2f7;border-radius:10px;background:#fbfcfe}
    .pqux-action-box strong{display:block;font-size:13px;font-weight:700;color:#172033}
    .pqux-action-box span{display:block;margin-top:4px;color:#657184;font-size:12px;line-height:1.45}
    .pqux-action-group{padding:12px;border:1px solid #eef2f7;border-radius:10px;background:#fbfcfe}
    .pqux-action-group-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
    .pqux-action-group-title strong{font-size:13px;font-weight:700;color:#172033}
    .pqux-action-group-title span{color:#657184;font-size:11px;line-height:1.4}
    .pqux-action-buttons{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
    .pqux-action-buttons form{display:inline-flex}
    .pqux-item-list{display:flex;flex-direction:column;gap:12px}
    .pqux-item{border:1px solid #edf1f6;border-radius:12px;padding:14px;background:#fbfcfe}
    .pqux-item-top{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:start}
    .pqux-item-no,.pqux-print-no{width:30px;height:30px;border-radius:999px;background:#eef2f7;color:#415068;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
    .pqux-item-title{font-size:15px;font-weight:700;margin:0 0 5px}
    .pqux-item-sub{display:flex;flex-wrap:wrap;gap:10px;color:#657184;font-size:12px}
    .pqux-item-note{margin-top:8px;color:#526174;font-size:12px;line-height:1.5}
    .pqux-totals{min-width:210px}
    .pqux-money{display:flex;justify-content:space-between;gap:10px;font-size:12px;padding:3px 0}
    .pqux-money span{color:#657184}
    .pqux-money strong{font-weight:700;color:#172033}
    .pqux-print-list{display:flex;flex-direction:column;gap:10px;margin-top:12px;padding-top:12px;border-top:1px solid #edf1f6}
    .pqux-print{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:start}
    .pqux-print-title{font-size:13px;font-weight:700;margin:0 0 4px}
    .pqux-print-sub{display:flex;flex-wrap:wrap;gap:10px;color:#657184;font-size:12px}
    .pqux-print-note{margin-top:6px;color:#526174;font-size:12px;line-height:1.45}
    .pqux-total-table{display:flex;flex-direction:column;gap:8px}
    .pqux-total-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #f0f3f7;font-size:13px}
    .pqux-total-row:last-child{border-bottom:0}
    .pqux-total-row span{color:#657184}
    .pqux-total-row strong{font-weight:700;color:#172033}
    .pqux-total-row.grand strong,.pqux-total-row.grand span{font-size:14px;color:#172033}
    .pqux-history details{border:0}
    .pqux-history summary{cursor:pointer;list-style:none;font-size:13px;font-weight:700;color:#172033}
    .pqux-history summary::-webkit-details-marker{display:none}
    .pqux-history-list{display:flex;flex-direction:column;gap:8px;margin-top:14px}
    .pqux-history-row{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid #eef2f7;border-radius:10px;background:#fbfcfe}
    .pqux-history-row span{font-size:12px;color:#657184}
    .pqux-history-row strong{font-size:12px;font-weight:700;color:#172033}
    .pqux-log-row{padding:12px;border:1px solid #eef2f7;border-radius:10px;background:#fbfcfe}
    .pqux-log-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
    .pqux-log-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;color:#657184;font-size:12px}
    .pqux-log-detail{margin-top:8px;color:#526174;font-size:12px;line-height:1.5}
    @media (max-width: 1100px){.pqux-layout,.pqux-top-grid{grid-template-columns:1fr}.pqux-actions{justify-content:flex-start}.pqux-band-top,.pqux-decision{flex-direction:column}}
    @media (max-width: 720px){.pqux-summary-grid,.pqux-approval-grid,.pqux-top-grid{grid-template-columns:1fr}.pqux-item-top,.pqux-print,.pqux-log-top{grid-template-columns:1fr;display:block}.pqux-totals{min-width:0}.pqux-history-row{flex-direction:column}.pqux-actions .pd-btn,.pqux-cta-row .pd-btn,.pqux-action-buttons .pd-btn{width:100%;justify-content:center}.pqux-action-buttons form{width:100%}}
</style>

<div class="pqux-page">
    @if(session('success'))
        <div class="pqux-flash pqux-flash-success" data-testid="quote-send-success-flash">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="pqux-flash pqux-flash-error" data-testid="quote-send-error-flash">{{ $errors->first() }}</div>
    @endif

    <section class="pqux-band">
        <div class="pqux-band-top">
            <div>
                <h1 class="pqux-band-title">{{ $quote->document_number }}</h1>
                <p class="pqux-band-subtitle">{{ $quote->customer?->legal_name ?: '-' }} için hazırlanan teklif. Gönderim, müşteri yanıtı ve siparişe dönüş kararı bu alandan yönetilir.</p>
                <div class="pqux-meta">
                    <span class="pd-badge {{ $statusClass }}">Teklif Durumu: {{ $displayStatusLabel }}</span>
                    <span class="pd-badge {{ $approvalStateClass }}">Müşteri Onayı: {{ $approvalStateLabel }}</span>
                    <span class="pd-badge pd-badge-slate">Teslimat Tipi: {{ $quote->delivery_type ?: 'Belirtilmedi' }}</span>
                    @if($quote->valid_until)
                        <span class="pd-badge pd-badge-slate">Geçerlilik: {{ $quote->valid_until->format('d.m.Y') }}</span>
                    @endif
                    @if($isConverted && $linkedOrder)
                        <span class="pd-badge pd-badge-green">Bağlı Sipariş: {{ $linkedOrder->document_number }}</span>
                    @endif
                </div>
            </div>

            <div class="pqux-actions">
                @if($quotePdfAvailable)
                    <a href="{{ route('admin.promotion-quotes.pdf', $quote) }}" class="pd-btn pd-btn-light" target="_blank" rel="noopener">PDF Teklif</a>
                @endif
                @if($showSendAction)
                    <button type="button" class="pd-btn pd-btn-primary" data-open-send-modal>{{ $sendActionLabel }}</button>
                @endif
                @if($canConvert)
                    <button type="button" class="pd-btn pd-btn-success" data-open-convert-modal data-testid="quote-open-convert-modal-button">Siparişe Çevir ve Süreci Başlat</button>
                @elseif($isConverted && $linkedOrder)
                    <a href="{{ route('admin.orders.show', $linkedOrder) }}" class="pd-btn pd-btn-success" data-testid="quote-open-order-button">Siparişi Aç</a>
                @endif
            </div>
        </div>

        <div class="pqux-decision">
            <div>
                <h2>Teklif Durumu ve Sıradaki Karar</h2>
                <p><strong>{{ $decisionHeadline }}</strong> {{ $decisionSupport }}</p>
            </div>
            <div class="pqux-meta">
                <span class="pd-badge {{ $approvalCardStatusClass }}">{{ $approvalCardStatusLabel }}</span>
                <span class="pd-badge pd-badge-slate">{{ $processStatusLabel }}</span>
            </div>
        </div>

        <div class="pqux-top-grid">
            <div class="pqux-summary-card">
                <div class="pqux-summary-label">Müşteri</div>
                <div class="pqux-summary-value">{{ $quote->customer?->legal_name ?: '-' }}</div>
            </div>
            <div class="pqux-summary-card">
                <div class="pqux-summary-label">Teklif Tarihi</div>
                <div class="pqux-summary-value">{{ optional($quote->quote_date)->format('d.m.Y') ?: '-' }}</div>
            </div>
            <div class="pqux-summary-card">
                <div class="pqux-summary-label">Teslim Tarihi / Geçerlilik</div>
                <div class="pqux-summary-value">{{ optional($quote->valid_until)->format('d.m.Y') ?: '-' }}</div>
            </div>
            <div class="pqux-summary-card">
                <div class="pqux-summary-label">Müşteri Fiyat Görünümü</div>
                <div class="pqux-summary-value" style="font-size:14px;">{{ $customerPrintVisibilityLabel }}</div>
            </div>
        </div>
    </section>

    <div class="pqux-layout">
        <div class="pqux-stack">
            <section class="pqux-card" data-testid="quote-send-actions-card">
                <div class="pqux-card-head">
                    <div>
                        <h3>Gönderim Aksiyonları</h3>
                        <p>Teklifi müşteriye gönderin, onay linkini açın, WhatsApp hazır mesajı oluşturun ve PDF durumunu buradan takip edin.</p>
                    </div>
                    <span class="pd-badge {{ $approvalCardStatusClass }}">{{ $approvalCardStatusLabel }}</span>
                </div>

                <div class="pqux-action-list">
                    <div class="pqux-action-box">
                        <strong>Gönderim Durumu</strong>
                        <span>{{ $quote->last_sent_at ? 'Son gönderim ' . $quote->last_sent_at->format('d.m.Y H:i') . ' tarihinde oluşturuldu.' : 'Teklif henüz müşteriye gönderilmedi.' }}</span>
                    </div>
                    <div class="pqux-action-box">
                        <strong>Onay Linki</strong>
                        <span>{{ $approvalHelperUrl ? 'Aktif onay bağlantısı hazır. İsterseniz müşteri ekranını doğrudan açabilirsiniz.' : 'Onay bağlantısı için önce geçerli bir gönderim oluşturulmalı.' }}</span>
                    </div>
                    <div class="pqux-action-box">
                        <strong>WhatsApp Hazır Mesaj</strong>
                        <span>{{ $whatsappActionLabel }}</span>
                    </div>
                    <div class="pqux-action-box">
                        <strong>PDF Teklif</strong>
                        <span>{{ $pdfActionLabel }}</span>
                    </div>
                    <div class="pqux-action-box" data-testid="quote-send-runtime-summary">
                        <strong>Son Oluşturulan Kayıtlar</strong>
                        <span>
                            E-posta: {{ $sendNotificationSummary['email']['status'] }}
                            @if($sendNotificationSummary['whatsapp']['status'])
                                · WhatsApp: {{ $sendNotificationSummary['whatsapp']['status'] }}
                            @endif
                            @if($sendNotificationSummary['internal']['status'])
                                · İç Kayıt: {{ $sendNotificationSummary['internal']['status'] }}
                            @endif
                        </span>
                        @if($sendNotificationSummary['email']['helper'] || $sendNotificationSummary['whatsapp']['helper'] || $sendNotificationSummary['internal']['helper'])
                            <span>
                                {{ $sendNotificationSummary['email']['helper'] ?: ($sendNotificationSummary['whatsapp']['helper'] ?: $sendNotificationSummary['internal']['helper']) }}
                            </span>
                        @endif
                    </div>
                </div>

                <div class="pqux-action-list" style="margin-top:12px;">
                    <div class="pqux-action-group">
                        <div class="pqux-action-group-title">
                            <strong>Birincil satış aksiyonları</strong>
                            <span>Günlük gönderim akışında en sık kullanılan adımlar</span>
                        </div>
                        <div class="pqux-action-buttons">
                            @if($showSendAction)
                                <button type="button" class="pd-btn pd-btn-primary" data-open-send-modal data-testid="quote-send-action-button">{{ $sendActionLabel }}</button>
                            @endif

                            @if($quotePdfAvailable)
                                <a href="{{ route('admin.promotion-quotes.pdf', $quote) }}" class="pd-btn pd-btn-light" target="_blank" rel="noopener" data-testid="quote-pdf-download-button">PDF Teklif</a>
                            @else
                                <span class="pd-btn pd-btn-light pd-btn-disabled" data-testid="quote-pdf-disabled" title="PDF teklif çıktısı ayrı bir fazda bağlanacak">PDF Teklif, yakında</span>
                            @endif

                            @if($whatsappAvailable && $whatsappReady)
                                <form method="POST" action="{{ route('admin.promotion-quotes.whatsapp.open', $quote) }}" target="_blank">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-light" data-testid="quote-whatsapp-send-button">WhatsApp Gönder</button>
                                </form>
                            @elseif($whatsappAvailable)
                                <span class="pd-btn pd-btn-light pd-btn-disabled" data-testid="quote-whatsapp-send-disabled" title="{{ $whatsappActionLabel }}">WhatsApp Gönder</span>
                            @endif
                        </div>
                    </div>

                    <div class="pqux-action-group">
                        <div class="pqux-action-group-title">
                            <strong>Onay ve süreç aksiyonları</strong>
                            <span>Müşteri onayı ve siparişe geçiş için kullanılır</span>
                        </div>
                        <div class="pqux-action-buttons">
                            @if($approvalHelperUrl)
                                <a href="{{ $approvalHelperUrl }}" class="pd-btn pd-btn-light" data-testid="quote-open-approval-link-button">Public Onay Linkini Aç</a>
                            @endif
                            @if($canConvert)
                                <button type="button" class="pd-btn pd-btn-success" data-open-convert-modal data-testid="quote-convert-cta">Siparişe Çevir ve Süreci Başlat</button>
                            @elseif($isConverted && $linkedOrder)
                                <a href="{{ route('admin.orders.show', $linkedOrder) }}" class="pd-btn pd-btn-success" data-testid="quote-open-order-button">Siparişi Aç</a>
                            @else
                                <span class="pd-btn pd-btn-light pd-btn-disabled" data-testid="quote-convert-cta-disabled" title="{{ $convertSummary }}">Siparişe Çevir ve Süreci Başlat</span>
                            @endif
                        </div>
                    </div>

                    <div class="pqux-action-group">
                        <div class="pqux-action-group-title">
                            <strong>İkincil aksiyonlar</strong>
                            <span>Düzenleme ve kayıt takibi için yardımcı aksiyonlar</span>
                        </div>
                        <div class="pqux-action-buttons">
                            @if($quote->canBeEdited() && ! $isConverted)
                                <a href="{{ route('admin.promotion-quotes.edit', $quote) }}" class="pd-btn pd-btn-light">Teklifi Düzenle</a>
                            @endif
                            <a href="{{ route('admin.promotion-quotes.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                            <a href="#quote-log-history" class="pd-btn pd-btn-light">Log / Gönderim Geçmişi</a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="pqux-card">
                <div class="pqux-card-head">
                    <div>
                        <h3>Müşteri Onayı</h3>
                        <p>Gönderim, görüntüleme ve yanıt durumu satış kararını doğrudan etkiler.</p>
                    </div>
                    <span class="pd-badge {{ $approvalCardStatusClass }}">{{ $approvalCardStatusLabel }}</span>
                </div>

                <div class="pqux-approval-grid">
                    <div class="pqux-info-box">
                        <div class="pqux-info-label">Gönderim Durumu</div>
                        <div class="pqux-info-value">{{ $quote->last_sent_at ? 'Gönderildi' : 'Gönderilmedi' }}</div>
                    </div>
                    <div class="pqux-info-box">
                        <div class="pqux-info-label">Son Gönderim</div>
                        <div class="pqux-info-value {{ $quote->last_sent_at ? '' : 'muted' }}">{{ $quote->last_sent_at ? $quote->last_sent_at->format('d.m.Y H:i') : 'Henüz gönderilmedi' }}</div>
                    </div>
                    <div class="pqux-info-box">
                        <div class="pqux-info-label">Müşteri Hareketi</div>
                        <div class="pqux-info-value">{{ $customerResponseSummary }}</div>
                    </div>
                    <div class="pqux-info-box">
                        <div class="pqux-info-label">Görüntülenme</div>
                        <div class="pqux-info-value {{ $latestApprovalRequest?->viewed_at ? '' : 'muted' }}">{{ $latestApprovalRequest?->viewed_at ? $latestApprovalRequest->viewed_at->format('d.m.Y H:i') : 'Henüz görüntülenmedi' }}</div>
                    </div>
                    <div class="pqux-info-box">
                        <div class="pqux-info-label">Yanıt Tarihi</div>
                        <div class="pqux-info-value {{ $latestApprovalRequest?->responded_at ? '' : 'muted' }}">{{ $latestApprovalRequest?->responded_at ? $latestApprovalRequest->responded_at->format('d.m.Y H:i') : 'Yanıt bekleniyor' }}</div>
                    </div>
                    <div class="pqux-info-box">
                        <div class="pqux-info-label">Gönderim Kanalı</div>
                        <div class="pqux-info-value {{ $lastSentChannel ? '' : 'muted' }}">{{ $lastSentChannel ?: 'Kayıt yok' }}</div>
                    </div>
                </div>

                @if($latestCustomerNote)
                    <div class="pqux-note">Müşteri notu: {{ Str::limit($latestCustomerNote, 220) }}</div>
                @endif

                <div class="pqux-cta-row">
                    @if($quote->canBeEdited() && ! $isConverted)
                        <a href="{{ route('admin.promotion-quotes.edit', $quote) }}" class="pd-btn pd-btn-light">Teklifi Düzenle</a>
                    @endif

                    @if($canApproveQuotes && ! $isConverted && ! $canConvert)
                        <form method="POST" action="{{ route('admin.promotion-quotes.mark-approved', $quote) }}">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-success" data-testid="quote-mark-approved-button">Onaylandı İşaretle</button>
                        </form>
                    @endif

                    @if($showSendAction)
                        <button type="button" class="pd-btn pd-btn-primary" data-open-send-modal>{{ $sendActionLabel }}</button>
                    @endif

                    @if($approvalHelperUrl)
                        <a href="{{ $approvalHelperUrl }}" class="pd-btn pd-btn-light">Public Onay Linkini Aç</a>
                    @endif
                </div>
            </section>

            <section class="pqux-card">
                <div class="pqux-card-head">
                    <div>
                        <h3>Ürün ve Baskı Kalemleri</h3>
                        <p>{{ $itemCount }} ürün kalemi, {{ $printCount }} baskı işlemi. Teklifin satış özeti aşağıda korunur.</p>
                    </div>
                </div>

                <div class="pqux-item-list">
                    @foreach($quote->items as $index => $item)
                        @php
                            $visiblePrints = $item->prints->filter(function ($print) {
                                return filled($print->print_type)
                                    || filled($print->print_option)
                                    || (float) $print->print_quantity > 0
                                    || (float) $print->print_unit_price > 0
                                    || (float) $print->print_total > 0
                                    || filled($print->note);
                            })->values();
                        @endphp
                        <article class="pqux-item">
                            <div class="pqux-item-top">
                                <div class="pqux-item-no">{{ $index + 1 }}</div>
                                <div>
                                    <div class="pqux-item-title">{{ $item->product_name ?: '-' }}</div>
                                    <div class="pqux-item-sub">
                                        <span>{{ $item->product_code ?: '-' }}</span>
                                        <span>{{ number_format((float) $item->quantity, 2, ',', '.') }} {{ $item->unit }}</span>
                                    </div>
                                    @if(filled($item->description))
                                        <div class="pqux-item-note">{{ $item->description }}</div>
                                    @endif
                                </div>
                                @if($canViewFinancialData)
                                    <div class="pqux-totals">
                                        <div class="pqux-money"><span>Ürün birim fiyatı</span><strong>{{ number_format((float) $item->unit_price, 2, ',', '.') }} {{ $quote->currency }}</strong></div>
                                        <div class="pqux-money"><span>Ürün toplamı</span><strong>{{ number_format((float) $item->line_total, 2, ',', '.') }} {{ $quote->currency }}</strong></div>
                                    </div>
                                @endif
                            </div>

                            @if($visiblePrints->isNotEmpty())
                                <div class="pqux-print-list">
                                    @foreach($visiblePrints as $printIndex => $print)
                                        @php
                                            $printTitle = trim(collect([$print->print_type, $print->print_option])->filter()->implode(' '));
                                        @endphp
                                        <div class="pqux-print">
                                            <div class="pqux-print-no">{{ ($index + 1) . chr(97 + $printIndex) }}</div>
                                            <div>
                                                <div class="pqux-print-title">{{ $printTitle !== '' ? $printTitle : 'Baskı detayı' }}</div>
                                                <div class="pqux-print-sub">
                                                    <span>{{ number_format((float) $print->print_quantity, 2, ',', '.') }} adet</span>
                                                    @if($canViewFinancialData)
                                                        <span>{{ number_format((float) $print->print_unit_price, 2, ',', '.') }} {{ $quote->currency }}</span>
                                                        <span>{{ number_format((float) $print->print_total, 2, ',', '.') }} {{ $quote->currency }}</span>
                                                    @endif
                                                </div>
                                                @if(filled($print->note))
                                                    <div class="pqux-print-note">{{ $print->note }}</div>
                                                @endif
                                                @if($canViewFinancialData && $print->setup_pricing_enabled)
                                                    <div class="pqux-print-note">
                                                        Ara eleman özeti:
                                                        {{ $print->setup_type ?: 'Standart setup' }}
                                                        · {{ $print->setup_status ?: ($print->cliche_status ?: 'Durum belirtilmedi') }}
                                                        · Toplam: {{ number_format((float) $print->setup_total_amount, 2, ',', '.') }} {{ $quote->currency }}
                                                        · Birim etki: {{ number_format((float) $print->setup_unit_amount, 2, ',', '.') }} {{ $quote->currency }}
                                                        · Baskı birim fiyatına dahil
                                                    </div>
                                                @endif
                                            </div>
                                            @if($canViewFinancialData)
                                                <div class="pqux-totals">
                                                    <div class="pqux-money"><span>Birim baskı fiyatı</span><strong>{{ number_format((float) $print->print_unit_price, 2, ',', '.') }} {{ $quote->currency }}</strong></div>
                                                    <div class="pqux-money"><span>Baskı toplamı</span><strong>{{ number_format((float) $print->print_total, 2, ',', '.') }} {{ $quote->currency }}</strong></div>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        </div>

        <div class="pqux-stack">
            <section class="pqux-card">
                <div class="pqux-card-head">
                    <div>
                        <h3>Siparişe Çevirme</h3>
                        <p>Bu teklifin siparişe dönüşmeye hazır olup olmadığı burada sade dille görünür.</p>
                    </div>
                    @if($canConvert)
                        <span class="pd-badge pd-badge-green">Hazır</span>
                    @else
                        <span class="pd-badge pd-badge-slate">Bekliyor</span>
                    @endif
                </div>

                <div class="pqux-convert">
                    <strong>{{ $convertSummary }}</strong>
                    <span>{{ $canConvert ? 'Siparişe çevirerek grafik, tedarik, üretim ve teslimat akışını başlatabilirsiniz.' : $decisionSupport }}</span>
                </div>

                @if(! $canConvert && ! empty($convertIssues))
                    <div class="pqux-note">
                        <div>Bu kayıt teklif aşamasındadır. Onaylandıktan sonra siparişe çevrilir.</div>
                        <div>{{ collect($convertIssues)->implode(' ') }}</div>
                    </div>
                @endif
            </section>

            <section class="pqux-card">
                <div class="pqux-card-head">
                    <div>
                        <h3>Teklif Özeti</h3>
                        <p>Satış toplamları teklif bağlamında korunur.</p>
                    </div>
                </div>
                @if($canViewFinancialData)
                    <div class="pqux-total-table">
                        <div class="pqux-total-row">
                            <span>Ürün Toplamı</span>
                            <strong>{{ number_format((float) $quote->product_total, 2, ',', '.') }} {{ $quote->currency }}</strong>
                        </div>
                        <div class="pqux-total-row">
                            <span>Baskı Toplamı</span>
                            <strong>{{ number_format((float) $quote->print_total, 2, ',', '.') }} {{ $quote->currency }}</strong>
                        </div>
                        <div class="pqux-total-row">
                            <span>Ara Toplam</span>
                            <strong>{{ number_format((float) $quote->subtotal, 2, ',', '.') }} {{ $quote->currency }}</strong>
                        </div>
                        @if($hasVatSummary)
                            @foreach($summaryVatRows as $vatRow)
                                <div class="pqux-total-row">
                                    <span>{{ $vatRow['label'] }}</span>
                                    <strong>{{ number_format((float) $vatRow['amount'], 2, ',', '.') }} {{ $quote->currency }}</strong>
                                </div>
                            @endforeach
                            <div class="pqux-total-row">
                                <span>KDV Toplamı</span>
                                <strong>{{ number_format((float) $quote->vat_total, 2, ',', '.') }} {{ $quote->currency }}</strong>
                            </div>
                        @endif
                        <div class="pqux-total-row grand">
                            <span>Genel Toplam</span>
                            <strong>{{ number_format((float) $quote->grand_total, 2, ',', '.') }} {{ $quote->currency }}</strong>
                        </div>
                    </div>
                @else
                    <div class="pqux-note"><strong>Gizli</strong> · Finansal bilgiler yetkiniz dışında gizlendi.</div>
                @endif
            </section>

            <section class="pqux-card pqux-history" id="quote-log-history">
                <div class="pqux-card-head" style="margin-bottom:0;">
                    <div>
                        <h3>Son Gönderim Kayıtları</h3>
                        <p>E-posta, WhatsApp ve iç kayıt hareketleri kısa ve güvenli özetlerle gösterilir.</p>
                    </div>
                </div>
                <div class="pqux-history-list">
                    @forelse($notificationLogRows as $log)
                        <div class="pqux-log-row">
                            <div class="pqux-log-top">
                                <div>
                                    <strong>{{ $log['channel'] }} · {{ $log['status'] }}</strong>
                                    <div class="pqux-log-meta">
                                        <span>{{ $log['date'] ?: '-' }}</span>
                                        <span>Alıcı: {{ $log['recipient'] }}</span>
                                    </div>
                                </div>
                                <span class="pd-badge pd-badge-slate">{{ $log['channel'] }}</span>
                            </div>
                            <div class="pqux-log-detail">{{ $log['detail'] }}</div>
                        </div>
                    @empty
                        <div class="pqux-history-row">
                            <span>Henüz gönderim kaydı yok</span>
                            <strong>-</strong>
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="pqux-card pqux-history">
                <details>
                    <summary>Gönderim Geçmişi ve İkincil Bilgiler</summary>
                    <div class="pqux-history-list">
                        @forelse($sendHistoryRows as $history)
                            <div class="pqux-history-row">
                                <span>{{ $history['date'] }} · {{ $history['channel'] }}</span>
                                <strong>{{ $history['recipient'] }} · {{ $history['status'] }}</strong>
                            </div>
                        @empty
                            <div class="pqux-history-row">
                                <span>Henüz gönderim yok</span>
                                <strong>-</strong>
                            </div>
                        @endforelse
                    </div>
                </details>
            </section>
        </div>
    </div>
</div>

@if($showSendAction)
    <div class="pqd-modal" id="quoteSendModal" aria-hidden="true">
        <div class="pqd-modal-panel" role="dialog" aria-modal="true" aria-labelledby="quote-send-modal-title">
            <div class="pqd-card-title" id="quote-send-modal-title">Müşteriye Gönder</div>
            <div class="pqd-card-note">Gönderim bilgilerini kontrol edin.</div>
            <form method="POST" action="{{ route('admin.promotion-quotes.send-to-customer', $quote) }}" class="mt-4">
                @csrf
                <div class="pqd-modal-grid">
                    <div class="pqd-box">
                        <div class="pqd-label">Alıcı Adı</div>
                        <input type="text" name="contact_name" value="{{ old('contact_name', $quote->customer?->legal_name) }}" class="mt-2 w-full border border-gray-300 rounded px-3 py-2">
                    </div>
                    <div class="pqd-box">
                        <div class="pqd-label">Geçerlilik Süresi</div>
                        <input type="number" name="expires_in_days" min="1" max="30" value="{{ old('expires_in_days', 7) }}" class="mt-2 w-full border border-gray-300 rounded px-3 py-2">
                    </div>
                    <div class="pqd-box">
                        <div class="pqd-label">E-posta</div>
                        <input type="email" name="contact_email" value="{{ old('contact_email', $quote->customer?->email) }}" class="mt-2 w-full border border-gray-300 rounded px-3 py-2">
                    </div>
                    <div class="pqd-box">
                        <div class="pqd-label">WhatsApp Cep Telefonu</div>
                        <div class="mt-2" style="display:flex; align-items:center; border:1px solid #d1d5db; border-radius:8px; overflow:hidden; background:#fff;">
                            <span style="display:inline-flex; align-items:center; gap:8px; padding:0 12px; min-height:42px; background:#f8fafc; border-right:1px solid #e5e7eb; color:#344054; font-size:13px; white-space:nowrap;">🇹🇷 +90</span>
                            <input type="text" name="contact_phone" value="{{ app(\App\Services\PhoneNumberNormalizer::class)->formatTurkishPhoneForDisplay(old('contact_phone', $quote->customer?->mobile ?: $quote->customer?->phone)) ?: old('contact_phone', $quote->customer?->mobile ?: $quote->customer?->phone) }}" class="w-full border-0 rounded-none px-3 py-2" placeholder="5xx xxx xx xx">
                        </div>
                    </div>
                </div>
                    <div class="pqd-box mt-3">
                    <div class="pqd-label">Kanal</div>
                    <select name="sent_channel" class="mt-2 w-full border border-gray-300 rounded px-3 py-2">
                        <option value="manual">Standart Gönderim</option>
                        <option value="email">E-posta Önizleme</option>
                        <option value="whatsapp_link">WhatsApp Link</option>
                    </select>
                </div>
                <div class="pqd-note mt-3">Gönderilen teklif hali kayıt altına alınır.</div>
                <div class="pqd-actions mt-4">
                    <button type="button" class="pd-btn pd-btn-light" data-close-send-modal>Vazgeç</button>
                    <button type="submit" class="pd-btn pd-btn-primary">Gönder</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if($canConvert)
    <div class="pqd-modal" id="quoteConvertModal" aria-hidden="true">
        <div class="pqd-modal-panel" role="dialog" aria-modal="true" aria-labelledby="quote-convert-modal-title">
            <div class="pqd-card-title" id="quote-convert-modal-title">Siparişe Çevir</div>
            <div class="pqd-card-note">Onaylanan teklif siparişe dönüşünce süreç başlar.</div>
            <div class="pqd-modal-grid mt-4">
                <div class="pqd-box"><div class="pqd-label">Teklif No</div><div class="pqd-value">{{ $quote->document_number }}</div></div>
                <div class="pqd-box"><div class="pqd-label">Müşteri</div><div class="pqd-value">{{ $quote->customer?->legal_name ?: '-' }}</div></div>
                <div class="pqd-box"><div class="pqd-label">Onay Durumu</div><div class="pqd-value">{{ $displayStatusLabel }}</div></div>
                <div class="pqd-box"><div class="pqd-label">Bilgi</div><div class="pqd-value">Süreç başlayacak</div></div>
            </div>
            <div class="pqd-actions mt-4">
                <button type="button" class="pd-btn pd-btn-light" data-close-convert-modal>Vazgeç</button>
                <form method="POST" action="{{ route('admin.orders.convert.from.quote', $quote) }}" data-testid="quote-convert-form">
                    @csrf
                    <button type="submit" class="pd-btn pd-btn-success">Siparişe Çevir</button>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection

@section('bottom_actions')
<div>
    <strong>Teklif Akışı:</strong>
    <span class="pd-muted">{{ $decisionHeadline }} {{ $decisionSupport }}</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.promotion-quotes.index') }}" class="pd-btn pd-btn-light">Teklifleri Listele</a>
    @if($quote->canBeEdited() && !$isConverted)
        <a href="{{ route('admin.promotion-quotes.edit', $quote) }}" class="pd-btn pd-btn-primary">Düzenle</a>
    @endif
    @if($showSendAction)
        <button type="button" class="pd-btn pd-btn-light" data-open-send-modal>{{ $sendActionLabel }}</button>
    @endif
    @if($approvalHelperUrl)
        <a href="{{ $approvalHelperUrl }}" class="pd-btn pd-btn-light">Public Onay Linkini Aç</a>
    @endif
    @if($canApproveQuotes && !$isConverted && !$canConvert)
        <form method="POST" action="{{ route('admin.promotion-quotes.mark-approved', $quote) }}">
            @csrf
            <button type="submit" class="pd-btn pd-btn-success" data-testid="quote-mark-approved-button">Onaylandı İşaretle</button>
        </form>
    @endif
    @if($canConvert)
        <button type="button" class="pd-btn pd-btn-success" data-open-convert-modal data-testid="quote-convert-cta">Siparişe Çevir ve Süreci Başlat</button>
    @endif
    @if($isConverted && $linkedOrder)
        <a href="{{ route('admin.orders.show', $linkedOrder) }}" class="pd-btn pd-btn-success">Siparişi Aç</a>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    function bindModal(modalId, openSelector, closeSelector) {
        var modal = document.getElementById(modalId);
        if (!modal) {
            return;
        }

        function openModal() {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll(openSelector).forEach(function (button) {
            button.addEventListener('click', openModal);
        });

        document.querySelectorAll(closeSelector).forEach(function (button) {
            button.addEventListener('click', closeModal);
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    bindModal('quoteSendModal', '[data-open-send-modal]', '[data-close-send-modal]');
    bindModal('quoteConvertModal', '[data-open-convert-modal]', '[data-close-convert-modal]');

    @if($showSendAction && ($errors->any() || old('contact_email') || old('contact_phone') || old('contact_name')))
    var sendModal = document.getElementById('quoteSendModal');
    if (sendModal) {
        sendModal.classList.add('is-open');
        sendModal.setAttribute('aria-hidden', 'false');
    }
    @endif
});
</script>
@endpush
