@extends('layouts.prodelya-admin')

@section('title', 'Global Tedarikçi Kaynakları')

@section('content')
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
                    <h1 class="pd-hero-title">Global Tedarikçi Kaynakları</h1>
                    <p class="pd-hero-subtitle">Etkin, Akdeniz, İlpen, Yeni Nesil ve özel XML / JSON / CSV / API kaynaklarını Super Admin olarak yönetin.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Super Admin</span>
                        <span class="pd-badge pd-badge-green">{{ $stats['active'] }} aktif kaynak</span>
                        <span class="pd-badge pd-badge-amber">{{ $stats['mapping_missing'] }} mapping kontrolü</span>
                        <span class="pd-badge pd-badge-red">{{ $stats['temp'] }} temp / geçici profil</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-btn pd-btn-primary">Yeni Global Kaynak Ekle</a>
                    <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-btn pd-btn-light">Akış Kontrol Paneli</a>
                    <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light">Tenant Erişimleri</a>
                    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-warning">Standart Kategori Ağacı</a>
                </div>
            </div>
        </div>
    </section>

    <div class="pd-note">Global tedarikçi kaynakları Super Admin tarafından yönetilir. Tenant bu URL’yi değiştiremez. Tenant’a erişim Tenant Tedarikçi Erişimleri ekranından verilir. Varsayılan görünüm yalnız aktif ve gerçek kaynakları gösterir.</div>
    <div class="pd-note">Otomatik zamanlama için sunucuda Laravel scheduler/cron aktif olmalıdır.</div>

    <section class="pd-metric-grid">
        <div class="pd-metric-card pd-metric-card-soft-blue"><div class="pd-metric-card-label">Toplam Kaynak</div><div class="pd-metric-card-value">{{ $stats['total'] }}</div><div class="pd-metric-card-note">{{ $stats['visible_total'] }} tanesi listede</div></div>
        <div class="pd-metric-card pd-metric-card-soft-green"><div class="pd-metric-card-label">Aktif</div><div class="pd-metric-card-value">{{ $stats['active'] }}</div><div class="pd-metric-card-note">Kullanıma açık kaynaklar</div></div>
        <div class="pd-metric-card pd-metric-card-soft-slate"><div class="pd-metric-card-label">Hazır</div><div class="pd-metric-card-value">{{ $stats['ready'] }}</div><div class="pd-metric-card-note">Preview ve mapping için hazır</div></div>
        <div class="pd-metric-card pd-metric-card-soft-amber"><div class="pd-metric-card-label">URL Eksik</div><div class="pd-metric-card-value">{{ $stats['url_missing'] }}</div><div class="pd-metric-card-note">Bağlantı tamamlanmalı</div></div>
        <div class="pd-metric-card pd-metric-card-soft-red"><div class="pd-metric-card-label">Temp Profil</div><div class="pd-metric-card-value">{{ $stats['temp'] }}</div><div class="pd-metric-card-note">Gerçek kaynak gibi kullanılmamalı</div></div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Kaynak Listesi</h3>
                <p class="pd-section-subtitle">Kaynak, profil ve erişim yoğunluğunu daha kompakt kart satırlarıyla izleyin.</p>
            </div>
            <div class="pd-chip-group">
                <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => 'active']) }}" class="pd-chip {{ $activeFilter === 'active' ? 'is-active' : '' }}">Aktif Kaynaklar</a>
                <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => 'inactive']) }}" class="pd-chip {{ $activeFilter === 'inactive' ? 'is-active' : '' }}">Pasif Kaynaklar</a>
                <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => 'archived']) }}" class="pd-chip {{ $activeFilter === 'archived' ? 'is-active' : '' }}">Arşivli Kaynaklar</a>
                <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => 'temp']) }}" class="pd-chip {{ $activeFilter === 'temp' ? 'is-active' : '' }}">Temp/Test Kaynaklar</a>
                <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => 'all']) }}" class="pd-chip {{ $activeFilter === 'all' ? 'is-active' : '' }}">Tümü</a>
            </div>
        </div>
        <div class="pd-section-body">
            @if($sources->count() > 0)
                <div class="pd-source-list">
                    @foreach($sources as $source)
                        <div class="pd-source-row">
                            <div class="pd-source-main">
                                <h4 class="pd-source-name">{{ $source->supplier->name }}</h4>
                                <div class="pd-source-subline">
                                    <span class="pd-muted-badge">{{ $source->supplier->code }}</span>
                                    <span class="pd-badge pd-badge-{{ $source->display_source_type === 'json' ? 'purple' : ($source->display_source_type === 'xml' ? 'blue' : ($source->display_source_type === 'csv' ? 'amber' : 'green')) }}">{{ strtoupper($source->display_source_type) }}</span>
                                    <span class="pd-badge pd-badge-gray">{{ $source->profile_key }}</span>
                                    <span class="pd-badge pd-badge-{{ $source->status_badge }}">{{ $source->status_label }}</span>
                                    @if($source->is_temp_profile)
                                        <span class="pd-badge pd-badge-red">Temp Profil</span>
                                    @endif
                                    @if($source->is_archived)
                                        <span class="pd-badge pd-badge-amber">Arşivlenmiş Temp</span>
                                    @endif
                                    @if(!$source->has_location)
                                        <span class="pd-badge pd-badge-amber">URL Eksik</span>
                                    @endif
                                    @if(!$source->has_field_mappings)
                                        <span class="pd-badge pd-badge-amber">Mapping Eksik</span>
                                    @endif
                                    @if(!$source->is_ready)
                                        <span class="pd-badge pd-badge-gray">Hazır Değil</span>
                                    @endif
                                    @if($source->build_pending)
                                        <span class="pd-badge pd-badge-amber">Build bekliyor</span>
                                    @endif
                                    @if($source->projection_pending)
                                        <span class="pd-badge pd-badge-purple">Projection bekliyor</span>
                                    @endif
                                </div>
                            </div>
                            <div class="pd-source-meta">
                                <div class="pd-source-meta-line">Kaynak adı: {{ $source->source_name }}</div>
                                <div class="pd-source-meta-line">Sync frekansı: <strong>{{ $source->sync_frequency_label }}</strong></div>
                                <div class="pd-source-meta-line">Sonraki planlanan sync: {{ $source->next_sync_label }}</div>
                                <div class="pd-source-meta-line">Auto build: {{ $source->auto_build_enabled ? 'Açık' : 'Kapalı' }}</div>
                                <div class="pd-source-meta-line">Tenant kataloğa otomatik yansıtma: {{ $source->auto_project_enabled ? 'Açık' : 'Kapalı' }}</div>
                                <div class="pd-source-meta-line">Alan eşleme: {{ $source->has_field_mappings ? $source->field_mappings_count . ' alan eşlenmiş' : 'Henüz tanımlı değil' }}</div>
                                <div class="pd-source-meta-line">URL / Dosya: {{ $source->display_location ?: 'Henüz yok' }}</div>
                                <div class="pd-source-meta-line">Node / Path: {{ $source->display_path ?: 'Henüz yok' }}</div>
                                <div class="pd-source-meta-line">Kategori sayısı: {{ $source->category_mappings_count ?? 0 }}</div>
                                <div class="pd-source-meta-line">Son kategori tarama: {{ $source->last_category_scan_display ? \Carbon\Carbon::parse($source->last_category_scan_display)->format('d.m.Y H:i') : 'Henüz yok' }}</div>
                                <div class="pd-source-meta-line">Son test: {{ $source->last_test_display ? \Carbon\Carbon::parse($source->last_test_display)->format('d.m.Y H:i') : 'Henüz yok' }}</div>
                                <div class="pd-source-meta-line">Son preview: {{ $source->last_preview_display ? \Carbon\Carbon::parse($source->last_preview_display)->format('d.m.Y H:i') : 'Henüz yok' }}</div>
                                <div class="pd-source-meta-line">Son sync: {{ optional($source->latest_sync_run?->finished_at)->format('d.m.Y H:i') ?: 'Henüz yok' }}</div>
                                <div class="pd-source-meta-line">Son sync durumu: <span class="pd-badge pd-badge-{{ $source->sync_status_badge }}">{{ $source->sync_status_label }}</span></div>
                                <div class="pd-source-meta-line">Bağlı kayıt: {{ $source->dependency_summary['total'] ?? 0 }}</div>
                                <div class="pd-source-meta-line">Son sync özeti: Yeni {{ $source->sync_summary['created'] }} | Güncellenen {{ $source->sync_summary['updated'] }} | Stok {{ $source->sync_summary['stock_changed'] }} | Fiyat {{ $source->sync_summary['price_changed'] }} | XML’den çıkan {{ $source->sync_summary['missing_from_feed'] }} | Hata {{ $source->sync_summary['errors'] }}</div>
                                @foreach($source->quality_alerts as $qualityAlert)
                                    <div class="pd-source-meta-line"><span class="pd-badge pd-badge-amber">Kalite uyarısı</span> {{ $qualityAlert }}</div>
                                @endforeach
                            </div>
                            <div class="pd-source-actions">
                                <a href="{{ route('admin.super.product-data-hub.sources.edit', $source) }}" class="pd-btn pd-btn-sm pd-btn-light">Policy Düzenle</a>
                                <form action="{{ route('admin.super.product-data-hub.sources.test', $source) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">Test Et</button>
                                </form>
                                <a href="{{ route('admin.super.product-data-hub.sources.preview', $source) }}" class="pd-btn pd-btn-sm pd-btn-primary">Preview</a>
                                <form action="{{ route('admin.super.product-data-hub.sources.sync', $source) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-warning" onclick="return confirm('Manuel sync canlı kaynak verisini yeniden işler ve build/projection politikalarını tetikleyebilir. Önce dry-run veya son raporu kontrol etmeniz önerilir. Devam edilsin mi?')">Manuel Sync Çalıştır</button>
                                </form>
                                <form action="{{ route('admin.super.product-data-hub.sources.sync', $source) }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="dry_run" value="1">
                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">Dry-run Çalıştır</button>
                                </form>
                                <a href="{{ route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id]) }}" class="pd-btn pd-btn-sm pd-btn-light">Son Raporu Gör</a>
                                <form action="{{ route('admin.super.product-data-hub.category-mappings.scan') }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="supplier_source_id" value="{{ $source->id }}">
                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-warning" onclick="return confirm('Kategori tarama tedarikçi kategori kuyruğunu günceller. Ürün veya kategori ağacı değiştirmez. Devam edilsin mi?')">Kategori Tara</button>
                                </form>
                                @if($source->status === 'active')
                                    <form action="{{ route('admin.super.product-data-hub.sources.deactivate', $source) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="pd-btn pd-btn-sm pd-btn-light" onclick="return confirm('Bu kaynağı pasifleştirmek istediğinizden emin misiniz?')">Pasifleştir</button>
                                    </form>
                                @endif
                                @if($source->is_temp_profile || $source->status !== 'inactive')
                                    <form action="{{ route('admin.super.product-data-hub.sources.archive', $source) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="pd-btn pd-btn-sm pd-btn-warning" onclick="return confirm('Bu kaynak arşivlenecek ve varsayılan listede gizlenecek. Devam etmek istiyor musunuz?')">Arşivle</button>
                                    </form>
                                @endif
                                <form action="{{ route('admin.super.product-data-hub.sources.destroy', $source) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        class="pd-btn pd-btn-sm pd-btn-danger"
                                        onclick="return confirm('{{ $source->can_hard_delete ? 'Bu geçici/test kaynak silinecek. Devam etmek istiyor musunuz?' : 'Bu kaynak ürün, kategori veya senkron kayıtlarına bağlı. Silmek yerine pasifleştirmeniz önerilir. Devam etmek istiyor musunuz?' }}')"
                                    >
                                        Sil
                                    </button>
                                </form>
                                @if(!$source->can_hard_delete)
                                    <span class="pd-muted-badge">Bağlı kayıt var, hard delete engelli</span>
                                @endif
                                @if($source->is_temp_profile)
                                    <span class="pd-muted-badge">Gerçek kaynak olarak kullanılmamalı</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="pd-empty-card">
                    <h3 class="text-lg font-medium" style="margin-bottom:8px;">Henüz tedarikçi kaynağı eklenmedi.</h3>
                    <p class="pd-muted" style="margin-bottom:14px;">İlk XML / JSON / CSV kaynağınızı ekleyin.</p>
                    <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-btn pd-btn-primary">Yeni Global Kaynak Ekle</a>
                </div>
            @endif
        </div>
    </section>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Global Kaynak Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Toplam kaynak</span><strong>{{ $stats['total'] }}</strong></div>
            <div class="pd-status-row"><span>Aktif</span><strong>{{ $stats['active'] }}</strong></div>
            <div class="pd-status-row"><span>Hazır</span><strong>{{ $stats['ready'] }}</strong></div>
            <div class="pd-status-row"><span>Temp profil</span><strong>{{ $stats['temp'] }}</strong></div>
            <div class="pd-status-row"><span>URL eksik</span><strong>{{ $stats['url_missing'] }}</strong></div>
        </div>
        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Aksiyonlar</h4>
            <div class="pd-summary-action-list">
                <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-summary-action"><span>Yeni Global Kaynak Ekle</span><span class="pd-badge pd-badge-blue">Yeni</span></a>
                <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-summary-action"><span>Akış Kontrol Paneli</span><span class="pd-badge pd-badge-amber">İzle</span></a>
                <a href="{{ route('admin.super.product-data-hub.sources.sync-reports') }}" class="pd-summary-action"><span>Tedarikçi Senkron Raporları</span><span class="pd-badge pd-badge-green">Rapor</span></a>
                <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-summary-action"><span>Tenant Erişimleri</span><span class="pd-badge pd-badge-green">Yetki</span></a>
                <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-summary-action"><span>Standart Kategori Ağacı</span><span class="pd-badge pd-badge-purple">Ağaç</span></a>
            </div>
        </div>
        <div class="pd-side-note">Global kaynak yönetimi tenant tarafına açılmaz. Preview, test ve eşleme adımları yalnız Super Admin tarafından yürütülür.</div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Kaynak yönetimi:</strong>
    <span class="pd-muted">Global kaynakları düzenleyin, preview alın ve erişim akışını kontrol edin.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-btn pd-btn-primary">Yeni Global Kaynak Ekle</a>
    <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-btn pd-btn-light">Akış Kontrol Paneli</a>
    <a href="{{ route('admin.super.product-data-hub.profile-comparison') }}" class="pd-btn pd-btn-light">Profil Karşılaştırma</a>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-warning">Kategori Eşleme</a>
</div>
@endsection
