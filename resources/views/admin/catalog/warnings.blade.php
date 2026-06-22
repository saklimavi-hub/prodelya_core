@extends('layouts.prodelya-admin')

@section('title', 'Uyarılı Ürünler')
@section('page_title', 'Uyarılı Ürünler')
@section('page_subtitle', 'Fiyat, görsel, kategori, stok ve sync kaynaklı uyarıları ürün bazında izleyin.')

@section('content')
<div class="pd-hub-family-shell">
    @if(session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="pd-alert-warning">{{ session('error') }}</div>
    @endif

    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Uyarılı Ürünler</h1>
                    <p class="pd-hero-subtitle">Fiyat eksik, görsel eksik, kategori eksik, net fiyat uyarısı, tedarikçi özel fiyat uyarısı ve XML’den çıkan ürünleri tenant tarafında izleyin.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-light">Ana Kataloğa Dön</a>
                    <a href="{{ route('admin.catalog.visibility') }}" class="pd-btn pd-btn-warning">Görünürlükleri Yönet</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler</h3>
                <p class="pd-section-subtitle">Uyarı tipi, kaynak ve stok/fiyat durumuna göre izleyin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" action="{{ route('admin.catalog.warnings') }}" class="pd-form-grid-3">
                <div>
                    <label class="pd-label">Arama</label>
                    <input name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Ürün adı veya kod">
                </div>
                <div>
                    <label class="pd-label">Uyarı tipi</label>
                    <select name="warning_state" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="missing_price" @selected($filters['warning_state'] === 'missing_price')>Fiyat eksik</option>
                        <option value="missing_category" @selected($filters['warning_state'] === 'missing_category')>Kategori eksik</option>
                        <option value="stock_warning" @selected($filters['warning_state'] === 'stock_warning')>Stok yok</option>
                        <option value="red_product" @selected($filters['warning_state'] === 'red_product')>Kırmızı ürün</option>
                        <option value="net_price" @selected($filters['warning_state'] === 'net_price')>Sabit/net fiyat</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Kaynak tipi</label>
                    <select name="source_type" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="local" @selected($filters['source_type'] === 'local')>Local</option>
                        <option value="supplier" @selected($filters['source_type'] === 'supplier')>Tedarikçi</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Tedarikçi</label>
                    <select name="supplier" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected($filters['supplier'] === $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Limit</label>
                    <select name="limit" class="pd-select">
                        @foreach([50, 100, 250, 500] as $limit)
                            <option value="{{ $limit }}" @selected((string) $filters['limit'] === (string) $limit)>{{ $limit }}</option>
                        @endforeach
                        <option value="all" @selected($filters['limit'] === 'all')>Tümü</option>
                    </select>
                </div>
                <div class="pd-hero-actions">
                    <button class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.catalog.warnings') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card">
        <div class="pd-section-body">
            <div class="pd-hub-table-wrap">
                <table class="pd-table pd-package-table">
                    <thead>
                        <tr>
                            <th>Ürün</th>
                            <th>Kaynak</th>
                            <th>Uyarı tipi</th>
                            <th>Açıklama</th>
                            <th>Son sync</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($warningRows as $row)
                            <tr>
                                <td>
                                    <div class="font-medium">{{ $row['product']->display_name }}</div>
                                    <div class="text-xs text-gray-500">{{ $row['product']->display_code }}</div>
                                </td>
                                <td>
                                    <span class="pd-badge pd-badge-{{ $row['product']->catalog_source === 'local_product' ? 'purple' : 'blue' }}">{{ $row['product']->catalog_source_label }}</span>
                                    <div class="text-xs text-gray-500" style="margin-top:6px;">{{ $row['product']->supplier_label }}</div>
                                </td>
                                <td><span class="pd-badge pd-badge-amber">{{ $row['warning_type'] }}</span></td>
                                <td>{{ $row['description'] }}</td>
                                <td>{{ optional($row['product']->last_synced_at ?: $row['product']->updated_at)->format('d.m.Y H:i') ?: '-' }}</td>
                                <td>
                                    <div class="pd-source-actions">
                                        <a href="{{ route('admin.catalog.show', $row['product']) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                        <form action="{{ route('admin.catalog.warnings.action', $row['product']) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="action" value="hide_catalog">
                                            <button type="submit" class="pd-btn pd-btn-sm pd-btn-warning">Katalogda Gizle</button>
                                        </form>
                                        <form action="{{ route('admin.catalog.warnings.action', $row['product']) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="action" value="disable_quote">
                                            <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">Teklifte Kullanılamaz</button>
                                        </form>
                                        <form action="{{ route('admin.catalog.warnings.review', $row['product']) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">Kontrol Edildi</button>
                                        </form>
                                        <form action="{{ route('admin.catalog.warnings.action', $row['product']) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="action" value="local_stock_boost">
                                            <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">Local Fiyat/Stok Gir</button>
                                        </form>
                                        @if($row['product']->catalog_source !== 'local_product')
                                            <a href="{{ route('admin.catalog.supplier-products') }}" class="pd-btn pd-btn-sm pd-btn-light">Tedarikçi Detayı</a>
                                        @else
                                            <a href="{{ route('admin.catalog.local-products', ['edit' => $row['product']->id]) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="pd-muted">Aktif uyarı bulunmuyor.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('admin.catalog.partials.pagination', ['paginator' => $warningRows, 'filters' => $filters])
        </div>
    </section>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Uyarı Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Toplam ürün</span><strong>{{ $stats['total'] }}</strong></div>
            <div class="pd-status-row"><span>Uyarılı ürün</span><strong>{{ $stats['warning'] }}</strong></div>
            <div class="pd-status-row"><span>Fiyatı eksik</span><strong>{{ $stats['missing_price'] }}</strong></div>
            <div class="pd-status-row"><span>Katalogda görünen</span><strong>{{ $stats['visible'] }}</strong></div>
        </div>
        <div class="pd-side-note">Fiyat eksik ve uyarılı ürünler tenant tarafında izlenir; ancak global XML veya mapping ayarları burada değiştirilmez.</div>
    </div>
</div>
@endsection
