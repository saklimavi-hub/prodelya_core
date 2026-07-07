@php
    $history = $history ?? collect();

    $actionLabelMap = [
        'production_operation_created' => 'Üretim kaydı açıldı',
        'production_assigned_internal' => 'İç üretime atandı',
        'production_assigned_external' => 'Fason ataması yapıldı',
        'production_started' => 'Üretime başlandı',
        'production_sent_to_subcontractor' => 'Fasona gönderildi',
        'production_returned_from_subcontractor' => 'Fasondan geldi',
        'production_qc_started' => 'Kalite kontrol başlatıldı',
        'production_qc_passed' => 'Kalite kontrol uygun',
        'production_qc_failed' => 'Kalite kontrolde sorun var',
        'production_partially_completed' => 'Kısmi üretildi',
        'production_completed' => 'Tamamlandı',
        'production_issue_reported' => 'Sorun kaydı oluşturuldu',
        'production_cancelled' => 'Üretim iptal edildi',
        'production_photo_added' => 'Fotoğraf eklendi',
    ];

    $historyTypeOptions = [
        'kayit' => 'production_operation_created',
        'ic-uretim' => 'production_assigned_internal',
        'fason-atama' => 'production_assigned_external',
        'baslangic' => 'production_started',
        'fasona-gonderim' => 'production_sent_to_subcontractor',
        'fason-donus' => 'production_returned_from_subcontractor',
        'kalite-baslangic' => 'production_qc_started',
        'kalite-uygun' => 'production_qc_passed',
        'kalite-sorun' => 'production_qc_failed',
        'kismi' => 'production_partially_completed',
        'tamamlandi' => 'production_completed',
        'sorun' => 'production_issue_reported',
        'iptal' => 'production_cancelled',
        'fotograf' => 'production_photo_added',
    ];

    $historyType = trim((string) request('history_type', ''));
    $historyOperator = trim((string) request('operator', ''));
    $historyDate = trim((string) request('history_date', ''));
    $selectedActionType = $historyType !== '' ? ($historyTypeOptions[$historyType] ?? '__invalid__') : '';

    $filteredHistory = $history->filter(function ($log) use ($selectedActionType, $historyOperator, $historyDate): bool {
        if ($selectedActionType !== '' && (string) $log->action_type !== $selectedActionType) {
            return false;
        }

        if ($historyOperator !== '' && (string) $log->creator_id !== $historyOperator) {
            return false;
        }

        if ($historyDate !== '' && optional($log->created_at)->format('Y-m-d') !== $historyDate) {
            return false;
        }

        return true;
    })->values();

    $operatorOptions = $history
        ->map(fn ($log) => $log->creator)
        ->filter()
        ->unique('id')
        ->values();
@endphp

<div class="prd-stack">
    <section class="prd-card">
        <h2 class="prd-section-title">Geçmiş</h2>
        <p class="prd-section-subtitle">Üretim adımlarını, operatör işlemlerini ve açıklamaları sade bir işlem dökümü olarak izleyin.</p>
        <div class="prd-grid-2">
            <div class="prd-table-card">
                <h3 class="prd-side-title">İşlem Geçmişi</h3>

                @if($filteredHistory->isNotEmpty())
                    <div class="prd-table-wrap">
                        <table class="prd-table">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>İşlem</th>
                                    <th>Açıklama</th>
                                    <th>Operatör</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($filteredHistory as $log)
                                    <tr>
                                        <td>{{ optional($log->created_at)->format('d.m.Y H:i') }}</td>
                                        <td>{{ $actionLabelMap[(string) $log->action_type] ?? 'Üretim hareketi' }}</td>
                                        <td>
                                            {{ $log->note ?: 'Açıklama girilmedi' }}
                                            @if($log->old_status || $log->new_status)
                                                <div style="margin-top:4px; color:#98a2b3;">
                                                    @if($log->old_status)
                                                        Önce: {{ $statusLabels[$log->old_status] ?? $log->old_status }}
                                                    @endif
                                                    @if($log->old_status && $log->new_status)
                                                        /
                                                    @endif
                                                    @if($log->new_status)
                                                        Sonra: {{ $statusLabels[$log->new_status] ?? $log->new_status }}
                                                    @endif
                                                </div>
                                            @endif
                                        </td>
                                        <td>{{ $log->creator?->name ?: 'Sistem' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="prd-empty">Seçilen filtrelere uygun işlem geçmişi bulunamadı.</div>
                @endif
            </div>

            <div class="prd-form-card">
                <h3 class="prd-side-title">Filtreler</h3>
                <form method="GET" action="{{ route('admin.productions.show', $production) }}">
                    <input type="hidden" name="tab" value="gecmis">

                    <div style="display:grid; gap:12px;">
                        <div>
                            <label class="form-label" for="history_type">İşlem Türü</label>
                            <select id="history_type" name="history_type" class="form-control">
                                <option value="">Tümü</option>
                                @foreach($historyTypeOptions as $safeValue => $actionType)
                                    <option value="{{ $safeValue }}" @selected($historyType === $safeValue)>{{ $actionLabelMap[$actionType] ?? 'İşlem' }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="history_operator">Operatör</label>
                            <select id="history_operator" name="operator" class="form-control">
                                <option value="">Tümü</option>
                                @foreach($operatorOptions as $operator)
                                    <option value="{{ $operator->id }}" @selected($historyOperator === (string) $operator->id)>{{ $operator->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="history_date">Tarih Aralığı</label>
                            <input id="history_date" type="date" name="history_date" class="form-control" value="{{ $historyDate }}">
                        </div>
                    </div>

                    <div class="prd-form-actions">
                        <button type="submit" class="btn btn-sm btn-primary">Filtrele</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>
