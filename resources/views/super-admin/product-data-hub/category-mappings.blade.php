@extends('layouts.prodelya-admin')

@section('title', 'Kategori Eşleme')
@section('page_title', 'Kategori Eşleme')
@section('page_subtitle', 'Tedarikçi kategorilerini Prodelya standart kategori ağacına bağlayın. Orijinal tedarikçi kategorisi silinmez; kontrol ve dışa aktarma için korunur.')

@php
    $isSimpleMode = ($filters['mode'] ?? 'simple') === 'simple';
    
    $queueOptions = [
        'queue' => 'Eşleme Kuyruğu',
        'safe_candidate' => 'Güvenli Öneriler',
        'target_missing' => 'Hedef Bulunamayan',
        'review' => 'Kontrol Gereken',
        'review_required' => 'Kontrollü İnceleme',
        'risk_groups' => 'Riskli Gruplar',
        'approved' => 'Eşlenenler',
        'cancelled' => 'İptal Edilmiş',
        'rejected' => 'Reddedilmiş',
        'separate_keep' => 'Ayrı Bırakılmış',
        'all' => 'Tümü',
    ];

    $activeQueue = $filters['queue'] ?? 'queue';
    $queueResultLabel = $queueOptions[$activeQueue] ?? 'Eşleme';
    $activeReviewGroup = $filters['review_group'] ?? 'all';
    $activeViewMode = $filters['view_mode'] ?? 'quick';
    $bulkConfirmText = 'Seçili kategori eşlemelerini kaydetmek istiyorum.';
    $riskQuickChips = [
        'desk_sumen' => 'Masa Sümeni',
        'mousepad' => 'Mousepad',
        'calendar' => 'Takvim',
        'set_boxes' => 'Set Kutuları',
        'gift_sets' => 'Hediyelik Setler',
        'cups' => 'Kupalar',
        'accessory' => 'Aksesuar',
    ];
@endphp

@if($isSimpleMode)
    @section('hide_side_summary', '1')
@endif

