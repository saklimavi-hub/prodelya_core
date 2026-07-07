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
    $isExternal = (bool) (($isExternalProduction ?? false) || ($isSubcontractedProduction ?? false));
    $setupItems = collect(data_get($snapshot, 'setup_summary.items', []))
        ->filter(fn (array $item) => filled($item['setup_type_label'] ?? null))
        ->values();
    $fasonFirma = $production->productionCompany;
    $matchedAccount = $matchedCurrentAccount ?? null;
    $unit = $snapshot['unit'] ?? ($production->orderItem?->unit ?: 'Adet');
    $unitCost = $canViewFinancialData && $production->subcontractor_cost !== null && $plannedQuantity > 0
        ? round(((float) $production->subcontractor_cost) / $plannedQuantity, 2)
        : null;
    $externalHistory = $history
        ->filter(fn ($log) => in_array((string) $log->action_type, [
            'production_assigned_external',
            'production_sent_to_subcontractor',
            'production_returned_from_subcontractor',
            'production_partially_completed',
            'production_completed',
            'production_issue_reported',
        ], true))
        ->take(5)
        ->values();
    $actionLabels = [
        'production_assigned_external' => 'Fason ataması yapıldı',
        'production_sent_to_subcontractor' => 'Fasona gönderildi',
        'production_returned_from_subcontractor' => 'Fasondan geldi',
        'production_partially_completed' => 'Kısmi üretildi',
        'production_completed' => 'Tamamlandı',
        'production_issue_reported' => 'Sorun bildirildi',
    ];
    $previewImage = data_get($snapshot, 'final_graphic.preview_url') ?: data_get($snapshot, 'product_image_url');
    $productName = $snapshot['product_name'] ?? ($production->orderItem?->product_name ?: 'Belirtilmedi');
@endphp

