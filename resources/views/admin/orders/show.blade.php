@extends('layouts.prodelya-admin')

@section('title', 'Sipariş Detayı')
@section('page_title', 'Sipariş Detayı')
@section('page_subtitle', $order->document_number . ' - ' . ($order->customer?->legal_name ?: 'Müşteri bilgisi yok'))

@section('content')
@php
    $badgeClass = static function (?string $color): string {
        return 'pd-badge-' . ($color ?: 'gray');
    };

    $moduleMap = collect($moduleCards)->keyBy('title');
    $workForm = $order->workForms->first();
    $trackingUrl = ($workForm && filled($workForm->public_tracking_token))
        ? route('admin.orders.tracking.open', ['order' => $order->id, 'workForm' => $workForm->id])
        : null;
    $workFormPdfUrl = $workForm ? route('admin.work-forms.pdf', $workForm) : null;
    $sourceQuoteUrl = $order->sourceQuote ? route('admin.promotion-quotes.show', $order->sourceQuote) : null;

    $graphicStatus = data_get($overview, 'sticky_panel.module_statuses.graphic.label', data_get($moduleMap->get('Grafik'), 'status', 'Bekliyor'));
    $procurementStatus = data_get($overview, 'sticky_panel.module_statuses.procurement.label', data_get($moduleMap->get('Tedarik'), 'status', 'Bekliyor'));
    $productionStatus = data_get($overview, 'sticky_panel.module_statuses.production.label', data_get($moduleMap->get('Üretim'), 'status', 'Bekliyor'));
    $deliveryStatus = data_get($overview, 'sticky_panel.module_statuses.delivery.label', data_get($moduleMap->get('Teslimat'), 'status', 'Bekliyor'));
    $financeStatus = data_get($overview, 'sticky_panel.module_statuses.finance.label', $financialDataVisible ? 'İncele' : 'Yetkili kullanıcı');

    $flowSteps = [
        [
            'title' => 'Teklif',
            'label' => $overview['source_quote_number'],
            'copy' => 'Kaynak teklif kaydı bağlı.',
            'badge' => 'blue',
            'url' => $sourceQuoteUrl,
            'cta' => 'Teklifi Aç',
        ],
        [
            'title' => 'İş Formu',
            'label' => $workForm?->work_form_number ?: 'Hazırlanacak',
            'copy' => $workForm ? 'İş formu üzerinden operasyon takip edilir.' : 'İş formu kaydı henüz oluşmadı.',
            'badge' => $workForm ? 'blue' : 'gray',
            'url' => $overview['links']['work_form'] ?? null,
            'cta' => 'İş Formu',
        ],
        [
            'title' => 'Grafik',
            'label' => $graphicStatus,
            'copy' => match ($graphicStatus) {
                'Hazır' => 'Grafik tarafı hazır görünüyor.',
                'Bekliyor' => 'Grafik veya müşteri onayı kontrol edilmeli.',
                default => 'Grafik süreci izleniyor.',
            },
            'badge' => data_get($overview, 'sticky_panel.module_statuses.graphic.badge', data_get($moduleMap->get('Grafik'), 'badge', 'gray')),
            'url' => $overview['links']['graphic'] ?? null,
            'cta' => 'Grafik',
        ],
        [
            'title' => 'Tedarik',
            'label' => $procurementStatus,
            'copy' => in_array($procurementStatus, ['Hazır', 'Tamamlandı'], true)
                ? 'Tedarik tamamlandı veya gerekmiyor.'
                : 'Tedarik adımı takip ediliyor.',
            'badge' => data_get($overview, 'sticky_panel.module_statuses.procurement.badge', data_get($moduleMap->get('Tedarik'), 'badge', 'gray')),
            'url' => $overview['links']['procurement'] ?? null,
            'cta' => 'Tedarik',
        ],
        [
            'title' => 'Üretim',
            'label' => $productionStatus,
            'copy' => in_array($productionStatus, ['Tamamlandı', 'Hazır'], true)
                ? 'Üretim tamamlandı.'
                : 'Üretim akışı izleniyor.',
            'badge' => data_get($overview, 'sticky_panel.module_statuses.production.badge', data_get($moduleMap->get('Üretim'), 'badge', 'gray')),
            'url' => $overview['links']['production'] ?? null,
            'cta' => 'Üretim',
        ],
        [
            'title' => 'Teslimat',
            'label' => $deliveryStatus,
            'copy' => in_array($deliveryStatus, ['Teslim Edildi'], true)
                ? 'Teslimat tamamlandı.'
                : 'Teslimat planı ve saha durumu izleniyor.',
            'badge' => data_get($overview, 'sticky_panel.module_statuses.delivery.badge', data_get($moduleMap->get('Teslimat'), 'badge', 'gray')),
            'url' => $overview['links']['delivery'] ?? null,
            'cta' => 'Teslimat',
        ],
        [
            'title' => 'Finans',
            'label' => $financeStatus,
            'copy' => $financialDataVisible
                ? 'Finans özeti yetkili kullanıcılar için açılır.'
                : 'Finans bilgileri yalnız yetkili kullanıcıya gösterilir.',
            'badge' => $financialDataVisible ? ($overview['payment_status_badge'] ?: 'gray') : 'gray',
            'url' => $financialDataVisible ? ($overview['links']['finance'] ?? null) : null,
            'cta' => 'Finans',
        ],
    ];

    $nextAction = $overview['next_action_label'];
    $nextActionDetail = match ($nextAction) {
        'Grafik kontrol et' => [
            'title' => 'Grafik görseli bekleniyor.',
            'detail' => 'Grafik veya müşteri onayı tamamlanmadan sonraki operasyonlara geçilmemeli.',
            'primary_url' => $overview['links']['graphic'] ?? null,
            'primary_label' => 'Grafiğe Git',
        ],
        'Tedarik bekliyor' => [
            'title' => 'Tedarik talebi hazırlanmalı.',
            'detail' => 'Tedarik tamamlanmadan üretim başlatılamaz.',
            'primary_url' => $overview['links']['procurement'] ?? null,
            'primary_label' => 'Tedarik Aç',
        ],
        'Üretim bekliyor' => [
            'title' => 'Üretim başlayabilir.',
            'detail' => 'Grafik ve tedarik tarafını doğrulayıp üretim ekranına geçin.',
            'primary_url' => $overview['links']['production'] ?? null,
            'primary_label' => 'Üretime Git',
        ],
        'Teslimat planla' => [
            'title' => 'Teslimat bekliyor.',
            'detail' => 'Üretim tamamlandıysa teslimat planını netleştirin ve saha hazırlığını başlatın.',
            'primary_url' => $overview['links']['delivery'] ?? null,
            'primary_label' => 'Teslimata Git',
        ],
        'Tahsilat bekliyor' => [
            'title' => 'Tahsilat kontrolü gerekiyor.',
            'detail' => 'Finans tarafında bakiye ve tahsilat durumunu yalnız yetkili kullanıcılar incelemeli.',
            'primary_url' => $financialDataVisible ? ($overview['links']['finance'] ?? null) : null,
            'primary_label' => 'Finans Aç',
        ],
        'Sipariş tamamlandı' => [
            'title' => 'Sipariş tamamlandı.',
            'detail' => 'Teslimat ve operasyon kayıtları tamamlanmış görünüyor.',
            'primary_url' => $overview['links']['delivery'] ?? null,
            'primary_label' => 'Teslimat Kaydı',
        ],
        default => [
            'title' => 'Sipariş operasyonunu kontrol edin.',
            'detail' => 'İş formu ve modül kartları üzerinden sıradaki adımı netleştirin.',
            'primary_url' => $overview['links']['work_form'] ?? null,
            'primary_label' => 'İş Formuna Git',
        ],
    };

    $secondaryLinks = collect([
        ['label' => 'Grafik', 'url' => $overview['links']['graphic'] ?? null],
        ['label' => 'Tedarik', 'url' => $overview['links']['procurement'] ?? null],
        ['label' => 'Üretim', 'url' => $overview['links']['production'] ?? null],
        ['label' => 'Teslimat', 'url' => $overview['links']['delivery'] ?? null],
    ])->filter(fn (array $link): bool => filled($link['url']))->take(3)->values();
