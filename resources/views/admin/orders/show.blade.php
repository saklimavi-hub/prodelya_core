@extends('layouts.prodelya-admin')

@section('title', ($order->document_number ?: 'Sipariş') . ' | Prodelya')
@section('page_title', 'Sipariş Detayı')
@section('page_subtitle', ($order->document_number ?: 'Sipariş') . ' · ' . ($order->customer?->legal_name ?: 'Müşteri bilgisi yok'))

@section('page_actions')
    <div class="flex gap-3">
        <a href="{{ route('admin.orders.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
        @if($order->workForms->first())
            <a href="{{ route('admin.work-forms.pdf', $order->workForms->first()) }}" class="pd-btn pd-btn-light">PDF / İş Formu</a>
        @endif
        <a href="{{ route('admin.orders.show', ['order' => $order, 'tab' => 'gecmis']) }}" class="pd-btn pd-btn-primary">Diğer İşlemler</a>
    </div>
@endsection

@section('content')
@php
    $orderStatusLabel = $overview['general_status_label'] ?? 'Sipariş';
    $operationStatusLabel = $overview['operation_status_label'] ?? 'İzleniyor';
    $operationBadge = $overview['operation_status_badge'] ?? 'gray';
    $generalBadge = $overview['general_status_badge'] ?? 'gray';
    $processDepth = (array) data_get($overview, 'process_depth', []);
    $processDepthKey = (string) data_get($processDepth, 'key', 'standard');
    $processDepthLabel = (string) data_get($processDepth, 'label', 'Standart Akış');
    $processDepthSourceLabel = (string) data_get($processDepth, 'source_label', 'Paket varsayılanı');
    $processDepthSource = (string) data_get($processDepth, 'source', 'package_default');
    $processDepthPresentation = (array) data_get($processDepth, 'presentation', []);
    $operationDensity = (string) data_get($processDepthPresentation, 'operation_card_density', 'standard');
    $processDepthDensityClass = (string) data_get($processDepthPresentation, 'density_class', 'pd-order-depth-standard');
    $showExtendedReadinessDetails = (bool) data_get($processDepthPresentation, 'show_extended_readiness_details', false);
    $showEvidenceSections = (bool) data_get($processDepthPresentation, 'show_evidence_sections', false);
    $showQualityControlSection = (bool) data_get($processDepthPresentation, 'show_quality_control_section', false);
    $showAdvancedActivityTimeline = (bool) data_get($processDepthPresentation, 'show_advanced_activity_timeline', false);
    $isFastDepth = $processDepthKey === 'fast' || $operationDensity === 'compact';
    $isControlledDepth = $processDepthKey === 'controlled' || $operationDensity === 'detailed';
    $isStandardDepth = ! $isFastDepth && ! $isControlledDepth;
    $isCompactDepth = $isFastDepth;
    $isDetailedDepth = $isControlledDepth;
    $processDepthBadgeTone = $processDepthSource === 'tenant_override' ? 'blue' : 'green';
    $workForm = $order->workForms->first();
    $workFormPdfUrl = $workForm ? route('admin.work-forms.pdf', $workForm) : null;
    $trackingUrl = ($workForm && filled($workForm->public_tracking_token))
        ? route('admin.orders.tracking.open', ['order' => $order->id, 'workForm' => $workForm->id])
        : null;
    $sourceQuoteUrl = $order->sourceQuote ? route('admin.promotion-quotes.show', $order->sourceQuote) : null;
    $historyRows = collect($historyRows ?? []);
    $activityPreviewRows = $historyRows->take($isDetailedDepth ? 6 : 2)->values();
    $deliveryInfo = $deliveryTab['delivery_info'] ?? [];
    $latestLabelBatch = $deliveryTab['latest_label_batch'] ?? null;
    $orderFamilyLabel = match ((string) ($order->order_family ?? '')) {
        'promotion' => 'Promosyon Sipariş',
        'print' => 'Baskı Sipariş',
        'matbaa' => 'Matbaa Sipariş',
        default => $order->order_family ? \Illuminate\Support\Str::headline((string) $order->order_family) : 'Sipariş',
    };
    $documentTypeLabel = match ((string) ($order->document_type ?? '')) {
        'order' => 'Sipariş',
        'quote' => 'Teklif',
        default => $order->document_type ? \Illuminate\Support\Str::headline((string) $order->document_type) : 'Belge',
    };
    $statusLine = collect([
        $order->document_number,
        $order->customer?->legal_name,
        $orderFamilyLabel . ' / ' . $documentTypeLabel,
        $orderStatusLabel,
    ])->filter()->implode(' · ');
    $deliveryDate = null;

    if ($order->delivery_date) {
        try {
            $deliveryDate = \Illuminate\Support\Carbon::parse($order->delivery_date)->startOfDay();
        } catch (\Throwable $exception) {
            $deliveryDate = null;
        }
    }

    $remainingDaysLabel = 'Belirtilmedi';

    if ($deliveryDate) {
        $remainingDays = now()->startOfDay()->diffInDays($deliveryDate, false);
        $remainingDaysLabel = match (true) {
            $remainingDays === 0 => 'Bugün',
            $remainingDays > 0 => $remainingDays . ' gün',
            default => abs($remainingDays) . ' gün geçti',
        };
    }

    $paymentStatusLabel = data_get($overview, 'payment_status_label', 'Finans izleniyor');
    $customerCurrentAccountUrl = $customerCurrentAccount ? route('admin.current-accounts.transactions.index', $customerCurrentAccount) : null;
    $customerCardUrl = $order->customer ? route('admin.companies.show', $order->customer) : route('admin.companies.index');
    $hasPrintProcess = $itemRows->contains(fn (array $row): bool => collect($row['prints'] ?? [])->isNotEmpty());
    $graphicNeedsAttention = $order->workForms->contains(function ($workFormRow): bool {
        $status = (string) data_get($workFormRow->graphic_snapshot, 'status', '');

        return $status !== '' && !in_array($status, ['gerekli_degil', 'uretime_hazir'], true);
    });
    $graphicVisualCount = $order->workForms->sum(fn ($form) => $form->attachments->where('attachment_type', 'graphic_visual')->count());
    $graphicApprovalCount = $order->workForms->sum(fn ($form) => $form->attachments->where('attachment_type', 'customer_approval')->count());
    $productionPhotoCount = $order->workForms->sum(fn ($form) => $form->attachments->where('attachment_type', 'production_photo')->count());
    $deliveryEvidenceCount = $order->workForms->sum(fn ($form) => $form->attachments->whereIn('attachment_type', ['delivery_photo', 'delivery_document'])->count());
    $graphicCard = [
        'title' => 'Grafik',
        'badge' => $graphicNeedsAttention ? 'amber' : ($order->workForms->isNotEmpty() ? 'green' : ($hasPrintProcess ? 'amber' : 'gray')),
        'status' => $order->workForms->isEmpty()
            ? ($hasPrintProcess ? 'Grafik bekliyor' : 'Grafik gerekli değil')
            : ($graphicNeedsAttention ? 'Grafik bekliyor' : 'Grafik hazır'),
        'summary' => $order->workForms->isEmpty()
            ? 'Grafik kaydı henüz oluşmadı.'
            : 'Müşteri onayı, revize ve üretime hazırlık durumu iş formu üzerinden izlenir.',
        'meta' => $workForm?->work_form_number ? 'Son iş formu: ' . $workForm->work_form_number : 'İş formu üzerinden izlenir',
        'primary_label' => 'Grafik Detayını Aç',
        'primary_url' => $workForm ? route('admin.graphics.show', $workForm) : route('admin.graphics.index'),
        'secondary_label' => $trackingUrl ? 'Müşteri Takip Ekranı' : 'İş Formuna Git',
        'secondary_url' => $trackingUrl ?: ($workForm ? route('admin.work-forms.show', $workForm) : route('admin.orders.show', ['order' => $order, 'tab' => 'is-formu'])),
        'warning' => $graphicNeedsAttention ? 'Revize veya onay bekleyen grafik işi var.' : null,
        'readiness_items' => collect([
            ['label' => 'İş formu', 'value' => $workForm?->work_form_number],
            ['label' => 'Grafik durumu', 'value' => data_get($workForm?->graphic_snapshot, 'public_status_label') ?: data_get($workForm?->graphic_snapshot, 'status_label')],
            ['label' => 'Onay durumu', 'value' => data_get($workForm?->graphic_snapshot, 'approval_status_label')],
            ['label' => 'Revize notu', 'value' => data_get($workForm?->graphic_snapshot, 'revision_note')],
        ])->filter(fn (array $item) => filled($item['value']))->values()->all(),
        'evidence_items' => collect([
            $graphicVisualCount > 0 ? $graphicVisualCount . ' grafik görseli' : null,
            $graphicApprovalCount > 0 ? $graphicApprovalCount . ' müşteri onay dosyası' : null,
        ])->filter()->values()->all(),
    ];
    $procurementRecord = $order->procurements->first();
    $procurementStatus = $procurementRecord?->safeStatusLabel();
    $procurementCard = [
        'title' => 'Tedarik / Malzeme',
        'badge' => $procurementRecord ? (($procurementRecord->remaining_quantity ?? 0) > 0 ? 'amber' : 'green') : 'gray',
        'status' => $procurementStatus ?: 'Tedarik gerekli değil',
        'summary' => $procurementRecord
            ? 'Malzeme ve ürün tedarik durumu mevcut tedarik kaydı üzerinden izlenir.'
            : 'Bu sipariş için ayrı tedarik kaydı görünmüyor.',
        'meta' => $procurementRecord ? 'Kalan miktar: ' . rtrim(rtrim(number_format((float) $procurementRecord->remaining_quantity, 4, ',', '.'), '0'), ',') : 'Tedarik listesi üzerinden yönetilir',
        'primary_label' => 'Tedarik Detayını Aç',
        'primary_url' => $procurementRecord ? route('admin.procurements.show', $procurementRecord) : route('admin.procurements.index'),
        'secondary_label' => 'Tedarik Listesi',
        'secondary_url' => route('admin.procurements.index'),
        'warning' => $procurementRecord && (float) $procurementRecord->remaining_quantity > 0 ? 'Eksik tedarik kalemi bulunuyor.' : null,
        'readiness_items' => collect([
            ['label' => 'Tedarik durumu', 'value' => $procurementStatus],
            ['label' => 'Karşılama kaynağı', 'value' => $procurementRecord?->safeFulfillmentSourceLabel()],
            ['label' => 'Gelen miktar', 'value' => $procurementRecord ? rtrim(rtrim(number_format((float) $procurementRecord->received_quantity, 4, ',', '.'), '0'), ',') : null],
            ['label' => 'Kalan miktar', 'value' => $procurementRecord ? rtrim(rtrim(number_format((float) $procurementRecord->remaining_quantity, 4, ',', '.'), '0'), ',') : null],
        ])->filter(fn (array $item) => filled($item['value']))->values()->all(),
        'evidence_items' => collect([
            $procurementRecord && $procurementRecord->supplier?->name ? 'Tedarikçi: ' . $procurementRecord->supplier->name : null,
            $procurementRecord && $procurementRecord->notes ? 'Not kaydı var' : null,
        ])->filter()->values()->all(),
    ];
    $productionRecord = $order->printProductions->first();
    $productionCard = [
        'title' => 'Üretim / Fason',
        'badge' => $productionRecord ? ($productionRecord->isCompleted() ? 'green' : ($productionRecord->isProblematic() ? 'red' : 'blue')) : ($hasPrintProcess ? 'amber' : 'gray'),
        'status' => $productionRecord?->safeStatusLabel() ?: ($hasPrintProcess ? 'Üretim bekliyor' : 'Üretim gerekli değil'),
        'summary' => $productionRecord
            ? 'İç üretim veya fason üretim durumu üretim kaydı üzerinden izlenir.'
            : 'Henüz aktif üretim kaydı görünmüyor.',
        'meta' => $productionRecord ? 'Tamamlanan: ' . rtrim(rtrim(number_format((float) $productionRecord->completed_quantity, 4, ',', '.'), '0'), ',') : 'Üretim listesi üzerinden izlenir',
        'primary_label' => 'Üretimi Aç',
        'primary_url' => $productionRecord ? route('admin.productions.show', $productionRecord) : route('admin.productions.index'),
        'secondary_label' => 'Üretim Listesi',
        'secondary_url' => route('admin.productions.index'),
        'warning' => $productionRecord && !$productionRecord->isCompleted() ? 'Üretim süreci devam ediyor.' : null,
        'readiness_items' => collect([
            ['label' => 'Üretim durumu', 'value' => $productionRecord?->safeStatusLabel()],
            ['label' => 'Üretim tipi', 'value' => $productionRecord?->safeProductionTypeLabel()],
            ['label' => 'Tamamlanan', 'value' => $productionRecord ? rtrim(rtrim(number_format((float) $productionRecord->completed_quantity, 4, ',', '.'), '0'), ',') : null],
            ['label' => 'Kalan', 'value' => $productionRecord ? rtrim(rtrim(number_format((float) $productionRecord->remaining_quantity, 4, ',', '.'), '0'), ',') : null],
        ])->filter(fn (array $item) => filled($item['value']))->values()->all(),
        'quality_control' => $showQualityControlSection ? collect([
            ['label' => 'Kalite kontrol', 'value' => $productionRecord?->safeQcStatusLabel() ?: data_get($workForm?->production_snapshot, 'qc_status_label')],
            ['label' => 'Klişe', 'value' => $productionRecord?->safeClicheStatusLabel() ?: data_get($workForm?->production_snapshot, 'cliche_status_label')],
        ])->filter(fn (array $item) => filled($item['value']))->values()->all() : [],
        'evidence_items' => collect([
            $productionPhotoCount > 0 ? $productionPhotoCount . ' üretim fotoğrafı' : null,
            $productionRecord && filled($productionRecord->issue_note) ? 'Sorun notu mevcut' : null,
        ])->filter()->values()->all(),
    ];
    $deliveryRecord = $order->deliveries->first();
    $deliveryCard = [
        'title' => 'Teslimat',
        'badge' => $deliveryTab['is_delivered'] ? 'green' : ($deliveryRecord ? 'orange' : 'gray'),
        'status' => $deliveryTab['is_delivered'] ? 'Teslim edildi' : ($deliveryRecord?->safeStatusLabel() ?: 'Teslimat bekliyor'),
        'summary' => 'Koli planı, etiket ve teslim bilgileri teslimat alanından yönetilir.',
        'meta' => $deliveryInfo['summary'] ?? ($deliveryTab['package_count'] . ' koli planı'),
        'primary_label' => 'Teslimat Detayını Aç',
        'primary_url' => $deliveryRecord ? route('admin.deliveries.show', $deliveryRecord) : route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']),
        'secondary_label' => 'Teslimat Sekmesi',
        'secondary_url' => route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']),
        'warning' => !$deliveryTab['is_delivered'] && ($deliveryTab['package_count'] ?? 0) === 0 ? 'Teslimat öncesi koli planı hazırlanmalı.' : null,
        'readiness_items' => collect([
            ['label' => 'Teslim durumu', 'value' => $deliveryTab['is_delivered'] ? 'Teslim edildi' : ($deliveryRecord?->safeStatusLabel() ?: 'Teslimat bekliyor')],
            ['label' => 'Teslim özeti', 'value' => $deliveryInfo['summary'] ?? null],
            ['label' => 'Teslim edilen', 'value' => $deliveryRecord ? rtrim(rtrim(number_format((float) $deliveryRecord->delivered_quantity, 4, ',', '.'), '0'), ',') : null],
            ['label' => 'Kalan', 'value' => $deliveryRecord ? rtrim(rtrim(number_format((float) $deliveryRecord->remaining_quantity, 4, ',', '.'), '0'), ',') : null],
        ])->filter(fn (array $item) => filled($item['value']))->values()->all(),
        'evidence_items' => collect([
            $deliveryEvidenceCount > 0 ? $deliveryEvidenceCount . ' teslim kanıtı / belge' : null,
            !empty($deliveryInfo['tracking_number']) ? 'Takip numarası kayıtlı' : null,
        ])->filter()->values()->all(),
    ];
    $financeCard = [
        'title' => 'Finans',
        'badge' => $financialDataVisible ? ($overview['payment_status_badge'] ?? 'gray') : 'gray',
        'status' => $financialDataVisible ? data_get($financeOverview, 'overall.status_label', $paymentStatusLabel) : 'Yetkiye göre görünür',
        'summary' => $financialDataVisible
            ? 'Tahsilat, cari hareket ve karşı borç özeti finans ekranından izlenir.'
            : 'Finans tutarları yalnız yetkili kullanıcıya gösterilir.',
        'meta' => $financialDataVisible
            ? 'Kalan bakiye: ' . number_format((float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL')
            : 'Ödeme durumu yetkiye göre gösterilir',
        'primary_label' => $financialDataVisible ? 'Finans Özeti' : 'Finans Sekmesi',
        'primary_url' => $financialDataVisible ? route('admin.finance.show', $order) : route('admin.orders.show', ['order' => $order, 'tab' => 'finans']),
        'secondary_label' => $customerCurrentAccountUrl ? 'Cari Hareketler' : 'Finans Sekmesi',
        'secondary_url' => $customerCurrentAccountUrl ?: route('admin.orders.show', ['order' => $order, 'tab' => 'finans']),
        'warning' => $financialDataVisible && (float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0) > 0 ? 'Tahsilat bekleyen bakiye bulunuyor.' : null,
    ];
    $operationFlowCards = [$graphicCard, $procurementCard, $productionCard, $deliveryCard];
    $priorityFlowCard = collect($operationFlowCards)
        ->first(fn (array $card): bool => in_array($card['badge'], ['red', 'amber', 'blue', 'orange'], true))
        ?: $operationFlowCards[0];
    $helperActions = collect([
        $workForm ? ['label' => 'İş Formunu Aç', 'url' => route('admin.work-forms.show', $workForm)] : null,
        $workFormPdfUrl ? ['label' => 'PDF İndir', 'url' => $workFormPdfUrl] : null,
        $financialDataVisible ? ['label' => 'Ödeme Al', 'url' => route('admin.finance.show', $order)] : null,
    ])->filter()->take($isCompactDepth ? 1 : 2)->values();
    $quickLinks = collect([
        ['label' => 'Cari Kart', 'url' => $customerCardUrl],
        $trackingUrl ? ['label' => 'Müşteri Takip Ekranı', 'url' => $trackingUrl] : null,
        $sourceQuoteUrl ? ['label' => 'Teklif Kaydı', 'url' => $sourceQuoteUrl] : null,
        ['label' => 'Teslimat Sekmesi', 'url' => route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat'])],
    ])->filter()->take(3)->values();
    $warnings = collect($operationFlowCards)->pluck('warning')->filter()->take(3)->values();
    $focusKey = (string) data_get($processDepth, 'focus.key', data_get($overview, 'workflow_focus_key', 'review'));
    $focusBlocker = data_get($processDepth, 'focus.blocker_label');
    $currentFocusLabel = (string) data_get($processDepth, 'focus.current_label', $operationStatusLabel);
    $nextFocusLabel = (string) data_get($processDepth, 'focus.next_label', $overview['next_action_label'] ?? 'Siparişi incele');
    $focusPrimaryLabel = (string) data_get($processDepth, 'focus.primary_label', $priorityFlowCard['primary_label']);
    $focusPrimaryUrl = (string) data_get($processDepth, 'focus.primary_url', $priorityFlowCard['primary_url']);
    $compactFlowCards = collect($operationFlowCards)
        ->map(fn (array $card): array => [
            'title' => $card['title'],
            'status' => $card['status'],
            'badge' => $card['badge'],
            'url' => $card['primary_url'],
        ])
        ->values();
    $standardStatusItems = collect($operationFlowCards)
        ->map(function (array $card): array {
            $firstReadinessValue = data_get($card, 'readiness_items.0.value');

            return [
                'label' => $card['title'],
                'value' => $firstReadinessValue ?: $card['status'],
            ];
        })
        ->filter(fn (array $item): bool => filled($item['value']))
        ->values();
    $controlledDetailSections = collect($operationFlowCards)
        ->map(function (array $card) use ($showQualityControlSection, $showEvidenceSections): array {
            $items = collect($card['readiness_items'] ?? []);

            if ($showQualityControlSection && !empty($card['quality_control'])) {
                $items = $items->concat($card['quality_control']);
            }

            if ($showEvidenceSections && !empty($card['evidence_items'])) {
                $items = $items->concat(
                    collect($card['evidence_items'])->map(fn (string $value): array => [
                        'label' => 'Kanıt',
                        'value' => $value,
                    ])
                );
            }

            return [
                'title' => $card['title'],
                'status' => $card['status'],
                'items' => $items->take(6)->values()->all(),
            ];
        })
        ->filter(fn (array $section): bool => !empty($section['items']))
        ->values();
    $hasControlledDetails = $controlledDetailSections->isNotEmpty() || ($showAdvancedActivityTimeline && $activityPreviewRows->isNotEmpty());
    $stickyTopOffset = 18;
    $processStatusRows = collect($operationFlowCards)
        ->map(fn (array $card): array => [
            'title' => $card['title'],
            'status' => $card['status'],
            'badge' => $card['badge'],
        ])
        ->values();
    $stickyRecentActivities = $activityPreviewRows->take(3)->values();
@endphp

@if(session('success'))
    <div class="pd-alert">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="pd-alert-warning">{{ session('error') }}</div>
@endif

@if($errors->any())
    <div class="pd-alert-warning">{{ $errors->first() }}</div>
@endif

<style>
    .pd-order-layout--single { display:block; }
    .pd-order-sticky-layout {
        grid-template-columns:minmax(0, 1fr) 330px;
        align-items:start;
        width:100%;
    }
    .pd-order-sticky-layout > * { min-width:0; }
    .pd-order-sticky-main { min-width:0; }
    .pd-order-sticky-sidebar {
        position:sticky;
        top:18px;
        align-self:start;
        min-width:0;
    }
    .pd-order-sticky-kpis {
        display:grid;
        grid-template-columns:repeat(4, minmax(0, 1fr));
        gap:var(--pd-space-inline);
    }
    .pd-order-sticky-kpis--compact { grid-template-columns:repeat(3, minmax(0, 1fr)); }
    .pd-order-sticky-kpi {
        border:1px solid #dfe5ec;
        border-radius:8px;
        padding:11px;
        background:#fff;
    }
    .pd-order-sticky-kpi-label { display:block; color:#6c7888; font-size:11px; margin-bottom:5px; }
    .pd-order-sticky-kpi-value { font-size:13px; font-weight:700; color:#142033; }
    .pd-order-sticky-items th,
    .pd-order-sticky-items td { padding:9px 8px; font-size:12px; border-bottom:1px solid #edf1f5; text-align:left; vertical-align:top; }
    .pd-order-sticky-items th { color:#6c7888; font-weight:600; }
    .pd-order-sticky-product { font-weight:700; color:#142033; }
    .pd-order-sticky-product-meta { margin-top:4px; color:#6c7888; font-size:11px; display:grid; gap:2px; }
    .pd-order-sticky-flow { }
    .pd-order-sticky-focus-card {
        border:1px solid #bad0fa;
        background:#f6f9ff;
        border-radius:6px;
        padding:13px;
    }
    .pd-order-sticky-focus-grid {
        display:grid;
        grid-template-columns:120px minmax(0, 1fr);
        gap:7px 12px;
    }
    .pd-order-sticky-focus-grid span { color:#6c7888; font-size:11px; }
    .pd-order-sticky-focus-grid strong { font-size:12px; text-align:right; color:#142033; }
    .pd-order-sticky-main-cta { margin-top:10px; }
    .pd-order-sticky-module-grid {
        display:grid;
        grid-template-columns:repeat(4, minmax(0, 1fr));
        gap:var(--pd-space-inline);
    }
    .pd-order-sticky-module {
        border:1px solid #dfe5ec;
        border-radius:6px;
        padding:10px;
        background:#fff;
    }
    .pd-order-sticky-module-title { display:block; font-size:11px; font-weight:700; margin-bottom:7px; color:#142033; }
    .pd-order-sticky-note {
        padding:10px 12px;
        border:1px solid #f3cf8f;
        background:#fff7e8;
        border-radius:6px;
        font-size:12px;
        color:#7b5514;
    }
    .pd-order-sticky-detail-block { display:none; }
    .pd-order-depth-detailed .pd-order-sticky-detail-block { display:block; }
    .pd-order-depth-compact .pd-order-sticky-standard-only,
    .pd-order-depth-compact .pd-order-sticky-detail-block { display:none; }
    .pd-order-sticky-control-list { display:grid; gap:var(--pd-space-inline); }
    .pd-order-sticky-control-row {
        display:flex;
        justify-content:space-between;
        gap:16px;
        border:1px solid #edf1f5;
        border-radius:5px;
        padding:9px 10px;
        font-size:12px;
    }
    .pd-order-sticky-finance-line {
        border-top:1px dashed #dfe5ec;
        padding-top:10px;
        display:flex;
        justify-content:space-between;
        gap:var(--pd-space-section);
        align-items:center;
    }
    .pd-order-sticky-action-row,
    .pd-order-sticky-quick-grid {
        display:grid;
        gap:7px;
    }
    .pd-order-sticky-action-row { grid-template-columns:repeat(4, minmax(0, 1fr)); }
    .pd-order-sticky-quick-grid { grid-template-columns:1fr 1fr; }
    .pd-order-sticky-action-form,
    .pd-order-sticky-quick-form { margin:0; }
    .pd-order-sticky-quick-form--full { grid-column:1 / -1; }
    .pd-order-sticky-action-button,
    .pd-order-sticky-quick-button {
        width:100%;
        border:1px solid #dfe5ec;
        border-radius:5px;
        padding:8px 10px;
        background:#fff;
        color:#142033;
        font-size:11px;
        font-weight:600;
        text-align:center;
        text-decoration:none;
        cursor:pointer;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:38px;
    }
    .pd-order-sticky-quick-button--primary,
    .pd-order-sticky-main-cta .pd-order-sticky-quick-button {
        background:#2563eb;
        border-color:#2563eb;
        color:#fff;
    }
    .pd-order-sticky-summary-id { font-size:12px; color:#6c7888; margin-bottom:4px; }
    .pd-order-sticky-summary-customer { font-size:15px; font-weight:700; color:#142033; }
    .pd-order-sticky-summary-list,
    .pd-order-sticky-recent-list,
    .pd-order-sticky-status-list { display:grid; gap:var(--pd-space-inline); }
    .pd-order-sticky-summary-row,
    .pd-order-sticky-status-row { display:flex; justify-content:space-between; gap:14px; }
    .pd-order-sticky-summary-row span,
    .pd-order-sticky-status-row span { color:#6c7888; font-size:11px; }
    .pd-order-sticky-summary-row strong,
    .pd-order-sticky-status-row strong { font-size:12px; text-align:right; color:#142033; }
    .pd-order-sticky-active-focus {
        border:1px solid #bad0fa;
        background:#f6f9ff;
        border-radius:6px;
        padding:11px;
    }
    .pd-order-sticky-active-focus-title { font-size:12px; font-weight:700; margin:0 0 9px; color:#142033; }
    .pd-order-sticky-active-focus-note { color:#6c7888; font-size:11px; margin-bottom:8px; }
    .pd-order-sticky-financial { display:grid; gap:7px; }
    .pd-order-sticky-financial-amount { font-size:18px; font-weight:700; color:#142033; }
    .pd-order-sticky-muted { color:#6c7888; }
    .pd-order-sticky-recent-item-title { font-weight:700; color:#142033; font-size:12px; }
    .pd-order-sticky-recent-item-meta { color:#6c7888; font-size:11px; margin-top:2px; }
    .pd-order-sticky-responsive-marker { display:none; }
    @media (max-width: 1100px) {
        .pd-order-sticky-layout {
            grid-template-columns:minmax(0, 1fr);
        }
        .pd-order-sticky-sidebar {
            position:static;
            top:auto;
        }
        .pd-order-sticky-responsive-marker { display:block; }
    }
    @media (max-width: 760px) {
        .pd-order-sticky-kpis,
        .pd-order-sticky-module-grid,
        .pd-order-sticky-action-row,
        .pd-order-sticky-quick-grid { grid-template-columns:1fr 1fr; }
        .pd-order-sticky-focus-grid { grid-template-columns:1fr; }
        .pd-order-sticky-focus-grid strong { text-align:left; }
    }
    @media (max-width: 560px) {
        .pd-order-sticky-kpis,
        .pd-order-sticky-module-grid,
        .pd-order-sticky-action-row,
        .pd-order-sticky-quick-grid { grid-template-columns:1fr; }
    }
</style>

<div class="pd-page-stack">
<div class="pd-order-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <div>
                        <div class="text-xs" style="color:var(--pd-muted);">Sipariş No · Müşteri · Sipariş Ailesi · Durum</div>
                        <div style="margin-top:6px; font-weight:700;">{{ $statusLine }}</div>
                        <div style="margin-top:6px; color:var(--pd-muted);">Sipariş akışı, teslimat planı ve finans görünümü aynı sipariş omurgasında izlenir.</div>
                    </div>
                    <div class="pd-order-tabs" role="tablist" aria-label="Sipariş sekmeleri">
                        @foreach($orderTabs as $tabKey => $tabLabel)
                            <a
                                href="{{ route('admin.orders.show', ['order' => $order, 'tab' => $tabKey]) }}"
                                class="pd-order-tab {{ $activeOrderTab === $tabKey ? 'is-active' : '' }}"
                                aria-current="{{ $activeOrderTab === $tabKey ? 'page' : 'false' }}"
                            >
                                {{ $tabLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

    </div>
        @if($activeOrderTab === 'genel')
            <section class="pd-two-column-layout pd-order-sticky-layout" data-order-sticky-layout="true" data-sticky-layout="true">
                <div class="pd-page-stack pd-order-sticky-main">
                <div class="pd-card">
                    <div class="pd-card-header">
                        <h3 class="pd-card-title">Genel Özet</h3>
                        <p class="pd-card-subtitle">Siparişin genel durumu ve temel bilgileri.</p>
                    </div>
                    <div class="pd-card-body">
                        <div class="pd-order-sticky-kpis {{ $financialDataVisible ? '' : 'pd-order-sticky-kpis--compact' }}">
                            <div class="pd-order-sticky-kpi">
                                <span class="pd-order-sticky-kpi-label">Genel Durum</span>
                                <div class="pd-order-sticky-kpi-value">{{ $orderStatusLabel }}</div>
                            </div>
                            <div class="pd-order-sticky-kpi">
                                <span class="pd-order-sticky-kpi-label">Teslim Tarihi</span>
                                <div class="pd-order-sticky-kpi-value">{{ $overview['delivery_date_label'] ?? '-' }}</div>
                            </div>
                            <div class="pd-order-sticky-kpi">
                                <span class="pd-order-sticky-kpi-label">Kalan Gün</span>
                                <div class="pd-order-sticky-kpi-value">{{ $remainingDaysLabel }}</div>
                            </div>
                            @if($financialDataVisible)
                                <div class="pd-order-sticky-kpi">
                                    <span class="pd-order-sticky-kpi-label">Açık Bakiye</span>
                                    <div class="pd-order-sticky-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0)])</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="pd-card">
                    <div class="pd-card-header">
                        <h3 class="pd-card-title">Sipariş Kalemleri</h3>
                        <p class="pd-card-subtitle">Ürün, baskı ve operasyon durumu.</p>
                    </div>
                    <div class="pd-card-body">
                        <table class="pd-order-item-table pd-order-sticky-items">
                            <thead>
                                <tr>
                                    <th>Kalem</th>
                                    <th>Ürün / Baskı</th>
                                    <th>Miktar</th>
                                    <th>Durum</th>
                                    @if($financialDataVisible)
                                        <th>Tutar</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($itemRows as $itemRow)
                                    <tr>
                                        <td>#{{ $itemRow['sequence'] }}</td>
                                        <td>
                                            <div class="pd-order-sticky-product">{{ $itemRow['product_name'] }}</div>
                                            @if(!empty($itemRow['prints']))
                                                <div class="pd-order-sticky-product-meta">
                                                    @foreach($itemRow['prints'] as $printRow)
                                                        <div>{{ $printRow['print_type'] ?: 'Baskı' }} · {{ $printRow['print_option'] ?: 'Detay yok' }}</div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td>{{ $itemRow['quantity'] }}</td>
                                        <td><span class="pd-badge pd-badge-{{ str_contains(strtolower($itemRow['operation_status']), 'teslim') ? 'green' : (str_contains(strtolower($itemRow['operation_status']), 'bekliyor') ? 'amber' : 'blue') }}">{{ $itemRow['operation_status'] }}</span></td>
                                        @if($financialDataVisible)
                                            <td>{{ $itemRow['product_total_label'] }}</td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ $financialDataVisible ? '5' : '4' }}" class="text-sm" style="color:var(--pd-muted);">Sipariş kalemi görünmüyor.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="pd-card" data-process-depth="{{ $processDepthKey }}" data-operation-density="{{ $operationDensity }}">
                    <div class="pd-card-header">
                        <h3 class="pd-card-title">Sipariş Akışı</h3>
                        <p class="pd-card-subtitle">Grafik, tedarik, üretim ve teslimat aynı akışta; finans ayrı hatta izlenir.</p>
                    </div>
                    <div class="pd-card-body pd-card-stack pd-order-sticky-flow {{ $processDepthDensityClass }}">
                        <div class="pd-order-sticky-focus-card" data-canonical-focus-panel="true">
                            <div class="pd-order-sticky-focus-grid">
                                <span>Çalışma şekli</span><strong>{{ $processDepthLabel }}</strong>
                                <span>Şu an</span><strong>{{ $currentFocusLabel }}</strong>
                                <span>Sıradaki işlem</span><strong>{{ $nextFocusLabel }}</strong>
                                @if($focusBlocker)
                                    <span>Engel</span><strong>{{ $focusBlocker }}</strong>
                                @endif
                            </div>
                            <div class="pd-order-sticky-main-cta">
                                <a href="{{ $focusPrimaryUrl }}" class="pd-order-sticky-quick-button pd-order-depth-primary-cta" data-focus-key="{{ $focusKey }}">{{ $focusPrimaryLabel }}</a>
                            </div>
                        </div>

                        <div class="pd-order-sticky-module-grid" data-depth-branch="{{ $isFastDepth ? 'fast' : ($isControlledDepth ? 'controlled' : 'standard') }}">
                            @foreach($operationFlowCards as $flowCard)
                                <div class="pd-order-sticky-module">
                                    <span class="pd-order-sticky-module-title">{{ $flowCard['title'] }}</span>
                                    <span class="pd-badge pd-badge-{{ $flowCard['badge'] }}">{{ $flowCard['status'] }}</span>
                                </div>
                            @endforeach
                        </div>

                        @if(! $isFastDepth && $warnings->isNotEmpty())
                            <div class="pd-order-sticky-standard-only">
                                <div class="pd-order-sticky-note">{{ $warnings->implode(' ') }}</div>
                            </div>
                        @endif

                        @if($isControlledDepth && $hasControlledDetails)
                            <div class="pd-order-sticky-detail-block" data-controlled-details="true">
                                <div class="pd-card" style="border-color:#dbe6fb; box-shadow:none;">
                                    <div class="pd-card-header">
                                        <h3 class="pd-card-title">Kontrol Ayrıntıları</h3>
                                        <p class="pd-card-subtitle">Hazırlık ve faaliyet bilgileri.</p>
                                    </div>
                                    <div class="pd-card-body pd-order-sticky-control-list">
                                        @foreach($controlledDetailSections->take(3) as $section)
                                            @foreach(array_slice($section['items'], 0, 2) as $detailItem)
                                                <div class="pd-order-sticky-control-row"><span>{{ $detailItem['label'] }}</span><strong>{{ $detailItem['value'] }}</strong></div>
                                            @endforeach
                                        @endforeach
                                        @if($stickyRecentActivities->isNotEmpty())
                                            <div class="pd-order-sticky-control-row"><span>Son faaliyet</span><strong>{{ $stickyRecentActivities->first()['label'] }}</strong></div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if($financialDataVisible)
                            <div class="pd-order-sticky-finance-line">
                                <div>
                                    <b>Finans Hattı</b>
                                    <div class="pd-order-sticky-muted">{{ $financeCard['warning'] ?: $financeCard['summary'] }}</div>
                                </div>
                                <a href="{{ $financeCard['primary_url'] }}" class="pd-btn pd-btn-light">{{ $financeCard['primary_label'] }}</a>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="pd-card">
                    <div class="pd-card-header">
                        <h3 class="pd-card-title">Yardımcı İşlemler</h3>
                        <p class="pd-card-subtitle">Tekrarsız yardımcı bağlantılar ve taslak aksiyonları.</p>
                    </div>
                    <div class="pd-card-body">
                        <div class="pd-order-sticky-action-row">
                            @if($workForm)
                                <a href="{{ route('admin.work-forms.show', $workForm) }}" class="pd-order-sticky-action-button">İş Formunu Aç</a>
                            @endif
                            @if($workFormPdfUrl)
                                <a href="{{ $workFormPdfUrl }}" class="pd-order-sticky-action-button">PDF İndir</a>
                            @endif
                            @if($canCreateQuoteDraft)
                                <form method="POST" action="{{ route('admin.orders.revision-draft.store', $order) }}" class="pd-order-sticky-action-form">
                                    @csrf
                                    <button type="submit" class="pd-order-sticky-action-button">Revizyon Oluştur</button>
                                </form>
                                <form method="POST" action="{{ route('admin.orders.repeat-order-draft.store', $order) }}" class="pd-order-sticky-action-form">
                                    @csrf
                                    <button type="submit" class="pd-order-sticky-action-button">Tekrar Sipariş Oluştur</button>
                                </form>
                            @endif
                        </div>
                        @if($quickLinks->isNotEmpty())
                            <div class="pd-order-mini-list" style="margin-top:12px;">
                                <div class="text-xs" style="color:var(--pd-muted); font-weight:700;">Yardımcı Bağlantılar</div>
                                @foreach($quickLinks as $quickLink)
                                    <a href="{{ $quickLink['url'] }}" class="pd-order-inline-link">{{ $quickLink['label'] }}</a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <aside class="pd-order-summary-panel pd-section-stack pd-order-sticky-sidebar" data-order-sticky-sidebar="true" data-sticky-sidebar="true" data-sticky-responsive="stack-under-1100">
                <div class="pd-card">
                    <div class="pd-card-header">
                        <div class="pd-order-sticky-summary-id">{{ $order->document_number ?: '-' }}</div>
                        <div class="pd-order-sticky-summary-customer">{{ $order->customer?->legal_name ?: 'Müşteri bilgisi yok' }}</div>
                    </div>
                    <div class="pd-card-body pd-order-sticky-summary-list">
                        <div class="pd-order-sticky-summary-row"><span>Durum</span><strong>{{ $orderStatusLabel }}</strong></div>
                        <div class="pd-order-sticky-summary-row"><span>Teslim Tarihi</span><strong>{{ $overview['delivery_date_label'] ?? '-' }}</strong></div>
                        <div class="pd-order-sticky-summary-row"><span>Sipariş Ailesi</span><strong>{{ $orderFamilyLabel }}</strong></div>
                        <div class="pd-order-sticky-summary-row"><span>Çalışma Şekli</span><strong>{{ $processDepthLabel }}</strong></div>
                    </div>
                </div>

                <div class="pd-card">
                    <div class="pd-card-header">
                        <h3 class="pd-card-title">Aktif Odak</h3>
                        <p class="pd-card-subtitle">Sayfa kaydırılsa da görünür kalan karar alanı.</p>
                    </div>
                    <div class="pd-card-body">
                        <div class="pd-order-sticky-active-focus">
                            <div class="pd-order-sticky-active-focus-title">{{ $currentFocusLabel }}</div>
                            <div class="pd-order-sticky-active-focus-note">{{ $nextFocusLabel }}</div>
                            <a href="{{ $focusPrimaryUrl }}" class="pd-order-sticky-quick-button pd-order-sticky-quick-button--primary pd-order-depth-primary-cta" data-focus-key="{{ $focusKey }}">{{ $focusPrimaryLabel }}</a>
                        </div>
                    </div>
                </div>

                @if(! $isFastDepth)
                    <div class="pd-card">
                        <div class="pd-card-header">
                            <h3 class="pd-card-title">Süreç Durumu</h3>
                            <p class="pd-card-subtitle">Operasyonların kompakt özeti.</p>
                        </div>
                        <div class="pd-card-body pd-order-sticky-status-list">
                            @foreach($processStatusRows as $statusRow)
                                <div class="pd-order-sticky-status-row">
                                    <span>{{ $statusRow['title'] }}</span>
                                    <strong><span class="pd-badge pd-badge-{{ $statusRow['badge'] }}">{{ $statusRow['status'] }}</span></strong>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="pd-card">
                    <div class="pd-card-header">
                        <h3 class="pd-card-title">Hızlı İşlemler</h3>
                        <p class="pd-card-subtitle">Tekrarsız kısa yollar.</p>
                    </div>
                    <div class="pd-card-body pd-order-sticky-quick-grid">
                        <a href="{{ $focusPrimaryUrl }}" class="pd-order-sticky-quick-button pd-order-sticky-quick-button--primary pd-order-depth-primary-cta pd-order-sticky-quick-form--full" data-focus-key="{{ $focusKey }}">{{ $focusPrimaryLabel }}</a>
                        @if($workForm)
                            <a href="{{ route('admin.work-forms.show', $workForm) }}" class="pd-order-sticky-quick-button">İş Formu</a>
                        @endif
                        @if($workFormPdfUrl)
                            <a href="{{ $workFormPdfUrl }}" class="pd-order-sticky-quick-button">PDF İndir</a>
                        @endif
                        @if($canCreateQuoteDraft)
                            <form method="POST" action="{{ route('admin.orders.revision-draft.store', $order) }}" class="pd-order-sticky-quick-form">
                                @csrf
                                <button type="submit" class="pd-order-sticky-quick-button">Revizyon</button>
                            </form>
                            <form method="POST" action="{{ route('admin.orders.repeat-order-draft.store', $order) }}" class="pd-order-sticky-quick-form">
                                @csrf
                                <button type="submit" class="pd-order-sticky-quick-button">Tekrar Sipariş</button>
                            </form>
                        @endif
                    </div>
                </div>

                @if($financialDataVisible)
                    <div class="pd-card">
                        <div class="pd-card-header">
                            <h3 class="pd-card-title">Finans</h3>
                            <p class="pd-card-subtitle">Yetkili kullanıcı görünümü.</p>
                        </div>
                        <div class="pd-card-body pd-order-sticky-financial">
                            <span class="pd-order-sticky-muted">Kalan Bakiye</span>
                            <div class="pd-order-sticky-financial-amount">{{ number_format((float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL') }}</div>
                            <span class="pd-badge pd-badge-{{ $financeCard['badge'] }}" style="width:max-content">{{ $financeCard['status'] }}</span>
                            <a href="{{ $financeCard['primary_url'] }}" class="pd-order-sticky-quick-button">{{ $financeCard['primary_label'] }}</a>
                        </div>
                    </div>
                @endif

                @if($isControlledDepth)
                    <div class="pd-card">
                        <div class="pd-card-header">
                            <h3 class="pd-card-title">Son Faaliyetler</h3>
                            <p class="pd-card-subtitle">Kontrollü Akışta görünür.</p>
                        </div>
                        <div class="pd-card-body pd-order-sticky-recent-list">
                            @forelse($stickyRecentActivities as $historyRow)
                                <div>
                                    <div class="pd-order-sticky-recent-item-title">{{ $historyRow['label'] }}</div>
                                    <div class="pd-order-sticky-recent-item-meta">{{ $historyRow['created_at_label'] }}</div>
                                </div>
                            @empty
                                <div class="pd-order-form-note">Faaliyet kaydı henüz görünmüyor.</div>
                            @endforelse
                        </div>
                    </div>
                @endif

                <div class="pd-order-sticky-responsive-marker" data-sticky-responsive-marker="true"></div>
            </aside>
            </section>
        @else
            <div class="pd-order-layout pd-order-layout--single">
                <div class="pd-order-stack">

        @if($activeOrderTab === 'is-formu')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">İş Formu</h3>
                    <p class="pd-card-subtitle">İş formu kaydı, PDF ve müşteri takip bağlantısı güvenli şekilde burada toplanır.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-grid-3">
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">İş Formu Durumu</div><div class="pd-order-kpi-value">{{ $workForm ? 'Hazır' : 'Henüz oluşmadı' }}</div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">İş Formu No</div><div class="pd-order-kpi-value">{{ $workForm?->work_form_number ?: '-' }}</div></div>
                        <div class="pd-order-kpi"><div class="pd-order-kpi-label">Versiyon</div><div class="pd-order-kpi-value">{{ $workForm?->version ?: '-' }}</div></div>
                    </div>

                    <div class="pd-order-package-actions" style="margin-top:14px;">
                        @if($workForm)
                            <a href="{{ route('admin.work-forms.show', $workForm) }}" class="pd-btn pd-btn-primary">İş Formunu Aç</a>
                        @endif
                        @if($workFormPdfUrl)
                            <a href="{{ $workFormPdfUrl }}" class="pd-btn pd-btn-light">PDF İndir</a>
                        @endif
                        @if($trackingUrl)
                            <a href="{{ $trackingUrl }}" class="pd-btn pd-btn-light">Müşteri Takip Ekranı</a>
                        @endif
                    </div>

                    <table class="pd-order-item-table" style="margin-top:14px;">
                        <thead>
                            <tr>
                                <th>Kalem</th>
                                <th>Ürün</th>
                                <th>Durum</th>
                                <th>İş Formu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($itemRows as $itemRow)
                                <tr>
                                    <td>{{ $itemRow['sequence'] }}</td>
                                    <td>
                                        <div style="font-weight:600;">{{ $itemRow['product_name'] }}</div>
                                        <div class="text-xs" style="color:var(--pd-muted);">{{ $itemRow['product_code'] }}</div>
                                    </td>
                                    <td>{{ $itemRow['operation_status'] }}</td>
                                    <td>{{ $itemRow['work_form_number'] ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-sm" style="color:var(--pd-muted);">Kalem özeti bulunmuyor.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'grafik')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Grafik</h3>
                    <p class="pd-card-subtitle">Grafik hazırlığı, müşteri onayı ve revize görünümü kısa özet olarak izlenir.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-grid-2">
                        @forelse($order->workForms as $workFormRow)
                            @php
                                $graphicSnapshot = is_array($workFormRow->graphic_snapshot) ? $workFormRow->graphic_snapshot : [];
                                $graphicStatusLabel = data_get($graphicSnapshot, 'public_status_label')
                                    ?: data_get($graphicSnapshot, 'status_label')
                                    ?: 'Grafik bekliyor';
                            @endphp
                            <div class="pd-order-list-row">
                                <div>
                                    <div style="font-weight:700;">{{ $workFormRow->work_form_number ?: 'İş Formu' }}</div>
                                    <div style="margin-top:4px;">{{ $graphicStatusLabel }}</div>
                                    <div class="text-xs" style="margin-top:4px; color:var(--pd-muted);">{{ $workFormRow->orderItem?->product_name ?: 'Kalem bilgisi yok' }}</div>
                                </div>
                                <div style="display:grid; gap:8px; justify-items:end;">
                                    <span class="pd-badge pd-badge-amber">Grafik</span>
                                    <a href="{{ route('admin.graphics.show', $workFormRow) }}" class="pd-btn pd-btn-light pd-btn-sm">Grafik Ekranına Git</a>
                                </div>
                            </div>
                        @empty
                            <div class="pd-order-form-note">Grafik kaydı bulunmuyor.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'tedarik')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Tedarik</h3>
                    <p class="pd-card-subtitle">Tedarik ihtiyaçları, kalan miktar ve tedarikçi yönlendirmeleri burada izlenir.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-package-actions" style="margin-bottom:14px;">
                        <a href="{{ route('admin.procurements.index') }}" class="pd-btn pd-btn-light">Tedarik Ekranına Git</a>
                        @if($order->procurements->first())
                            <a href="{{ route('admin.procurements.show', $order->procurements->first()) }}" class="pd-btn pd-btn-primary">Talebi Aç</a>
                        @endif
                    </div>
                    <table class="pd-order-item-table">
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>Tedarik Durumu</th>
                                <th>Kalan Miktar</th>
                                <th>Aksiyon</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($order->procurements as $procurement)
                                <tr>
                                    <td>{{ $procurement->orderItem?->product_name ?: 'Kalem bilgisi yok' }}</td>
                                    <td>{{ $procurement->safeStatusLabel() }}</td>
                                    <td>{{ rtrim(rtrim(number_format((float) $procurement->remaining_quantity, 4, ',', '.'), '0'), ',') }}</td>
                                    <td><a href="{{ route('admin.procurements.show', $procurement) }}" class="pd-btn pd-btn-light pd-btn-sm">Tedarik Kaydını Aç</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-sm" style="color:var(--pd-muted);">Tedarik ihtiyacı görünmüyor.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'uretim')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Üretim</h3>
                    <p class="pd-card-subtitle">Baskı ve üretim satırları, operasyon durumu ve kalite kontrol uyarıları burada görünür.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-package-actions" style="margin-bottom:14px;">
                        <a href="{{ route('admin.productions.index') }}" class="pd-btn pd-btn-light">Üretim Ekranına Git</a>
                        @if($order->printProductions->first())
                            <a href="{{ route('admin.productions.show', $order->printProductions->first()) }}" class="pd-btn pd-btn-primary">Üretim Kaydını Aç</a>
                        @endif
                    </div>
                    <table class="pd-order-item-table">
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>Üretim Durumu</th>
                                <th>Tamamlanan</th>
                                <th>Kalan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($order->printProductions as $production)
                                <tr>
                                    <td>{{ $production->orderItemPrint?->orderItem?->product_name ?: 'Kalem bilgisi yok' }}</td>
                                    <td>{{ $production->safeStatusLabel() }}</td>
                                    <td>{{ rtrim(rtrim(number_format((float) $production->completed_quantity, 4, ',', '.'), '0'), ',') }}</td>
                                    <td>{{ rtrim(rtrim(number_format((float) $production->remaining_quantity, 4, ',', '.'), '0'), ',') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-sm" style="color:var(--pd-muted);">Üretim kaydı görünmüyor.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'teslimat')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Teslimat</h3>
                    <p class="pd-card-subtitle">Teslimata hazırlama, koli planı, etiket ve teslim bilgisi tek operasyon alanında yönetilir.</p>
                </div>
                <div class="pd-card-body">
                    @if(!($deliveryTab['planning_available'] ?? true) && !empty($deliveryTab['planning_notice']))
                        <div class="pd-order-form-note" style="margin-bottom:14px;">{{ $deliveryTab['planning_notice'] }}</div>
                    @endif

                    <div class="pd-order-step-list">
                        @foreach($deliveryTab['steps'] as $step)
                            <div class="pd-order-step-row">
                                <div>
                                    <div style="font-weight:700;">{{ $step['title'] }}</div>
                                    <div class="text-xs" style="margin-top:4px; color:var(--pd-muted);">{{ $step['detail'] }}</div>
                                </div>
                                <span class="pd-badge pd-badge-{{ $step['status_tone'] }}">{{ $step['status_label'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    @if($deliveryTab['completion_note'])
                        <div class="pd-order-form-note" style="margin-top:14px;">{{ $deliveryTab['completion_note'] }}</div>
                    @endif

                    <div class="pd-order-grid-2" style="margin-top:14px;">
                        <div class="pd-card" style="border:none; box-shadow:none; background:#f8fafc;">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Teslimata Hazırla</h3>
                                <p class="pd-card-subtitle">Hazır miktarlar teslimat planı için referans alınır.</p>
                            </div>
                            <div class="pd-card-body">
                                <table class="pd-order-item-table">
                                    <thead>
                                        <tr>
                                            <th>Ürün</th>
                                            <th>Sipariş Miktarı</th>
                                            <th>Hazır</th>
                                            <th>Henüz Hazır Değil</th>
                                            <th>Durum</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($deliveryTab['item_readiness'] as $readiness)
                                            <tr>
                                                <td>
                                                    <div style="font-weight:600;">{{ $readiness['product_name'] }}</div>
                                                    <div class="text-xs" style="color:var(--pd-muted);">{{ $readiness['product_code'] }}</div>
                                                </td>
                                                <td>{{ $readiness['ordered_quantity_label'] }}</td>
                                                <td>{{ $readiness['ready_quantity_label'] }}</td>
                                                <td>{{ $readiness['waiting_quantity_label'] }}</td>
                                                <td><span class="pd-badge pd-badge-{{ $readiness['status_tone'] }}">{{ $readiness['status_label'] }}</span></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="pd-card" style="border:none; box-shadow:none; background:#f8fafc;">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Koli ve Etiket Özeti</h3>
                                <p class="pd-card-subtitle">Koli adedi kadar etiket hazırlanır.</p>
                            </div>
                            <div class="pd-card-body">
                                <div class="pd-order-grid-3">
                                    <div class="pd-order-kpi"><div class="pd-order-kpi-label">Koli Sayısı</div><div class="pd-order-kpi-value">{{ $deliveryTab['package_count'] }}</div></div>
                                    <div class="pd-order-kpi"><div class="pd-order-kpi-label">Etiket Adedi</div><div class="pd-order-kpi-value">{{ $deliveryTab['label_count'] }}</div></div>
                                    <div class="pd-order-kpi"><div class="pd-order-kpi-label">Teslim Durumu</div><div class="pd-order-kpi-value">{{ $deliveryTab['is_delivered'] ? 'Operasyon tamamlandı' : 'Teslim bilgisi bekliyor' }}</div></div>
                                </div>
                                @if($latestLabelBatch)
                                    <div class="pd-order-form-note" style="margin-top:14px;">
                                        <strong>Son etiket partisi:</strong> {{ \App\Models\OrderDeliveryLabelBatch::templateLabels()[$latestLabelBatch->template_type] ?? 'Etiket' }}
                                        · {{ $deliveryTab['label_batches'][0]['page_summary'] ?? '' }}
                                    </div>
                                @endif
                                <div class="pd-order-package-actions" style="margin-top:14px;">
                                    @if($deliveryTab['label_count'] > 0)
                                        <a href="{{ route('admin.orders.delivery-labels.print', ['order' => $order, 'batch' => $latestLabelBatch?->id]) }}" class="pd-btn pd-btn-light" target="_blank">Etiketleri Yazdır</a>
                                    @endif
                                    @if($order->deliveries->first())
                                        <a href="{{ route('admin.deliveries.show', $order->deliveries->first()) }}" class="pd-btn pd-btn-light">Teslimat Kaydını Aç</a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pd-card" style="margin-top:14px; border:none; box-shadow:none; background:#f8fafc;">
                        <div class="pd-card-header">
                            <h3 class="pd-card-title">Koli Planı</h3>
                            <p class="pd-card-subtitle">Bir ürün birden fazla koliye bölünebilir, bir koli içinde birden fazla ürün olabilir.</p>
                        </div>
                        <div class="pd-card-body">
                            <div class="pd-order-form-note">Tek ürün 2 koli, iki ürün tek koli veya çok ürün çok koli senaryoları desteklenir. Boş koli satırı kaydedilmez.</div>

                            @if($deliveryTab['package_rows'] !== [])
                                <div class="pd-order-package-list" style="margin-top:14px;">
                                    @foreach($deliveryTab['package_rows'] as $packageRow)
                                        <div class="pd-order-package-card">
                                            <div class="pd-order-package-toolbar">
                                                <div>
                                                    <div style="font-weight:700;">{{ $packageRow['package_label'] }}</div>
                                                    <div class="text-xs" style="margin-top:4px; color:var(--pd-muted);">{{ $packageRow['package_type_label'] }} · Toplam {{ $packageRow['total_quantity_label'] }}</div>
                                                </div>
                                                <span class="pd-badge pd-badge-{{ $packageRow['status_tone'] }}">{{ $packageRow['status_label'] }}</span>
                                            </div>
                                            <div class="pd-order-package-items">
                                                @foreach($packageRow['items'] as $packageItem)
                                                    <div class="pd-order-list-row" style="padding:8px 10px;">
                                                        <div>{{ $packageItem['product_name'] }} <span class="text-xs" style="color:var(--pd-muted);">{{ $packageItem['product_code'] }}</span></div>
                                                        <div>{{ $packageItem['quantity_label'] }}</div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <form method="POST" action="{{ route('admin.orders.delivery-packages.store', $order) }}" style="margin-top:14px;">
                                @csrf
                                <div class="pd-order-package-builder" data-package-builder>
                                    <div data-package-list>
                                        @php($renderedPackages = max(count(old('packages', [])), max(1, count($deliveryTab['package_rows'] ?? []))))
                                        @for($packageIndex = 0; $packageIndex < $renderedPackages; $packageIndex++)
                                            <div class="pd-order-package-card" data-package-card>
                                                <div class="pd-order-package-toolbar">
                                                    <div style="font-weight:700;">Koli <span data-package-number>{{ $packageIndex + 1 }}</span></div>
                                                    <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-remove-package>Kaldır</button>
                                                </div>
                                                <div class="pd-order-form-grid" style="margin-top:12px;">
                                                    <div>
                                                        <label>Koli Etiketi</label>
                                                        <input type="text" name="packages[{{ $packageIndex }}][package_label]" class="pd-form-control" value="{{ old('packages.' . $packageIndex . '.package_label', $deliveryTab['package_rows'][$packageIndex]['package_label'] ?? '') }}" placeholder="Örn. Koli 1">
                                                    </div>
                                                    <div>
                                                        <label>Koli Tipi</label>
                                                        <select name="packages[{{ $packageIndex }}][package_type]" class="pd-form-control">
                                                            @foreach($deliveryTab['package_type_options'] as $optionValue => $optionLabel)
                                                                <option value="{{ $optionValue }}" @selected(old('packages.' . $packageIndex . '.package_type', 'box') === $optionValue)>{{ $optionLabel }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="full">
                                                        <label>Koli Notu</label>
                                                        <input type="text" name="packages[{{ $packageIndex }}][notes]" class="pd-form-control" value="{{ old('packages.' . $packageIndex . '.notes') }}" placeholder="İsteğe bağlı not">
                                                    </div>
                                                    <div class="full">
                                                        <table class="pd-order-item-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>Ürün</th>
                                                                    <th>Hazır Adet</th>
                                                                    <th>Koliye Yazılacak Adet</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach($deliveryTab['item_readiness'] as $itemIndex => $readiness)
                                                                    <tr>
                                                                        <td>
                                                                            <input type="hidden" name="packages[{{ $packageIndex }}][items][{{ $itemIndex }}][order_item_id]" value="{{ $readiness['order_item_id'] }}">
                                                                            <div style="font-weight:600;">{{ $readiness['product_name'] }}</div>
                                                                            <div class="text-xs" style="color:var(--pd-muted);">{{ $readiness['product_code'] }}</div>
                                                                        </td>
                                                                        <td>{{ $readiness['ready_quantity_label'] }}</td>
                                                                        <td><input type="number" step="0.0001" min="0" name="packages[{{ $packageIndex }}][items][{{ $itemIndex }}][quantity]" class="pd-form-control" value="{{ old('packages.' . $packageIndex . '.items.' . $itemIndex . '.quantity') }}" placeholder="0"></td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        @endfor
                                    </div>
                                    <div class="pd-order-package-actions">
                                        <button type="button" class="pd-btn pd-btn-light" data-add-package>Yeni Koli Ekle</button>
                                        <button type="submit" class="pd-btn pd-btn-primary" @disabled(!($deliveryTab['planning_available'] ?? true))>Koli Planını Kaydet</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="pd-order-grid-2" style="margin-top:14px;">
                        <div class="pd-card" style="border:none; box-shadow:none; background:#f8fafc;">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Etiket Oluştur</h3>
                                <p class="pd-card-subtitle">A4 veya rulo etiket şablonuyla tek seferde baskı için hazırlık yapılır.</p>
                            </div>
                            <div class="pd-card-body">
                                <form method="POST" action="{{ route('admin.orders.delivery-labels.store', $order) }}">
                                    @csrf
                                    <div class="pd-order-form-grid">
                                        <div>
                                            <label>Etiket Şablonu</label>
                                            <select name="template_type" class="pd-form-control" data-label-template>
                                                @foreach($deliveryTab['label_template_options'] as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}" @selected(old('template_type', \App\Models\OrderDeliveryLabelBatch::TEMPLATE_A4_1_4) === $optionValue)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label>Etiket Adedi</label>
                                            <input type="text" class="pd-form-control" value="{{ $deliveryTab['label_count'] }}" readonly>
                                        </div>
                                        <div data-roll-field style="{{ old('template_type') === \App\Models\OrderDeliveryLabelBatch::TEMPLATE_ROLL ? '' : 'display:none;' }}">
                                            <label>Etiket Eni (mm)</label>
                                            <input type="number" step="0.01" min="1" name="roll_width_mm" class="pd-form-control" value="{{ old('roll_width_mm', '100') }}">
                                        </div>
                                        <div data-roll-field style="{{ old('template_type') === \App\Models\OrderDeliveryLabelBatch::TEMPLATE_ROLL ? '' : 'display:none;' }}">
                                            <label>Etiket Boyu (mm)</label>
                                            <input type="number" step="0.01" min="1" name="roll_height_mm" class="pd-form-control" value="{{ old('roll_height_mm', '70') }}">
                                        </div>
                                        <div data-roll-field style="{{ old('template_type') === \App\Models\OrderDeliveryLabelBatch::TEMPLATE_ROLL ? '' : 'display:none;' }}">
                                            <label>Etiket Ara Mesafesi (mm)</label>
                                            <input type="number" step="0.01" min="0" name="roll_gap_mm" class="pd-form-control" value="{{ old('roll_gap_mm', '3') }}">
                                        </div>
                                    </div>
                                    <div class="pd-order-package-actions" style="margin-top:14px;">
                                        <button type="submit" class="pd-btn pd-btn-primary" @disabled(!($deliveryTab['planning_available'] ?? true))>Etiket Partisi Oluştur</button>
                                        @if($latestLabelBatch)
                                            <a href="{{ route('admin.orders.delivery-labels.print', ['order' => $order, 'batch' => $latestLabelBatch->id]) }}" class="pd-btn pd-btn-light" target="_blank">Etiketleri Aç</a>
                                        @elseif(!($deliveryTab['planning_available'] ?? true))
                                            <a href="{{ route('admin.orders.delivery-labels.print', ['order' => $order]) }}" class="pd-btn pd-btn-light" target="_blank">Etiket Görünümünü Aç</a>
                                        @endif
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="pd-card" style="border:none; box-shadow:none; background:#f8fafc;">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Teslim Bilgisi</h3>
                                <p class="pd-card-subtitle">Teslim yöntemi, kişi, takip ve teslim notu burada kaydedilir.</p>
                            </div>
                            <div class="pd-card-body">
                                <form method="POST" action="{{ route('admin.orders.delivery-info.update', $order) }}">
                                    @csrf
                                    <div class="pd-order-form-grid">
                                        <div>
                                            <label>Teslim Yöntemi</label>
                                            <select name="delivery_method" class="pd-form-control">
                                                <option value="">Seçin</option>
                                                @foreach($deliveryTab['method_options'] as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}" @selected(old('delivery_method', $deliveryInfo['delivery_method'] ?? '') === $optionValue)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label>Ticari Teslim Tipi</label>
                                            <input type="text" name="delivery_type" class="pd-form-control" value="{{ old('delivery_type', $order->delivery_type) }}" placeholder="Kargo / Ambar / Kurye">
                                        </div>
                                        <div>
                                            <label>Teslim Alan Kişi</label>
                                            <input type="text" name="recipient_name" class="pd-form-control" value="{{ old('recipient_name', $deliveryInfo['recipient_name'] ?? '') }}">
                                        </div>
                                        <div>
                                            <label>Telefon</label>
                                            <input type="text" name="recipient_phone" class="pd-form-control" value="{{ old('recipient_phone', $deliveryInfo['recipient_phone'] ?? '') }}">
                                        </div>
                                        <div>
                                            <label>Kargo / Ambar Adı</label>
                                            <input type="text" name="carrier_name" class="pd-form-control" value="{{ old('carrier_name', $deliveryInfo['carrier_name'] ?? '') }}">
                                        </div>
                                        <div>
                                            <label>Takip No</label>
                                            <input type="text" name="tracking_number" class="pd-form-control" value="{{ old('tracking_number', $deliveryInfo['tracking_number'] ?? '') }}">
                                        </div>
                                        <div>
                                            <label>Belge No</label>
                                            <input type="text" name="delivery_document_no" class="pd-form-control" value="{{ old('delivery_document_no', $deliveryInfo['delivery_document_no'] ?? '') }}">
                                        </div>
                                        <div class="full">
                                            <label>Teslim Notu</label>
                                            <textarea name="delivery_note" class="pd-form-control" rows="3">{{ old('delivery_note', $deliveryInfo['delivery_note'] ?? '') }}</textarea>
                                        </div>
                                    </div>
                                    <div class="pd-order-package-actions" style="margin-top:14px;">
                                        <button type="submit" class="pd-btn pd-btn-primary">Teslim Bilgisini Kaydet</button>
                                    </div>
                                </form>

                                <form method="POST" action="{{ route('admin.orders.delivery.complete', $order) }}" style="margin-top:14px;" onsubmit="return confirm('Teslimat tamamlandığında sipariş operasyon akışından çıkarılır. Finans bakiyesi açıksa takip devam eder. Devam etmek istiyor musunuz?');">
                                    @csrf
                                    <input type="hidden" name="delivery_method" value="{{ $deliveryInfo['delivery_method'] ?? '' }}">
                                    <input type="hidden" name="recipient_name" value="{{ $deliveryInfo['recipient_name'] ?? '' }}">
                                    <input type="hidden" name="delivery_document_no" value="{{ $deliveryInfo['delivery_document_no'] ?? '' }}">
                                    <input type="hidden" name="tracking_number" value="{{ $deliveryInfo['tracking_number'] ?? '' }}">
                                    <input type="hidden" name="carrier_name" value="{{ $deliveryInfo['carrier_name'] ?? '' }}">
                                    <input type="hidden" name="delivery_note" value="{{ $deliveryInfo['delivery_note'] ?? '' }}">
                                    <button type="submit" class="pd-btn pd-btn-success">Teslim Edildi</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'finans')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Finans</h3>
                    <p class="pd-card-subtitle">Müşteri borcu, tahsilat ve karşı borçlar finans özetinden izlenir.</p>
                </div>
                <div class="pd-card-body">
                    @if($financialDataVisible && $financeOverview)
                        <div class="pd-order-grid-4">
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Müşteri Borcu</div>
                                <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.debit_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.debit_amount', 0)])</div>
                            </div>
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Tahsil Edilen</div>
                                <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.collected_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.collected_amount', 0)])</div>
                            </div>
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Kalan Bakiye</div>
                                <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0)])</div>
                            </div>
                            <div class="pd-order-kpi">
                                <div class="pd-order-kpi-label">Finans Durumu</div>
                                <div class="pd-order-kpi-value">{{ data_get($financeOverview, 'overall.status_label', 'Finans açık') }}</div>
                            </div>
                        </div>
                        <div class="pd-order-package-actions" style="margin-top:14px;">
                            <a href="{{ route('admin.finance.show', $order) }}" class="pd-btn pd-btn-primary">Finans Özeti</a>
                            @if($customerCurrentAccount)
                                <a href="{{ route('admin.current-accounts.transactions.index', $customerCurrentAccount) }}" class="pd-btn pd-btn-light">Cari Ekstre</a>
                            @endif
                        </div>
                    @else
                        <div class="pd-order-form-note">Finans tutarları yalnız yetkili kullanıcıya gösterilir.</div>
                    @endif
                </div>
            </div>
        @endif

        @if($activeOrderTab === 'gecmis')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Geçmiş</h3>
                    <p class="pd-card-subtitle">Siparişin grafik, tedarik, üretim, teslimat ve iş formu aksiyonları zaman sırasıyla görünür.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-history-list">
                        @forelse($historyRows as $historyRow)
                            <div class="pd-order-history-row">
                                <div class="text-xs" style="color:var(--pd-muted);">{{ $historyRow['created_at_label'] }}</div>
                                <div>
                                    <div style="font-weight:700;">{{ $historyRow['label'] }}</div>
                                    <div style="margin-top:4px;">{{ $historyRow['note'] }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="pd-order-form-note">Geçmiş kaydı henüz görünmüyor.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

                </div>
            </div>
        @endif

</div>

<template id="pd-order-package-template">
    <div class="pd-order-package-card" data-package-card>
        <div class="pd-order-package-toolbar">
            <div style="font-weight:700;">Koli <span data-package-number></span></div>
            <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-remove-package>Kaldır</button>
        </div>
    </div>
</template>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const packageBuilder = document.querySelector('[data-package-builder]');
        const packageList = packageBuilder?.querySelector('[data-package-list]');
        const addPackageButton = packageBuilder?.querySelector('[data-add-package]');

        const renumberPackages = function () {
            if (!packageList) {
                return;
            }

            [...packageList.querySelectorAll('[data-package-card]')].forEach(function (card, index) {
                const packageNumber = index + 1;
                const numberTarget = card.querySelector('[data-package-number]');
                if (numberTarget) {
                    numberTarget.textContent = String(packageNumber);
                }

                [...card.querySelectorAll('input, select, textarea')].forEach(function (field) {
                    const name = field.getAttribute('name');
                    if (!name) {
                        return;
                    }

                    field.setAttribute('name', name.replace(/packages\[\d+\]/, 'packages[' + index + ']'));
                });
            });
        };

        packageList?.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-remove-package]');
            if (!removeButton) {
                return;
            }

            const cards = packageList.querySelectorAll('[data-package-card]');
            if (cards.length <= 1) {
                return;
            }

            removeButton.closest('[data-package-card]')?.remove();
            renumberPackages();
        });

        addPackageButton?.addEventListener('click', function () {
            if (!packageList) {
                return;
            }

            const cards = packageList.querySelectorAll('[data-package-card]');
            const clone = cards[cards.length - 1]?.cloneNode(true);

            if (!clone) {
                return;
            }

            clone.querySelectorAll('input').forEach(function (field) {
                if (field.type === 'hidden') {
                    return;
                }

                field.value = '';
            });

            clone.querySelectorAll('textarea').forEach(function (field) {
                field.value = '';
            });

            clone.querySelectorAll('select').forEach(function (field) {
                field.selectedIndex = 0;
            });

            packageList.appendChild(clone);
            renumberPackages();
        });

        const templateSelect = document.querySelector('[data-label-template]');
        const rollFields = document.querySelectorAll('[data-roll-field]');

        const toggleRollFields = function () {
            const isRoll = templateSelect?.value === 'roll';
            rollFields.forEach(function (field) {
                field.style.display = isRoll ? '' : 'none';
            });
        };

        templateSelect?.addEventListener('change', toggleRollFields);
        toggleRollFields();
        renumberPackages();
    });
</script>
@endsection







