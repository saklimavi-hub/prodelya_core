@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçiden Stoğa Alınanlar')
@section('page_title', 'Tedarikçiden Stoğa Alınanlar')
@section('page_subtitle', 'Tedarikçiden stoğa alınan ürünleri ayrı bir yüzeyde toplu şekilde izleyin.')

@section('content')
<div class="pd-local-product-shell">
    @if(session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="pd-alert-warning">{{ session('error') }}</div>
    @endif

    @include('admin.catalog.partials._local-products-subnav')

    <section class="pd-hero-card pd-local-product-hero">
        <div class="pd-card-body">
            <div class="pd-local-product-hero-row">
                <div>
                    <span class="pd-local-product-eyebrow">Abone Firma · Tedarikçiden Stoğa Alınanlar</span>
                    <h1 class="pd-local-product-hero-title">Tedarikçiden Stoğa Alınanlar</h1>
                    <p class="pd-local-product-hero-subtitle">Stoğa alınmış tedarikçi ürünlerini tek listede takip edin; eldeki, rezerve ve kullanılabilir miktarları birlikte görün.</p>
                </div>
                <div class="pd-local-product-hero-actions">
                    <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-light">Ürün Listem</a>
                </div>
            </div>
            <div class="pd-local-product-stat-strip">
                <div class="pd-local-product-stat"><span>Stokta ürün</span><strong>{{ number_format((int) ($stats['total'] ?? 0), 0, ',', '.') }}</strong></div>
                <div class="pd-local-product-stat"><span>Eldeki toplam</span><strong>{{ number_format((float) ($stats['quantity_on_hand'] ?? 0), 0, ',', '.') }}</strong></div>
                <div class="pd-local-product-stat"><span>Rezerve</span><strong>{{ number_format((float) ($stats['quantity_reserved'] ?? 0), 0, ',', '.') }}</strong></div>
                <div class="pd-local-product-stat"><span>Kullanılabilir</span><strong>{{ number_format((float) ($stats['quantity_available'] ?? 0), 0, ',', '.') }}</strong></div>
            </div>
            @if(($stats['legacy_unassigned_count'] ?? 0) > 0)
                <div class="pd-local-product-legacy-warning">
                    Varyantı belirlenmemiş stok kaydı bulunuyor.
                    <strong>{{ number_format((int) ($stats['legacy_unassigned_count'] ?? 0), 0, ',', '.') }} kayıt / {{ number_format((float) ($stats['legacy_unassigned_quantity'] ?? 0), 0, ',', '.') }} adet</strong>
                </div>
            @endif
        </div>
    </section>

    <div class="pd-local-product-layout">
        <section class="pd-section-card pd-local-product-main-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Stokta Olan Tedarikçi Ürünleri</h3>
                    <p class="pd-section-subtitle">Ürün, tedarikçi ve stok adetleri aynı tabloda sade biçimde gösterilir.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form method="GET" action="{{ route('admin.catalog.local-products.supplier-stock') }}" class="pd-local-product-filter-grid">
                    <div>
                        <label class="pd-label">Ürün, SKU veya tedarikçi</label>
                        <input name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Arama yapın">
                    </div>
                    <div>
                        <label class="pd-label">Stok durumu</label>
                        <select name="stock_state" class="pd-select">
                            <option value="">Tümü</option>
                            <option value="in_stock" @selected($filters['stock_state'] === 'in_stock')>Stok var</option>
                            <option value="out_of_stock" @selected($filters['stock_state'] === 'out_of_stock')>Stok yok</option>
                        </select>
                    </div>
                    <div>
                        <label class="pd-label">Sayfa boyutu</label>
                        <select name="limit" class="pd-select">
                            @foreach([50, 100, 250, 500] as $limit)
                                <option value="{{ $limit }}" @selected((string) $filters['limit'] === (string) $limit)>{{ $limit }}</option>
                            @endforeach
                            <option value="all" @selected($filters['limit'] === 'all')>Tümü</option>
                        </select>
                    </div>
                    <div class="pd-local-product-filter-actions">
                        <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                        <a href="{{ route('admin.catalog.local-products.supplier-stock') }}" class="pd-btn pd-btn-light">Temizle</a>
                    </div>
                </form>

                <div class="pd-local-product-table-wrap">
                    <table class="pd-table pd-local-product-table pd-local-product-table-supplier">
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>SKU / Varyant</th>
                                <th>Tedarikçi</th>
                                <th>Eldeki</th>
                                <th>Rezerve</th>
                                <th>Kullanılabilir</th>
                                <th>Son Stok Hareketi</th>
                                <th>Aksiyon</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($products as $row)
                                <tr>
                                    <td>
                                        <div class="pd-local-product-table-product">
                                            <div class="pd-local-product-thumb pd-local-product-thumb-green">{{ \Illuminate\Support\Str::limit($row->display_name, 1, '') }}</div>
                                            <div>
                                                <strong>{{ $row->display_name }}</strong>
                                                <div class="pd-local-product-muted-row">
                                                    @if($row->identity_type === 'variant' && filled($row->parent_context))
                                                        <span>{{ $row->parent_context }}</span>
                                                    @endif
                                                    <span class="pd-badge pd-badge-green">{{ $row->source_badge_label }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div>{{ $row->sku }}</div>
                                        @if(filled($row->variant_label))
                                            <div class="pd-local-product-muted-row">{{ $row->variant_label }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $row->supplier_label }}</td>
                                    <td>{{ number_format((float) $row->quantity_on_hand, 0, ',', '.') }}</td>
                                    <td>{{ number_format((float) $row->quantity_reserved, 0, ',', '.') }}</td>
                                    <td>{{ number_format((float) $row->quantity_available, 0, ',', '.') }}</td>
                                    <td>{{ $row->last_stock_movement_at ? \Illuminate\Support\Carbon::parse($row->last_stock_movement_at)->format('d.m.Y H:i') : '-' }}</td>
                                    <td>
                                        <div class="pd-local-product-action-row">
                                            <a href="{{ $row->detail_url }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8"><div class="pd-note">Stoğa alınmış tedarikçi ürünü bulunamadı.</div></td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @include('admin.catalog.partials.pagination', ['paginator' => $products, 'filters' => $filters])
            </div>
        </section>

        <aside class="pd-local-product-sidebar">
            <section class="pd-section-card pd-local-product-summary-card">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Stok Özeti</h3>
                        <p class="pd-section-subtitle">Toplam eldeki, rezerve ve kullanılabilir miktarları tek panelde görün.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-local-product-sidebar-list">
                        <div class="pd-local-product-sidebar-row"><span>Stokta ürün</span><strong>{{ number_format((int) ($stats['total'] ?? 0), 0, ',', '.') }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Eldeki</span><strong>{{ number_format((float) ($stats['quantity_on_hand'] ?? 0), 0, ',', '.') }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Rezerve</span><strong>{{ number_format((float) ($stats['quantity_reserved'] ?? 0), 0, ',', '.') }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Kullanılabilir</span><strong>{{ number_format((float) ($stats['quantity_available'] ?? 0), 0, ',', '.') }}</strong></div>
                        @if(($stats['legacy_unassigned_count'] ?? 0) > 0)
                            <div class="pd-local-product-sidebar-row"><span>Belirsiz kayıt</span><strong>{{ number_format((int) ($stats['legacy_unassigned_count'] ?? 0), 0, ',', '.') }}</strong></div>
                        @endif
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection
