@php
    $flow = $source->flow_snapshot;
    $summary = $flow['summary'];
    $freshness = $source->freshness_summary ?? [];
    $decision = $source->sync_decision_summary ?? [];
    $reviewTotal = (int) ($summary['review_total'] ?? 0);
    $quoteVisibleTotal = (int) ($summary['quote_visible_products'] ?? 0) + (int) ($summary['quote_visible_variants'] ?? 0);
    $quoteHiddenTotal = (int) ($summary['quote_hidden_products'] ?? 0) + (int) ($summary['quote_hidden_variants'] ?? 0);
    $catalogNote = $summary['tenant_catalog_products'] > 0
        ? $summary['tenant_catalog_products'] . ' ürün / ' . $summary['tenant_catalog_variants'] . ' varyant'
        : ($summary['tenant_access'] > 0 ? 'Yansıtma bekliyor' : 'Erişim tanımı bekleniyor');
    $focusMessage = match (true) {
        $reviewTotal > 0 => 'Bekleyen Kontroller alanında ' . $reviewTotal . ' kayıt var.',
        ($decision['projection_pending'] ?? 0) > 0 => 'Satış listesine otomatik yansıması bekleyen kayıt var.',
        ($summary['tenant_catalog_products'] ?? 0) === 0 && ($summary['standard_products'] ?? 0) > 0 && ($summary['tenant_access'] ?? 0) > 0 => 'Bu kaynakta ürünler hazır, ancak Abone Firma kataloğuna henüz yansıtılmamış kayıtlar var.',
        ($summary['category_pending'] ?? 0) > 0 => $summary['category_pending'] . ' kayıt için kategori kararı bekleniyor.',
        default => $summary['preview_note'] ?? 'Akış durumuna göre yönlendiriliyor.',
    };
@endphp