@section('content')
<div class="pd-page-shell">
    @if($isSimpleMode)
    <!-- Simple Mode Header -->
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Kategori Eşleme</h1>
                    <p class="pd-hero-subtitle">Tedarikçi kategorisini Prodelya standart kategorisine bağlayın. Orijinal kategori korunur; bu ekran karar kaydı içindir.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Basit Mod</span>
                        <span class="pd-badge pd-badge-amber">{{ $stats['pending'] + $stats['needs_review'] }} bekleyen</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.category-mappings.index', array_merge(request()->query(), ['mode' => 'advanced'])) }}" class="pd-btn pd-btn-light">Gelişmiş Mod</a>
                </div>
            </div>
        </div>
    </section>
    @else
    <!-- Advanced Mode Header -->
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Kategori Eşleme Kuyruğu</h1>
                    <p class="pd-hero-subtitle">Tedarikçi kategorisini, sistem önerisini ve operatör kararını yan yana görüp hızlıca bağlayın. Orijinal tedarikçi kategorisi silinmez; kontrol ve dışa aktarma için korunur.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Super Admin</span>
                        <span class="pd-badge pd-badge-green">{{ $stats['mapped'] }} öneri bağlı</span>
                        <span class="pd-badge pd-badge-amber">{{ $stats['pending'] + $stats['needs_review'] }} karar bekleyen</span>
                        <span class="pd-badge pd-badge-green">{{ $stats['safe_apply'] }} güvenli kayıt adayı</span>
                        <span class="pd-badge pd-badge-purple">{{ $stats['approved_aliases'] }} alias kayıtlı</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <form method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.scan') }}">
                        @csrf
                        <button type="submit" class="pd-btn pd-btn-primary" onclick="return confirm('Tedarikçi kategori kuyruğu yeniden taranacak. Ürün/projection veya kategori ağacı değişmez. Devam edilsin mi?')">Tedarikçi Kategori Tara</button>
                    </form>
                    <a href="{{ route('admin.super.product-data-hub.category-mappings.index', array_merge(request()->query(), ['queue' => 'safe_candidate', 'view_mode' => 'quick'])) }}" class="pd-btn pd-btn-success">{{ $stats['safe_apply'] }} güvenli adayı aç</a>
                    <a href="{{ route('admin.super.product-data-hub.category-review-batches.show', '001') }}" class="pd-btn pd-btn-warning">Review Paketi 001</a>
                    <a href="{{ route('admin.super.product-data-hub.category-cleanup.index') }}" class="pd-btn pd-btn-light">Kategori Temizlik</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-body">
            <div class="pd-view-mode-switch">
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index', array_merge(request()->query(), ['mode' => 'simple'])) }}" class="pd-filter-chip {{ $isSimpleMode ? 'active' : '' }}">Basit Eşleme</a>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index', array_merge(request()->query(), ['mode' => 'advanced'])) }}" class="pd-filter-chip {{ !$isSimpleMode ? 'active' : '' }}">Gelişmiş İnceleme</a>
            </div>
        </div>
    </section>
    @endif

    @unless($isSimpleMode)
    <section class="pd-kpi-strip">
        <div class="pd-metric-card pd-metric-card-soft-blue">
            <div class="pd-metric-card-label">Tedarikçi Kategorisi</div>
            <div class="pd-metric-card-value">{{ $stats['total'] }}</div>
            <div class="pd-metric-card-note">Taranan kaynak kategori kaydı</div>
        </div>
        <div class="pd-metric-card pd-metric-card-soft-green">
            <div class="pd-metric-card-label">Otomatik Öneri</div>
            <div class="pd-metric-card-value">{{ $stats['high_confidence'] }}</div>
            <div class="pd-metric-card-note">%90 ve üstü güvenli aday</div>
        </div>
        <div class="pd-metric-card pd-metric-card-soft-amber">
            <div class="pd-metric-card-label">Kontrol Gereken</div>
            <div class="pd-metric-card-value">{{ $stats['needs_review'] }}</div>
            <div class="pd-metric-card-note">Operatör kararı bekleyen</div>
        </div>
        <div class="pd-metric-card pd-metric-card-soft-purple">
            <div class="pd-metric-card-label">Hedef Bulunamayan</div>
            <div class="pd-metric-card-value">{{ $stats['no_target'] }}</div>
            <div class="pd-metric-card-note">Diğer’e otomatik atanmaz</div>
        </div>
    </section>

    <section class="pd-kpi-strip">
        <div class="pd-metric-card pd-metric-card-soft-green">
            <div class="pd-metric-card-label">Onaylı</div>
            <div class="pd-metric-card-value">{{ $stats['approved'] }}</div>
            <div class="pd-metric-card-note">Onaylı kategori eşleme kaydı</div>
        </div>
        <div class="pd-metric-card pd-metric-card-soft-blue">
            <div class="pd-metric-card-label">Güvenli Karar Adayı</div>
            <div class="pd-metric-card-value">{{ $stats['safe_apply'] }}</div>
            <div class="pd-metric-card-note">%95+, kontrollü risk yok, kalıcı hedef</div>
        </div>
        <div class="pd-metric-card pd-metric-card-soft-amber">
            <div class="pd-metric-card-label">Kategori Bekleyen Ürün</div>
            <div class="pd-metric-card-value">{{ $stats['refresh_waiting_products'] }}</div>
            <div class="pd-metric-card-note">Ürün tarafında kategori güncellemesi bekliyor</div>
        </div>
        <div class="pd-metric-card pd-metric-card-soft-purple">
            <div class="pd-metric-card-label">Abone Firma Kataloğu Bekleyen</div>
            <div class="pd-metric-card-value">{{ $stats['refresh_waiting_tenant_catalog'] }}</div>
            <div class="pd-metric-card-note">Abone Firma kataloğunda kategori güncellemesi bekliyor</div>
        </div>
    </section>

    <div class="pd-alert pd-alert-info">
        <div class="pd-chip-group">
            <span class="pd-badge pd-badge-amber" title="Tedarikçi kategorileri yeni kalıcı omurgaya yeniden eşlenmelidir.">Review Kararı Bekliyor</span>
            <span class="pd-badge pd-badge-blue" title="Eşleme yoksa ürün erişimi olan Abone Firma kataloglarında geçici kategoriyle görünebilir.">Geçici görünüm olabilir</span>
            <span class="pd-badge pd-badge-green" title="Onaylı mapping ürünlere ayrıca category refresh ile yansır.">Mapping / Refresh Ayrı</span>
        </div>
        <details class="pd-inline-details mt-3">
            <summary>Detay</summary>
            <p>Standart kategori ağacı yenilendi. Arşiv kategoriler hedef listelerinde kullanılmaz. Bu ekranda toplu kategori uygulaması veya kategori yenileme çalıştırılmaz; hedef bulunamayan kayıtlar otomatik “Diğer” kategorisine atanmaz.</p>
        </details>
    </div>

    <div class="pd-note mt-4">Orijinal kategori korunur. Abone Firma isterse ileride dışa aktarma çıktısında Prodelya kategorisini veya tedarikçi orijinal kategorisini kullanabilir.</div>

    <section class="pd-main-utility-grid">
        <div class="pd-nav-card pd-nav-card-compact">
            <div class="pd-card-body">
                <h3 class="pd-nav-title">Hub Navigasyonu</h3>
                <div class="pd-mini-grid">
                    <a href="#mapping-queue" class="pd-mini-link-card"><div class="pd-mini-link-title">Eşleme Kuyruğu</div><div class="pd-mini-link-copy">Bekleyenleri bağlayın</div></a>
                    <a href="#mapping-queue" class="pd-mini-link-card"><div class="pd-mini-link-title">Hızlı Kabul</div><div class="pd-mini-link-copy">Yüksek güvenli öneriler</div></a>
                    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Standart Kategori Ağacı</div><div class="pd-mini-link-copy">Canonical yapıyı açın</div></a>
                    <a href="{{ route('admin.super.product-data-hub.category-cleanup.index') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Kategori Temizlik</div><div class="pd-mini-link-copy">Duplicate ve alias adayları</div></a>
                </div>
            </div>
        </div>

        <div class="pd-section-card pd-section-card-soft-blue pd-model-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Önerilen Çalışma Akışı</h3>
                    <p class="pd-section-subtitle">Operatör kararını hızlandıran kısa adımlar.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-compact-flow-grid">
                    @foreach($workflowSteps as $step)
                        <div class="pd-compact-flow-card pd-flow-card-blue">
                            <span class="pd-badge pd-badge-blue">{{ $step['no'] }}</span>
                            <div class="pd-mini-link-title">{{ $step['title'] }}</div>
                            <div class="pd-flow-copy">{{ $step['copy'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    @endunless

    @unless($isSimpleMode)
    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tedarikçi Kategori Özeti</h3>
                <p class="pd-section-subtitle">4 tedarikçiden gelen kategori tarama çıktısını supplier bazında görün.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-source-list">
                @foreach($sourceStats as $sourceRow)
                    <div class="pd-source-row">
                        <div class="pd-source-main">
                            <h4 class="pd-source-name">{{ $sourceRow['supplier_name'] }}</h4>
                            <div class="pd-source-subline">
                                <span class="pd-muted-badge">Source #{{ $sourceRow['source_id'] }}</span>
                                <span class="pd-badge pd-badge-blue">{{ $sourceRow['category_count'] }} kategori</span>
                            </div>
                        </div>
                        <div class="pd-source-meta pd-source-meta-grid">
                            <div class="pd-source-meta-line">Eşlenen: <span class="pd-source-meta-chip">{{ $sourceRow['mapped_count'] }}</span></div>
                            <div class="pd-source-meta-line">Bekleyen: <span class="pd-source-meta-chip">{{ $sourceRow['pending_count'] }}</span></div>
                            <div class="pd-source-meta-line">Yüksek Güven: <span class="pd-source-meta-chip">{{ $sourceRow['auto_approved_count'] }}</span></div>
                            <div class="pd-source-meta-line">Kontrol: <span class="pd-source-meta-chip">{{ $sourceRow['review_count'] }}</span></div>
                        </div>
                        <div class="pd-source-actions">
                            <form method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.scan') }}">
                                @csrf
                                <input type="hidden" name="supplier_source_id" value="{{ $sourceRow['source_id'] }}">
                                <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Yeniden Tara</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endunless

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">{{ $isSimpleMode ? 'Filtreler' : 'Filtre ve Kuyruk' }}</h3>
                <p class="pd-section-subtitle">{{ $isSimpleMode ? 'Tedarikçi kategorisini bulun, hedef kategoriyi seçin ve kaydedin.' : 'Bekleyen, alias, ikiz görünüm ve temizlik adaylarını hızlıca daraltın.' }}</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" class="pd-stack-md">
                <input type="hidden" name="mode" value="{{ $filters['mode'] }}">
                <input type="hidden" name="view_mode" value="{{ $activeViewMode }}">
                <div class="pd-filter-row pd-filter-row-4">
                    <div>
                        <label class="pd-label">Tedarikçi</label>
                        <select name="supplier_id" class="pd-select">
                            <option value="">Tümü</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected(($filters['supplier_id'] ?? null) == $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Durum</label>
                        <select name="status" class="pd-select">
                            <option value="">Tümü</option>
                            <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>Bekleyen</option>
                            <option value="auto_approved" @selected(($filters['status'] ?? '') === 'auto_approved')>Otomatik Öneri</option>
                            <option value="needs_review" @selected(($filters['status'] ?? '') === 'needs_review')>Kontrol Gerekli</option>
                            <option value="conflict" @selected(($filters['status'] ?? '') === 'conflict')>Çakışmalı</option>
                            <option value="approved" @selected(($filters['status'] ?? '') === 'approved')>Onaylı</option>
                            <option value="rejected" @selected(($filters['status'] ?? '') === 'rejected')>Reddedilen</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Hedef Kategori</label>
                        <select name="standard_category_id" class="pd-select">
                            <option value="">Tümü</option>
                            @foreach($standardCategories as $category)
                                <option value="{{ $category->id }}" @selected(($filters['standard_category_id'] ?? null) == $category->id)>{{ $category->full_path }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Arama</label>
                        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" class="pd-input" placeholder="Kategori, yol veya tedarikçi ara">
                    </div>
                </div>
                @unless($isSimpleMode)
                <div>
                    <div class="pd-chip-row">
                        @foreach($queueOptions as $queueKey => $queueLabel)
                            <label class="pd-filter-chip {{ $activeQueue === $queueKey ? 'active' : '' }}">
                                <input type="radio" name="queue" value="{{ $queueKey }}" {{ $activeQueue === $queueKey ? 'checked' : '' }}>
                                <span>{{ $queueLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <div class="pd-chip-row">
                        @foreach($riskQuickChips as $groupKey => $groupLabel)
                            <a href="{{ route('admin.super.product-data-hub.category-mappings.index', array_merge(request()->query(), ['queue' => 'risk_groups', 'review_group' => $groupKey, 'view_mode' => $activeViewMode])) }}" class="pd-filter-chip {{ $activeQueue === 'risk_groups' && $activeReviewGroup === $groupKey ? 'active' : '' }}">
                                {{ $groupLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>
                @endunless
                <div class="pd-form-actions">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-green pd-product-hub__setup-flow">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Birleşik Kurulum Akışında Bu Adım</h3>
                <p class="pd-section-subtitle">Kategori eşlenirse aynı kategoriye gelen yeni ürünler tekrar sorulmadan akışa devam eder. Kategori eşlenmeyen ürünler satış listesine otomatik açılmaz; Bekleyen Kontroller tarafında kalır.</p>
            </div>
            <a href="{{ route('admin.super.product-data-hub.sources.sync-reports', ['review_only' => 1]) }}" class="pd-btn pd-btn-light">Bekleyen Kontrolleri Aç</a>
        </div>
        <div class="pd-section-body">
            <div class="pd-grid pd-grid-3">
                <div class="pd-note"><strong>1. Kaynak Bilgisi</strong><br>Kaynak kaydı tamamlandı.</div>
                <div class="pd-note"><strong>2. Ön Kontrol</strong><br>Örnek ürünler ve veri kalitesi gözden geçirildi.</div>
                <div class="pd-note"><strong>3. Alan Eşleme</strong><br>Ürün kodu, ad, kategori, fiyat, stok ve görsel alanları bağlandı.</div>
                <div class="pd-note"><strong>4. İlk Kategori Eşleme</strong><br>Şu an bu adımı tamamlıyorsunuz.</div>
                <div class="pd-note"><strong>5. Toplu Kategori Değiştir</strong><br>Aynı tedarikçi kategorisini topluca yeni hedefe bağlayabilir veya bekletebilirsiniz.</div>
                <div class="pd-note"><strong>6. Otomatik Senkron</strong><br>Eşleme tamamlanınca uygun ürünler ekstra “teklife aç” işlemi olmadan normal akışta ilerler.</div>
            </div>
        </div>
    </section>

    @unless($isSimpleMode)
    <section class="pd-section-card pd-section-card-soft-amber">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Manuel Review Listesi</h3>
                <p class="pd-section-subtitle">
                    {{ number_format($reviewReport['summary']['total_reviewable']) }} kalan kayıt sınıflandırıldı;
                    {{ number_format($reviewReport['summary']['shown']) }} kayıt gösteriliyor.
                </p>
            </div>
            <div class="flex gap-2 flex-wrap">
                <a href="{{ route('admin.super.product-data-hub.category-mappings.review-export', ['format' => 'csv', 'review_group' => $activeReviewGroup]) }}" class="pd-btn pd-btn-light pd-btn-sm">CSV Export</a>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.review-export', ['format' => 'json', 'review_group' => $activeReviewGroup]) }}" class="pd-btn pd-btn-light pd-btn-sm">JSON Export</a>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-chip-row">
                @foreach($reviewReport['groups'] as $groupKey => $groupLabel)
                    <a href="{{ route('admin.super.product-data-hub.category-mappings.index', array_merge(request()->query(), ['review_group' => $groupKey])) }}" class="pd-filter-chip {{ $activeReviewGroup === $groupKey ? 'active' : '' }}">
                        <span>{{ $groupLabel }}</span>
                        @if(($reviewReport['summary']['by_risk_group'][$groupKey] ?? $reviewReport['summary']['by_class'][$groupKey] ?? null) !== null)
                            <span class="pd-badge pd-badge-gray">{{ $reviewReport['summary']['by_risk_group'][$groupKey] ?? $reviewReport['summary']['by_class'][$groupKey] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            <form method="GET" class="pd-filter-row pd-filter-row-4 mt-3">
                @foreach(request()->except(['limit', 'page']) as $key => $value)
                    @if(is_scalar($value))
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <div>
                    <label class="pd-label">Review limit</label>
                    <select name="limit" class="pd-select">
                        @foreach([25, 50, 100] as $limitOption)
                            <option value="{{ $limitOption }}" @selected(($filters['limit'] ?? 25) === $limitOption)>{{ $limitOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="pd-btn pd-btn-primary">Review Listesini Güncelle</button>
                </div>
            </form>

            <div class="pd-kpi-strip mt-3">
                <div class="pd-metric-card pd-metric-card-soft-purple">
                    <div class="pd-metric-card-label">Alias Adayı</div>
                    <div class="pd-metric-card-value">{{ $reviewReport['summary']['by_class']['alias_candidate'] ?? 0 }}</div>
                    <div class="pd-metric-card-note">Mevcut hedefe farklı ad olarak bağlanabilir</div>
                </div>
                <div class="pd-metric-card pd-metric-card-soft-blue">
                    <div class="pd-metric-card-label">Özellik / Filtre</div>
                    <div class="pd-metric-card-value">{{ $reviewReport['summary']['by_class']['feature_attribute'] ?? 0 }}</div>
                    <div class="pd-metric-card-note">Kategori yerine özellik olarak değerlendirilmeli</div>
                </div>
                <div class="pd-metric-card pd-metric-card-soft-amber">
                    <div class="pd-metric-card-label">Yeni Kategori Adayı</div>
                    <div class="pd-metric-card-value">{{ $reviewReport['summary']['by_class']['new_category_candidate'] ?? 0 }}</div>
                    <div class="pd-metric-card-note">Kullanıcı kararı gerektirir</div>
                </div>
                <div class="pd-metric-card pd-metric-card-soft-slate">
                    <div class="pd-metric-card-label">Manuel Review</div>
                    <div class="pd-metric-card-value">{{ $reviewReport['summary']['by_class']['manual_review'] ?? 0 }}</div>
                    <div class="pd-metric-card-note">Risk sinyali veya ayrım belirsizliği var</div>
                </div>
            </div>

            <div class="pd-table-wrapper mt-3">
                <table class="pd-table pd-table-compact">
                    <thead>
                        <tr>
                            <th>Tedarikçi</th>
                            <th>Kategori</th>
                            <th>Ürün</th>
                            <th>Önerilen sınıf</th>
                            <th>Olası hedef</th>
                            <th>Risk</th>
                            <th>Karar</th>
                            <th>Sebep</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reviewReport['rows'] as $reviewRow)
                            <tr>
                                <td>{{ $reviewRow['supplier'] }}</td>
                                <td>
                                    <strong>{{ $reviewRow['supplier_category_name'] }}</strong><br>
                                    <span class="pd-muted">{{ $reviewRow['supplier_category_path'] }}</span>
                                    @if(!empty($reviewRow['sample_products']))
                                        <div class="pd-chip-row mt-1">
                                            @foreach($reviewRow['sample_products'] as $sampleProduct)
                                                <span class="pd-muted-badge">{{ $sampleProduct }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td>{{ number_format($reviewRow['product_count']) }}</td>
                                <td>{{ $reviewRow['suggested_class'] }}</td>
                                <td>{{ $reviewRow['suggested_target_category'] ?: 'Hedef yok - otomatik Diğer atanmadı' }}</td>
                                <td>
                                    <span class="pd-badge {{ $reviewRow['risk_level'] === 'high' ? 'pd-badge-red' : ($reviewRow['risk_level'] === 'medium' ? 'pd-badge-amber' : 'pd-badge-green') }}">{{ $reviewRow['risk_group'] }}</span>
                                </td>
                                <td>{{ $reviewRow['suggested_decision'] }}</td>
                                <td>{{ $reviewRow['reason'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">Bu filtre için review kaydı yok.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pd-source-list mt-3">
                @foreach($reviewReport['supplier_summary'] as $supplierRow)
                    <div class="pd-source-row">
                        <div class="pd-source-main">
                            <h4 class="pd-source-name">{{ $supplierRow['supplier_name'] }}</h4>
                            <div class="pd-source-subline">
                                <span class="pd-badge pd-badge-blue">Onaylı {{ $supplierRow['approved'] }}</span>
                                <span class="pd-badge pd-badge-amber">Bekleyen {{ $supplierRow['pending'] }}</span>
                                <span class="pd-badge pd-badge-purple">Kontrol {{ $supplierRow['review_required'] }}</span>
                                <span class="pd-badge pd-badge-gray">Hedef yok {{ $supplierRow['target_missing'] }}</span>
                            </div>
                        </div>
                        <div class="pd-source-meta">
                            <div class="pd-source-meta-line">Problem örnekleri: {{ implode(', ', $supplierRow['problem_samples']) ?: 'Yok' }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endunless

    <section id="mapping-queue" class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Kategori Eşleme Kuyruğu</h3>
                <p class="pd-section-subtitle">{{ number_format($mappings->count()) }} {{ mb_strtolower($queueResultLabel) }} kaydı gösteriliyor. Kategori adı, örnek ürünler, thumbnail ve öneri sebepleri ile hızlı karar verin.</p>
            </div>
        </div>
        <div class="pd-section-body" id="pdCategoryDecisionSaveAnchor">
            @if($mappings->isEmpty())
                <div class="pd-note">Henüz kategori kaydı bulunmuyor. Önce “Tedarikçi Kategori Tara” ile kaynakları tarayın.</div>
            @else
                @if($activeViewMode === 'quick')
                    @if($isSimpleMode)
                    <div class="pd-table-wrap pd-quick-mapping-table-wrap">
                        <table class="pd-table pd-table-compact pd-quick-mapping-table">
                            <thead>
                                <tr>
                                    <th>Tedarikçi</th>
                                    <th>Tedarikçi Kategorisi</th>
                                    <th>Ürün Sayısı</th>
                                    <th>Örnek 2 Ürün</th>
                                    <th>Sistem Önerisi</th>
                                    <th>Hedef Kategori Arama</th>
                                    <th>Durum</th>
                                    <th>Aksiyon</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($mappings as $mapping)
                                    @php
                                        $sampleNames = collect($mapping->sample_product_names ?? [])->take(2);
                                        $sampleImages = collect($mapping->sample_image_urls ?? [])->take(2);
                                        $targetCategory = $mapping->standardCategory;
                                        $targetIsPermanent = $targetCategory?->isPermanentBackbone() ?? false;
                                        $safeTargetCategoryId = $targetIsPermanent ? $targetCategory->id : '';
                                        $targetDisplayName = $targetIsPermanent
                                            ? ($targetCategory->full_path ?: $mapping->target_category)
                                            : 'Sistem hedef bulamadı';
                                        $riskText = $mapping->quick_risk_group ?: 'Genel';
                                    @endphp
                                    <tr id="mapping-{{ $mapping->id }}">
                                        <td>
                                            <strong>{{ $mapping->supplier?->name ?: '-' }}</strong><br>
                                            <span class="pd-muted">Source #{{ $mapping->supplier_source_id }}</span>
                                        </td>
                                        <td>
                                            <strong>{{ $mapping->source_category ?: '-' }}</strong><br>
                                            <span class="pd-muted">{{ $mapping->supplier_category_path ?: 'Kategori yolu yok' }}</span>
                                        </td>
                                        <td>{{ number_format((int) $mapping->product_count) }}</td>
                                        <td>
                                            @if($sampleNames->isEmpty())
                                                <span class="pd-muted">Örnek yok</span>
                                            @else
                                                @foreach($sampleNames as $sampleName)
                                                    <div>{{ $sampleName }}</div>
                                                @endforeach
                                            @endif
                                        </td>
                                        <td style="min-width:220px;">
                                            <div>{{ $targetDisplayName }}</div>
                                            <div class="pd-muted" style="margin-top:4px;">{{ $mapping->description ?: 'Sistem önerisi hazır.' }}</div>
                                        </td>
                                        <td style="min-width:260px;">
                                            <form method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.update', $mapping) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="mode" value="simple">
                                                <input type="hidden" name="view_mode" value="quick">
                                                <input type="hidden" name="mapping_status" value="approved">
                                                <input type="hidden" name="decision_type" value="map">
                                                <input
                                                    type="text"
                                                    class="pd-input pd-category-target-search"
                                                    id="mapping-category-search-{{ $mapping->id }}"
                                                    data-mapping-id="{{ $mapping->id }}"
                                                    data-search-url="{{ route('admin.super.product-data-hub.categories.search') }}"
                                                    value="{{ $safeTargetCategoryId ? $targetCategory->full_path : '' }}"
                                                    placeholder="Hedef kategori ara">
                                                <input type="hidden" name="standard_category_id" id="mapping-category-{{ $mapping->id }}" value="{{ $safeTargetCategoryId }}">
                                                <div class="pd-category-search-results" id="mapping-category-results-{{ $mapping->id }}"></div>
                                                <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
                                                    <button type="submit" class="pd-btn pd-btn-primary pd-btn-sm">Eşle ve Kaydet</button>
                                                </div>
                                            </form>
                                        </td>
                                        <td>
                                            <span class="pd-badge {{ $mapping->quick_status_badge }}">{{ $mapping->quick_status_label }}</span>
                                            @if($mapping->quick_review_required)
                                                <div style="margin-top:6px;"><span class="pd-badge pd-badge-amber">Kontrollü</span></div>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="pd-quick-row-actions">
                                                @if($mapping->quick_can_accept && !$mapping->quick_review_required)
                                                    <form method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.accept', $mapping) }}">
                                                        @csrf
                                                        <input type="hidden" name="mode" value="simple">
                                                        <input type="hidden" name="view_mode" value="quick">
                                                        <button type="submit" class="pd-btn pd-btn-success pd-btn-sm">Öneriyi Eşle</button>
                                                    </form>
                                                @elseif($mapping->quick_can_accept && $mapping->quick_review_required)
                                                    <form method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.accept', $mapping) }}">
                                                        @csrf
                                                        <input type="hidden" name="mode" value="simple">
                                                        <input type="hidden" name="view_mode" value="quick">
                                                        <input type="hidden" name="controlled_confirm" value="1">
                                                        <button type="submit" class="pd-btn pd-btn-warning pd-btn-sm" onclick="return confirm('Bu kayıt kontrol gerektiriyor. Kontrollü eşlemek istiyor musunuz?')">Kontrollü Eşle</button>
                                                    </form>
                                                @else
                                                    <button type="button" class="pd-btn pd-btn-light pd-btn-sm" disabled title="{{ $mapping->quick_disabled_reason }}">Öneriyi Eşle</button>
                                                @endif
                                                <details class="pd-inline-details pd-quick-detail">
                                                    <summary>Detay</summary>
                                                    <div class="pd-quick-detail-body">
                                                        <div><strong>Örnek ürünler:</strong> {{ $sampleNames->implode(', ') ?: 'Yok' }}</div>
                                                        <div><strong>Öneri sebebi:</strong> {{ $mapping->description ?: 'Sistem önerisi var.' }}</div>
                                                        <div><strong>Eski eşleme:</strong> {{ $mapping->target_category ?: 'Yok' }}</div>
                                                        <div><strong>Risk:</strong> {{ $riskText }}</div>
                                                        @if($sampleImages->isNotEmpty())
                                                            <div class="pd-gallery-strip mt-2">
                                                                @foreach($sampleImages as $sampleImage)
                                                                    <img src="{{ $sampleImage }}" alt="Kategori örnek görseli" class="pd-allow-large" onerror="this.style.display='none';">
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </div>
                                                </details>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                    <div id="pdMappingBulkApplyScope" class="pd-quick-bulk-form">
                        <div class="pd-quick-toolbar">
                            <div>
                                <strong>Hızlı Eşleme</strong>
                                <span class="pd-muted">Öneriyi kabul etmek tek adımda kategori kararını kaydeder; ürün veya katalog yansıtma çalışmaz.</span>
                            </div>
                            <div class="pd-quick-toolbar-actions">
                                <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-select-safe-page>Sayfadaki Güvenli Önerileri Seç</button>
                                <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-select-safe-filter>Bu Filtredeki Güvenli Önerileri Seç</button>
                                <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-clear-selection>Seçimi Temizle</button>
                                <button type="button" class="pd-btn pd-btn-warning pd-btn-sm" data-preview-selection>Seçili Kayıtları Önizle</button>
                                <button type="button" class="pd-btn pd-btn-primary pd-btn-sm" data-submit-selection>Seçili Kayıtları Uygula</button>
                            </div>
                        </div>
                        <div class="pd-quick-selection-counter" data-selection-counter>0 kayıt seçildi</div>

                        <div class="pd-table-wrap pd-quick-mapping-table-wrap">
                            <table class="pd-table pd-table-compact pd-quick-mapping-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" data-toggle-page-selection aria-label="Sayfadaki kayıtları seç"></th>
                                        <th>Tedarikçi</th>
                                        <th>Orijinal Kategori</th>
                                        <th>Ürün</th>
                                        <th>Önerilen Prodelya Kategorisi</th>
                                        <th>Güven</th>
                                        <th>Durum</th>
                                        <th>Risk / Özel Kural</th>
                                        <th>Karar</th>
                                        <th>Aksiyon</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($mappings as $mapping)
                                        @php
                                            $confidence = $mapping->confidence_score !== null ? number_format((float) $mapping->confidence_score, 0, ',', '.') . '%' : '-';
                                            $quickTarget = $mapping->quick_target_path ?: 'Hedef bulunamadı; hızlı arama ile kategori seçin';
                                            $quickDecision = match ($mapping->decision_type) {
                                                'alias' => 'Alias Yap',
                                                'twin_view' => 'İkiz Yap',
                                                'separate' => 'Ayrı Bırak',
                                                'ignore' => 'Reddet',
                                                default => 'Eşle',
                                            };
                                            $sampleNames = collect($mapping->sample_product_names ?? [])->take(3);
                                            $sampleImages = collect($mapping->sample_image_urls ?? [])->take(3);
                                        @endphp
                                        <tr
                                            data-mapping-row
                                            data-mapping-id="{{ $mapping->id }}"
                                            data-safe="{{ $mapping->quick_is_safe ? '1' : '0' }}"
                                            data-supplier="{{ e($mapping->supplier?->name ?: '-') }}"
                                            data-category="{{ e($mapping->source_category ?: '-') }}"
                                            data-target="{{ e($quickTarget) }}"
                                            data-confidence="{{ e($confidence) }}"
                                            data-status="{{ e($mapping->quick_status_label) }}"
                                            data-risk="{{ e($mapping->quick_risk_group) }}"
                                            data-applicable="{{ $mapping->quick_can_accept ? '1' : '0' }}"
                                        >
                                            <td>
                                                <input type="checkbox" name="mapping_ids[]" value="{{ $mapping->id }}" data-bulk-checkbox @disabled(!$mapping->quick_can_accept)>
                                            </td>
                                            <td>
                                                <strong>{{ $mapping->supplier?->name ?: '-' }}</strong><br>
                                                <span class="pd-muted">Source #{{ $mapping->supplier_source_id }}</span>
                                            </td>
                                            <td>
                                                <strong>{{ $mapping->source_category ?: '-' }}</strong><br>
                                                <span class="pd-muted">{{ $mapping->supplier_category_path ?: 'Kategori yolu yok' }}</span>
                                            </td>
                                            <td>{{ number_format((int) $mapping->product_count) }}</td>
                                            <td>
                                                <span class="{{ $mapping->quick_target_path ? '' : 'pd-text-danger' }}">{{ $quickTarget }}</span>
                                            </td>
                                            <td>{{ $confidence }}</td>
                                            <td>
                                                <span class="pd-badge {{ $mapping->quick_status_badge }}">{{ $mapping->quick_status_label }}</span>
                                                @if($mapping->quick_is_safe)
                                                    <span class="pd-badge pd-badge-green mt-1">Güvenli Öneri</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="pd-badge {{ $mapping->quick_is_risky ? 'pd-badge-amber' : 'pd-badge-gray' }}">{{ $mapping->quick_risk_group }}</span>
                                                @if($mapping->quick_review_required)
                                                    <span class="pd-badge pd-badge-amber mt-1">Kontrollü</span>
                                                @endif
                                            </td>
                                            <td>{{ $quickDecision }}</td>
                                            <td>
                                                <div class="pd-quick-row-actions">
                                                    @if($mapping->quick_can_accept && !$mapping->quick_review_required)
                                                        <form method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.accept', $mapping) }}">
                                                            @csrf
                                                            <input type="hidden" name="mode" value="advanced">
                                                            <input type="hidden" name="view_mode" value="quick">
                                                            <button type="submit" class="pd-btn pd-btn-success pd-btn-sm">Kategorileri Eşle</button>
                                                        </form>
                                                    @elseif($mapping->quick_can_accept && $mapping->quick_review_required)
                                                        <form method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.accept', $mapping) }}">
                                                            @csrf
                                                            <input type="hidden" name="mode" value="advanced">
                                                            <input type="hidden" name="view_mode" value="quick">
                                                            <input type="hidden" name="controlled_confirm" value="1">
                                                            <button type="submit" class="pd-btn pd-btn-warning pd-btn-sm" onclick="return confirm('Bu kayıt review/risk sinyali taşıyor. Kontrollü onaylamak istiyor musunuz?')">Kontrollü Onayla</button>
                                                        </form>
                                                    @else
                                                        <button type="button" class="pd-btn pd-btn-light pd-btn-sm" disabled title="{{ $mapping->quick_disabled_reason }}">Kategorileri Eşle</button>
                                                    @endif
                                                    <a href="{{ route('admin.super.product-data-hub.category-mappings.index', array_merge(request()->query(), ['view_mode' => 'detail', 'search' => $mapping->source_category])) }}#mapping-{{ $mapping->id }}" class="pd-btn pd-btn-light pd-btn-sm">Değiştir</a>
                                                    <details class="pd-inline-details pd-quick-detail">
                                                        <summary>Detay</summary>
                                                        <div class="pd-quick-detail-body">
                                                            <div><strong>Örnek ürünler:</strong> {{ $sampleNames->implode(', ') ?: 'Yok' }}</div>
                                                            <div><strong>Öneri sebebi:</strong> {{ $mapping->description ?: 'Operatör kararı bekleniyor.' }}</div>
                                                            <div><strong>Eski eşleme:</strong> {{ $mapping->target_category ?: 'Yok' }}</div>
                                                            @if($sampleImages->isNotEmpty())
                                                                <div class="pd-gallery-strip mt-2">
                                                                    @foreach($sampleImages as $sampleImage)
                                                                        <img src="{{ $sampleImage }}" alt="Kategori örnek görseli" class="pd-allow-large" onerror="this.style.display='none';">
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </details>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <form id="pdMappingBulkApplyForm" method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.bulk-apply') }}" class="pd-hidden-form">
                        @csrf
                        <input type="hidden" name="mode" id="pdBulkApplyMode" value="selected">
                        <input type="hidden" name="confirm" id="pdBulkApplyConfirm" value="">
                        <div id="pdBulkApplySelectedInputs"></div>
                    </form>

                    <div class="pd-modal-backdrop" id="pdBulkPreviewModal" hidden>
                        <div class="pd-modal-card">
                            <div class="pd-section-header">
                                <div>
                                    <h3 class="pd-section-title">Seçili Kayıtları Önizle</h3>
                                    <p class="pd-section-subtitle" id="pdBulkPreviewSummary">Seçili kayıt yok.</p>
                                    <p class="pd-section-subtitle" id="pdBulkPreviewDistribution">Tedarikçi ve hedef kategori dağılımı seçimden sonra görünür.</p>
                                </div>
                                <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-close-preview>Vazgeç</button>
                            </div>
                            <div class="pd-table-wrap">
                                <table class="pd-table pd-table-compact">
                                    <thead>
                                        <tr>
                                            <th>Tedarikçi</th>
                                            <th>Tedarikçi Kategorisi</th>
                                            <th>Mevcut Durum</th>
                                            <th>Önerilen Hedef</th>
                                            <th>Güven</th>
                                            <th>Risk</th>
                                            <th>Uygulanabilir mi?</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pdBulkPreviewRows"></tbody>
                                </table>
                            </div>
                            <div class="pd-category-action-row">
                                <button type="button" class="pd-btn pd-btn-light" data-close-preview>Vazgeç</button>
                                <button type="button" class="pd-btn pd-btn-success" data-submit-safe-selection>Sadece Güvenlileri Uygula</button>
                                <button type="button" class="pd-btn pd-btn-primary" data-submit-selection>Seçili Kayıtları Uygula</button>
                            </div>
                        </div>
                    </div>
                    @endif
                @else
                <div class="pd-category-queue">
                    @foreach($mappings as $mapping)
                        @php
                            $suggestionMeta = (array) ($mapping->suggestion_meta ?? []);
                            $sampleNames = collect($mapping->sample_product_names ?? [])->take(5);
                            $sampleImages = collect($mapping->sample_image_urls ?? [])->take(4);
                            $reasonText = $mapping->description ?: 'Operatör kararı bekleniyor.';
                            $secondCandidate = data_get($suggestionMeta, 'second_candidate');
                            $state = data_get($suggestionMeta, 'suggestion_state', 'pending');
                            $safeAutoApprove = (bool) data_get($suggestionMeta, 'safe_auto_approve', false);
                            $reviewRequired = (bool) data_get($suggestionMeta, 'review_required', false);
                            $specialRule = data_get($suggestionMeta, 'special_rule');
                            $featureSuggestions = (array) data_get($suggestionMeta, 'feature_suggestions', []);
                            $confidence = $mapping->confidence_score !== null ? number_format((float) $mapping->confidence_score, 0, ',', '.') . '%' : '-';
                            $queueTone = match ($mapping->mapping_status) {
                                'auto_approved' => 'pd-badge-green',
                                'conflict' => 'pd-badge-red',
                                'needs_review' => 'pd-badge-amber',
                                'approved', 'mapped' => 'pd-badge-blue',
                                'rejected', 'ignored' => 'pd-badge-red',
                                'cancelled' => 'pd-badge-gray',
                                default => 'pd-badge-gray',
                            };
                            $statusLabel = match ($mapping->mapping_status) {
                                'approved', 'mapped' => 'Eşlendi',
                                'auto_approved' => 'Otomatik Kabul Edildi',
                                'needs_review', 'conflict' => 'Kontrol Gerekli',
                                'cancelled' => 'İptal Edildi',
                                'rejected', 'ignored' => 'Reddedildi',
                                default => $mapping->standard_category_id ? 'Bekliyor' : 'Hedef Bulunamadı',
                            };
                        @endphp
                        <article class="pd-category-queue-card">
                            <span id="mapping-{{ $mapping->id }}"></span>
                            <div class="pd-category-queue-head">
                                <div>
                                    <div class="pd-category-queue-title">{{ $mapping->source_category }}</div>
                                    <div class="pd-category-queue-sub">
                                        {{ $mapping->supplier?->name ?: '-' }} · Source #{{ $mapping->supplier_source_id }} · {{ $mapping->supplier_category_path ?: 'Kategori yolu yok' }}
                                    </div>
                                </div>
                                <div class="flex gap-2 flex-wrap items-center justify-end">
                                    <span class="pd-badge {{ $queueTone }}">{{ $statusLabel }}</span>
                                    <span class="pd-badge pd-badge-blue">Güven {{ $confidence }}</span>
                                    @if($mapping->decision_type === 'alias')
                                        <span class="pd-badge pd-badge-purple">Alias adayı</span>
                                    @endif
                                    @if($mapping->decision_type === 'twin_view')
                                        <span class="pd-badge pd-badge-amber">İkiz görünüm adayı</span>
                                    @endif
                                    @if(in_array($mapping->decision_type, ['merge_candidate', 'filter_candidate'], true))
                                        <a href="{{ route('admin.super.product-data-hub.category-cleanup.index') }}" class="pd-badge pd-badge-gray">Temizlikte incele</a>
                                    @endif
                                    @if($mapping->standardCategory?->isPermanentBackbone())
                                        <span class="pd-badge pd-badge-green">Yeni Ağaca Önerildi</span>
                                    @elseif($mapping->standard_category_id)
                                        <span class="pd-badge pd-badge-amber">Eski Eşleme Arşivde</span>
                                    @endif
                                    @if($specialRule)
                                        <span class="pd-badge pd-badge-purple">Özel Kural</span>
                                    @endif
                                    @if($reviewRequired)
                                        <span class="pd-badge pd-badge-amber">Kontrol Gerekli</span>
                                    @elseif($safeAutoApprove)
                                        <span class="pd-badge pd-badge-green">Yüksek Güven</span>
                                    @elseif(($mapping->confidence_score ?? 0) > 0 && ($mapping->confidence_score ?? 0) < 60)
                                        <span class="pd-badge pd-badge-gray">Düşük Güven</span>
                                    @endif
                                </div>
                            </div>

                            <div class="pd-category-queue-body pd-category-compare-grid">
                                <div class="pd-category-samples pd-category-compare-block">
                                    <div class="pd-category-compare-title">Sol Blok — Orijinal Tedarikçi Kategorisi</div>
                                    <div class="pd-category-reason-box">
                                        <strong>{{ $mapping->supplier?->name ?: '-' }}</strong><br>
                                        {{ $mapping->source_category ?: '-' }}<br>
                                        Kod/ID: {{ $mapping->supplier_category_code ?: $mapping->id }}<br>
                                        Yol: {{ $mapping->supplier_category_path ?: 'Kategori yolu yok' }}<br>
                                        Ürün sayısı: {{ number_format((int) $mapping->product_count) }}
                                    </div>
                                    <div class="pd-category-sample-block">
                                        <div class="pd-category-sample-label">Örnek ürünler</div>
                                        <div class="pd-category-sample-list">
                                            @forelse($sampleNames as $sampleName)
                                                <span class="pd-summary-item">{{ $sampleName }}</span>
                                            @empty
                                                <span class="pd-summary-item">Örnek ürün bulunamadı.</span>
                                            @endforelse
                                        </div>
                                    </div>

                                    <div class="pd-category-sample-block">
                                        <div class="pd-category-sample-label">Thumbnail</div>
                                        <div class="pd-gallery-strip">
                                            @forelse($sampleImages as $sampleImage)
                                                <img src="{{ $sampleImage }}" alt="Kategori örnek görseli" class="pd-allow-large" onerror="this.style.display='none';">
                                            @empty
                                                <span class="pd-badge pd-badge-gray">Görsel yok</span>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>

                                <div class="pd-category-suggestion-block pd-category-compare-block">
                                    <div class="pd-category-compare-title">Orta Blok — Önerilen Prodelya Kategorisi</div>
                                    @php
                                        $targetCategory = $mapping->standardCategory;
                                        $targetIsPermanent = $targetCategory?->isPermanentBackbone() ?? false;
                                        $safeTargetCategoryId = $targetIsPermanent ? $targetCategory->id : '';
                                        $targetDisplayName = $targetIsPermanent
                                            ? ($targetCategory->full_path ?: $mapping->target_category)
                                            : 'Eski kategori arşivde - yeni omurgadan hedef seçin';
                                    @endphp
                                    <div class="pd-category-target-box">{{ $targetDisplayName ?: 'Henüz hedef önerisi yok' }}</div>
                                    @if(in_array($mapping->mapping_status, ['approved', 'auto_approved', 'mapped'], true) && $targetIsPermanent)
                                        <div class="pd-profile-note mt-2">Bu tedarikçi kategorisi yeni kategori ağacına eşlendi.</div>
                                    @elseif(!$safeTargetCategoryId)
                                        <div class="pd-profile-note mt-2">Hedef kategori bulunamadı; manuel kategori seçin.</div>
                                    @else
                                        <div class="pd-profile-note mt-2">Yeni kategori seçimi bekleniyor.</div>
                                    @endif
                                    <div class="pd-category-reason-box">{{ $reasonText }}</div>
                                    <div class="pd-chip-row mt-2">
                                        <span class="pd-muted-badge">Güven {{ $confidence }}</span>
                                        @if($state !== 'pending')
                                            <span class="pd-muted-badge">{{ $state }}</span>
                                        @endif
                                        @if($secondCandidate)
                                            <span class="pd-muted-badge">Alternatif: {{ $secondCandidate['name'] }} ({{ number_format((float) $secondCandidate['score'], 0, ',', '.') }}%)</span>
                                        @endif
                                    </div>
                                    @if(!empty($mapping->sample_keywords_preview))
                                        <div class="pd-chip-row mt-2">
                                            @foreach($mapping->sample_keywords_preview as $keyword)
                                                <span class="pd-muted-badge">{{ $keyword }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if($featureSuggestions !== [])
                                        <div class="pd-profile-note mt-2">
                                            Özellik önerisi:
                                            @foreach($featureSuggestions as $featureKey => $featureValue)
                                                <span class="pd-muted-badge">{{ $featureKey }}: {{ is_bool($featureValue) ? ($featureValue ? 'Evet' : 'Hayır') : $featureValue }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="pd-profile-note mt-2">Benzer standart kategoriler, aynı parent benzerleri ve farklı parent tekrarlar karar riskini belirler.</div>
                                </div>

                                @if($mapping->standard_category_id || ($mapping->mapping_status ?? '') !== 'cancelled')
                                    <form id="mapping-cancel-{{ $mapping->id }}" method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.cancel', $mapping) }}" class="pd-hidden-form">
                                        @csrf
                                        <input type="hidden" name="reason" value="Eşleme operatör tarafından iptal edildi.">
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.update', $mapping) }}" class="pd-category-decision-form pd-category-compare-block">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="mode" value="advanced">
                                    <input type="hidden" name="view_mode" value="detail">
                                    <div class="pd-category-compare-title">Sağ Blok — Karar</div>
                                    <div class="pd-grid pd-grid-3">
                                        <div>
                                            <label class="pd-label">Seçilen Prodelya Kategorisi</label>
                                            <input
                                                type="text"
                                                class="pd-input pd-category-target-search"
                                                id="mapping-category-search-{{ $mapping->id }}"
                                                data-mapping-id="{{ $mapping->id }}"
                                                data-search-url="{{ route('admin.super.product-data-hub.categories.search') }}"
                                                value="{{ $safeTargetCategoryId ? $targetCategory->full_path : '' }}"
                                                placeholder="Kategori adı, kodu veya yolu ara">
                                            <input type="hidden" name="standard_category_id" id="mapping-category-{{ $mapping->id }}" value="{{ $safeTargetCategoryId }}">
                                            <div class="pd-profile-note mt-1">En az 2 karakter yazın; yalnız aktif Prodelya kategori ağacı listelenir. Orijinal tedarikçi kategorisi silinmez.</div>
                                            <div class="pd-category-search-results" id="mapping-category-results-{{ $mapping->id }}"></div>
                                        </div>
                                        <div>
                                            <label class="pd-label">Karar</label>
                                            <select name="decision_type" class="pd-select" id="mapping-decision-{{ $mapping->id }}">
                                                <option value="map" @selected(($mapping->decision_type ?? 'map') === 'map')>Eşle</option>
                                                <option value="alias" @selected(($mapping->decision_type ?? '') === 'alias')>Alias Yap</option>
                                                <option value="twin_view" @selected(($mapping->decision_type ?? '') === 'twin_view')>İkiz Yap</option>
                                                <option value="separate" @selected(($mapping->decision_type ?? '') === 'separate')>Ayrı Bırak</option>
                                                <option value="ignore" @selected(($mapping->decision_type ?? '') === 'ignore')>Reddet</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="pd-label">Durum</label>
                                            <select name="mapping_status" class="pd-select" id="mapping-status-{{ $mapping->id }}">
                                                <option value="pending" @selected(($mapping->mapping_status ?? 'pending') === 'pending')>Bekleyen</option>
                                                <option value="auto_approved" @selected(($mapping->mapping_status ?? '') === 'auto_approved')>Otomatik Kabul</option>
                                                <option value="approved" @selected(($mapping->mapping_status ?? '') === 'approved')>Onaylı</option>
                                                <option value="needs_review" @selected(($mapping->mapping_status ?? '') === 'needs_review')>Kontrol Edildi</option>
                                                <option value="conflict" @selected(($mapping->mapping_status ?? '') === 'conflict')>Çakışmalı</option>
                                                <option value="cancelled" @selected(($mapping->mapping_status ?? '') === 'cancelled')>İptal Edildi</option>
                                                <option value="rejected" @selected(($mapping->mapping_status ?? '') === 'rejected')>Reddedildi</option>
                                                <option value="ignored" @selected(($mapping->mapping_status ?? '') === 'ignored')>Yok Sayıldı</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="pd-decision-helper mt-3">
                                        <span><strong>Eşle:</strong> doğrudan standart kategoriye bağlar.</span>
                                        <span><strong>Alias Yap:</strong> yeni kategori açmadan aynı hedefe bağlar.</span>
                                        <span><strong>İkiz Yap:</strong> canonical veri tek kalır, katalogda çok görünüm olur.</span>
                                        <span><strong>Ayrı Bırak:</strong> benzer ama farklı ürün olarak saklar.</span>
                                        <span><strong>Temizlik kararları:</strong> duplicate ve filtre adayları Kategori Temizlik ekranında incelenir.</span>
                                    </div>
                                    <div class="pd-grid pd-grid-3 mt-3">
                                        <div>
                                            <label class="pd-label">Güven Skoru</label>
                                            <input type="number" name="confidence_score" min="0" max="100" step="0.01" value="{{ old('confidence_score', $mapping->confidence_score) }}" class="pd-input">
                                        </div>
                                        <div style="grid-column: span 2;">
                                            <label class="pd-label">Karar Notu</label>
                                            <input type="text" name="note" value="{{ old('note', $mapping->decision_note ?: $mapping->description) }}" class="pd-input" placeholder="Operatör notu, alias gerekçesi veya ikiz görünüm açıklaması">
                                        </div>
                                    </div>
                                    <div class="pd-category-action-row">
                                        <button type="submit" class="pd-btn pd-btn-success pd-btn-sm" onclick="pdApplyCategoryAction({{ $mapping->id }}, 'approved', 'map', '{{ $safeTargetCategoryId }}'); return true;" @disabled(!$safeTargetCategoryId)>Kategorileri Eşle</button>
                                        <button type="button" class="pd-btn pd-btn-light pd-btn-sm" onclick="pdApplyCategoryAction({{ $mapping->id }}, 'approved', 'alias', '{{ $safeTargetCategoryId }}')">Alias Kaydet</button>
                                        <button type="button" class="pd-btn pd-btn-warning pd-btn-sm" onclick="pdApplyCategoryAction({{ $mapping->id }}, 'needs_review', 'twin_view', '{{ $safeTargetCategoryId }}')">İkiz Yap</button>
                                        <button type="button" class="pd-btn pd-btn-light pd-btn-sm" onclick="pdApplyCategoryAction({{ $mapping->id }}, 'needs_review', 'separate', '')">Ayrı Bırak</button>
                                        <button type="button" class="pd-btn pd-btn-danger pd-btn-sm" onclick="pdApplyCategoryAction({{ $mapping->id }}, 'rejected', 'ignore', '')">Reddet</button>
                                        <button type="submit" class="pd-btn pd-btn-primary pd-btn-sm">Değişikliği Kaydet</button>
                                        @if($mapping->standard_category_id || ($mapping->mapping_status ?? '') !== 'cancelled')
                                            <button type="submit" form="mapping-cancel-{{ $mapping->id }}" class="pd-btn pd-btn-danger-soft pd-btn-sm" onclick="return confirm('Bu eşlemeyi iptal etmek ve tedarikçi kategorisini tekrar kontrol kuyruğuna almak istiyor musunuz?')">Eşlemeyi İptal Et</button>
                                        @endif
                                    </div>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
                @endif
            @endif
        </div>
    </section>

</div>
@endsection

@section('side_summary')
@unless($isSimpleMode)
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Yönetim Özeti</h3>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Standart kategori</span><span class="pd-badge pd-badge-blue">{{ $standardCategories->count() }}</span></div>
            <div class="pd-status-row"><span>Aktif tedarikçi</span><span class="pd-badge pd-badge-green">{{ $sources->count() }}</span></div>
            <div class="pd-status-row"><span>Eşleme bekleyen</span><span class="pd-badge pd-badge-amber">{{ $stats['pending'] + $stats['needs_review'] }}</span></div>
            <div class="pd-status-row"><span>Alias kayıtlı</span><span class="pd-badge pd-badge-purple">{{ $stats['approved_aliases'] }}</span></div>
            <div class="pd-status-row"><span>İkiz görünüm</span><span class="pd-badge pd-badge-gray">{{ $stats['twin_views'] }}</span></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Aksiyonlar</h4>
            <div class="pd-summary-action-list">
                <form method="POST" action="{{ route('admin.super.product-data-hub.category-mappings.scan') }}">
                    @csrf
                    <button type="submit" class="pd-summary-action pd-summary-action-button"><span>Kategori Tara</span><span class="pd-badge pd-badge-blue">Tara</span></button>
                </form>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index', ['queue' => 'safe_candidate', 'view_mode' => 'quick']) }}" class="pd-summary-action"><span>Güvenli Öneriler</span><span class="pd-badge pd-badge-green">{{ $stats['safe_apply'] }}</span></a>
                <a href="{{ route('admin.super.product-data-hub.category-cleanup.index') }}" class="pd-summary-action"><span>Kategori Temizlik</span><span class="pd-badge pd-badge-amber">Temizle</span></a>
                <a href="{{ route('admin.super.product-data-hub.category-feature-templates.index') }}" class="pd-summary-action"><span>Özellik Şablonları</span><span class="pd-badge pd-badge-blue">Şablon</span></a>
            </div>
        </div>

        <div class="pd-side-note">Kategori ağacı sade kalmalı; renk, malzeme, ölçü ve baskı türü gibi detaylar mümkün olduğunca filtre/özellik katmanına taşınmalıdır.</div>
    </div>
</div>
@endunless
@endsection

@section('bottom_actions')
@unless($isSimpleMode)
<div>
    <strong>Sonraki adım:</strong>
    <span class="pd-muted">Kategori taraması, karar kaydı ve gerektiğinde toplu değiştir aynı bar üzerinden yönetilir. Eşleme tamamlanınca uygun ürünler otomatik senkron akışına devam eder.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">Önizleme</a>
    <button type="button" class="pd-btn pd-btn-primary" onclick="document.getElementById('pdCategoryDecisionSaveAnchor')?.scrollIntoView({behavior:'smooth'});">Kararları Kaydet</button>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index', ['queue' => 'safe_candidate', 'view_mode' => 'quick']) }}" class="pd-btn pd-btn-warning">Güvenli Önerileri Aç</a>
</div>
@endunless
@endsection

@push('scripts')
<script>
const pdBulkApplyConfirmText = @json($bulkConfirmText);

function pdApplyCategoryAction(mappingId, status, decisionType, fallbackCategoryId) {
    const statusEl = document.getElementById('mapping-status-' + mappingId);
    const decisionEl = document.getElementById('mapping-decision-' + mappingId);
    const categoryEl = document.getElementById('mapping-category-' + mappingId);

    if (statusEl) {
        statusEl.value = status;
    }

    if (decisionEl) {
        decisionEl.value = decisionType;
    }

    if (categoryEl && !categoryEl.value && fallbackCategoryId) {
        categoryEl.value = fallbackCategoryId;
    }

    if (decisionType === 'ignore' && categoryEl) {
        categoryEl.value = '';
    }
}

function pdMappingSelectedCheckboxes() {
    return Array.from(document.querySelectorAll('[data-bulk-checkbox]:checked'));
}

function pdUpdateBulkSelectionCounter() {
    const counter = document.querySelector('[data-selection-counter]');
    if (!counter) {
        return;
    }

    const selected = pdMappingSelectedCheckboxes();
    const safeCount = selected.filter((checkbox) => checkbox.closest('[data-mapping-row]')?.dataset.safe === '1').length;
    counter.textContent = selected.length === 0
        ? '0 kayıt seçildi'
        : `${selected.length} kayıt seçildi · ${safeCount} güvenli öneri`;
}

function pdSetBulkSelection(onlySafe = false) {
    document.querySelectorAll('[data-bulk-checkbox]').forEach((checkbox) => {
        const row = checkbox.closest('[data-mapping-row]');
        checkbox.checked = !checkbox.disabled && (!onlySafe || row?.dataset.safe === '1');
    });
    pdUpdateBulkSelectionCounter();
}

function pdClearBulkSelection() {
    document.querySelectorAll('[data-bulk-checkbox]').forEach((checkbox) => {
        checkbox.checked = false;
    });
    pdUpdateBulkSelectionCounter();
}

function pdOpenBulkPreview() {
    const modal = document.getElementById('pdBulkPreviewModal');
    const body = document.getElementById('pdBulkPreviewRows');
    const summary = document.getElementById('pdBulkPreviewSummary');
    const distribution = document.getElementById('pdBulkPreviewDistribution');
    const selected = pdMappingSelectedCheckboxes();

    if (!modal || !body || !summary || !distribution) {
        return;
    }

    const rows = selected.map((checkbox) => checkbox.closest('[data-mapping-row]')).filter(Boolean);
    const safeCount = rows.filter((row) => row.dataset.safe === '1').length;
    const applicableCount = rows.filter((row) => row.dataset.applicable === '1').length;
    const riskyCount = rows.filter((row) => row.dataset.safe !== '1').length;
    const supplierDistribution = rows.reduce((items, row) => {
        items[row.dataset.supplier || '-'] = (items[row.dataset.supplier || '-'] || 0) + 1;
        return items;
    }, {});
    const targetDistribution = rows.reduce((items, row) => {
        items[row.dataset.target || '-'] = (items[row.dataset.target || '-'] || 0) + 1;
        return items;
    }, {});
    const summarizeDistribution = (items) => Object.entries(items)
        .slice(0, 4)
        .map(([label, count]) => `${label}: ${count}`)
        .join(' · ') || '-';

    summary.textContent = `${rows.length} seçili · ${applicableCount} uygulanabilir · ${safeCount} güvenli · ${riskyCount} risk/kontrol gerektiren`;
    distribution.textContent = `Tedarikçi dağılımı: ${summarizeDistribution(supplierDistribution)} | Hedef kategori dağılımı: ${summarizeDistribution(targetDistribution)} | Category refresh otomatik çalışmaz.`;
    body.innerHTML = rows.length === 0
        ? '<tr><td colspan="7">Seçili kayıt yok.</td></tr>'
        : rows.map((row) => `
            <tr>
                <td>${pdEscapeHtml(row.dataset.supplier)}</td>
                <td>${pdEscapeHtml(row.dataset.category)}</td>
                <td>${pdEscapeHtml(row.dataset.status)}</td>
                <td>${pdEscapeHtml(row.dataset.target)}</td>
                <td>${pdEscapeHtml(row.dataset.confidence)}</td>
                <td>${pdEscapeHtml(row.dataset.risk)}</td>
                <td>${row.dataset.applicable === '1' ? 'Evet' : 'Atlanır'}</td>
            </tr>
        `).join('');

    modal.hidden = false;
}

function pdCloseBulkPreview() {
    const modal = document.getElementById('pdBulkPreviewModal');
    if (modal) {
        modal.hidden = true;
    }
}

function pdSubmitBulkApply(mode = 'selected') {
    const selected = pdMappingSelectedCheckboxes();
    const form = document.getElementById('pdMappingBulkApplyForm');
    const inputs = document.getElementById('pdBulkApplySelectedInputs');
    const modeInput = document.getElementById('pdBulkApplyMode');
    const confirmInput = document.getElementById('pdBulkApplyConfirm');

    if (!form || !inputs || !modeInput || !confirmInput) {
        return;
    }

    if (selected.length === 0) {
        alert('Önce en az bir kategori eşleme kaydı seçin.');
        return;
    }

    const confirmation = window.prompt('Toplu eşleme mapping kararlarını kaydeder; ürün/projection refresh çalıştırmaz. Devam etmek için şu metni yazın:', pdBulkApplyConfirmText);
    if (confirmation !== pdBulkApplyConfirmText) {
        alert('Onay metni eşleşmedi; işlem yapılmadı.');
        return;
    }

    inputs.innerHTML = selected.map((checkbox) => `<input type="hidden" name="mapping_ids[]" value="${pdEscapeHtml(checkbox.value)}">`).join('');
    modeInput.value = mode;
    confirmInput.value = confirmation;
    form.submit();
}

document.querySelectorAll('[data-bulk-checkbox]').forEach((checkbox) => {
    checkbox.addEventListener('change', pdUpdateBulkSelectionCounter);
});

document.querySelector('[data-toggle-page-selection]')?.addEventListener('change', (event) => {
    document.querySelectorAll('[data-bulk-checkbox]').forEach((checkbox) => {
        if (!checkbox.disabled) {
            checkbox.checked = event.target.checked;
        }
    });
    pdUpdateBulkSelectionCounter();
});

document.querySelector('[data-select-safe-page]')?.addEventListener('click', () => pdSetBulkSelection(true));
document.querySelector('[data-select-safe-filter]')?.addEventListener('click', () => pdSetBulkSelection(true));
document.querySelector('[data-clear-selection]')?.addEventListener('click', pdClearBulkSelection);
document.querySelector('[data-preview-selection]')?.addEventListener('click', pdOpenBulkPreview);
document.querySelectorAll('[data-close-preview]').forEach((button) => button.addEventListener('click', pdCloseBulkPreview));
document.querySelectorAll('[data-submit-selection]').forEach((button) => button.addEventListener('click', () => pdSubmitBulkApply('selected')));
document.querySelector('[data-submit-safe-selection]')?.addEventListener('click', () => pdSubmitBulkApply('only_safe'));

pdUpdateBulkSelectionCounter();

function pdEscapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[character]));
}

document.querySelectorAll('.pd-category-target-search').forEach((input) => {
    let timer = null;
    const mappingId = input.dataset.mappingId;
    const resultBox = document.getElementById('mapping-category-results-' + mappingId);
    const hiddenInput = document.getElementById('mapping-category-' + mappingId);

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const query = input.value.trim();

        if (hiddenInput) {
            hiddenInput.value = '';
        }

        if (!resultBox || query.length < 2) {
            if (resultBox) {
                resultBox.innerHTML = '';
            }
            return;
        }

        timer = setTimeout(() => {
            const url = new URL(input.dataset.searchUrl, window.location.origin);
            url.searchParams.set('q', query);

            fetch(url.toString(), {headers: {'Accept': 'application/json'}})
                .then((response) => response.ok ? response.json() : [])
                .then((categories) => {
                    if (!Array.isArray(categories) || categories.length === 0) {
                        resultBox.innerHTML = '<div class="pd-profile-note">Eşleşen aktif kategori bulunamadı.</div>';
                        return;
                    }

                    resultBox.innerHTML = categories.map((category) => `
                        <button type="button" class="pd-category-search-option" data-category-id="${pdEscapeHtml(category.id)}" data-category-path="${pdEscapeHtml(category.path)}">
                            <strong>${pdEscapeHtml(category.name)}</strong>
                            <span>${pdEscapeHtml(category.code)}</span>
                            <small>${pdEscapeHtml(category.path)}</small>
                        </button>
                    `).join('');
                })
                .catch(() => {
                    resultBox.innerHTML = '<div class="pd-profile-note">Kategori araması şu an tamamlanamadı.</div>';
                });
        }, 220);
    });

    resultBox?.addEventListener('click', (event) => {
        const option = event.target.closest('.pd-category-search-option');
        if (!option) {
            return;
        }

        if (hiddenInput) {
            hiddenInput.value = option.dataset.categoryId;
        }

        input.value = option.dataset.categoryPath;
        resultBox.innerHTML = '';
    });
});
</script>
@endpush
