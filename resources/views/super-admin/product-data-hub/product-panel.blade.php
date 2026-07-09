@extends('layouts.prodelya-admin')

@section('title', 'Bekleyen Kontroller')
@section('page_title', 'Bekleyen Kontroller')
@section('page_subtitle', 'Bu ekran yalnız karar bekleyen istisnaları öne çıkarır. Normal fiyat ve stok senkronizasyonu otomatik çalışır; operatör yalnız kategori, kimlik, görünürlük ve şüpheli değişim kararlarını burada yönetir.')

@section('page_actions')
    <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light">Standart Ürünler (Teknik)</a>
@endsection

@section('content')
    @php
        $panelQuery = request()->except(['category_mapping_product_id']);
        $drawerProduct = $categoryMappingDrawer['product'] ?? null;
        $drawerMapping = $categoryMappingDrawer['mapping'] ?? null;
        $drawerSearchUrl = route('admin.super.product-data-hub.categories.search');
        $drawerFormAction = isset($categoryMappingDrawer['save_action'])
            ? $categoryMappingDrawer['save_action'] . (!empty($panelQuery) ? ('?' . http_build_query($panelQuery)) : '')
            : null;

        $toneClass = static function (string $tone): string {
            return match ($tone) {
                'green' => 'pd-badge-green',
                'amber' => 'pd-badge-amber',
                'red' => 'pd-badge-red',
                'purple' => 'pd-badge-purple',
                'blue' => 'pd-badge-blue',
                default => 'pd-badge-light',
            };
        };

        $formatPrice = static function ($value, ?string $currency = 'TL'): string {
            if ($value === null || $value === '') {
                return '-';
            }

            return number_format((float) $value, 2, ',', '.') . ' ' . ($currency ?: 'TL');
        };

        $formatStock = static function ($value): string {
            if ($value === null || $value === '') {
                return '-';
            }

            return number_format((float) $value, 0, ',', '.');
        };

        $metricCards = [
            ['label' => 'Otomatik güncellendi', 'value' => $diagnosticSummary['auto_updated'] ?? 0, 'class' => 'pd-metric-card-soft-green'],
            ['label' => 'İnceleme bekliyor', 'value' => $diagnosticSummary['review_required'] ?? 0, 'class' => 'pd-metric-card-soft-amber'],
            ['label' => 'Kategori eşleşmemiş', 'value' => $diagnosticSummary['category_waiting'] ?? 0, 'class' => 'pd-metric-card-soft-blue'],
            ['label' => 'Abone Firma çıkışı kapalı', 'value' => $diagnosticSummary['tenant_output_closed'] ?? 0, 'class' => 'pd-metric-card-soft-red'],
            ['label' => 'Toplam satır', 'value' => $diagnosticSummary['total_rows'] ?? 0, 'class' => 'pd-metric-card-soft-blue'],
            ['label' => 'Satılabilir varyant', 'value' => $diagnosticSummary['sellable_variant'] ?? 0, 'class' => 'pd-metric-card-soft-green'],
            ['label' => 'Satılabilir flat', 'value' => $diagnosticSummary['sellable_flat'] ?? 0, 'class' => 'pd-metric-card-soft-green'],
            ['label' => 'Parent / grup', 'value' => $diagnosticSummary['parent_only'] ?? 0, 'class' => 'pd-metric-card-soft-purple'],
            ['label' => 'Fiyat uyumsuz', 'value' => $diagnosticSummary['stale_price'] ?? 0, 'class' => 'pd-metric-card-soft-amber'],
            ['label' => 'Stok uyumsuz', 'value' => $diagnosticSummary['stale_stock'] ?? 0, 'class' => 'pd-metric-card-soft-amber'],
            ['label' => 'Satış listesi eski', 'value' => $diagnosticSummary['projection_outdated'] ?? 0, 'class' => 'pd-metric-card-soft-red'],
            ['label' => 'Erişim kapalı', 'value' => $diagnosticSummary['supplier_access_closed'] ?? 0, 'class' => 'pd-metric-card-soft-red'],
            ['label' => 'Teklifte görünür', 'value' => $diagnosticSummary['quote_visible'] ?? 0, 'class' => 'pd-metric-card-soft-blue'],
            ['label' => 'Teklifte görünmez', 'value' => $diagnosticSummary['not_quote_visible'] ?? 0, 'class' => 'pd-metric-card-soft-slate'],
        ];

        $decisionCards = [
            [
                'label' => 'Kategori Uyarısı',
                'value' => $reviewQueueSummary['category_waiting'] ?? 0,
                'tone' => 'blue',
                'copy' => 'Ürün teklif/sipariş ekranında görünür; yalnız genel kategori eşlemesi eksik.',
                'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['review_bucket' => 'category_waiting'])),
                'active' => ($filters['review_bucket'] ?? '') === 'category_waiting',
            ],
            [
                'label' => 'Eksik Alan / Bilgi',
                'value' => $diagnosticSummary['review_required'] ?? 0,
                'tone' => 'amber',
                'copy' => 'Temel bilgi eksiği veya manuel göz gerektiren satırlar.',
                'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['warning_state' => 'warning'])),
                'active' => ($filters['warning_state'] ?? '') === 'warning',
            ],
            [
                'label' => 'Şüpheli Fiyat / Stok',
                'value' => ($reviewQueueSummary['anomaly_flags'] ?? 0) + ($diagnosticSummary['stale_price'] ?? 0) + ($diagnosticSummary['stale_stock'] ?? 0),
                'tone' => 'red',
                'copy' => 'Normal akışın dışına çıkan fiyat, stok veya güncellik uyarıları.',
                'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['review_bucket' => 'anomaly_flags'])),
                'active' => ($filters['review_bucket'] ?? '') === 'anomaly_flags',
            ],
            [
                'label' => 'Yeni Ürün',
                'value' => $reviewQueueSummary['new_items'] ?? 0,
                'tone' => 'blue',
                'copy' => 'İlk kez gelen ürün ve varyantlar.',
                'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['review_bucket' => 'new_items'])),
                'active' => ($filters['review_bucket'] ?? '') === 'new_items',
            ],
            [
                'label' => 'Kaybolan / Pasife Düşen',
                'value' => ($diagnosticSummary['tenant_output_closed'] ?? 0) + ($reviewQueueSummary['tenant_output_blocks'] ?? 0),
                'tone' => 'purple',
                'copy' => 'Yayından düşen veya görünürlük blokuna takılan kayıtlar.',
                'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['review_bucket' => 'tenant_output_blocks'])),
                'active' => ($filters['review_bucket'] ?? '') === 'tenant_output_blocks',
            ],
            [
                'label' => 'Otomatik Güncellenen Fiyat / Stok',
                'value' => $diagnosticSummary['auto_updated'] ?? 0,
                'tone' => 'green',
                'copy' => 'Operatöre iş çıkarmadan akışta kalan kayıtlar.',
                'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['flow_mode' => 'clean_flow'])),
                'active' => ($filters['flow_mode'] ?? '') === 'clean_flow',
            ],
            [
                'label' => 'Kimlik Güven Problemi',
                'value' => $reviewQueueSummary['identity_issues'] ?? 0,
                'tone' => 'red',
                'copy' => 'Kimlik, varyant veya eşleşme güveni düşük satırlar.',
                'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['review_bucket' => 'identity_issues'])),
                'active' => ($filters['review_bucket'] ?? '') === 'identity_issues',
            ],
        ];

        $decisionTabs = [
            ['label' => 'Tümü', 'href' => route('admin.super.product-data-hub.product-panel', $panelQuery), 'active' => ($filters['review_bucket'] ?? '') === '' && ($filters['flow_mode'] ?? '') === '' && ($filters['warning_state'] ?? '') === ''],
            ['label' => 'Kategori', 'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['review_bucket' => 'category_waiting'])), 'active' => ($filters['review_bucket'] ?? '') === 'category_waiting'],
            ['label' => 'Alan / Bilgi', 'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['warning_state' => 'warning'])), 'active' => ($filters['warning_state'] ?? '') === 'warning'],
            ['label' => 'Fiyat / Stok', 'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['review_bucket' => 'anomaly_flags'])), 'active' => ($filters['review_bucket'] ?? '') === 'anomaly_flags'],
            ['label' => 'Yeni Ürün', 'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['review_bucket' => 'new_items'])), 'active' => ($filters['review_bucket'] ?? '') === 'new_items'],
            ['label' => 'Kaybolan', 'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['review_bucket' => 'tenant_output_blocks'])), 'active' => ($filters['review_bucket'] ?? '') === 'tenant_output_blocks'],
            ['label' => 'Kimlik', 'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['review_bucket' => 'identity_issues'])), 'active' => ($filters['review_bucket'] ?? '') === 'identity_issues'],
            ['label' => 'Bilgi', 'href' => route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['technical_columns' => $filters['technical_columns'] ? 0 : 1])), 'active' => (bool) ($filters['technical_columns'] ?? false)],
        ];
    @endphp

    @if(session('product_panel_category_mapping_saved'))
        <div class="pd-card pd-section-card-soft-amber pd-gap-bottom-md">
            <div class="pd-card-body pd-flex-between-start">
                <div>
                    <div class="pd-text-strong">Kategori eşlendi, ürün listesine yansıtma bekliyor.</div>
                    <div class="pd-card-subtitle">Bu ekran read-only teşhis ekranıdır; kategori kaydı ayrıdır, satış listesi yazımı burada yapılmaz.</div>
                </div>
                <span class="pd-badge pd-badge-amber">Yansıtma Bekliyor</span>
            </div>
        </div>
    @endif

    <div class="pd-note pd-note-soft-blue pd-gap-bottom-md">
        Bu karar ekranında yalnız istisnalar öne çıkar. Normal fiyat ve stok senkronizasyonu otomatik çalışır; kategori kararı, kimlik güveni, görünürlük blokajı ve şüpheli değişimler için aksiyon alınır.
    </div>

    <div class="pd-hub-family-shell pd-product-hub">
        <section class="pd-section-card pd-section-card-soft-amber">
            <div class="pd-section-header">
                <div>
                    <h2 class="pd-section-title">Karar Bekleyen Başlıklar</h2>
                    <p class="pd-section-subtitle">Operasyonun görmesi gereken tek konu istisnalardır. Otomatik güncellenen kayıtlar bilgi amaçlı özetlenir.</p>
                </div>
                <div class="pd-inline-wrap-xs">
                    <span class="pd-badge pd-badge-amber">Toplam karar: {{ $reviewQueueSummary['total'] ?? 0 }}</span>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-mini-grid pd-mini-grid-compact">
                    @foreach($decisionCards as $card)
                        <a href="{{ $card['href'] }}" class="pd-mini-link-card pd-operation-link-card pd-operation-link-card-{{ $card['tone'] }} {{ $card['active'] ? 'pd-operation-link-card-active' : '' }}">
                            <div class="pd-inline-wrap-xs pd-gap-bottom-xs">
                                <span class="pd-badge pd-badge-{{ $card['tone'] }}">{{ $card['value'] }}</span>
                                @if($card['active'])
                                    <span class="pd-badge pd-badge-light">Aktif görünüm</span>
                                @endif
                            </div>
                            <div class="pd-mini-link-title">{{ $card['label'] }}</div>
                            <div class="pd-mini-link-copy">{{ $card['copy'] }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h2 class="pd-section-title">Akış Özeti</h2>
                    <p class="pd-section-subtitle">Temiz akış sessizce ilerler. Karar gerektiren satırlar ise ayrı kuyruklara düşürülür.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-mini-grid pd-mini-grid-compact">
                    @foreach($flowCards as $card)
                        <a href="{{ $card['href'] }}" class="pd-mini-link-card pd-operation-link-card pd-operation-link-card-{{ $card['tone'] }} {{ $filters['flow_mode'] === $card['key'] ? 'pd-operation-link-card-active' : '' }}">
                            <div class="pd-inline-wrap-xs pd-gap-bottom-xs">
                                <span class="pd-badge pd-badge-{{ $card['tone'] }}">{{ $card['count'] }}</span>
                                @if($filters['flow_mode'] === $card['key'])
                                    <span class="pd-badge pd-badge-light">Aktif görünüm</span>
                                @endif
                            </div>
                            <div class="pd-mini-link-title">{{ $card['title'] }}</div>
                            <div class="pd-mini-link-copy">{{ $card['copy'] }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="pd-kpi-strip">
            @foreach($metricCards as $metric)
                <div class="pd-metric-card {{ $metric['class'] }}">
                    <div class="pd-metric-card-label">{{ $metric['label'] }}</div>
                    <div class="pd-metric-card-value">{{ $metric['value'] }}</div>
                </div>
            @endforeach
        </section>

        <section class="pd-section-card pd-section-card-soft-amber">
            <div class="pd-section-header">
                <div>
                    <h2 class="pd-section-title">Bekleyen Kontrol Kuyruğu</h2>
                    <p class="pd-section-subtitle">Normal akış dışında kalan kayıtları konu başlığına göre ayırın. Hedef, yalnız manuel karar gerektiren istisnaları görmektir.</p>
                </div>
                <div class="pd-inline-wrap-xs">
                    <span class="pd-badge pd-badge-amber">Toplam iş: {{ $reviewQueueSummary['total'] ?? 0 }}</span>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-mini-grid pd-mini-grid-compact">
                    @foreach($reviewQueueCards as $card)
                        <a href="{{ $card['href'] }}" class="pd-mini-link-card pd-operation-link-card pd-operation-link-card-{{ $card['tone'] }} {{ $filters['review_bucket'] === $card['key'] ? 'pd-operation-link-card-active' : '' }}">
                            <div class="pd-inline-wrap-xs pd-gap-bottom-xs">
                                <span class="pd-badge pd-badge-{{ $card['tone'] }}">{{ $card['count'] }}</span>
                                @if($filters['review_bucket'] === $card['key'])
                                    <span class="pd-badge pd-badge-light">Aktif kuyruk</span>
                                @endif
                            </div>
                            <div class="pd-mini-link-title">{{ $card['title'] }}</div>
                            <div class="pd-mini-link-copy">{{ $card['copy'] }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-blue">
            <div class="pd-section-header">
                <div>
                    <h2 class="pd-section-title">Filtreler</h2>
                    <p class="pd-section-subtitle">Kod, ad, tedarikçi, kategori ve karar türüne göre ekranı daraltın. Gelişmiş teknik bilgiyi açmadan da ana aksiyon net görünür.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form method="GET" action="{{ route('admin.super.product-data-hub.product-panel') }}" class="pd-form-grid-3">
                    <div>
                        <label class="pd-label">Kod / Ürün Arama</label>
                        <input type="text" name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Ürün kodu, varyant kodu veya ürün adı">
                    </div>
                    <div>
                        <label class="pd-label">Tedarikçi</label>
                        <select name="supplier" class="pd-select">
                            <option value="">Tümü</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((int) $filters['supplier'] === (int) $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Kategori</label>
                        <select name="category" class="pd-select">
                            <option value="">Tüm Kategoriler</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected((int) $filters['category'] === (int) $category->id)>{{ $category->path ?: $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Satış Durumu</label>
                        <select name="sales_state" class="pd-select">
                            <option value="">Tümü</option>
                            <option value="sellable_variant" @selected($filters['sales_state'] === 'sellable_variant')>Satılabilir varyant</option>
                            <option value="sellable_flat" @selected($filters['sales_state'] === 'sellable_flat')>Satılabilir flat ürün</option>
                            <option value="parent_only" @selected($filters['sales_state'] === 'parent_only')>Parent / grup</option>
                            <option value="quote_visible" @selected($filters['sales_state'] === 'quote_visible')>Teklifte görünür</option>
                            <option value="quote_hidden" @selected($filters['sales_state'] === 'quote_hidden')>Teklifte görünmez</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Güncellik Durumu</label>
                        <select name="freshness_state" class="pd-select">
                            <option value="all" @selected($filters['freshness_state'] === 'all')>Tümü</option>
                            <option value="price_mismatch" @selected($filters['freshness_state'] === 'price_mismatch')>Fiyat uyumsuz</option>
                            <option value="stock_mismatch" @selected($filters['freshness_state'] === 'stock_mismatch')>Stok uyumsuz</option>
                            <option value="projection_outdated" @selected($filters['freshness_state'] === 'projection_outdated')>Satış listesi eski</option>
                            <option value="standard_variant_outdated" @selected($filters['freshness_state'] === 'standard_variant_outdated')>Standart varyant eski</option>
                            <option value="supplier_access_closed" @selected($filters['freshness_state'] === 'supplier_access_closed')>Tedarikçi erişimi kapalı</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Akış Modu</label>
                        <select name="flow_mode" class="pd-select">
                            <option value="" @selected($filters['flow_mode'] === '')>Tümü</option>
                            <option value="clean_flow" @selected($filters['flow_mode'] === 'clean_flow')>Temiz akış</option>
                            <option value="review_queue" @selected($filters['flow_mode'] === 'review_queue')>İnceleme gerekenler</option>
                            <option value="category_waiting" @selected($filters['flow_mode'] === 'category_waiting')>Kategori bekleyenler</option>
                            <option value="projection_issues" @selected($filters['flow_mode'] === 'projection_issues')>Satış listesi sorunları</option>
                            <option value="tenant_output_blocks" @selected($filters['flow_mode'] === 'tenant_output_blocks')>Abone Firma çıkışı blokajları</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Kontrol Kuyruğu</label>
                        <select name="review_bucket" class="pd-select">
                            <option value="" @selected($filters['review_bucket'] === '')>Tümü</option>
                            <option value="new_items" @selected($filters['review_bucket'] === 'new_items')>Yeni ürünler</option>
                            <option value="category_waiting" @selected($filters['review_bucket'] === 'category_waiting')>Kategori bekleyenler</option>
                            <option value="identity_issues" @selected($filters['review_bucket'] === 'identity_issues')>Kimlik / varyant sorunu</option>
                            <option value="anomaly_flags" @selected($filters['review_bucket'] === 'anomaly_flags')>Anomali / güncellik</option>
                            <option value="projection_issues" @selected($filters['review_bucket'] === 'projection_issues')>Satış listesi sorunları</option>
                            <option value="tenant_output_blocks" @selected($filters['review_bucket'] === 'tenant_output_blocks')>Abone Firma çıkışı blokajları</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Kategori Durumu</label>
                        <select name="category_status" class="pd-select">
                            <option value="">Tümü</option>
                            <option value="matched" @selected($filters['category_status'] === 'matched')>Eşleşmiş</option>
                            <option value="category_waiting" @selected($filters['category_status'] === 'category_waiting')>Kategori eşleşmemiş</option>
                            <option value="target_missing" @selected($filters['category_status'] === 'target_missing')>Hedef bulunamayan</option>
                            <option value="warning" @selected($filters['category_status'] === 'warning')>Uyarılı</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Stok</label>
                        <select name="stock_state" class="pd-select">
                            <option value="">Tümü</option>
                            <option value="in_stock" @selected($filters['stock_state'] === 'in_stock')>Stok var</option>
                            <option value="out_of_stock" @selected($filters['stock_state'] === 'out_of_stock')>Stok yok</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Fiyat</label>
                        <select name="price_state" class="pd-select">
                            <option value="">Tümü</option>
                            <option value="available" @selected($filters['price_state'] === 'available')>Fiyat var</option>
                            <option value="missing" @selected($filters['price_state'] === 'missing')>Fiyat yok</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Resim</label>
                        <select name="image_state" class="pd-select">
                            <option value="">Tümü</option>
                            <option value="available" @selected($filters['image_state'] === 'available')>Resimli</option>
                            <option value="missing" @selected($filters['image_state'] === 'missing')>Resimsiz</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Genel Uyarı</label>
                        <select name="warning_state" class="pd-select">
                            <option value="">Tümü</option>
                            <option value="warning" @selected($filters['warning_state'] === 'warning')>Uyarılı</option>
                            <option value="clean" @selected($filters['warning_state'] === 'clean')>Temiz</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Kayıt</label>
                        <select name="limit" class="pd-select">
                            @foreach(['50', '100', '250', '500'] as $limit)
                                <option value="{{ $limit }}" @selected((string) $filters['limit'] === $limit)>{{ $limit }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pd-inline-actions pd-inline-actions-end">
                        <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                        <a href="{{ route('admin.super.product-data-hub.product-panel') }}" class="pd-btn pd-btn-light">Temizle</a>
                    </div>
                    <div class="pd-inline-actions">
                        <label class="pd-checkbox">
                            <input type="checkbox" name="technical_columns" value="1" @checked($filters['technical_columns'])>
                            Gelişmiş teknik bilgiyi göster.
                        </label>
                    </div>
                </form>
            </div>
        </section>

        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h2 class="pd-section-title">Kontrol Listesi</h2>
                    <p class="pd-section-subtitle">Her satırda ne olduğunu, ne yapılacağını ve hangi aksiyonun beklendiğini hızlıca okuyun. Gelişmiş teknik bağlam yalnız istenirse açılır.</p>
                </div>
                <div class="pd-inline-wrap-xs">
                    <span class="pd-badge pd-badge-green">Satılabilir: {{ $stats['sellable'] }}</span>
                    <span class="pd-badge pd-badge-amber">Kontrol isteyen: {{ $stats['with_warning'] }}</span>
                </div>
            </div>
            <div class="pd-section-body">
                @if($searchFirstIdle ?? false)
                    <div class="pd-note pd-note-soft-blue pd-gap-bottom-md">
                        <div class="pd-text-strong">Kontrol başlatmak için arama veya filtre seçin.</div>
                        <div>Bu ekran büyük ürün havuzunda hedefli çalışır; boş açılışta tam tarama yapmaz. Ürün kodu, tedarikçi, kategori veya kuyruk filtresi seçerek karar ekranını doldurabilirsiniz.</div>
                    </div>
                @endif
                <div class="pd-inline-wrap-sm pd-gap-bottom-md">
                    @foreach($decisionTabs as $tab)
                        <a href="{{ $tab['href'] }}" class="pd-btn {{ $tab['active'] ? 'pd-btn-primary' : 'pd-btn-light' }} pd-btn-sm">{{ $tab['label'] }}</a>
                    @endforeach
                </div>
                <div class="pd-table-wrap">
                    <table class="pd-table pd-product-diagnostic-table">
                        <thead>
                            <tr>
                                <th>Etkilenen Kayıt</th>
                                <th>Kaynak</th>
                                <th>Kontrol Tipi</th>
                                <th>Ne Oldu?</th>
                                <th>Ne Yapılacak?</th>
                                <th>Aksiyon</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr>
                                    <td class="pd-diagnostic-cell">
                                        <div class="pd-diagnostic-product">
                                            @if($row['image_url'])
                                                <img src="{{ $row['image_url'] }}" alt="{{ $row['display_name'] }}" class="pd-product-thumb-sm">
                                            @endif
                                            <div>
                                                <div class="pd-diagnostic-main">{{ $row['display_name'] }}</div>
                                                <div class="pd-diagnostic-meta">{{ $row['display_code'] }}</div>
                                                <div class="pd-diagnostic-meta">Varyant: {{ $row['color'] ?: '-' }} / {{ $row['size'] ?: ($row['measure'] ?: '-') }}</div>
                                                @if($filters['technical_columns'])
                                                    <div class="pd-diagnostic-meta">Satır tipi: {{ $row['row_type'] }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="pd-diagnostic-cell">
                                        <div class="pd-diagnostic-main">{{ $row['supplier_name'] }}</div>
                                        <div class="pd-diagnostic-meta">{{ $row['supplier_category_name'] ?: 'Kategori bilgisi bekleniyor' }}</div>
                                        @if($filters['technical_columns'])
                                            <div class="pd-diagnostic-meta">Standart ürün #{{ $row['standard_product_id'] }}</div>
                                            @if($row['standard_product_variant_id'])
                                                <div class="pd-diagnostic-meta">Varyant #{{ $row['standard_product_variant_id'] }}</div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="pd-diagnostic-cell">
                                        @php
                                            $truth = $row['sellable_truth'] ?? [];
                                            $effectiveStock = $truth['effective_stock'] ?? 0;
                                            $isCurrentState = ($row['operation_state_key'] ?? null) === 'auto_updated';
                                            $controlType = match (true) {
                                                $row['category_action_required'] => 'Kategori Kararı',
                                                ($row['operation_state_key'] ?? null) === 'tenant_output_closed' => 'Görünürlük / Abone Firma çıkışı',
                                                ($row['operation_state_key'] ?? null) === 'projection_lagging' => 'Satış Listesi Gecikmesi',
                                                ($row['operation_state_key'] ?? null) === 'review_required' => 'Eksik Bilgi',
                                                ($row['operation_state_key'] ?? null) === 'auto_updated' => 'Otomatik Güncelleme',
                                                default => 'Manuel Kontrol',
                                            };
                                        @endphp
                                        <div class="pd-diagnostic-main">{{ $controlType }}</div>
                                        <div class="pd-diagnostic-meta">{{ $row['projection_visibility_label'] }}</div>
                                        <div class="pd-diagnostic-meta">{{ ($truth['category_status'] ?? 'matched') === 'category_waiting' ? 'Kategori: Bekliyor' : 'Kategori: Hazır' }}</div>
                                    </td>
                                    <td class="pd-diagnostic-cell">
                                        <div class="pd-inline-wrap-xs pd-gap-bottom-xs">
                                            <span class="pd-badge {{ $isCurrentState ? 'pd-badge-green' : $toneClass($row['operation_state_tone']) }}">{{ $isCurrentState ? 'Güncel' : $row['operation_state_label'] }}</span>
                                            <span class="pd-badge {{ ($truth['quote_visibility_status'] ?? 'hidden') === 'visible' ? 'pd-badge-blue' : 'pd-badge-light' }}">
                                                {{ ($truth['quote_visibility_status'] ?? 'hidden') === 'visible' ? 'Teklifte Açık' : 'Teklifte Kapalı' }}
                                            </span>
                                        </div>
                                        <div class="pd-diagnostic-meta">Geçerli stok: {{ $formatStock($effectiveStock) }}</div>
                                        <div class="pd-inline-wrap-xs">
                                            @foreach(collect($row['diagnostic_badges'])->reject(fn ($badge) => in_array($badge['key'], ['sellable_variant', 'sellable_flat', 'parent_only', 'not_quote_visible'], true)) as $badge)
                                                <span class="pd-badge {{ $toneClass($badge['tone']) }}">{{ $badge['label'] }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="pd-diagnostic-cell">
                                        <div class="pd-diagnostic-main">
                                            @if($row['category_action_required'])
                                                Kategori eşlemesi yapın.
                                            @elseif(($row['operation_state_key'] ?? null) === 'tenant_output_closed')
                                                Abone Firma çıkışı ve erişim koşullarını kontrol edin.
                                            @elseif(($row['operation_state_key'] ?? null) === 'projection_lagging')
                                                Satış listesine yansıma gecikmesini doğrulayın.
                                            @elseif(($row['operation_state_key'] ?? null) === 'review_required')
                                                Eksik bilgi veya şüpheli değişimi gözden geçirin.
                                            @else
                                                Kayıt otomatik akışla güncelleniyor.
                                            @endif
                                        </div>
                                        <div class="pd-diagnostic-meta">{{ $row['matched_category_name'] }}</div>
                                        @if($filters['technical_columns'])
                                            <details class="pd-inline-details pd-gap-top-xs">
                                                <summary class="pd-diagnostic-meta">Gelişmiş teknik bağlam</summary>
                                                <div class="pd-diagnostic-matrix pd-gap-top-xs">
                                                    <div><span>Kaynak katmanı</span><strong>{{ $truth['source_layer'] ?? '-' }}</strong></div>
                                                    <div><span>Satış tipi</span><strong>{{ collect($row['diagnostic_badges'])->firstWhere('key', $row['sellable_state_key'])['label'] ?? '-' }}</strong></div>
                                                    <div><span>Eşleşen kategori</span><strong>{{ $row['matched_category_name'] }}</strong></div>
                                                    <div><span>Raw stok</span><strong>{{ $formatStock($row['raw_snapshot']['stock'] ?? null) }}</strong></div>
                                                    <div><span>Standart stok</span><strong>{{ $formatStock($row['standard_snapshot']['stock'] ?? null) }}</strong></div>
                                                    <div><span>Satış Listesi stoğu</span><strong>{{ $row['projection_snapshot']['stock_label'] ?? '-' }}</strong></div>
                                                    <div><span>Abone Firma sayısı</span><strong>{{ $row['projection_snapshot']['count'] ?? 0 }}</strong></div>
                                                    <div><span>Tedarikçi ürünü</span><strong>{{ $row['raw_snapshot']['supplier_product_code'] ?: '-' }}</strong></div>
                                                    <div><span>Tedarikçi varyantı</span><strong>{{ $row['raw_snapshot']['supplier_variant_code'] ?: '-' }}</strong></div>
                                                    <div><span>Satır tipi</span><strong>{{ $row['row_type'] }}</strong></div>
                                                </div>
                                            </details>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="pd-inline-wrap-xs pd-gap-bottom-xs">
                                            <a href="{{ $row['detail_link'] }}" class="pd-btn pd-btn-sm pd-btn-light">Kaydı Aç</a>
                                            @if($row['category_action_required'])
                                                <a href="{{ route('admin.super.product-data-hub.product-panel', array_merge($panelQuery, ['category_mapping_product_id' => $row['standard_product_id']])) }}" class="pd-btn pd-btn-sm pd-btn-primary">Karar Ver</a>
                                            @else
                                                <a href="{{ $row['standard_link'] }}" class="pd-btn pd-btn-sm pd-btn-light">Detay Gör</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6">{{ ($searchFirstIdle ?? false) ? 'Kontrol için arama veya filtre seçin.' : 'Filtrelere uygun ürün bulunamadı.' }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="pd-gap-top-md">
                    {{ $rows->links() }}
                </div>
            </div>
        </section>
    </div>

    @if($drawerProduct && $drawerMapping)
        <div class="pd-drawer-overlay">
            <a href="{{ $categoryMappingDrawer['cancel_link'] }}" aria-label="Kapat" class="pd-drawer-overlay-link"></a>
            <div class="pd-drawer-panel">
                <div class="pd-drawer-head">
                    <div>
                        <div class="pd-badge pd-badge-blue pd-gap-bottom-xs">Kategori Eşle</div>
                        <h2 class="pd-card-title pd-title-gap-xs">Kategori Eşle</h2>
                        <p class="pd-card-subtitle">Tedarikçi kategorisini Prodelya standart kategorisine bağlayın.</p>
                    </div>
                    <a href="{{ $categoryMappingDrawer['cancel_link'] }}" class="pd-btn pd-btn-light pd-btn-sm">Vazgeç</a>
                </div>

                <div class="pd-card pd-gap-bottom-sm">
                    <div class="pd-card-header">
                        <div>
                            <h3 class="pd-card-title">Ürün Bilgisi</h3>
                        </div>
                    </div>
                    <div class="pd-card-body pd-drawer-product-grid">
                        <div>
                            @if($drawerProduct['image_url'])
                                <img src="{{ $drawerProduct['image_url'] }}" alt="{{ $drawerProduct['display_name'] }}" class="pd-drawer-thumb">
                            @else
                                <div class="pd-drawer-thumb-placeholder">
                                    <span class="pd-badge pd-badge-light">Görsel Yok</span>
                                </div>
                            @endif
                        </div>
                        <div class="pd-grid pd-gap-xs">
                            <div>
                                <div class="text-xs text-gray-500">Ürün Kodu</div>
                                <div class="pd-text-strong">{{ $drawerProduct['display_code'] }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Ürün Adı</div>
                                <div class="pd-text-semibold">{{ $drawerProduct['display_name'] }}</div>
                            </div>
                            <div class="pd-inline-wrap-sm">
                                <span class="pd-badge pd-badge-light">{{ $drawerProduct['supplier_name'] }}</span>
                                <span class="pd-badge pd-badge-blue">Stok: {{ number_format($drawerProduct['stock_quantity'], 0, ',', '.') }}</span>
                                <span class="pd-badge pd-badge-green">
                                    @if($drawerProduct['price'])
                                        Fiyat: {{ number_format((float) $drawerProduct['price'], 2, ',', '.') }} {{ $drawerProduct['currency'] }}
                                    @else
                                        Fiyat Eksik
                                    @endif
                                </span>
                                @if(!empty($drawerProduct['warnings']))
                                    <span class="pd-badge pd-badge-amber">{{ $drawerProduct['warnings'][0] }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pd-card pd-gap-bottom-sm">
                    <div class="pd-card-header">
                        <div>
                            <h3 class="pd-card-title">Tedarikçi Kategorisi</h3>
                        </div>
                    </div>
                    <div class="pd-card-body pd-grid pd-gap-sm">
                        <div>
                            <div class="text-xs text-gray-500">Kategori Adı</div>
                            <div class="pd-text-strong">{{ $drawerMapping->source_category }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">Kategori Yolu</div>
                            <div>{{ $drawerMapping->supplier_category_path ?: '-' }}</div>
                        </div>
                        <div class="pd-inline-wrap-sm pd-inline-align-center">
                            <span class="pd-badge pd-badge-light">Ürün Sayısı: {{ number_format((int) $drawerMapping->product_count, 0, ',', '.') }}</span>
                            @foreach(($categoryMappingDrawer['sample_products'] ?? []) as $sampleProduct)
                                <span class="pd-badge pd-badge-blue">{{ $sampleProduct }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="pd-card pd-gap-bottom-sm">
                    <div class="pd-card-header">
                        <div>
                            <h3 class="pd-card-title">Sistem Önerisi</h3>
                        </div>
                    </div>
                    <div class="pd-card-body pd-grid pd-gap-sm">
                        <div>
                            <div class="text-xs text-gray-500">Önerilen Hedef Kategori</div>
                            <div class="pd-text-strong">{{ $categoryMappingDrawer['suggestion_path'] ?: 'Henüz öneri yok' }}</div>
                        </div>
                        <div class="pd-inline-wrap-sm">
                            <span class="pd-badge pd-badge-green">Güven Skoru: {{ $categoryMappingDrawer['confidence_label'] }}</span>
                        </div>
                        <div class="pd-card-subtitle">{{ $categoryMappingDrawer['suggestion_reason'] }}</div>
                    </div>
                </div>

                <div class="pd-card">
                    <div class="pd-card-header">
                        <div>
                            <h3 class="pd-card-title">Hedef Kategori</h3>
                        </div>
                    </div>
                    <div class="pd-card-body">
                        <form method="POST" action="{{ $drawerFormAction }}" id="product-panel-category-mapping-form">
                            @csrf
                            <div class="pd-grid pd-gap-sm">
                                <div>
                                    <label class="pd-label" for="quick-category-search">Hızlı kategori arama</label>
                                    <input type="text" id="quick-category-search" class="pd-input" placeholder="Kategori adı, kodu veya yolunu ara" autocomplete="off">
                                </div>
                                <div id="quick-category-results" class="pd-grid pd-gap-xs"></div>
                                <div>
                                    <label class="pd-label" for="quick-standard-category-id">Seçilen kategori</label>
                                    <input type="hidden" name="standard_category_id" id="quick-standard-category-id" value="{{ old('standard_category_id', $drawerMapping->standard_category_id) }}">
                                    <div id="quick-selected-category" class="pd-input pd-selected-category-box">
                                        {{ old('standard_category_id') ? 'Kategori seçildi' : ($categoryMappingDrawer['suggestion_path'] ?: 'Henüz kategori seçilmedi') }}
                                    </div>
                                </div>
                                <div>
                                    <label class="pd-label">Karar Tipi</label>
                                    <div class="pd-input pd-selected-category-box">Eşle</div>
                                </div>
                                <div class="pd-inline-wrap-sm pd-inline-align-center">
                                    <button type="submit" class="pd-btn pd-btn-primary">Eşle ve Kaydet</button>
                                    <a href="{{ $categoryMappingDrawer['cancel_link'] }}" class="pd-btn pd-btn-light">Vazgeç</a>
                                    <a href="{{ $categoryMappingDrawer['advanced_link'] }}" class="pd-btn pd-btn-light">Gelişmiş Eşleme Ekranında Aç</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (function () {
                const input = document.getElementById('quick-category-search');
                const results = document.getElementById('quick-category-results');
                const selectedInput = document.getElementById('quick-standard-category-id');
                const selectedLabel = document.getElementById('quick-selected-category');
                const searchUrl = @json($drawerSearchUrl);
                const initialCategoryId = @json(old('standard_category_id', $drawerMapping->standard_category_id));
                const initialCategoryPath = @json($categoryMappingDrawer['suggestion_path']);

                if (!input || !results || !selectedInput || !selectedLabel) {
                    return;
                }

                if (initialCategoryId && initialCategoryPath) {
                    selectedInput.value = initialCategoryId;
                    selectedLabel.textContent = initialCategoryPath;
                }

                let timer = null;

                const renderResults = function (items) {
                    if (!items.length) {
                        results.innerHTML = '<div class="pd-card-subtitle">Sonuç bulunamadı.</div>';
                        return;
                    }

                    results.innerHTML = items.map(function (item) {
                        const path = item.path || item.name;
                        return '<button type="button" class="pd-btn pd-btn-light pd-btn-sm quick-category-option pd-quick-category-option" data-id="' + item.id + '" data-path="' + String(path).replace(/"/g, '&quot;') + '">' + path + '</button>';
                    }).join('');

                    results.querySelectorAll('.quick-category-option').forEach(function (button) {
                        button.addEventListener('click', function () {
                            selectedInput.value = this.getAttribute('data-id') || '';
                            selectedLabel.textContent = this.getAttribute('data-path') || 'Henüz kategori seçilmedi';
                        });
                    });
                };

                input.addEventListener('input', function () {
                    clearTimeout(timer);
                    const term = this.value.trim();

                    if (term.length < 2) {
                        results.innerHTML = '';
                        return;
                    }

                    timer = window.setTimeout(function () {
                        fetch(searchUrl + '?q=' + encodeURIComponent(term), {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json'
                            }
                        })
                            .then(function (response) { return response.json(); })
                            .then(function (items) { renderResults(Array.isArray(items) ? items : []); })
                            .catch(function () {
                                results.innerHTML = '<div class="pd-card-subtitle">Kategori araması şu anda açılamadı.</div>';
                            });
                    }, 220);
                });
            }());
        </script>
    @endif
@endsection

@section('side_summary')
    <div class="pd-side-summary">
        <div class="pd-card-body">
            <h3 class="pd-summary-title">Güncellik Özeti</h3>
            <div class="pd-status-list">
                <div class="pd-status-row"><span>Otomatik güncellendi</span><span class="pd-badge pd-badge-green">{{ $diagnosticSummary['auto_updated'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>İnceleme bekliyor</span><span class="pd-badge pd-badge-amber">{{ $diagnosticSummary['review_required'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Kategori eşleşmemiş</span><span class="pd-badge pd-badge-blue">{{ $diagnosticSummary['category_waiting'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Abone Firma çıkışı kapalı</span><span class="pd-badge pd-badge-red">{{ $diagnosticSummary['tenant_output_closed'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Toplam satır</span><span class="pd-badge pd-badge-blue">{{ $diagnosticSummary['total_rows'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Satılabilir varyant</span><span class="pd-badge pd-badge-green">{{ $diagnosticSummary['sellable_variant'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Satılabilir flat</span><span class="pd-badge pd-badge-green">{{ $diagnosticSummary['sellable_flat'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Parent / grup</span><span class="pd-badge pd-badge-purple">{{ $diagnosticSummary['parent_only'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Fiyat uyumsuz</span><span class="pd-badge pd-badge-amber">{{ $diagnosticSummary['stale_price'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Stok uyumsuz</span><span class="pd-badge pd-badge-amber">{{ $diagnosticSummary['stale_stock'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Satış listesi eski</span><span class="pd-badge pd-badge-red">{{ $diagnosticSummary['projection_outdated'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Tedarikçi erişimi kapalı</span><span class="pd-badge pd-badge-red">{{ $diagnosticSummary['supplier_access_closed'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Teklifte görünür</span><span class="pd-badge pd-badge-blue">{{ $diagnosticSummary['quote_visible'] ?? 0 }}</span></div>
                <div class="pd-status-row"><span>Teklifte görünmez</span><span class="pd-badge pd-badge-light">{{ $diagnosticSummary['not_quote_visible'] ?? 0 }}</span></div>
            </div>
            <div class="pd-side-note">Bu ekran read-only karar ekranıdır. Otomatik akış değişiklikleri burada uygulanmaz; yalnız istisna görünürlüğü sağlanır.</div>
        </div>
    </div>
@endsection
