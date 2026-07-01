@extends('layouts.prodelya-admin')

@section('title', 'Senkron ve Raporlar')

@section('content')
@php
    $latestRun = $runs->first();
    $latestPayload = $latestRun?->report_payload ?? [];
    $applySummary = data_get($latestPayload, 'delta_apply_summary', []);
    $reviewSummary = [
        'new_product' => $changes->where('change_type', 'new_product')->count(),
        'new_variant' => $changes->where('change_type', 'new_variant')->count(),
        'missing_product' => $changes->where('change_type', 'missing_product')->count(),
        'missing_variant' => $changes->where('change_type', 'missing_variant')->count(),
        'passive_candidate' => $changes->where('review_status', 'passive_candidate')->count(),
        'blocked_required_field_missing' => $changes->where('change_type', 'blocked_required_field_missing')->count(),
        'content_or_category' => $changes->filter(fn ($change) => in_array($change->change_type, ['content_changed', 'category_changed', 'image_changed', 'variant_structure_changed'], true))->count(),
    ];
    $summaryCards = [
        ['label' => 'Son işlem', 'value' => $latestRun?->display_run_type ?? 'Henüz yok', 'class' => 'pd-metric-card-soft-blue'],
        ['label' => 'Başarılı / uyarılı / hatalı', 'value' => ($latestRun?->products_updated ?? 0) . ' / ' . (($latestRun?->blocked_total ?? 0) + ($latestRun?->error_count ?? 0)) . ' / ' . ($latestRun?->error_count ?? 0), 'class' => 'pd-metric-card-soft-slate'],
        ['label' => 'Fiyat değişimi', 'value' => $latestRun?->price_changed_count ?? 0, 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Stok değişimi', 'value' => $latestRun?->stock_changed_count ?? 0, 'class' => 'pd-metric-card-soft-purple'],
        ['label' => 'İnceleme bekleyen', 'value' => $changes->whereNotNull('review_status')->count(), 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Kataloğa yansıyan', 'value' => data_get($latestPayload, 'projection.updated_products', 0), 'class' => 'pd-metric-card-soft-red'],
    ];
    $decisionCards = [
        ['label' => 'Otomatik güncellenen', 'value' => $decisionSummary['automatic_updates'] ?? 0, 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Review gerektiren', 'value' => $decisionSummary['review_required'] ?? 0, 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Yeni ürün / varyant', 'value' => $decisionSummary['new_items'] ?? 0, 'class' => 'pd-metric-card-soft-blue'],
        ['label' => 'Kategori bekleyen', 'value' => $decisionSummary['category_waiting'] ?? 0, 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Kimlik / varyant sorunu', 'value' => $decisionSummary['identity_issues'] ?? 0, 'class' => 'pd-metric-card-soft-red'],
        ['label' => 'Projection bekleyen', 'value' => $decisionSummary['projection_pending'] ?? 0, 'class' => 'pd-metric-card-soft-purple'],
    ];
@endphp

<div class="pd-hub-family-shell">
    @if (session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="pd-alert-warning">{{ session('error') }}</div>
    @endif

    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Senkron ve Raporlar</h1>
                    <p class="pd-hero-subtitle">Kaynak güncelleme sonuçlarını, fiyat/stok değişimlerini ve inceleme bekleyen kayıtları günlük karar için daha net izleyin.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Kaynak Güncelleme</span>
                        <span class="pd-badge pd-badge-green">Fiyat / Stok</span>
                        <span class="pd-badge pd-badge-amber">İnceleme Bekleyenler</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Kaynaklara Dön</a>
                    <a href="{{ route('admin.super.product-data-hub.sources.sync-reports', ['review_only' => 1]) }}" class="pd-btn pd-btn-warning">İnceleme Bekleyenleri Aç</a>
                </div>
            </div>
            <div class="pd-note mt-4">Önizleme canlı veriyi gösterir; katalog ve teklif fiyatları ancak fiyat/stok güncelleme ve Abone Katalog yansıtma sonrası güncellenir.</div>
            <div class="pd-note mt-3">Hedef akışta normal fiyat ve stok değişimleri sessizce ilerler; bu ekran yalnız yeni ürün, kategori, kimlik ve projection istisnalarını görünür kılmak için kullanılır.</div>
            <div class="pd-note mt-3">Otomatik saatlik/günlük/haftalık çalışmalar için sunucuda Laravel scheduler aktif olmalıdır.</div>
        </div>
    </section>

    <section class="pd-metric-grid">
        @foreach($summaryCards as $metric)
            <div class="pd-metric-card {{ $metric['class'] }}">
                <div class="pd-metric-card-label">{{ $metric['label'] }}</div>
                <div class="pd-metric-card-value">{{ $metric['value'] }}</div>
            </div>
        @endforeach
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Otomatik Akış ve Review Ayrımı</h3>
                <p class="pd-section-subtitle">{{ $decisionSummary['note'] ?? 'Normal değişiklikler sessiz akışta ilerler; yalnız istisnalar operatöre iş çıkarır.' }}</p>
            </div>
            <span class="pd-badge pd-badge-{{ $decisionSummary['state_tone'] ?? 'blue' }}">{{ $decisionSummary['state_label'] ?? 'Henüz delta raporu yok' }}</span>
        </div>
        <div class="pd-section-body">
            <div class="pd-kpi-strip">
                @foreach($decisionCards as $metric)
                    <div class="pd-metric-card {{ $metric['class'] }}">
                        <div class="pd-metric-card-label">{{ $metric['label'] }}</div>
                        <div class="pd-metric-card-value">{{ $metric['value'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler</h3>
                <p class="pd-section-subtitle">Tedarikçi, işlem tipi ve inceleme kuyruğuna göre görünümü daraltın.</p>
            </div>
            <span class="pd-badge pd-badge-blue">Günlük görünüm</span>
        </div>
        <div class="pd-section-body">
            <form method="GET" class="pd-form-grid-2">
                <div>
                    <label class="pd-label">Hazır Tedarikçi Kaynağı</label>
                    <select name="source_id" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($sources as $source)
                            <option value="{{ $source->id }}" {{ (int) $sourceId === $source->id ? 'selected' : '' }}>{{ $source->supplier->name }} / {{ $source->source_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">İşlem tipi</label>
                    <select name="change_type" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach([
                            'price_changed' => 'Fiyat değişimi',
                            'stock_changed' => 'Stok değişimi',
                            'price_and_stock_changed' => 'Fiyat + stok değişimi',
                            'new_product' => 'Yeni ürün',
                            'new_variant' => 'Yeni varyant',
                            'missing_product' => 'Kaynakta görünmeyen ürün',
                            'missing_variant' => 'Kaynakta görünmeyen varyant',
                            'content_changed' => 'İçerik değişimi',
                            'category_changed' => 'Kategori değişimi',
                            'image_changed' => 'Görsel değişimi',
                            'variant_structure_changed' => 'Varyant yapısı değişimi',
                            'blocked_required_field_missing' => 'Eksik zorunlu alan',
                        ] as $value => $label)
                            <option value="{{ $value }}" {{ $changeType === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Durum</label>
                    <select name="review_status" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach([
                            'pending_review' => 'İnceleme bekliyor',
                            'reviewed' => 'İncelendi',
                            'approved_for_standard_build' => 'Hazır ürün onaylı',
                            'approved_for_projection' => 'Kataloğa yansıtma onaylı',
                            'ignored' => 'Yok sayıldı',
                            'passive_candidate' => 'Pasife alma adayı',
                            'resolved' => 'Çözüldü',
                        ] as $value => $label)
                            <option value="{{ $value }}" {{ $reviewStatus === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">İnceleme Bekleyenler</label>
                    <select name="review_only" class="pd-select">
                        <option value="0" {{ !$reviewOnly ? 'selected' : '' }}>Tüm kayıtlar</option>
                        <option value="1" {{ $reviewOnly ? 'selected' : '' }}>Sadece İnceleme Bekleyenler</option>
                    </select>
                </div>
                <div>
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-purple">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Fiyat / Stok Güncelleme Özeti</h3>
                <p class="pd-section-subtitle">Bu bölüm teknik apply dilini değil, normal değişikliklerin ne kadarının sessiz akışta ilerlediğini gösterir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-grid pd-grid-3">
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Fiyat/stok kontrol edildi</div>
                    <div class="pd-profile-info-value">{{ $latestRun?->records_read ?? 0 }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Fiyat değişen</div>
                    <div class="pd-profile-info-value">{{ $latestRun?->price_changed_count ?? 0 }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Stok değişen</div>
                    <div class="pd-profile-info-value">{{ $latestRun?->stock_changed_count ?? 0 }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Fiyat + stok değişen</div>
                    <div class="pd-profile-info-value">{{ data_get($applySummary, 'price_and_stock_changed', 0) }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Güncellenen kayıt</div>
                    <div class="pd-profile-info-value">{{ data_get($applySummary, 'price_stock_applied', 0) ?: (($decisionSummary['automatic_updates'] ?? 0)) }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Kataloğa yansıtılan dirty ürün</div>
                    <div class="pd-profile-info-value">{{ data_get($applySummary, 'projected_dirty_products', data_get($latestPayload, 'projection.updated_products', 0)) }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Atlanan kayıt</div>
                    <div class="pd-profile-info-value">{{ data_get($applySummary, 'skipped_review_only_changes', 0) + data_get($applySummary, 'skipped_required_field_missing', 0) }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">İncelemeye bırakılan</div>
                    <div class="pd-profile-info-value">{{ $decisionSummary['review_required'] ?? data_get($applySummary, 'review_only_changes_detected', 0) }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Hata sayısı</div>
                    <div class="pd-profile-info-value">{{ $latestRun?->error_count ?? 0 }}</div>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-green">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">İnceleme Bekleyenler</h3>
                <p class="pd-section-subtitle">Normal fiyat/stok değişimleri burada iş üretmez. Yalnız yeni ürün, kimlik sorunu, kategori ve eksik alan gibi gerçek istisnalar ayrılır.</p>
            </div>
            <a href="{{ route('admin.super.product-data-hub.sources.sync-reports', ['review_only' => 1, 'source_id' => $sourceId]) }}" class="pd-btn pd-btn-light pd-btn-sm">İnceleme Bekleyenler filtresi</a>
        </div>
        <div class="pd-section-body">
            <div class="pd-grid pd-grid-3">
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Yeni ürün</div>
                    <div class="pd-profile-info-value">{{ $reviewSummary['new_product'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Yeni varyant</div>
                    <div class="pd-profile-info-value">{{ $reviewSummary['new_variant'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Kaynakta görünmeyen ürün</div>
                    <div class="pd-profile-info-value">{{ $reviewSummary['missing_product'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Kaynakta görünmeyen varyant</div>
                    <div class="pd-profile-info-value">{{ $reviewSummary['missing_variant'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Pasife alma adayı</div>
                    <div class="pd-profile-info-value">{{ $reviewSummary['passive_candidate'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Eksik zorunlu alan</div>
                    <div class="pd-profile-info-value">{{ $reviewSummary['blocked_required_field_missing'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">İçerik / kategori / görsel değişimi</div>
                    <div class="pd-profile-info-value">{{ $reviewSummary['content_or_category'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Kimlik / varyant sorunu</div>
                    <div class="pd-profile-info-value">{{ $decisionSummary['identity_issues'] ?? 0 }}</div>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Senkron Çalışmaları</h3>
                <p class="pd-section-subtitle">Son koşuların günlük özeti önde, teknik detaylar detay alanında gösterilir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-source-list">
                @forelse($runs as $run)
                    <details class="pd-source-row">
                        <summary class="pd-source-main">
                            <div>
                                <h4 class="pd-source-name">{{ $run->source->supplier->name ?? 'Tedarikçi' }} / {{ $run->source->source_name ?? '-' }}</h4>
                                <div class="pd-source-subline">
                                    <span class="pd-badge pd-badge-blue">{{ $run->display_run_type }}</span>
                                    <span class="pd-badge pd-badge-{{ $run->display_status_badge }}">{{ $run->display_status_label }}</span>
                                </div>
                            </div>
                            <div class="pd-source-meta pd-source-meta-grid">
                                <div class="pd-source-meta-line">Başlangıç: {{ optional($run->started_at)->format('d.m.Y H:i') ?: '-' }}</div>
                                <div class="pd-source-meta-line">Fiyat: {{ $run->price_changed_count }} / Stok: {{ $run->stock_changed_count }}</div>
                                <div class="pd-source-meta-line">İnceleme: {{ $run->changes()->whereNotNull('review_status')->count() }}</div>
                                <div class="pd-source-meta-line">Kataloğa yansıyan: {{ data_get($run->report_payload, 'projection.updated_products', 0) }}</div>
                            </div>
                        </summary>
                        <div class="pd-source-meta mt-3">
                            <div class="pd-source-meta-line">Bitiş: {{ optional($run->finished_at)->format('d.m.Y H:i') ?: '-' }}</div>
                            <div class="pd-source-meta-line">Okunan: {{ $run->records_read }}</div>
                            <div class="pd-source-meta-line">Yeni: {{ $run->products_created }} / Güncellenen: {{ $run->products_updated }} / Değişmeyen: {{ $run->products_unchanged }}</div>
                            <div class="pd-source-meta-line">Kaynakta görünmeyen: {{ $run->products_missing_from_feed }} / Pasif adayı: {{ $run->products_inactivated }}</div>
                            <div class="pd-source-meta-line">Kategori: {{ $run->category_changed_count }} / Görsel: {{ $run->image_changed_count }} / Açıklama: {{ $run->description_changed_count }}</div>
                            <div class="pd-source-meta-line">Standart ürün: +{{ data_get($run->report_payload, 'build.created_products', 0) }} / {{ data_get($run->report_payload, 'build.updated_products', 0) }}</div>
                            <div class="pd-source-meta-line">Abone Kataloğu: +{{ data_get($run->report_payload, 'projection.created_products', 0) }} / {{ data_get($run->report_payload, 'projection.updated_products', 0) }}</div>
                            <div class="pd-source-meta-line">Bloklanan: {{ $run->blocked_total }} / Hata: {{ $run->error_count }}</div>
                            @if(data_get($run->report_payload, 'dry_run'))
                                <div class="pd-source-meta-line">Not: Bu işlem ön kontroldür; veri yazılmadı.</div>
                            @endif
                            @if($run->error_message)
                                <div class="pd-source-meta-line">Hata özeti: {{ $run->error_message }}</div>
                            @endif
                            <details class="pd-inline-details mt-3">
                                <summary>Teknik Detaylar</summary>
                                <div class="pd-source-meta mt-2">
                                    <div class="pd-source-meta-line">Projection bloklu kategori: {{ data_get($run->report_payload, 'projection.blocked_missing_category', 0) }}</div>
                                    <div class="pd-source-meta-line">Projection bloklu fiyat: {{ data_get($run->report_payload, 'projection.blocked_missing_price', 0) }}</div>
                                    <div class="pd-source-meta-line">Kategori conflict: {{ data_get($run->report_payload, 'projection.blocked_conflict_category', 0) }}</div>
                                    <div class="pd-source-meta-line">Projection hatası: {{ data_get($run->report_payload, 'projection.blocked_projection_errors', 0) }}</div>
                                </div>
                            </details>
                        </div>
                    </details>
                @empty
                    <div class="pd-note">Henüz senkron raporu oluşmadı. Kaynaklar ekranında manuel veri güncelleme ile ilk raporu oluşturun.</div>
                    <details class="pd-inline-details mt-4">
                        <summary>Teknik Detaylar</summary>
                        <div class="pd-note mt-3">İlk rapor oluştuğunda run id, iç hata, command output ve benzeri teknik alanlar burada detay olarak gösterilir.</div>
                    </details>
                @endforelse
            </div>
        </div>
    </section>

    @if($selectedRun)
        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Kayıt Bazlı Değişimler</h3>
                    <p class="pd-section-subtitle">Seçili koşu için değişim tipi, inceleme durumu ve kısa mesaj birlikte görünür.</p>
                </div>
            </div>
            <div class="pd-section-body">
                @if($reviewOnly)
                    <div class="pd-note">İnceleme bekleyen değişiklikler gösteriliyor. Bu ekranda veri yazan aksiyon çalışmaz.</div>
                @endif
                <div class="pd-hub-table-wrap">
                    <table class="pd-table pd-table-compact pd-package-table">
                        <thead>
                            <tr>
                                <th>Ürün anahtarı</th>
                                <th>İşlem tipi</th>
                                <th>İnceleme durumu</th>
                                <th>Grace / aday</th>
                                <th>Mesaj</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($changes as $change)
                                <tr>
                                    <td>{{ $change->supplier_product_key ?: '-' }}</td>
                                    <td>{{ str_replace('_', ' ', $change->change_type) }}</td>
                                    <td>{{ $change->review_status ?: '-' }}</td>
                                    <td>
                                        @if($change->is_passive_candidate)
                                            <span class="pd-badge pd-badge-amber">Aday</span>
                                        @endif
                                        {{ $change->missing_feed_run_count ?: '-' }}
                                    </td>
                                    <td>{{ $change->message ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="pd-muted">Bu filtrede değişim kaydı bulunamadı.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif
</div>
@endsection