@endphp

<style>
    .pd-order-flow-shell {
        display: grid;
        gap: 14px;
    }

    .pd-order-hero,
    .pd-order-flow-card,
    .pd-order-next-card,
    .pd-order-workform-card,
    .pd-order-summary-card,
    .pd-order-item-card,
    .pd-order-finance-card {
        border: 1px solid var(--pd-line);
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }

    .pd-order-hero,
    .pd-order-flow-card,
    .pd-order-next-card,
    .pd-order-workform-card,
    .pd-order-summary-card {
        padding: 14px;
    }

    .pd-order-hero {
        display: grid;
        gap: 14px;
        grid-template-columns: minmax(0, 1fr);
    }

    .pd-order-eyebrow,
    .pd-order-copy,
    .pd-order-mini-label,
    .pd-order-item-note,
    .pd-order-step-copy {
        color: var(--pd-muted);
        font-size: 12px;
        line-height: 1.5;
    }

    .pd-order-eyebrow {
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
    }

    .pd-order-hero-title {
        margin: 4px 0 0;
        color: var(--pd-text);
        font-size: 26px;
        line-height: 1.25;
        font-weight: 700;
        letter-spacing: -0.04em;
    }

    .pd-order-hero-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
    }

    .pd-order-hero-meta,
    .pd-order-hero-links,
    .pd-order-next-links,
    .pd-order-workform-links {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .pd-order-hero-grid,
    .pd-order-workform-grid,
    .pd-order-finance-grid,
    .pd-order-item-meta,
    .pd-order-print-grid {
        display: grid;
        gap: 8px;
    }

    .pd-order-hero-grid {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }

    .pd-order-mini-card {
        border: 1px solid var(--pd-line);
        border-radius: 8px;
        background: #f8fafc;
        padding: 10px;
    }

    .pd-order-mini-value,
    .pd-order-item-title,
    .pd-order-step-value {
        color: var(--pd-text);
        font-weight: 600;
    }

    .pd-order-mini-value {
        margin-top: 4px;
        font-size: 13px;
        line-height: 1.45;
    }

    .pd-order-flow-grid,
    .pd-order-mini-grid,
    .pd-order-items-grid {
        display: grid;
        gap: 10px;
    }

    .pd-order-flow-grid {
        grid-template-columns: repeat(7, minmax(0, 1fr));
    }

    .pd-order-step {
        border: 1px solid #e4e8ef;
        border-radius: 8px;
        background: #fff;
        padding: 10px;
        min-height: 94px;
        display: grid;
        gap: 6px;
        align-content: start;
        position: relative;
        overflow: hidden;
    }

    .pd-order-step::before {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background: #22c55e;
    }

    .pd-order-step-top,
    .pd-order-item-top {
        display: flex;
        align-items: start;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
    }

    .pd-order-step-title {
        color: var(--pd-text);
        font-size: 12px;
        font-weight: 700;
    }

    .pd-order-step-value {
        font-size: 13px;
    }

    .pd-order-step-label {
        color: var(--pd-muted);
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .pd-order-main-grid {
        display: grid;
        gap: 14px;
        grid-template-columns: minmax(0, 1.75fr) minmax(280px, 1fr);
    }

    .pd-order-mini-grid {
        grid-template-columns: 1.15fr .85fr;
    }

    .pd-order-panel-head {
        display: flex;
        align-items: start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .pd-order-panel-title {
        color: var(--pd-text);
        font-size: 16px;
        font-weight: 700;
    }

    .pd-order-panel-subtitle {
        color: var(--pd-muted);
        font-size: 12px;
        margin-top: 2px;
    }

    .pd-order-next-card {
        padding: 14px;
    }

    .pd-order-next-title {
        color: var(--pd-text);
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .pd-order-next-box {
        display: flex;
        align-items: start;
        justify-content: space-between;
        gap: 12px;
        padding: 12px;
        border: 1px solid #f1d39a;
        background: #fff8e8;
        border-radius: 8px;
    }

    .pd-order-next-links {
        margin-top: 10px;
    }

    .pd-order-workform-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .pd-order-items-grid {
        grid-template-columns: 1fr;
    }

    .pd-order-item-card {
        padding: 14px;
        display: grid;
        gap: 10px;
    }

    .pd-order-item-meta,
    .pd-order-print-grid,
    .pd-order-finance-grid {
        gap: 8px;
    }

    .pd-order-item-meta {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }

    .pd-order-print-grid {
        grid-template-columns: 1fr;
    }

    .pd-order-item-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--pd-line);
    }

    .pd-order-item-index {
        width: 24px;
        height: 24px;
        border-radius: 5px;
        display: grid;
        place-items: center;
        background: #eef4ff;
        color: #2f6fed;
        font-size: 12px;
        font-weight: 700;
        flex: 0 0 auto;
    }

    .pd-order-item-title-wrap {
        display: flex;
        gap: 10px;
        align-items: flex-start;
    }

    .pd-order-item-actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .pd-order-print-row {
        display: grid;
        grid-template-columns: minmax(220px, 1.6fr) minmax(70px, .5fr) minmax(90px, .6fr) minmax(90px, .6fr) minmax(110px, .7fr) auto;
        gap: 8px;
        align-items: center;
        border: 1px solid var(--pd-line);
        border-radius: 8px;
        padding: 10px;
        background: #fdfefe;
    }

    .pd-order-print-title b {
        display: block;
        font-size: 12px;
        color: var(--pd-text);
    }

    .pd-order-print-title span {
        display: block;
        font-size: 10.5px;
        color: var(--pd-muted);
        margin-top: 2px;
        line-height: 1.45;
    }

    .pd-order-print-cell small {
        display: block;
        font-size: 10px;
        color: var(--pd-muted);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .pd-order-print-cell b {
        display: block;
        margin-top: 3px;
        font-size: 12px;
        color: var(--pd-text);
    }

    .pd-order-print-details {
        border-top: 1px dashed #d8e0eb;
        padding-top: 10px;
    }

    .pd-order-print-summary {
        cursor: pointer;
        list-style: none;
        font-weight: 600;
        color: var(--pd-text);
    }

    .pd-order-print-summary::-webkit-details-marker {
        display: none;
    }

    .pd-order-side-stack {
        display: grid;
        gap: 14px;
        align-content: start;
    }

    .pd-order-module-list {
        display: grid;
        gap: 8px;
    }

    .pd-order-module-row {
        border: 1px solid var(--pd-line);
        border-radius: 8px;
        padding: 9px 10px;
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: center;
        background: #fff;
    }

    .pd-order-module-left b {
        display: block;
        font-size: 12px;
    }

    .pd-order-module-left span {
        display: block;
        font-size: 10.5px;
        color: var(--pd-muted);
        margin-top: 2px;
    }

    @media (max-width: 1440px) {
        .pd-order-flow-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 1180px) {
        .pd-order-hero,
        .pd-order-main-grid {
            grid-template-columns: 1fr;
        }

        .pd-order-hero-top {
            flex-direction: column;
        }

        .pd-order-mini-grid,
        .pd-order-hero-grid,
        .pd-order-items-grid,
        .pd-order-item-meta,
        .pd-order-finance-grid,
        .pd-order-workform-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .pd-order-print-row {
            grid-template-columns: 1.2fr .8fr .8fr .8fr;
        }
    }

    @media (max-width: 768px) {
        .pd-order-hero-grid,
        .pd-order-mini-grid,
        .pd-order-flow-grid,
        .pd-order-items-grid,
        .pd-order-item-meta,
        .pd-order-finance-grid,
        .pd-order-workform-grid {
            grid-template-columns: 1fr;
        }

        .pd-order-step {
            min-height: 0;
        }

        .pd-order-item-header,
        .pd-order-item-top {
            display: block;
        }

        .pd-order-hero-links {
            justify-content: flex-start;
        }

        .pd-order-item-actions {
            margin-top: 10px;
            justify-content: flex-start;
        }

        .pd-order-print-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="pd-order-flow-shell">
    <section class="pd-order-hero">
        <div class="pd-order-hero-top">
            <div>
                <div class="pd-order-eyebrow">Sipariş Özeti</div>
                <h2 class="pd-order-hero-title">{{ $order->document_number }}</h2>
                <p class="pd-order-copy mt-2">
                    {{ $overview['customer_name'] }} · Kaynak teklif: {{ $overview['source_quote_number'] }}
                </p>

                <div class="pd-order-hero-meta mt-3">
                    <span class="pd-order-step-label">Genel Durum</span>
                    <span class="pd-badge {{ $badgeClass($overview['general_status_badge']) }}">{{ $overview['general_status_label'] }}</span>
                    <span class="pd-order-step-label">Operasyon Durumu</span>
                    <span class="pd-badge {{ $badgeClass($overview['operation_status_badge']) }}">{{ $overview['operation_status_label'] }}</span>
                    @if($financialDataVisible && filled($overview['payment_status_label']))
                        <span class="pd-order-step-label">Ödeme Durumu</span>
                        <span class="pd-badge {{ $badgeClass($overview['payment_status_badge']) }}">{{ $overview['payment_status_label'] }}</span>
                    @endif
                    @if($deliveryStatus === 'Teslim Edildi')
                        <span class="pd-badge pd-badge-blue">Teslim Edildi</span>
                    @endif
                </div>
            </div>

            <div class="pd-order-hero-links">
                @if($overview['links']['work_form'] ?? null)
                    <a href="{{ $overview['links']['work_form'] }}" class="pd-btn pd-btn-primary pd-btn-sm">İş Formu</a>
                @endif
                @if($overview['links']['graphic'] ?? null)
                    <a href="{{ $overview['links']['graphic'] }}" class="pd-btn pd-btn-light pd-btn-sm">Grafik</a>
                @endif
                @if($overview['links']['procurement'] ?? null)
                    <a href="{{ $overview['links']['procurement'] }}" class="pd-btn pd-btn-light pd-btn-sm">Tedarik</a>
                @endif
                @if($overview['links']['production'] ?? null)
                    <a href="{{ $overview['links']['production'] }}" class="pd-btn pd-btn-light pd-btn-sm">Üretim</a>
                @endif
                @if($overview['links']['delivery'] ?? null)
                    <a href="{{ $overview['links']['delivery'] }}" class="pd-btn pd-btn-light pd-btn-sm">Teslimat</a>
                @endif
                @if($financialDataVisible && ($overview['links']['finance'] ?? null))
                    <a href="{{ $overview['links']['finance'] }}" class="pd-btn pd-btn-light pd-btn-sm">Finans</a>
                @endif
            </div>
        </div>

        <div class="pd-order-hero-grid">
            <div class="pd-order-mini-card">
                <div class="pd-order-mini-label">Müşteri</div>
                <div class="pd-order-mini-value">{{ $overview['customer_name'] }}</div>
                <div class="pd-order-copy">Cari kart bağlantılı</div>
            </div>
            <div class="pd-order-mini-card">
                <div class="pd-order-mini-label">Sıradaki İş</div>
                <div class="pd-order-mini-value">{{ $overview['next_action_label'] }}</div>
                <div class="pd-order-copy">{{ $financialDataVisible ? 'Yetkili kullanıcı' : 'Operasyon takibi' }}</div>
            </div>
            <div class="pd-order-mini-card">
                <div class="pd-order-mini-label">İş Formu</div>
                <div class="pd-order-mini-value">{{ $workForm?->work_form_number ?: '-' }}</div>
                <div class="pd-order-copy">Takip {{ $trackingUrl ? 'hazır' : 'henüz hazır değil' }}</div>
            </div>
            <div class="pd-order-mini-card">
                <div class="pd-order-mini-label">Teslim Tarihi</div>
                <div class="pd-order-mini-value">{{ $overview['delivery_date_label'] }}</div>
                <div class="pd-order-copy">Plan {{ $overview['delivery_date_label'] !== '-' ? 'tamamlandı' : 'bekleniyor' }}</div>
            </div>
            <div class="pd-order-mini-card">
                <div class="pd-order-mini-label">Müşteri Takip Ekranı</div>
                <div class="pd-order-mini-value">
                    @if($trackingUrl)
                        <a href="{{ $trackingUrl }}" class="pd-link" target="_blank" rel="noreferrer">Müşteri Takip Ekranını Aç</a>
                    @else
                        Takip linki henüz hazır değil
                    @endif
                </div>
                <div class="pd-order-copy">Müşteri görünümü</div>
            </div>
            @if($financialDataVisible && filled($overview['payment_status_label']))
                <div class="pd-order-mini-card">
                    <div class="pd-order-mini-label">Ödeme Durumu</div>
                    <div class="pd-order-mini-value">{{ $financeSummary ? number_format((float) $financeSummary['net_paid_total'], 2, ',', '.') . ' / ' . number_format((float) $financeSummary['grand_total'], 2, ',', '.') . ' ' . $financeSummary['currency'] : $overview['payment_status_label'] }}</div>
                    <div class="pd-order-copy">Bakiye: {{ $financeSummary ? number_format((float) $financeSummary['balance_due'], 2, ',', '.') . ' ' . $financeSummary['currency'] : '-' }}</div>
                </div>
            @endif
        </div>
    </section>

    <section class="pd-order-flow-card">
        <div class="pd-order-panel-head">
            <div>
                <h2 class="pd-order-panel-title">Operasyon Akış Şeridi</h2>
                <div class="pd-order-panel-subtitle">Siparişi tek satır mantığında, daha kompakt modül geçişleriyle görün.</div>
            </div>
            <span class="pd-badge {{ $badgeClass($overview['payment_status_badge'] ?: $overview['operation_status_badge']) }}">{{ $overview['next_action_label'] }}</span>
        </div>

        <div class="pd-order-flow-grid">
            @foreach($flowSteps as $step)
                <div class="pd-order-step">
                    <div class="pd-order-step-top">
                        <div class="pd-order-step-title">{{ $step['title'] }}</div>
                        <span class="pd-badge {{ $badgeClass($step['badge']) }}">{{ $step['label'] }}</span>
                    </div>
                    <div class="pd-order-step-copy">{{ $step['copy'] }}</div>
                    @if($step['url'])
                        <div class="mt-auto">
                            <a href="{{ $step['url'] }}" class="pd-btn pd-btn-light pd-btn-sm">{{ $step['cta'] }}</a>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <div class="pd-order-main-grid">
        <div class="pd-order-side-stack">
            <div class="pd-order-mini-grid">
                <section class="pd-order-next-card">
                    <div class="pd-order-panel-head">
                        <div>
                            <h2 class="pd-order-panel-title">Sıradaki Aksiyon</h2>
                            <div class="pd-order-panel-subtitle">Kullanıcıyı yormadan tek bir ana öneri gösterilir.</div>
                        </div>
                    </div>

                    <div class="pd-order-next-box">
                        <div>
                            <div class="pd-order-next-title">{{ $nextActionDetail['title'] }}</div>
                            <p class="pd-order-copy">{{ $nextActionDetail['detail'] }}</p>

                            @if($graphicStatus === 'Hazır' && str_contains($nextActionDetail['title'], 'Üretim'))
                                <p class="pd-order-copy mt-2">Grafik hazır görünüyor. Tedarik tamamlandıysa üretim ekranından son kontrolleri yapabilirsiniz.</p>
                            @elseif($graphicStatus !== 'Hazır' && $nextActionDetail['primary_label'] === 'Grafiğe Git')
                                <p class="pd-order-copy mt-2">Müşteri onayı veya grafik hazırlığı tamamlanmadan üretim adımına geçilmemeli.</p>
                            @endif

                            <div class="pd-order-next-links">
                                @if($nextActionDetail['primary_url'])
                                    <a href="{{ $nextActionDetail['primary_url'] }}" class="pd-btn pd-btn-primary pd-btn-sm">{{ $nextActionDetail['primary_label'] }}</a>
                                @endif
                                @foreach($secondaryLinks as $link)
                                    <a href="{{ $link['url'] }}" class="pd-btn pd-btn-light pd-btn-sm">{{ $link['label'] }}</a>
                                @endforeach
                            </div>
                        </div>
                        <span class="pd-badge {{ $badgeClass($overview['operation_status_badge']) }}">{{ $overview['next_action_label'] }}</span>
                    </div>
                </section>

                <section class="pd-order-workform-card">
                    <div class="pd-order-panel-head">
                        <div>
                            <h2 class="pd-order-panel-title">İş Formu ve Müşteri Takip Ekranı</h2>
                            <div class="pd-order-panel-subtitle">PDF ve müşteri takip ekranına kısa yol.</div>
                        </div>
                    </div>

                    <div class="pd-order-workform-grid">
                        <div class="pd-order-mini-card">
                            <div class="pd-order-mini-label">İş Formu No</div>
                            <div class="pd-order-mini-value">{{ $workForm?->work_form_number ?: '-' }}</div>
                        </div>
                        <div class="pd-order-mini-card">
                            <div class="pd-order-mini-label">Takip Durumu</div>
                            <div class="pd-order-mini-value">{{ $trackingUrl ? 'Hazır' : 'Henüz yok' }}</div>
                        </div>
                    </div>

                    <div class="pd-order-workform-links mt-3">
                        @if($overview['links']['work_form'] ?? null)
                            <a href="{{ $overview['links']['work_form'] }}" class="pd-btn pd-btn-primary pd-btn-sm">İş Formu</a>
                        @endif
                        @if($workFormPdfUrl)
                            <a href="{{ $workFormPdfUrl }}" class="pd-btn pd-btn-light pd-btn-sm">İş Formu PDF</a>
                        @endif
                        @if($trackingUrl)
                            <a href="{{ $trackingUrl }}" class="pd-btn pd-btn-light pd-btn-sm" target="_blank" rel="noreferrer">Müşteri Takip Ekranı</a>
                        @endif
                    </div>
                </section>
            </div>

            <section class="pd-order-summary-card">
                <div class="pd-order-panel-head">
                    <div>
                        <h2 class="pd-order-panel-title">Kalem Özeti</h2>
                        <div class="pd-order-panel-subtitle">Ürün ve baskı detayları daha kompakt kart görünümüyle listelenir.</div>
                    </div>
                    <span class="pd-badge pd-badge-gray">{{ $itemRows->count() }} kalem</span>
                </div>

                <div class="pd-order-items-grid">
                    @foreach($itemRows as $row)
                        <div class="pd-order-item-card" data-testid="order-item-{{ $row['sequence'] }}">
                            <div class="pd-order-item-header">
                                <div class="pd-order-item-title-wrap">
                                    <div class="pd-order-item-index">{{ $row['sequence'] }}</div>
                                    <div>
                                        <div class="flex items-center gap-2 flex-wrap mb-1">
                                            <span class="pd-badge pd-badge-blue">{{ $row['product_code'] }}</span>
                                        </div>
                                        <div class="pd-order-item-title">{{ $row['product_name'] }}</div>
                                        <div class="pd-order-item-note">Kod: {{ $row['product_code'] }} · Adet: {{ $row['quantity'] }} · Operasyon durumu: {{ $row['operation_status'] }}</div>
                                    </div>
                                </div>
                                <div class="pd-order-item-actions">
                                    @if($row['work_form_url'])
                                        <a href="{{ $row['work_form_url'] }}" class="pd-btn pd-btn-light pd-btn-sm">İş Formu</a>
                                    @endif
                                    @if($overview['links']['graphic'] ?? null)
                                        <a href="{{ $overview['links']['graphic'] }}" class="pd-btn pd-btn-light pd-btn-sm">Grafik</a>
                                    @endif
                                </div>
                            </div>

                            <div class="pd-order-item-meta">
                                <div class="pd-order-mini-card">
                                    <div class="pd-order-mini-label">İş Formu</div>
                                    <div class="pd-order-mini-value">{{ $row['work_form_number'] ?: '-' }}</div>
                                </div>
                                <div class="pd-order-mini-card">
                                    <div class="pd-order-mini-label">Baskı Satırı</div>
                                    <div class="pd-order-mini-value">{{ $row['prints']->count() }}</div>
                                </div>
                                <div class="pd-order-mini-card">
                                    <div class="pd-order-mini-label">Operasyon Durumu</div>
                                    <div class="pd-order-mini-value">{{ $row['operation_status'] }}</div>
                                </div>
                                <div class="pd-order-mini-card">
                                    <div class="pd-order-mini-label">Teslim Durumu</div>
                                    <div class="pd-order-mini-value">{{ $deliveryStatus }}</div>
                                </div>
                                @if($row['show_prices'])
                                    <div class="pd-order-mini-card">
                                        <div class="pd-order-mini-label">Ürün Toplamı</div>
                                        <div class="pd-order-mini-value">{{ $row['product_total_label'] }}</div>
                                    </div>
                                    <div class="pd-order-mini-card">
                                        <div class="pd-order-mini-label">Baskı Toplamı</div>
                                        <div class="pd-order-mini-value">{{ $row['print_total_label'] }}</div>
                                    </div>
                                @endif
                            </div>

                            @if($row['prints']->isNotEmpty())
                                <details class="pd-order-print-details" open>
                                    <summary class="pd-order-print-summary">Baskı Detayları</summary>
                                    <div class="flex items-center justify-between gap-3 mt-3 mb-2 flex-wrap">
                                        <div class="pd-order-item-note">Baskı satırları daha kısa ve yatay görünümde listelenir.</div>
                                        <span class="pd-badge pd-badge-green">{{ $row['prints']->first()['code'] ?? '' }} tamamlandı</span>
                                    </div>
                                    <div class="pd-order-print-grid">
                                        @foreach($row['prints'] as $print)
                                            <div class="pd-order-print-row">
                                                <div class="pd-order-print-title">
                                                    <b>{{ $print['code'] }} — {{ $print['print_type'] ?: 'Baskı' }}</b>
                                                    <span>
                                                        @if($print['print_option'])
                                                            {{ $print['print_option'] }}
                                                        @endif
                                                        · Grafik {{ strtolower($graphicStatus) }}
                                                        · Üretim {{ strtolower($print['production_status']) }}
                                                    </span>
                                                </div>
                                                <div class="pd-order-print-cell">
                                                    <small>Adet</small>
                                                    <b>{{ $print['quantity'] }}</b>
                                                </div>
                                                @if($row['show_prices'])
                                                    <div class="pd-order-print-cell">
                                                        <small>Birim</small>
                                                        <b>{{ $print['unit_price_label'] }}</b>
                                                    </div>
                                                    <div class="pd-order-print-cell">
                                                        <small>Toplam</small>
                                                        <b>{{ $print['total_label'] }}</b>
                                                    </div>
                                                @else
                                                    <div class="pd-order-print-cell">
                                                        <small>Grafik</small>
                                                        <b>{{ $graphicStatus }}</b>
                                                    </div>
                                                    <div class="pd-order-print-cell">
                                                        <small>Teslimat</small>
                                                        <b>{{ $deliveryStatus }}</b>
                                                    </div>
                                                @endif
                                                <div class="pd-order-print-cell">
                                                    <small>Durum</small>
                                                    <b>{{ $print['production_status'] }}</b>
                                                </div>
                                                <div class="action-cell">
                                                    @if($overview['links']['production'] ?? null)
                                                        <a href="{{ $overview['links']['production'] }}" class="pd-btn pd-btn-light pd-btn-sm">Üretim</a>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        </div>

        <div class="pd-order-side-stack">
            <section class="pd-order-summary-card">
                <div class="pd-order-panel-head">
                    <div>
                        <h2 class="pd-order-panel-title">Modül Geçişleri</h2>
                        <div class="pd-order-panel-subtitle">Siparişle ilişkili ekranlara daha küçük ve sade kartlarla geçin.</div>
                    </div>
                </div>

                <div class="pd-order-module-list">
                    @foreach($moduleCards as $card)
                        <div class="pd-order-module-row">
                            <div class="pd-order-module-left">
                                <b>{{ $card['title'] }}</b>
                                <span>{{ $card['copy'] }}</span>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap justify-end">
                                <span class="pd-badge {{ $badgeClass($card['badge']) }}">{{ $card['status'] }}</span>
                                @if($card['url'])
                                    <a href="{{ $card['url'] }}" class="pd-btn pd-btn-light pd-btn-sm">Aç</a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            @if($financialDataVisible && $financeSummary)
                <section class="pd-order-summary-card">
                    <div class="pd-order-panel-head">
                        <div>
                            <h2 class="pd-order-panel-title">Finans Özeti</h2>
                            <div class="pd-order-panel-subtitle">Bu alan yalnız yetkili kullanıcılar için görünür.</div>
                        </div>
                        <span class="pd-badge {{ $badgeClass($overview['payment_status_badge']) }}">{{ $financeSummary['payment_status_label'] }}</span>
                    </div>

                    <div class="pd-order-finance-grid">
                        <div class="pd-order-mini-card">
                            <div class="pd-order-mini-label">Genel Toplam</div>
                            <div class="pd-order-mini-value">{{ number_format((float) $financeSummary['grand_total'], 2, ',', '.') }} {{ $financeSummary['currency'] }}</div>
                        </div>
                        <div class="pd-order-mini-card">
                            <div class="pd-order-mini-label">Ödenen</div>
                            <div class="pd-order-mini-value">{{ number_format((float) $financeSummary['net_paid_total'], 2, ',', '.') }} {{ $financeSummary['currency'] }}</div>
                        </div>
                        <div class="pd-order-mini-card">
                            <div class="pd-order-mini-label">Bakiye</div>
                            <div class="pd-order-mini-value">{{ number_format((float) $financeSummary['balance_due'], 2, ',', '.') }} {{ $financeSummary['currency'] }}</div>
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card">
        <div class="pd-card-body">
            <div class="pd-summary-title">Hızlı Geçiş</div>
            <div class="space-y-2">
                <a href="{{ route('admin.orders.index') }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block text-center">Sipariş Listesi</a>
                @foreach($moduleCards as $card)
                    @if($card['url'])
                        <a href="{{ $card['url'] }}" class="pd-btn pd-btn-light pd-btn-sm pd-btn-block text-center">{{ $card['title'] }}</a>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
