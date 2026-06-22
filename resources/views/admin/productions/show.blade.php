@extends('layouts.prodelya-admin')

@section('title', 'Üretim Detayı')
@section('page_title', 'Üretim Detayı')
@section('page_subtitle', 'Per-print üretim operasyonunu büyük, hızlı ve net görünümle yönetin.')

@section('content')
@php
    use App\Models\OrderItemPrintGraphic;
    use App\Models\OrderItemPrintProduction;
    use App\Models\OrderItemProcurement;
    use App\Models\OrderItemPrintSetupRequirement;
    use Illuminate\Support\Facades\Storage;

    $snapshot = is_array($production->production_snapshot) ? $production->production_snapshot : [];
    $print = $production->orderItemPrint;
    $workForm = $production->workForm;
    $procurement = $workForm?->procurement;
    $delivery = $workForm?->delivery;
    $productSnapshot = is_array($workForm?->product_snapshot) ? $workForm->product_snapshot : [];
    $systemWorkFolder = $workForm?->systemWorkFolder;
    $productionPhotos = $workForm?->productionPhotos()->latest('id')->get() ?? collect();
    $lastPhoto = $productionPhotos->first();
    $siblingOperations = $workForm?->orderItem?->prints
        ?->map(function ($siblingPrint) use ($production) {
            $siblingProduction = $siblingPrint->production;
            if (!$siblingProduction || $siblingProduction->id === $production->id) {
                return null;
            }

            return [
                'id' => $siblingProduction->id,
                'label' => trim(($siblingPrint->sequence_code ?: '') . ' ' . ($siblingPrint->print_type ?: '')),
            ];
        })
        ->filter()
        ->values() ?? collect();
    $finalGraphic = $snapshot['final_graphic'] ?? null;
    $productControl = $snapshot['product_control'] ?? [];
    $statusBanner = $snapshot['status_banner'] ?? 'Üretim Detayı';
    $statusHelp = $snapshot['status_help'] ?? '';
    $uiCanStart = (bool) ($snapshot['ui_can_start'] ?? false);
    $startBlockers = (array) ($snapshot['start_blockers'] ?? []);
    $preparationRequired = (bool) ($snapshot['preparation_required'] ?? false);
    $preparationLabel = $snapshot['preparation_label'] ?? null;
    $setupRequirements = $print?->setupRequirements
        ?->reject(fn ($requirement) => $requirement->status === OrderItemPrintSetupRequirement::STATUS_NOT_REQUIRED)
        ->values() ?? collect();
    $setupSummaryLabel = $snapshot['setup_summary_label'] ?? null;
    $hasPrintLocation = filled($snapshot['print_location'] ?? ($print?->print_location ?? null));
    $hasPrintSize = filled($snapshot['print_size'] ?? ($print?->print_size ?? null));
    $statusTone = match ($statusBanner) {
        'Baskıya Başlanabilir', 'Baskı Başladı' => 'green',
        'Tamamlandı' => 'green',
        'Kısmi Basıldı' => 'amber',
        'Revize Bekliyor', 'Final Görsel Yok', 'Klişe Bekliyor' => 'red',
        'QC Bekliyor', 'Tedarik Bekliyor', 'Grafik Bekliyor' => 'amber',
        'Sorunlu' => 'red',
        default => 'gray',
    };
    $preferredCompanyId = $production->production_company_id ?: ($print?->subcontractor_company_id ?: null);
    $preferredCompanyName = $production->productionCompany?->short_name
        ?: $production->productionCompany?->legal_name
        ?: $print?->subcontractorCompany?->short_name
        ?: $print?->subcontractorCompany?->legal_name;
    $preferredUnitName = $production->production_unit_name ?: 'İç üretim hattı';
    $isOutsourcedType = in_array($production->production_type, [
        OrderItemPrintProduction::TYPE_EXTERNAL,
        OrderItemPrintProduction::TYPE_OUTSOURCED,
    ], true);
    $startAction = in_array($production->production_type, [
        OrderItemPrintProduction::TYPE_EXTERNAL,
        OrderItemPrintProduction::TYPE_OUTSOURCED,
    ], true) || $preferredCompanyId
        ? 'assign_external'
        : 'assign_internal';
    $primaryActionUrl = null;
    $primaryActionLabel = null;
    if (($snapshot['graphic_status'] ?? null) === 'revision_requested' || !($snapshot['graphic_ready'] ?? false)) {
        $primaryActionUrl = $workForm ? route('admin.graphics.show', $workForm) : null;
        $primaryActionLabel = 'Grafiğe Git';
    } elseif (!($snapshot['procurement_ready'] ?? false)) {
        $primaryActionUrl = $procurement ? route('admin.procurements.show', $procurement) : null;
        $primaryActionLabel = 'Tedariğe Git';
    } elseif (!($snapshot['setup_ready'] ?? true)) {
        $primaryActionUrl = '#setup-requirements';
        $primaryActionLabel = 'Hazırlığı Kontrol Et';
    } elseif ($preparationRequired && !($snapshot['preparation_ready'] ?? false)) {
        $primaryActionUrl = $workForm ? route('admin.work-forms.show', $workForm) : null;
        $primaryActionLabel = 'Hazırlığı Kontrol Et';
    }
    $startReason = $startBlockers !== [] ? match ($startBlockers[0] ?? null) {
        'Grafik bekleniyor' => 'Baskıya başlamak için grafik hazır olmalı.',
        'Tedarik bekleniyor' => 'Baskıya başlamak için tedarik tamamlanmalı.',
        'Hazırlık bekleniyor: Klişe' => 'Baskıya başlamak için klişe hazır olmalı.',
        'Klişe bekleniyor' => 'Baskıya başlamak için klişe hazır olmalı.',
        'Revize bekliyor' => 'Baskıya başlamak için revize tamamlanmalı.',
        'Final görsel yok' => 'Baskıya başlamak için final görsel gerekli.',
        default => $statusHelp,
    } : $statusHelp;
    $remainingQuantity = round((float) $production->remaining_quantity, 4);
    $completedQuantity = round((float) $production->completed_quantity, 4);
    $hasRemainingQuantity = $remainingQuantity > 0.0001;
    $outsourcedCompanyMissing = $isOutsourcedType && !$preferredCompanyId;
    $actionReadinessReason = $outsourcedCompanyMissing
        ? 'Dış üretim / fason için önce fason firma seçilmelidir.'
        : $startReason;
    $canAdvanceProduction = $uiCanStart && $hasRemainingQuantity && !$outsourcedCompanyMissing;
    $progressReason = $hasRemainingQuantity ? $actionReadinessReason : 'Bu baskı için planlanan adet tamamlandı.';
    $partialDefaultQuantity = $hasRemainingQuantity ? max(min($remainingQuantity, 1), 1) : 0;
    $subcontractorCostVisible = (bool) ($canViewFinancialData ?? false);
    $isOutsourcedFlow = in_array($production->production_type, [
        OrderItemPrintProduction::TYPE_EXTERNAL,
        OrderItemPrintProduction::TYPE_OUTSOURCED,
    ], true) || $preferredCompanyId;
    $selectedProductionType = old('production_type', $production->production_type ?: ($preferredCompanyId ? OrderItemPrintProduction::TYPE_OUTSOURCED : OrderItemPrintProduction::TYPE_INTERNAL));
    $assignmentUsesExternalCompany = in_array($selectedProductionType, [
        OrderItemPrintProduction::TYPE_EXTERNAL,
        OrderItemPrintProduction::TYPE_OUTSOURCED,
    ], true);
    $selectedProductionCompanyId = old('production_company_id', $preferredCompanyId);
