@extends('layouts.prodelya-admin')

@section('title', 'Kategori Review Paketi ' . $batch)
@section('page_title', 'Kategori Review Paketi ' . $batch)
@section('page_subtitle', 'İlk kritik kayıtları kullanıcı kararı için inceleyin. Kararlar kaydedilir; mapping apply ve category refresh ayrı fazdadır.')

@php
    $statusLabels = [
        '' => 'Karar Bekliyor',
        'approved' => 'Onaylandı',
        'changed' => 'Değiştirildi',
        'held' => 'Bekletildi',
        'rejected' => 'Reddedildi',
        'separate_keep' => 'Ayrı Bırakıldı',
    ];
@endphp

@section('content')
<div class="pd-page-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Kategori Review Paketi {{ $batch }}</h1>
                    <p class="pd-hero-subtitle">Bu ekran kategori eşleme kuyruğundaki ilk kritik kayıtları kullanıcı kararı için gösterir. Karar kaydı apply değildir.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Paket {{ $batch }}</span>
                        <span class="pd-badge pd-badge-amber">Apply durumu: uygulanmadı</span>
                        <span class="pd-badge pd-badge-green">{{ $summary['row_count'] }} kayıt</span>
                        <span class="pd-badge pd-badge-purple">{{ $summary['pending'] }} karar bekleyen</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-light">Eşleme Kuyruğu</a>
                    <a href="{{ route('admin.super.product-data-hub.category-review-batches.export', [$batch, 'csv']) }}" class="pd-btn pd-btn-light">CSV Export</a>
                    <a href="{{ route('admin.super.product-data-hub.category-review-batches.export', [$batch, 'json']) }}" class="pd-btn pd-btn-primary">JSON Export</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-metric-grid">
        <div class="pd-metric-card pd-metric-card-soft-blue">
            <div class="pd-metric-card-label">Kayıt Sayısı</div>
            <div class="pd-metric-card-value">{{ $summary['row_count'] }}</div>
            <div class="pd-metric-card-note">Kaynak: category_review_batch_{{ $batch }}.json</div>
        </div>
        <div class="pd-metric-card pd-metric-card-soft-amber">
            <div class="pd-metric-card-label">Karar Bekleyen</div>
            <div class="pd-metric-card-value">{{ $summary['pending'] }}</div>
            <div class="pd-metric-card-note">Henüz kullanıcı kararı yok</div>
        </div>
        <div class="pd-metric-card pd-metric-card-soft-green">
            <div class="pd-metric-card-label">Onaylanan</div>
            <div class="pd-metric-card-value">{{ $summary['approved'] }}</div>
            <div class="pd-metric-card-note">Sonraki apply fazına aday</div>
        </div>
        <div class="pd-metric-card pd-metric-card-soft-purple">
            <div class="pd-metric-card-label">Değiştirilen / Bekletilen</div>
            <div class="pd-metric-card-value">{{ $summary['changed'] }} / {{ $summary['held'] }}</div>
            <div class="pd-metric-card-note">Operatör kararı ile ayrışanlar</div>
        </div>
    </section>

    <section class="pd-card">
        <div class="pd-card-body">
            <div class="pd-section-heading">
                <div>
                    <h2>Filtreler</h2>
                    <p>{{ $rows->count() }} kayıt gösteriliyor. Oluşturulma zamanı: {{ $summary['generated_at'] ?: '-' }}</p>
                </div>
                <span class="pd-badge pd-badge-gray">Kaynak dosya: {{ $summary['source_path'] }}</span>
            </div>
            <form method="GET" class="pd-filter-grid">
                <label>
                    <span>Tedarikçi</span>
                    <select name="supplier" class="pd-input">
                        <option value="">Tümü</option>
                        @foreach($supplierOptions as $supplier)
                            <option value="{{ $supplier }}" @selected($filters['supplier'] === $supplier)>{{ $supplier }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Risk Grubu</span>
                    <select name="risk_group" class="pd-input">
                        <option value="">Tümü</option>
                        @foreach($riskOptions as $risk)
                            <option value="{{ $risk }}" @selected($filters['risk_group'] === $risk)>{{ $risk }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Karar Durumu</span>
                    <select name="decision_status" class="pd-input">
                        <option value="">Tümü</option>
                        <option value="pending" @selected($filters['decision_status'] === 'pending')>Karar Bekleyen</option>
                        <option value="approved" @selected($filters['decision_status'] === 'approved')>Onaylanan</option>
                        <option value="changed" @selected($filters['decision_status'] === 'changed')>Değiştirilen</option>
                        <option value="held" @selected($filters['decision_status'] === 'held')>Bekletilen</option>
                        <option value="rejected" @selected($filters['decision_status'] === 'rejected')>Reddedilen</option>
                        <option value="separate_keep" @selected($filters['decision_status'] === 'separate_keep')>Ayrı Bırakılan</option>
                    </select>
                </label>
                <label>
                    <span>Önerilen Sınıf</span>
                    <select name="suggested_class" class="pd-input">
                        <option value="">Tümü</option>
                        @foreach($classOptions as $class)
                            <option value="{{ $class }}" @selected($filters['suggested_class'] === $class)>{{ $class }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Hedef Durumu</span>
                    <select name="target_state" class="pd-input">
                        <option value="">Tümü</option>
                        <option value="with_target" @selected($filters['target_state'] === 'with_target')>Hedef var</option>
                        <option value="missing_target" @selected($filters['target_state'] === 'missing_target')>Hedef yok</option>
                    </select>
                </label>
                <label>
                    <span>Arama</span>
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="pd-input" placeholder="Kategori, tedarikçi, ürün örneği">
                </label>
                <div class="pd-filter-actions">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.product-data-hub.category-review-batches.show', $batch) }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
            <div class="pd-chip-row">
                @foreach(['' => 'Tümü', 'pending' => 'Karar Bekleyen', 'approved' => 'Onaylanan', 'changed' => 'Değiştirilen', 'held' => 'Bekletilen', 'rejected' => 'Reddedilen'] as $key => $label)
                    <a href="{{ route('admin.super.product-data-hub.category-review-batches.show', array_filter([$batch, 'decision_status' => $key])) }}" class="pd-filter-chip {{ $filters['decision_status'] === $key ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
            <div class="pd-chip-row">
                @foreach(['Masa Sümeni', 'Mousepad', 'Takvim', 'Hediyelik Setler', 'Kupa / Malzeme', 'Set Kutuları', 'Açacak / Magnet'] as $risk)
                    <a href="{{ route('admin.super.product-data-hub.category-review-batches.show', [$batch, 'risk_group' => $risk]) }}" class="pd-filter-chip {{ $filters['risk_group'] === $risk ? 'active' : '' }}">{{ $risk }}</a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-card">
        <div class="pd-card-body">
            <div class="pd-section-heading">
                <div>
                    <h2>Özel Kural Hatırlatmaları</h2>
                    <p>Bu kurallar karar verirken rehberdir; kayıt sadece review karar havuzuna yazılır.</p>
                </div>
            </div>
            <div class="pd-review-rule-grid">
                <div><strong>Hediyelik Setler:</strong> Tek hedef Hediyelik Setler; VIP, kalemli, defterli, termoslu gibi alt türler özellik olarak tutulur.</div>
                <div><strong>Kupalar:</strong> Seramik, porselen, cam, metal kategori değil malzeme özelliğidir.</div>
                <div><strong>Takvimler:</strong> Matbaa Ürünleri > Takvimler altında değerlendirilir; promosyon tarafına önerilmez.</div>
                <div><strong>Masa Sümeni:</strong> Ofis, Kağıt & Üretim veya Matbaa > Takvimler ayrımı ürün sinyaline göre yapılır.</div>
                <div><strong>Mousepad:</strong> Wireless teknoloji; klasik/baskılı kağıt üretim tarafında kalır.</div>
                <div><strong>Set Kutuları:</strong> Boş kutu ambalajdır; ürünlü set Hediyelik Setler + kutu_tipi özelliğidir.</div>
                <div><strong>Açacak / Magnet:</strong> Birleştirilmez; ayrı hedeflere gider.</div>
            </div>
        </div>
    </section>

    <section class="pd-review-list">
        @forelse($rows as $row)
            @php
                $decisionStatus = $row['user_decision'] ?: '';
                $mappingId = $row['supplier_category_mapping_id'] ?? null;
                $suggestedDecision = match ($row['suggested_decision'] ?? '') {
                    'Alias Yap' => 'alias',
                    'Özellik/Filtre Yap', 'Eşle veya Özellik/Filtre Yap' => 'feature_attribute',
                    'Ayrı Bırak' => 'separate_keep',
                    'Reddet' => 'reject',
                    'Manuel incele' => 'hold',
                    default => 'map',
                };
            @endphp
            <article class="pd-review-card" id="review-row-{{ $row['priority'] }}">
                <div class="pd-review-block">
                    <div class="pd-block-title">Sol — Tedarikçi Bilgisi</div>
                    <div class="pd-priority">#{{ $row['priority'] }}</div>
                    <h3>{{ $row['supplier_category_name'] ?: '-' }}</h3>
                    <p class="pd-muted">{{ $row['supplier'] ?: 'Tedarikçi yok' }}</p>
                    <div class="pd-meta-stack">
                        <span>Kod: {{ $row['supplier_category_code'] ?: '-' }}</span>
                        <span>Yol: {{ $row['supplier_category_path'] ?: '-' }}</span>
                        <span>Ürün sayısı: {{ $row['product_count'] }}</span>
                    </div>
                    <div class="pd-sample-products">{{ $row['sample_products'] ?: 'Örnek ürün yok' }}</div>
                </div>

                <div class="pd-review-block">
                    <div class="pd-block-title">Orta — Sistem Önerisi</div>
                    <div class="pd-badge-row">
                        <span class="pd-badge pd-badge-gray">{{ $row['current_status'] }}</span>
                        <span class="pd-badge pd-badge-amber">{{ $row['risk_group'] }}</span>
                        <span class="pd-badge pd-badge-blue">{{ $row['suggested_class'] }}</span>
                    </div>
                    <dl class="pd-decision-dl">
                        <dt>Önerilen hedef</dt>
                        <dd>{{ $row['suggested_target_category'] ?: 'Hedef yok' }}</dd>
                        <dt>Önerilen özellik</dt>
                        <dd>{{ $row['suggested_feature'] ?: '-' }}</dd>
                        <dt>Önerilen karar</dt>
                        <dd>{{ $row['suggested_decision'] ?: '-' }}</dd>
                        <dt>Güven skoru</dt>
                        <dd>{{ $row['confidence_score'] ?? 0 }}</dd>
                    </dl>
                    <p class="pd-reason">{{ $row['reason'] ?: '-' }}</p>
                </div>

                <div class="pd-review-block pd-review-decision">
                    <div class="pd-block-title">Sağ — Kullanıcı Kararı</div>
                    <div class="pd-current-decision">
                        <span class="pd-badge {{ $decisionStatus ? 'pd-badge-green' : 'pd-badge-amber' }}">{{ $statusLabels[$decisionStatus] ?? $decisionStatus }}</span>
                        @if($row['decided_at'])
                            <span>{{ $row['decided_by'] }} · {{ $row['decided_at'] }}</span>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('admin.super.product-data-hub.category-review-batches.decisions.store', $batch) }}" class="pd-review-form">
                        @csrf
                        <input type="hidden" name="supplier_category_mapping_id" value="{{ $mappingId }}">
                        <input type="hidden" name="supplier" value="{{ $row['supplier'] }}">
                        <input type="hidden" name="supplier_category_code" value="{{ $row['supplier_category_code'] }}">
                        <input type="hidden" name="supplier_category_name" value="{{ $row['supplier_category_name'] }}">
                        <input type="hidden" name="supplier_category_path" value="{{ $row['supplier_category_path'] }}">
                        <input type="hidden" name="suggested_target_category" value="{{ $row['suggested_target_category'] }}">
                        <input type="hidden" name="suggested_decision" value="{{ $row['suggested_decision'] }}">
                        <input type="hidden" name="suggested_feature" value="{{ $row['suggested_feature'] }}">

                        <label>
                            <span>Hedef kategori hızlı arama</span>
                            <input type="text" class="pd-input pd-category-search" data-search-url="{{ route('admin.super.product-data-hub.categories.search') }}" value="{{ $row['final_target_category'] ?: $row['suggested_target_category'] }}" placeholder="kalem, kupa, takvim, mouse...">
                            <input type="hidden" name="final_target_category_id" class="pd-category-id" value="{{ $row['final_target_category_id'] }}">
                            <div class="pd-category-results" hidden></div>
                        </label>

                        <label>
                            <span>Karar tipi</span>
                            <select name="final_decision" class="pd-input">
                                <option value="map" @selected(($row['final_decision'] ?: $suggestedDecision) === 'map')>Eşle</option>
                                <option value="alias" @selected(($row['final_decision'] ?: $suggestedDecision) === 'alias')>Alias Yap</option>
                                <option value="feature_attribute" @selected(($row['final_decision'] ?: $suggestedDecision) === 'feature_attribute')>Özellik / Filtre Yap</option>
                                <option value="separate_keep" @selected(($row['final_decision'] ?: $suggestedDecision) === 'separate_keep')>Ayrı Bırak</option>
                                <option value="reject" @selected(($row['final_decision'] ?: $suggestedDecision) === 'reject')>Reddet</option>
                                <option value="hold" @selected(($row['final_decision'] ?: $suggestedDecision) === 'hold')>Beklet</option>
                            </select>
                        </label>

                        <label>
                            <span>Özellik / filtre alanı</span>
                            <input type="text" name="final_feature" value="{{ $row['final_feature'] }}" class="pd-input" placeholder="set_icerigi: Kalem">
                        </label>

                        <label>
                            <span>Kullanıcı kararı</span>
                            <select name="user_decision_status" class="pd-input">
                                <option value="approved" @selected($decisionStatus === 'approved')>Onayla</option>
                                <option value="changed" @selected($decisionStatus === 'changed')>Değiştir</option>
                                <option value="held" @selected($decisionStatus === 'held' || $decisionStatus === '')>Beklet</option>
                                <option value="rejected" @selected($decisionStatus === 'rejected')>Reddet</option>
                                <option value="separate_keep" @selected($decisionStatus === 'separate_keep')>Ayrı Bırak</option>
                            </select>
                        </label>

                        <label>
                            <span>Kullanıcı notu</span>
                            <textarea name="user_note" class="pd-input" rows="2" placeholder="Karar notu">{{ $row['user_note'] }}</textarea>
                        </label>

                        <button type="submit" class="pd-btn pd-btn-primary" @disabled(!$mappingId)>Kararı Kaydet</button>
                    </form>
                </div>
            </article>
        @empty
            <div class="pd-empty-state">Bu filtreyle kayıt bulunamadı.</div>
        @endforelse
    </section>
</div>

<style>
    .pd-filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;align-items:end}
    .pd-filter-grid label,.pd-review-form label{display:grid;gap:6px;font-size:12px;color:#64748b;font-weight:700}
    .pd-filter-actions{display:flex;gap:8px;align-items:center}
    .pd-chip-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
    .pd-review-rule-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;color:#334155}
    .pd-review-list{display:grid;gap:18px}
    .pd-review-card{display:grid;grid-template-columns:32% 28% 40%;gap:14px;background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:18px;box-shadow:0 14px 36px rgba(15,23,42,.07)}
    .pd-review-block{border:1px solid #edf2f7;border-radius:18px;padding:14px;background:#f8fafc}
    .pd-review-decision{background:#fff}
    .pd-block-title{font-size:12px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px}
    .pd-priority{font-weight:900;color:#0f766e;margin-bottom:6px}
    .pd-muted{color:#64748b}
    .pd-meta-stack{display:grid;gap:6px;color:#475569;font-size:13px}
    .pd-sample-products{margin-top:12px;padding:10px;border-radius:12px;background:#fff;color:#334155}
    .pd-badge-row,.pd-current-decision{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}
    .pd-decision-dl{display:grid;grid-template-columns:120px 1fr;gap:7px;font-size:13px}
    .pd-decision-dl dt{color:#64748b;font-weight:800}
    .pd-decision-dl dd{margin:0;color:#0f172a}
    .pd-reason{color:#334155;line-height:1.5}
    .pd-review-form{display:grid;gap:10px}
    .pd-category-results{border:1px solid #cbd5e1;border-radius:12px;background:#fff;max-height:180px;overflow:auto}
    .pd-category-result{display:block;width:100%;padding:8px 10px;text-align:left;border:0;background:#fff;color:#0f172a;cursor:pointer}
    .pd-category-result:hover{background:#eff6ff}
    @media (max-width:1100px){.pd-review-card{grid-template-columns:1fr 1fr}.pd-review-decision{grid-column:1/-1}}
    @media (max-width:720px){.pd-review-card{grid-template-columns:1fr}}
</style>

<script>
document.addEventListener('input', async (event) => {
    const input = event.target.closest('.pd-category-search');
    if (!input) return;

    const wrapper = input.closest('label');
    const resultBox = wrapper.querySelector('.pd-category-results');
    const hidden = wrapper.querySelector('.pd-category-id');
    const term = input.value.trim();

    hidden.value = '';

    if (term.length < 2) {
        resultBox.hidden = true;
        resultBox.innerHTML = '';
        return;
    }

    const url = input.dataset.searchUrl + '?q=' + encodeURIComponent(term);
    const response = await fetch(url, {headers: {'Accept': 'application/json'}});
    const rows = await response.json();

    resultBox.innerHTML = rows.map((row) => (
        `<button type="button" class="pd-category-result" data-id="${row.id}" data-path="${String(row.path).replace(/"/g, '&quot;')}">${row.path}<br><small>${row.code}</small></button>`
    )).join('');
    resultBox.hidden = rows.length === 0;
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('.pd-category-result');
    if (!button) return;

    const wrapper = button.closest('label');
    wrapper.querySelector('.pd-category-search').value = button.dataset.path;
    wrapper.querySelector('.pd-category-id').value = button.dataset.id;
    wrapper.querySelector('.pd-category-results').hidden = true;
});
</script>
@endsection
