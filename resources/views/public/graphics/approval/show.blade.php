<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grafik Çalışmasını İncele</title>
    <style>
        .pd-public-graphic-approval {
            --ga-bg: #f5f7fb;
            --ga-surface: #ffffff;
            --ga-soft: #f8fafc;
            --ga-line: #dbe4f0;
            --ga-ink: #1f2937;
            --ga-muted: #64748b;
            --ga-brand: #2f6fed;
            --ga-brand-soft: #eaf1ff;
            --ga-success: #15803d;
            --ga-success-soft: #ecfdf3;
            --ga-warn: #b45309;
            --ga-warn-soft: #fff7ed;
            --ga-danger: #b91c1c;
            --ga-danger-soft: #fef2f2;
            margin: 0;
            background:
                radial-gradient(circle at top right, rgba(47, 111, 237, 0.08), transparent 28%),
                linear-gradient(180deg, #f8fbff 0%, var(--ga-bg) 100%);
            color: var(--ga-ink);
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }

        .pd-public-graphic-approval * { box-sizing: border-box; }
        .pd-public-graphic-approval__shell {
            max-width: 1160px;
            margin: 0 auto;
            padding: 24px 14px 40px;
            display: grid;
            gap: 14px;
        }

        .pd-public-graphic-approval__card {
            background: var(--ga-surface);
            border: 1px solid var(--ga-line);
            border-radius: 8px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .pd-public-graphic-approval__body { padding: 18px; }
        .pd-public-graphic-approval__hero {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }

        .pd-public-graphic-approval__eyebrow {
            margin: 0 0 6px;
            color: var(--ga-brand);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .pd-public-graphic-approval__title {
            margin: 0;
            color: #14233a;
            font-size: 28px;
            line-height: 1.12;
        }

        .pd-public-graphic-approval__copy,
        .pd-public-graphic-approval__note,
        .pd-public-graphic-approval__helper {
            color: var(--ga-muted);
            font-size: 14px;
        }

        .pd-public-graphic-approval__copy { margin: 10px 0 0; max-width: 680px; }
        .pd-public-graphic-approval__badge {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .pd-public-graphic-approval__badge--blue { background: #edf4ff; color: #2456a6; border-color: #cfe0ff; }
        .pd-public-graphic-approval__badge--green { background: var(--ga-success-soft); color: var(--ga-success); border-color: #c9ecd6; }
        .pd-public-graphic-approval__badge--amber { background: var(--ga-warn-soft); color: var(--ga-warn); border-color: #ffd7ad; }
        .pd-public-graphic-approval__badge--red { background: var(--ga-danger-soft); color: var(--ga-danger); border-color: #fecaca; }

        .pd-public-graphic-approval__flash {
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid transparent;
        }

        .pd-public-graphic-approval__flash--success { background: var(--ga-success-soft); color: var(--ga-success); border-color: #c9ecd6; }
        .pd-public-graphic-approval__flash--error { background: var(--ga-danger-soft); color: var(--ga-danger); border-color: #fecaca; }

        .pd-public-graphic-approval__status-box,
        .pd-public-graphic-approval__stat,
        .pd-public-graphic-approval__preview-card,
        .pd-public-graphic-approval__action-card,
        .pd-public-graphic-approval__reference-card {
            border: 1px solid var(--ga-line);
            border-radius: 8px;
            background: #fff;
            padding: 14px;
        }

        .pd-public-graphic-approval__status-box { margin-top: 16px; background: var(--ga-soft); }
        .pd-public-graphic-approval__stats {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
            margin-top: 16px;
        }

        .pd-public-graphic-approval__label {
            display: block;
            margin-bottom: 5px;
            color: var(--ga-muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .pd-public-graphic-approval__value {
            color: var(--ga-ink);
            font-size: 14px;
            font-weight: 700;
            word-break: break-word;
        }

        .pd-public-graphic-approval__layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 300px;
            gap: 14px;
            align-items: start;
        }

        .pd-public-graphic-approval__main,
        .pd-public-graphic-approval__sidebar {
            min-width: 0;
            display: grid;
            gap: 14px;
        }

        .pd-public-graphic-approval__sidebar {
            position: sticky;
            top: 16px;
        }

        .pd-public-graphic-approval__section-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 12px;
        }

        .pd-public-graphic-approval__section-title {
            margin: 0;
            color: #14233a;
            font-size: 18px;
            font-weight: 700;
        }

        .pd-public-graphic-approval__preview {
            position: relative;
            width: 100%;
            min-height: 460px;
            border-radius: 8px;
            border: 1px solid var(--ga-line);
            background: var(--ga-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .pd-public-graphic-approval__preview img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            background: #fff;
        }

        .pd-public-graphic-approval__preview-empty {
            padding: 28px;
            text-align: center;
            color: var(--ga-muted);
            font-size: 14px;
        }

        .pd-public-graphic-approval__preview-fallback[hidden] { display: none; }
        .pd-public-graphic-approval__preview-actions,
        .pd-public-graphic-approval__secondary-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .pd-public-graphic-approval__button,
        .pd-public-graphic-approval__button:visited {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid #cfd8e6;
            background: #fff;
            color: #21314a;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .pd-public-graphic-approval__button--primary { background: var(--ga-success); border-color: var(--ga-success); color: #fff; }
        .pd-public-graphic-approval__button--warn { background: var(--ga-warn); border-color: var(--ga-warn); color: #fff; }

        .pd-public-graphic-approval__meta-grid,
        .pd-public-graphic-approval__actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .pd-public-graphic-approval__textarea {
            width: 100%;
            min-height: 120px;
            border: 1px solid var(--ga-line);
            border-radius: 8px;
            padding: 10px 12px;
            background: #fffdfa;
            color: var(--ga-ink);
            font: inherit;
            resize: vertical;
        }

        .pd-public-graphic-approval__error {
            margin-top: 6px;
            color: var(--ga-danger);
            font-size: 12px;
        }

        .pd-public-graphic-approval__kv {
            display: grid;
            grid-template-columns: 110px 1fr;
            gap: 8px 10px;
            font-size: 13px;
        }

        .pd-public-graphic-approval__kv span { color: var(--ga-muted); }
        .pd-public-graphic-approval__kv strong { color: var(--ga-ink); text-align: right; }

        .pd-public-graphic-approval__reference {
            width: 100%;
            min-height: 180px;
            border-radius: 8px;
            border: 1px solid var(--ga-line);
            background: var(--ga-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .pd-public-graphic-approval__reference img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            background: #fff;
        }

        .pd-public-graphic-approval__modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.84);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 90;
        }

        .pd-public-graphic-approval__modal.is-open { display: flex; }
        .pd-public-graphic-approval__modal-dialog {
            width: min(96vw, 1500px);
            height: min(92vh, 980px);
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .pd-public-graphic-approval__modal-head,
        .pd-public-graphic-approval__modal-foot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--ga-line);
            background: #fff;
        }

        .pd-public-graphic-approval__modal-foot {
            border-bottom: 0;
            border-top: 1px solid var(--ga-line);
            justify-content: flex-end;
        }

        .pd-public-graphic-approval__modal-body {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: var(--ga-soft);
        }

        .pd-public-graphic-approval__modal-image {
            width: 100%;
            height: 100%;
            max-width: 100%;
            max-height: calc(92vh - 160px);
            object-fit: contain;
            background: #fff;
            border-radius: 8px;
            border: 1px solid var(--ga-line);
        }

        @media (max-width: 1180px) {
            .pd-public-graphic-approval__layout { grid-template-columns: 1fr; }
            .pd-public-graphic-approval__sidebar { position: static; top: auto; }
            .pd-public-graphic-approval__stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 820px) {
            .pd-public-graphic-approval__stats,
            .pd-public-graphic-approval__meta-grid,
            .pd-public-graphic-approval__actions { grid-template-columns: 1fr; }
            .pd-public-graphic-approval__hero,
            .pd-public-graphic-approval__section-head,
            .pd-public-graphic-approval__modal-head,
            .pd-public-graphic-approval__modal-foot { flex-direction: column; align-items: flex-start; }
            .pd-public-graphic-approval__preview { min-height: 320px; }
            .pd-public-graphic-approval__kv { grid-template-columns: 1fr; }
            .pd-public-graphic-approval__kv strong { text-align: left; }
        }
    </style>
</head>
<body class="pd-public-graphic-approval">
<div class="pd-public-graphic-approval__shell">
    @if(session('success'))
        <div class="pd-public-graphic-approval__flash pd-public-graphic-approval__flash--success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="pd-public-graphic-approval__flash pd-public-graphic-approval__flash--error">{{ session('error') }}</div>
    @endif

    @php
        $badgeClass = match($pageStatus) {
            'approved' => 'pd-public-graphic-approval__badge--green',
            'revision_requested' => 'pd-public-graphic-approval__badge--amber',
            'expired', 'cancelled' => 'pd-public-graphic-approval__badge--red',
            default => 'pd-public-graphic-approval__badge--blue',
        };
    @endphp

    <div class="pd-public-graphic-approval__card">
        <div class="pd-public-graphic-approval__body">
            <div class="pd-public-graphic-approval__hero">
                <div>
                    <p class="pd-public-graphic-approval__eyebrow">{{ $tenantName }}</p>
                    <h1 class="pd-public-graphic-approval__title">Grafik Çalışmasını İncele</h1>
                    <p class="pd-public-graphic-approval__copy">Gönderilen grafik çalışmasını inceleyin. Uygunsa onaylayın; değişiklik gerekiyorsa kısa revize notunuzu iletin.</p>
                </div>
                <span class="pd-public-graphic-approval__badge {{ $badgeClass }}">{{ $pageStatusLabel }}</span>
            </div>

            <div class="pd-public-graphic-approval__status-box">
                <strong class="pd-public-graphic-approval__value" style="display:block; margin-bottom:6px;">{{ $pageStatusLabel }}</strong>
                <div class="pd-public-graphic-approval__note">{{ $pageMessage }}</div>
            </div>

            <div class="pd-public-graphic-approval__stats">
                <div class="pd-public-graphic-approval__stat">
                    <span class="pd-public-graphic-approval__label">Müşteri / Firma</span>
                    <div class="pd-public-graphic-approval__value">{{ $graphic['company_name'] }}</div>
                </div>
                <div class="pd-public-graphic-approval__stat">
                    <span class="pd-public-graphic-approval__label">Sipariş No</span>
                    <div class="pd-public-graphic-approval__value">{{ $graphic['order_number'] }}</div>
                </div>
                <div class="pd-public-graphic-approval__stat">
                    <span class="pd-public-graphic-approval__label">İş Formu No</span>
                    <div class="pd-public-graphic-approval__value">{{ $graphic['work_form_number'] }}</div>
                </div>
                <div class="pd-public-graphic-approval__stat">
                    <span class="pd-public-graphic-approval__label">Ürün / SKU</span>
                    <div class="pd-public-graphic-approval__value">{{ $graphic['product_name'] }}</div>
                    <div class="pd-public-graphic-approval__helper">{{ $graphic['product_code'] }}</div>
                </div>
                <div class="pd-public-graphic-approval__stat">
                    <span class="pd-public-graphic-approval__label">Miktar</span>
                    <div class="pd-public-graphic-approval__value">{{ $graphic['quantity'] }}</div>
                </div>
                <div class="pd-public-graphic-approval__stat">
                    <span class="pd-public-graphic-approval__label">Baskı</span>
                    <div class="pd-public-graphic-approval__value">{{ $graphic['print_label'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="pd-public-graphic-approval__layout">
        <div class="pd-public-graphic-approval__main">
            <div class="pd-public-graphic-approval__card">
                <div class="pd-public-graphic-approval__body">
                    <div class="pd-public-graphic-approval__section-head">
                        <div>
                            <h2 class="pd-public-graphic-approval__section-title">Grafik Çalışması</h2>
                            <div class="pd-public-graphic-approval__note">Onaya gönderilen müşteri görünür grafik burada gösterilir.</div>
                        </div>
                        <div class="pd-public-graphic-approval__preview-actions">
                            @if($graphic['attachment_original_url'])
                                <button type="button" class="pd-public-graphic-approval__button" data-public-graphic-lightbox-trigger data-lightbox-src="{{ $graphic['attachment_original_url'] }}" data-lightbox-title="{{ $graphic['attachment_name'] }}" data-lightbox-open-url="{{ $graphic['attachment_original_url'] }}">Büyük Önizleme</button>
                            @endif
                            @if($graphic['attachment_original_url'])
                                <a href="{{ $graphic['attachment_original_url'] }}" target="_blank" rel="noopener" class="pd-public-graphic-approval__button">Orijinal Boyutta Aç</a>
                            @endif
                        </div>
                    </div>

                    <div class="pd-public-graphic-approval__preview-card">
                        <div class="pd-public-graphic-approval__preview">
                            @if(($graphic['attachment_original_url'] ?? $graphic['attachment_preview_url']) && $graphic['attachment_is_image'])
                                <img src="{{ $graphic['attachment_original_url'] ?? $graphic['attachment_preview_url'] }}" alt="{{ $graphic['attachment_name'] }}" data-public-graphic-main-image>
                                <div class="pd-public-graphic-approval__preview-empty pd-public-graphic-approval__preview-fallback" data-public-graphic-fallback hidden>Görsel şu anda yüklenemedi.</div>
                            @elseif($graphic['attachment_original_url'] ?? $graphic['attachment_preview_url'])
                                <div class="pd-public-graphic-approval__preview-empty">Bu dosya tipi büyük önizleme yerine güvenli açma bağlantısıyla görüntülenir.</div>
                            @else
                                <div class="pd-public-graphic-approval__preview-empty">
                                    @if($graphic['attachment_missing'])
                                        Görsel dosyasına şu anda erişilemiyor.
                                    @else
                                        Henüz müşteriye açılmış bir grafik bulunmuyor.
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="pd-public-graphic-approval__meta-grid" style="margin-top:12px;">
                        <div class="pd-public-graphic-approval__preview-card">
                            <span class="pd-public-graphic-approval__label">Gönderilen Dosya</span>
                            <div class="pd-public-graphic-approval__value">{{ $graphic['attachment_name'] }}</div>
                            <div class="pd-public-graphic-approval__helper">{{ $graphic['attachment_uploaded_at'] ?: 'Yüklenme zamanı yok' }}</div>
                        </div>
                        <div class="pd-public-graphic-approval__preview-card">
                            <span class="pd-public-graphic-approval__label">Grafik Notu</span>
                            <div class="pd-public-graphic-approval__value">{{ $graphic['customer_note'] ?: 'Ek müşteri notu bulunmuyor.' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            @if($canRespond)
                <div class="pd-public-graphic-approval__card">
                    <div class="pd-public-graphic-approval__body">
                        <h2 class="pd-public-graphic-approval__section-title">Kararınızı Bildirin</h2>
                        <div class="pd-public-graphic-approval__actions" style="margin-top:12px;">
                            <div class="pd-public-graphic-approval__action-card">
                                <span class="pd-public-graphic-approval__label">Onayla</span>
                                <div class="pd-public-graphic-approval__note">Grafik sizin için uygunsa tek adımda onay verebilirsiniz.</div>
                                <form method="POST" style="margin-top:12px;">
                                    @csrf
                                    <input type="hidden" name="decision" value="approve">
                                    <input type="hidden" name="customer_note" value="">
                                    <button type="submit" class="pd-public-graphic-approval__button pd-public-graphic-approval__button--primary">Grafiği Onayla</button>
                                </form>
                            </div>
                            <div class="pd-public-graphic-approval__action-card">
                                <span class="pd-public-graphic-approval__label">Revize İste</span>
                                <div class="pd-public-graphic-approval__note">Değişmesini istediğiniz noktayı kısa ve net biçimde yazabilirsiniz.</div>
                                <form method="POST" style="margin-top:12px;">
                                    @csrf
                                    <input type="hidden" name="decision" value="revision">
                                    <label class="pd-public-graphic-approval__label" for="revision_note">Revize Notu</label>
                                    <textarea class="pd-public-graphic-approval__textarea" id="revision_note" name="customer_note" placeholder="Örnek: Logo biraz daha yukarı alınsın.">{{ old('customer_note') }}</textarea>
                                    @error('customer_note')
                                        <div class="pd-public-graphic-approval__error">{{ $message }}</div>
                                    @enderror
                                    <button type="submit" class="pd-public-graphic-approval__button pd-public-graphic-approval__button--warn" style="margin-top:10px;">Revize İste</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="pd-public-graphic-approval__sidebar">
            <div class="pd-public-graphic-approval__card">
                <div class="pd-public-graphic-approval__body">
                    <h2 class="pd-public-graphic-approval__section-title">Sipariş Bağlamı</h2>
                    <div class="pd-public-graphic-approval__kv">
                        <span>Müşteri</span><strong>{{ $graphic['company_name'] }}</strong>
                        <span>Ürün</span><strong>{{ $graphic['product_name'] }}</strong>
                        <span>SKU</span><strong>{{ $graphic['product_code'] }}</strong>
                        <span>Miktar</span><strong>{{ $graphic['quantity'] }}</strong>
                        <span>Baskı Anahtarı</span><strong>{{ $graphic['print_label'] }}</strong>
                        <span>Baskı Türü</span><strong>{{ $graphic['print_type'] }}</strong>
                        <span>Son Güncelleme</span><strong>{{ $graphic['updated_at'] ?: '-' }}</strong>
                        <span>Onay Durumu</span><strong>{{ $graphic['status_label'] }}</strong>
                    </div>
                </div>
            </div>

            <div class="pd-public-graphic-approval__card">
                <div class="pd-public-graphic-approval__body">
                    <h2 class="pd-public-graphic-approval__section-title">Ürün Referansı</h2>
                    <div class="pd-public-graphic-approval__reference-card">
                        <div class="pd-public-graphic-approval__reference">
                            @if($graphic['reference_image_url'])
                                <img src="{{ $graphic['reference_image_url'] }}" alt="{{ $graphic['reference_image_title'] }}">
                            @else
                                <div class="pd-public-graphic-approval__preview-empty">Ürün referans görseli bulunmuyor.</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="pd-public-graphic-approval__card">
                <div class="pd-public-graphic-approval__body">
                    <div class="pd-public-graphic-approval__note">Bu sayfa yalnız grafik onayı içindir. Fiyat, maliyet, cari, tedarik ve teknik dosya yolu bilgileri burada gösterilmez.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="pd-public-graphic-approval__modal" data-public-graphic-lightbox>
    <div class="pd-public-graphic-approval__modal-dialog">
        <div class="pd-public-graphic-approval__modal-head">
            <div>
                <div class="pd-public-graphic-approval__label">Büyük Önizleme</div>
                <div class="pd-public-graphic-approval__value" data-public-graphic-lightbox-title>Grafik Çalışması</div>
            </div>
            <button type="button" class="pd-public-graphic-approval__button" data-public-graphic-lightbox-close>Kapat</button>
        </div>
        <div class="pd-public-graphic-approval__modal-body">
            <img src="" alt="" class="pd-public-graphic-approval__modal-image" data-public-graphic-lightbox-image>
        </div>
        <div class="pd-public-graphic-approval__modal-foot">
            <a href="#" target="_blank" rel="noopener" class="pd-public-graphic-approval__button" data-public-graphic-lightbox-open-original hidden>Orijinal Boyutta Aç</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const image = document.querySelector('[data-public-graphic-main-image]');
    const fallback = document.querySelector('[data-public-graphic-fallback]');
    const lightbox = document.querySelector('[data-public-graphic-lightbox]');
    const lightboxImage = lightbox?.querySelector('[data-public-graphic-lightbox-image]');
    const lightboxTitle = lightbox?.querySelector('[data-public-graphic-lightbox-title]');
    const lightboxOpenOriginal = lightbox?.querySelector('[data-public-graphic-lightbox-open-original]');

    if (image && fallback) {
        image.addEventListener('error', function () {
            image.hidden = true;
            fallback.hidden = false;
        });
    }

    document.querySelectorAll('[data-public-graphic-lightbox-trigger]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            if (!lightbox || !lightboxImage || !lightboxTitle) {
                return;
            }

            const src = trigger.getAttribute('data-lightbox-src');
            const title = trigger.getAttribute('data-lightbox-title') || 'Grafik Çalışması';
            const openUrl = trigger.getAttribute('data-lightbox-open-url');

            if (!src) {
                return;
            }

            lightboxImage.src = src;
            lightboxImage.alt = title;
            lightboxTitle.textContent = title;

            if (lightboxOpenOriginal) {
                if (openUrl) {
                    lightboxOpenOriginal.href = openUrl;
                    lightboxOpenOriginal.hidden = false;
                } else {
                    lightboxOpenOriginal.href = '#';
                    lightboxOpenOriginal.hidden = true;
                }
            }

            lightbox.classList.add('is-open');
        });
    });

    lightbox?.addEventListener('click', function (event) {
        if (event.target === lightbox || event.target.hasAttribute('data-public-graphic-lightbox-close')) {
            lightbox.classList.remove('is-open');
            if (lightboxImage) {
                lightboxImage.src = '';
            }
            if (lightboxOpenOriginal) {
                lightboxOpenOriginal.href = '#';
                lightboxOpenOriginal.hidden = true;
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && lightbox?.classList.contains('is-open')) {
            lightbox.classList.remove('is-open');
            if (lightboxImage) {
                lightboxImage.src = '';
            }
            if (lightboxOpenOriginal) {
                lightboxOpenOriginal.href = '#';
                lightboxOpenOriginal.hidden = true;
            }
        }
    });
});
</script>
</body>
</html>