<article class="pd-source-row pd-source-row-stepper" data-flow-source="{{ $source->id }}">
    <div class="pd-source-header pd-source-header-clean">
        <div class="pd-source-main">
            <div class="pd-source-kicker">Kaynak Detayı</div>
            <h4 class="pd-source-name">{{ $source->source_name }}</h4>
            <div class="pd-source-subtitle">{{ $source->supplier->name }}</div>
            <div class="pd-source-subline">
                <span class="pd-muted-badge">{{ $source->supplier->code }}</span>
                <span class="pd-badge pd-badge-{{ $source->display_source_type === 'json' ? 'purple' : ($source->display_source_type === 'xml' ? 'blue' : ($source->display_source_type === 'csv' ? 'amber' : 'green')) }}">{{ strtoupper($source->display_source_type) }}</span>
                <span class="pd-badge pd-badge-{{ $source->status_badge }}">{{ $source->status_label }}</span>
                <span class="pd-badge pd-badge-gray">Profil: {{ $source->source_profile_template }}</span>
                @if($source->is_temp_profile)
                    <span class="pd-badge pd-badge-red">Geçici Profil</span>
                @endif
            </div>
        </div>
        <div class="pd-source-primary">
            <div class="pd-source-next-label">Ana aksiyon</div>
            <form action="{{ route('admin.super.product-data-hub.sources.apply-price-stock', $source) }}" method="POST">
                @csrf
                <button type="submit" class="pd-btn pd-btn-warning" data-primary-action="Ürünleri Senkronize Et" onclick="return confirm('Bu işlem güvenli fiyat/stok değişikliklerini arka plandaki otomatik akışa yansıtır. Devam edilsin mi?')">
                    Ürünleri Senkronize Et
                </button>
            </form>
            @if($reviewTotal > 0)
                <a href="{{ route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id, 'review_only' => 1]) }}" class="pd-btn pd-btn-light pd-gap-top-xs">Bekleyen Kontrolleri Aç</a>
            @endif
            <div class="pd-source-next-note">{{ $focusMessage }}</div>
        </div>
    </div>

    <div class="pd-source-info-strip">
        <span class="pd-source-meta-chip">Bağlantı: {{ $source->has_location ? 'Hazır' : 'Eksik' }}</span>
        <span class="pd-source-meta-chip">Önizleme: {{ $summary['preview_label'] ?? 'Eksik' }}</span>
        <span class="pd-source-meta-chip">Son önizleme: {{ $source->last_preview_display ? \Carbon\Carbon::parse($source->last_preview_display)->format('d.m.Y H:i') : 'Henüz yok' }}</span>
        <span class="pd-source-meta-chip">Son senkron: {{ optional($source->latest_sync_run?->finished_at)->format('d.m.Y H:i') ?: 'Henüz yok' }}</span>
        <span class="pd-source-meta-chip">Sıklık: {{ $source->sync_frequency_label }}</span>
    </div>

    <div class="pd-source-summary-grid pd-source-summary-grid-clean">
        <div class="pd-source-summary-card"><div class="pd-source-summary-label">Ürün</div><div class="pd-source-summary-value">{{ $summary['standard_products'] }}</div><div class="pd-source-summary-note">{{ $summary['raw_products'] }} hazırlık kaydı</div></div>
        <div class="pd-source-summary-card"><div class="pd-source-summary-label">Varyant</div><div class="pd-source-summary-value">{{ $summary['standard_variants'] }}</div><div class="pd-source-summary-note">{{ $summary['raw_variants'] }} hazırlık varyantı</div></div>
        <div class="pd-source-summary-card"><div class="pd-source-summary-label">Kategori Bekleyen</div><div class="pd-source-summary-value">{{ $summary['category_pending'] }}</div><div class="pd-source-summary-note">{{ $source->category_mappings_count ?? 0 }} eşleme kaydı</div></div>
        <div class="pd-source-summary-card"><div class="pd-source-summary-label">Teklifte Görünen</div><div class="pd-source-summary-value">{{ $quoteVisibleTotal }}</div><div class="pd-source-summary-note">{{ $summary['quote_visibility_reason'] }}</div></div>
        <div class="pd-source-summary-card" data-review-total="{{ $reviewTotal }}"><div class="pd-source-summary-label">İnceleme Bekleyen</div><div class="pd-source-summary-value">{{ $reviewTotal }}</div><div class="pd-source-summary-note">{{ $reviewTotal > 0 ? 'Kontrol bekleyen kayıtlar var' : 'Kontrol bekleyen değişiklik yok' }}</div></div>
        <div class="pd-source-summary-card"><div class="pd-source-summary-label">Abone Katalog Durumu</div><div class="pd-source-summary-value">{{ $summary['tenant_catalog_products'] }}</div><div class="pd-source-summary-note">{{ $catalogNote }}</div></div>
    </div>

    @if($reviewTotal > 0)
        <div class="pd-review-mini-grid" data-review-total="{{ $reviewTotal }}">
            <div class="pd-review-mini-card" data-review-type="new_product" data-review-count="{{ $summary['new_product_review_count'] }}"><span class="pd-review-mini-label">Yeni Ürün</span><strong>{{ $summary['new_product_review_count'] }}</strong></div>
            <div class="pd-review-mini-card" data-review-type="new_variant" data-review-count="{{ $summary['new_variant_review_count'] }}"><span class="pd-review-mini-label">Yeni Varyant</span><strong>{{ $summary['new_variant_review_count'] }}</strong></div>
            <div class="pd-review-mini-card" data-review-type="missing_product" data-review-count="{{ $summary['missing_product_review_count'] }}"><span class="pd-review-mini-label">Kaynakta Yok Ürün</span><strong>{{ $summary['missing_product_review_count'] }}</strong></div>
            <div class="pd-review-mini-card" data-review-type="missing_variant" data-review-count="{{ $summary['missing_variant_review_count'] }}"><span class="pd-review-mini-label">Kaynakta Yok Varyant</span><strong>{{ $summary['missing_variant_review_count'] }}</strong></div>
            <div class="pd-review-mini-card" data-review-type="passive_candidate" data-review-count="{{ $summary['passive_candidate_review_count'] }}"><span class="pd-review-mini-label">Pasife Alma Adayı</span><strong>{{ $summary['passive_candidate_review_count'] }}</strong></div>
        </div>
    @endif

    <div class="pd-freshness-card" data-freshness-source="{{ $source->id }}">
        <div class="pd-freshness-header">
            <div>
                <div class="pd-source-summary-label">Katalog Tazeliği</div>
                <div class="pd-source-summary-note">Normal fiyat/stok değişimleri sessiz akışta ilerlemeli; yalnız yeni ürün, kategori, kimlik ve satış listesine yansıma istisnaları operatöre iş çıkarmalı.</div>
            </div>
            <span class="pd-badge pd-badge-{{ $decision['state_tone'] ?? 'blue' }}">{{ $decision['state_label'] ?? 'Henüz delta raporu yok' }}</span>
        </div>
        <div class="pd-inline-wrap-sm pd-gap-bottom-sm">
            <span class="pd-muted-badge">Otomatik işlenen {{ $decision['automatic_updates'] ?? 0 }}</span>
            <span class="pd-muted-badge">Review {{ $decision['review_required'] ?? 0 }}</span>
            <span class="pd-muted-badge">Yeni ürün {{ $decision['new_items'] ?? 0 }}</span>
            <span class="pd-muted-badge">Kimlik / varyant {{ $decision['identity_issues'] ?? 0 }}</span>
        </div>
        <div class="pd-freshness-metrics">
            <div class="pd-freshness-metric"><span>Son fiyat/stok kontrolü</span><strong>{{ optional($freshness['last_check_at'] ?? null)->format('d.m.Y H:i') ?: 'Henüz yok' }}</strong></div>
            <div class="pd-freshness-metric"><span>Son fiyat/stok güncelleme</span><strong>{{ optional($freshness['last_apply_at'] ?? null)->format('d.m.Y H:i') ?: 'Henüz yok' }}</strong></div>
            <div class="pd-freshness-metric"><span>Son satış listesi onarımı</span><strong>{{ optional($freshness['last_project_at'] ?? null)->format('d.m.Y H:i') ?: 'Henüz yok' }}</strong></div>
            <div class="pd-freshness-metric"><span>Değişen fiyat/stok kaydı</span><strong>{{ ($freshness['price_changed_total'] ?? 0) + ($freshness['stock_changed_total'] ?? 0) }}</strong></div>
            <div class="pd-freshness-metric"><span>Kataloğa yansıtılan kayıt</span><strong>{{ $freshness['projected_total'] ?? 0 }}</strong></div>
            <div class="pd-freshness-metric"><span>İnceleme gerektiren istisna</span><strong>{{ $decision['review_required'] ?? 0 }}</strong></div>
            <div class="pd-freshness-metric"><span>Katalogda var, teklifte kapalı</span><strong>{{ $quoteHiddenTotal }}</strong></div>
        </div>
        <div class="pd-source-next-note">{{ $decision['note'] ?? 'Ön kontrol canlı kaynağı okur; uygun ürünler normal senkron akışında Abone Firma ürün listesine ve teklif seçimine otomatik yansır.' }}</div>
    </div>

    <div class="pd-flow-stepper" aria-label="Tedarikçi akışı stepper">
        @foreach($flow['steps'] as $index => $step)
            <div class="pd-flow-step is-{{ $step['status'] }}" data-flow-step="{{ $step['key'] }}" data-flow-status="{{ $step['status'] }}">
                <div class="pd-flow-step-top">
                    <div class="pd-flow-step-index">{{ $index + 1 }}</div>
                    <div>
                        <div class="pd-flow-step-title">{{ $step['title'] }}</div>
                        <div class="pd-flow-step-description">{{ $step['description'] }}</div>
                    </div>
                </div>
                <div class="pd-flow-step-bottom">
                    <span class="pd-badge pd-badge-{{ $step['status'] === 'ready' ? 'green' : ($step['status'] === 'warning' ? 'amber' : ($step['status'] === 'error' ? 'red' : 'gray')) }}">{{ $step['status_label'] }}</span>
                    <div class="pd-flow-step-note">{{ $step['note'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <details class="pd-source-advanced">
        <summary class="pd-source-advanced-toggle">Gelişmiş İşlemler</summary>
        <div class="pd-source-actions">
            <form action="{{ route('admin.super.product-data-hub.sources.delta-dry-run', $source) }}" method="POST">@csrf<button type="submit" class="pd-btn pd-btn-sm pd-btn-light">Sadece Tara</button></form>
            <form action="{{ route('admin.super.product-data-hub.sources.apply-price-stock-project-dirty', $source) }}" method="POST">@csrf<button type="submit" class="pd-btn pd-btn-sm pd-btn-primary" onclick="return confirm('Bu işlem yalnız teknik onarım içindir. Normal kullanımda gerekmez. Devam edilsin mi?')">Satış Listesi Onar</button></form>
            <a href="{{ route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id]) }}" class="pd-btn pd-btn-sm pd-btn-light">Senkron Raporları</a>
            <a href="{{ route('admin.super.product-data-hub.sources.edit', $source) }}" class="pd-btn pd-btn-sm pd-btn-light">Kaynak Ayarları</a>
            <a href="{{ route('admin.super.product-data-hub.sources.preview', $source) }}" class="pd-btn pd-btn-sm pd-btn-light">Ön Kontrol</a>
            <a href="{{ route('admin.super.product-data-hub.supplier-products', ['source_id' => $source->id]) }}" class="pd-btn pd-btn-sm pd-btn-light">Teknik Kayıtlar</a>
        </div>
    </details>
</article>
