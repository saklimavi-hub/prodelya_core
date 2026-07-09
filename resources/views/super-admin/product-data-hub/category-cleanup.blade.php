@extends('layouts.prodelya-admin')

@section('title', 'Kategori Temizlik')
@section('page_title', 'Kategori Temizlik')
@section('page_subtitle', 'Kategori adlarını analiz edin, kararları hazırlayın, taslak düzeni inceleyin ve dışa aktarma çıktısını güvenle yönetin.')

@section('page_actions')
<div class="pd-actions-wrap">
    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Standart Kategori Ağacı</a>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-light">Kategori Eşleme Kuyruğu</a>
    <a href="{{ route('admin.super.product-data-hub.category-feature-templates.index') }}" class="pd-btn pd-btn-light">Özellik Şablonları</a>
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
                <span>5. Onaylı Uygulama Fazı</span>
            </div>
            <div class="pd-note mt-3">Bu ekran tedarikçilerden gelen kategori adlarını analiz etmek ve Prodelya standart kategori ağacına bağlanacak kararları hazırlamak için kullanılır. Bu fazda gerçek kategori ağacı otomatik değiştirilmez; kararlar önce incelenir.</div>
            <div class="pd-note mt-3">Bu ekrandaki bazı işlemler taslak veya öneri niteliğindedir. Gerçek kategori değişikliği ayrıca onaylı uygulama fazında yapılır.</div>
        </div>
    </section>

    <div class="pd-kpi-strip">
        <div class="pd-metric-card"><span>Toplam kategori</span><strong>{{ number_format($analysis['total']) }}</strong></div>
        <div class="pd-metric-card pd-metric-card-warning"><span>Duplicate isim</span><strong>{{ number_format($analysis['duplicate_name_count']) }}</strong></div>
        <div class="pd-metric-card"><span>Farklı parent tekrar</span><strong>{{ number_format($analysis['repeated_across_parent_count']) }}</strong></div>
        <div class="pd-metric-card"><span>3+ seviye</span><strong>{{ number_format($analysis['deep_count']) }}</strong></div>
        <div class="pd-metric-card pd-metric-card-danger"><span>Boş kategori</span><strong>{{ number_format($analysis['empty_count']) }}</strong></div>
        <div class="pd-metric-card pd-metric-card-soft-purple"><span>Kontrol gereken</span><strong>{{ number_format($decisionSummary['needs_review']) }}</strong></div>
    </div>

    <section class="pd-card mt-4">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Analiz Özeti</h2>
                <p class="pd-card-subtitle">Temizlik Grupları aynı isimli kategorileri, benzer aileleri, boş kayıtları ve derin yapıları karar hazırlığı için özetler.</p>
            </div>
            <span class="pd-badge pd-badge-amber">Gerçek kategori ağacını otomatik değiştirmez</span>
        </div>
        <div class="pd-card-body">
            <div class="pd-mini-kpi-strip">
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
                            <span class="pd-muted-badge">Taslak: Birleştirme adayı</span>
                            <span class="pd-muted-badge">Taslak: Alias adayı</span>
                            <span class="pd-muted-badge">Taslak: İkiz görünüm</span>
                            <span class="pd-muted-badge">Taslak: Ayrı bırak</span>
                            <span class="pd-muted-badge">Hazırlık: Kontrol edildi</span>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-card mt-4">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Karar Listesi</h2>
                <p class="pd-card-subtitle">Her kategori için önerilen karar burada toplanır. Bu liste gerçek kategori ağacını değiştirmez; kararları inceleme ve dışa aktarma için hazırlar.</p>
            </div>
            <div class="pd-page-actions">
                <a href="{{ route('admin.super.product-data-hub.category-cleanup.export', 'csv') }}" class="pd-btn pd-btn-light pd-btn-sm">Dışa Aktar: CSV</a>
                <a href="{{ route('admin.super.product-data-hub.category-cleanup.export', 'json') }}" class="pd-btn pd-btn-light pd-btn-sm">Dışa Aktar: JSON</a>
            </div>
        </div>
        <div class="pd-card-body">
            <div class="pd-note mb-4">Eşleşmeyen kategoriler ürünlerde Kategori eşleşmemiş uyarısı üretir; tek başına teklif görünürlüğünü kapatmaz.</div>
            <div class="pd-health-list">
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
                                        <span class="pd-muted-badge">Taslak: Birleştirme</span>
                                        <span class="pd-muted-badge">Taslak: Alias</span>
                                        <span class="pd-muted-badge">Taslak: İkiz görünüm</span>
                                        <span class="pd-muted-badge">Taslak: Pasif adayı</span>
                                        <span class="pd-muted-badge">Hazırlık: Kontrol edildi</span>
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
                <h2 class="pd-card-title">Taslak Düzen</h2>
                <p class="pd-card-subtitle">Yeni Kategori Taslağı burada özetlenir. Taslak kararlar gerçek kategori ağacını değiştirmez.</p>
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
                                <td><span class="pd-muted-badge">Taslağı İncele</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pd-note mt-3">{{ $preview['risk_note'] }}</div>
        </div>
    </section>

    <section class="pd-card mt-4 mb-20">
        <div class="pd-card-header">
            <div>
                <h2 class="pd-card-title">Dışa Aktar</h2>
                <p class="pd-card-subtitle">Karar listesini ekip incelemesi veya sonraki onaylı uygulama fazı için dışa aktarın. Export gerçek kategori ağacını değiştirmez.</p>
            </div>
            <span class="pd-badge pd-badge-blue">Güvenli çıktı</span>
        </div>
        <div class="pd-card-body">
            <div class="pd-chip-list">
                <a href="{{ route('admin.super.product-data-hub.category-cleanup.export', 'csv') }}" class="pd-btn pd-btn-light pd-btn-sm">Kategori Kararlarını CSV Al</a>
                <a href="{{ route('admin.super.product-data-hub.category-cleanup.export', 'json') }}" class="pd-btn pd-btn-light pd-btn-sm">Kategori Kararlarını JSON Al</a>
                <span class="pd-muted-badge">Orijinal kategori korunur</span>
                <span class="pd-muted-badge">Taslak kararlar uygulanmaz</span>
            </div>
        </div>
    </section>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Kategori Analizi Özeti</h3>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Merge</span><span class="pd-badge pd-badge-red">{{ $decisionSummary['by_action']['merge'] ?? 0 }}</span></div>
            <div class="pd-status-row"><span>Alias</span><span class="pd-badge pd-badge-purple">{{ $decisionSummary['by_action']['alias'] ?? 0 }}</span></div>
            <div class="pd-status-row"><span>İkiz görünüm</span><span class="pd-badge pd-badge-amber">{{ $decisionSummary['by_action']['twin_view'] ?? 0 }}</span></div>
            <div class="pd-status-row"><span>Pasif adayı</span><span class="pd-badge pd-badge-gray">{{ $decisionSummary['by_action']['deactivate'] ?? 0 }}</span></div>
        </div>
        <div class="pd-side-note">Tedarikçi kategorisi eşleme kartları bu ekranda gösterilmez. Eşleme işi Kategori Eşleme ekranındadır. Bu alan analiz, karar listesi, taslak düzen ve dışa aktarma amaçlıdır.</div>
    </div>
</div>
@endsection
