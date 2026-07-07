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
    $isInternal = $production->production_type === OrderItemPrintProduction::TYPE_INTERNAL;
    $canStart = $production->production_status === OrderItemPrintProduction::STATUS_PENDING;
    $canTrackProgress = in_array($production->production_status, [
        OrderItemPrintProduction::STATUS_INTERNAL,
        OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
    ], true);
    $canFinish = $canTrackProgress && $remainingQuantity > 0.0001;
    $photosTabUrl = route('admin.productions.show', $production) . '?tab=fotograflar';
    $unit = $snapshot['unit'] ?? ($production->orderItem?->unit ?: 'Adet');
    $lastHistory = $history->first();
    $internalHistory = $history->take(4)->values();
    $previewImage = data_get($snapshot, 'final_graphic.preview_url') ?: data_get($snapshot, 'product_image_url');
    $productName = $snapshot['product_name'] ?? ($production->orderItem?->product_name ?: 'Belirtilmedi');
    $internalActionLabels = [
        'production_assigned_internal' => 'Üretime başlandı',
        'production_started' => 'Üretime başlandı',
        'production_partially_completed' => 'Kısmi üretildi',
        'production_completed' => 'Tamamlandı',
        'production_issue_reported' => 'Sorun bildirildi',
        'production_photo_added' => 'Fotoğraf eklendi',
    ];
    $stepStates = [
        'Üretime Başla' => $canStart ? 'is-active' : ($production->production_status !== OrderItemPrintProduction::STATUS_PENDING ? 'is-done' : 'is-muted'),
        'Kısmi Üretildi' => $production->production_status === OrderItemPrintProduction::STATUS_INTERNAL ? 'is-active' : ($production->production_status === OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED ? 'is-done' : 'is-muted'),
        'Tamamlandı' => $production->production_status === OrderItemPrintProduction::STATUS_COMPLETED ? 'is-done' : ($canFinish ? 'is-active' : 'is-muted'),
        'Sorun Bildir' => $production->production_status === OrderItemPrintProduction::STATUS_PROBLEMATIC ? 'is-active' : 'is-muted',
        'Fotoğraf Yükle' => 'is-muted',
    ];
@endphp

