@extends('layouts.prodelya-admin')

@section('title', 'Dosyadan Ürün Aktar')
@section('page_title', 'Dosyadan Ürün Aktar')
@section('page_subtitle', 'CSV önizleme ve explicit apply akışıyla flat ve varyantlı kendi ürünlerinizi güvenle içe aktarın.')

@section('content')
@php
    /** @var \App\Services\TenantCatalog\LocalProductFieldCatalogService $fieldCatalog */
    $fieldCatalog = app(\App\Services\TenantCatalog\LocalProductFieldCatalogService::class);
    $aliasLabels = $fieldCatalog->labelsByCsvAlias();
    $previewHeaders = $fieldCatalog->importPreviewHeaders();
    $hasPreviewErrors = !empty($preview['errors'] ?? []);
    $delimiterLabel = match ($preview['delimiter'] ?? ',') {
        ';' => 'Noktalı virgül (;)',
        "\t" => 'Sekme (TAB)',
        default => 'Virgül (,)',
    };
@endphp
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
                    <span class="pd-local-product-eyebrow">Abone Firma · Dosyadan Ürün Aktar</span>
                    <h1 class="pd-local-product-hero-title">Dosyadan Ürün Aktar</h1>
                    <p class="pd-local-product-hero-subtitle">Canonical own-product alan sözleşmesine göre CSV yükleyin, önce preview ile kontrol edin, ardından açık onayla apply edin.</p>
                </div>
                <div class="pd-local-product-hero-actions">
                    <a href="{{ route('admin.catalog.local-products.import.template') }}" class="pd-btn pd-btn-primary">Örnek CSV Şablonu İndir</a>
                    <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-light">Ürün Listem</a>
                </div>
            </div>
            <div class="pd-local-product-stepper">
                <div class="pd-local-product-step is-active"><span>1</span><strong>CSV Yükle</strong></div>
                <div class="pd-local-product-step {{ $preview ? 'is-active' : '' }}"><span>2</span><strong>Önizleme</strong></div>
                <div class="pd-local-product-step {{ $preview ? 'is-active' : '' }}"><span>3</span><strong>Explicit Apply</strong></div>
            </div>
        </div>
    </section>

    <div class="pd-local-product-layout">
        <section class="pd-section-card pd-local-product-main-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">CSV Yükleme</h3>
                    <p class="pd-section-subtitle">`urun_id`, `urun_detay_url` ve `urun_tedarikci` sistem/supplier alanlarıdır; input şablonuna dahil edilmez. Preview adımı DB write yapmaz.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form action="{{ route('admin.catalog.local-products.import.preview') }}" method="POST" enctype="multipart/form-data" class="pd-local-product-import-upload">
                    @csrf
                    <div class="pd-local-product-dropzone">
                        <label for="local-product-import-file" class="pd-local-product-dropzone-label">CSV dosyasını seçin veya sürükleyip bırakın</label>
                        <input id="local-product-import-file" type="file" name="file" accept=".csv,text/csv" class="pd-input @error('file') pd-input-error @enderror" required>
                        <div class="pd-local-product-help">Yalnız .csv desteklenir. Maksimum 5 MB.</div>
                        @error('file')<div class="pd-local-product-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="pd-local-product-filter-actions">
                        <button type="submit" class="pd-btn pd-btn-primary">Önizleme Al</button>
                    </div>
                </form>

                <div class="pd-local-product-field-map-card">
                    <div>
                        <h4>Alan Karşılıkları</h4>
                        <p>CSV kolonları create/edit/detail ile aynı canonical own-product field catalog üzerinden yorumlanır.</p>
                    </div>
                    <div class="pd-local-product-field-map-grid">
                        @foreach($fieldCatalog->csvTemplateHeaders() as $alias)
                            <div class="pd-local-product-field-map-row">
                                <span>{{ $alias }}</span>
                                <strong>{{ $aliasLabels[$alias] ?? $alias }}</strong>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if($preview)
                    <div class="pd-local-product-import-preview-shell">
                        <div class="pd-local-product-import-preview-head">
                            <div>
                                <h4>Önizleme ve Kontrol</h4>
                                <p>Toplam {{ number_format((int) ($preview['total'] ?? 0), 0, ',', '.') }} satır bulundu. İlk 10 normalize satır listeleniyor.</p>
                            </div>
                            <div class="pd-local-product-import-metrics">
                                <span class="pd-badge pd-badge-green">Toplam {{ number_format((int) ($preview['total'] ?? 0), 0, ',', '.') }}</span>
                                <span class="pd-badge pd-badge-blue">Ayrıştırıcı {{ $delimiterLabel }}</span>
                                <span class="pd-badge pd-badge-amber">Uyarı {{ number_format((int) count($preview['errors'] ?? []), 0, ',', '.') }}</span>
                            </div>
                        </div>

                        @if(!empty($preview['errors']))
                            <div class="pd-local-product-alert pd-local-product-alert-warning">
                                <strong>Doğrulama uyarıları</strong>
                                <ul>
                                    @foreach(array_slice($preview['errors'], 0, 12) as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="pd-local-product-table-wrap">
                            <table class="pd-table pd-local-product-table">
                                <thead>
                                    <tr>
                                        @foreach($previewHeaders as $label)
                                            <th>{{ $label }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($preview['preview_rows'] as $row)
                                        <tr>
                                            <td>{{ $row['line'] ?? '-' }}</td>
                                            <td>{{ ($row['product_type'] ?? 'flat') === 'variant' ? 'Varyantlı' : 'Varyantsız' }}</td>
                                            <td>{{ $row['group_code'] ?? '-' }}</td>
                                            <td>{{ $row['product_code'] ?? '-' }}</td>
                                            <td>{{ $row['product_name'] ?? '-' }}</td>
                                            <td>{{ $row['category_label'] ?? '-' }}</td>
                                            <td>{{ $row['display_price'] !== null ? number_format((float) $row['display_price'], 2, ',', '.') : '-' }}</td>
                                            <td>{{ ($row['currency'] ?? 'TRY') === 'TRY' ? 'TL' : ($row['currency'] ?? 'TRY') }}</td>
                                            <td>{{ number_format((float) ($row['initial_stock'] ?? 0), 0, ',', '.') }}</td>
                                            <td>{{ $row['variant_color'] ?? '-' }}</td>
                                            <td>{{ $row['variant_size'] ?? '-' }}</td>
                                            <td>{{ $row['variant_dimensions'] ?? '-' }}</td>
                                            <td>
                                                @if(!empty($row['errors']))
                                                    <span class="pd-badge pd-badge-amber">Kontrol gerekli</span>
                                                    <div class="pd-local-product-help" style="margin-top:6px;">{{ $row['errors'][0] }}</div>
                                                @else
                                                    <span class="pd-badge pd-badge-green">Hazır</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <form action="{{ route('admin.catalog.local-products.import.apply') }}" method="POST" class="pd-local-product-import-store-row">
                            @csrf
                            <div>
                                <label class="pd-label" for="duplicate_policy">Duplicate exact SKU davranışı</label>
                                <select id="duplicate_policy" name="duplicate_policy" class="pd-select">
                                    <option value="update">Var olanı güncelle</option>
                                    <option value="skip">Var olanı atla</option>
                                </select>
                                @if($hasPreviewErrors)
                                    <div class="pd-local-product-help" style="margin-top:6px;">Hatalı satırlar çözülmeden explicit apply açılamaz.</div>
                                @endif
                            </div>
                            <div class="pd-local-product-sticky-actions-buttons">
                                <button type="submit" class="pd-btn pd-btn-primary" @disabled($hasPreviewErrors)>Explicit Apply</button>
                                <a href="{{ route('admin.catalog.local-products.import') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        </section>

        <aside class="pd-local-product-sidebar">
            <section class="pd-section-card pd-local-product-summary-card">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">İçe Aktarım Özeti</h3>
                        <p class="pd-section-subtitle">Bu yüzey yalnız CSV preview + apply akışını taşır.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-local-product-sidebar-list">
                        <div class="pd-local-product-sidebar-row"><span>Format</span><strong>CSV</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Flat destek</span><strong>Var</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Exact varyant destek</span><strong>Var</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Preview write</span><strong>Yok</strong></div>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection
