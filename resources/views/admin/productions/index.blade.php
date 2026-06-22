@extends('layouts.prodelya-admin')

@section('title', 'Üretim Yönetimi')
@section('page_title', 'Üretim Yönetimi')
@section('page_subtitle', 'Baskıya hazır, bekleyen ve devam eden üretim operasyonlarını takip edin.')

@section('content')
@php
    use App\Models\OrderItemPrintGraphic;
    use App\Models\OrderItemPrintProduction;
    use App\Models\OrderItemProcurement;

    $activePool = $filters['pool'] ?? 'ready';
    $poolTabs = [
        'ready' => 'Baskıya Hazır',
        'internal' => 'İç Baskı',
        'outsourced' => 'Dış / Fason',
        'preparation' => 'Hazırlık Bekleyen',
        'partial' => 'Kısmi Basıldı',
        'completed' => 'Tamamlanan',
    ];
    if ($qcUiEnabled ?? false) {
        $poolTabs['qc'] = 'QC Bekleyen';
    }
    $graphicStatuses = [
        OrderItemPrintGraphic::STATUS_PRODUCTION_READY => 'Üretime Hazır',
        OrderItemPrintGraphic::STATUS_WAITING_VISUAL => 'Grafik Bekliyor',
        OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => 'Revize Var',
        OrderItemPrintGraphic::STATUS_APPROVED => 'Onaylı',
        OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED => 'Görsel Yüklendi',
        OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => 'Grafik Onayı Bekliyor',
    ];
    $procurementStatuses = [
        OrderItemProcurement::STATUS_FULLY_RECEIVED => 'Ürün Geldi',
        OrderItemProcurement::STATUS_PENDING => 'Tedarik Bekliyor',
        OrderItemProcurement::STATUS_PARTIALLY_RECEIVED => 'Kısmi Geldi',
        OrderItemProcurement::STATUS_CUSTOMER_WAITING => 'Müşteri Ürünü Bekleniyor',
        OrderItemProcurement::STATUS_NOT_REQUIRED => 'Tedarik Gerekmez',
        OrderItemProcurement::STATUS_CUSTOMER_RECEIVED => 'Müşteri Ürünü Geldi',
    ];
    $printTypes = $rows
        ->map(fn ($row) => data_get($row->production_snapshot, 'print_type'))
        ->filter()
        ->unique()
        ->values();

    $statusTones = [
        OrderItemPrintProduction::STATUS_PENDING => 'amber',
        OrderItemPrintProduction::STATUS_INTERNAL => 'blue',
        OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR => 'blue',
        OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR => 'amber',
        OrderItemPrintProduction::STATUS_QUALITY_CONTROL => 'amber',
        OrderItemPrintProduction::STATUS_COMPLETED => 'green',
        OrderItemPrintProduction::STATUS_PROBLEMATIC => 'red',
        OrderItemPrintProduction::STATUS_CANCELLED => 'gray',
    ];
@endphp

