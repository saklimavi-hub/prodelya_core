@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Kaynakları ve İlk Aktarım')
@section('page_title', 'Tedarikçi Kaynakları ve İlk Aktarım')
@section('page_subtitle', 'Kaynak bağlantısını, örnek ürünleri, alan eşlemelerini ve aktarım hazırlığını tek akıştan yönetin.')

@section('content')
@php($selectedSource = $selectedSourceOnboarding ?? $selectedSource ?? null)
@php($activeStepCard = $selectedSource ? collect($selectedSource['step_cards'])->firstWhere('key', $selectedSource['active_step']) : null)
@php($rangeStart = $sources->total() > 0 ? (($sources->currentPage() - 1) * $sources->perPage()) + 1 : 0)
@php($rangeEnd = $sources->total() > 0 ? min($sources->currentPage() * $sources->perPage(), $sources->total()) : 0)
@php($paginationWindowStart = max(1, $sources->currentPage() - 1))
@php($paginationWindowEnd = min($sources->lastPage(), $sources->currentPage() + 1))
<div class="pd-hub-family-shell pd-product-hub pd-ph-source-page">
    @if (session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="pd-alert-warning">{{ session('error') }}</div>
    @endif

    <section class="pd-hero-card">
        <div class="pd-card-body pd-stack-lg">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Tedarikçi Kaynakları ve İlk Aktarım</h1>
                    <p class="pd-hero-subtitle">Kaynak bağlantısını, örnek ürün ön kontrolünü, alan eşlemelerini ve ilk aktarım hazırlığını tek hazırlık alanı’inde yönetin.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Super Admin</span>
                        <span class="pd-badge pd-badge-green">{{ $stats['active'] }} aktif kaynak</span>
                        <span class="pd-badge pd-badge-amber">{{ $stats['mapping_required'] }} alan eşleme eksik</span>
                        <span class="pd-badge pd-badge-gray">Ön kontrol ve bağlantı testi kayıt oluşturmaz</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-btn pd-btn-primary">Yeni Kaynak</a>
                    <a href="{{ route('admin.super.product-data-hub.field-mappings.index') }}" class="pd-btn pd-btn-light">Genel Eşlemeler</a>
                    <a href="{{ route('admin.super.product-data-hub.sources.sync-reports') }}" class="pd-btn pd-btn-light">Raporları Aç</a>
                </div>
            </div>
            <div class="pd-note pd-product-hub__auto-note">Bağlantı testi ve önizleme yazma yapmaz; ürün havuzu veya Abone Firma kataloglarına veri eklemez. İşlem denemeleri sistem günlüğüne kaydedilebilir.</div>
        </div>
    </section>

    <section class="pd-kpi-strip">
        <div class="pd-metric-card pd-metric-card-soft-green"><div class="pd-metric-card-label">Aktif Kaynak</div><div class="pd-metric-card-value">{{ $stats['active'] }}</div><div class="pd-metric-card-note">Gerçek supplier_sources kaydı</div></div>
        <div class="pd-metric-card pd-metric-card-soft-amber"><div class="pd-metric-card-label">Kurulumu Eksik</div><div class="pd-metric-card-value">{{ $stats['setup_missing'] }}</div><div class="pd-metric-card-note">Kaynak bilgisi tamamlanmalı</div></div>
        <div class="pd-metric-card pd-metric-card-soft-blue"><div class="pd-metric-card-label">Bağlantı Kontrolü Gereken</div><div class="pd-metric-card-value">{{ $stats['connection_check'] }}</div><div class="pd-metric-card-note">No-write test bekliyor</div></div>
        <div class="pd-metric-card pd-metric-card-soft-slate"><div class="pd-metric-card-label">Önizleme Bekleyen</div><div class="pd-metric-card-value">{{ $stats['preview_required'] }}</div><div class="pd-metric-card-note">5-10 ürün kontrolü henüz yok</div></div>
        <div class="pd-metric-card pd-metric-card-soft-amber"><div class="pd-metric-card-label">Alan Eşleme Eksik</div><div class="pd-metric-card-value">{{ $stats['mapping_required'] }}</div><div class="pd-metric-card-note">Source-specific mapping gerekli</div></div>
        <div class="pd-metric-card pd-metric-card-soft-green"><div class="pd-metric-card-label">İlk Aktarıma Hazır</div><div class="pd-metric-card-value">{{ $stats['first_import_ready'] }}</div><div class="pd-metric-card-note">Onay akışı bu fazda disabled</div></div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Kaynak Listesi ve Filtreler</h3>
                <p class="pd-section-subtitle">Sol kaynak kartları, orta hazırlık alanı ve sağ özet birlikte görünür.</p>
                <div class="pd-note mt-2">Bağlantı Bekleyen kaynaklar önce no-write bağlantı kontrolünden geçirilmelidir.</div>
            </div>
            <div class="pd-chip-group">
                @foreach([
                    ['key' => 'active', 'label' => 'Aktif', 'count' => $tabCounts['active'] ?? null],
                    ['key' => 'temp', 'label' => 'İnceleme', 'count' => $tabCounts['temp'] ?? null],
                    ['key' => 'inactive', 'label' => 'Pasif', 'count' => $tabCounts['inactive'] ?? null],
                    ['key' => 'archived', 'label' => 'Arşiv', 'count' => $tabCounts['archived'] ?? null],
                    ['key' => 'all', 'label' => 'Tümü', 'count' => $tabCounts['all'] ?? null],
                ] as $tab)
                    @php($tabQuery = array_merge(request()->query(), ['filter' => $tab['key'], 'page' => 1]))
                    <a href="{{ route('admin.super.product-data-hub.sources.index', $tabQuery) }}" class="pd-chip {{ $activeFilter === $tab['key'] ? 'is-active' : '' }}">{{ $tab['label'] }}@if(is_numeric($tab['count'])) {{ $tab['count'] }}@endif</a>
                @endforeach
            </div>
        </div>
        <div class="pd-card-body">
            <form method="GET" action="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-form-grid-2">
                <input type="hidden" name="filter" value="{{ $activeFilter }}">
                @if($selectedSource)
                    <input type="hidden" name="source_id" value="{{ $selectedSource['id'] }}">
                @endif
                <div>
                    <label class="pd-label">Arama</label>
                    <input type="search" name="search" class="pd-input" value="{{ $filters['search'] }}" placeholder="Tedarikçi, kaynak adı veya profil ile ara">
                </div>
                <div>
                    <label class="pd-label">Durum</label>
                    <select name="status" class="pd-select">
                        <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>Tüm durumlar</option>
                        <option value="source_info_missing" {{ $filters['status'] === 'source_info_missing' ? 'selected' : '' }}>Kaynak bilgisi eksik</option>
                        <option value="connection_check_required" {{ $filters['status'] === 'connection_check_required' ? 'selected' : '' }}>Bağlantı kontrolü gerekiyor</option>
                        <option value="preview_required" {{ $filters['status'] === 'preview_required' ? 'selected' : '' }}>Önizleme bekleniyor</option>
                        <option value="mapping_required" {{ $filters['status'] === 'mapping_required' ? 'selected' : '' }}>Alan eşleme eksik</option>
                        <option value="first_import_ready" {{ $filters['status'] === 'first_import_ready' ? 'selected' : '' }}>İlk aktarıma hazır</option>
                        <option value="active_sync" {{ $filters['status'] === 'active_sync' ? 'selected' : '' }}>Aktif kaynak</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Format</label>
                    <select name="format" class="pd-select">
                        <option value="all" {{ $filters['format'] === 'all' ? 'selected' : '' }}>Tüm formatlar</option>
                        <option value="XML" {{ strtoupper($filters['format']) === 'XML' ? 'selected' : '' }}>XML</option>
                        <option value="JSON" {{ strtoupper($filters['format']) === 'JSON' ? 'selected' : '' }}>JSON</option>
                        <option value="CSV" {{ strtoupper($filters['format']) === 'CSV' ? 'selected' : '' }}>CSV</option>
                        <option value="API" {{ strtoupper($filters['format']) === 'API' ? 'selected' : '' }}>API</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Hazırlık</label>
                    <select name="readiness" class="pd-select">
                        <option value="all" {{ $filters['readiness'] === 'all' ? 'selected' : '' }}>Tümü</option>
                        <option value="80" {{ $filters['readiness'] === '80' ? 'selected' : '' }}>%80 ve üzeri</option>
                        <option value="60" {{ $filters['readiness'] === '60' ? 'selected' : '' }}>%60 ve üzeri</option>
                        <option value="below60" {{ $filters['readiness'] === 'below60' ? 'selected' : '' }}>%60 altı</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Sıralama</label>
                    <select name="sort" class="pd-select">
                        <option value="supplier" {{ $filters['sort'] === 'supplier' ? 'selected' : '' }}>Tedarikçi A-Z</option>
                        <option value="supplier_desc" {{ $filters['sort'] === 'supplier_desc' ? 'selected' : '' }}>Tedarikçi Z-A</option>
                        <option value="readiness" {{ $filters['sort'] === 'readiness' ? 'selected' : '' }}>Hazırlık yüksekten düşüğe</option>
                        <option value="state" {{ $filters['sort'] === 'state' ? 'selected' : '' }}>Duruma göre</option>
                    </select>
                </div>
                <div class="pd-form-actions">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => $activeFilter]) }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>
    </section>

    <div class="pd-ph-source-workspace">
        <section class="pd-card pd-ph-source-list pd-ph-source-list-panel">
            <div class="pd-ph-source-list-header">
                <div>
                    <h3 class="pd-card-title">Kaynak Kartları</h3>
                    <p class="pd-card-subtitle">{{ $rangeStart }}-{{ $rangeEnd }} / {{ $sources->total() }} kaynak gösteriliyor. Uzun metadata orta workspace’te tutulur.</p>
                </div>
                <div class="pd-ph-source-list-toolbar">
                    <div class="pd-ph-source-list-summary">{{ $filters['search'] !== '' ? 'Arama: ' . $filters['search'] . ' • ' : '' }}{{ strtoupper($filters['format']) !== 'ALL' ? strtoupper($filters['format']) . ' • ' : '' }}{{ $filters['status'] !== 'all' ? $filters['status'] . ' • ' : '' }}{{ $filters['per_page'] }}/sayfa</div>
                    <form method="GET" action="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-ph-source-list-per-page">
                        @foreach(request()->except('per_page', 'page') as $key => $value)
                            @if(is_array($value))
                                @foreach($value as $item)
                                    <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                                @endforeach
                            @else
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <label class="pd-label" for="pdSourcePerPage">Sayfa başına</label>
                        <select id="pdSourcePerPage" name="per_page" class="pd-select" onchange="this.form.submit()">
                            @foreach([20, 40, 80] as $option)
                                <option value="{{ $option }}" {{ (int) $filters['per_page'] === $option ? 'selected' : '' }}>{{ $option }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
            </div>

            <div class="pd-ph-source-list-scroll" aria-label="Tedarikçi kaynak kartları" tabindex="0">
                <div class="pd-ph-source-list-rows">
                    @forelse($sources as $row)
                        @php($isSelected = $selectedSource && $selectedSource['id'] === $row['id'])
                        <a
                            href="{{ route('admin.super.product-data-hub.sources.index', array_merge(request()->query(), ['source_id' => $row['id']])) }}"
                            class="pd-ph-source-card pd-ph-source-card--compact {{ $isSelected ? 'is-selected' : '' }}"
                            title="{{ $row['supplier_name'] }} / {{ $row['source_name'] }} / {{ $row['next_job'] }}"
                            @if($isSelected) aria-current="true" @endif
                        >
                            <div class="pd-ph-source-card__compact-head">
                                <div class="pd-ph-source-card__compact-copy">
                                    <div class="pd-ph-source-card__supplier" title="{{ $row['supplier_name'] }}">{{ $row['supplier_name'] }}</div>
                                    <h4 class="pd-ph-source-card__title" title="{{ $row['source_name'] }}">{{ $row['source_name'] }}</h4>
                                </div>
                                <div class="pd-ph-source-card__compact-badges">
                                    <span class="pd-badge pd-badge-gray">{{ $row['format_label'] }}</span>
                                    <span class="pd-badge pd-badge-{{ $row['state_tone'] }}">{{ $row['state_label'] }}</span>
                                </div>
                            </div>
                            <div class="pd-ph-source-card__compact-meta">
                                <span class="pd-ph-source-card__readiness">Hazırlık %{{ $row['readiness_percent'] }}</span>
                                @if($row['is_temp_profile'])
                                    <span class="pd-badge pd-badge-red">Geçici Profil</span>
                                @endif
                            </div>
                            <div class="pd-ph-source-card__compact-next" title="{{ $row['next_job'] }}">Sıradaki iş: {{ $row['next_job'] }}</div>
                            <span class="pd-ph-source-card__detail">Detaya Git</span>
                        </a>
                    @empty
                        <div class="pd-ph-source-empty">Bu filtrelerle gösterilecek kaynak bulunamadı.</div>
                    @endforelse
                </div>
            </div>

            <div class="pd-ph-source-list-pagination" aria-label="Kaynak listesi sayfalama">
                <div class="pd-ph-source-list-pagination__summary">{{ $rangeStart }}-{{ $rangeEnd }} / {{ $sources->total() }} kaynak</div>
                <div class="pd-ph-source-list-pagination__links">
                    @if($sources->onFirstPage())
                        <span class="pd-pagination-link is-disabled">Önceki</span>
                    @else
                        <a href="{{ $sources->previousPageUrl() }}" class="pd-pagination-link" aria-label="Önceki sayfa">Önceki</a>
                    @endif

                    @for($pageNumber = $paginationWindowStart; $pageNumber <= $paginationWindowEnd; $pageNumber++)
                        @if($pageNumber === $sources->currentPage())
                            <span class="pd-pagination-link is-current" aria-current="page">{{ $pageNumber }}</span>
                        @else
                            <a href="{{ $sources->url($pageNumber) }}" class="pd-pagination-link" aria-label="Sayfa {{ $pageNumber }}">{{ $pageNumber }}</a>
                        @endif
                    @endfor

                    @if($sources->hasMorePages())
                        <a href="{{ $sources->nextPageUrl() }}" class="pd-pagination-link" aria-label="Sonraki sayfa">Sonraki</a>
                    @else
                        <span class="pd-pagination-link is-disabled">Sonraki</span>
                    @endif
                </div>
            </div>
        </section>

        <section class="pd-card pd-ph-source-main">
            <div class="pd-card-body">
                @if($selectedSource)
                    <div class="pd-ph-source-main__head">
                        <div>
                            <div class="pd-source-kicker">Seçili hazırlık alanı</div>
                            <h3 class="pd-section-title">{{ $selectedSource['source_name'] }}</h3>
                            <p class="pd-section-subtitle">{{ $selectedSource['supplier_name'] }} • {{ $selectedSource['state_label'] }} • {{ $selectedSource['readiness_help'] }}</p>
                        </div>
                        <div class="pd-chip-group">
                            <span class="pd-badge pd-badge-{{ $selectedSource['state_tone'] }}">{{ $selectedSource['state_label'] }}</span>
                            <span class="pd-badge pd-badge-gray">{{ $selectedSource['sync_frequency_label'] }}</span>
                        </div>
                    </div>

                    <div class="pd-ph-source-stepper">
                        @foreach($selectedSource['step_cards'] as $index => $step)
                            <div class="pd-ph-source-step is-{{ $step['status'] }} {{ $step['key'] === $selectedSource['active_step'] ? 'is-active' : '' }}">
                                <div class="pd-ph-source-step__index">{{ $index + 1 }}</div>
                                <div>
                                    <div class="pd-ph-source-step__title">{{ $step['title'] }}</div>
                                    <div class="pd-ph-source-step__status">{{ $step['status_label'] }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="pd-ph-source-detail-grid">
                        <section class="pd-ph-source-panel pd-ph-source-panel--active-step">
                            <div class="pd-ph-source-panel__head">
                                <div>
                                    <div class="pd-ph-source-panel__eyebrow">Aktif adım</div>
                                    <h4 class="pd-ph-source-panel__title">{{ $activeStepCard['title'] ?? 'Aktif adım bulunamadı' }}</h4>
                                </div>
                                <span class="pd-badge pd-badge-blue">{{ $activeStepCard['status_label'] ?? 'Aktif' }}</span>
                            </div>
                            <p class="pd-ph-source-panel__copy">{{ $activeStepCard['note'] ?? $selectedSource['primary_action']['help'] }}</p>
                            <div class="pd-ph-source-meta-list">
                                <div class="pd-ph-source-meta-row"><span>Bağlantı özeti</span><strong>{{ $selectedSource['connection_summary'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Önizleme özeti</span><strong>{{ $selectedSource['preview_summary'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Alan eşleme</span><strong>{{ $selectedSource['mapping_summary'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Sıradaki iş</span><strong>{{ $selectedSource['next_job'] }}</strong></div>
                            </div>
                        </section>

                        <section class="pd-ph-source-panel">
                            <div class="pd-ph-source-panel__head">
                                <div>
                                    <div class="pd-ph-source-panel__eyebrow">Kaynak meta</div>
                                    <h4 class="pd-ph-source-panel__title">Bağlantı, zamanlama ve görünür alanlar</h4>
                                </div>
                            </div>
                            <div class="pd-ph-source-meta-list">
                                <div class="pd-ph-source-meta-row"><span>Host / domain</span><strong>{{ $selectedSource['location_host'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Tam konum</span><strong>{{ $selectedSource['location_display'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Son Sync</span><strong>{{ $selectedSource['last_sync_display'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Sonraki Sync</span><strong>{{ $selectedSource['next_sync_display'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Son bağlantı kontrolü</span><strong>{{ $selectedSource['last_test_display'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Son önizleme</span><strong>{{ $selectedSource['last_preview_display'] }}</strong></div>
                            </div>
                        </section>
                    </div>

                    <div class="pd-ph-source-detail-grid">
                        <section class="pd-ph-source-panel">
                            <div class="pd-ph-source-panel__head">
                                <div>
                                    <div class="pd-ph-source-panel__eyebrow">Fiyat sözleşmesi</div>
                                    <h4 class="pd-ph-source-panel__title">Brüt liste, net referans ve para birimi</h4>
                                </div>
                            </div>
                            <div class="pd-ph-source-meta-list">
                                <div class="pd-ph-source-meta-row"><span>Brüt Liste Fiyatı</span><strong>{{ $selectedSource['pricing_contract']['gross_list'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Net Referans</span><strong>{{ $selectedSource['pricing_contract']['net_reference'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Para Birimi</span><strong>{{ $selectedSource['pricing_contract']['currency'] }}</strong></div>
                                <div class="pd-ph-source-meta-row"><span>Operasyon notu</span><strong>Satış listesi güncellemesi veya Abone Firma fiyatı kaynak fiyatı olarak burada gösterilmez.</strong></div>
                            </div>
                        </section>

                        <section class="pd-ph-source-panel">
                            <div class="pd-ph-source-panel__head">
                                <div>
                                    <div class="pd-ph-source-panel__eyebrow">İlk aktarım hazırlığı</div>
                                    <h4 class="pd-ph-source-panel__title">Kontrol listesi</h4>
                                </div>
                            </div>
                            <div class="pd-ph-source-checks">
                                @foreach($selectedSource['first_import_checks'] as $check)
                                    <div class="pd-ph-source-check">
                                        <strong>{{ $check['label'] }}</strong>
                                        <span>{{ $check['done'] ? 'Hazır' : 'Henüz doğrulanmadı' }}</span>
                                    </div>
                                @endforeach
                            </div>
                            @if($selectedSource['primary_action']['disabled'])
                                <div class="pd-note">{{ $selectedSource['primary_action']['help'] }}</div>
                            @endif
                        </section>
                    </div>

                    <section class="pd-ph-source-panel">
                        <div class="pd-ph-source-panel__head">
                            <div>
                                <div class="pd-ph-source-panel__eyebrow">Yardımcı bağlantılar</div>
                                <h4 class="pd-ph-source-panel__title">Günlük erişimler</h4>
                            </div>
                        </div>
                        <div class="pd-ph-source-link-grid">
                            <a href="{{ route('admin.super.product-data-hub.sources.edit', $selectedSource['id']) }}" class="pd-btn pd-btn-light">Kaynağı Düzenle</a>
                            <a href="{{ route('admin.super.product-data-hub.sources.preview', $selectedSource['id']) }}" class="pd-btn pd-btn-light">Ön Kontrol</a>
                            <a href="{{ route('admin.super.product-data-hub.field-mappings.source', $selectedSource['id']) }}" class="pd-btn pd-btn-light">Alan Eşlemeyi Aç</a>
                            <a href="{{ route('admin.super.product-data-hub.field-mappings.index', ['source_id' => $selectedSource['id']]) }}" class="pd-btn pd-btn-light">Genel Eşlemeler</a>
                            <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-light">Abone Firma Ürün Listesi</a>
                        </div>
                    </section>

                    <details class="pd-card pd-ph-source-advanced">
                        <summary class="pd-card-header"><h4 class="pd-card-title">Gelişmiş İşlemler</h4></summary>
                        <div class="pd-card-body">
                            <div class="pd-ph-source-link-grid">
                                @foreach($selectedSource['advanced_actions'] as $action)
                                    @if($action['type'] === 'form')
                                        <form action="{{ $action['url'] }}" method="POST">
                                            @csrf
                                            <button type="submit" class="pd-btn pd-btn-light">{{ $action['label'] }}</button>
                                        </form>
                                    @else
                                        <a href="{{ $action['url'] }}" class="pd-btn pd-btn-light">{{ $action['label'] }}</a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </details>
                @else
                    <div class="pd-ph-source-empty">Seçili kaynak bulunamadı. Filtreleri değiştirerek uygun bir kaynak seçin.</div>
                @endif
            </div>
        </section>

        <aside class="pd-card pd-ph-source-summary">
            <div class="pd-card-body pd-section-stack">
                @if($selectedSource)
                    <section class="pd-ph-source-panel pd-ph-source-panel--summary">
                        <div class="pd-ph-source-panel__head">
                            <div>
                                <div class="pd-ph-source-panel__eyebrow">Sağ sticky özet</div>
                                <h4 class="pd-ph-source-panel__title">{{ $selectedSource['source_name'] }}</h4>
                            </div>
                            <span class="pd-badge pd-badge-{{ $selectedSource['state_tone'] }}">{{ $selectedSource['state_label'] }}</span>
                        </div>
                        <div class="pd-ph-source-meta-list">
                            <div class="pd-ph-source-meta-row"><span>Tedarikçi</span><strong>{{ $selectedSource['supplier_name'] }}</strong></div>
                            <div class="pd-ph-source-meta-row"><span>Format</span><strong>{{ $selectedSource['format_label'] }}</strong></div>
                            <div class="pd-ph-source-meta-row"><span>Hazırlık</span><strong>%{{ $selectedSource['readiness_percent'] }}</strong></div>
                            <div class="pd-ph-source-meta-row"><span>Sıradaki iş</span><strong>{{ $selectedSource['next_job'] }}</strong></div>
                            <div class="pd-ph-source-meta-row"><span>Son hata / uyarı</span><strong>{{ $selectedSource['preview_summary'] }}</strong></div>
                        </div>
                    </section>

                    <section class="pd-ph-source-panel pd-ph-source-panel--action">
                        <div class="pd-ph-source-panel__head">
                            <div>
                                <div class="pd-ph-source-panel__eyebrow">Tek ana aksiyon</div>
                                <h4 class="pd-ph-source-panel__title">{{ $selectedSource['primary_action']['label'] }}</h4>
                            </div>
                        </div>
                        <p class="pd-ph-source-panel__copy">{{ $selectedSource['primary_action']['help'] }}</p>
                        @if($selectedSource['primary_action']['type'] === 'form')
                            <form action="{{ $selectedSource['primary_action']['url'] }}" method="POST">
                                @csrf
                                <button type="submit" class="pd-btn pd-btn-primary pd-btn-block pd-ph-source-primary-action">{{ $selectedSource['primary_action']['label'] }}</button>
                            </form>
                        @elseif($selectedSource['primary_action']['type'] === 'link')
                            <a href="{{ $selectedSource['primary_action']['url'] }}" class="pd-btn pd-btn-primary pd-btn-block pd-ph-source-primary-action">{{ $selectedSource['primary_action']['label'] }}</a>
                        @else
                            <button type="button" class="pd-btn pd-btn-primary pd-btn-block pd-ph-source-primary-action" disabled>{{ $selectedSource['primary_action']['label'] }}</button>
                        @endif
                    </section>

                    <section class="pd-ph-source-panel pd-ph-source-panel--notes">
                        <div class="pd-ph-source-panel__head">
                            <div>
                                <div class="pd-ph-source-panel__eyebrow">No-write sözleşmesi</div>
                                <h4 class="pd-ph-source-panel__title">Operasyon notları</h4>
                            </div>
                        </div>
                        <div class="pd-section-stack">
                            @foreach($selectedSource['operational_notes'] as $note)
                                <div class="pd-note">{{ $note }}</div>
                            @endforeach
                        </div>
                    </section>
                @else
                    <div class="pd-ph-source-empty">Sağ özet göstermek için görünür bir kaynak seçin.</div>
                @endif
            </div>
        </aside>
    </div>
</div>
@endsection
