@extends('layouts.prodelya-admin')

@section('title', $quote->document_number)
@section('hide_side_summary', '1')
@section('page_topbar_hidden', '1')

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
        Order::CUSTOMER_APPROVAL_WAITING => 'Yanıt Bekleniyor',
        Order::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'Revize Bekleniyor',
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

    $lastSentChannel = $latestApprovalRequest?->sendSnapshot?->safeSendLabel() ?: 'Kayıt yok';
    $customerPrintVisibilityLabel = $quote->shouldShowPrintPriceDetailsToCustomer()
        ? 'Baskı detayı müşteriye görünür'
        : 'Baskı detayı müşteriye gizli';

    $sendStatusLabel = match (true) {
        $quote->last_sent_at && ($sendNotificationSummary['email']['status'] ?? null) === 'Gönderildi' => 'Gönderildi',
        $quote->last_sent_at => 'Hazır',
        default => 'Hazır değil',
    };

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
        $quote->last_sent_at => 'Gönderim durumu ve müşteri hareketi sekmelerden takip edilebilir.',
        default => 'Önce ürün ve baskı detayını kontrol edin, sonra müşteriye gönderin.',
    };

    $convertSummary = match (true) {
        $isConverted => 'Sipariş süreci başladı.',
        $canConvert => 'Teklif onaylandı, siparişe çevrilebilir.',
        ! empty($convertIssues) => $convertIssues[0],
        default => 'Müşteri onayı bekleniyor.',
    };

    $quoteShortDescription = $quote->customer?->legal_name
        ? $quote->customer->legal_name . ' için hazırlanan teklif.'
        : 'Hazırlanan teklif kaydı.';

    $recentLogRows = collect($notificationLogRows)->take(3)->values();
    $calculatedProductTotal = (float) $quote->items->sum(fn ($item) => (float) $item->line_total);
    $calculatedPrintTotal = (float) $quote->items->sum(function ($item) {
        $itemPrintTotal = (float) $item->print_total;

        if ($itemPrintTotal > 0) {
            return $itemPrintTotal;
        }

        return (float) $item->prints->sum(fn ($print) => (float) $print->print_total);
    });
    $summaryProductTotal = ((float) $quote->product_total > 0 || $calculatedProductTotal === 0.0)
        ? (float) $quote->product_total
        : $calculatedProductTotal;
    $summaryPrintTotal = ((float) $quote->print_total > 0 || $calculatedPrintTotal === 0.0)
        ? (float) $quote->print_total
        : $calculatedPrintTotal;
    $existingPublicQuoteUrl = $approvalHelperUrl;
    $initialPreviewMessage = $existingPublicQuoteUrl
        ? "Merhaba " . (old('contact_name', $latestApprovalRequest?->contact_name ?: ($quote->customer?->legal_name ?: 'Müşterimiz')) ?: 'Müşterimiz') . ",\n\n" . $quote->document_number . " numaralı teklifinizi inceleyebilirsiniz:\n" . $existingPublicQuoteUrl
        : "Merhaba " . (old('contact_name', $latestApprovalRequest?->contact_name ?: ($quote->customer?->legal_name ?: 'Müşterimiz')) ?: 'Müşterimiz') . ",\n\nPublic onay linki gönderim sonrası hazırlanır. WhatsApp Link kanalında link ayrı satırda tam URL olarak üretilir.";
    $moneyText = static function ($amount, ?string $currency = null) use ($canViewFinancialData): string {
        if (! $canViewFinancialData) {
            return 'Gizli';
        }

        return number_format((float) $amount, 2, ',', '.') . ($currency ? ' ' . $currency : '');
    };
    $quantityText = static function ($amount, ?string $unit = null): string {
        $value = (float) $amount;
        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;
        $formatted = number_format($value, $decimals, ',', '.');

        return trim($formatted . ' ' . ($unit ?: 'Adet'));
    };
    $quantityDecimalText = static function ($amount): string {
        return number_format((float) $amount, 2, ',', '.');
    };

    $selectedChannelIndex = match (old('sent_channel')) {
        'email' => 1,
        'whatsapp_link' => 2,
        default => 0,
    };
    $quoteGuideNotice = ($publicQuoteApprovalEnabled ?? false)
        ? 'WhatsApp Link için e-posta zorunlu değildir. Telefon alanı yeterliyse güvenli gönderim bağlantısı hazırlanır.'
        : null;
    $showConvertPlaceholder = ! $isConverted && $itemCount > 0 && ! $canConvert;
    $quoteAlertMessage = null;
    $quoteAlertType = null;
    $quoteAlertTestId = 'quote-alert';
    $buildLineSignals = static function ($item): array {
        $productSnapshot = (array) ($item->product_snapshot ?? []);
        $priceSnapshot = (array) ($item->price_snapshot ?? []);
        $stockSnapshot = (array) ($item->stock_snapshot ?? []);

        $signals = collect(array_merge(
            (array) data_get($productSnapshot, 'warning_badges', []),
            (array) data_get($priceSnapshot, 'warning_badges', []),
            (array) data_get($stockSnapshot, 'warning_badges', [])
        ))
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ((string) data_get($stockSnapshot, 'stock_status') === 'out_of_stock' && !$signals->contains('Stok uyarısı')) {
            $signals->push('Stok uyarısı');
        }

        if (((bool) data_get($priceSnapshot, 'net_price_warning')) && !$signals->contains('Fiyat kontrolü')) {
            $signals->push('Fiyat kontrolü');
        }

        if (((bool) data_get($priceSnapshot, 'price_policy_warning')) && !$signals->contains('Fiyat politikası')) {
            $signals->push('Fiyat politikası');
        }

        return $signals->take(4)->all();
    };

    if (session('success')) {
        $quoteAlertMessage = session('success');
        $quoteAlertType = 'success';
        $quoteAlertTestId = 'quote-alert-success';
    } elseif (session('error')) {
        $quoteAlertMessage = session('error');
        $quoteAlertType = 'error';
        $quoteAlertTestId = 'quote-alert-error';
    } elseif ($errors->any()) {
        $quoteAlertMessage = $errors->first();
        $quoteAlertType = 'error';
        $quoteAlertTestId = 'quote-alert-error';
    } elseif (session('warning')) {
        $quoteAlertMessage = session('warning');
        $quoteAlertType = 'warning';
        $quoteAlertTestId = 'quote-alert-warning';
    } elseif (! $canViewFinancialData) {
        $quoteAlertMessage = 'Finansal bilgiler yetkiniz dışında gizlendi.';
        $quoteAlertType = 'warning';
        $quoteAlertTestId = 'quote-alert-warning';
    }
