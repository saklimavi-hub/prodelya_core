@extends('layouts.prodelya-admin')

@section('title', 'Kaynak Önizleme')
@section('page_title', 'Kaynak Önizleme')
@section('page_subtitle', $source->source_name . ' - ' . $source->supplier->name)

@section('content')
@php
    $profileKey = $parserResult['profile_key'] ?? null;
    $currentQuery = request()->query();
    $selectedLimit = in_array($requestedLimit, array_keys($availableLimits), true) ? $requestedLimit : '50';
    $selectedFilter = in_array($activeFilter, array_keys($availableFilters), true) ? $activeFilter : 'all';
    $formatLabel = strtoupper($parserResult['content_type'] ?? ($source->config['format'] ?? $source->source_type));
    $lastPreviewAt = $sourceSummary['last_preview_at'] ? \Carbon\Carbon::parse($sourceSummary['last_preview_at'])->format('d.m.Y H:i') : '-';
    $lastFetchAt = $sourceSummary['last_fetch_at'] ? \Carbon\Carbon::parse($sourceSummary['last_fetch_at'])->format('d.m.Y H:i') : '-';
    $nextStepLabel = match (true) {
        $sourceMode !== 'live_source' => 'Kaynak bağlantısını kontrol edin',
        !empty($mappingWarnings) => 'Zorunlu alan eşlemelerini tamamlayın',
        !$canStagePreview => 'Önce zorunlu eksikleri giderin',
        default => 'Alan eşlemeyi gözden geçirip sonraki uygulama adımına geçin',
    };
    $warningBadges = static function (array $item) use ($profileKey): array {
        $badges = [];
        if (!empty($item['net_price_warning'])) {
            $badges[] = ['label' => 'Net fiyat uyarısı', 'tone' => 'pd-badge-amber'];
        }
        if (!empty($item['supplier_warning_flag'])) {
            $badge = match ($profileKey ?? null) {
                'ETKIN' => ['label' => 'Kırmızı Ürün', 'tone' => 'pd-badge-red'],
                'YENI-NESIL' => ['label' => 'Turuncu Ürün', 'tone' => 'pd-badge-amber'],
                default => null,
            };
            if ($badge) {
                $badges[] = $badge;
            }
        }
        if (!empty($item['price_policy_warning'])) {
            $badges[] = ['label' => 'Fiyat kontrolü gerekli', 'tone' => 'pd-badge-amber'];
        }
        if (!empty($item['critical_issue_count'])) {
            $badges[] = ['label' => 'Kritik hata', 'tone' => 'pd-badge-red'];
        }
        if (!empty($item['missing_price'])) {
            $badges[] = ['label' => 'Fiyat eksik', 'tone' => 'pd-badge-red'];
        }
        if (!empty($item['missing_image'])) {
            $badges[] = ['label' => 'Görsel eksik', 'tone' => 'pd-badge-amber'];
        }
        if (!empty($item['has_parse_error'])) {
            $badges[] = ['label' => 'Parse hatası', 'tone' => 'pd-badge-red'];
        }
        if (!empty($item['derived_product_code'])) {
            $badges[] = ['label' => 'Kod türetildi', 'tone' => 'pd-badge-blue'];
        }
        if (!empty($item['image_fallback_used'])) {
            $badges[] = ['label' => 'Fallback görsel', 'tone' => 'pd-badge-blue'];
        }

        return $badges;
    };
    $priceInfoMessage = static function (string $profileKey, array $item): ?string {
        return match ($profileKey) {
            'YENI-NESIL' => filled($item['list_price'] ?? null) ? 'Yeni Nesil fiyat alanı liste fiyatı olarak yorumlandı.' : null,
            'AKDENIZ' => !empty($item['net_price_warning'])
                ? 'Bu ürün net fiyatlı olabilir. Teklif/sipariş sırasında standart iskonto uygulanmamalı; gerekirse birim satış fiyatı artırılarak çalışılmalıdır.'
                : (filled($item['list_price'] ?? null) ? 'Akdeniz liste fiyatı satış referansı olarak kullanıldı.' : null),
            'ILPEN' => !empty($item['image_fallback_used']) ? 'Varyasyon görseli gelmedi, ana ürün görseli kullanıldı.' : null,
            'ETKIN' => !empty($item['price_policy_warning']) ? 'Bu ürünün fiyat alanı satış kararı öncesinde kontrol edilmelidir.' : null,
            default => null,
        };
    };
    $formatMoney = static function ($value): string {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2, ',', '.');
    };
    $formatPercent = static function ($value): string {
        if ($value === null || $value === '') {
            return '-';
        }

        return '%' . rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    };
    $resolveStockValue = static function (array $item, array $candidates): ?float {
        foreach ($candidates as $field) {
            if (array_key_exists($field, $item) && $item[$field] !== null && $item[$field] !== '') {
                return (float) $item[$field];
            }
        }

        $normalizedPayload = $item['normalized_payload'] ?? [];
        if (is_array($normalizedPayload)) {
            foreach ($candidates as $field) {
                if (array_key_exists($field, $normalizedPayload) && $normalizedPayload[$field] !== null && $normalizedPayload[$field] !== '') {
                    return (float) $normalizedPayload[$field];
                }
            }
        }

        return null;
    };
    $formatStock = static function (?float $value): string {
        return $value === null
            ? 'Stok bilgisi gelmedi'
            : 'Stok: ' . number_format($value, 0, ',', '.');
    };
    $metricCards = [
        ['label' => 'Toplam okunan', 'value' => $stats['records_read'], 'note' => 'Kaynak akışında okunan satır', 'class' => 'pd-hub-metric-info'],
        ['label' => 'Gösterilen', 'value' => $stats['displayed_row_count'], 'note' => 'Filtre ve limit sonrası görünüm', 'class' => 'pd-hub-metric-success'],
        ['label' => 'Ürün', 'value' => $stats['product_count'], 'note' => 'Ana ürün kartı sayısı', 'class' => 'pd-hub-metric-info'],
        ['label' => 'Varyant', 'value' => $stats['variant_count'], 'note' => 'Varyasyon satırı sayısı', 'class' => 'pd-hub-metric-info'],
        ['label' => 'Kritik hata', 'value' => $stats['critical_issue_count'], 'note' => 'İş akışını durdurabilecek kayıtlar', 'class' => 'pd-hub-metric-danger'],
        ['label' => 'Kontrol gerekli', 'value' => $stats['review_issue_count'], 'note' => 'Ticari veya içeriksel uyarılar', 'class' => 'pd-hub-metric-warn'],
    ];
    $qualityMetrics = [
        ['label' => 'Fiyat eksik', 'value' => $stats['missing_price_count']],
        ['label' => 'Görsel eksik', 'value' => $stats['missing_image_count']],
        ['label' => 'Kategori eksik', 'value' => $stats['missing_category_count']],
        ['label' => 'Ürün kodu eksik', 'value' => $stats['missing_product_code_count']],
        ['label' => 'Varyant kodu eksik', 'value' => $stats['missing_variant_code_count']],
        ['label' => 'Net fiyat uyarılı', 'value' => $stats['net_price_warning_count']],
        ['label' => 'Tedarikçi uyarılı', 'value' => $stats['supplier_warning_count']],
        ['label' => 'Bilgi uyarısı', 'value' => $stats['info_issue_count']],
        ['label' => 'Parse hatası', 'value' => $stats['parse_error_count']],
        ['label' => 'Uyarılı ürün', 'value' => $stats['warning_product_count']],
    ];
    $toolbarFilters = [
        'all' => 'Tümü',
        'warning' => 'Uyarılı',
        'missing-image' => 'Görseli eksik',
        'missing-price' => 'Fiyatı eksik',
        'net-price-warning' => 'Net fiyat uyarılı',
        'supplier-warning' => 'Kırmızı / Turuncu uyarılı',
        'parse-error' => 'Parse hatalı',
    ];
    $setupSteps = [
        [
            'title' => 'Kaynak Bilgisi',
            'status' => filled($source->url) || filled(data_get($source->config, 'source_file_path')) ? 'Tamam' : 'Eksik',
            'tone' => filled($source->url) || filled(data_get($source->config, 'source_file_path')) ? 'pd-badge-green' : 'pd-badge-amber',
            'note' => 'Bağlantı ve güncelleme ayarı tanımlı olmalı.',
        ],
        [
            'title' => 'Ön Kontrol',
            'status' => $sourceMode === 'live_source' ? 'Hazır' : 'Kontrol',
            'tone' => $sourceMode === 'live_source' ? 'pd-badge-green' : 'pd-badge-amber',
            'note' => '5-10 örnek ürün üzerinden veri kalitesini doğrulayın.',
        ],
        [
            'title' => 'Alan Eşleme',
            'status' => empty($mappingWarnings) ? 'Hazır' : 'Eksik',
            'tone' => empty($mappingWarnings) ? 'pd-badge-green' : 'pd-badge-amber',
            'note' => 'Ürün kodu, ürün adı, kategori, fiyat, stok ve görsel alanları eksiksiz olmalı.',
        ],
        [
            'title' => 'İlk Kategori Eşleme',
            'status' => ($stats['missing_category_count'] ?? 0) === 0 ? 'Hazır' : 'Bekliyor',
            'tone' => ($stats['missing_category_count'] ?? 0) === 0 ? 'pd-badge-green' : 'pd-badge-amber',
            'note' => 'Kategori eşlenmeyen ürünler satış listesine otomatik açılmaz.',
        ],
        [
            'title' => 'Kaynağı Aktif Et / Senkron',
            'status' => $source->status === 'active' ? 'Aktif' : 'Pasif',
            'tone' => $source->status === 'active' ? 'pd-badge-green' : 'pd-badge-gray',
            'note' => 'Uygun ürünler bundan sonra otomatik senkronla Abone Firma ürün listesine ve teklif seçimine yansır.',
        ],
    ];
