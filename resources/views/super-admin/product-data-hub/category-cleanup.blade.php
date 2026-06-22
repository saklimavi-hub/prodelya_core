@extends('layouts.prodelya-admin')

@section('title', 'Kategori Temizlik')
@section('page_title', 'Kategori Temizlik')
@section('page_subtitle', 'Standart kategori ağacındaki duplicate, alias, ikiz görünüm, birleştirme ve pasif adaylarını sade şekilde inceleyin.')

@section('page_actions')
<div class="pd-actions-wrap">
    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Standart Kategori Ağacı</a>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-light">Kategori Eşleme Kuyruğu</a>
    <a href="{{ route('admin.super.product-data-hub.category-feature-templates.index') }}" class="pd-btn pd-btn-primary">Özellik Şablonları</a>
</div>
@endsection

@section('content')
@php
    $draftRoots = $draft->items->whereNull('parent_id')->sortBy('sort_order');
    $actionLabels = [
        'merge' => 'Birleştirme adayı',
        'alias' => 'Alias adayı',
        'twin_view' => 'İkiz görünüm adayı',
        'filter_attribute' => 'Filtre / özellik adayı',
        'deactivate' => 'Pasif adayı',
        'needs_review' => 'Kontrol gerekli',
        'separate_keep' => 'Ayrı bırak',
        'move' => 'Taşıma önerisi',
        'keep' => 'Koru',
    ];
@endphp

