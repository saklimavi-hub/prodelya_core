<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sipariş Takibi</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --card: #ffffff;
            --line: #d9dee5;
            --muted: #667085;
            --text: #172033;
            --blue: #2563eb;
            --green: #15803d;
            --amber: #b45309;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.45;
        }

        .pt-shell {
            max-width: 860px;
            margin: 0 auto;
            padding: 18px 14px 28px;
            display: grid;
            gap: 14px;
        }

        .pt-card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .pt-card-body {
            padding: 16px;
        }

        .pt-title {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
        }

        .pt-muted {
            color: var(--muted);
            font-size: 13px;
        }

        .pt-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            border: 1px solid transparent;
        }

        .pt-badge-blue { background: #e8f0ff; color: var(--blue); border-color: #cfe0ff; }
        .pt-badge-green { background: #eaf8ef; color: var(--green); border-color: #cdecd6; }
        .pt-badge-amber { background: #fff4df; color: var(--amber); border-color: #f6dfb3; }

        .pt-grid-4,
        .pt-grid-2,
        .pt-timeline,
        .pt-print-grid,
        .pt-attachment-grid,
        .pt-history {
            display: grid;
            gap: 12px;
        }

        .pt-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .pt-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .pt-timeline { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        .pt-print-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .pt-attachment-grid { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }

        .pt-stat,
        .pt-tile,
        .pt-print-card,
        .pt-attachment-card,
        .pt-history-row {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
        }

        .pt-stat,
        .pt-tile,
        .pt-print-card,
        .pt-attachment-card,
        .pt-history-row {
            padding: 12px;
        }

        .pt-label {
            display: block;
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 12px;
        }

        .pt-value,
        .pt-print-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }

        .pt-product {
            display: grid;
            grid-template-columns: 88px 1fr;
            gap: 14px;
            align-items: start;
        }

        .pt-product-image,
        .pt-product-placeholder {
            width: 88px;
            height: 88px;
            border-radius: 12px;
            border: 1px solid var(--line);
            overflow: hidden;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        .pt-product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .pt-attachment-card img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--line);
            margin-bottom: 8px;
        }

        .pt-link {
            color: var(--blue);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .pt-section-title {
            margin: 0 0 10px;
            font-size: 16px;
            font-weight: 600;
        }

        @media (max-width: 720px) {
            .pt-grid-4,
            .pt-grid-2,
            .pt-timeline,
            .pt-print-grid,
            .pt-product {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="pt-shell">
    <div class="pt-card">
        <div class="pt-card-body">
            <div class="flex items-start justify-between gap-3" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div>
                    <div class="pt-muted">{{ $tenantName }}</div>
                    <h1 class="pt-title">Sipariş Takibi</h1>
                    <div class="pt-muted">Müşteri Takip Ekranı üzerinden siparişinizin güncel aşamasını görüntüleyebilirsiniz.</div>
                </div>
                <span class="pt-badge pt-badge-blue">{{ $workForm['status'] }}</span>
            </div>

            <div class="pt-grid-4" style="margin-top:14px;">
                <div class="pt-stat">
                    <span class="pt-label">İş Formu No</span>
                    <div class="pt-value">{{ $workForm['number'] }}</div>
                </div>
                <div class="pt-stat">
                    <span class="pt-label">Sipariş No</span>
                    <div class="pt-value">{{ $order['document_number'] }}</div>
                </div>
                <div class="pt-stat">
                    <span class="pt-label">Versiyon</span>
                    <div class="pt-value">v{{ $workForm['version'] }}</div>
                </div>
                <div class="pt-stat">
                    <span class="pt-label">Son Güncelleme</span>
                    <div class="pt-value">{{ $workForm['last_updated_at'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="pt-card">
        <div class="pt-card-body">
            <h2 class="pt-section-title">Sipariş Özeti</h2>
            <div class="pt-product">
                @if(filled($product['image_url']))
                    <div class="pt-product-image"><img src="{{ $product['image_url'] }}" alt="Ürün görseli"></div>
                @else
                    <div class="pt-product-placeholder">Görsel yok</div>
                @endif
                <div class="pt-grid-2">
                    <div class="pt-stat">
                        <span class="pt-label">Ürün Adı</span>
                        <div class="pt-value">{{ $product['name'] }}</div>
                    </div>
                    <div class="pt-stat">
                        <span class="pt-label">Ürün Kodu</span>
                        <div class="pt-value">{{ $product['code'] }}</div>
                    </div>
                    <div class="pt-stat">
                        <span class="pt-label">Miktar</span>
                        <div class="pt-value">{{ $product['quantity'] }}</div>
                    </div>
                    <div class="pt-stat">
                        <span class="pt-label">Teslimat Tipi</span>
                        <div class="pt-value">{{ $order['delivery_type'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="pt-card">
        <div class="pt-card-body">
            <h2 class="pt-section-title">Siparişiniz şu aşamada</h2>
            <div class="pt-grid-2">
                <div class="pt-stat">
                    <span class="pt-label">Hazırlık Durumu</span>
                    <div class="pt-value">{{ $procurement['status'] }}</div>
                </div>
                <div class="pt-stat">
                    <span class="pt-label">Bilgi</span>
                    <div class="pt-value">Siparişiniz için müşteriye açık süreç özeti burada paylaşılır.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="pt-card">
        <div class="pt-card-body">
            <h2 class="pt-section-title">Üretim ve Teslimat</h2>
            <div class="pt-grid-2">
                <div class="pt-stat">
                    <span class="pt-label">Üretim Durumu</span>
                    <div class="pt-value">{{ $production['status'] }}</div>
                </div>
                <div class="pt-stat">
                    <span class="pt-label">Teslimat Bilgisi</span>
                    <div class="pt-value">{{ data_get($timeline, '4.status', 'Teslimat bekliyor') }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="pt-card">
        <div class="pt-card-body">
            <h2 class="pt-section-title">Sipariş Süreci</h2>
            <div class="pt-timeline">
                @foreach($timeline as $step)
                    <div class="pt-tile">
                        <span class="pt-label">{{ $step['title'] }}</span>
                        <div class="pt-value">{{ $step['status'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="pt-card">
        <div class="pt-card-body">
            <h2 class="pt-section-title">Baskı Bilgileri</h2>
            <div class="pt-print-grid">
                @forelse($prints as $print)
                    <div class="pt-print-card">
                        <div class="pt-print-title">{{ $print['type'] }}</div>
                        <div class="pt-muted">{{ $print['option'] }}</div>
                        <div class="pt-muted" style="margin-top:6px;">{{ $print['quantity'] }}</div>
                        @if(filled($print['note']))
                            <div class="pt-muted" style="margin-top:8px;">{{ $print['note'] }}</div>
                        @endif
                    </div>
                @empty
                    <div class="pt-muted">Bu kalem için müşteriye açık baskı bilgisi bulunmuyor.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="pt-card">
        <div class="pt-card-body">
            <h2 class="pt-section-title">Müşteri Dosyaları</h2>
            <div class="pt-attachment-grid">
                @forelse($attachments as $attachment)
                    <div class="pt-attachment-card">
                        @if($attachment['is_image'] && $attachment['url'])
                            <img src="dosya/{{ $attachment['id'] }}" alt="{{ $attachment['file_name'] }}">
                        @endif
                        <div class="pt-value">{{ $attachment['file_name'] }}</div>
                        <div class="pt-muted">{{ $attachment['visibility_label'] }}</div>
                        @if(filled($attachment['note']))
                            <div class="pt-muted" style="margin-top:6px;">{{ $attachment['note'] }}</div>
                        @endif
                        @if($attachment['url'])
                            <div style="margin-top:8px;"><a href="dosya/{{ $attachment['id'] }}" class="pt-link" target="_blank" rel="noopener">{{ $attachment['is_document'] ? 'Aç / İndir' : 'Aç' }}</a></div>
                        @endif
                    </div>
                @empty
                    <div class="pt-muted">Müşteriye açık dosya henüz paylaşılmadı.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="pt-card">
        <div class="pt-card-body">
            <h2 class="pt-section-title">Paylaşılan Güncellemeler</h2>
            <div class="pt-history">
                @forelse($activityLogs as $log)
                    <div class="pt-history-row">
                        <div class="pt-label">{{ $log['at'] }}</div>
                        <div class="pt-value">{{ $log['label'] }}</div>
                        @if(filled($log['note']))
                            <div class="pt-muted" style="margin-top:6px;">{{ $log['note'] }}</div>
                        @endif
                    </div>
                @empty
                    <div class="pt-muted">Müşteriye açık işlem geçmişi henüz paylaşılmadı.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="pt-card">
        <div class="pt-card-body">
            <div class="pt-muted">Bu ekran yalnız sipariş süreci ve paylaşılan dosyaları gösterir. Fiyat ve finans detayları için firma yetkilinizle iletişime geçebilirsiniz.</div>
        </div>
    </div>
</div>
</body>
</html>
