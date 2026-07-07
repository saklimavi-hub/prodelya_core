@php
    use App\Models\OrderItemPrintProduction;

    $snapshot = is_array($production->production_snapshot) ? $production->production_snapshot : [];
    $plannedQuantity = (float) $production->planned_quantity;
    $completedQuantity = (float) $production->completed_quantity;
    $remainingQuantity = (float) $production->remaining_quantity;
    $progressPercent = $plannedQuantity > 0 ? min(100, max(0, round(($completedQuantity / $plannedQuantity) * 100))) : 0;
    $formattedPlannedQuantity = OrderItemPrintProduction::formatDisplayQuantity($plannedQuantity);
    $formattedCompletedQuantity = OrderItemPrintProduction::formatDisplayQuantity($completedQuantity);
    $formattedRemainingQuantity = OrderItemPrintProduction::formatDisplayQuantity($remainingQuantity);
    $unit = $snapshot['unit'] ?? ($production->orderItem?->unit ?: 'Adet');
    $customerName = $production->order?->customer?->legal_name ?: 'Belirtilmemiş';
    $productName = $snapshot['product_name'] ?? ($production->orderItem?->product_name ?: 'Belirtilmemiş');
    $productCode = $snapshot['product_code'] ?? ($production->orderItem?->product_code ?: null);
    $previewImage = data_get($snapshot, 'final_graphic.preview_url') ?: data_get($snapshot, 'product_image_url');
    $lastHistory = $history->first();
    $setupItems = collect(data_get($snapshot, 'setup_summary.items', []))
        ->filter(fn (array $item) => filled($item['setup_type_label'] ?? null))
        ->values();
    $setupSummaryLabel = data_get($snapshot, 'setup_summary_label');
    $issueSummary = $production->isProblematic()
        ? 'Sorun kaydı var'
        : ($production->issue_note ? 'Not girildi' : 'Sorun görünmüyor');
    $photoCount = $production->workForm?->productionPhotos()->count() ?? 0;
    $preparationSafetyNote = (
        (($snapshot['setup_required'] ?? false) && !($snapshot['setup_ready'] ?? true))
        || (($snapshot['preparation_required'] ?? false) && !($snapshot['preparation_ready'] ?? true))
    )
        ? 'Bu baskı için gerekli ara eleman hazır olmadan baskıya başlanmaz.'
        : null;
    $importantNotes = collect([
        ...((array) ($snapshot['start_blockers'] ?? [])),
        $preparationSafetyNote,
        $snapshot['status_help'] ?? null,
        $production->production_note,
        $production->issue_note,
    ])
        ->filter(fn ($note) => filled($note))
        ->map(fn ($note) => trim((string) $note))
        ->unique()
        ->values()
        ->all();
    $readinessSteps = [
        'Grafik Hazır' => (bool) ($snapshot['graphic_ready'] ?? false),
        'Tedarik Hazır' => (bool) ($snapshot['procurement_ready'] ?? false),
        'Üretimde' => in_array($production->production_status, [
            OrderItemPrintProduction::STATUS_INTERNAL,
            OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
            OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
            OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR,
        ], true),
        'Kalite Kontrol' => $production->production_status === OrderItemPrintProduction::STATUS_QUALITY_CONTROL,
        'Teslimata Hazır' => $production->production_status === OrderItemPrintProduction::STATUS_COMPLETED,
    ];
@endphp

