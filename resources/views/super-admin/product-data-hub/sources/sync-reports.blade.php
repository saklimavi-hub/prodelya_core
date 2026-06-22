@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Senkron Raporları')

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Tedarikçi Senkron Raporları</h1>
                    <p class="pd-hero-subtitle">Kaynak silmeden aynı source üzerinde yeni XML sürümlerini izleyin, değişen ürünleri görün ve XML’den çıkan kayıtları silmeden kontrol kuyruğuna alın.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Kaynaklara Dön</a>
                </div>
            </div>
        </div>
    </section>

    <div class="pd-note">Otomatik saatlik/günlük/haftalık çalışmalar için sunucuda Laravel scheduler aktif olmalıdır.</div>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler</h3>
                <p class="pd-section-subtitle">Tedarikçi ve değişim tipine göre raporu daraltın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" class="pd-form-grid-2">
                <div>
                    <label class="pd-label">Tedarikçi Kaynağı</label>
                    <select name="source_id" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($sources as $source)
                            <option value="{{ $source->id }}" {{ (int) $sourceId === $source->id ? 'selected' : '' }}>{{ $source->supplier->name }} / {{ $source->source_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Değişim Tipi</label>
                    <select name="change_type" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach(['created' => 'Yeni', 'updated' => 'Güncellendi', 'unchanged' => 'Değişmedi', 'missing_from_feed' => 'XML’den Çıkan', 'price_changed' => 'Fiyat', 'stock_changed' => 'Stok', 'image_changed' => 'Görsel', 'category_changed' => 'Kategori', 'name_changed' => 'Ad', 'description_changed' => 'Açıklama'] as $value => $label)
                            <option value="{{ $value }}" {{ $changeType === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Senkron Çalışmaları</h3>
                <p class="pd-section-subtitle">Son koşuların özetini ve değişim sayılarını izleyin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-source-list">
                @forelse($runs as $run)
                    <div class="pd-source-row">
                        <div class="pd-source-main">
                            <h4 class="pd-source-name">{{ $run->source->supplier->name ?? 'Tedarikçi' }} / {{ $run->source->source_name ?? '-' }}</h4>
                            <div class="pd-source-subline">
                                <span class="pd-badge pd-badge-blue">{{ $run->display_run_type }}</span>
                                <span class="pd-badge pd-badge-{{ $run->display_status_badge }}">{{ $run->display_status_label }}</span>
                            </div>
                        </div>
                        <div class="pd-source-meta">
                            <div class="pd-source-meta-line">Başlangıç: {{ optional($run->started_at)->format('d.m.Y H:i') ?: '-' }}</div>
                            <div class="pd-source-meta-line">Bitiş: {{ optional($run->finished_at)->format('d.m.Y H:i') ?: '-' }}</div>
                            <div class="pd-source-meta-line">Okunan: {{ $run->records_read }}</div>
                            <div class="pd-source-meta-line">Yeni: {{ $run->products_created }} / Güncellenen: {{ $run->products_updated }} / Değişmeyen: {{ $run->products_unchanged }}</div>
                            <div class="pd-source-meta-line">XML’den çıkan: {{ $run->products_missing_from_feed }} / Pasif adayı: {{ $run->products_inactivated }}</div>
                            <div class="pd-source-meta-line">Fiyat: {{ $run->price_changed_count }} / Stok: {{ $run->stock_changed_count }} / Görsel: {{ $run->image_changed_count }}</div>
                            <div class="pd-source-meta-line">Kategori: {{ $run->category_changed_count }} / Ad: {{ $run->name_changed_count }} / Açıklama: {{ $run->description_changed_count }}</div>
                            <div class="pd-source-meta-line">Standart ürün: +{{ data_get($run->report_payload, 'build.created_products', 0) }} yeni / {{ data_get($run->report_payload, 'build.updated_products', 0) }} güncellendi</div>
                            <div class="pd-source-meta-line">Tenant katalog: +{{ data_get($run->report_payload, 'projection.created_products', 0) }} yeni / {{ data_get($run->report_payload, 'projection.updated_products', 0) }} güncellendi</div>
                            <div class="pd-source-meta-line">Bloklanan: toplam {{ $run->blocked_total }} / kategori {{ data_get($run->report_payload, 'projection.blocked_missing_category', 0) }} / fiyat {{ data_get($run->report_payload, 'projection.blocked_missing_price', 0) }} / conflict {{ data_get($run->report_payload, 'projection.blocked_conflict_category', 0) }}</div>
                            <div class="pd-source-meta-line">Uyarıyla çıkan: {{ data_get($run->report_payload, 'projection.projected_with_warnings', 0) }} / Pasif adayı: {{ data_get($run->report_payload, 'projection.inactive_candidates', 0) }}</div>
                            <div class="pd-source-meta-line">Hata: {{ $run->error_count }} / Projection hatası: {{ data_get($run->report_payload, 'projection.blocked_projection_errors', 0) }}</div>
                            @if(data_get($run->report_payload, 'dry_run'))
                                <div class="pd-source-meta-line">Not: Bu işlem test çalıştırmasıdır, ürün/stok/fiyat verisi değiştirilmedi.</div>
                            @endif
                            @if($run->error_message)
                                <div class="pd-source-meta-line">Hata özeti: {{ $run->error_message }}</div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="pd-note">Henüz senkron raporu oluşmadı. Kaynak listesinde “Manuel Güncelle” ile ilk raporu üretin.</div>
                @endforelse
            </div>
        </div>
    </section>

    @if($selectedRun)
        <section class="pd-section-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Ürün Bazlı Değişimler</h3>
                    <p class="pd-section-subtitle">Seçili koşu için ürün düzeyinde log kayıtları.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-hub-table-wrap">
                    <table class="pd-table pd-package-table">
                        <thead>
                            <tr>
                                <th>Ürün Anahtarı</th>
                                <th>Değişim</th>
                                <th>Mesaj</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($changes as $change)
                                <tr>
                                    <td>{{ $change->supplier_product_key ?: '-' }}</td>
                                    <td>{{ $change->change_type }}</td>
                                    <td>{{ $change->message ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="pd-muted">Bu filtrede değişim kaydı bulunamadı.</td>
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