<div class="pd-page-shell">
    <section class="pd-card mb-4">
        <div class="pd-card-body">
            <div class="pd-flow-steps">
                <span>1. Standart Kategori Ağacı</span>
                <span>2. Kategori Temizlik</span>
                <span>3. Kategori Eşleme Kuyruğu</span>
                <span>4. Özellik Şablonları</span>
                <span>5. Uygulama Önizlemesi</span>
            </div>
            <div class="pd-note mt-3">Bu ekran sadece standart kategori ağacındaki fazlalıkları inceler. Tedarikçi kategori eşleme işi Kategori Eşleme Kuyruğu ekranında yapılır.</div>
        </div>
    </section>

    <div class="pd-metric-grid pd-metric-grid-6">
        <div class="pd-metric-card"><span>Toplam kategori</span><strong>{{ number_format($analysis['total']) }}</strong></div>
        <div class="pd-metric-card pd-metric-card-warning"><span>Duplicate isim</span><strong>{{ number_format($analysis['duplicate_name_count']) }}</strong></div>
        <div class="pd-metric-card"><span>Farklı parent tekrar</span><strong>{{ number_format($analysis['repeated_across_parent_count']) }}</strong></div>
        <div class="pd-metric-card"><span>3+ seviye</span><strong>{{ number_format($analysis['deep_count']) }}</strong></div>
        <div class="pd-metric-card pd-metric-card-danger"><span>Boş kategori</span><strong>{{ number_format($analysis['empty_count']) }}</strong></div>
        <div class="pd-metric-card pd-metric-card-soft-purple"><span>Review</span><strong>{{ number_format($decisionSummary['needs_review']) }}</strong></div>
    </div>

    <section class="pd-card mt-4">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Temizlik Grupları</h2>
                <p class="pd-card-subtitle">Aynı isimli kategoriler, benzer aileler, boş kategoriler ve riskli derin yapılar tek amaçla listelenir: sadeleşme kararı üretmek.</p>
            </div>
            <span class="pd-badge pd-badge-amber">Apply yok</span>
        </div>
        <div class="pd-card-body">
            <div class="pd-grid pd-grid-2">
                @foreach($reviewGroups as $group)
                    <article class="pd-cleanup-card">
                        <div class="pd-cleanup-card-head">
                            <div>
                                <h3>{{ $group['title'] }}</h3>
                                <p>{{ $group['recommendation'] }}</p>
                            </div>
                            <span class="pd-badge pd-badge-blue">{{ $group['count'] }} aday</span>
                        </div>
                        <div class="pd-cleanup-card-body">
                            @forelse($group['paths'] as $path)
                                <div class="pd-summary-item">{{ $path }}</div>
                            @empty
                                <div class="pd-note">Bu grup için aday bulunmadı.</div>
                            @endforelse
                        </div>
                        <div class="pd-chip-list mt-3">
                            <span class="pd-muted-badge">Birleştirme adayı yap</span>
                            <span class="pd-muted-badge">Alias adayı yap</span>
                            <span class="pd-muted-badge">İkiz görünüm adayı yap</span>
                            <span class="pd-muted-badge">Ayrı bırak</span>
                            <span class="pd-muted-badge">Kontrol edildi</span>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-card mt-4">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Temizlik Kararları</h2>
                <p class="pd-card-subtitle">Her kategori için önerilen sadeleşme aksiyonu. Bu liste gerçek ağacı değiştirmez.</p>
            </div>
            <div class="pd-page-actions">
                <a href="{{ route('admin.super.product-data-hub.category-cleanup.export', 'csv') }}" class="pd-btn pd-btn-light pd-btn-sm">CSV Export</a>
                <a href="{{ route('admin.super.product-data-hub.category-cleanup.export', 'json') }}" class="pd-btn pd-btn-light pd-btn-sm">JSON Export</a>
            </div>
        </div>
        <div class="pd-card-body">
            <div class="pd-decision-filter-row">
                <span>Tümü</span>
                <span>İncelenmemiş</span>
                <span>Merge adayı</span>
                <span>Alias adayı</span>
                <span>İkiz görünüm adayı</span>
                <span>Filtre/özellik adayı</span>
                <span>Pasif adayı</span>
                <span>Yüksek risk</span>
            </div>
            <div class="pd-table-wrapper">
                <table class="pd-table pd-table-compact">
                    <thead>
                        <tr>
                            <th>Problem tipi</th>
                            <th>Kategori</th>
                            <th>Hedef / yeni yol</th>
                            <th>Ürün</th>
                            <th>Eşleme</th>
                            <th>Alt</th>
                            <th>Risk</th>
                            <th>Önerilen karar</th>
                            <th>Aksiyonlar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($decisionRows->where('proposed_action', '!=', 'keep')->take(80) as $decision)
                            <tr>
                                <td>{{ $actionLabels[$decision->proposed_action] ?? $decision->proposed_action }}</td>
                                <td>
                                    <strong>{{ $decision->current_name }}</strong>
                                    <div class="pd-profile-note">{{ $decision->current_path }}</div>
                                </td>
                                <td>{{ $decision->proposed_category_path ?: '-' }}</td>
                                <td>{{ number_format($decision->product_count) }}</td>
                                <td>{{ number_format($decision->supplier_mapping_count) }}</td>
                                <td>{{ number_format($decision->child_count) }}</td>
                                <td><span class="pd-badge {{ $decision->risk_level === 'high' ? 'pd-badge-red' : ($decision->risk_level === 'medium' ? 'pd-badge-amber' : 'pd-badge-green') }}">{{ $decision->risk_level }}</span></td>
                                <td>{{ \Illuminate\Support\Str::limit($decision->reason, 120) }}</td>
                                <td>
                                    <div class="pd-chip-list">
                                        <span class="pd-muted-badge">Birleştirme adayı yap</span>
                                        <span class="pd-muted-badge">Alias adayı yap</span>
                                        <span class="pd-muted-badge">İkiz görünüm adayı yap</span>
                                        <span class="pd-muted-badge">Pasif adayı yap</span>
                                        <span class="pd-muted-badge">Kontrol edildi</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="pd-card mt-4 mb-20">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Yeni Kategori Taslağı</h2>
                <p class="pd-card-subtitle">Taslak sadece özetlenir. Detaylı düzenleme ayrı apply fazında ele alınacaktır.</p>
            </div>
            <span class="pd-badge pd-badge-purple">Taslak · Aktif değil · Uygulanmadı</span>
        </div>
        <div class="pd-card-body">
            <div class="pd-table-wrapper">
                <table class="pd-table pd-table-compact">
                    <thead>
                        <tr>
                            <th>Ana kategori</th>
                            <th>Alt kategori</th>
                            <th>Karar durumu</th>
                            <th>Onay durumu</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($draftRoots as $root)
                            <tr>
                                <td><strong>{{ $root->proposed_name }}</strong></td>
                                <td>{{ number_format($root->children->count()) }}</td>
                                <td>{{ $root->action_type }}</td>
                                <td>Taslak</td>
                                <td><span class="pd-muted-badge">Taslağı Aç</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pd-note mt-3">{{ $preview['risk_note'] }}</div>
        </div>
    </section>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Temizlik Özeti</h3>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Merge</span><span class="pd-badge pd-badge-red">{{ $decisionSummary['by_action']['merge'] ?? 0 }}</span></div>
            <div class="pd-status-row"><span>Alias</span><span class="pd-badge pd-badge-purple">{{ $decisionSummary['by_action']['alias'] ?? 0 }}</span></div>
            <div class="pd-status-row"><span>İkiz görünüm</span><span class="pd-badge pd-badge-amber">{{ $decisionSummary['by_action']['twin_view'] ?? 0 }}</span></div>
            <div class="pd-status-row"><span>Pasif adayı</span><span class="pd-badge pd-badge-gray">{{ $decisionSummary['by_action']['deactivate'] ?? 0 }}</span></div>
        </div>
        <div class="pd-side-note">Tedarikçi kategorisi eşleme kartları bu ekranda gösterilmez. Eşleme işi Kategori Eşleme Kuyruğu ekranındadır.</div>
    </div>
</div>
@endsection