<div class="prd-stack">
    <section class="prd-card">
        <h2 class="prd-section-title">İç Üretim</h2>

        @if(!$isInternal)
            <div class="prd-empty">Bu kayıt iç üretim akışıyla ilerlemiyor. Ayrıntıları görmek için Dış Üretim / Fason sekmesini kullanın.</div>
        @else
            <div class="prd-split">
                <div class="prd-info-card">
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
                            <div class="prd-line-item"><span>Baskı ölçüsü</span><strong>{{ $snapshot['print_size'] ?? 'Belirtilmedi' }}</strong></div>
                            <div class="prd-line-item"><span>Onaylı grafik</span><strong>{{ $previewImage ? 'Hazır' : 'Bekleniyor' }}</strong></div>
                        </div>
                    </div>
                </div>

                <div class="prd-info-card">
                    <div class="prd-grid-2">
                        <div class="prd-info-card">
                            <span class="prd-info-label">Üretim Türü</span>
                            <div class="prd-info-value">İç Üretim</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Makine / Hat</span>
                            <div class="prd-info-value">{{ $production->production_unit_name ?: 'İç üretim hattı' }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Üretim Alanı</span>
                            <div class="prd-info-value">{{ $snapshot['print_location'] ?? 'Üretim alanı belirlenmedi' }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Planlanan</span>
                            <div class="prd-info-value">{{ $formattedPlannedQuantity }} {{ $unit }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Üretilen Adet</span>
                            <div class="prd-info-value">{{ $formattedCompletedQuantity }} {{ $unit }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Kalan Adet</span>
                            <div class="prd-info-value">{{ $formattedRemainingQuantity }} {{ $unit }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Son İşlem</span>
                            <div class="prd-info-value">{{ $lastHistory ? ($lastHistory->note ?: 'İşlem kaydı var') : 'Henüz işlem yok' }}</div>
                        </div>
                        <div class="prd-info-card">
                            <span class="prd-info-label">Operasyon Notu</span>
                            <div class="prd-info-value" style="font-size:14px;">{{ $production->production_note ?: ($snapshot['status_help'] ?? 'Henüz operasyon notu girilmedi.') }}</div>
                        </div>
                    </div>
                    <div style="margin-top:14px;" class="prd-info-card">
                        <span class="prd-info-label">Kısa İlerleme</span>
                        <div class="prd-info-value">%{{ $progressPercent }}</div>
                        <div style="margin-top:10px;" class="prd-progress-track">
                            <div class="prd-progress-fill" style="width: {{ $progressPercent }}%;"></div>
                        </div>
                        <div style="margin-top:10px; color:#667085; font-size:12px;">{{ $formattedCompletedQuantity }} / {{ $formattedPlannedQuantity }} {{ $unit }}</div>
                    </div>
                </div>
            </div>
        @endif
    </section>

    <section class="prd-card">
        <h2 class="prd-section-title">Üretim Akış Adımları</h2>
        <div class="prd-step-grid">
            <div class="prd-step-card {{ $stepStates['Üretime Başla'] }}">
                <div class="prd-step-top">
                    <span class="prd-step-badge">1</span>
                    <div class="prd-step-title">Üretime Başla</div>
                    <div class="prd-step-copy">Üretimi başlatır, işin aktif operasyona alınmasını sağlar.</div>
                </div>
                <div class="prd-step-meta">{{ $canStart ? 'Hazır' : 'İşlemde' }}</div>
                <div class="prd-step-action">
                    @if($isInternal && $canStart)
                        <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="assign_internal">
                            <input type="hidden" name="production_unit_name" value="{{ $production->production_unit_name ?: 'İç üretim hattı' }}">
                            <button type="submit" class="btn btn-success w-100 prd-touch-button">Başla</button>
                        </form>
                    @else
                        <button type="button" class="btn btn-outline-secondary w-100 prd-touch-button" disabled>Bekliyor</button>
                    @endif
                </div>
            </div>

            <div class="prd-step-card {{ $stepStates['Kısmi Üretildi'] }}">
                <div class="prd-step-top">
                    <span class="prd-step-badge">2</span>
                    <div class="prd-step-title">Kısmi Üretildi</div>
                    <div class="prd-step-copy">Tamamlanan miktarı adım adım işler ve üretim ilerlemesini günceller.</div>
                </div>
                <div class="prd-step-meta">{{ $canTrackProgress ? 'Aktif' : 'Bekliyor' }}</div>
                <div class="prd-step-action">
                    @if($isInternal && $canTrackProgress)
                        <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="partial">
                            <input type="number" name="partial_quantity" class="form-control prd-touch-input" min="0.0001" max="{{ $remainingQuantity > 0 ? number_format($remainingQuantity, 4, '.', '') : 0 }}" step="0.0001" placeholder="Adet" required>
                            <button type="submit" class="btn btn-primary w-100 prd-touch-button" style="margin-top:8px;">Kısmi Üretildi</button>
                        </form>
                    @else
                        <button type="button" class="btn btn-outline-secondary w-100 prd-touch-button" disabled>Bekliyor</button>
                    @endif
                </div>
            </div>

            <div class="prd-step-card {{ $stepStates['Tamamlandı'] }}">
                <div class="prd-step-top">
                    <span class="prd-step-badge">3</span>
                    <div class="prd-step-title">Tamamlandı</div>
                    <div class="prd-step-copy">Kalan adedi bitirir ve üretimi tamamlandı durumuna alır.</div>
                </div>
                <div class="prd-step-meta">{{ $production->production_status === OrderItemPrintProduction::STATUS_COMPLETED ? 'Tamamlandı' : 'Hazır olduğunda' }}</div>
                <div class="prd-step-action">
                    @if($isInternal && $canFinish)
                        <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="completed">
                            <input type="hidden" name="completed_quantity" value="{{ $production->planned_quantity }}">
                            <button type="submit" class="btn btn-success w-100 prd-touch-button">Tamamlandı</button>
                        </form>
                    @else
                        <button type="button" class="btn btn-outline-secondary w-100 prd-touch-button" disabled>Bekliyor</button>
                    @endif
                </div>
            </div>

            <div class="prd-step-card {{ $stepStates['Sorun Bildir'] }}">
                <div class="prd-step-top">
                    <span class="prd-step-badge">4</span>
                    <div class="prd-step-title">Sorun Bildir</div>
                    <div class="prd-step-copy">Üretimi durduran veya kontrol gerektiren problemi hızlıca kaydeder.</div>
                </div>
                <div class="prd-step-meta">{{ $production->isProblematic() ? 'Aktif sorun kaydı var' : 'İhtiyaç halinde' }}</div>
                <div class="prd-step-action">
                    <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="issue">
                        <textarea class="form-control prd-touch-input" name="note" rows="2" placeholder="Sorunu yazın"></textarea>
                        <button type="submit" class="btn btn-danger w-100 prd-touch-button" style="margin-top:8px;">Sorun Bildir</button>
                    </form>
                </div>
            </div>

            <div class="prd-step-card {{ $stepStates['Fotoğraf Yükle'] }}">
                <div class="prd-step-top">
                    <span class="prd-step-badge">5</span>
                    <div class="prd-step-title">Fotoğraf Yükle</div>
                    <div class="prd-step-copy">Üretim aşamasını belgelemek için görsel ekleme alanına geçiş yapar.</div>
                </div>
                <div class="prd-step-meta">İstediğiniz zaman</div>
                <div class="prd-step-action">
                    <a href="{{ $photosTabUrl }}" class="btn btn-outline-primary w-100 prd-touch-button">Fotoğraf Yükle</a>
                </div>
            </div>
        </div>
    </section>

    @if($isInternal)
        <section class="prd-card">
            <h2 class="prd-section-title">Son Üretim Kayıtları</h2>
            @if($internalHistory->isNotEmpty())
                <div class="prd-table-wrap">
                    <table class="prd-table">
                        <thead>
                            <tr>
                                <th>İşlem</th>
                                <th>Açıklama</th>
                                <th>Operatör</th>
                                <th>Tarih</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($internalHistory as $log)
                                <tr>
                                    <td>{{ $internalActionLabels[(string) $log->action_type] ?? 'Üretim kaydı' }}</td>
                                    <td>{{ $log->note ?: 'Açıklama girilmedi' }}</td>
                                    <td>{{ $log->creator?->name ?: 'Sistem' }}</td>
                                    <td>{{ optional($log->created_at)->format('d.m.Y H:i') ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="prd-empty">Henüz üretim kaydı bulunmuyor.</div>
            @endif
        </section>
    @endif
</div>
