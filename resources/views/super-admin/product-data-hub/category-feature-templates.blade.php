@extends('layouts.prodelya-admin')

@section('title', 'Özellik Şablonları')
@section('page_title', 'Özellik Şablonları')
@section('page_subtitle', 'Renk, malzeme, hacim, kapasite, ebat ve baskı türü gibi alanları kategori çoğaltmadan yönetin.')

@section('page_actions')
<div class="pd-actions-wrap">
    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Standart Kategori Ağacı</a>
    <a href="{{ route('admin.super.product-data-hub.category-cleanup.index') }}" class="pd-btn pd-btn-light">Kategori Temizlik</a>
</div>
@endsection

@section('content')
<div class="pd-page-shell">
    <section class="pd-card mb-4">
        <div class="pd-card-body">
            <h2 class="pd-card-title">Özellikler kategori çoğaltmayı azaltır</h2>
            <p class="pd-card-subtitle">Bu şablonlar web filtrelerinde, tenant katalog filtrelerinde, import/export alanlarında ve kategori öneri motorunda kullanılır. Teklif ekranını kalabalıklaştırmak için değildir.</p>
        </div>
    </section>

    <section class="pd-card">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Şablon Listesi</h2>
                <p class="pd-card-subtitle">Her şablonda alan adı, alan kodu, tip, görünürlük ve kullanım amacı birlikte okunur.</p>
            </div>
            <span class="pd-badge pd-badge-blue">Yardımcı ekran</span>
        </div>
        <div class="pd-card-body">
            <div class="pd-template-grid">
                @foreach($featureTemplates as $template)
                    <article class="pd-template-card">
                        <h3>{{ $template['name'] }}</h3>
                        <p>{{ $template['category'] }}</p>
                        <div class="pd-table-wrapper mt-3">
                            <table class="pd-table pd-table-compact">
                                <thead>
                                    <tr>
                                        <th>Özellik adı</th>
                                        <th>Alan kodu</th>
                                        <th>Tip</th>
                                        <th>Kullanım</th>
                                        <th>Birim</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($template['fields'] as $field)
                                        @php
                                            [$name, $type] = array_pad(explode(':', $field, 2), 2, 'text');
                                            $code = \Illuminate\Support\Str::slug($name, '_');
                                            $unit = collect(['ml', 'mAh', 'GB', 'cm', 'mm'])->first(fn ($candidate) => \Illuminate\Support\Str::contains($field, $candidate));
                                        @endphp
                                        <tr>
                                            <td>{{ trim($name) }}</td>
                                            <td><code>{{ $code }}</code></td>
                                            <td>{{ trim($type) }}</td>
                                            <td>
                                                <div class="pd-chip-list">
                                                    <span class="pd-muted-badge">Filtre</span>
                                                    <span class="pd-muted-badge">Katalog</span>
                                                    <span class="pd-muted-badge">Export</span>
                                                    <span class="pd-muted-badge">Import</span>
                                                    <span class="pd-muted-badge">Öneri motoru</span>
                                                </div>
                                            </td>
                                            <td>{{ $unit ?: '-' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <small>{{ $template['purpose'] }}</small>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Şablon Amacı</h3>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Web filtresi</span><span class="pd-badge pd-badge-green">Evet</span></div>
            <div class="pd-status-row"><span>Tenant katalog</span><span class="pd-badge pd-badge-green">Evet</span></div>
            <div class="pd-status-row"><span>Import / Export</span><span class="pd-badge pd-badge-blue">Evet</span></div>
            <div class="pd-status-row"><span>Teklif formu kalabalığı</span><span class="pd-badge pd-badge-gray">Hayır</span></div>
        </div>
        <div class="pd-side-note">Özellikler kategori ağacını sade tutmak için kullanılır. Gerçek ürün taşıma veya kategori merge bu ekranda yapılmaz.</div>
    </div>
</div>
@endsection
