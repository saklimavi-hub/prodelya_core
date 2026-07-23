@extends('layouts.prodelya-admin')

@section('title', 'Grafik İşleri')
@section('page_title', 'Grafik İşleri')
@section('page_subtitle', 'Aynı siparişe ait baskı operasyonlarını tek grup altında izleyin; tamamen tamamlanan işler arşiv görünümünde saklanır.')
@section('hide_side_summary', '1')

@section('content')
@php
    $queueQuery = collect($filters)->except(['queue', 'page'])->filter(fn ($value) => $value !== null && $value !== '')->all();
@endphp

<div class="pd-ui-v1-graphics">
    <div class="pd-ui-v1-graphics__page">
        <section class="pd-ui-v1-graphics__hero">
            <div>
                <p class="pd-ui-v1-graphics__eyebrow">Operasyon listesi</p>
                <h2 class="pd-ui-v1-graphics__hero-title">Grafik İşleri</h2>
                <p class="pd-ui-v1-graphics__hero-copy">Aynı siparişe ait baskı operasyonları tek grup altında gösterilir. Tamamlanan grafik işleri arşiv görünümünde saklanır.</p>
            </div>
            <div class="pd-ui-v1-graphics__hero-actions">
                <a href="{{ route('admin.orders.index') }}" class="pd-ui-v1-graphics__ghost-btn">Siparişlere Git</a>
            </div>
        </section>

        <div class="pd-ui-v1-graphics__layout">
            <div class="pd-ui-v1-graphics__main">
                <section class="pd-ui-v1-graphics__card">
                    <div class="pd-ui-v1-graphics__metric-grid">
                        <article class="pd-ui-v1-graphics__metric">
                            <div class="pd-ui-v1-graphics__metric-label">Görsel Bekleyen</div>
                            <div class="pd-ui-v1-graphics__metric-value">{{ $summary['waiting_visual'] }}</div>
                            <div class="pd-ui-v1-graphics__metric-note">Henüz son görseli olmayan exact operasyonlar</div>
                        </article>
                        <article class="pd-ui-v1-graphics__metric">
                            <div class="pd-ui-v1-graphics__metric-label">Kontrol Bekleyen</div>
                            <div class="pd-ui-v1-graphics__metric-value">{{ $summary['control_waiting'] }}</div>
                            <div class="pd-ui-v1-graphics__metric-note">İç kontrol veya onay adımındaki işler</div>
                        </article>
                        <article class="pd-ui-v1-graphics__metric">
                            <div class="pd-ui-v1-graphics__metric-label">Sipariş Grubu</div>
                            <div class="pd-ui-v1-graphics__metric-value">{{ $summary['order_groups'] }}</div>
                            <div class="pd-ui-v1-graphics__metric-note">Filtre sonrası toplam sipariş kartı</div>
                        </article>
                        <article class="pd-ui-v1-graphics__metric">
                            <div class="pd-ui-v1-graphics__metric-label">Tamamlanan Grup</div>
                            <div class="pd-ui-v1-graphics__metric-value">{{ $summary['completed_groups'] }}</div>
                            <div class="pd-ui-v1-graphics__metric-note">Arşiv görünümüne taşınan siparişler</div>
                        </article>
                    </div>
                </section>

                <section class="pd-ui-v1-graphics__card pd-ui-v1-graphics__card--tabs">
                    <div class="pd-ui-v1-graphics__tabs" role="tablist" aria-label="Grafik görev kuyrukları">
                        @foreach($tabs as $tab)
                            <a href="{{ route('admin.graphics.index', array_merge($queueQuery, ['queue' => $tab['key']])) }}" class="pd-ui-v1-graphics__tab {{ $tab['active'] ? 'is-active' : '' }}">{{ $tab['label'] }} <span>{{ $tab['count'] }}</span></a>
                        @endforeach
                    </div>
                </section>

                <section class="pd-ui-v1-graphics__card">
                    <form method="GET" action="{{ route('admin.graphics.index') }}" class="pd-ui-v1-graphics__filters">
                        <input type="hidden" name="queue" value="{{ $filters['queue'] }}">
                        <div class="pd-ui-v1-graphics__field pd-ui-v1-graphics__field--search">
                            <label for="graphics-q">Arama</label>
                            <input id="graphics-q" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Sipariş no, müşteri, ürün, SKU, 1a / 1b">
                        </div>
                        <div class="pd-ui-v1-graphics__field">
                            <label for="graphics-status">Durum</label>
                            <select id="graphics-status" name="status">
                                <option value="">Hepsi</option>
                                <option value="waiting_visual" @selected($filters['status'] === 'waiting_visual')>Görsel Bekliyor</option>
                                <option value="visual_uploaded" @selected($filters['status'] === 'visual_uploaded')>Kontrol Bekliyor</option>
                                <option value="customer_approval_waiting" @selected($filters['status'] === 'customer_approval_waiting')>Müşteri Onayı Bekliyor</option>
                                <option value="revision_requested" @selected($filters['status'] === 'revision_requested')>Revize İstendi</option>
                                <option value="approved" @selected($filters['status'] === 'approved')>Onaylandı</option>
                                <option value="production_ready" @selected($filters['status'] === 'production_ready')>Üretime Hazır</option>
                            </select>
                        </div>
                        <div class="pd-ui-v1-graphics__field">
                            <label for="graphics-approval">Onay</label>
                            <select id="graphics-approval" name="approval_status">
                                <option value="">Hepsi</option>
                                <option value="waiting" @selected($filters['approval_status'] === 'waiting')>Onay Bekliyor</option>
                                <option value="revision_requested" @selected($filters['approval_status'] === 'revision_requested')>Revize İstendi</option>
                                <option value="approved" @selected($filters['approval_status'] === 'approved')>Onaylandı</option>
                            </select>
                        </div>
                        <div class="pd-ui-v1-graphics__field">
                            <label for="graphics-visibility">Görsel Görünürlüğü</label>
                            <select id="graphics-visibility" name="customer_visible_visual">
                                <option value="">Hepsi</option>
                                <option value="yes" @selected($filters['customer_visible_visual'] === 'yes')>Müşteriye Açık</option>
                                <option value="no" @selected($filters['customer_visible_visual'] === 'no')>Yalnız İç Kullanım / Yok</option>
                            </select>
                        </div>
                        <div class="pd-ui-v1-graphics__field">
                            <label for="graphics-per-page">Sayfa Boyutu</label>
                            <select id="graphics-per-page" name="per_page">
                                <option value="10" @selected((int) $filters['per_page'] === 10)>10 grup</option>
                                <option value="20" @selected((int) $filters['per_page'] === 20)>20 grup</option>
                                <option value="50" @selected((int) $filters['per_page'] === 50)>50 grup</option>
                            </select>
                        </div>
                        <div class="pd-ui-v1-graphics__filter-actions">
                            <button type="submit" class="pd-ui-v1-graphics__primary-btn">Filtrele</button>
                            <a href="{{ route('admin.graphics.index') }}" class="pd-ui-v1-graphics__ghost-btn">Temizle</a>
                        </div>
                    </form>
                </section>

                <section class="pd-ui-v1-graphics__card pd-ui-v1-graphics__list-card">
                    <div class="pd-ui-v1-graphics__list-head">
                        <div>
                            <h3>Grafik Görevleri</h3>
                            <p>Pagination sipariş grubu bazındadır; aynı sipariş farklı sayfalara bölünmez.</p>
                        </div>
                        <div class="pd-ui-v1-graphics__list-count">{{ $sideSummary['listed_groups'] }} grup / {{ $sideSummary['listed_operations'] }} grafik işi</div>
                    </div>

                    @if($groupPaginator->hasPages())
                        <div class="pd-graphic-group-pagination pd-graphic-group-pagination--top">
                            <div class="pd-graphic-group-pagination__summary">
                                Toplam {{ $sideSummary['total_groups'] }} sipariş grubundan {{ $sideSummary['range_start'] }}-{{ $sideSummary['range_end'] }} arası gösteriliyor
                            </div>
                            <div class="pd-graphic-group-pagination__links">
                                {{ $groupPaginator->onEachSide(1)->links('vendor.pagination.graphics-turkish') }}
                            </div>
                        </div>
                    @endif

                    <div class="pd-graphic-order-groups">
                        @forelse($groups as $group)
                            <article class="pd-graphic-order-group {{ $group['color_class'] }} {{ $group['is_completed_group'] ? 'is-completed' : '' }}">
                                <header class="pd-graphic-order-group__header">
                                    <div>
                                        <div class="pd-graphic-order-group__title">{{ $group['order_number'] }}</div>
                                        <div class="pd-graphic-order-group__meta">{{ $group['customer_name'] }}</div>
                                        <div class="pd-graphic-order-group__meta">
                                            @if($group['delivery_date_label'])
                                                Teslim: {{ $group['delivery_date_label'] }}
                                            @else
                                                Teslim tarihi yok
                                            @endif
                                        </div>
                                    </div>
                                    <div class="pd-graphic-order-group__summary">
                                        <div class="pd-graphic-order-group__count">{{ $group['progress_label'] }}</div>
                                        @if($group['is_completed_group'])
                                            <span class="pd-ui-v1-graphics__badge pd-ui-v1-graphics__badge--green">Tamamlandı</span>
                                            @if($group['completion_label'])
                                                <div class="pd-graphic-order-group__progress">{{ $group['completion_label'] }}</div>
                                            @endif
                                        @else
                                            <span class="pd-ui-v1-graphics__badge pd-ui-v1-graphics__badge--blue">Aktif</span>
                                        @endif
                                    </div>
                                </header>

                                <div class="pd-graphic-order-group__operations">
                                    @foreach($group['rows'] as $row)
                                        <div class="pd-graphic-operation-row">
                                            <div class="pd-graphic-operation-row__main">
                                                <div class="pd-graphic-operation-key">{{ $row['sequence_code'] }}</div>
                                                @if($row['image_url'])
                                                    <div class="pd-ui-v1-graphics__thumb"><img class="pd-allow-large" src="{{ $row['image_url'] }}" alt="{{ $row['product_name'] }}"></div>
                                                @endif
                                                <div class="pd-graphic-operation-product">
                                                    <div class="pd-ui-v1-graphics__product">{{ $row['product_name'] }}</div>
                                                    <div class="pd-ui-v1-graphics__meta-line">Exact SKU: {{ $row['product_code'] }}</div>
                                                    <div class="pd-ui-v1-graphics__meta-line">İş Formu: {{ $row['work_form_number'] }}</div>
                                                    <div class="pd-ui-v1-graphics__meta-line">{{ $row['print_type'] }} · {{ $row['print_option'] }} · {{ $row['print_quantity'] }}</div>
                                                </div>
                                            </div>
                                            <div class="pd-graphic-operation-step">
                                                <div class="pd-ui-v1-graphics__visual-title">Görsel</div>
                                                @if($row['last_visual_thumbnail_url'])
                                                    <div class="pd-ui-v1-graphics__thumb pd-ui-v1-graphics__thumb--visual"><img class="pd-allow-large" src="{{ $row['last_visual_thumbnail_url'] }}" alt="{{ $row['last_visual_name'] ?? 'Son görsel' }}"></div>
                                                @endif
                                                <div class="pd-ui-v1-graphics__meta-line">{{ $row['visual_summary_label'] }}</div>
                                                <div class="pd-ui-v1-graphics__meta-line">{{ $row['visibility_label'] }}</div>
                                            </div>
                                            <div class="pd-graphic-operation-status">
                                                <span class="pd-ui-v1-graphics__badge {{ $row['status_badge'] }}">{{ $row['status_label'] }}</span>
                                                @if($row['status_hint'])
                                                    <div class="pd-ui-v1-graphics__status-note">{{ $row['status_hint'] }}</div>
                                                @endif
                                            </div>
                                            <div class="pd-graphic-operation-next">
                                                <div class="pd-ui-v1-graphics__next-label">{{ $row['next_action_label'] }}</div>
                                                <div class="pd-ui-v1-graphics__meta-line">{{ $row['next_action_note'] }}</div>
                                            </div>
                                            <div class="pd-graphic-operation-action">
                                                @if($group['is_completed_group'])
                                                    <span class="pd-ui-v1-graphics__meta-line">Arşiv kaydı</span>
                                                @else
                                                    <a href="{{ $row['primary_action']['url'] }}" class="pd-ui-v1-graphics__primary-btn">{{ $row['primary_action']['label'] }}</a>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                @if($group['is_completed_group'])
                                    <footer class="pd-graphic-order-group__footer">
                                        <a href="{{ $group['group_action']['url'] }}" class="pd-ui-v1-graphics__primary-btn">{{ $group['group_action']['label'] }}</a>
                                    </footer>
                                @endif
                            </article>
                        @empty
                            <div class="pd-ui-v1-graphics__empty pd-graphic-order-groups__empty">Seçili filtrelerle gösterilecek grafik sipariş grubu bulunamadı.</div>
                        @endforelse
                    </div>

                    @if($groupPaginator->hasPages())
                        <div class="pd-graphic-group-pagination pd-graphic-group-pagination--bottom">
                            <div class="pd-graphic-group-pagination__summary">
                                Toplam {{ $sideSummary['total_groups'] }} sipariş grubundan {{ $sideSummary['range_start'] }}-{{ $sideSummary['range_end'] }} arası gösteriliyor
                            </div>
                            <div class="pd-graphic-group-pagination__links">
                                {{ $groupPaginator->onEachSide(1)->links('vendor.pagination.graphics-turkish') }}
                            </div>
                        </div>
                    @endif
                </section>
            </div>

            <aside class="pd-ui-v1-graphics__side">
                <section class="pd-ui-v1-graphics__card pd-ui-v1-graphics__side-card">
                    <h3>Liste Özeti</h3>
                    <dl class="pd-ui-v1-graphics__side-grid">
                        <div>
                            <dt>Listelenen Grup</dt>
                            <dd>{{ $sideSummary['listed_groups'] }}</dd>
                        </div>
                        <div>
                            <dt>Listelenen İş</dt>
                            <dd>{{ $sideSummary['listed_operations'] }}</dd>
                        </div>
                        <div>
                            <dt>Görsel Bekleyen</dt>
                            <dd>{{ $sideSummary['waiting_visual'] }}</dd>
                        </div>
                        <div>
                            <dt>Revize İstenen</dt>
                            <dd>{{ $sideSummary['revision_requested'] }}</dd>
                        </div>
                        <div>
                            <dt>Üretime Hazır</dt>
                            <dd>{{ $sideSummary['production_ready'] }}</dd>
                        </div>
                        <div>
                            <dt>Tamamlanan Grup</dt>
                            <dd>{{ $sideSummary['completed_groups'] }}</dd>
                        </div>
                        <div class="is-full">
                            <dt>Seçili Filtre</dt>
                            <dd>{{ $sideSummary['selected_queue_label'] }}</dd>
                        </div>
                    </dl>
                </section>
            </aside>
        </div>
    </div>
</div>
@endsection