<div class="prd-stack">
    <section class="prd-card">
        <h2 class="prd-section-title">Dış Üretim / Fason</h2>

        @if(!$isExternal)
            <div class="prd-empty">Bu kayıt dış üretim / fason sürecinde ilerlemiyor. Ayrıntılar için İç Üretim sekmesini kullanın.</div>
        @else
            <p class="prd-section-subtitle">Fason üretim sürecini, geliş takibini ve cari işlenme durumunu bu alandan izleyin.</p>
            <div class="prd-split">
                <div class="prd-info-card">
                    <h3 class="prd-side-title">Ürün ve Baskı Bilgisi</h3>
                    <div class="prd-product-preview">
                        <div class="prd-product-image">
                            @if($previewImage)
                                <img src="{{ $previewImage }}" alt="{{ $productName }}">
                            @else
                                <div class="prd-product-empty">Onaylı görsel yok</div>
                            @endif
                        </div>
                        <div class="prd-product-lines">
                            <div class="prd-line-item"><span>Ürün</span><strong>{{ $productName }}</strong></div>
                            <div class="prd-line-item"><span>Baskı</span><strong>{{ $snapshot['print_type'] ?? 'Belirtilmedi' }}</strong></div>
                            <div class="prd-line-item"><span>Baskı alanı</span><strong>{{ $snapshot['print_location'] ?? 'Belirtilmedi' }}</strong></div>
                            <div class="prd-line-item"><span>Baskı seçeneği</span><strong>{{ $snapshot['print_option'] ?? 'Belirtilmedi' }}</strong></div>
                            <div class="prd-line-item"><span>Onaylı grafik</span><strong>{{ $previewImage ? 'Hazır' : 'Bekleniyor' }}</strong></div>
                            <div class="prd-line-item"><span>Hazırlık</span><strong>{{ data_get($snapshot, 'setup_summary_label') ?: 'Hazırlık gerekmiyor' }}</strong></div>
                        </div>
                    </div>
                </div>

                <div class="prd-info-card">
                    <h3 class="prd-side-title">Fason Üretim Bilgileri</h3>
                    <div class="prd-grid-2">
                        <div class="prd-info-card">
                            <span class="prd-info-label">Üretim Türü</span>
                            <div class="prd-info-value">{{ $productionTypeLabel ?? $production->safeProductionTypeLabel() }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Fason Firma</span>
                            <div class="prd-info-value">{{ $fasonFirma?->short_name ?: $fasonFirma?->legal_name ?: 'Seçilmedi' }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">İletişim</span>
                            <div class="prd-info-value">{{ $fasonFirma?->phone ?: $fasonFirma?->mobile ?: $fasonFirma?->email ?: 'Bilgi bulunmuyor' }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Gönderim Tarihi</span>
                            <div class="prd-info-value">{{ optional($production->sent_to_subcontractor_at)->format('d.m.Y H:i') ?: 'Henüz gönderilmedi' }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Tahmini Geliş Tarihi</span>
                            <div class="prd-info-value">{{ optional($production->returned_from_subcontractor_at)->format('d.m.Y H:i') ?: 'Planlanmadı' }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Fason Notu</span>
                            <div class="prd-info-value">{{ $production->production_note ?: 'Henüz fason notu girilmedi' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </section>

    @if($isExternal)
        <div class="prd-grid-2">
            <section class="prd-card">
                <h2 class="prd-section-title">Adet Takibi</h2>
                <div class="prd-grid-3">
                    <div class="prd-info-card">
                        <span class="prd-info-label">Planlanan / Gönderilecek Adet</span>
                        <div class="prd-info-value">{{ $formattedPlannedQuantity }} {{ $unit }}</div>
                    </div>
                    <div class="prd-info-card">
                        <span class="prd-info-label">Gelen / Tamamlanan Adet</span>
                        <div class="prd-info-value">{{ $formattedCompletedQuantity }} {{ $unit }}</div>
                    </div>
                    <div class="prd-info-card">
                        <span class="prd-info-label">Kalan Adet</span>
                        <div class="prd-info-value">{{ $formattedRemainingQuantity }} {{ $unit }}</div>
                    </div>
                </div>

                <div class="prd-info-card" style="margin-top:14px;">
                    <span class="prd-info-label">İlerleme Oranı</span>
                    <div class="prd-info-value">%{{ $progressPercent }}</div>
                    <div style="margin-top:10px;" class="prd-progress-track">
                        <div class="prd-progress-fill" style="width: {{ $progressPercent }}%;"></div>
                    </div>
                    <div style="margin-top:10px; color:#667085; font-size:12px; display:flex; justify-content:space-between; gap:10px;">
                        <span>{{ $formattedCompletedQuantity }} / {{ $formattedPlannedQuantity }} {{ $unit }}</span>
                        <strong style="color:#182230;">{{ $production->safeStatusLabel() }}</strong>
                    </div>
                </div>
            </section>

            <section class="prd-card">
                <h2 class="prd-section-title">Cari ve Finansal Bilgiler</h2>

                @if($canViewFinancialData)
                    <div class="prd-side-rows">
                        <div class="prd-side-row"><span>Eşleşen Cari</span><strong>{{ $matchedAccount?->display_name ?: ($fasonFirma?->short_name ?: $fasonFirma?->legal_name ?: 'Henüz eşleşmedi') }}</strong></div>
                        <div class="prd-side-row"><span>Cari Hareketi</span><strong>{{ $subcontractorTransaction && !$subcontractorTransaction->isCancelled() ? 'İşlendi' : 'Bekleniyor' }}</strong></div>
                        <div class="prd-side-row"><span>Son İşlem Tarihi</span><strong>{{ $subcontractorTransaction ? optional($subcontractorTransaction->transaction_date)->format('d.m.Y') : 'Henüz işlenmedi' }}</strong></div>
                        <div class="prd-side-row"><span>Fason Maliyeti</span><strong>{{ $production->subcontractor_cost !== null ? number_format((float) $production->subcontractor_cost, 2, ',', '.') . ' ' . ($production->subcontractor_cost_currency ?: 'TRY') : 'Belirtilmedi' }}</strong></div>
                        <div class="prd-side-row"><span>Para Birimi</span><strong>{{ $production->subcontractor_cost_currency ?: 'TRY' }}</strong></div>
                        <div class="prd-side-row"><span>Birim Maliyet</span><strong>{{ $unitCost !== null ? number_format($unitCost, 2, ',', '.') . ' ' . ($production->subcontractor_cost_currency ?: 'TRY') : 'Belirtilmedi' }}</strong></div>
                        <div class="prd-side-row"><span>Ödeme Durumu</span><strong>{{ $subcontractorTransaction ? $subcontractorTransaction->safeStatusLabel() : 'Cari ekstreden takip edilir' }}</strong></div>
                    </div>
                @else
                    <div class="prd-soft-message">Fason maliyeti ve cari işlemleri yalnız yetkili kullanıcıya gösterilir.</div>
                @endif
            </section>
        </div>

        @if($setupItems->isNotEmpty())
            <section class="prd-card">
                <h2 class="prd-section-title">Ara Eleman Üretimi</h2>
                <div class="prd-grid-3">
                    @foreach($setupItems as $setupItem)
                        <div class="prd-info-card">
                            <span class="prd-info-label">{{ $setupItem['setup_type_label'] }}</span>
                            <div class="prd-info-value" style="font-size:15px;">{{ $setupItem['assigned_company_name'] ?: 'Dış firma bekleniyor' }}</div>
                            <div style="margin-top:8px; color:#667085; font-size:12px; line-height:1.5;">
                                <div>Durum: {{ $setupItem['status_label'] ?? 'Bekleniyor' }}</div>
                                <div>Cari işlenme durumu: {{ !empty($setupItem['has_current_account_match']) ? 'Eşleşme var' : 'Bekliyor' }}</div>
                                @if(!empty($setupItem['note']))
                                    <div>Not: {{ $setupItem['note'] }}</div>
                                @endif
                                @if($canViewFinancialData && array_key_exists('cost', $setupItem))
                                    <div>
                                        Maliyet:
                                        {{ $setupItem['cost'] !== null
                                            ? number_format((float) $setupItem['cost'], 2, ',', '.') . ' ' . ($setupItem['currency'] ?: 'TRY')
                                            : 'Belirtilmedi' }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="prd-card">
            <h2 class="prd-section-title">Son İşlemler</h2>
            @if($externalHistory->isNotEmpty())
                <div class="prd-table-wrap">
                    <table class="prd-table">
                        <thead>
                            <tr>
                                <th>İşlem</th>
                                <th>Açıklama</th>
                                <th>Miktar</th>
                                <th>Operatör</th>
                                <th>Tarih</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($externalHistory as $log)
                                <tr>
                                    <td>{{ $actionLabels[(string) $log->action_type] ?? 'İşlem kaydı' }}</td>
                                    <td>{{ $log->note ?: 'Açıklama girilmedi' }}</td>
                                    <td>
                                        @if((string) $log->action_type === 'production_sent_to_subcontractor')
                                            {{ $formattedPlannedQuantity }} {{ $unit }}
                                        @elseif(in_array((string) $log->action_type, ['production_partially_completed', 'production_completed', 'production_returned_from_subcontractor'], true))
                                            {{ $formattedCompletedQuantity }} {{ $unit }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>{{ $log->creator?->name ?: 'Sistem' }}</td>
                                    <td>{{ optional($log->created_at)->format('d.m.Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="prd-empty">Henüz fason işlem kaydı bulunmuyor.</div>
            @endif
        </section>
    @endif
</div>