<style>
    .pp-page { display: grid; gap: 16px; }
    .pp-hero, .pp-card { background: #fff; border: 1px solid #d8dde5; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06); }
    .pp-hero { padding: 18px; }
    .pp-hero-top, .pp-section-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap; }
    .pp-subtitle { margin-top: 6px; color: #697586; font-size: 14px; }
    .pp-summary { margin-top: 16px; display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; }
    .pp-metric { border: 1px solid #e8ecf1; border-radius: 6px; background: #fbfcfe; padding: 12px; }
    .pp-metric-label { color: #697586; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .pp-metric-value { margin-top: 7px; font-size: 24px; font-weight: 700; color: #172033; }
    .pp-body { padding: 16px; }
    .pp-title { margin: 0; font-size: 18px; font-weight: 700; color: #172033; }
    .pp-note { color: #697586; font-size: 13px; }
    .pp-filters { display: grid; grid-template-columns: 1.4fr repeat(4, minmax(0, 1fr)) auto; gap: 10px; margin-bottom: 12px; }
    .pp-field label { display: block; margin-bottom: 5px; color: #697586; font-size: 12px; font-weight: 700; }
    .pp-field input, .pp-field select { width: 100%; border: 1px solid #d8dde5; border-radius: 5px; background: #fff; color: #172033; font: inherit; font-size: 13px; padding: 9px 10px; }
    .pp-actions, .pp-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
    .pp-actions-compact { display: flex; gap: 6px; flex-wrap: wrap; }
    .pp-tab, .pp-link { display: inline-flex; align-items: center; justify-content: center; min-height: 36px; padding: 0 12px; border: 1px solid #d8dde5; border-radius: 5px; background: #f8fafc; color: #172033; font-size: 13px; font-weight: 700; text-decoration: none; }
    .pp-tab.active { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pp-link.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pp-link.light { background: #fff; }
    .pp-table-wrap { overflow: auto; }
    .pp-table { width: 100%; border-collapse: collapse; min-width: 1320px; }
    .pp-table th, .pp-table td { padding: 12px 10px; border-bottom: 1px solid #e8ecf1; text-align: left; vertical-align: top; }
    .pp-table th { background: #fbfcfe; color: #697586; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .pp-table tr:last-child td { border-bottom: 0; }
    .pp-row-team td:first-child { border-left: 4px solid #d6e4ff; }
    .pp-row-team-start td { background: linear-gradient(180deg, #fbfcff 0%, #ffffff 100%); }
    .pp-code { font-weight: 700; color: #172033; }
    .pp-sub { margin-top: 4px; color: #697586; font-size: 12px; line-height: 1.4; }
    .pp-quiet { color: #98a2b3; }
    .pp-product { display: grid; grid-template-columns: 56px minmax(0, 1fr); gap: 10px; min-width: 250px; }
    .pp-thumb, .pp-thumb-empty { width: 56px; height: 56px; border: 1px solid #d8dde5; border-radius: 6px; overflow: hidden; background: linear-gradient(135deg, #f7f8fb, #eef2f7); }
    .pp-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .pp-thumb-empty { display: flex; align-items: center; justify-content: center; text-align: center; color: #697586; font-size: 10px; padding: 4px; }
    .pp-badge { display: inline-flex; align-items: center; padding: 4px 8px; border: 1px solid transparent; border-radius: 4px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .pp-badge-blue { background: #e8f0ff; color: #2563eb; border-color: #cfe0ff; }
    .pp-badge-green { background: #eaf8ef; color: #15803d; border-color: #cfe9d4; }
    .pp-badge-amber { background: #fff4df; color: #b45309; border-color: #f5deb1; }
    .pp-badge-red { background: #fdecec; color: #b42318; border-color: #f7c7c4; }
    .pp-badge-gray { background: #f2f4f7; color: #475467; border-color: #e4e7ec; }
    .pp-status-card { border: 1px solid #e8ecf1; border-radius: 6px; padding: 8px 10px; }
    .pp-status-card.ready { background: #effaf2; border-color: #cfe9d4; }
    .pp-status-card.warn { background: #fff8eb; border-color: #f5deb1; }
    .pp-status-card.blocked { background: #fff3f2; border-color: #f7c7c4; }
    .pp-stack { display: grid; gap: 5px; }
    .pp-compact { display: grid; gap: 6px; }
    @media (max-width: 1400px) { .pp-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); } .pp-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 760px) { .pp-summary, .pp-filters { grid-template-columns: 1fr; } }
</style>

<div class="pp-page">
    <section class="pp-hero">
        <div class="pp-hero-top">
            <div>
                <h2 class="pp-title">Üretim / Baskı İşleri</h2>
                <div class="pp-subtitle">Baskıya hazır, bekleyen ve devam eden üretim operasyonlarını takip edin.</div>
            </div>
        </div>

        <div class="pp-summary">
            @foreach($summaryCards as $card)
                <div class="pp-metric">
                    <div class="pp-metric-label">{{ $card['label'] }}</div>
                    <div class="pp-metric-value">{{ $card['value'] }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="pp-card">
        <div class="pp-body">
            <div class="pp-section-head">
                <div>
                    <h3 class="pp-title">İş Havuzu</h3>
                    <div class="pp-note">Her satır tek bir per-print üretim operasyonudur. Operasyon için gerekli olmayan ticari ve teknik detaylar bu ekranda yer almaz.</div>
                </div>
            </div>

            <div class="pp-tabs" style="margin: 12px 0;">
                @foreach($poolTabs as $poolKey => $poolLabel)
                    <a
                        href="{{ route('admin.productions.index', array_merge($filters, ['pool' => $poolKey])) }}"
                        class="pp-tab {{ $activePool === $poolKey ? 'active' : '' }}"
                    >{{ $poolLabel }}</a>
                @endforeach
            </div>

            <form method="GET" action="{{ route('admin.productions.index') }}" class="pp-filters">
                <input type="hidden" name="pool" value="{{ $activePool }}">
                <div class="pp-field">
                    <label>Arama</label>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Sipariş no, iş formu, müşteri, ürün">
                </div>
                <div class="pp-field">
                    <label>Baskı Türü</label>
                    <select name="print_type">
                        <option value="">Hepsi</option>
                        @foreach($printTypes as $printType)
                            <option value="{{ $printType }}" @selected(($filters['print_type'] ?? '') === $printType)>{{ $printType }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pp-field">
                    <label>Üretim Tipi</label>
                    <select name="type">
                        <option value="">Hepsi</option>
                        @foreach($typeLabels as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['type'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pp-field">
                    <label>Tedarik Durumu</label>
                    <select name="procurement_status">
                        <option value="">Hepsi</option>
                        @foreach($procurementStatuses as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['procurement_status'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pp-field">
                    <label>Grafik Durumu</label>
                    <select name="graphic_status">
                        <option value="">Hepsi</option>
                        @foreach($graphicStatuses as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['graphic_status'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pp-field">
                    <label>Limit</label>
                    <div class="pp-actions">
                        <select name="limit">
                            @foreach([25, 50, 100, 250] as $limitOption)
                                <option value="{{ $limitOption }}" @selected((int) ($filters['limit'] ?? 50) === $limitOption)>{{ $limitOption }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="pp-link primary">Filtrele</button>
                        <a href="{{ route('admin.productions.index') }}" class="pp-link light">Temizle</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="pp-table-wrap">
            <table class="pp-table">
                <thead>
                <tr>
                    <th>Sipariş / İş Formu</th>
                    <th>Müşteri</th>
                    <th>Ürün</th>
                    <th>Baskı</th>
                    <th>Grafik</th>
                    <th>Tedarik / Ürün</th>
                    <th>Ara Eleman</th>
                    <th>Üretim</th>
                    <th>Tamamlanan</th>
                    <th>İşlem</th>
                </tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    @php
                        $snapshot = is_array($row->production_snapshot) ? $row->production_snapshot : [];
                        $customerName = $row->order?->customer?->legal_name ?: '-';
                        $previousOrderNumber = data_get($rows->get($loop->index - 1), 'production_snapshot.order_number');
                        $currentOrderNumber = $snapshot['order_number'] ?? ($row->order?->document_number ?: null);
                        $rowTeamClasses = trim(implode(' ', array_filter([
                            'pp-row-team',
                            $loop->first || $previousOrderNumber !== $currentOrderNumber ? 'pp-row-team-start' : '',
                        ])));
                        $readinessWarnings = collect($snapshot['readiness_warnings'] ?? [])
                            ->filter(fn ($warning) => filled($warning))
                            ->values();
                        $graphicTone = $snapshot['graphic_status_tone'] ?? (($snapshot['graphic_ready'] ?? false) ? 'green' : 'amber');
                        $procurementTone = $snapshot['procurement_status_tone'] ?? match ($snapshot['procurement_status'] ?? null) {
                            OrderItemProcurement::STATUS_FULLY_RECEIVED,
                            OrderItemProcurement::STATUS_NOT_REQUIRED,
                            OrderItemProcurement::STATUS_CUSTOMER_RECEIVED => 'green',
                            OrderItemProcurement::STATUS_PARTIALLY_RECEIVED => 'amber',
                            default => 'red',
                        };
                        $preparationLabel = $snapshot['preparation_label'] ?? null;
                        $productionLabel = match ($row->production_status) {
                            OrderItemPrintProduction::STATUS_PENDING => 'Bekliyor',
                            OrderItemPrintProduction::STATUS_INTERNAL,
                            OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR => 'Başladı',
                            OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR => 'Bekliyor',
                            OrderItemPrintProduction::STATUS_QUALITY_CONTROL => 'Bekliyor',
                            OrderItemPrintProduction::STATUS_COMPLETED => 'Tamamlandı',
                            OrderItemPrintProduction::STATUS_PROBLEMATIC => 'Sorunlu',
                            default => $statusLabels[$row->production_status] ?? $row->production_status,
                        };
                        $startStatusLabel = $snapshot['start_status_label'] ?? (($snapshot['ui_can_start'] ?? false) ? 'Başlanabilir' : 'Kontrol gerekli');
                        $startStatusTone = $snapshot['start_status_tone'] ?? (($snapshot['ui_can_start'] ?? false) ? 'green' : 'amber');
                        $startStatusCard = match ($startStatusTone) {
                            'green' => 'ready',
                            'red' => 'blocked',
                            default => 'warn',
                        };
                        $rowPreferredCompanyId = $row->production_company_id ?: ($row->orderItemPrint?->subcontractor_company_id ?: null);
                        $rowPreferredCompanyName = $row->productionCompany?->short_name
                            ?: $row->productionCompany?->legal_name
                            ?: $row->orderItemPrint?->subcontractorCompany?->short_name
                            ?: $row->orderItemPrint?->subcontractorCompany?->legal_name;
                        $requiresCompanySelection = in_array($row->production_type, [OrderItemPrintProduction::TYPE_EXTERNAL, OrderItemPrintProduction::TYPE_OUTSOURCED], true)
                            && ! $rowPreferredCompanyId;
                    @endphp
                    <tr class="{{ $rowTeamClasses }}" data-order-team="{{ $currentOrderNumber }}">
                        <td>
                    <div class="pp-stack">
                        <div class="pp-code">{{ $currentOrderNumber ?: '-' }}</div>
                        <div class="pp-sub">{{ $snapshot['work_form_number'] ?? ($row->workForm?->work_form_number ?: '-') }}</div>
                            </div>
                        </td>
                        <td>
                            <div class="pp-code">{{ $customerName }}</div>
                        </td>
                        <td>
                            <div class="pp-product">
                                @if(filled($snapshot['product_image_url'] ?? null))
                                    <div class="pp-thumb"><img src="{{ $snapshot['product_image_url'] }}" alt="{{ $snapshot['product_name'] ?? '-' }}"></div>
                                @else
                                    <div class="pp-thumb-empty">Ürün</div>
                                @endif
                                <div>
                                    <div class="pp-code">{{ $snapshot['product_name'] ?? ($row->orderItem?->product_name ?: '-') }}</div>
                                    <div class="pp-sub">{{ $snapshot['product_code'] ?? ($row->orderItem?->product_code ?: '-') }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="pp-stack">
                                <div class="pp-code">{{ $snapshot['print_sequence'] ?? '-' }} {{ $snapshot['print_type'] ?? '-' }}</div>
                                <div class="pp-sub">{{ $snapshot['print_option'] ?? '-' }}</div>
                                <div class="pp-sub">{{ rtrim(rtrim(number_format((float) data_get($snapshot, 'print_quantity', 0), 4, ',', '.'), '0'), ',') }} {{ $snapshot['unit'] ?? ($row->orderItem?->unit ?: '') }}</div>
                            </div>
                        </td>
                        <td>
                            <div class="pp-compact">
                                <span class="pp-badge pp-badge-{{ $graphicTone }}">{{ $snapshot['graphic_status_label'] ?? '-' }}</span>
                                @if(($snapshot['final_graphic']['file_name'] ?? null))
                                    <div class="pp-sub">{{ $snapshot['final_graphic']['file_name'] }}</div>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="pp-compact">
                                <span class="pp-badge pp-badge-{{ $procurementTone }}">{{ $snapshot['procurement_status_label'] ?? '-' }}</span>
                                <div class="pp-sub">Gelen adet: {{ rtrim(rtrim(number_format((float) data_get($snapshot, 'product_control.received_quantity', 0), 4, ',', '.'), '0'), ',') }}</div>
                            </div>
                        </td>
                        <td>
                            @if($preparationLabel)
                                <span class="pp-badge pp-badge-{{ ($snapshot['preparation_ready'] ?? false) ? 'green' : 'red' }}">{{ $preparationLabel }}</span>
                            @else
                                <span class="pp-sub pp-quiet">-</span>
                            @endif
                        </td>
                        <td>
                            <div class="pp-compact">
                                <div class="pp-status-card {{ $startStatusCard }}">
                                    <span class="pp-badge pp-badge-{{ $startStatusTone }}">{{ $startStatusLabel }}</span>
                                </div>
                                <span class="pp-badge pp-badge-{{ $statusTones[$row->production_status] ?? 'gray' }}">{{ $productionLabel }}</span>
                                @if(($snapshot['ui_can_start'] ?? false) && $row->production_status === OrderItemPrintProduction::STATUS_PENDING)
                                    <div class="pp-sub">Baskıya başlanabilir</div>
                                @elseif(($snapshot['start_blockers'] ?? []) !== [])
                                    <div class="pp-sub">{{ collect($snapshot['start_blockers'])->take(2)->implode(' · ') }}</div>
                                @endif
                                @if(in_array($row->production_type, [OrderItemPrintProduction::TYPE_EXTERNAL, OrderItemPrintProduction::TYPE_OUTSOURCED], true) || $rowPreferredCompanyId)
                                    <div class="pp-sub">{{ $rowPreferredCompanyName ? 'Fason firma: ' . $rowPreferredCompanyName : 'Dış üretim için fason firma seçilmedi.' }}</div>
                                @endif
                                @if(! ($snapshot['graphic_ready'] ?? false))
                                    <div class="pp-sub">Grafik henüz üretime hazır değil.</div>
                                @endif
                                @if(! ($snapshot['procurement_ready'] ?? false))
                                    <div class="pp-sub">Tedarik süreci tamamlanmadı.</div>
                                @endif
                                @if($readinessWarnings->isNotEmpty())
                                    <div class="pp-sub">{{ $readinessWarnings->take(1)->implode(' · ') }}</div>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="pp-code">{{ rtrim(rtrim(number_format((float) $row->completed_quantity, 4, ',', '.'), '0'), ',') }} / {{ rtrim(rtrim(number_format((float) $row->planned_quantity, 4, ',', '.'), '0'), ',') }}</div>
                            <div class="pp-sub">Kalan: {{ rtrim(rtrim(number_format((float) $row->remaining_quantity, 4, ',', '.'), '0'), ',') }}</div>
                        </td>
                        <td>
                            <div class="pp-actions-compact">
                                <a href="{{ route('admin.productions.show', $row) }}" class="pp-link primary">Üretimi Aç</a>
                                @if(($snapshot['ui_can_start'] ?? false) && $row->production_status === OrderItemPrintProduction::STATUS_PENDING)
                                    @if($requiresCompanySelection)
                                        <a href="{{ route('admin.productions.show', $row) }}#assignment-form" class="pp-link light">Fason Firma Seç</a>
                                    @else
                                        <form method="POST" action="{{ route('admin.productions.update-status', $row) }}" style="margin:0;">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="action" value="{{ in_array($row->production_type, [OrderItemPrintProduction::TYPE_EXTERNAL, OrderItemPrintProduction::TYPE_OUTSOURCED], true) || $rowPreferredCompanyId ? 'assign_external' : 'assign_internal' }}">
                                            @if(in_array($row->production_type, [OrderItemPrintProduction::TYPE_EXTERNAL, OrderItemPrintProduction::TYPE_OUTSOURCED], true) || $rowPreferredCompanyId)
                                                <input type="hidden" name="production_company_id" value="{{ $rowPreferredCompanyId }}">
                                            @else
                                                <input type="hidden" name="production_unit_name" value="{{ $row->production_unit_name ?: 'İç üretim hattı' }}">
                                            @endif
                                            <button type="submit" class="pp-link light">Başla</button>
                                        </form>
                                    @endif
                                @endif
                                @if(($snapshot['ui_can_start'] ?? false) && (float) $row->remaining_quantity > 0.0001)
                                    <a href="{{ route('admin.productions.show', $row) }}#production-actions" class="pp-link light">Kısmi Basıldı</a>
                                    <a href="{{ route('admin.productions.show', $row) }}#production-actions" class="pp-link light">Tamamı Basıldı</a>
                                @endif
                                @if($row->workForm)
                                    <a href="{{ route('admin.work-forms.show', $row->workForm) }}" class="pp-link light">İş Formu</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="pp-note">Filtreye uygun per-print üretim kaydı bulunamadı.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">İş Havuzu Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Listelenen kayıt</span><strong>{{ $rows->count() }}</strong></div>
            <div class="pd-status-row"><span>Aktif sekme</span><strong>{{ $poolTabs[$activePool] ?? 'Baskıya Hazır' }}</strong></div>
            <div class="pd-status-row"><span>Üretim tipi</span><strong>{{ ($filters['type'] ?? '') !== '' ? ($typeLabels[$filters['type']] ?? $filters['type']) : 'Hepsi' }}</strong></div>
            <div class="pd-status-row"><span>Grafik filtresi</span><strong>{{ ($filters['graphic_status'] ?? '') !== '' ? ($graphicStatuses[$filters['graphic_status']] ?? $filters['graphic_status']) : 'Hepsi' }}</strong></div>
        </div>
        <div class="pd-side-note">
            Index ekranı yalnız iş havuzudur. Operasyon aksiyonları ve büyük hedef görsel karşılaştırması detay ekranında yapılır.
        </div>
    </div>
</div>
@endsection
