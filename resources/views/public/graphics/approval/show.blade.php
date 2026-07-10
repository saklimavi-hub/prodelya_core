<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grafik Onayı</title>
    <style>
        :root {
            --bg: #f4f7f3;
            --paper: #ffffff;
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #dbe5dc;
            --brand: #1f5f46;
            --brand-soft: #e8f4ee;
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
                radial-gradient(circle at top right, rgba(31, 95, 70, 0.08), transparent 28%),
                linear-gradient(180deg, #f7faf7 0%, #f1f5f1 100%);
            color: var(--ink);
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.5;
        }

        .ga-shell {
            max-width: 980px;
            margin: 0 auto;
            padding: 24px 14px 40px;
            display: grid;
            gap: 16px;
        }

        .ga-card {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 18px 40px rgba(31, 41, 55, 0.08);
            overflow: hidden;
        }

        .ga-body { padding: 18px; }

        .ga-hero {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .ga-eyebrow {
            margin: 0 0 6px;
            color: var(--brand);
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .ga-title {
            margin: 0;
            font-size: 30px;
            line-height: 1.1;
            color: #12261d;
        }

        .ga-copy {
            margin: 10px 0 0;
            color: var(--muted);
            max-width: 620px;
            font-size: 14px;
        }

        .ga-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .ga-badge-blue { background: #edf4ff; color: #2456a6; border-color: #cfe0ff; }
        .ga-badge-green { background: var(--success-soft); color: var(--success); border-color: #c5e7d1; }
        .ga-badge-amber { background: var(--warn-soft); color: var(--warn); border-color: #f0d19d; }
        .ga-badge-red { background: var(--danger-soft); color: var(--danger); border-color: #f4c3bf; }

        .ga-flash {
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid transparent;
            font-size: 14px;
        }

        .ga-flash-success { background: var(--success-soft); color: var(--success); border-color: #c5e7d1; }
        .ga-flash-error { background: var(--danger-soft); color: var(--danger); border-color: #f4c3bf; }

        .ga-grid {
            display: grid;
            gap: 12px;
        }

        .ga-grid-5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        .ga-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }

        .ga-stat,
        .ga-status-box,
        .ga-action-card,
        .ga-preview-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            padding: 14px;
        }

        .ga-label {
            display: block;
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .ga-value {
            font-size: 15px;
            font-weight: 700;
            word-break: break-word;
        }

        .ga-section-title {
            margin: 0 0 12px;
            font-size: 18px;
            color: #12261d;
        }

        .ga-preview {
            width: 100%;
            min-height: 340px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #f7faf7;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .ga-preview img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            background: #fff;
        }

        .ga-preview-empty {
            padding: 24px;
            color: var(--muted);
            text-align: center;
            font-size: 14px;
        }

        .ga-button {
            width: 100%;
            border: 1px solid transparent;
            border-radius: 12px;
            padding: 11px 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .ga-button-green { background: var(--success); color: #fff; }
        .ga-button-amber { background: var(--warn); color: #fff; }

        .ga-textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fffdfa;
            color: var(--ink);
            min-height: 120px;
            resize: vertical;
        }

        .ga-error {
            margin-top: 6px;
            color: var(--danger);
            font-size: 12px;
        }

        .ga-actions {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        @media (max-width: 820px) {
            .ga-grid-5,
            .ga-grid-2,
            .ga-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="ga-shell">
    @if(session('success'))
        <div class="ga-flash ga-flash-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="ga-flash ga-flash-error">{{ session('error') }}</div>
    @endif

    @php
        $badgeClass = match($pageStatus) {
            'approved' => 'ga-badge-green',
            'revision_requested' => 'ga-badge-amber',
            'expired', 'cancelled' => 'ga-badge-red',
            default => 'ga-badge-blue',
        };
    @endphp

    <div class="ga-card">
        <div class="ga-body">
            <div class="ga-hero">
                <div>
                    <p class="ga-eyebrow">{{ $tenantName }}</p>
                    <h1 class="ga-title">Grafik Tasarımınızı İnceleyin</h1>
                    <p class="ga-copy">Görsel uygunsa onaylayın. Değişiklik gerekiyorsa kısa bir revize notu yazın. Onay sonrası firma yetkilileri üretim hazırlığını tamamlayacaktır.</p>
                </div>
                <span class="ga-badge {{ $badgeClass }}">{{ $pageStatusLabel }}</span>
            </div>

            <div class="ga-status-box" style="margin-top:16px;">
                <strong class="ga-value" style="display:block; margin-bottom:6px;">{{ $pageStatusLabel }}</strong>
                <div class="ga-copy" style="margin-top:0;">{{ $pageMessage }}</div>
            </div>

            <div class="ga-grid ga-grid-5" style="margin-top:16px;">
                <div class="ga-stat">
                    <span class="ga-label">Sipariş No</span>
                    <div class="ga-value">{{ $graphic['order_number'] }}</div>
                </div>
                <div class="ga-stat">
                    <span class="ga-label">İş Formu No</span>
                    <div class="ga-value">{{ $graphic['work_form_number'] }}</div>
                </div>
                <div class="ga-stat">
                    <span class="ga-label">Ürün</span>
                    <div class="ga-value">{{ $graphic['product_name'] }}</div>
                </div>
                <div class="ga-stat">
                    <span class="ga-label">Baskı</span>
                    <div class="ga-value">{{ $graphic['print_label'] }}</div>
                </div>
                <div class="ga-stat">
                    <span class="ga-label">Durum</span>
                    <div class="ga-value">{{ $graphic['status_label'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="ga-card">
        <div class="ga-body">
            <h2 class="ga-section-title">Gönderilen Grafik</h2>
            <div class="ga-grid ga-grid-2">
                <div class="ga-preview-card">
                    <div class="ga-preview">
                        @if($graphic['attachment_preview_data_url'])
                            <img src="{{ $graphic['attachment_preview_data_url'] }}" alt="{{ $graphic['attachment_name'] }}">
                        @else
                            <div class="ga-preview-empty">
                                @if($graphic['attachment_missing'])
                                    Görsel bulunamadı.
                                @else
                                    Önizleme bu dosya tipi için gösterilemiyor.
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
                <div class="ga-preview-card">
                    <span class="ga-label">Dosya</span>
                    <div class="ga-value">{{ $graphic['attachment_name'] }}</div>

                    @if($graphic['customer_note'])
                        <div style="margin-top:16px;">
                            <span class="ga-label">Açıklama</span>
                            <div class="ga-copy" style="margin-top:0;">{{ $graphic['customer_note'] }}</div>
                        </div>
                    @endif

                    <div style="margin-top:16px;" class="ga-copy">
                        Görseli inceleyip onay verebilir veya revize talebinizi iletebilirsiniz.
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($canRespond)
        <div class="ga-card">
            <div class="ga-body">
                <h2 class="ga-section-title">Kararınızı Bildirin</h2>
                <div class="ga-actions">
                    <div class="ga-action-card">
                        <span class="ga-label">Onay</span>
                        <div class="ga-copy" style="margin-top:0;">Grafik sizin için uygunsa hemen onaylayabilirsiniz.</div>
                        <form method="POST" action="{{ route('public.graphics.approval.approve', ['token' => $request->token]) }}" style="margin-top:12px;">
                            @csrf
                            <input type="hidden" name="customer_note" value="">
                            <button type="submit" class="ga-button ga-button-green">Grafiği Onayla</button>
                        </form>
                    </div>

                    <div class="ga-action-card">
                        <span class="ga-label">Revize</span>
                        <div class="ga-copy" style="margin-top:0;">Değişmesini istediğiniz noktayı kısa bir notla iletebilirsiniz.</div>
                        <form method="POST" action="{{ route('public.graphics.approval.revision', ['token' => $request->token]) }}" style="margin-top:12px;">
                            @csrf
                            <label class="ga-label" for="revision_note">Revize Notu</label>
                            <textarea class="ga-textarea" id="revision_note" name="customer_note" placeholder="Örnek: Logo biraz daha yukarı alınsın.">{{ old('customer_note') }}</textarea>
                            @error('customer_note')
                                <div class="ga-error">{{ $message }}</div>
                            @enderror
                            <button type="submit" class="ga-button ga-button-amber" style="margin-top:10px;">Revize İste</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="ga-card">
        <div class="ga-body">
            <div class="ga-copy" style="margin-top:0;">Bu bağlantı yalnız grafik onay amacıyla hazırlanmıştır. Fiyat, cari bilgiler, maliyet kayıtları ve teknik dosya yolları bu ekranda yer almaz.</div>
        </div>
    </div>
</div>
</body>
</html>
