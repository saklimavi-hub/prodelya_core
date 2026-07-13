<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $quote['number'] }} | Teklif Onayı</title>
    <style>
        :root {
            --quote-approval-bg: #f5f6f8;
            --quote-approval-panel: #ffffff;
            --quote-approval-line: #e2e6ee;
            --quote-approval-line-soft: #eef1f5;
            --quote-approval-text: #243149;
            --quote-approval-muted: #6d7788;
            --quote-approval-blue: #2f6fed;
            --quote-approval-blue-soft: #eef4ff;
            --quote-approval-green: #238a55;
            --quote-approval-green-soft: #edf8f2;
            --quote-approval-amber: #c47713;
            --quote-approval-amber-soft: #fff6e7;
            --quote-approval-red: #c33b35;
            --quote-approval-red-soft: #fff0ef;
            --quote-approval-shadow: 0 8px 24px rgba(31, 45, 78, 0.07);
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(47, 111, 237, 0.05), transparent 28%),
                linear-gradient(180deg, #faf7ef 0%, var(--quote-approval-bg) 42%, var(--quote-approval-bg) 100%);
            color: var(--quote-approval-text);
            font-size: 14px;
            line-height: 1.45;
        }

        .quote-approval-page {
            max-width: 1180px;
            margin: 0 auto;
            padding: 32px 18px 42px;
        }

        .quote-approval-flash {
            border-radius: 8px;
            border: 1px solid transparent;
            padding: 12px 14px;
            margin-bottom: 14px;
        }

        .quote-approval-flash-success {
            background: var(--quote-approval-green-soft);
            color: var(--quote-approval-green);
            border-color: #d8f0e4;
        }

        .quote-approval-flash-error {
            background: var(--quote-approval-red-soft);
            color: var(--quote-approval-red);
            border-color: #f3cdc9;
        }

        .quote-approval-card {
            background: var(--quote-approval-panel);
            border: 1px solid var(--quote-approval-line);
            border-radius: 8px;
            box-shadow: var(--quote-approval-shadow);
            overflow: hidden;
        }

        .quote-approval-card + .quote-approval-card {
            margin-top: 14px;
        }

        .quote-approval-card-head {
            padding: 15px 18px 10px;
            border-bottom: 1px solid var(--quote-approval-line-soft);
            background: linear-gradient(180deg, #fff 0%, #fcfcfd 100%);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .quote-approval-card-body {
            padding: 16px 18px 18px;
        }

        .quote-approval-top-card {
            margin-bottom: 14px;
            padding: 18px;
        }

        .quote-approval-top-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
        }

        .quote-approval-brand {
            color: #5d6a7d;
            letter-spacing: 0.05em;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .quote-approval-title {
            margin: 0 0 8px;
            font-size: 28px;
            line-height: 1.12;
            font-weight: 400;
            color: #1f2d45;
        }

        .quote-approval-subtitle,
        .quote-approval-muted,
        .quote-approval-note,
        .quote-approval-textarea,
        .quote-approval-button {
            font-family: Arial, Helvetica, sans-serif;
        }

        .quote-approval-subtitle,
        .quote-approval-muted {
            color: var(--quote-approval-muted);
        }

        .quote-approval-muted {
            font-size: 13px;
        }

        .quote-approval-small {
            font-size: 12px;
        }

        .quote-approval-status-badge {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 0 10px;
            border-radius: 4px;
            white-space: nowrap;
            font-size: 12px;
            border: 1px solid transparent;
        }

        .quote-approval-status-blue {
            background: var(--quote-approval-blue-soft);
            color: var(--quote-approval-blue);
            border-color: #dce8ff;
        }

        .quote-approval-status-green {
            background: var(--quote-approval-green-soft);
            color: var(--quote-approval-green);
            border-color: #d8f0e4;
        }

        .quote-approval-status-amber {
            background: var(--quote-approval-amber-soft);
            color: var(--quote-approval-amber);
            border-color: #f2d5a6;
        }

        .quote-approval-status-red {
            background: var(--quote-approval-red-soft);
            color: var(--quote-approval-red);
            border-color: #f3cdc9;
        }

        .quote-approval-info-box {
            margin-top: 14px;
            border: 1px solid #dfe7f3;
            background: #f8fbff;
            border-radius: 6px;
            padding: 12px 14px;
        }

        .quote-approval-info-box h2,
        .quote-approval-section-title,
        .quote-approval-action-card h3 {
            margin-top: 0;
            color: #233149;
            font-weight: 400;
        }

        .quote-approval-info-box h2 {
            font-size: 15px;
            margin-bottom: 4px;
        }

        .quote-approval-meta-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-top: 14px;
        }

        .quote-approval-meta {
            border: 1px solid var(--quote-approval-line);
            border-radius: 6px;
            padding: 12px;
            background: #fff;
            min-height: 88px;
        }

        .quote-approval-meta-label,
        .quote-approval-kicker {
            color: var(--quote-approval-muted);
            font-size: 11px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 7px;
        }

        .quote-approval-meta-value {
            color: #21304a;
            font-size: 15px;
        }

        .quote-approval-layout {
            display: grid;
            grid-template-columns: minmax(700px, 1fr) 320px;
            gap: 16px;
            align-items: start;
        }

        .quote-approval-sidebar {
            position: sticky;
            top: 18px;
            display: grid;
            gap: 14px;
        }

        .quote-approval-mobile-summary {
            display: none;
        }

        .quote-approval-summary-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid var(--quote-approval-line);
            border-radius: 6px;
            margin-bottom: 8px;
            background: #fff;
        }

        .quote-approval-summary-row:last-child {
            margin-bottom: 0;
        }

        .quote-approval-summary-row-total {
            background: #f7ead9;
            border-color: #ead8bd;
        }

        .quote-approval-summary-note {
            border-left: 3px solid var(--quote-approval-blue);
            background: #f8fbff;
            border-radius: 6px;
            padding: 10px 12px;
            color: #536176;
            font-size: 12px;
            margin-top: 10px;
        }

        .quote-approval-item-list {
            display: grid;
            gap: 10px;
        }

        .quote-approval-item {
            border: 1px solid #dfe4ec;
            border-radius: 7px;
            background: #fff;
            overflow: hidden;
        }

        .quote-approval-item-main {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 150px;
            gap: 12px;
            padding: 14px;
            align-items: start;
        }

        .quote-approval-item-title {
            font-size: 15px;
            color: #22304a;
            margin: 0 0 5px;
        }

        .quote-approval-item-code {
            color: var(--quote-approval-muted);
            font-size: 12px;
            margin-bottom: 8px;
        }

        .quote-approval-item-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .quote-approval-tag {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 8px;
            border-radius: 4px;
            font-size: 12px;
            border: 1px solid var(--quote-approval-line);
            color: #5e6b7e;
            background: #f6f8fb;
        }

        .quote-approval-tag-blue {
            background: var(--quote-approval-blue-soft);
            color: var(--quote-approval-blue);
            border-color: #dbe8ff;
        }

        .quote-approval-tag-green {
            background: var(--quote-approval-green-soft);
            color: var(--quote-approval-green);
            border-color: #d8f0e4;
        }

        .quote-approval-amount-box {
            text-align: right;
            color: #20304a;
        }

        .quote-approval-amount-label {
            color: var(--quote-approval-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 5px;
        }

        .quote-approval-amount {
            font-size: 16px;
            font-weight: 400;
            white-space: nowrap;
        }

        .quote-approval-print-line {
            border-top: 1px solid var(--quote-approval-line-soft);
            background: #fbfcfe;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 150px;
            gap: 12px;
            padding: 12px 14px;
            align-items: start;
        }

        .quote-approval-print-title {
            color: #22304a;
            margin-bottom: 4px;
        }

        .quote-approval-decision-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .quote-approval-action-card {
            border: 1px solid var(--quote-approval-line);
            border-radius: 7px;
            padding: 12px;
            background: #fff;
            display: flex;
            flex-direction: column;
            min-height: 240px;
        }

        .quote-approval-action-card h3 {
            font-size: 15px;
            margin-bottom: 4px;
        }

        .quote-approval-textarea {
            width: 100%;
            min-height: 88px;
            resize: vertical;
            border: 1px solid #d7dde7;
            border-radius: 6px;
            padding: 10px 12px;
            color: var(--quote-approval-text);
            font: inherit;
            outline: none;
            margin: 8px 0 10px;
        }

        .quote-approval-textarea:focus {
            border-color: #b6caef;
            box-shadow: 0 0 0 3px rgba(47, 111, 237, 0.08);
        }

        .quote-approval-button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 5px;
            border: 1px solid var(--quote-approval-line);
            text-decoration: none;
            color: #344155;
            background: #fff;
            font: inherit;
            cursor: pointer;
            margin-top: auto;
        }

        .quote-approval-button[disabled] {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .quote-approval-button-green {
            background: var(--quote-approval-green);
            color: #fff;
            border-color: var(--quote-approval-green);
        }

        .quote-approval-button-amber {
            background: var(--quote-approval-amber);
            color: #fff;
            border-color: var(--quote-approval-amber);
        }

        .quote-approval-button-red {
            background: var(--quote-approval-red);
            color: #fff;
            border-color: var(--quote-approval-red);
        }

        .quote-approval-button-blue {
            background: var(--quote-approval-blue);
            color: #fff;
            border-color: var(--quote-approval-blue);
        }

        .quote-approval-button-block {
            width: 100%;
        }

        .quote-approval-quick-actions {
            display: grid;
            gap: 9px;
        }

        .quote-approval-error {
            margin-top: 6px;
            color: var(--quote-approval-red);
            font-size: 12px;
        }

        .quote-approval-notice {
            border: 1px solid var(--quote-approval-line);
            border-left: 3px solid var(--quote-approval-blue);
            background: #fbfcfe;
            border-radius: 6px;
            padding: 12px 14px;
            color: #657085;
            font-size: 12px;
        }

        @media (max-width: 1100px) {
            .quote-approval-layout {
                grid-template-columns: 1fr;
            }

            .quote-approval-sidebar {
                position: static;
            }

            .quote-approval-mobile-summary {
                display: block;
            }

            .quote-approval-desktop-summary {
                display: none;
            }

            .quote-approval-decision-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .quote-approval-page {
                padding: 18px 12px 30px;
            }

            .quote-approval-top-row {
                flex-direction: column;
            }

            .quote-approval-meta-grid {
                grid-template-columns: 1fr 1fr;
            }

            .quote-approval-item-main,
            .quote-approval-print-line {
                grid-template-columns: 1fr;
            }

            .quote-approval-amount-box {
                text-align: left;
            }

            .quote-approval-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
<main class="quote-approval-page">
    @if(session('success'))
        <div class="quote-approval-flash quote-approval-flash-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="quote-approval-flash quote-approval-flash-error">{{ session('error') }}</div>
    @endif

    @php
        $statusClass = match($pageStatus) {
            'approved' => 'quote-approval-status-green',
            'revision_requested' => 'quote-approval-status-amber',
            'rejected', 'expired' => 'quote-approval-status-red',
            default => 'quote-approval-status-blue',
        };
    @endphp

    <section class="quote-approval-card quote-approval-top-card">
        <div class="quote-approval-top-row">
            <div>
                <div class="quote-approval-brand">{{ $tenantName }}</div>
                <h1 class="quote-approval-title">Teklifinizi İnceleyin</h1>
                <p class="quote-approval-subtitle">Uygunsa teklifi onaylayabilir, değişiklik isterseniz revize talebi iletebilirsiniz. Onay alındığında firma yetkilileri sipariş sürecini başlatacaktır.</p>
            </div>
            <span class="quote-approval-status-badge {{ $statusClass }}">{{ $pageStatusLabel }}</span>
        </div>

        <div class="quote-approval-info-box">
            <h2>İnceleme Durumu</h2>
            <p class="quote-approval-muted quote-approval-small" style="margin:0;">{{ $pageMessage }}</p>
        </div>

        <div class="quote-approval-meta-grid">
            <div class="quote-approval-meta">
                <div class="quote-approval-meta-label">Teklif No</div>
                <div class="quote-approval-meta-value">{{ $quote['number'] }}</div>
            </div>
            <div class="quote-approval-meta">
                <div class="quote-approval-meta-label">Müşteri</div>
                <div class="quote-approval-meta-value">{{ $quote['customer_name'] }}</div>
            </div>
            <div class="quote-approval-meta">
                <div class="quote-approval-meta-label">Teklif Tarihi</div>
                <div class="quote-approval-meta-value">{{ $quote['quote_date'] ?: '-' }}</div>
            </div>
            <div class="quote-approval-meta">
                <div class="quote-approval-meta-label">Geçerlilik Tarihi</div>
                <div class="quote-approval-meta-value">{{ $quote['valid_until'] ?: '-' }}</div>
            </div>
        </div>
    </section>

    <div class="quote-approval-layout">
        <section class="quote-approval-main">
            <div class="quote-approval-card quote-approval-mobile-summary">
                <div class="quote-approval-card-head">
                    <div>
                        <h2 class="quote-approval-section-title">Fiyat Özeti</h2>
                        <p class="quote-approval-muted quote-approval-small">KDV tek satır veya oran bazlı sade satırlarda gösterilir.</p>
                    </div>
                </div>
                <div class="quote-approval-card-body">
                    <div class="quote-approval-summary-row">
                        <span>Ara Toplam</span>
                        <span>{{ $totals['subtotal'] ?: '-' }}</span>
                    </div>
                    @foreach($totals['vat_breakdown'] as $vatRow)
                        <div class="quote-approval-summary-row">
                            <span>{{ $vatRow['label'] }}</span>
                            <span>{{ $vatRow['total'] ?: '-' }}</span>
                        </div>
                    @endforeach
                    <div class="quote-approval-summary-row quote-approval-summary-row-total">
                        <span><strong>Genel Toplam</strong></span>
                        <span><strong>{{ $totals['grand_total'] ?: '-' }}</strong></span>
                    </div>
                </div>
            </div>

            <div class="quote-approval-card">
                <div class="quote-approval-card-head">
                    <div>
                        <h2 class="quote-approval-section-title">Teklif Kalemleri</h2>
                        <p class="quote-approval-muted quote-approval-small">Ürün ve baskı işlemleri kalem altında ayrı satırda gösterilir.</p>
                    </div>
                    <span class="quote-approval-tag quote-approval-tag-blue">{{ $quote['item_count'] }} {{ $quote['item_count'] === 1 ? 'ürün' : 'ürün' }}</span>
                </div>
                <div class="quote-approval-card-body">
                    <div class="quote-approval-item-list">
                        @foreach($items as $index => $item)
                            <article class="quote-approval-item">
                                <div class="quote-approval-item-main">
                                    <div>
                                        <h3 class="quote-approval-item-title">{{ $index + 1 }}. {{ $item['product_name'] }}</h3>
                                        <div class="quote-approval-item-code">
                                            {{ $item['product_code'] ?: 'Kod bilgisi yok' }} · {{ $item['quantity'] }}
                                        </div>
                                        <div class="quote-approval-item-tags">
                                            @if($item['product_code'])
                                                <span class="quote-approval-tag">{{ $item['product_code'] }}</span>
                                            @endif
                                            <span class="quote-approval-tag quote-approval-tag-green">Ürün</span>
                                        </div>
                                    </div>
                                    <div class="quote-approval-amount-box">
                                        @if($item['unit_price'])
                                            <div class="quote-approval-amount-label">Birim Fiyat</div>
                                            <div class="quote-approval-amount">{{ $item['unit_price'] }}</div>
                                        @endif
                                        @if($item['line_total'])
                                            <div class="quote-approval-amount-label" style="margin-top:8px;">Kalem Toplamı</div>
                                            <div class="quote-approval-amount">{{ $item['line_total'] }}</div>
                                        @endif
                                    </div>
                                </div>

                                @foreach($item['print_lines'] as $print)
                                    <div class="quote-approval-print-line">
                                        <div>
                                            <div class="quote-approval-print-title">
                                                {{ trim(collect([$print['print_type'], $print['print_option']])->filter()->implode(' · ')) ?: '-' }}
                                            </div>
                                            <div class="quote-approval-muted quote-approval-small">{{ $print['print_quantity'] }}</div>
                                            @if($print['show_price_details'])
                                                <div class="quote-approval-muted quote-approval-small" style="margin-top:4px;">
                                                    Baskı Birim: {{ $print['print_unit_price'] ?: '-' }}
                                                </div>
                                            @endif
                                            @if($print['print_note'])
                                                <div class="quote-approval-muted quote-approval-small" style="margin-top:4px;">{{ $print['print_note'] }}</div>
                                            @endif
                                        </div>
                                        <div class="quote-approval-amount-box">
                                            <div class="quote-approval-amount-label">Baskı Toplamı</div>
                                            <div class="quote-approval-amount">
                                                @if($print['show_price_details'])
                                                    {{ $print['print_total'] ?: '-' }}
                                                @else
                                                    Fiyata dahil
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </article>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="quote-approval-card" id="decision-actions">
                <div class="quote-approval-card-head">
                    <div>
                        <h2 class="quote-approval-section-title">Kararınızı Bildirin</h2>
                        <p class="quote-approval-muted quote-approval-small">Onay, revize veya red işlemlerinden birini seçebilirsiniz.</p>
                    </div>
                </div>
                <div class="quote-approval-card-body">
                    <div class="quote-approval-decision-grid">
                        <div class="quote-approval-action-card" id="approve-action">
                            <div class="quote-approval-kicker">Onay</div>
                            <h3>Teklif uygunsa</h3>
                            <p class="quote-approval-muted quote-approval-small">Teklifi kabul edip sipariş sürecinin başlamasını sağlayabilirsiniz.</p>
                            <form method="POST">
                                @csrf
                                <input type="hidden" name="decision" value="approve">
                                <input type="hidden" name="customer_note" value="">
                                <button type="submit" class="quote-approval-button quote-approval-button-green quote-approval-button-block" @disabled(!$canRespond)>Teklifi Onayla</button>
                            </form>
                        </div>

                        <div class="quote-approval-action-card" id="revision-action">
                            <div class="quote-approval-kicker">Revize</div>
                            <h3>Değişiklik isteyin</h3>
                            <p class="quote-approval-muted quote-approval-small">Teslim tarihi, baskı notu veya miktar gibi değişiklikleri yazabilirsiniz.</p>
                            <form method="POST">
                                @csrf
                                <input type="hidden" name="decision" value="revision">
                                <textarea class="quote-approval-textarea" name="customer_note" placeholder="Örnek: Teslim tarihi ve baskı notu güncellensin." @disabled(!$canRespond)>{{ old('customer_note') }}</textarea>
                                @error('customer_note')
                                    <div class="quote-approval-error">{{ $message }}</div>
                                @enderror
                                <button type="submit" class="quote-approval-button quote-approval-button-amber quote-approval-button-block" @disabled(!$canRespond)>Revize İste</button>
                            </form>
                        </div>

                        <div class="quote-approval-action-card" id="reject-action">
                            <div class="quote-approval-kicker">Red</div>
                            <h3>Teklifi reddedin</h3>
                            <p class="quote-approval-muted quote-approval-small">İsterseniz kısa bir açıklama ekleyerek teklifi reddedebilirsiniz.</p>
                            <form method="POST">
                                @csrf
                                <input type="hidden" name="decision" value="reject">
                                <textarea class="quote-approval-textarea" name="customer_note" placeholder="İsterseniz kısa bir açıklama ekleyebilirsiniz." @disabled(!$canRespond)>{{ old('customer_note') }}</textarea>
                                <button type="submit" class="quote-approval-button quote-approval-button-red quote-approval-button-block" @disabled(!$canRespond)>Teklifi Reddet</button>
                            </form>
                        </div>
                    </div>

                    @if(!$canRespond)
                        <p class="quote-approval-muted" style="margin:14px 0 0;">Bu teklif için işlem daha önce tamamlandı veya bağlantının süresi doldu. Yeni işlem yapılamaz.</p>
                    @endif
                </div>
            </div>

            <div class="quote-approval-notice">
                Bu bağlantı yalnız teklif değerlendirme amacıyla hazırlanmıştır. Cari hesap, operasyon geçmişi ve teknik dosya yolları bu ekranda yer almaz.
            </div>
        </section>

        <aside class="quote-approval-sidebar quote-approval-responsive-sidebar quote-approval-desktop-summary">
            <div class="quote-approval-card quote-approval-summary">
                <div class="quote-approval-card-head">
                    <div>
                        <h2 class="quote-approval-section-title">Fiyat Özeti</h2>
                        <p class="quote-approval-muted quote-approval-small">KDV tek satır veya oran bazlı sade satırlarda gösterilir.</p>
                    </div>
                </div>
                <div class="quote-approval-card-body">
                    <div class="quote-approval-summary-row">
                        <span>Ara Toplam</span>
                        <span>{{ $totals['subtotal'] ?: '-' }}</span>
                    </div>
                    @foreach($totals['vat_breakdown'] as $vatRow)
                        <div class="quote-approval-summary-row">
                            <span>{{ $vatRow['label'] }}</span>
                            <span>{{ $vatRow['total'] ?: '-' }}</span>
                        </div>
                    @endforeach
                    <div class="quote-approval-summary-row quote-approval-summary-row-total">
                        <span><strong>Genel Toplam</strong></span>
                        <span><strong>{{ $totals['grand_total'] ?: '-' }}</strong></span>
                    </div>
                    <div class="quote-approval-summary-note">Aynı KDV oranına sahip ürün ve baskı tutarları tek KDV satırında toplanır. Müşteri ekranında tekrar eden KDV satırı gösterilmez.</div>
                </div>
            </div>

            <div class="quote-approval-card">
                <div class="quote-approval-card-head">
                    <div>
                        <h2 class="quote-approval-section-title">Hızlı Karar</h2>
                        <p class="quote-approval-muted quote-approval-small">Sayfayı kaydırmadan ilgili karar alanına gidebilirsiniz.</p>
                    </div>
                </div>
                <div class="quote-approval-card-body">
                    <div class="quote-approval-quick-actions">
                        <a class="quote-approval-button quote-approval-button-green quote-approval-button-block" href="#approve-action">Teklifi Onayla</a>
                        <a class="quote-approval-button quote-approval-button-amber quote-approval-button-block" href="#revision-action">Revize İste</a>
                        <a class="quote-approval-button quote-approval-button-red quote-approval-button-block" href="#reject-action">Teklifi Reddet</a>
                    </div>
                </div>
            </div>

            <div class="quote-approval-card">
                <div class="quote-approval-card-head">
                    <div>
                        <h2 class="quote-approval-section-title">Teklif Durumu</h2>
                        <p class="quote-approval-muted quote-approval-small">Mevcut müşteri karar aşaması.</p>
                    </div>
                </div>
                <div class="quote-approval-card-body">
                    <div class="quote-approval-summary-row">
                        <span>Durum</span>
                        <span class="quote-approval-status-badge {{ $statusClass }}">{{ $pageStatusLabel }}</span>
                    </div>
                    <div class="quote-approval-summary-row">
                        <span>Kalem sayısı</span>
                        <span>{{ $quote['item_count'] }} {{ $quote['item_count'] === 1 ? 'ürün' : 'ürün' }}</span>
                    </div>
                    <div class="quote-approval-summary-row">
                        <span>Geçerlilik tarihi</span>
                        <span>{{ $quote['valid_until'] ?: '-' }}</span>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</main>
</body>
</html>