<div class="prd-stack">
    <section class="prd-card">
        <h2 class="prd-section-title">Genel Özet</h2>
        <div class="prd-summary-band">
            <div class="prd-info-card prd-summary-hero">
                <div style="width:112px; height:112px; border-radius:10px; overflow:hidden; background:#f3f6fb; display:flex; align-items:center; justify-content:center; border:1px solid #e7edf4;">
                    @if($previewImage)
                        <img src="{{ $previewImage }}" alt="{{ $productName }}" style="width:100%; height:100%; object-fit:cover;">
                    @else
                        <span style="color:#98a2b3; font-size:12px; font-weight:700;">Görsel Yok</span>
                    @endif
                </div>
                <div>
                    <div class="prd-info-label">Ürün</div>
                    <div class="prd-info-value">{{ $productName }}</div>
                    <div style="margin-top:8px; color:#667085; font-size:13px; line-height:1.5;">
                        @if(filled($productCode))
                            <div>Ürün Kodu: {{ $productCode }}</div>
                        @endif
                        <div>Müşteri: {{ $customerName }}</div>
                        <div>Sipariş No: {{ $snapshot['order_number'] ?? ($production->order?->document_number ?: 'Belirtilmedi') }}</div>
                        <div>İş Formu No: {{ $snapshot['work_form_number'] ?? 'Belirtilmedi' }}</div>
                    </div>
                </div>
            </div>

            <div class="prd-summary-stat-grid">
                <div class="prd-summary-stat">
                    <span class="prd-summary-stat-label">Üretim Türü</span>
                    <div class="prd-summary-stat-value" style="font-size:16px;">{{ $productionTypeLabel ?? $production->safeProductionTypeLabel() }}</div>
                    <div class="prd-summary-stat-meta">İş akışı {{ $production->safeStatusLabel() }} durumunda.</div>
                </div>
                <div class="prd-summary-stat">
                    <span class="prd-summary-stat-label">Planlanan</span>
                    <div class="prd-summary-stat-value">{{ $formattedPlannedQuantity }}</div>
                    <div class="prd-summary-stat-meta">{{ $unit }}</div>
                </div>
                <div class="prd-summary-stat">
                    <span class="prd-summary-stat-label">Üretilen Adet</span>
                    <div class="prd-summary-stat-value" style="color:#15803d;">{{ $formattedCompletedQuantity }}</div>
                    <div class="prd-summary-stat-meta">{{ $unit }}</div>
                </div>
                <div class="prd-summary-stat">
                    <span class="prd-summary-stat-label">Kalan Adet</span>
                    <div class="prd-summary-stat-value" style="color:#c2410c;">{{ $formattedRemainingQuantity }}</div>
                    <div class="prd-summary-stat-meta">{{ $unit }}</div>
                </div>
                <div class="prd-summary-stat" style="grid-column: 1 / -1;">
                    <span class="prd-summary-stat-label">Üretim İlerlemesi</span>
                    <div class="prd-summary-stat-value">%{{ $progressPercent }}</div>
                    <div style="margin-top: 10px;" class="prd-progress-track">
                        <div class="prd-progress-fill" style="width: {{ $progressPercent }}%;"></div>
                    </div>
                    <div style="margin-top:10px; color:#667085; font-size:12px; display:flex; justify-content:space-between; gap:10px;">
                        <span>{{ $formattedCompletedQuantity }} / {{ $formattedPlannedQuantity }} {{ $unit }}</span>
                        <strong style="color:#182230;">{{ $production->safeStatusLabel() }}</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="prd-snapshot-grid" style="margin-top:14px;">
            <div class="prd-snapshot-item">
                <span class="prd-snapshot-label">Üretim No</span>
                <div class="prd-snapshot-value">{{ $production->id }}</div>
            </div>
            <div class="prd-snapshot-item">
                <span class="prd-snapshot-label">Sipariş No</span>
                <div class="prd-snapshot-value">{{ $snapshot['order_number'] ?? ($production->order?->document_number ?: 'Belirtilmedi') }}</div>
            </div>
            <div class="prd-snapshot-item">
                <span class="prd-snapshot-label">İş Formu No</span>
                <div class="prd-snapshot-value">{{ $snapshot['work_form_number'] ?? 'Belirtilmedi' }}</div>
            </div>
            <div class="prd-snapshot-item">
                <span class="prd-snapshot-label">Müşteri</span>
                <div class="prd-snapshot-value">{{ $customerName }}</div>
            </div>
            <div class="prd-snapshot-item">
                <span class="prd-snapshot-label">Durum</span>
                <div class="prd-snapshot-value">{{ $production->safeStatusLabel() }}</div>
            </div>
        </div>
    </section>

    <section class="prd-card">
        <h2 class="prd-section-title">Üretim Durumu Adımları</h2>
        <div class="prd-grid-4">
            @foreach($readinessSteps as $label => $done)
                <div class="prd-info-card">
                    <span class="prd-info-label">{{ $label }}</span>
                    <div class="prd-info-value" style="font-size:14px; color:{{ $done ? '#15803d' : '#667085' }};">
                        {{ $done ? 'Hazır' : 'Bekliyor' }}
                    </div>
                </div>
            @endforeach
            <div class="prd-info-card">
                <span class="prd-info-label">İlerleme</span>
                <div class="prd-info-value">%{{ $progressPercent }}</div>
            </div>
        </div>
    </section>

    <section class="prd-card">
        <h2 class="prd-section-title">Hızlı Bakış</h2>
        <div class="prd-grid-4">
            <div class="prd-info-card">
                <span class="prd-info-label">Grafik Durumu</span>
                <div class="prd-info-value">{{ ($snapshot['graphic_status_label'] ?? null) ?: 'Bekleniyor' }}</div>
            </div>
            <div class="prd-info-card">
                <span class="prd-info-label">Tedarik Durumu</span>
                <div class="prd-info-value">{{ ($snapshot['procurement_status_label'] ?? null) ?: 'Bekleniyor' }}</div>
            </div>
            <div class="prd-info-card">
                <span class="prd-info-label">Hazırlık Durumu</span>
                <div class="prd-info-value">{{ $setupSummaryLabel ?: 'Hazırlık gerekmiyor' }}</div>
            </div>
            <div class="prd-info-card">
                <span class="prd-info-label">Fotoğraf Sayısı</span>
                <div class="prd-info-value">{{ $photoCount }}</div>
            </div>
        </div>
    </section>

    @if($setupItems->isNotEmpty())
        <section class="prd-card">
            <h2 class="prd-section-title">Hazırlık / Ara Eleman</h2>
            <div class="prd-grid-4">
                <div class="prd-info-card">
                    <span class="prd-info-label">Hazırlık Durumu</span>
                    <div class="prd-info-value">{{ $setupSummaryLabel ?: 'Hazırlık planlandı' }}</div>
                </div>
                @foreach($setupItems as $setupItem)
                    <div class="prd-info-card">
                        <span class="prd-info-label">{{ $setupItem['setup_type_label'] }}</span>
                        <div class="prd-info-value">{{ $setupItem['status_label'] ?? 'Bekliyor' }}</div>
                        <div style="margin-top:8px; color:#667085; font-size:12px; line-height:1.5;">
                            <div>{{ $setupItem['assigned_company_name'] ?: 'Hazırlık ataması bekleniyor' }}</div>
                            @if(!empty($setupItem['note']))
                                <div>Not: {{ $setupItem['note'] }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="prd-grid-3">
        <section class="prd-card">
            <h2 class="prd-section-title">Üretim Bilgileri</h2>
            <div class="prd-side-rows">
                <div class="prd-side-row"><span>Üretim Başlangıcı</span><strong>{{ optional($production->started_at)->format('d.m.Y H:i') ?: 'Henüz başlamadı' }}</strong></div>
                <div class="prd-side-row"><span>Planlanan Bitiş</span><strong>{{ optional($production->completed_at)->format('d.m.Y H:i') ?: 'Henüz tamamlanmadı' }}</strong></div>
                <div class="prd-side-row"><span>Son İşlem</span><strong>{{ $lastHistory ? ((string) ($lastHistory->note ?: 'İşlem kaydı var')) : 'Henüz işlem yok' }}</strong></div>
                <div class="prd-side-row"><span>Son Güncelleme</span><strong>{{ optional($production->updated_at)->format('d.m.Y H:i') ?: '-' }}</strong></div>
                <div class="prd-side-row"><span>Sorumlu Operatör</span><strong>{{ $production->assignedUser?->name ?: 'Planlanmadı' }}</strong></div>
            </div>
        </section>

        <section class="prd-card">
            <h2 class="prd-section-title">Önemli Notlar</h2>
            @if($importantNotes !== [])
                <ul class="prd-note-list">
                    @foreach(array_slice($importantNotes, 0, 4) as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            @else
                <div class="prd-empty">Henüz önemli not eklenmedi.</div>
            @endif
        </section>

        <section class="prd-card">
            <h2 class="prd-section-title">Sıradaki İşlem</h2>
            <div class="prd-soft-message">
                {{ $nextActionLabel }}
                @if(($snapshot['status_help'] ?? null))
                    <div style="margin-top:8px;">{{ $snapshot['status_help'] }}</div>
                @endif
            </div>
            <div class="prd-form-actions">
                <a href="{{ $tabUrl('islemler') }}" class="btn btn-sm btn-primary">Durumu Güncelle</a>
                <a href="{{ $tabUrl('fotograflar') }}" class="btn btn-sm btn-outline-primary">Fotoğraf Ekle</a>
            </div>
        </section>
    </div>
</div>