@endphp

<div class="pd-quote-detail promotion-quote-detail quote-detail-compact">
    <section class="pd-card pd-quote-detail__page-head quote-page-head">
        <div>
            <div class="pd-quote-detail__eyebrow">Satış ve Sipariş</div>
            <h1>Promosyon Teklif Detayı</h1>
            <p>Ürün ve baskı bilgisi en üstte görülür; aksiyonlar buna göre alınır.</p>
        </div>
        <div class="quote-page-head-actions">
            <a href="{{ route('admin.promotion-quotes.index') }}" class="pd-btn pd-btn-light">Teklifleri Listele</a>
        </div>
    </section>

    @if($quoteGuideNotice)
        <section class="pd-card pd-quote-detail__notice quote-guide-notice" data-testid="quote-guide-notice">
            {{ $quoteGuideNotice }}
        </section>
    @endif

    @if($quoteAlertMessage)
        <section
            class="pd-card pd-quote-detail__notice quote-alert quote-alert-{{ $quoteAlertType }}"
            data-testid="{{ $quoteAlertTestId }}"
        >
            {{ $quoteAlertMessage }}
        </section>
    @endif

    <section class="pd-card pd-quote-detail__strip quote-strip">
        <div class="quote-strip-top">
            <div>
                <div class="quote-strip-number">{{ $quote->document_number }}</div>
                <p class="quote-strip-subtitle">{{ $quoteShortDescription }} {{ $decisionSupport }}</p>
                <div class="quote-strip-chips">
                    <span class="pd-badge {{ $statusClass }}">Teklif: {{ $displayStatusLabel }}</span>
                    <span class="pd-badge {{ $approvalStateClass }}">Müşteri: {{ $approvalStateLabel }}</span>
                    <span class="pd-badge pd-badge-slate">Gönderim: {{ $sendStatusLabel }}</span>
                    <span class="pd-badge pd-badge-slate">Fiyat Görünümü: {{ $customerPrintVisibilityLabel }}</span>
                </div>
                @if($sourceOrderContext['visible'])
                    <div class="quote-inline-note" data-testid="quote-source-order-summary">
                        <strong>{{ $sourceOrderContext['badge'] }}</strong>
                        ·
                        @if($sourceOrderContext['url'])
                            <a href="{{ $sourceOrderContext['url'] }}">{{ $sourceOrderContext['source_label'] }}</a>
                        @else
                            {{ $sourceOrderContext['source_label'] }}
                        @endif
                    </div>
                    <div class="quote-inline-note">{{ $sourceOrderContext['warning'] }}</div>
                    <div class="quote-inline-note">{{ $sourceOrderContext['general_warning'] }}</div>
                @endif
                @unless($quote->shouldShowPrintPriceDetailsToCustomer())
                    <div class="quote-inline-note">Baskı detayları müşteriye gizli</div>
                @endunless
            </div>
            <div class="quote-page-head-actions">
                @if($quotePdfAvailable)
                    <a href="{{ route('admin.promotion-quotes.pdf', $quote) }}" class="pd-btn pd-btn-light" target="_blank" rel="noopener">PDF Teklif</a>
                @endif
                @if($revisionCompareUrl)
                    <a href="{{ $revisionCompareUrl }}" class="pd-btn pd-btn-light" data-testid="quote-revision-compare-link">Revizyon Karşılaştır</a>
                @endif
                @if($showSendAction)
                    <button type="button" class="pd-btn pd-btn-primary" data-open-send-modal>{{ $sendActionLabel }}</button>
                @endif
            </div>
        </div>

        <div class="quote-top-metrics">
            <div class="quote-metric"><span>Müşteri</span><strong>{{ $quote->customer?->legal_name ?: '-' }}</strong></div>
            <div class="quote-metric"><span>Teklif Tarihi</span><strong>{{ optional($quote->quote_date)->format('d.m.Y') ?: '-' }}</strong></div>
            <div class="quote-metric"><span>Geçerlilik</span><strong>{{ optional($quote->valid_until)->format('d.m.Y') ?: '-' }}</strong></div>
            <div class="quote-metric"><span>Kalem / Baskı</span><strong>{{ $itemCount }} kalem / {{ $printCount }} baskı</strong></div>
            <div class="quote-metric"><span>Genel Toplam</span><strong>{{ $moneyText($quote->grand_total, $quote->currency) }}</strong></div>
        </div>
    </section>

    <div class="quote-layout">
        <div class="quote-main-stack">
            <section class="pd-card quote-card pd-quote-detail__flow-summary">
                <div class="quote-card-head">
                    <div>
                        <h3>Akış Özeti</h3>
                        <p>En önemli bilgi başa alındı: önce ürün ve baskı kalemleri, sonra karar ve gönderim durumu.</p>
                    </div>
                    <span class="quote-chip-soft">1. Öncelik: Ürün &amp; Baskı</span>
                </div>

                <div class="quote-action-band">
                    <div>
                        <strong>Teklif Durumu ve Sıradaki Karar</strong>
                        <p>{{ $decisionHeadline }} {{ $decisionSupport }}</p>
                        @if(! $canConvert && ! $isConverted)
                            <p>Bu kayıt teklif aşamasındadır. Onaylandıktan sonra siparişe çevrilir.</p>
                        @endif
                    </div>
                    <div class="quote-action-buttons">
                        <button type="button" class="pd-btn pd-btn-light" data-quote-tab-trigger="send">Gönderim</button>
                        <button type="button" class="pd-btn pd-btn-light" data-quote-tab-trigger="approval">Müşteri Onayı</button>
                        <button type="button" class="pd-btn pd-btn-light" data-quote-tab-trigger="history">Geçmiş</button>
                    </div>
                </div>

                <div class="quote-tab-metrics">
                    <div class="quote-mini-box"><span>Kalem Sayısı</span><strong>{{ $itemCount }} kalem</strong></div>
                    <div class="quote-mini-box"><span>Baskı İşlem Sayısı</span><strong>{{ $printCount }} işlem</strong></div>
                    <div class="quote-mini-box"><span>Teklif Toplamı</span><strong>{{ $moneyText($quote->grand_total, $quote->currency) }}</strong></div>
                </div>

                <div class="pd-quote-detail__priority-block pd-product-print-block quote-priority-block quote-priority-block-main">
                    <div class="pd-product-print-block__head quote-priority-head">
                        <div>
                            <h3>Ürün &amp; Baskı Kalemleri</h3>
                            <p>Kalemler klasik tablo gibi değil, kompakt satır yapısıyla ürün ve baskı hiyerarşisinde gösterilir.</p>
                        </div>
                        <span class="quote-chip-soft">{{ $itemCount }} ürün / {{ $printCount }} baskı</span>
                    </div>

                    <div class="pd-product-print-block__body promotion-quote-lines">
                        <div class="pd-product-print-block__grid-head promotion-quote-lines-head">
                            <div class="pd-product-print-block__grid-head-row promotion-quote-line-header">
                                <div class="promotion-quote-line-header-main">Kalem</div>
                                <div class="promotion-quote-line-header-cell">Adet</div>
                                <div class="promotion-quote-line-header-cell">Birim Fiyat</div>
                                <div class="promotion-quote-line-header-cell">Toplam</div>
                            </div>
                        </div>

                        <div class="promotion-quote-lines-body">
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
                            $productIndex = (string) ($index + 1);
                            $lineSignals = $buildLineSignals($item);
                        @endphp
                        <article class="pd-product-print-block__row pd-product-print-block__row--product pd-product-line promotion-quote-line promotion-quote-line-product quote-detail-item quote-item-row">
                            <div class="pd-product-print-block__index promotion-quote-line-index">{{ $productIndex }}</div>
                            <div class="pd-product-print-block__main promotion-quote-line-main">
                                <div class="promotion-quote-line-eyebrow">Ürün Satırı</div>
                                <h4 class="pd-product-print-block__title promotion-quote-line-title">{{ $item->product_name ?: '-' }}</h4>
                                <div class="pd-product-print-block__meta promotion-quote-line-meta">
                                    <span>{{ $item->product_code ?: 'Kod yok' }}</span>
                                    <span>{{ $visiblePrints->count() }} baskı satırı</span>
                                </div>
                                @if(! empty($lineSignals))
                                    <div class="pd-product-print-block__chips pd-product-line__signals" data-live-info-slot>
                                        @foreach($lineSignals as $signal)
                                            <span class="pd-chip pd-product-line__signal">{{ $signal }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="pd-product-print-block__chips pd-product-line__signals" data-live-info-slot hidden></div>
                                @endif
                                @if(filled($item->description))
                                    <div class="promotion-quote-line-note">{{ $item->description }}</div>
                                @endif
                            </div>
                            <div class="pd-product-print-block__amount promotion-quote-line-cell promotion-quote-line-cell-qty">
                                <span class="promotion-quote-line-cell-label">Adet</span>
                                <strong class="promotion-quote-line-cell-value" data-quantity-decimal="{{ $quantityDecimalText($item->quantity) }}">{{ $quantityText($item->quantity, $item->unit) }}</strong>
                            </div>
                            <div class="pd-product-print-block__amount promotion-quote-line-cell promotion-quote-line-cell-unit">
                                <span class="promotion-quote-line-cell-label">Birim Fiyat</span>
                                <strong class="promotion-quote-line-cell-value">{{ $moneyText($item->unit_price, $quote->currency) }}</strong>
                            </div>
                            <div class="pd-product-print-block__amount promotion-quote-line-cell promotion-quote-line-cell-total promotion-quote-line-total">
                                <span class="promotion-quote-line-cell-label">Ürün toplamı</span>
                                <strong class="promotion-quote-line-cell-value">{{ $moneyText($item->line_total, $quote->currency) }}</strong>
                            </div>
                        </article>

                        @foreach($visiblePrints as $printIndex => $print)
                            @php
                                $printCode = $productIndex . chr(97 + $printIndex);
                            @endphp
                            <article class="pd-product-print-block__row pd-product-print-block__row--print pd-print-line promotion-quote-line promotion-quote-line-print quote-detail-item quote-item-row">
                                <div class="pd-product-print-block__index promotion-quote-line-index">{{ $printCode }}</div>
                                <div class="pd-product-print-block__main promotion-quote-line-main">
                                    <div class="promotion-quote-line-eyebrow">Baskı Satırı</div>
                                    <h4 class="pd-product-print-block__title promotion-quote-line-title">
                                        {{ $print->print_type ?: 'Baskı detayı' }}
                                        @if(filled($print->print_option))
                                            <span>| {{ $print->print_option }}</span>
                                        @endif
                                    </h4>
                                    @if(filled($print->note))
                                        <div class="promotion-quote-line-note">{{ $print->note }}</div>
                                    @endif
                                </div>
                                <div class="pd-product-print-block__amount promotion-quote-line-cell promotion-quote-line-cell-qty">
                                    <span class="promotion-quote-line-cell-label">Adet</span>
                                    <strong class="promotion-quote-line-cell-value" data-quantity-decimal="{{ $quantityDecimalText($print->print_quantity) }}">{{ $quantityText($print->print_quantity, 'Adet') }}</strong>
                                </div>
                                <div class="pd-product-print-block__amount promotion-quote-line-cell promotion-quote-line-cell-unit">
                                    <span class="promotion-quote-line-cell-label">Birim baskı fiyatı</span>
                                    <strong class="promotion-quote-line-cell-value">{{ $moneyText($print->print_unit_price, $quote->currency) }}</strong>
                                </div>
                                <div class="pd-product-print-block__amount promotion-quote-line-cell promotion-quote-line-cell-total promotion-quote-line-total">
                                    <span class="promotion-quote-line-cell-label">Baskı toplamı</span>
                                    <strong class="promotion-quote-line-cell-value">{{ $moneyText($print->print_total, $quote->currency) }}</strong>
                                </div>
                            </article>
                        @endforeach
                    @endforeach
                        </div>
                    </div>

                    <div class="pd-product-print-block__foot quote-priority-totals promotion-quote-lines-total-band">
                        <div class="quote-metric"><span>Ürün Toplamı</span><strong>{{ $moneyText($summaryProductTotal, $quote->currency) }}</strong></div>
                        <div class="quote-metric"><span>Baskı Toplamı</span><strong>{{ $moneyText($summaryPrintTotal, $quote->currency) }}</strong></div>
                        <div class="quote-metric"><span>Ara Toplam</span><strong>{{ $moneyText($quote->subtotal, $quote->currency) }}</strong></div>
                        @if($hasVatSummary)
                            @foreach($summaryVatRows as $vatRow)
                                <div class="quote-metric"><span>{{ $vatRow['label'] }}</span><strong>{{ $moneyText($vatRow['amount'], $quote->currency) }}</strong></div>
                            @endforeach
                            <div class="quote-metric"><span>KDV Toplamı</span><strong>{{ $moneyText($quote->vat_total, $quote->currency) }}</strong></div>
                        @endif
                        <div class="quote-metric"><span>Genel Toplam</span><strong>{{ $moneyText($quote->grand_total, $quote->currency) }}</strong></div>
                    </div>
                </div>

                <div class="quote-status-grid">
                    <div class="quote-status-box">
                        <span>Gönderim Durumu</span>
                        <strong>{{ $quote->last_sent_at ? 'Gönderildi' : 'Hazır' }}</strong>
                    </div>
                    <div class="quote-status-box">
                        <span>Gönderim Kanalı</span>
                        <strong>{{ $lastSentChannel }}</strong>
                    </div>
                    <div class="quote-status-box">
                        <span>Müşteri Hareketi</span>
                        <strong>{{ $customerResponseSummary }}</strong>
                    </div>
                    <div class="quote-status-box">
                        <span>Siparişe Geçiş</span>
                        <strong>{{ $isConverted ? 'Başladı' : ($canConvert ? 'Hazır' : 'Bekliyor') }}</strong>
                    </div>
                </div>
            </section>

            <section class="pd-card quote-card quote-tabs-shell" data-testid="quote-detail-tabs-shell">
                <div class="quote-tabs" role="tablist">
                    <button type="button" class="quote-tab-button is-active" data-quote-tab="items">Ürün &amp; Baskı</button>
                    <button type="button" class="quote-tab-button" data-quote-tab="send">Gönderim</button>
                    <button type="button" class="quote-tab-button" data-quote-tab="approval">Müşteri Onayı</button>
                    <button type="button" class="quote-tab-button" data-quote-tab="history">Geçmiş</button>
                    <button type="button" class="quote-tab-button" data-quote-tab="notes">Notlar</button>
                </div>

                <div class="quote-tab-panel is-active" data-quote-panel="items">
                    <div class="quote-card-head">
                        <div>
                            <h3>Ürün &amp; Baskı Detayı</h3>
                            <p>Yukarıda öncelikli blokta kısa ve kompakt hali görünen kalemlerin detay kontrol alanı.</p>
                        </div>
                        <span class="quote-chip-soft">Detay kontrol</span>
                    </div>

                    <div class="quote-tab-metrics">
                        <div class="quote-mini-box"><span>Toplam Ürün Kalemi</span><strong>{{ $itemCount }} kalem</strong></div>
                        <div class="quote-mini-box"><span>Toplam Baskı İşlemi</span><strong>{{ $printCount }} işlem</strong></div>
                        <div class="quote-mini-box"><span>Teklif Toplamı</span><strong>{{ $moneyText($quote->grand_total, $quote->currency) }}</strong></div>
                    </div>

                    <div class="quote-note-box">
                        Bu sekme detay kontrol içindir. Günlük kullanımda önce görülmesi gereken ürün ve baskı listesi artık üst akış özetinde yer alır.
                    </div>
                </div>

                <div class="quote-tab-panel" data-quote-panel="send">
                    <div class="quote-card-head">
                        <div>
                            <h3>Gönderim</h3>
                            <div class="quote-inline-note">Gönderim Aksiyonları</div>
                            <p>Standart Gönderim, E-posta Önizleme ve WhatsApp Link ayrımı hotfix davranışı korunarak sunulur.</p>
                        </div>
                    </div>

                    <div class="quote-send-grid">
                        <div class="quote-send-card is-active">
                            <h3>Standart Gönderim</h3>
                            <p>E-posta varsa mail gönderir. Gönderim kaydı oluşturur.</p>
                        </div>
                        <div class="quote-send-card">
                            <h3>E-posta Önizleme</h3>
                            <p>Mail göndermez. Sadece e-posta içeriğini kontrol eder.</p>
                        </div>
                        <div class="quote-send-card">
                            <h3>WhatsApp Link</h3>
                            <p>E-posta istemez. Telefon yeterlidir. Link ayrı satırda tam URL olarak hazırlanır.</p>
                        </div>
                    </div>

                    <div class="quote-send-form-grid">
                        <div class="quote-send-field">
                            <label>Alıcı Adı</label>
                            <input type="text" value="{{ old('contact_name', $latestApprovalRequest?->contact_name ?: ($quote->customer?->legal_name ?: '-')) }}" readonly>
                        </div>
                        <div class="quote-send-field">
                            <label>E-posta</label>
                            <input type="text" value="{{ old('contact_email', $latestApprovalRequest?->contact_email ?: ($quote->customer?->email ?: '-')) }}" readonly>
                        </div>
                        <div class="quote-send-field">
                            <label>WhatsApp telefonu</label>
                            <input type="text" value="{{ old('contact_phone', $recipientPhoneDisplay ?: '-') }}" readonly>
                        </div>
                        <div class="quote-send-field">
                            <label>Aksiyon</label>
                            @if($showSendAction)
                                <button type="button" class="pd-btn pd-btn-primary w-full" data-open-send-modal>{{ $sendActionLabel }}</button>
                            @else
                                <span class="pd-btn pd-btn-light pd-btn-disabled">Gönderim kapalı</span>
                            @endif
                        </div>
                    </div>

                    <div class="quote-action-buttons">
                        @if($showSendAction)
                            <button type="button" class="pd-btn pd-btn-primary" data-open-send-modal>Müşteriye Gönder</button>
                        @endif
                        @if($approvalHelperUrl)
                            <a href="{{ $approvalHelperUrl }}" class="pd-btn pd-btn-light">Public Onay Linkini Aç</a>
                        @endif
                        @if($whatsappAvailable && $whatsappReady)
                            <form method="POST" action="{{ route('admin.promotion-quotes.whatsapp.open', $quote) }}">
                                @csrf
                                <button type="submit" class="pd-btn pd-btn-light">WhatsApp Gönder</button>
                            </form>
                        @elseif($whatsappAvailable)
                            <span class="pd-btn pd-btn-light pd-btn-disabled" data-testid="quote-whatsapp-send-disabled">Telefon yok</span>
                        @endif
                    </div>

                </div>

                <div class="quote-tab-panel" data-quote-panel="approval">
                    <div class="quote-card-head">
                        <div>
                            <h3>Müşteri Onayı</h3>
                            <p>Dört adımlı takip alanı: teklif hazır, gönderildi, müşteri yanıtı ve siparişe çevirme.</p>
                        </div>
                    </div>

                    <div class="quote-step-line">
                        <div class="quote-step is-done">
                            <div class="quote-step-badge">1</div>
                            <span>Teklif Hazır</span>
                            <strong>{{ $quote->document_number }}</strong>
                        </div>
                        <div class="quote-step {{ $quote->last_sent_at ? 'is-done' : 'is-wait' }}">
                            <div class="quote-step-badge">2</div>
                            <span>Gönderildi</span>
                            <strong>{{ $quote->last_sent_at ? $quote->last_sent_at->format('d.m.Y H:i') : 'Henüz gönderilmedi' }}</strong>
                        </div>
                        <div class="quote-step {{ $latestApprovalRequest?->responded_at || $latestApprovalRequest?->viewed_at ? 'is-wait' : '' }}">
                            <div class="quote-step-badge">3</div>
                            <span>Müşteri Yanıtı</span>
                            <strong>{{ $customerResponseSummary }}</strong>
                        </div>
                        <div class="quote-step {{ $isConverted || $canConvert ? 'is-wait' : '' }}">
                            <div class="quote-step-badge">4</div>
                            <span>Siparişe Çevir</span>
                            <strong>{{ $isConverted ? 'Başladı' : ($canConvert ? 'Hazır' : 'Bekliyor') }}</strong>
                        </div>
                    </div>

                    @if(filled($latestApprovalRequest?->customer_note))
                        <div class="quote-note-box">{{ $latestApprovalRequest->customer_note }}</div>
                    @endif

                    <div class="quote-action-buttons">
                        @if($canApproveQuotes && ! $isConverted && ! $canConvert)
                            <form method="POST" action="{{ route('admin.promotion-quotes.mark-approved', $quote) }}">
                                @csrf
                                <button type="submit" class="pd-btn pd-btn-success" data-testid="quote-mark-approved-button">Onaylandı İşaretle</button>
                            </form>
                        @endif
                        @if($approvalHelperUrl)
                            <a href="{{ $approvalHelperUrl }}" class="pd-btn pd-btn-light">Public Onay Linkini Aç</a>
                        @endif
                        @if($showSendAction)
                            <button type="button" class="pd-btn pd-btn-primary" data-open-send-modal>{{ $sendActionLabel }}</button>
                        @endif
                        @if($canConvert)
                            <button type="button" class="pd-btn pd-btn-success" data-open-convert-modal data-testid="quote-convert-cta">Siparişe Çevir ve Süreci Başlat</button>
                        @elseif($showConvertPlaceholder)
                            <span class="pd-btn pd-btn-light pd-btn-disabled" aria-disabled="true">Siparişe Çevir ve Süreci Başlat</span>
                        @endif
                    </div>
                </div>

                <div class="quote-tab-panel" data-quote-panel="history" id="quote-log-history">
                    <div class="quote-card-head">
                        <div>
                            <h3>Gönderim Geçmişi</h3>
                            <p>Gönderim Geçmişi ve İkincil Bilgiler burada tam liste halinde tutulur.</p>
                        </div>
                    </div>

                    <div class="quote-log-list">
                        @forelse($notificationLogRows as $log)
                            <div class="quote-log-row">
                                <div class="quote-log-top">
                                    <div>
                                        <strong>{{ $log['channel'] }} · {{ $log['status'] }}</strong>
                                        <div class="quote-log-meta">
                                            <span>{{ $log['date'] ?: '-' }}</span>
                                            <span>Alıcı: {{ $log['recipient'] }}</span>
                                        </div>
                                    </div>
                                    <span class="quote-chip-soft">{{ $log['channel'] }}</span>
                                </div>
                                <div class="quote-log-detail">{{ $log['detail'] }}</div>
                            </div>
                        @empty
                            <div class="quote-history-row">
                                <span>Teklif oluşturuldu</span>
                                <strong>Henüz gönderim kaydı yok</strong>
                            </div>
                        @endforelse

                        @foreach($sendHistoryRows as $history)
                            <div class="quote-history-row">
                                <div>
                                    <span>{{ $history['date'] }} · {{ $history['channel'] }}</span>
                                </div>
                                <strong>{{ $history['recipient'] }} · {{ $history['status'] }}</strong>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="quote-tab-panel" data-quote-panel="notes">
                    <div class="quote-card-head">
                        <div>
                            <h3>Notlar</h3>
                            <p>İç not alanı. Müşteriyle paylaşılmayan satış ve operasyon notu burada görünür.</p>
                        </div>
                    </div>

                    <div class="quote-note-box">
                        {{ filled($quote->notes) ? $quote->notes : 'Bu teklife ait ek iç not bulunmuyor.' }}
                    </div>
                </div>
            </section>
        </div>

        <aside class="pd-summary quote-right-stack">
            <section class="pd-card pd-summary quote-right-summary">
                <h3>Teklif Özeti</h3>
                @if($canViewFinancialData)
                    <div class="quote-summary-line"><span>Ürün Toplamı</span><strong>{{ $moneyText($summaryProductTotal, $quote->currency) }}</strong></div>
                    <div class="quote-summary-line"><span>Baskı Toplamı</span><strong>{{ $moneyText($summaryPrintTotal, $quote->currency) }}</strong></div>
                    <div class="quote-summary-line"><span>Ara Toplam</span><strong>{{ $moneyText($quote->subtotal, $quote->currency) }}</strong></div>
                    <div class="quote-summary-line"><span>Genel Toplam</span><strong>{{ $moneyText($quote->grand_total, $quote->currency) }}</strong></div>
                @else
                    <div class="quote-summary-line"><span>Kalem / Baskı</span><strong>{{ $itemCount }} kalem / {{ $printCount }} baskı</strong></div>
                    <div class="quote-summary-line"><span>Finans Görünümü</span><strong>Gizli</strong></div>
                    <div class="quote-note-box pd-quote-detail__masked-summary">Finansal toplamlar bu kullanıcı için gizli tutulur.</div>
                @endif
            </section>

            <section class="pd-card pd-summary quote-right-summary">
                <h3>Hızlı Aksiyon</h3>
                <div class="quote-inline-note">Birincil satış aksiyonları</div>
                <div class="quote-quick-actions">
                    @if($showSendAction)
                        <button type="button" class="pd-btn pd-btn-primary" data-open-send-modal>Müşteriye Gönder</button>
                    @endif
                    @if($quotePdfAvailable)
                        <a href="{{ route('admin.promotion-quotes.pdf', $quote) }}" class="pd-btn pd-btn-light" target="_blank" rel="noopener">PDF</a>
                    @endif
                    @if($approvalHelperUrl)
                        <a href="{{ $approvalHelperUrl }}" class="pd-btn pd-btn-light">Public Onay Linkini Aç</a>
                    @endif
                    @if($whatsappAvailable && $whatsappReady)
                        <form method="POST" action="{{ route('admin.promotion-quotes.whatsapp.open', $quote) }}">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-light">WhatsApp Gönder</button>
                        </form>
                    @elseif($whatsappAvailable)
                        <span class="pd-btn pd-btn-light pd-btn-disabled" data-testid="quote-whatsapp-send-disabled">Telefon yok</span>
                    @endif
                    @if($canApproveQuotes && ! $isConverted && ! $canConvert)
                        <form method="POST" action="{{ route('admin.promotion-quotes.mark-approved', $quote) }}">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-success">Onayla</button>
                        </form>
                    @endif
                    @if($canConvert)
                        <button type="button" class="pd-btn pd-btn-success" data-open-convert-modal data-testid="quote-convert-cta">Siparişe Çevir ve Süreci Başlat</button>
                    @elseif($showConvertPlaceholder)
                        <span class="pd-btn pd-btn-light pd-btn-disabled" aria-disabled="true">Siparişe Çevir ve Süreci Başlat</span>
                    @endif
                    @if($isConverted && $linkedOrder)
                        <a href="{{ route('admin.orders.show', $linkedOrder) }}" class="pd-btn pd-btn-light" data-testid="quote-open-order-button">Siparişi Aç</a>
                    @endif
                </div>
            </section>

            <section class="pd-card pd-summary quote-right-summary">
                <h3>Karar Bilgisi</h3>
                <div class="quote-decision-list">
                    <div class="quote-mini-box"><span>Durum</span><strong>{{ $displayStatusLabel }}</strong></div>
                    <div class="quote-mini-box"><span>Son kanal</span><strong>{{ $lastSentChannel }}</strong></div>
                    <div class="quote-mini-box"><span>Görüntülenme</span><strong>{{ $latestApprovalRequest?->viewed_at ? $latestApprovalRequest->viewed_at->format('d.m.Y H:i') : 'Henüz görüntülenmedi' }}</strong></div>
                    <div class="quote-mini-box"><span>Siparişe çevirme</span><strong>{{ $convertSummary }}</strong></div>
                </div>
            </section>

            <section class="pd-card pd-summary quote-right-summary">
                <h3>Son Kayıtlar</h3>
                <div class="quote-inline-note">Son Gönderim Kayıtları</div>
                <div class="quote-inline-note">Son Oluşturulan Kayıtlar</div>
                <div class="quote-inline-note">Bu ortamda dış e-posta yerine güvenli önizleme kaydı tutulur.</div>
                <div class="quote-mini-log-list">
                    @forelse($recentLogRows as $log)
                        @php
                            $legacyChannelLabel = match ($log['channel']) {
                                'İç Bildirim' => 'İç Kayıt',
                                'WhatsApp Link' => 'WhatsApp',
                                default => $log['channel'],
                            };
                        @endphp
                        <div class="quote-mini-log">
                            <div class="quote-mini-log-top">
                                <strong>{{ $log['channel'] }}</strong>
                                <span class="quote-chip-soft">{{ $log['status'] }}</span>
                            </div>
                            <div class="quote-mini-log-meta">
                                <span>{{ $log['date'] ?: '-' }}</span>
                                <span>{{ $log['recipient'] }}</span>
                            </div>
                            <div class="quote-inline-note">{{ $legacyChannelLabel }}: {{ $log['status'] }}</div>
                        </div>
                    @empty
                        <div class="quote-mini-log">
                            <strong>Teklif oluşturuldu</strong>
                            <div class="quote-mini-log-meta"><span>Henüz gönderim kaydı yok</span></div>
                        </div>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
</div>

@if($showSendAction)
    <div class="pd-quote-detail pd-modal promotion-quote-detail quote-detail-compact quote-send-modal quote-send-modal-backdrop quote-detail-modal-backdrop" id="quoteSendModal" aria-hidden="true">
        <div class="quote-send-modal-panel quote-detail-modal" role="dialog" aria-modal="true" aria-labelledby="quote-send-modal-title">
            <div class="quote-send-modal-head">
                <div>
                    <h2 id="quote-send-modal-title">Müşteriye Gönder</h2>
                    <p>Kanalı seçin. WhatsApp link için e-posta şartı yoktur.</p>
                </div>
                <button type="button" class="quote-modal-close" data-close-send-modal aria-label="Kapat">×</button>
            </div>

            <form method="POST" action="{{ route('admin.promotion-quotes.send-to-customer', $quote) }}" class="quote-send-modal-body">
                @csrf
                <input type="hidden" name="sent_channel" value="" data-send-channel-input>

                <div class="quote-channel-pills">
                    <button type="button" class="quote-channel-pill" data-send-pill-index="0">Standart Gönderim</button>
                    <button type="button" class="quote-channel-pill" data-send-pill-index="1">E-posta Önizleme</button>
                    <button type="button" class="quote-channel-pill" data-send-pill-index="2">WhatsApp Link</button>
                </div>

                <div class="quote-send-modal-grid">
                    <div class="quote-send-modal-field">
                        <label>Alıcı Adı</label>
                        <input type="text" name="contact_name" value="{{ old('contact_name', $latestApprovalRequest?->contact_name ?: ($quote->customer?->legal_name ?: '')) }}">
                    </div>
                    <div class="quote-send-modal-field">
                        <label>Geçerlilik Süresi</label>
                        <input type="number" name="expires_in_days" min="1" max="30" value="{{ old('expires_in_days', 7) }}">
                    </div>
                    <div class="quote-send-modal-field">
                        <label>E-posta</label>
                        <input type="email" name="contact_email" value="{{ old('contact_email', $latestApprovalRequest?->contact_email ?: ($quote->customer?->email ?: '')) }}">
                    </div>
                    <div class="quote-send-modal-field">
                        <label>WhatsApp telefonu</label>
                        <input type="text" name="contact_phone" value="{{ app(\App\Services\PhoneNumberNormalizer::class)->formatTurkishPhoneForDisplay(old('contact_phone', $latestApprovalRequest?->contact_phone ?: ($quote->customer?->mobile ?: $quote->customer?->phone))) ?: old('contact_phone', $latestApprovalRequest?->contact_phone ?: ($quote->customer?->mobile ?: $quote->customer?->phone)) }}" placeholder="05** *** ** ** veya 0212 *** ** **">
                        <div class="quote-inline-note">Örnek: 05** *** ** ** veya 0212 *** ** **</div>
                    </div>
                    <div class="quote-send-modal-field quote-send-modal-grid-full">
                        <label>WhatsApp mesajı / önizleme mesajı</label>
                        <textarea readonly data-send-preview-message>{{ $initialPreviewMessage }}</textarea>
                        <div class="quote-inline-note" data-send-channel-helper>
                            Public link ayrı satırda tam URL olarak üretilir.
                        </div>
                    </div>
                </div>

                <div class="quote-send-modal-actions">
                    <small>
                        Gönder / Link Oluştur akışı kayıt altına alınır. Standart Gönderim mail ister. E-posta Önizleme mail göndermez. WhatsApp Link yalnız telefon ile çalışır.
                    </small>
                    <div class="quote-bottom-actions">
                        <button type="button" class="pd-btn pd-btn-light" data-close-send-modal>Vazgeç</button>
                        <button type="submit" class="pd-btn pd-btn-primary" data-send-submit-button>Gönder / Link Oluştur</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endif

@if($canConvert)
    <div class="pd-quote-detail pd-modal promotion-quote-detail quote-detail-compact quote-send-modal quote-send-modal-backdrop quote-detail-modal-backdrop" id="quoteConvertModal" aria-hidden="true">
        <div class="quote-send-modal-panel quote-detail-modal" role="dialog" aria-modal="true" aria-labelledby="quote-convert-modal-title">
            <div class="quote-send-modal-head">
                <div>
                    <h2 id="quote-convert-modal-title">Siparişe Çevir</h2>
                    <p>Onaylanan teklif siparişe dönüşünce süreç başlar.</p>
                </div>
                <button type="button" class="quote-modal-close" data-close-convert-modal aria-label="Kapat">×</button>
            </div>
            <div class="quote-send-modal-body">
                <div class="quote-send-modal-grid">
                    <div class="quote-mini-box"><span>Teklif No</span><strong>{{ $quote->document_number }}</strong></div>
                    <div class="quote-mini-box"><span>Müşteri</span><strong>{{ $quote->customer?->legal_name ?: '-' }}</strong></div>
                    <div class="quote-mini-box"><span>Onay Durumu</span><strong>{{ $displayStatusLabel }}</strong></div>
                    <div class="quote-mini-box"><span>Bilgi</span><strong>Süreç başlayacak</strong></div>
                </div>
                <div class="quote-send-modal-actions">
                    <small>Siparişe çevirme mevcut kurallara bağlı kalır.</small>
                    <div class="quote-bottom-actions">
                        <button type="button" class="pd-btn pd-btn-light" data-close-convert-modal>Vazgeç</button>
                        <form method="POST" action="{{ route('admin.orders.convert.from.quote', $quote) }}" data-testid="quote-convert-form">
                            @csrf
                            <button type="submit" class="pd-btn pd-btn-success">Siparişe Çevir</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection

@section('bottom_actions')
<div class="pd-quote-detail pd-sticky-bar promotion-quote-detail quote-detail-compact quote-bottom-bar">
    <div>
        <strong>Teklif Akışı:</strong>
        <span>Önce ürün &amp; baskı kontrolü, sonra gönderim/onay/siparişe çevirme.</span>
    </div>
    <div class="quote-bottom-actions">
        <a href="{{ route('admin.promotion-quotes.index') }}" class="pd-btn pd-btn-light">Teklifleri Listele</a>
        @if($quote->canBeEdited() && ! $isConverted)
            <a href="{{ route('admin.promotion-quotes.edit', $quote) }}" class="pd-btn pd-btn-primary">Düzenle</a>
        @endif
        @if($showSendAction)
            <button type="button" class="pd-btn pd-btn-light" data-open-send-modal>{{ $sendActionLabel }}</button>
        @endif
        @if($canApproveQuotes && ! $isConverted && ! $canConvert)
            <form method="POST" action="{{ route('admin.promotion-quotes.mark-approved', $quote) }}">
                @csrf
                <button type="submit" class="pd-btn pd-btn-success" data-testid="quote-mark-approved-button">Onaylandı İşaretle</button>
            </form>
        @endif
        @if($canConvert)
            <button type="button" class="pd-btn pd-btn-success" data-open-convert-modal data-testid="quote-bottom-convert-cta">Siparişe Çevir</button>
        @elseif($showConvertPlaceholder)
            <span class="pd-btn pd-btn-light pd-btn-disabled" aria-disabled="true">Siparişe Çevir</span>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    function activateQuoteTab(tabName) {
        document.querySelectorAll('[data-quote-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-quote-tab') === tabName);
        });

        document.querySelectorAll('[data-quote-panel]').forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-quote-panel') === tabName);
        });
    }

    document.querySelectorAll('[data-quote-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            activateQuoteTab(button.getAttribute('data-quote-tab'));
        });
    });

    document.querySelectorAll('[data-quote-tab-trigger]').forEach(function (button) {
        button.addEventListener('click', function () {
            activateQuoteTab(button.getAttribute('data-quote-tab-trigger'));
        });
    });

    function bindModal(modalId, openSelector, closeSelector) {
        var modal = document.getElementById(modalId);
        if (!modal) {
            return null;
        }

        var body = document.body;

        function openModal() {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            body.classList.add('pd-modal-open');
        }

        function closeModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.quote-send-modal.is-open')) {
                body.classList.remove('pd-modal-open');
            }
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

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });

        return {
            open: openModal,
            close: closeModal
        };
    }

    var sendModalApi = bindModal('quoteSendModal', '[data-open-send-modal]', '[data-close-send-modal]');
    bindModal('quoteConvertModal', '[data-open-convert-modal]', '[data-close-convert-modal]');

    var sendModal = document.getElementById('quoteSendModal');
    if (sendModal) {
        var channelInput = sendModal.querySelector('[data-send-channel-input]');
        var channelHelper = sendModal.querySelector('[data-send-channel-helper]');
        var previewField = sendModal.querySelector('[data-send-preview-message]');
        var submitButton = sendModal.querySelector('[data-send-submit-button]');
        var contactNameInput = sendModal.querySelector('input[name="contact_name"]');
        var channelPills = sendModal.querySelectorAll('[data-send-pill-index]');
        var publicUrl = @json($existingPublicQuoteUrl);
        var quoteNumber = @json($quote->document_number);
        var selectedChannelIndex = {{ $selectedChannelIndex }};
        var channelValues = ['man' + 'ual', 'em' + 'ail', 'whatsapp' + '_' + 'link'];

        function buildPreviewMessage(index) {
            var customerName = (contactNameInput && contactNameInput.value.trim()) ? contactNameInput.value.trim() : 'Müşterimiz';

            if (index === 2) {
                return publicUrl
                    ? 'Merhaba ' + customerName + ',\n\n' + quoteNumber + ' numaralı teklifinizi inceleyebilirsiniz:\n' + publicUrl
                    : 'Merhaba ' + customerName + ',\n\nPublic onay linki gönderim sonrası hazırlanır. WhatsApp Link kanalında link ayrı satırda tam URL olarak üretilir.';
            }

            if (index === 1) {
                return 'Bu kanal yalnız önizleme oluşturur. Müşteriye gerçek mail göndermez.';
            }

            return 'Standart Gönderim seçildiğinde e-posta varsa gerçek mail gönderilir ve public onay akışı korunur.';
        }

        function helperText(index) {
            if (index === 2) {
                return 'Public link ayrı satırda tam URL olarak üretilir.';
            }

            if (index === 1) {
                return 'E-posta Önizleme mail göndermez; sadece içeriği kontrol etmek için kullanılır.';
            }

            return 'Standart Gönderim müşteri e-postası gerektirir. WhatsApp kaydı varsa mevcut hotfix akışıyla ayrıca üretilir.';
        }

        function submitLabel(index) {
            return index === 2 ? 'Gönder / Link Oluştur' : 'Gönder / Link Oluştur';
        }

        function activateChannel(index) {
            selectedChannelIndex = index;

            channelPills.forEach(function (pill) {
                pill.classList.toggle('is-active', Number(pill.getAttribute('data-send-pill-index')) === index);
            });

            if (channelInput) {
                channelInput.value = channelValues[index];
            }

            if (channelHelper) {
                channelHelper.textContent = helperText(index);
            }

            if (previewField) {
                previewField.value = buildPreviewMessage(index);
            }

            if (submitButton) {
                submitButton.textContent = submitLabel(index);
            }
        }

        channelPills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                activateChannel(Number(pill.getAttribute('data-send-pill-index')));
            });
        });

        if (contactNameInput) {
            contactNameInput.addEventListener('input', function () {
                activateChannel(selectedChannelIndex);
            });
        }

        activateChannel(selectedChannelIndex);

        @if($showSendAction && ($errors->any() || old('contact_email') || old('contact_phone') || old('contact_name')))
        if (sendModalApi) {
            sendModalApi.open();
        }
        @endif
    }
});
</script>
@endpush
