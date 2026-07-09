@extends('layouts.prodelya-admin')

@section('title', 'Sipariş Revizyon Karşılaştırması')
@section('hide_side_summary', '1')
@section('page_topbar_hidden', '1')

@section('content')
@php
    $badgeClass = static function (string $tone): string {
        return match ($tone) {
            'green' => 'pd-badge-green',
            'amber' => 'pd-badge-amber',
            'red' => 'pd-badge-red',
            'blue' => 'pd-badge-blue',
            default => 'pd-badge-slate',
        };
    };
@endphp

<div class="order-revision-compare" data-testid="order-revision-compare-page">
    <section class="orc-topbar">
        <div>
            <h1>Sipariş Revizyon Karşılaştırması</h1>
            <p>Revizyon kaynak siparişi değiştirmez; uygun değişiklikler Revize {{ (int) ($revisionQuote->revision_number ?: 1) }} olarak kontrollü uygulanır.</p>
        </div>
        <div class="orc-actions">
            @if($sourceOrderContext['url'])
                <a href="{{ $sourceOrderContext['url'] }}" class="pd-btn pd-btn-light">Kaynak Siparişe Dön</a>
            @endif
            <a href="{{ route('admin.promotion-quotes.show', $revisionQuote) }}" class="pd-btn pd-btn-light">Revizyon Teklifini Aç</a>
            <button
                type="button"
                class="pd-btn pd-btn-primary"
                data-open-revision-apply-modal
                @disabled(!($applySummary['button_enabled'] ?? false))
            >
                Revizyonu Uygula
            </button>
        </div>
    </section>

    <section class="orc-summary-strip">
        <div class="orc-summary-grid">
            <div class="orc-summary-item">
                <span>Kaynak Sipariş</span>
                <strong>{{ $summary['source_label'] }}</strong>
            </div>
            <div class="orc-summary-item">
                <span>Revizyon</span>
                <strong>{{ $summary['revision_label'] }}</strong>
            </div>
            <div class="orc-summary-item">
                <span>Revizyon Teklifi</span>
                <strong>{{ $summary['quote_label'] }}</strong>
            </div>
            <div class="orc-summary-item">
                <span>Müşteri</span>
                <strong>{{ $summary['customer_label'] }}</strong>
            </div>
            <div class="orc-summary-item">
                <span>Revizyon Durumu</span>
                <strong><span class="pd-badge {{ $badgeClass($summary['status_tone']) }}">{{ $summary['status_label'] }}</span></strong>
            </div>
            <div class="orc-summary-item">
                <span>Süreç Özeti</span>
                <strong>{{ $summary['process_summary'] }}</strong>
            </div>
        </div>
    </section>

    <section class="pd-note pd-note-amber orc-banner">
        Bu ekran karşılaştırma ve kontrollü uygulama içindir. Kilitli veya manuel kontrol gerektiren alanlar otomatik uygulanmaz.
    </section>

    @if($applySummary['has_finance_note'] ?? false)
        <section class="pd-note pd-note-amber orc-banner">
            {{ $applySummary['finance_note'] }}
        </section>
    @endif

    @if(($applySummary['button_enabled'] ?? false) === false && !empty($applySummary['button_disabled_reason']))
        <section class="pd-note pd-note-slate orc-banner">
            {{ $applySummary['button_disabled_reason'] }}
        </section>
    @endif

    <div class="orc-layout">
        <div class="orc-main">
            <section class="orc-card">
                <div class="orc-card-head">
                    <h2>Revizyon Karar Matrisi</h2>
                    <p>Kararlar kaynak siparişin süreç durumuna göre hesaplanır; otomatik uygulama yapılmaz.</p>
                </div>
                <div class="orc-card-body">
                    <table class="orc-table" data-testid="revision-decision-matrix">
                        <thead>
                            <tr>
                                <th>Değişim Alanı</th>
                                <th>Karar</th>
                                <th>Açıklama</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($decisionMatrix as $row)
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td><span class="pd-badge {{ $badgeClass($row['decision_tone']) }}">{{ $row['decision'] }}</span></td>
                                    <td>{{ $row['helper'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="orc-card">
                <div class="orc-card-head">
                    <h2>Ürün &amp; Baskı Karşılaştırması</h2>
                    <p>Kaynak sipariş ve revizyon teklifindeki ticari kalemler yan yana gösterilir.</p>
                </div>
                <div class="orc-card-body orc-compare-stack">
                    @foreach($lineComparisons as $row)
                        <article class="orc-compare-row" data-testid="revision-compare-line">
                            <div class="orc-compare-head">
                                <div>
                                    <h3>Kalem {{ $row['sequence'] }}</h3>
                                    <p>{{ $row['match_reason'] }}</p>
                                </div>
                                <span class="pd-badge {{ $badgeClass($row['status_tone']) }}">{{ $row['status'] }}</span>
                            </div>

                            <div class="orc-dual-grid">
                                <div class="orc-side">
                                    <span class="orc-side-label">Kaynak Sipariş</span>
                                    <strong>{{ $row['source']['title'] }}</strong>
                                    <div>Kod: {{ $row['source']['code'] }}</div>
                                    <div>Adet: {{ $row['source']['quantity'] }}</div>
                                    <div>Birim Fiyat: {{ $row['source']['unit_price'] }}</div>
                                    <div>Satır Toplam: {{ $row['source']['line_total'] }}</div>
                                    <div>Açıklama: {{ $row['source']['description'] }}</div>
                                </div>
                                <div class="orc-side">
                                    <span class="orc-side-label">Revizyon Teklifi</span>
                                    <strong>{{ $row['revision']['title'] }}</strong>
                                    <div>Kod: {{ $row['revision']['code'] }}</div>
                                    <div>Adet: {{ $row['revision']['quantity'] }}</div>
                                    <div>Birim Fiyat: {{ $row['revision']['unit_price'] }}</div>
                                    <div>Satır Toplam: {{ $row['revision']['line_total'] }}</div>
                                    <div>Açıklama: {{ $row['revision']['description'] }}</div>
                                </div>
                            </div>

                            @if(!empty($row['prints']))
                                <div class="orc-print-stack">
                                    @foreach($row['prints'] as $print)
                                        <div class="orc-print-row" data-testid="revision-compare-print-line">
                                            <div class="orc-print-head">
                                                <strong>Baskı {{ $print['sequence'] }}</strong>
                                                <span class="pd-badge {{ $badgeClass($print['status_tone']) }}">{{ $print['status'] }}</span>
                                            </div>
                                            <div class="orc-dual-grid">
                                                <div class="orc-side">
                                                    <span class="orc-side-label">Kaynak Baskı</span>
                                                    <div>Tip: {{ $print['source']['type'] }}</div>
                                                    <div>Opsiyon: {{ $print['source']['option'] }}</div>
                                                    <div>Konum: {{ $print['source']['location'] }}</div>
                                                    <div>Üretim: {{ $print['source']['production_type'] }}</div>
                                                    <div>Adet: {{ $print['source']['quantity'] }}</div>
                                                    <div>Birim Fiyat: {{ $print['source']['unit_price'] }}</div>
                                                    <div>Toplam: {{ $print['source']['total'] }}</div>
                                                    <div>Not: {{ $print['source']['note'] }}</div>
                                                </div>
                                                <div class="orc-side">
                                                    <span class="orc-side-label">Revizyon Baskı</span>
                                                    <div>Tip: {{ $print['revision']['type'] }}</div>
                                                    <div>Opsiyon: {{ $print['revision']['option'] }}</div>
                                                    <div>Konum: {{ $print['revision']['location'] }}</div>
                                                    <div>Üretim: {{ $print['revision']['production_type'] }}</div>
                                                    <div>Adet: {{ $print['revision']['quantity'] }}</div>
                                                    <div>Birim Fiyat: {{ $print['revision']['unit_price'] }}</div>
                                                    <div>Toplam: {{ $print['revision']['total'] }}</div>
                                                    <div>Not: {{ $print['revision']['note'] }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        </div>

        <aside class="orc-side-stack">
            <section class="orc-card orc-sticky">
                <div class="orc-card-head">
                    <h2>Revizyon Özeti</h2>
                </div>
                <div class="orc-card-body">
                    @foreach($summary['counters'] as $counter)
                        <div class="orc-kpi-row">
                            <span>{{ $counter['label'] }}</span>
                            <strong>{{ $counter['value'] }}</strong>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="orc-card">
                <div class="orc-card-head">
                    <h2>Süreç Kapıları</h2>
                </div>
                <div class="orc-card-body">
                    @foreach($processGates['rows'] as $gate)
                        <div class="orc-gate-row">
                            <div class="orc-gate-top">
                                <strong>{{ $gate['label'] }}</strong>
                                <span class="pd-badge {{ $badgeClass($gate['tone']) }}">{{ $gate['value'] }}</span>
                            </div>
                            <p>{{ $gate['helper'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="orc-card">
                <div class="orc-card-head">
                    <h2>Aksiyonlar</h2>
                </div>
                <div class="orc-card-body orc-action-stack">
                    <div class="pd-note pd-note-amber">
                        Revizyon yalnız güvenli ticari alanlara uygulanır. Operasyon, finans, tahsilat ve tedarik kayıtları otomatik değişmez.
                    </div>
                    @if($sourceOrderContext['url'])
                        <a href="{{ $sourceOrderContext['url'] }}" class="pd-btn pd-btn-light">Kaynak Siparişe Dön</a>
                    @endif
                    <a href="{{ route('admin.promotion-quotes.edit', $revisionQuote) }}" class="pd-btn pd-btn-light">Revizyon Teklifini Düzenle</a>
                    <button
                        type="button"
                        class="pd-btn pd-btn-primary"
                        data-open-revision-apply-modal
                        data-testid="revision-apply-button"
                        @disabled(!($applySummary['button_enabled'] ?? false))
                    >
                        Revizyonu Uygula
                    </button>
                    <small>Yalnız uygulanabilir ve kontrollü uygulanabilir ticari alanlar işlenir; kilitli ve manuel alanlar atlanır.</small>
                </div>
            </section>
        </aside>
    </div>
</div>

<div class="promotion-quote-detail quote-detail-compact quote-send-modal quote-send-modal-backdrop quote-detail-modal-backdrop" id="revisionApplyModal" aria-hidden="true">
    <div class="quote-send-modal-panel quote-detail-modal" role="dialog" aria-modal="true" aria-labelledby="revision-apply-modal-title">
        <div class="quote-send-modal-head">
            <div>
                <h2 id="revision-apply-modal-title">Revizyonu Uygula</h2>
                <p>Yalnız güvenli ticari alanlar uygulanır; kilitli ve manuel kararlar atlanır.</p>
            </div>
            <button type="button" class="quote-modal-close" data-close-revision-apply-modal aria-label="Kapat">×</button>
        </div>
        <div class="quote-send-modal-body">
            <div class="quote-send-modal-grid">
                <div class="quote-send-modal-field">
                    <label>Uygulanacak Değişiklik</label>
                    <strong>{{ $applySummary['applicable_count'] ?? 0 }}</strong>
                </div>
                <div class="quote-send-modal-field">
                    <label>Kilitli Alan</label>
                    <strong>{{ $applySummary['locked_count'] ?? 0 }}</strong>
                </div>
                <div class="quote-send-modal-field">
                    <label>Manuel Kontrol</label>
                    <strong>{{ $applySummary['manual_count'] ?? 0 }}</strong>
                </div>
                <div class="quote-send-modal-field">
                    <label>Değişiklik Yok</label>
                    <strong>{{ $applySummary['no_change_count'] ?? 0 }}</strong>
                </div>
            </div>
            @if($applySummary['has_finance_note'] ?? false)
                <div class="pd-note pd-note-amber">
                    {{ $applySummary['finance_note'] }}
                </div>
            @endif
            @if(!empty($applySummary['button_disabled_reason']))
                <div class="pd-note pd-note-slate">
                    {{ $applySummary['button_disabled_reason'] }}
                </div>
            @endif
            <form method="POST" action="{{ route('admin.promotion-quotes.revision-apply', $revisionQuote) }}" class="quote-send-modal-actions">
                @csrf
                <div>
                    <small>Kaynak siparişte ürün kimliği ve baskı tipi otomatik değiştirilmeyecektir.</small>
                </div>
                <div class="quote-send-modal-actions-inline">
                    <button type="button" class="pd-btn pd-btn-light" data-close-revision-apply-modal>Vazgeç</button>
                    <button type="submit" class="pd-btn pd-btn-primary" @disabled(!($applySummary['button_enabled'] ?? false))>
                        Revizyonu Uygula
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('revisionApplyModal');
    if (!modal) {
        return;
    }

    var body = document.body;
    var openButtons = document.querySelectorAll('[data-open-revision-apply-modal]');
    var closeButtons = modal.querySelectorAll('[data-close-revision-apply-modal]');

    function openModal() {
        if (openButtons.length < 1 || openButtons[0].disabled) {
            return;
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        body.classList.add('pd-modal-open');
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        body.classList.remove('pd-modal-open');
    }

    openButtons.forEach(function (button) {
        button.addEventListener('click', openModal);
    });

    closeButtons.forEach(function (button) {
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
});
</script>
@endpush
