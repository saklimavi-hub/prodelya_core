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
    $workForm = $order->workForms->first();
    $workFormPdfUrl = $workForm ? route('admin.work-forms.pdf', $workForm) : null;
    $trackingUrl = ($workForm && filled($workForm->public_tracking_token))
        ? route('admin.orders.tracking.open', ['order' => $order->id, 'workForm' => $workForm->id])
        : null;
    $sourceQuoteUrl = $order->sourceQuote ? route('admin.promotion-quotes.show', $order->sourceQuote) : null;
    $historyRows = $order->workForms
        ->flatMap(fn ($form) => $form->activityLogs)
        ->sortByDesc('created_at')
        ->values();
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
    $flowCards = [$graphicCard, $procurementCard, $productionCard, $deliveryCard, $financeCard];
    $priorityFlowCard = collect($flowCards)
        ->first(fn (array $card): bool => in_array($card['badge'], ['red', 'amber', 'blue', 'orange'], true))
        ?: $flowCards[0];
    $helperActions = collect([
        $workForm ? ['label' => 'İş Formunu Aç', 'url' => route('admin.work-forms.show', $workForm)] : null,
        $workFormPdfUrl ? ['label' => 'PDF İndir', 'url' => $workFormPdfUrl] : null,
        $financialDataVisible ? ['label' => 'Ödeme Al', 'url' => route('admin.finance.show', $order)] : null,
    ])->filter()->take(2)->values();
    $quickLinks = collect([
        ['label' => 'Cari Kart', 'url' => $customerCardUrl],
        $trackingUrl ? ['label' => 'Müşteri Takip Ekranı', 'url' => $trackingUrl] : null,
        $sourceQuoteUrl ? ['label' => 'Teklif Kaydı', 'url' => $sourceQuoteUrl] : null,
        ['label' => 'Teslimat Sekmesi', 'url' => route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat'])],
    ])->filter()->take(3)->values();
    $warnings = collect($flowCards)->pluck('warning')->filter()->take(3)->values();
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

<div class="pd-order-layout">
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

        @if($activeOrderTab === 'genel')
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Genel Özet</h3>
                    <p class="pd-card-subtitle">Siparişin genel durumu, kalem özeti ve operasyon akışı tek merkezde toplanır.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-order-kpi-strip">
                        <div class="pd-order-kpi">
                            <div class="pd-order-kpi-label">Genel Durum</div>
                            <div class="pd-order-kpi-value"><span class="pd-badge pd-badge-{{ $generalBadge }}">{{ $orderStatusLabel }}</span></div>
                        </div>
                        <div class="pd-order-kpi">
                            <div class="pd-order-kpi-label">Teslim Tarihi</div>
                            <div class="pd-order-kpi-value">{{ $overview['delivery_date_label'] ?? '-' }}</div>
                        </div>
                        <div class="pd-order-kpi">
                            <div class="pd-order-kpi-label">Kalan Gün</div>
                            <div class="pd-order-kpi-value">{{ $remainingDaysLabel }}</div>
                        </div>
                        <div class="pd-order-kpi">
                            <div class="pd-order-kpi-label">{{ $financialDataVisible ? 'Açık Bakiye' : 'Ödeme Durumu' }}</div>
                            <div class="pd-order-kpi-value">
                                @if($financialDataVisible)
                                    @include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0), 2, ',', '.') . ' ' . ($order->currency ?: 'TL'), 'amount' => (float) data_get($financeOverview, 'customer_receivable.remaining_amount', 0)])
                                @else
                                    {{ $paymentStatusLabel }}
                                @endif
                            </div>
                        </div>
                        <div class="pd-order-kpi">
                            <div class="pd-order-kpi-label">Sipariş Ailesi / Belge Tipi</div>
                            <div class="pd-order-kpi-value">{{ $orderFamilyLabel }} · {{ $documentTypeLabel }}</div>
                        </div>
                    </div>

                    <div class="pd-order-grid-2" style="margin-top:14px;">
                        <div class="pd-card pd-order-subcard">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Sipariş Özeti</h3>
                                <p class="pd-card-subtitle">Sipariş kimliği, belge tipi ve teslim bilgisi kısa görünümde toplanır.</p>
                            </div>
                            <div class="pd-card-body">
                                <div class="pd-order-summary-grid">
                                    <div class="pd-order-summary-cell"><span>Sipariş No</span><strong>{{ $order->document_number ?: '-' }}</strong></div>
                                    <div class="pd-order-summary-cell"><span>Müşteri</span><strong>{{ $order->customer?->legal_name ?: 'Müşteri bilgisi yok' }}</strong></div>
                                    <div class="pd-order-summary-cell"><span>Belge / Sipariş Durumu</span><strong>{{ $documentTypeLabel }} · {{ $orderStatusLabel }}</strong></div>
                                    <div class="pd-order-summary-cell"><span>Sipariş Ailesi</span><strong>{{ $orderFamilyLabel }}</strong></div>
                                    <div class="pd-order-summary-cell"><span>Teslim Tipi</span><strong>{{ $order->delivery_type ?: 'Belirtilmedi' }}</strong></div>
                                    <div class="pd-order-summary-cell"><span>Yetkili</span><strong>{{ $order->customer?->contact_name ?: 'Belirtilmedi' }}</strong></div>
                                    <div class="pd-order-summary-cell pd-order-summary-cell-full"><span>Kısa Not</span><strong>{{ $order->notes ?: 'Sipariş için ek not girilmemiş.' }}</strong></div>
                                </div>
                            </div>
                        </div>

                        <div class="pd-card pd-order-subcard">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Sipariş Kalemleri</h3>
                                <p class="pd-card-subtitle">Ürün, baskı ve operasyon bilgileri kompakt biçimde birlikte gösterilir.</p>
                            </div>
                            <div class="pd-card-body">
                                <table class="pd-order-item-table">
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
                                                <td><strong>#{{ $itemRow['sequence'] }}</strong></td>
                                                <td>
                                                    <div class="pd-order-item-name">{{ $itemRow['product_name'] }}</div>
                                                    @if(!empty($itemRow['prints']))
                                                        <div class="pd-order-item-meta">
                                                            @foreach($itemRow['prints'] as $printRow)
                                                                <div>{{ $printRow['print_type'] ?: 'Baskı' }} · {{ $printRow['print_option'] ?: 'Detay yok' }} · {{ $printRow['production_status'] }}</div>
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

                        @if($financialDataVisible && $financeOverview)
                            <div class="pd-card pd-order-subcard">
                                <div class="pd-card-header">
                                    <h3 class="pd-card-title">Finans Özeti</h3>
                                    <p class="pd-card-subtitle">Müşteri borcu, tahsilat ve karşı borç görünümü sipariş merkezinde kısa biçimde izlenir.</p>
                                </div>
                                <div class="pd-card-body">
                                    <div class="pd-order-grid-2">
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
                                            <div class="pd-order-kpi-label">Karşı Borçlar</div>
                                            <div class="pd-order-kpi-value">@include('admin.current-accounts._money-display', ['label' => number_format((float) data_get($financeOverview, 'supplier_debts.total_debt', 0) + (float) data_get($financeOverview, 'subcontractor_debts.total_debt', 0), 2, ',', '.') . ' TL', 'amount' => (float) data_get($financeOverview, 'supplier_debts.total_debt', 0) + (float) data_get($financeOverview, 'subcontractor_debts.total_debt', 0)])</div>
                                        </div>
                                    </div>
                                    <div class="pd-order-package-actions" style="margin-top:14px;">
                                        <a href="{{ route('admin.finance.show', $order) }}" class="pd-btn pd-btn-primary">Finans Özeti</a>
                                        @if($customerCurrentAccount)
                                            <a href="{{ route('admin.current-accounts.transactions.index', $customerCurrentAccount) }}" class="pd-btn pd-btn-light">Cari Hareketler</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="pd-card pd-order-flow-card-shell" style="margin-top:14px;">
                            <div class="pd-card-header">
                                <h3 class="pd-card-title">Sipariş Akışı</h3>
                            <p class="pd-card-subtitle">Bu alan siparişin grafik, tedarik, üretim, teslimat ve finans sürecini tek ekranda takip etmek için kullanılır.</p>
                        </div>
                        <div class="pd-card-body">
                            <div class="pd-order-flow-grid">
                                @foreach($flowCards as $flowCard)
                                    <div class="pd-order-flow-card">
                                        <div class="pd-order-flow-head">
                                            <div>
                                                <div class="pd-order-flow-title">{{ $flowCard['title'] }}</div>
                                                <div class="pd-order-flow-text">{{ $flowCard['summary'] }}</div>
                                            </div>
                                            <span class="pd-badge pd-badge-{{ $flowCard['badge'] }}">{{ $flowCard['status'] }}</span>
                                        </div>
                                        <div class="pd-order-flow-meta">{{ $flowCard['meta'] }}</div>
                                        @if($flowCard['warning'])
                                            <div class="pd-order-flow-warning">{{ $flowCard['warning'] }}</div>
                                        @endif
                                        <div class="pd-order-flow-actions">
                                            <a href="{{ $flowCard['primary_url'] }}" class="pd-btn pd-btn-light pd-btn-sm">{{ $flowCard['primary_label'] }}</a>
                                            <a href="{{ $flowCard['secondary_url'] }}" class="pd-order-inline-link">{{ $flowCard['secondary_label'] }}</a>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="pd-order-form-note" style="margin-top:14px;">
                        Operasyon notu: Teslimat tamamlandığında sipariş operasyon akışından çıkarılabilir. Finans açık ise cari ve tahsilat takibi ilgili ekranlarda sürer.
                    </div>
                </div>
            </div>
        @endif

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
                                <div class="text-xs" style="color:var(--pd-muted);">{{ optional($historyRow->created_at)->format('d.m.Y H:i') ?: '-' }}</div>
                                <div>
                                    <div style="font-weight:700;">{{ str_replace('_', ' ', \Illuminate\Support\Str::headline($historyRow->action_type)) }}</div>
                                    <div style="margin-top:4px;">{{ $historyRow->note ?: 'İşlem kaydı' }}</div>
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

    <aside class="pd-order-summary-panel">
        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Kısa Özet</h3>
                <p class="pd-card-subtitle">Siparişin kısa görünümü ve kontrollü hızlı aksiyon alanı</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-order-mini-list">
                    <div class="pd-order-list-row">
                        <div>
                            <div class="text-xs" style="color:var(--pd-muted);">Müşteri</div>
                            <div style="margin-top:6px; font-weight:700;">{{ $order->customer?->legal_name ?: 'Müşteri bilgisi yok' }}</div>
                        </div>
                        <span class="pd-badge pd-badge-{{ $generalBadge }}">{{ $orderStatusLabel }}</span>
                    </div>
                    <div class="pd-order-list-row">
                        <div>
                            <div class="text-xs" style="color:var(--pd-muted);">Sipariş Ailesi</div>
                            <div style="margin-top:6px; font-weight:700;">{{ $orderFamilyLabel }}</div>
                        </div>
                        <span class="pd-badge pd-badge-{{ $operationBadge }}">{{ $documentTypeLabel }}</span>
                    </div>
                    <div class="pd-order-list-row">
                        <div>
                            <div class="text-xs" style="color:var(--pd-muted);">Teslimat</div>
                            <div style="margin-top:6px; font-weight:700;">{{ $deliveryInfo['summary'] ?? 'Teslim bilgisi yok' }}</div>
                        </div>
                        <span class="pd-badge pd-badge-{{ $deliveryTab['is_delivered'] ? 'green' : 'gray' }}">{{ $deliveryTab['is_delivered'] ? 'Tamamlandı' : 'Bekliyor' }}</span>
                    </div>
                </div>

                <div class="pd-order-form-note" style="margin-top:14px;">
                    <strong>Sıradaki İşlem:</strong> {{ $overview['next_action_label'] ?? 'Siparişi incele' }}
                </div>

                <div class="pd-order-package-actions" style="margin-top:14px;">
                    <a href="{{ $priorityFlowCard['primary_url'] }}" class="pd-btn pd-btn-primary">{{ $priorityFlowCard['primary_label'] }}</a>
                    @foreach($helperActions as $helperAction)
                        <a href="{{ $helperAction['url'] }}" class="pd-btn pd-btn-light">{{ $helperAction['label'] }}</a>
                    @endforeach
                </div>

                @if($canCreateQuoteDraft)
                    <div class="pd-order-mini-list" style="margin-top:14px;">
                        <div class="text-xs" style="color:var(--pd-muted); font-weight:700;">Yeni Taslak Oluştur</div>
                        <div class="pd-order-form-note" style="margin-top:8px;">
                            Bu aksiyonlar mevcut siparişi değiştirmez. Önce yeni teklif taslağı oluşur, sonra normal teklif akışıyla ilerlenir.
                        </div>
                        <div class="pd-order-package-actions" style="margin-top:12px;">
                            <form method="POST" action="{{ route('admin.orders.revision-draft.store', $order) }}">
                                @csrf
                                <button type="submit" class="pd-btn pd-btn-light">Revizyon Oluştur</button>
                            </form>
                            <form method="POST" action="{{ route('admin.orders.repeat-order-draft.store', $order) }}">
                                @csrf
                                <button type="submit" class="pd-btn pd-btn-light">Tekrar Sipariş Oluştur</button>
                            </form>
                        </div>
                    </div>
                @endif

                @if($warnings->isNotEmpty())
                    <div class="pd-order-mini-list" style="margin-top:14px;">
                        <div class="text-xs" style="color:var(--pd-muted); font-weight:700;">Uyarılar</div>
                        @foreach($warnings as $warningText)
                            <div class="pd-order-flow-warning">{{ $warningText }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="pd-order-mini-list" style="margin-top:14px;">
                    <div class="text-xs" style="color:var(--pd-muted); font-weight:700;">Hızlı Bağlantılar</div>
                    @foreach($quickLinks as $quickLink)
                        <a href="{{ $quickLink['url'] }}" class="pd-order-inline-link">{{ $quickLink['label'] }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </aside>
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
