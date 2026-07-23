@extends('layouts.prodelya-admin')

@section('title', 'Stok Girişi / Satın Alma')
@section('page_title', 'Stok Girişi / Satın Alma')
@section('page_subtitle', 'Satın alma ve eldeki mevcut stok girişlerini tek ekrandan kaydedin ve oluşan stok hareketlerini takip edin.')

@section('content')
<div class="pd-hub-family-shell">
    @if(session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif

    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Stok Girişi / Satın Alma</h1>
                    <p class="pd-hero-subtitle">Satın alma ve eldeki mevcut stok girişleri burada tutulur. Satın alma kaydı stokla birlikte tedarikçi cari borcunu da doğurur; eldeki mevcut stok kaydı yalnız stock movement üretir.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.stock-purchases.create') }}" class="pd-btn pd-btn-primary">Yeni Giriş</a>
                    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-light">Kataloğa Dön</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-metric-grid">
        <div class="pd-metric-card pd-metric-card-soft-blue"><div class="pd-metric-card-label">Toplam kayıt</div><div class="pd-metric-card-value">{{ $summary['total_entries'] }}</div><div class="pd-metric-card-note">Tüm stok girişleri</div></div>
        <div class="pd-metric-card pd-metric-card-soft-green"><div class="pd-metric-card-label">Satın alma</div><div class="pd-metric-card-value">{{ $summary['purchase_entries'] }}</div><div class="pd-metric-card-note">Cari borç oluşturan girişler</div></div>
        <div class="pd-metric-card pd-metric-card-soft-purple"><div class="pd-metric-card-label">Eldeki mevcut stok</div><div class="pd-metric-card-value">{{ $summary['opening_entries'] }}</div><div class="pd-metric-card-note">Fiyatsız stok girişleri</div></div>
        <div class="pd-metric-card pd-metric-card-soft-amber"><div class="pd-metric-card-label">İptal</div><div class="pd-metric-card-value">{{ $summary['cancelled_entries'] }}</div><div class="pd-metric-card-note">Ters kayıt oluşturulmuş işlemler</div></div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler</h3>
                <p class="pd-section-subtitle">Giriş tipi, tedarikçi ve tarih aralığına göre kayıtları daraltın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" action="{{ route('admin.stock-purchases.index') }}" class="pd-form-grid-5">
                <div>
                    <label class="pd-label">Giriş tipi</label>
                    <select name="entry_type" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="supplier_purchase" @selected($filters['entry_type'] === 'supplier_purchase')>Satın Alma</option>
                        <option value="existing_stock" @selected($filters['entry_type'] === 'existing_stock')>Eldeki Mevcut Stok</option>
                    </select>
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
                    <label class="pd-label">Ürün kodu / adı</label>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Ürün, SKU, belge no">
                </div>
                <div>
                    <label class="pd-label">Başlangıç</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label">Bitiş</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="pd-input">
                </div>
                <div class="pd-hero-actions" style="grid-column:1 / -1;">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.stock-purchases.index') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Kayıt Listesi</h3>
                <p class="pd-section-subtitle">Teknik enum yerine kullanıcı etiketleri gösterilir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            @if($entries->isEmpty())
                <div class="pd-empty-card">
                    <h3 class="text-lg font-medium pd-title-gap-xs">Henüz stok girişi kaydı yok.</h3>
                    <p class="pd-muted pd-gap-bottom-sm">İlk exact stok kaydınızı oluşturmak için yeni giriş ekranını açın.</p>
                    <a href="{{ route('admin.stock-purchases.create') }}" class="pd-btn pd-btn-primary">Yeni Giriş</a>
                </div>
            @else
                <div class="pd-hub-table-wrap">
                    <table class="pd-table pd-package-table">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Giriş Tipi</th>
                                <th>Tedarikçi</th>
                                <th>Ürün / Exact SKU</th>
                                <th>Adet</th>
                                <th>Toplam Tutar</th>
                                <th>Stok Hareketi</th>
                                <th>Cari Durumu</th>
                                <th>Durum</th>
                                <th>Not</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($entries as $entry)
                                <tr>
                                    <td>{{ optional($entry->entry_date)->format('d.m.Y') ?: '-' }}</td>
                                    <td>{{ $entry->entry_type === 'supplier_purchase' ? 'Satın Alma' : 'Eldeki Mevcut Stok' }}</td>
                                    <td>{{ $entry->supplier_name ?: '-' }}</td>
                                    <td>
                                        <div class="font-medium">{{ $entry->product_name }}</div>
                                        <div class="text-xs text-gray-500">{{ $entry->product_code }}</div>
                                    </td>
                                    <td>{{ number_format((float) $entry->quantity, 0, ',', '.') }}</td>
                                    <td>{{ $entry->entry_type === 'supplier_purchase' ? number_format((float) $entry->purchase_total_try, 2, ',', '.') . ' TL' : '-' }}</td>
                                    <td>{{ $entry->entry_status === 'cancelled' ? 'Ters kayıt oluşturuldu' : 'Tamamlandı' }}</td>
                                    <td>{{ $entry->entry_type === 'supplier_purchase' ? ($entry->payable_status === 'cancelled' ? 'İptal Edildi' : 'Açık Borç') : '-' }}</td>
                                    <td>{{ $entry->entry_status === 'cancelled' ? 'İptal Edildi' : 'Tamamlandı' }}</td>
                                    <td>{{ $entry->notes ?: '-' }}</td>
                                    <td><a href="{{ route('admin.stock-purchases.show', $entry) }}" class="pd-btn pd-btn-sm pd-btn-light">Detay</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @include('admin.catalog.partials.pagination', ['paginator' => $entries, 'filters' => $filters])
            @endif
        </div>
    </section>
</div>
@endsection