@endphp

<div class="pd-page-shell pd-hub-preview-shell">
    <section class="pd-card pd-hero-card pd-hub-hero mb-6">
        <div class="pd-card-body">
            <div class="pd-hub-hero-main">
                <div class="pd-hub-hero-icon">PDH</div>
                <div class="pd-hub-hero-copy">
                    <div class="pd-hub-hero-title">Kaynak Önizleme</div>
                    <div class="pd-hub-hero-subtitle">Bu ekran kaynağın ilk kayıtlarını okur ve alanların doğru gelip gelmediğini kontrol eder. Bu adım birleşik kurulum akışındaki ön kontroldür; veri yazmadan önce örnek değerleri inceleyin.</div>
                    <div class="pd-hub-pill-row mt-3">
                        <span class="pd-badge pd-badge-blue">{{ $sourceSummary['supplier_name'] }}</span>
                        <span class="pd-badge pd-badge-gray">Kaynak #{{ $sourceSummary['source_id'] }}</span>
                        <span class="pd-badge pd-badge-purple">{{ $sourceSummary['profile_key'] ?? '-' }}</span>
                        <span class="pd-badge {{ $sourceMode === 'live_source' ? 'pd-badge-green' : 'pd-badge-amber' }}">
                            {{ $sourceMode === 'live_source' ? 'Canlı kaynak okundu' : 'Demo veri gösteriliyor' }}
                        </span>
                        <span class="pd-badge pd-badge-gray">{{ $formatLabel }}</span>
                        <span class="pd-badge pd-badge-gray">{{ $selectedLimit === 'all' ? 'Tüm veri görünümü' : 'Limitli görünüm' }}</span>
                        <span class="pd-badge pd-badge-gray">Super Admin kontrolü</span>
                    </div>
                </div>
            </div>
            <div class="pd-hub-hero-actions">
                <form action="{{ route('admin.super.product-data-hub.sources.preview', $source) }}" method="GET">
                    <input type="hidden" name="limit" value="{{ $selectedLimit }}">
                    <input type="hidden" name="filter" value="{{ $selectedFilter }}">
                    <button type="submit" class="pd-btn pd-btn-primary">Ön Kontrol Yap</button>
                </form>
                <a href="{{ route('admin.super.product-data-hub.field-mappings.source', $source) }}" class="pd-btn pd-btn-light">Eşlemeyi Kaydet</a>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index', ['supplier_id' => $source->supplier_id]) }}" class="pd-btn pd-btn-light">Kategorileri Eşle</a>
                <a href="{{ route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id, 'review_only' => 1]) }}" class="pd-btn pd-btn-light">Bekleyen Kontrolleri Aç</a>
                <a href="{{ route('admin.super.product-data-hub.sources.edit', $source) }}" class="pd-btn pd-btn-light">Kaynağı Düzenle</a>
                <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Kaynaklara Dön</a>
            </div>
        </div>
    </section>

    <section class="pd-card pd-section-card mb-6 pd-product-hub__setup-flow">
        <div class="pd-card-body">
            <div class="pd-hub-section-head">
                <div>
                    <div class="pd-hub-section-title">Birleşik Kurulum Akışı</div>
                    <div class="pd-hub-section-copy">Bu ekran ön kontrol adımıdır. Alan eşleme ve ilk kategori eşleme tamamlanınca uygun ürünler ek işlem gerekmeden otomatik senkron akışına katılır. Yalnız eksik veya şüpheli kayıtlar Bekleyen Kontroller alanına düşer.</div>
                </div>
            </div>
            <div class="pd-grid pd-grid-3">
                @foreach($setupSteps as $step)
                    <div class="pd-note pd-product-hub__setup-step">
                        <div class="pd-inline-wrap-sm pd-gap-bottom-sm">
                            <strong>{{ $step['title'] }}</strong>
                            <span class="pd-badge {{ $step['tone'] }}">{{ $step['status'] }}</span>
                        </div>
                        <div>{{ $step['note'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-card pd-section-card mb-6">
        <div class="pd-card-body">
            <div class="pd-hub-section-head">
                <div>
                    <div class="pd-hub-section-title">Önizleme Özeti</div>
                    <div class="pd-hub-section-copy">Kaynağın tipi, profil durumu, canlı veya demo ayrımı ve sonraki önerilen adım tek bakışta görünür.</div>
                </div>
            </div>

            <div class="pd-hub-detail-grid">
                <div class="pd-hub-metric-card pd-hub-metric-info">
                    <div class="pd-hub-metric-label">Tedarikçi</div>
                    <div class="pd-hub-metric-value">{{ $sourceSummary['supplier_name'] }}</div>
                    <div class="pd-hub-metric-note">Kaynak adı: {{ $source->source_name }}</div>
                </div>
                <div class="pd-hub-metric-card pd-hub-metric-info">
                    <div class="pd-hub-metric-label">Profil</div>
                    <div class="pd-hub-metric-value">{{ $sourceSummary['profile_key'] ?? '-' }}</div>
                    <div class="pd-hub-metric-note">Format: {{ $formatLabel }}</div>
                </div>
                <div class="pd-hub-metric-card pd-hub-metric-success">
                    <div class="pd-hub-metric-label">Son preview</div>
                    <div class="pd-hub-metric-value">{{ $lastPreviewAt }}</div>
                    <div class="pd-hub-metric-note">Node path: {{ $parserResult['node_path'] ?? $standardizationNotes['product_node_path'] ?? '-' }}</div>
                </div>
                <div class="pd-hub-metric-card pd-hub-metric-success">
                    <div class="pd-hub-metric-label">Son veri çekimi</div>
                    <div class="pd-hub-metric-value">{{ $lastFetchAt }}</div>
                    <div class="pd-hub-metric-note">Gösterim modu: {{ $selectedLimit === 'all' ? 'Tümü' : $availableLimits[$selectedLimit] }}</div>
                </div>
                <div class="pd-hub-metric-card {{ $sourceMode === 'live_source' ? 'pd-hub-metric-success' : 'pd-hub-metric-warn' }}">
                    <div class="pd-hub-metric-label">Önizleme durumu</div>
                    <div class="pd-hub-metric-value">{{ $sourceMode === 'live_source' ? 'Canlı Kaynak' : 'Demo Fallback' }}</div>
                    <div class="pd-hub-metric-note">{{ $sourceMode === 'live_source' ? 'Canlı kaynak verisi okundu.' : 'Canlı kaynak okunamadı; örnek veri gösteriliyor.' }}</div>
                </div>
                <div class="pd-hub-metric-card pd-hub-metric-info">
                    <div class="pd-hub-metric-label">Sonraki önerilen adım</div>
                    <div class="pd-hub-metric-value">{{ $nextStepLabel }}</div>
                    <div class="pd-hub-metric-note">Önce inceleyin, sonra alan eşleme ve kategori eşleme ile aynı kurulum zincirine devam edin.</div>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-card pd-section-card mb-6">
        <div class="pd-card-body">
            <div class="pd-hub-section-head">
                <div>
                    <div class="pd-hub-section-title">Veri Kalite Raporu</div>
                    <div class="pd-hub-section-copy">Ana kalite sinyallerini öne çıkarın, detay metrikleri yalnız gerektiğinde açın.</div>
                </div>
            </div>

            <div class="pd-hub-overview-grid">
                @foreach($metricCards as $metric)
                    <div class="pd-hub-metric-card {{ $metric['class'] }}">
                        <div class="pd-hub-metric-label">{{ $metric['label'] }}</div>
                        <div class="pd-hub-metric-value">{{ $metric['value'] }}</div>
                        <div class="pd-hub-metric-note">{{ $metric['note'] }}</div>
                    </div>
                @endforeach
            </div>

            <details class="pd-hub-preview-details mt-4">
                <summary class="pd-hub-preview-details-summary">Detay kalite metrikleri</summary>
                <div class="pd-hub-detail-grid mt-4">
                    @foreach($qualityMetrics as $metric)
                        <div class="pd-hub-metric-card {{ $metric['value'] > 0 ? 'pd-hub-metric-warn' : 'pd-hub-metric-success' }}">
                            <div class="pd-hub-metric-label">{{ $metric['label'] }}</div>
                            <div class="pd-hub-metric-value">{{ $metric['value'] }}</div>
                            <div class="pd-hub-metric-note">{{ $metric['value'] > 0 ? 'Kontrol listesine eklendi' : 'Sorun görünmüyor' }}</div>
                        </div>
                    @endforeach
                </div>
            </details>
        </div>
    </section>

    <section class="pd-card pd-section-card mb-6">
        <div class="pd-card-body">
            <div class="pd-hub-section-head">
                <div>
                    <div class="pd-hub-section-title">Zorunlu Eksikler ve Uyarılar</div>
                    <div class="pd-hub-section-copy">Ürün kodu, ürün adı, fiyat, stok ve varyant ayrımı gibi kritik eksikleri önce bu bloktan kontrol edin.</div>
                </div>
            </div>

            @if(!empty($mappingWarnings))
                <div class="pd-warn mb-4">Zorunlu eşleme eksikleri var. Güvenli veri işleme öncesinde bu alanları tamamlayın.</div>
                <div class="pd-grid">
                    @foreach($mappingWarnings as $warning)
                        <div class="pd-note">{{ $warning }}</div>
                    @endforeach
                </div>
            @else
                <div class="pd-note mb-4">Bu ön kontrolde zorunlu alan eşleme uyarısı görünmüyor.</div>
            @endif

            @if($sourceMode === 'live_source')
                <div class="pd-note">Canlı kaynak okundu. Ön kontrol başarılıysa sistem senkronizasyon sonrası satış listesine otomatik yansıtır; ekstra havuza aktarma veya teklife gönderme kararı beklenmez.</div>
            @else
                <div class="pd-warn">Canlı kaynak okunamadı, örnek veya demo veri gösteriliyor. Bu ekranı başarılı canlı bağlantı doğrulaması yerine kullanmayın.</div>
            @endif
        </div>
    </section>

    <section class="pd-card pd-section-card mb-6">
        <div class="pd-card-body">
            <div class="pd-hub-section-head">
                <div>
                    <div class="pd-hub-section-title">Filtre ve Limit</div>
                    <div class="pd-hub-section-copy">Tüm kayıtları, yalnız uyarılı satırları veya eksik alanları kompakt filtrelerle izleyin.</div>
                </div>
            </div>

            <form method="GET" action="{{ route('admin.super.product-data-hub.sources.preview', $source) }}" class="pd-hub-toolbar">
                <div class="pd-hub-toolbar-row">
                    <div>
                        <label class="pd-label">Gösterim limiti</label>
                        <select name="limit" class="pd-input">
                            @foreach($availableLimits as $limitValue => $limitLabel)
                                <option value="{{ $limitValue }}" {{ $selectedLimit === $limitValue ? 'selected' : '' }}>{{ $limitLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Filtre</label>
                        <select name="filter" class="pd-input">
                            @foreach($availableFilters as $filterValue => $filterLabel)
                                <option value="{{ $filterValue }}" {{ $selectedFilter === $filterValue ? 'selected' : '' }}>{{ $filterLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pd-preview-filter-actions">
                        <button type="submit" class="pd-btn pd-btn-primary">Filtreyi Uygula</button>
                        <a href="{{ route('admin.super.product-data-hub.sources.preview', $source) }}" class="pd-btn pd-btn-light">Sıfırla</a>
                    </div>
                </div>

                <div class="pd-hub-chip-group">
                    @foreach($availableLimits as $limitValue => $limitLabel)
                        <a href="{{ route('admin.super.product-data-hub.sources.preview', array_merge(['source' => $source->id], $currentQuery, ['limit' => $limitValue, 'filter' => $selectedFilter])) }}"
                           class="pd-hub-chip {{ $selectedLimit === $limitValue ? 'pd-hub-chip-active' : 'pd-hub-chip-muted' }}">
                            {{ $limitLabel }}
                        </a>
                    @endforeach
                </div>

                <div class="pd-hub-chip-group">
                    @foreach($toolbarFilters as $filterValue => $filterLabel)
                        <a href="{{ route('admin.super.product-data-hub.sources.preview', array_merge(['source' => $source->id], $currentQuery, ['filter' => $filterValue, 'limit' => $selectedLimit])) }}"
                           class="pd-hub-chip {{ $selectedFilter === $filterValue ? 'pd-hub-chip-active' : 'pd-hub-chip-muted' }}">
                            {{ $filterLabel }}
                        </a>
                    @endforeach
                </div>
            </form>
        </div>
    </section>

    @if($sourceMode === 'live_source')
        <div class="pd-note mb-4">Canlı kaynak okundu. Limit ve filtreyi değiştirerek ilk kayıtları ürün, varyant, fiyat ve görsel kalitesi açısından inceleyebilirsiniz.</div>
    @else
        <div class="pd-warn mb-4">Canlı kaynak okunamadı, örnek veya demo veri gösteriliyor. Kaynak bağlantısını düzeltmeden bu görünümü canlı veri gibi değerlendirmeyin.</div>
    @endif

    @if(blank($source->url) && blank($source->config['source_file_path'] ?? null))
        <div class="pd-warn mb-4">Kaynak URL veya dosya yolu tanımlanmamış. Test Et, Preview ve Sync işlemleri gerçek kaynağa bağlanamaz.</div>
    @endif

    @foreach($previewWarnings as $previewWarning)
        <div class="pd-note mb-2">{{ $previewWarning }}</div>
    @endforeach

    @if(!$canStagePreview)
        <div class="pd-warn mb-4">
            <strong>Staging’e Aktar şu an kilitli.</strong>
            @foreach($stageBlockedReasons as $reason)
                <div class="mt-2">{{ $reason }}</div>
            @endforeach
        </div>
    @endif

    @if(($fetchErrorType ?? 'none') === 'ssl_certificate')
        <div class="pd-warn mb-4">
            <strong>SSL Sertifika Hatası</strong>
            <div class="mt-2">Kaynak okunamadı çünkü SSL sertifikası doğrulanamadı. Local ortamda PHP cacert.pem ayarını kontrol edin. Canlı ortamda geçerli SSL sertifikası ve sabit sunucu IP ile çalışılması önerilir.</div>
            <div class="mt-2">Bu hata IP whitelist hatası değildir. IP whitelist hatası genellikle HTTP 403 olarak görünür.</div>
            <div class="mt-2">Local test için onaylı kaynaktan indirilen XML dosyasını <strong>storage/app/supplier-feeds/akdeniz.xml</strong> konumuna koyup Yerel Dosya Yolu alanından okutabilirsiniz.</div>
        </div>
    @endif

    @if(($profileKey ?? null) === 'AKDENIZ' && collect($previewWarnings)->contains(fn ($warning) => str_contains($warning, 'IP izinli olabilir')))
        <div class="pd-warn mb-4">Akdeniz kaynağı IP izinli olabilir. Canlı sistemde sabit sunucu IP’si Akdeniz’e bildirilmelidir. Local geliştirmede onaylı IP’den indirilen XML dosyasını Yerel Dosya Yolu alanına ekleyerek preview alabilirsiniz.</div>
    @endif

    @foreach($previewErrors as $previewError)
        <div class="pd-warn mb-2">{{ $previewError }}</div>
    @endforeach

    @if(!empty($mappingWarnings))
        <div class="pd-warn mb-4">Bu kaynak import için hazır değil. Zorunlu alan eşlemeleri eksik.</div>
        @foreach($mappingWarnings as $mappingWarning)
            <div class="pd-note mb-2">{{ $mappingWarning }}</div>
        @endforeach
    @endif

    <section class="pd-card pd-section-card pd-hub-table-card mb-6">
        <div class="pd-hub-table-head">
            <div>
                <div class="pd-hub-section-title">Ana Ürün Önizleme</div>
                <div class="pd-hub-section-copy">Liste fiyatı, görsel ve kalite uyarılarını ana ürün seviyesinde kontrol edin.</div>
            </div>
        </div>
        <div class="pd-card-body">
            @if($previewProducts->isEmpty())
                <div class="pd-note">Seçilen limit veya filtre için ürün bulunamadı.</div>
            @else
                <div class="pd-hub-table-wrap">
                    <table class="pd-table pd-hub-table">
                        <thead>
                            <tr>
                                <th>Görsel</th>
                                <th>Ürün</th>
                                <th>Varyant / Kategori</th>
                                <th>Liste fiyatı</th>
                                <th>Uyarılar</th>
                                <th>Teknik detay</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($previewProducts as $item)
                                @php
                                    $galleryImages = $item['gallery_images'] ?? [];
                                    $galleryPreview = array_slice($galleryImages, 0, 3);
                                    $warningBadgeList = $warningBadges($item);
                                    $infoMessage = $priceInfoMessage($profileKey ?? '', $item);
                                    $displayStock = $resolveStockValue($item, ['stock_quantity', 'total_variant_stock_quantity', 'variant_stock_quantity']);
                                    $variantStock = $resolveStockValue($item, ['variant_stock_quantity']);
                                    $totalVariantStock = $resolveStockValue($item, ['total_variant_stock_quantity']);
                                @endphp
                                <tr>
                                    <td class="pd-media-cell">
                                        @if(!empty($item['image_url']))
                                            <img src="{{ $item['image_url'] }}" alt="Ürün görseli" class="pd-product-thumb pd-allow-large">
                                        @else
                                            <div class="pd-hub-placeholder">Görsel yok</div>
                                        @endif

                                        <div class="pd-media-links">
                                            @if(!empty($item['image_url']))
                                                <a href="{{ $item['image_url'] }}" target="_blank" rel="noopener noreferrer">Görseli aç</a>
                                            @endif
                                            @if(!empty($item['product_url']))
                                                <a href="{{ $item['product_url'] }}" target="_blank" rel="noopener noreferrer">Ürün sayfası</a>
                                            @endif
                                            @if(!empty($item['detail_url']))
                                                <a href="{{ $item['detail_url'] }}" target="_blank" rel="noopener noreferrer">Detay linki</a>
                                            @endif
                                            @if(!empty($item['artwork_template_url']))
                                                <a href="{{ $item['artwork_template_url'] }}" target="_blank" rel="noopener noreferrer">Trase / Şablon</a>
                                            @endif
                                        </div>

                                        @if(!empty($galleryImages))
                                            <div class="mt-2"><span class="pd-badge pd-badge-blue">Galeri: {{ count($galleryImages) }}</span></div>
                                            <div class="pd-gallery-strip">
                                                @foreach($galleryPreview as $galleryImage)
                                                    <a href="{{ $galleryImage }}" target="_blank" rel="noopener noreferrer">
                                                        <img src="{{ $galleryImage }}" alt="Galeri görseli">
                                                    </a>
                                                @endforeach
                                                @if(count($galleryImages) > 3)
                                                    <span class="pd-badge pd-badge-gray">+{{ count($galleryImages) - 3 }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="pd-hub-product-title">{{ $item['product_name'] ?: '-' }}</div>
                                        <div class="pd-hub-meta-list">
                                            <div class="pd-hub-meta-item">Ürün kodu: {{ $item['generated_product_code'] ?: '-' }}</div>
                                            <div class="pd-hub-meta-item">Tedarikçi kodu: {{ $item['supplier_product_code'] ?: '-' }}</div>
                                            <div class="pd-hub-meta-item">Tedarikçi: {{ $sourceSummary['supplier_name'] }}</div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="pd-hub-meta-list">
                                            <div class="pd-hub-meta-item">Kategori: {{ $item['supplier_category_name'] ?: '-' }}</div>
                                            <div class="pd-hub-meta-item">{{ $formatStock($displayStock) }}</div>
                                            @if($totalVariantStock !== null && $variantStock !== null && abs($totalVariantStock - $variantStock) > 0.0001)
                                                <div class="pd-hub-meta-item">Toplam stok: {{ number_format($totalVariantStock, 0, ',', '.') }}</div>
                                                <div class="pd-hub-meta-item">Varyant stok: {{ number_format($variantStock, 0, ',', '.') }}</div>
                                            @endif
                                            <div class="pd-hub-meta-item">Galeri sayısı: {{ count($galleryImages) }}</div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="pd-hub-price-stack">
                                            <div class="pd-hub-price-main">{{ $formatMoney($item['list_price'] ?? null) }}</div>
                                            <div class="pd-hub-meta-item">Para birimi: {{ $item['currency'] ?: '-' }}</div>
                                            <div class="pd-hub-meta-item">KDV: {{ $formatPercent($item['vat_rate'] ?? null) }}</div>
                                        </div>
                                        @if($infoMessage)
                                            <div class="pd-hub-inline-note pd-hub-inline-note-amber mt-2">{{ $infoMessage }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="pd-hub-warning-stack">
                                            <div class="pd-hub-warning-badges">
                                                @forelse($warningBadgeList as $warningBadge)
                                                    <span class="pd-badge {{ $warningBadge['tone'] }}">{{ $warningBadge['label'] }}</span>
                                                @empty
                                                    <span class="pd-badge pd-badge-green">Sorun yok</span>
                                                @endforelse
                                            </div>

                                            @if(!empty($item['net_price_warning']))
                                                <div class="pd-hub-inline-note pd-hub-inline-note-amber">Bu ürün net fiyatlı olabilir. Teklif/sipariş sırasında standart iskonto uygulanmamalıdır.</div>
                                            @endif
                                            @if(!empty($item['supplier_warning_flag']))
                                                @if(($profileKey ?? null) === 'ETKIN' || ($profileKey ?? null) === 'YENI-NESIL')
                                                    <div class="pd-hub-inline-note pd-hub-inline-note-red">
                                                        {{ ($profileKey ?? null) === 'ETKIN' ? 'Bu ürün Etkin kaynağında kırmızı ürün olarak işaretlenmiş.' : 'Bu ürün Yeni Nesil kaynağında turuncu ürün olarak işaretlenmiş.' }}
                                                    </div>
                                                @endif
                                            @endif

                                            <div class="pd-hub-warning-text">
                                                @forelse(($item['warnings'] ?? []) as $warning)
                                                    <div>{{ $warning }}</div>
                                                @empty
                                                    <div>-</div>
                                                @endforelse
                                            </div>

                                            @foreach(($item['info_messages'] ?? []) as $infoMessage)
                                                <div class="pd-note">{{ $infoMessage }}</div>
                                            @endforeach
                                            @foreach(($item['errors'] ?? []) as $error)
                                                <div class="pd-hub-inline-note pd-hub-inline-note-red">{{ $error }}</div>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        <details class="pd-hub-detail-box">
                                            <summary>Teknik detay</summary>
                                            <div class="pd-hub-detail-list">
                                                <div class="pd-hub-detail-item">Ham fiyat alanı: {{ filled($item['purchase_price'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">İskonto alanı: {{ filled($item['discount_rate'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">Alternatif fiyat alanı: {{ filled($item['alternative_price'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">USD fiyat alanı: {{ filled($item['usd_price'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">EUR fiyat alanı: {{ filled($item['eur_price'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">Kapalı liste alanı: {{ filled($item['closed_list_price'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">Ana görsel kaynağı: {{ $item['image_source_field'] ?? '-' }}</div>
                                                <div class="pd-hub-detail-item">Galeri alanları: {{ !empty($item['gallery_source_fields']) ? implode(', ', $item['gallery_source_fields']) : '-' }}</div>
                                                @if(count($galleryImages) === 1)
                                                    <div class="pd-hub-detail-item">Bu üründe yalnız ana görsel alanı geldi.</div>
                                                @endif
                                                <div class="pd-hub-detail-item">Warning flag: {{ !empty($item['warning_flag']) ? 'Var' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">Supplier warning: {{ !empty($item['supplier_warning_flag']) ? ($item['supplier_warning_type'] ?? 'Var') : 'Yok' }}</div>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    <section class="pd-card pd-section-card pd-hub-table-card mb-6">
        <div class="pd-hub-table-head">
            <div>
                <div class="pd-hub-section-title">Varyasyon Önizleme</div>
                <div class="pd-hub-section-copy">Varyant seviyesinde görsel fallback, stok ve liste fiyatı davranışını izleyin.</div>
            </div>
        </div>
        <div class="pd-card-body">
            @if($previewVariants->isEmpty())
                <div class="pd-note">Bu kaynak için ayrı varyasyon satırı oluşmadı.</div>
            @else
                <div class="pd-hub-table-wrap">
                    <table class="pd-table pd-hub-table">
                        <thead>
                            <tr>
                                <th>Görsel</th>
                                <th>Varyant</th>
                                <th>Renk / Stok</th>
                                <th>Liste fiyatı</th>
                                <th>Uyarılar</th>
                                <th>Teknik detay</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($previewVariants as $item)
                                @php
                                    $warningBadgeList = $warningBadges($item);
                                    $infoMessage = $priceInfoMessage($profileKey ?? '', $item);
                                    $variantStock = $resolveStockValue($item, ['variant_stock_quantity', 'stock_quantity']);
                                @endphp
                                <tr>
                                    <td class="pd-media-cell">
                                        @if(!empty($item['variant_image_url']))
                                            <img src="{{ $item['variant_image_url'] }}" alt="Varyasyon görseli" class="pd-product-thumb-sm pd-allow-large">
                                            <div class="pd-media-links">
                                                <a href="{{ $item['variant_image_url'] }}" target="_blank" rel="noopener noreferrer">Görseli aç</a>
                                            </div>
                                        @else
                                            <div class="pd-hub-placeholder">Görsel yok</div>
                                        @endif
                                        <div class="pd-hub-meta-list">
                                            <div class="pd-hub-meta-item">Ana görsel kaynağı: {{ $item['variant_image_source_field'] ?? '-' }}</div>
                                            @if(!empty($item['image_fallback_used']))
                                                <div class="pd-hub-meta-item">Varyasyon görseli gelmedi, ana ürün görseli kullanıldı.</div>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <div class="pd-hub-product-title">{{ $item['generated_variant_code'] ?: '-' }}</div>
                                        <div class="pd-hub-meta-list">
                                            <div class="pd-hub-meta-item">Parent kod: {{ $item['parent_supplier_product_id'] ?: '-' }}</div>
                                            <div class="pd-hub-meta-item">Tedarikçi: {{ $sourceSummary['supplier_name'] }}</div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="pd-hub-meta-list">
                                            <div class="pd-hub-meta-item">Renk: {{ $item['variant_color'] ?: '-' }}</div>
                                            <div class="pd-hub-meta-item">Varyant kodu: {{ $item['variant_stock_code'] ?: '-' }}</div>
                                            <div class="pd-hub-meta-item">{{ $formatStock($variantStock) }}</div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="pd-hub-price-stack">
                                            <div class="pd-hub-price-main">{{ $formatMoney($item['list_price'] ?? null) }}</div>
                                            <div class="pd-hub-meta-item">Para birimi: {{ $item['currency'] ?: '-' }}</div>
                                            <div class="pd-hub-meta-item">KDV: {{ $formatPercent($item['vat_rate'] ?? null) }}</div>
                                        </div>
                                        @if($infoMessage)
                                            <div class="pd-hub-inline-note pd-hub-inline-note-amber mt-2">{{ $infoMessage }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="pd-hub-warning-stack">
                                            <div class="pd-hub-warning-badges">
                                                @forelse($warningBadgeList as $warningBadge)
                                                    <span class="pd-badge {{ $warningBadge['tone'] }}">{{ $warningBadge['label'] }}</span>
                                                @empty
                                                    <span class="pd-badge pd-badge-green">Sorun yok</span>
                                                @endforelse
                                            </div>

                                            @if(!empty($item['net_price_warning']))
                                                <div class="pd-hub-inline-note pd-hub-inline-note-amber">Bu ürün net fiyatlı olabilir. Standart iskonto uygulanmadan önce kontrol edilmelidir.</div>
                                            @endif
                                            @if(!empty($item['supplier_warning_flag']))
                                                @if(($profileKey ?? null) === 'ETKIN' || ($profileKey ?? null) === 'YENI-NESIL')
                                                    <div class="pd-hub-inline-note pd-hub-inline-note-red">
                                                        {{ ($profileKey ?? null) === 'ETKIN' ? 'Bu ürün Etkin kaynağında kırmızı ürün olarak işaretlenmiş.' : 'Bu ürün Yeni Nesil kaynağında turuncu ürün olarak işaretlenmiş.' }}
                                                    </div>
                                                @endif
                                            @endif

                                            <div class="pd-hub-warning-text">
                                                @forelse(($item['warnings'] ?? []) as $warning)
                                                    <div>{{ $warning }}</div>
                                                @empty
                                                    <div>-</div>
                                                @endforelse
                                            </div>

                                            @foreach(($item['info_messages'] ?? []) as $infoMessage)
                                                <div class="pd-note">{{ $infoMessage }}</div>
                                            @endforeach
                                            @foreach(($item['errors'] ?? []) as $error)
                                                <div class="pd-hub-inline-note pd-hub-inline-note-red">{{ $error }}</div>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        <details class="pd-hub-detail-box">
                                            <summary>Teknik detay</summary>
                                            <div class="pd-hub-detail-list">
                                                <div class="pd-hub-detail-item">Ham fiyat alanı: {{ filled($item['purchase_price'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">İskonto alanı: {{ filled($item['discount_rate'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">Alternatif fiyat alanı: {{ filled($item['alternative_price'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">USD fiyat alanı: {{ filled($item['usd_price'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">EUR fiyat alanı: {{ filled($item['eur_price'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">Kapalı liste alanı: {{ filled($item['closed_list_price'] ?? null) ? 'Algılandı' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">Fallback: {{ !empty($item['image_fallback_used']) ? 'Var' : 'Yok' }}</div>
                                                <div class="pd-hub-detail-item">Supplier warning: {{ !empty($item['supplier_warning_flag']) ? ($item['supplier_warning_type'] ?? 'Var') : 'Yok' }}</div>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    <section class="pd-card pd-section-card pd-hub-sticky-actions">
        <div class="pd-card-body">
            <div class="pd-hub-section-head">
                <div>
                    <div class="pd-hub-section-title">Gelişmiş Teknik İşlemler</div>
                    <div class="pd-hub-section-copy">Normal kullanımda gerekmez. Önce ön kontrol, alan eşleme, kategori eşleme ve Bekleyen Kontroller akışını tamamlayın.</div>
                </div>
            </div>
            <form id="pdSourceRetestForm" action="{{ route('admin.super.product-data-hub.sources.test', $source) }}" method="POST">
                @csrf
            </form>
            <form id="pdSourceBuildStandardForm" action="{{ route('admin.super.product-data-hub.sources.build-standard-products', $source) }}" method="POST">
                @csrf
            </form>
            <form id="pdSourceStagePreviewForm" action="{{ route('admin.super.product-data-hub.sources.stage-preview', $source) }}" method="POST">
                @csrf
                <label class="pd-note pd-preview-confirm-note">
                    <input type="checkbox" name="confirm_stage" value="1" {{ old('confirm_stage') ? 'checked' : '' }}>
                    Bu verileri ham ürün havuzuna aktaracağımı onaylıyorum.
                </label>
                @if(isset($errors) && $errors->has('confirm_stage'))
                    <div class="pd-warn mb-3">{{ $errors->first('confirm_stage') }}</div>
                @endif

                <div class="pd-form-actions pd-preview-advanced-actions">
                    <button type="submit" form="pdSourceRetestForm" class="pd-btn pd-btn-light" onclick="return confirm('Kaynak bağlantısı yeniden test edilecek ve canlı önizleme verisi çekilebilir. Veri güncellenmez. Devam edilsin mi?')">Ön Kontrolü Yenile</button>
                    <a href="{{ route('admin.super.product-data-hub.sources.preview', array_merge(['source' => $source->id], $currentQuery, ['limit' => 'all'])) }}" class="pd-btn pd-btn-light" onclick="return confirm('Tüm kayıtları göstermek ekranı yavaşlatabilir. Devam edilsin mi?')">Tümünü Göster</a>
                    <button type="submit" class="pd-btn pd-btn-primary" onclick="return confirm('Preview kayıtları staging havuzuna aktarılacak. Bu işlem veri yazar; checkbox onayı da gerektirir. Devam edilsin mi?')" {{ !$canStagePreview ? 'disabled' : '' }}>Staging’e Aktar</button>
                    <button type="submit" form="pdSourceBuildStandardForm" class="pd-btn pd-btn-warning" onclick="return confirm('Bu işlem kaynak ürünlerini standart ürün havuzuna dönüştürür veya günceller. Önce önizleme ve hazırlık sonucu kontrol edilmelidir. Devam edilsin mi?')" {{ $sourceMode !== 'live_source' ? 'disabled' : '' }}>Standart Ürün Havuzuna Al</button>
                </div>

                @if(!$canStagePreview)
                    <div class="pd-note mt-3">Zorunlu alanlar tamamlanmadan veya geçici kod üretimi varken staging başlatılamaz.</div>
                @endif
            </form>
        </div>
    </section>
</div>
@endsection

@section('bottom_actions')
@php($bottomQuery = request()->query())
<div>
    <strong>Preview aksiyonları:</strong>
    <span class="pd-muted">Liste fiyatı bazlı görünümü, uyarıları ve görselleri bu ekrandan kontrol edebilirsiniz.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.sources.preview', array_merge(['source' => $source->id], $bottomQuery, ['filter' => 'warning'])) }}" class="pd-btn pd-btn-light">Uyarılı ürünleri göster</a>
    <a href="{{ route('admin.super.product-data-hub.sources.preview', array_merge(['source' => $source->id], $bottomQuery, ['filter' => 'missing-image'])) }}" class="pd-btn pd-btn-light">Görseli eksik ürünler</a>
    <a href="{{ route('admin.super.product-data-hub.sources.preview', array_merge(['source' => $source->id], $bottomQuery, ['filter' => 'missing-price'])) }}" class="pd-btn pd-btn-light">Fiyatı eksik ürünler</a>
    <a href="{{ route('admin.super.product-data-hub.sources.preview', array_merge(['source' => $source->id], $bottomQuery, ['filter' => 'parse-error'])) }}" class="pd-btn pd-btn-light">Parse hatalı ürünler</a>
</div>
@endsection

@section('summary')
<div class="pd-card">
    <div class="pd-card-body">
        <div class="pd-summary-title">Önizleme Özeti</div>
        <div class="pd-summary-row"><span>Kayıt</span><strong>{{ $stats['records_read'] }}</strong></div>
        <div class="pd-summary-row"><span>Ürün</span><strong>{{ $stats['product_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Varyasyon</span><strong>{{ $stats['variant_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Gösterilen</span><strong>{{ $stats['displayed_product_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Uyarılı</span><strong>{{ $stats['warning_product_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Görseli Eksik</span><strong>{{ $stats['missing_image_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Fiyatı Eksik</span><strong>{{ $stats['missing_price_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Kategori Eksik</span><strong>{{ $stats['missing_category_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Kod Eksik</span><strong>{{ $stats['missing_product_code_count'] + $stats['missing_variant_code_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Net Fiyat Uyarılı</span><strong>{{ $stats['net_price_warning_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Tedarikçi Uyarısı</span><strong>{{ $stats['supplier_warning_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Kritik</span><strong>{{ $stats['critical_issue_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Kontrol</span><strong>{{ $stats['review_issue_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Bilgi</span><strong>{{ $stats['info_issue_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Parse Hatası</span><strong>{{ $stats['parse_error_count'] }}</strong></div>
        <div class="pd-summary-row"><span>Hata</span><strong>{{ $stats['errors'] }}</strong></div>
    </div>
</div>
@endsection
