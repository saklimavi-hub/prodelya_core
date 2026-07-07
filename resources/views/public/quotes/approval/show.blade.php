<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teklif Onayı</title>
    <style>
        :root {
            --bg: #f4f1ea;
            --paper: #fffdf8;
            --ink: #1f2a37;
            --muted: #6b7280;
            --line: #e7dcc9;
            --brand: #8c5a2b;
            --brand-soft: #f7ebdc;
            --success: #1f7a45;
            --success-soft: #e9f6ee;
            --warn: #b56a10;
            --warn-soft: #fff1de;
            --danger: #b42318;
            --danger-soft: #fdecec;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background:
                radial-gradient(circle at top right, rgba(140, 90, 43, 0.08), transparent 28%),
                linear-gradient(180deg, #f8f3ea 0%, #f4f1ea 100%);
            color: var(--ink);
            font-family: Georgia, "Times New Roman", serif;
            line-height: 1.5;
        }

        .qa-shell {
            max-width: 980px;
            margin: 0 auto;
            padding: 24px 14px 40px;
            display: grid;
            gap: 16px;
        }

        .qa-card {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 18px 40px rgba(59, 42, 24, 0.08);
            overflow: hidden;
        }

        .qa-body { padding: 18px; }
        .qa-hero {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .qa-eyebrow {
            margin: 0 0 6px;
            color: var(--brand);
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
            font-family: Arial, Helvetica, sans-serif;
        }

        .qa-title {
            margin: 0;
            font-size: 30px;
            line-height: 1.1;
            color: #23180f;
        }

        .qa-copy,
        .qa-muted,
        .qa-label,
        .qa-button,
        .qa-field,
        .qa-textarea,
        .qa-flash,
        .qa-badge {
            font-family: Arial, Helvetica, sans-serif;
        }

        .qa-copy {
            margin: 10px 0 0;
            color: var(--muted);
            max-width: 620px;
            font-size: 14px;
        }

        .qa-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .qa-badge-blue { background: #edf4ff; color: #2456a6; border-color: #cfe0ff; }
        .qa-badge-green { background: var(--success-soft); color: var(--success); border-color: #c5e7d1; }
        .qa-badge-amber { background: var(--warn-soft); color: var(--warn); border-color: #f0d19d; }
        .qa-badge-red { background: var(--danger-soft); color: var(--danger); border-color: #f4c3bf; }

        .qa-flash {
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid transparent;
            font-size: 14px;
        }

        .qa-flash-success { background: var(--success-soft); color: var(--success); border-color: #c5e7d1; }
        .qa-flash-error { background: var(--danger-soft); color: var(--danger); border-color: #f4c3bf; }

        .qa-grid {
            display: grid;
            gap: 12px;
        }

        .qa-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .qa-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .qa-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }

        .qa-stat,
        .qa-item,
        .qa-print,
        .qa-total-row,
        .qa-status-box,
        .qa-action-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            padding: 14px;
        }

        .qa-label {
            display: block;
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .qa-value {
            font-size: 15px;
            font-weight: 700;
        }

        .qa-section-title {
            margin: 0 0 12px;
            font-size: 18px;
            color: #23180f;
        }

        .qa-muted {
            color: var(--muted);
            font-size: 13px;
        }

        .qa-item-head,
        .qa-total-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .qa-item-title {
            margin: 0;
            font-size: 17px;
        }

        .qa-item-copy {
            margin: 8px 0 0;
            font-size: 14px;
            color: #334155;
        }

        .qa-list {
            display: grid;
            gap: 12px;
        }

        .qa-button {
            width: 100%;
            border: 1px solid transparent;
            border-radius: 12px;
            padding: 11px 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .qa-button-green { background: var(--success); color: #fff; }
        .qa-button-amber { background: var(--warn); color: #fff; }
        .qa-button-red { background: var(--danger); color: #fff; }

        .qa-field,
        .qa-textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fffdfa;
            color: var(--ink);
        }

        .qa-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .qa-error {
            margin-top: 6px;
            color: var(--danger);
            font-size: 12px;
            font-family: Arial, Helvetica, sans-serif;
        }

        .qa-actions {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .qa-helper {
            margin-top: 10px;
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 820px) {
            .qa-grid-4,
            .qa-grid-3,
            .qa-grid-2,
            .qa-actions {
                grid-template-columns: 1fr;
            }

            .qa-item-head,
            .qa-total-row {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="qa-shell">
    @if(session('success'))
        <div class="qa-flash qa-flash-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="qa-flash qa-flash-error">{{ session('error') }}</div>
    @endif

    @php
        $badgeClass = match($pageStatus) {
            'approved' => 'qa-badge-green',
            'revision_requested' => 'qa-badge-amber',
            'rejected', 'expired' => 'qa-badge-red',
            default => 'qa-badge-blue',
        };
    @endphp

    <div class="qa-card">
        <div class="qa-body">
            <div class="qa-hero">
                <div>
                    <p class="qa-eyebrow">{{ $tenantName }}</p>
                    <h1 class="qa-title">Teklifinizi İnceleyin</h1>
                    <p class="qa-copy">Teklif sizin için uygunsa onaylayabilir, değişiklik gerekiyorsa kısa bir revize notu bırakabilirsiniz. Onayınız alındığında sipariş süreci başlatılır.</p>
                </div>
                <span class="qa-badge {{ $badgeClass }}">{{ $pageStatusLabel }}</span>
            </div>

            <div class="qa-status-box" style="margin-top:16px;">
                <strong class="qa-value" style="display:block; margin-bottom:6px;">{{ $pageStatusLabel }}</strong>
                <div class="qa-copy" style="margin-top:0;">{{ $pageMessage }}</div>
            </div>

            <div class="qa-grid qa-grid-4" style="margin-top:16px;">
                <div class="qa-stat">
                    <span class="qa-label">Teklif No</span>
                    <div class="qa-value">{{ $quote['number'] }}</div>
                </div>
                <div class="qa-stat">
                    <span class="qa-label">Müşteri</span>
                    <div class="qa-value">{{ $quote['customer_name'] }}</div>
                </div>
                <div class="qa-stat">
                    <span class="qa-label">Teklif Tarihi</span>
                    <div class="qa-value">{{ $quote['quote_date'] ?: '-' }}</div>
                </div>
                <div class="qa-stat">
                    <span class="qa-label">Geçerlilik Tarihi</span>
                    <div class="qa-value">{{ $quote['valid_until'] ?: '-' }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="qa-card">
        <div class="qa-body">
            <h2 class="qa-section-title">Teklif Kalemleri</h2>
            <div class="qa-list">
                @foreach($items as $index => $item)
                    <div class="qa-item">
                        <div class="qa-item-head">
                            <div>
                                <h3 class="qa-item-title">{{ $index + 1 }}. {{ $item['product_name'] }}</h3>
                                <div class="qa-helper">{{ $item['product_code'] ?: '-' }} · {{ $item['quantity'] }}</div>
                            </div>
                            @if($item['unit_price'])
                                <div>
                                    <span class="qa-label">Birim Fiyat</span>
                                    <div class="qa-value">{{ $item['unit_price'] }}</div>
                                </div>
                            @endif
                            @if($item['line_total'])
                                <div>
                                    <span class="qa-label">Kalem Toplamı</span>
                                    <div class="qa-value">{{ $item['line_total'] }}</div>
                                </div>
                            @endif
                        </div>

                        @if(!empty($item['print_lines']))
                            <div class="qa-list" style="margin-top:12px;">
                                @foreach($item['print_lines'] as $print)
                                    <div class="qa-print">
                                    <div class="qa-item-head">
                                        <div>
                                            <div class="qa-value">{{ trim(collect([$print['print_type'], $print['print_option']])->filter()->implode(' | ')) ?: '-' }}</div>
                                            <div class="qa-helper">
                                                {{ $print['print_quantity'] }}
                                                @if($print['show_price_details'])
                                                    · Baskı Birim: {{ $print['print_unit_price'] ?: '-' }}
                                                    · Baskı Toplam: {{ $print['print_total'] ?: '-' }}
                                                @endif
                                            </div>
                                            @if($print['print_note'])
                                                <p class="qa-item-copy">{{ $print['print_note'] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="qa-card">
        <div class="qa-body">
            <h2 class="qa-section-title">Fiyat Özeti</h2>
            <div class="qa-list">
                @if($totals['subtotal'])
                    <div class="qa-total-row">
                        <span>Ara Toplam</span>
                        <strong>{{ $totals['subtotal'] }}</strong>
                    </div>
                @endif

                @foreach($totals['vat_breakdown'] as $vatRow)
                    <div class="qa-total-row">
                        <span>{{ $vatRow['label'] }}</span>
                        <strong>{{ $vatRow['total'] }}</strong>
                    </div>
                @endforeach

                @if($totals['vat_total'])
                    <div class="qa-total-row">
                        <span>KDV</span>
                        <strong>{{ $totals['vat_total'] }}</strong>
                    </div>
                @endif

                @if($totals['grand_total'])
                    <div class="qa-total-row" style="background:var(--brand-soft); border-color:#ecd5b6;">
                        <span><strong>Genel Toplam</strong></span>
                        <strong>{{ $totals['grand_total'] }}</strong>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if($canRespond)
        <div class="qa-card">
            <div class="qa-body">
            <h2 class="qa-section-title">Kararınızı Bildirin</h2>
                <div class="qa-actions">
                    <div class="qa-action-card">
                        <span class="qa-label">Onay</span>
                        <div class="qa-copy" style="margin-top:0;">Teklif uygunsa tek adımda onay verebilirsiniz.</div>
                        <form method="POST" action="onayla" style="margin-top:12px;">
                            @csrf
                            <input type="hidden" name="customer_note" value="">
                            <button type="submit" class="qa-button qa-button-green">Teklifi Onayla</button>
                        </form>
                    </div>

                    <div class="qa-action-card">
                        <span class="qa-label">Revize</span>
                        <div class="qa-copy" style="margin-top:0;">Değişmesini istediğiniz noktayı kısa bir notla iletebilirsiniz.</div>
                        <form method="POST" action="revize-iste" style="margin-top:12px;">
                            @csrf
                            <label class="qa-label" for="revision_note">Revize Notu</label>
                            <textarea class="qa-textarea" id="revision_note" name="customer_note" placeholder="Örnek: Teslim tarihi ve baskı notu güncellensin.">{{ old('customer_note') }}</textarea>
                            @error('customer_note')
                                <div class="qa-error">{{ $message }}</div>
                            @enderror
                            <button type="submit" class="qa-button qa-button-amber" style="margin-top:10px;">Revize İste</button>
                        </form>
                    </div>

                    <div class="qa-action-card">
                        <span class="qa-label">Red</span>
                        <div class="qa-copy" style="margin-top:0;">Uygun değilse kısa bir notla teklifi kapatabilirsiniz.</div>
                        <form method="POST" action="reddet" style="margin-top:12px;">
                            @csrf
                            <label class="qa-label" for="reject_note">Kısa Not</label>
                            <textarea class="qa-textarea" id="reject_note" name="customer_note" placeholder="İsterseniz kısa bir açıklama ekleyebilirsiniz."></textarea>
                            <button type="submit" class="qa-button qa-button-red" style="margin-top:10px;">Teklifi Reddet</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="qa-card">
        <div class="qa-body">
            <div class="qa-copy" style="margin-top:0;">Bu bağlantı yalnız teklif değerlendirme amacıyla hazırlanmıştır. İç maliyet, operasyon geçmişi ve teknik dosya yolları bu ekranda yer almaz.</div>
        </div>
    </div>
</div>
</body>
</html>