@endphp

<style>
    .pf-page { display: grid; gap: 16px; }
    .pf-card { background: #fff; border: 1px solid #d8dde5; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06); overflow: hidden; }
    .pf-body { padding: 16px; }
    .pf-head, .pf-section-head, .pf-status-banner, .pf-file-row { display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap; }
    .pf-detail { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start; }
    .pf-stack, .pf-side-stack, .pf-progress-list { display: grid; gap: 16px; }
    .pf-title { margin: 0; font-size: 28px; font-weight: 700; color: #172033; }
    .pf-note { color: #697586; font-size: 13px; line-height: 1.45; }
    .pf-meta { margin-top: 12px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
    .pf-box, .pf-file-row, .pf-progress-card, .pf-status-strip { border: 1px solid #e8ecf1; border-radius: 6px; background: #fbfcfe; padding: 12px; }
    .pf-label { display: block; margin-bottom: 4px; color: #697586; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .pf-value { color: #172033; font-size: 14px; font-weight: 700; line-height: 1.45; word-break: break-word; }
    .pf-badge { display: inline-flex; align-items: center; padding: 4px 8px; border: 1px solid transparent; border-radius: 4px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .pf-badge-blue { background: #e8f0ff; color: #2563eb; border-color: #cfe0ff; }
    .pf-badge-green { background: #eaf8ef; color: #15803d; border-color: #cfe9d4; }
    .pf-badge-amber { background: #fff4df; color: #b45309; border-color: #f5deb1; }
    .pf-badge-red { background: #fdecec; color: #b42318; border-color: #f7c7c4; }
    .pf-badge-gray { background: #f2f4f7; color: #475467; border-color: #e4e7ec; }
    .pf-links, .pf-actions, .pf-mobile-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .pf-link, .pf-action, .pf-mobile-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 0 12px; border: 1px solid #d8dde5; border-radius: 5px; background: #fff; color: #172033; font-size: 13px; font-weight: 700; text-decoration: none; }
    .pf-link.primary, .pf-action.primary, .pf-mobile-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pf-link.alert { background: #fff8eb; border-color: #f5deb1; color: #b45309; }
    .pf-action.green, .pf-mobile-btn.green { background: #eaf8ef; border-color: #cfe9d4; color: #15803d; }
    .pf-action.amber { background: #fff4df; border-color: #f5deb1; color: #b45309; }
    .pf-action.red, .pf-mobile-btn.red { background: #fff2f2; border-color: #f4c7c7; color: #b42318; }
    .pf-action.disabled { background: #eef2f6; border-color: #dde3ea; color: #98a2b3; }
    .pf-status-copy { display: grid; gap: 6px; }
    .pf-status-title { font-size: 20px; font-weight: 700; color: #172033; }
    .pf-status-help { color: #697586; font-size: 13px; }
    .pf-status-strip.ready { background: #effaf2; border-color: #cfe9d4; }
    .pf-status-strip.warn { background: #fff8eb; border-color: #f5deb1; }
    .pf-status-strip.blocked { background: #fff3f2; border-color: #f7c7c4; }
    .pf-compare { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .pf-visual-panel { border: 1px solid #e8ecf1; border-radius: 6px; background: #fcfcfd; padding: 14px; }
    .pf-visual-box, .pf-photo-thumb, .pf-mobile-visual { border: 1px solid #d8dde5; border-radius: 6px; background: linear-gradient(135deg, #f7f8fb, #eef2f7); display: flex; align-items: center; justify-content: center; text-align: center; color: #697586; overflow: hidden; }
    .pf-visual-box { min-height: 420px; padding: 8px; }
    .pf-visual-box img, .pf-photo-thumb img { width: 100%; height: 100%; object-fit: contain; display: block; }
    .pf-visual-meta { margin-top: 12px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .pf-action-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .pf-action-grid-wide { grid-column: 1 / -1; }
    .pf-big-btn { min-height: 68px; padding: 12px; border: 1px solid #d8dde5; border-radius: 6px; background: #fff; text-align: left; font: inherit; font-size: 15px; font-weight: 700; color: #172033; display: grid; gap: 4px; }
    .pf-big-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pf-big-btn.green { background: #eaf8ef; border-color: #cfe9d4; color: #15803d; }
    .pf-big-btn.amber { background: #fff4df; border-color: #f5deb1; color: #b45309; }
    .pf-big-btn.red { background: #fff2f2; border-color: #f4c7c7; color: #b42318; }
    .pf-big-btn.disabled { background: #eef2f6; border-color: #dde3ea; color: #98a2b3; }
    .pf-big-sub { font-size: 12px; font-weight: 600; }
    .pf-inline-form { display: grid; gap: 8px; }
    .pf-inline-field { width: 100%; border: 1px solid #d8dde5; border-radius: 5px; background: #fff; color: #172033; font: inherit; font-size: 14px; padding: 10px 12px; }
    .pf-progress-bar { height: 12px; border-radius: 999px; background: #edf1f5; overflow: hidden; }
    .pf-progress-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #2563eb, #15803d); }
    .pf-photo-thumb { min-height: 120px; padding: 10px; }
    .pf-tabset { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
    .pf-mini-link { display: inline-flex; align-items: center; min-height: 32px; padding: 0 10px; border: 1px solid #d8dde5; border-radius: 999px; background: #fff; color: #172033; font-size: 12px; font-weight: 700; text-decoration: none; }
    @media (max-width: 1260px) { .pf-detail { grid-template-columns: 1fr; } }
    @media (max-width: 860px) { .pf-meta, .pf-compare, .pf-visual-meta, .pf-action-grid { grid-template-columns: 1fr; } .pf-title { font-size: 24px; } }
</style>

@if(session('success'))
    <div class="pd-alert">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="pd-alert-warning">{{ $errors->first() }}</div>
@endif

<div class="pf-page">
    <div class="pf-links">
        <a href="{{ route('admin.productions.index') }}" class="pf-link">Listeye Dön</a>
        @if($production->order)
            <a href="{{ route('admin.orders.show', $production->order) }}" class="pf-link">Siparişi Aç</a>
        @endif
        @if($workForm)
            <a href="{{ route('admin.work-forms.show', $workForm) }}" class="pf-link">İş Formu</a>
            <a href="{{ route('admin.graphics.show', $workForm) }}" class="pf-link {{ ($snapshot['graphic_ready'] ?? false) ? '' : 'alert' }}">Grafiğe Git</a>
            @if($procurement)
                <a href="{{ route('admin.procurements.show', $procurement) }}" class="pf-link {{ ($snapshot['procurement_ready'] ?? false) ? '' : 'alert' }}">Tedariğe Git</a>
            @endif
            @if($delivery)
                <a href="{{ route('admin.deliveries.show', $delivery) }}" class="pf-link">Teslimat</a>
            @endif
            <a href="{{ route('admin.work-forms.pdf', $workForm) }}" class="pf-link" target="_blank" rel="noopener">İş Klasörü / PDF</a>
        @endif
    </div>

    <section class="pf-card">
        <div class="pf-body">
            <div class="pf-head">
                <div>
                    <h2 class="pf-title">{{ $snapshot['order_number'] ?? ($production->order?->document_number ?: '-') }} / {{ $snapshot['print_sequence'] ?? '-' }} {{ $snapshot['print_type'] ?? '-' }}</h2>
                    <div class="pf-note">{{ $snapshot['product_name'] ?? ($production->orderItem?->product_name ?: '-') }} / {{ $snapshot['product_code'] ?? ($production->orderItem?->product_code ?: '-') }}</div>
                    <div class="pf-note">{{ rtrim(rtrim(number_format((float) data_get($snapshot, 'print_quantity', 0), 4, ',', '.'), '0'), ',') }} {{ $snapshot['unit'] ?? ($production->orderItem?->unit ?: '') }} · {{ $snapshot['production_type_label'] ?? '-' }} · {{ $production->order?->customer?->legal_name ?: '-' }}</div>
                </div>
                <span class="pf-badge pf-badge-{{ $statusTone }}">{{ $statusBanner }}</span>
            </div>

            <div class="pf-meta">
                <div class="pf-box"><span class="pf-label">İş Formu</span><div class="pf-value">{{ $snapshot['work_form_number'] ?? ($workForm?->work_form_number ?: '-') }}</div></div>
                <div class="pf-box"><span class="pf-label">Grafik</span><div class="pf-value">{{ $snapshot['graphic_status_label'] ?? '-' }}</div></div>
                <div class="pf-box"><span class="pf-label">Tedarik / Ürün</span><div class="pf-value">{{ $snapshot['procurement_status_label'] ?? '-' }}</div></div>
                @if($preparationRequired && $preparationLabel)
                    <div class="pf-box"><span class="pf-label">Ara Eleman</span><div class="pf-value">{{ $preparationLabel }}</div></div>
                @endif
            </div>

            <div class="pf-meta" style="margin-top: 12px;">
                <div class="pf-box"><span class="pf-label">Ne Basılacak?</span><div class="pf-value">{{ ($snapshot['print_sequence'] ?? '-') . ' ' . ($snapshot['print_type'] ?? '-') }}</div></div>
                <div class="pf-box"><span class="pf-label">Kalan Adet</span><div class="pf-value">{{ rtrim(rtrim(number_format((float) $production->remaining_quantity, 4, ',', '.'), '0'), ',') }} {{ $snapshot['unit'] ?? ($production->orderItem?->unit ?: '') }}</div></div>
                <div class="pf-box"><span class="pf-label">Başlamaya Engel</span><div class="pf-value">{{ $uiCanStart ? 'Engel yok, baskı başlayabilir.' : ($startBlockers[0] ?? 'Kontrol gerekli') }}</div></div>
            </div>

            @if($siblingOperations->isNotEmpty())
                <div class="pf-tabset">
                    @foreach($siblingOperations as $operation)
                        <a href="{{ route('admin.productions.show', $operation['id']) }}" class="pf-mini-link">{{ $operation['label'] }}</a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <div class="pf-detail">
        <div class="pf-stack">
            <section class="pf-card" id="production-actions">
                <div class="pf-body">
                    @php
                        $statusStripTone = $uiCanStart ? 'ready' : ((($snapshot['graphic_status'] ?? null) === 'revision_requested' || in_array('Final görsel yok', $startBlockers, true)) ? 'blocked' : 'warn');
                    @endphp
                    <div class="pf-status-strip {{ $statusStripTone }}">
                        <div class="pf-section-head">
                            <div class="pf-status-copy">
                                <div class="pf-status-title">{{ $snapshot['start_status_label'] ?? $statusBanner }}</div>
                                <div class="pf-status-help">{{ $startReason }}</div>
                            </div>
                            @if($primaryActionUrl && $primaryActionLabel)
                                <a href="{{ $primaryActionUrl }}" class="pf-link alert">{{ $primaryActionLabel }}</a>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="pf-card">
                <div class="pf-body">
                    <div class="pf-section-head">
                        <div>
                            <h3 class="pf-title" style="font-size: 18px;">Büyük Hedef Grafik + Gelen Ürün Kontrolü</h3>
                        </div>
                    </div>

                    <div class="pf-compare">
                        <div class="pf-visual-panel">
                            <div class="pf-section-head">
                                <div>
                                    <h4 class="pf-title" style="font-size: 16px;">Onaylı Grafik / Hedef Tasarım</h4>
                                    <div class="pf-note">Per-print `latestAttachment` kaynağı</div>
                                </div>
                                @if($finalGraphic)
                                    <span class="pf-badge pf-badge-green">Final</span>
                                @endif
                            </div>
                            <div class="pf-visual-box">
                                @if($finalGraphic && ($finalGraphic['is_image'] ?? false))
                                    <img src="{{ $finalGraphic['preview_url'] }}" alt="{{ $finalGraphic['file_name'] }}">
                                @elseif($finalGraphic)
                                    <div>
                                        <div class="pf-value">{{ $finalGraphic['file_name'] }}</div>
                                        <div class="pf-note" style="margin-top: 8px;">Görsel dosya değil. Güvenli önizleme ile açılır.</div>
                                        <div class="pf-actions" style="margin-top: 10px;">
                                            <a href="{{ $finalGraphic['open_url'] }}" target="_blank" rel="noopener" class="pf-link primary">Dosyayı Aç</a>
                                        </div>
                                    </div>
                                @else
                                    <div>
                                        <div class="pf-value">Final görsel yok</div>
                                        <div class="pf-note" style="margin-top: 8px;">latest_attachment_id olmadan üretim başlatılamaz.</div>
                                    </div>
                                @endif
                            </div>
                            <div class="pf-visual-meta">
                                <div class="pf-box"><span class="pf-label">Final Grafik Dosyası</span><div class="pf-value">{{ $finalGraphic['file_name'] ?? '-' }}</div></div>
                                <div class="pf-box"><span class="pf-label">Grafik Durumu</span><div class="pf-value">{{ $snapshot['graphic_status_label'] ?? '-' }}</div></div>
                                @if($hasPrintLocation)
                                    <div class="pf-box"><span class="pf-label">Baskı Yeri</span><div class="pf-value">{{ $snapshot['print_location'] ?? $print?->print_location }}</div></div>
                                @endif
                                @if($hasPrintSize)
                                    <div class="pf-box"><span class="pf-label">Baskı Ölçüsü</span><div class="pf-value">{{ $snapshot['print_size'] ?? $print?->print_size }}</div></div>
                                @endif
                            </div>
                        </div>

                        <div class="pf-visual-panel">
                            <div class="pf-section-head">
                                <div>
                                    <h4 class="pf-title" style="font-size: 16px;">Gelen Ürün / Ürün Kontrolü</h4>
                                    <div class="pf-note">Ürün kodu, gelen adet ve görsel aynı blokta</div>
                                </div>
                                <span class="pf-badge pf-badge-{{ ($snapshot['procurement_ready'] ?? false) ? 'green' : 'amber' }}">{{ $snapshot['procurement_status_label'] ?? '-' }}</span>
                            </div>
                            <div class="pf-visual-box">
                                @if(filled($productControl['product_image_url'] ?? null))
                                    <img src="{{ $productControl['product_image_url'] }}" alt="{{ $snapshot['product_name'] ?? '-' }}">
                                @else
                                    <div>
                                        <div class="pf-value">Ürün görseli yok</div>
                                        <div class="pf-note" style="margin-top: 8px;">Gelen ürün kontrolü için placeholder gösteriliyor.</div>
                                    </div>
                                @endif
                            </div>
                            <div class="pf-visual-meta">
                                <div class="pf-box"><span class="pf-label">Sipariş Ürün Kodu</span><div class="pf-value">{{ $productControl['order_product_code'] ?? ($snapshot['product_code'] ?? '-') }}</div></div>
                                <div class="pf-box"><span class="pf-label">Gelen Ürün Kodu</span><div class="pf-value">{{ $productControl['incoming_product_code'] ?? ($snapshot['product_code'] ?? '-') }}</div></div>
                                <div class="pf-box"><span class="pf-label">Gelen Adet</span><div class="pf-value">{{ rtrim(rtrim(number_format((float) ($productControl['received_quantity'] ?? 0), 4, ',', '.'), '0'), ',') }}</div></div>
                                <div class="pf-box"><span class="pf-label">Tedarik / Ürün</span><div class="pf-value">{{ $snapshot['procurement_status_label'] ?? '-' }}</div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            @if($setupRequirements->isNotEmpty())
                <section class="pf-card" id="setup-requirements">
                    <div class="pf-body">
                        <div class="pf-section-head">
                            <div>
                                <h3 class="pf-title" style="font-size: 18px;">Hazırlık / Ara Eleman</h3>
                                <div class="pf-note">Bu baskı operasyonu için gereken setup kayıtları burada izlenir.</div>
                            </div>
                            @if(filled($setupSummaryLabel))
                                <span class="pf-badge pf-badge-{{ (bool) ($snapshot['setup_ready'] ?? false) ? 'green' : 'amber' }}">{{ $setupSummaryLabel }}</span>
                            @endif
                        </div>

                        <div class="pf-progress-list" style="margin-top: 12px;">
                            @foreach($setupRequirements as $requirement)
                                <div class="pf-progress-card">
                                    <div class="pf-section-head">
                                        <div>
                                            <div class="pf-value">{{ $requirement->safeSetupTypeLabel() }}</div>
                                            <div class="pf-note">
                                                @if(filled($requirement->assignedCompany?->legal_name))
                                                    {{ $requirement->assignedCompany->legal_name }}
                                                @else
                                                    Atama yapılmadı
                                                @endif
                                            </div>
                                        </div>
                                        <span class="pf-badge pf-badge-{{ $requirement->isReady() ? 'green' : ($requirement->isCancelled() ? 'red' : 'amber') }}">{{ $requirement->safeStatusLabel() }}</span>
                                    </div>

                                    <div class="pf-meta" style="margin-top: 10px;">
                                        <div class="pf-box"><span class="pf-label">Durum</span><div class="pf-value">{{ $requirement->safeStatusLabel() }}</div></div>
                                        <div class="pf-box"><span class="pf-label">Tamamlandı</span><div class="pf-value">{{ optional($requirement->completed_at)->format('d.m.Y H:i') ?: '-' }}</div></div>
                                        <div class="pf-box"><span class="pf-label">Not</span><div class="pf-value">{{ $requirement->note ?: ($requirement->cancellation_reason ?: '-') }}</div></div>
                                    </div>

                                    <div class="pf-actions" style="margin-top: 10px;">
                                        @if($requirement->status !== \App\Models\OrderItemPrintSetupRequirement::STATUS_REQUESTED && !$requirement->isCancelled())
                                            <form method="POST" action="{{ route('admin.print-setup-requirements.requested', $requirement) }}">
                                                @csrf
                                                <button type="submit" class="pf-action">Talep Edildi</button>
                                            </form>
                                        @endif

                                        @if($requirement->canBeCompleted())
                                            <form method="POST" action="{{ route('admin.print-setup-requirements.ready', $requirement) }}">
                                                @csrf
                                                <button type="submit" class="pf-action green">Hazır İşaretle</button>
                                            </form>
                                        @endif

                                        @if($requirement->canBeCancelled())
                                            <form method="POST" action="{{ route('admin.print-setup-requirements.cancel', $requirement) }}" class="pf-inline-form">
                                                @csrf
                                                <input type="text" name="reason" class="pf-inline-field" placeholder="İptal nedeni">
                                                <button type="submit" class="pf-action">İptal Et</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @elseif($preparationRequired)
                <section class="pf-card">
                    <div class="pf-body">
                        <div class="pf-section-head">
                            <div>
                                <h3 class="pf-title" style="font-size: 18px;">Klişe / Kalıp Kontrolü</h3>
                            </div>
                        </div>

                        <div class="pf-meta">
                            <div class="pf-box"><span class="pf-label">Hazırlık Tipi</span><div class="pf-value">Klişe / Kalıp</div></div>
                            <div class="pf-box"><span class="pf-label">Hazırlık Durumu</span><div class="pf-value">{{ $preparationLabel }}</div></div>
                            @if(filled($production->production_note))
                                <div class="pf-box"><span class="pf-label">Not</span><div class="pf-value">{{ $production->production_note }}</div></div>
                            @endif
                        </div>
                    </div>
                </section>
            @endif

            <section class="pf-card" id="assignment-form">
                <div class="pf-body">
                    <div class="pf-section-head">
                        <div>
                            <h3 class="pf-title" style="font-size: 18px;">Üretim / Fason Ataması</h3>
                            <div class="pf-note">İç üretimde birim seçebilir, dış üretimde yalnız fason rolündeki aktif cari kartları kullanabilirsiniz.</div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.productions.update-assignment', $production) }}" class="pf-progress-list" style="margin-top: 12px;" data-production-assignment-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="assigned_to" value="{{ old('assigned_to', $production->assigned_to) }}">
                        <input type="hidden" name="cliche_required" value="{{ old('cliche_required', $production->cliche_required ? 1 : 0) }}">
                        @if(filled($production->cliche_status))
                            <input type="hidden" name="cliche_status" value="{{ old('cliche_status', $production->cliche_status) }}">
                        @endif

                        <div class="pf-meta">
                            <div class="pf-box">
                                <label for="production_type" class="pf-label">Üretim Tipi</label>
                                <select id="production_type" name="production_type" class="pf-inline-field" data-production-type-select>
                                    @foreach($typeLabels as $key => $label)
                                        <option value="{{ $key }}" @selected($selectedProductionType === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="pf-box" data-internal-unit-wrap @if($assignmentUsesExternalCompany) style="display: none;" @endif>
                                <label for="production_unit_name" class="pf-label">İç Üretim Birimi</label>
                                <input id="production_unit_name" name="production_unit_name" type="text" class="pf-inline-field" value="{{ old('production_unit_name', $preferredUnitName) }}" placeholder="Örn. UV hattı / atölye adı">
                            </div>

                            <div class="pf-box" data-production-company-wrap @if(!$assignmentUsesExternalCompany) style="display: none;" @endif>
                                <label for="production_company_id" class="pf-label">Fason Firma</label>
                                <select id="production_company_id" name="production_company_id" class="pf-inline-field" data-production-company-select @if($assignmentUsesExternalCompany) required @endif>
                                    <option value="">Fason firma seçin</option>
                                    @foreach($companies as $companyOption)
                                        <option value="{{ $companyOption->id }}" @selected((string) $selectedProductionCompanyId === (string) $companyOption->id)>
                                            {{ $companyOption->short_name ?: $companyOption->legal_name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="pf-note" style="margin-top: 8px;">Yalnız Fason Baskı Firması veya Fason Üretim Firması rolündeki aktif cariler listelenir.</div>
                            </div>
                        </div>

                        <div>
                            <label for="production_note" class="pf-label">Operasyon Notu</label>
                            <input id="production_note" name="production_note" type="text" class="pf-inline-field" value="{{ old('production_note', $production->production_note) }}" placeholder="Kısa operasyon notu">
                        </div>

                        <div class="pf-actions">
                            <button type="submit" class="pf-action primary">Atamayı Güncelle</button>
                            @if($assignmentUsesExternalCompany && !$preferredCompanyId)
                                <span class="pf-note">Dış üretim / fason için önce fason firma seçin.</span>
                            @endif
                        </div>
                    </form>
                </div>
            </section>

            <section class="pf-card">
                <div class="pf-body">
                    <div class="pf-section-head">
                        <div>
                            <h3 class="pf-title" style="font-size: 18px;">Üretim Aksiyonları</h3>
                        </div>
                    </div>

                    <div class="pf-action-grid">
                        <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="{{ $startAction }}">
                            @if($startAction === 'assign_external')
                                <input type="hidden" name="production_company_id" value="{{ $preferredCompanyId }}">
                            @else
                                <input type="hidden" name="production_unit_name" value="{{ $preferredUnitName }}">
                            @endif
                            <button type="submit" class="pf-big-btn primary {{ $canAdvanceProduction ? '' : 'disabled' }}" @disabled(!$canAdvanceProduction)>Baskıya Başla<span class="pf-big-sub">{{ $canAdvanceProduction ? 'Operasyonu üretime al' : $actionReadinessReason }}</span></button>
                        </form>

                        <form method="POST" action="{{ route('admin.productions.update-status', $production) }}" class="pf-inline-form">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="partial">
                            <label class="pf-label">Bu tur basılan adet</label>
                            <input type="number" step="0.0001" min="1" max="{{ $hasRemainingQuantity ? number_format($remainingQuantity, 4, '.', '') : '0' }}" value="{{ old('partial_quantity', number_format($partialDefaultQuantity, 4, '.', '')) }}" name="partial_quantity" class="pf-inline-field" placeholder="20">
                            <button type="submit" class="pf-big-btn amber {{ $canAdvanceProduction ? '' : 'disabled' }}" @disabled(!$canAdvanceProduction)>Kısmi Basıldı<span class="pf-big-sub">{{ $canAdvanceProduction ? 'Girilen adet kadar baskıyı işle' : $progressReason }}</span></button>
                        </form>

                        <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="completed">
                            <button type="submit" class="pf-big-btn green {{ $canAdvanceProduction ? '' : 'disabled' }}" @disabled(!$canAdvanceProduction)>Tamamı Basıldı<span class="pf-big-sub">{{ $canAdvanceProduction ? 'Kalan adedi tamamla' : $progressReason }}</span></button>
                        </form>

                        <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="issue">
                            <input type="text" name="note" class="pf-inline-field" placeholder="Kısa sorun notu">
                            <button type="submit" class="pf-big-btn red">Sorun Bildir<span class="pf-big-sub">Yüzey, grafik veya ürün sorunu</span></button>
                        </form>
                    </div>
                </div>
            </section>
        </div>

        <aside class="pf-side-stack">
            <section class="pf-card">
                <div class="pf-body">
                    <h3 class="pf-title" style="font-size: 18px;">Operasyon Özeti</h3>
                    <div class="pf-progress-list">
                        <div class="pf-progress-card"><span class="pf-label">Müşteri</span><div class="pf-value">{{ $production->order?->customer?->legal_name ?: '-' }}</div></div>
                        <div class="pf-progress-card"><span class="pf-label">Üretim Tipi</span><div class="pf-value">{{ $snapshot['production_type_label'] ?? '-' }}</div></div>
                        @if($isOutsourcedFlow)
                            <div class="pf-progress-card"><span class="pf-label">Fason Firma</span><div class="pf-value">{{ $preferredCompanyName ?: 'Seçilmedi' }}</div></div>
                        @endif
                        @if(filled($production->production_unit_name))
                            <div class="pf-progress-card"><span class="pf-label">Üretim Birimi</span><div class="pf-value">{{ $production->production_unit_name }}</div></div>
                        @endif
                    </div>
                </div>
            </section>

            <section class="pf-card">
                <div class="pf-body">
                    <h3 class="pf-title" style="font-size: 18px;">Üretim İlerleme</h3>
                    <div class="pf-progress-list">
                        <div class="pf-progress-card"><span class="pf-label">Planlanan Adet</span><div class="pf-value">{{ rtrim(rtrim(number_format((float) $production->planned_quantity, 4, ',', '.'), '0'), ',') }}</div></div>
                        <div class="pf-progress-card"><span class="pf-label">Basılan Adet</span><div class="pf-value">{{ rtrim(rtrim(number_format((float) $production->completed_quantity, 4, ',', '.'), '0'), ',') }}</div></div>
                        <div class="pf-progress-card"><span class="pf-label">Kalan Adet</span><div class="pf-value">{{ rtrim(rtrim(number_format((float) $production->remaining_quantity, 4, ',', '.'), '0'), ',') }}</div></div>
                        <div class="pf-progress-card">
                            <span class="pf-label">İlerleme</span>
                            @php
                                $progressPercent = (float) $production->planned_quantity > 0
                                    ? min(100, max(0, round(((float) $production->completed_quantity / (float) $production->planned_quantity) * 100, 1)))
                                    : 0;
                            @endphp
                            <div class="pf-progress-bar"><div class="pf-progress-fill" style="width: {{ $progressPercent }}%;"></div></div>
                            <div class="pf-note" style="margin-top: 8px;">{{ rtrim(rtrim(number_format($progressPercent, 1, ',', '.'), '0'), ',') }}% tamamlandı</div>
                        </div>
                    </div>
                </div>
            </section>

            @if($subcontractorCostVisible && $isOutsourcedFlow)
                <section class="pf-card">
                    <div class="pf-body">
                        <h3 class="pf-title" style="font-size: 18px;">Fason Maliyet Bilgisi</h3>
                        <div class="pf-note" style="margin-top: 8px;">Bu alan yalnız finans / yetkili kullanıcı görünümündedir.</div>
                        <form method="POST" action="{{ route('admin.productions.update-assignment', $production) }}" class="pf-progress-list" style="margin-top: 12px;">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="production_type" value="{{ $production->production_type }}">
                            <input type="hidden" name="production_company_id" value="{{ $preferredCompanyId }}">
                            <input type="hidden" name="production_unit_name" value="{{ $preferredUnitName }}">
                            <input type="hidden" name="assigned_to" value="{{ $production->assigned_to }}">
                            <input type="hidden" name="cliche_required" value="{{ $production->cliche_required ? 1 : 0 }}">
                            @if(filled($production->cliche_status))
                                <input type="hidden" name="cliche_status" value="{{ $production->cliche_status }}">
                            @endif
                            <input type="hidden" name="production_note" value="{{ $production->production_note }}">
                            <div>
                                <label class="pf-label">Fason Maliyeti</label>
                                <input type="number" step="0.01" min="0" name="subcontractor_cost" class="pf-inline-field" value="{{ old('subcontractor_cost', $production->subcontractor_cost !== null ? number_format((float) $production->subcontractor_cost, 2, '.', '') : '') }}" placeholder="0.00">
                            </div>
                            <div>
                                <label class="pf-label">Para Birimi</label>
                                <select name="subcontractor_cost_currency" class="pf-inline-field">
                                    @foreach(['TRY', 'USD', 'EUR'] as $currency)
                                        <option value="{{ $currency }}" @selected(old('subcontractor_cost_currency', $production->subcontractor_cost_currency ?: 'TRY') === $currency)>{{ $currency }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="pf-label">Maliyet Notu</label>
                                <input type="text" name="subcontractor_cost_note" class="pf-inline-field" value="{{ old('subcontractor_cost_note', $production->subcontractor_cost_note) }}" placeholder="Fason hizmet notu">
                            </div>
                            <button type="submit" class="pf-action primary">Fason Maliyetini Kaydet</button>
                        </form>
                    </div>
                </section>
            @endif

            @if($workForm)
                <section class="pf-card" id="photo-upload">
                    <div class="pf-body">
                        <h3 class="pf-title" style="font-size: 18px;">Üretim Fotoğrafı</h3>
                        <div class="pf-progress-list" style="margin-top: 12px;">
                            <div class="pf-photo-thumb">
                                @if($lastPhoto && $lastPhoto->isImage())
                                    <img src="{{ route('admin.work-forms.attachments.preview', $lastPhoto) }}" alt="{{ $lastPhoto->file_name }}">
                                @elseif($lastPhoto)
                                    <div>
                                        <div class="pf-value">{{ $lastPhoto->file_name }}</div>
                                        <div class="pf-note" style="margin-top: 8px;">Görsel dışı dosya</div>
                                    </div>
                                @else
                                    <div>
                                        <div class="pf-value">Fotoğraf yok</div>
                                    </div>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('admin.work-forms.attachments.store', $workForm) }}" enctype="multipart/form-data" class="pf-progress-list">
                                @csrf
                                <input type="hidden" name="attachment_type" value="production_photo">
                                <input type="hidden" name="section" value="production">
                                <input type="file" name="file" accept="image/*" capture="environment">
                                <input type="text" name="note" class="pf-inline-field" placeholder="Fotoğraf notu">
                                <button type="submit" class="pf-action primary">Fotoğraf Ekle</button>
                            </form>
                        </div>
                    </div>
                </section>
            @endif

            <section class="pf-card">
                <div class="pf-body">
                    <h3 class="pf-title" style="font-size: 18px;">Dosyalar</h3>
                    <div class="pf-progress-list" style="margin-top: 12px;">
                        <div class="pf-progress-card">
                            <span class="pf-label">İş Klasörü</span>
                            <div class="pf-value">{{ $systemWorkFolder?->display_path ?: '-' }}</div>
                        </div>
                        @if($finalGraphic)
                            <div class="pf-file-row">
                                <div>
                                    <div class="pf-value">Final Baskı Dosyası</div>
                                    <div class="pf-note">{{ $finalGraphic['file_name'] }}</div>
                                </div>
                                <a href="{{ $finalGraphic['open_url'] }}" target="_blank" rel="noopener" class="pf-link primary">Aç</a>
                            </div>
                        @endif
                    </div>
                </div>
            </section>

        </aside>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-production-assignment-form]');
    if (!form) {
        return;
    }

    const typeSelect = form.querySelector('[data-production-type-select]');
    const companyWrap = form.querySelector('[data-production-company-wrap]');
    const companySelect = form.querySelector('[data-production-company-select]');
    const internalWrap = form.querySelector('[data-internal-unit-wrap]');

    const syncAssignmentMode = () => {
        const externalMode = ['external', 'outsourced'].includes(typeSelect?.value || '');

        if (companyWrap) {
            companyWrap.style.display = externalMode ? '' : 'none';
        }

        if (internalWrap) {
            internalWrap.style.display = externalMode ? 'none' : '';
        }

        if (companySelect) {
            companySelect.required = externalMode;

            if (!externalMode) {
                companySelect.value = '';
            }
        }
    };

    typeSelect?.addEventListener('change', syncAssignmentMode);
    syncAssignmentMode();
});
</script>
@endpush

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Operasyon Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Sipariş No</span><strong>{{ $snapshot['order_number'] ?? ($production->order?->document_number ?: '-') }}</strong></div>
            <div class="pd-status-row"><span>İş Formu No</span><strong>{{ $snapshot['work_form_number'] ?? ($workForm?->work_form_number ?: '-') }}</strong></div>
            <div class="pd-status-row"><span>Baskı Operasyonu</span><strong>{{ $snapshot['print_sequence'] ?? '-' }} {{ $snapshot['print_type'] ?? '-' }}</strong></div>
            <div class="pd-status-row"><span>Üretim Tipi</span><strong>{{ $snapshot['production_type_label'] ?? '-' }}</strong></div>
            @if($isOutsourcedFlow)
                <div class="pd-status-row"><span>Fason Firma</span><strong>{{ $preferredCompanyName ?: 'Seçilmedi' }}</strong></div>
            @endif
            <div class="pd-status-row"><span>Grafik</span><strong>{{ $snapshot['graphic_status_label'] ?? '-' }}</strong></div>
            <div class="pd-status-row"><span>Tedarik</span><strong>{{ $snapshot['procurement_status_label'] ?? '-' }}</strong></div>
        </div>
    </div>
</div>
@endsection
