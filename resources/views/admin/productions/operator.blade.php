@extends('layouts.prodelya-admin')

@section('title', 'İç Üretim Operatörü')
@section('page_topbar_hidden', true)
@section('hide_side_summary', true)
@section('page_title', 'İç Üretim Operatörü')
@section('page_subtitle', 'Exact baskı işi için sade üretim ekranı.')

@section('content')
@php
    use App\Models\OrderItemPrintProduction;

    $order = $production->order;
    $workForm = $production->workForm;
    $orderItem = $production->orderItem;
    $print = $production->orderItemPrint;
    $graphic = $readiness['graphic_operation'] ?? ($production->graphicOperation ?: $print?->graphicOperation);
    $finalGraphic = $readiness['final_graphic_attachment'] ?? null;
    $planned = (float) $production->planned_quantity;
    $completed = (float) $production->completed_quantity;
    $remaining = max((float) $production->remaining_quantity, 0.0);
    $progress = $planned > 0 ? min(100, round(($completed / $planned) * 100)) : 0;
    $unit = $snapshot['unit'] ?? ($orderItem?->unit ?: 'Adet');
    $printSequence = $snapshot['print_sequence'] ?? ($print?->print_sequence ?: '-');
    $productName = $snapshot['product_name'] ?? ($orderItem?->product_name ?: '-');
    $productCode = $snapshot['product_code'] ?? ($orderItem?->product_code ?: '-');
    $customerName = $order?->customer?->legal_name ?: '-';
    $operatorActivity = $history->first(function ($log) {
        return in_array((string) $log->action_type, ['production_assigned_internal', 'production_started', 'production_partially_completed'], true)
            || in_array(\Illuminate\Support\Str::of((string) $log->action_type)->replace(['-', ' '], '_')->snake()->lower()->toString(), ['assigned_to_internal_production', 'production_started', 'production_partially_completed'], true);
    });
    $operatorName = $production->assignedUser?->name ?: ($operatorActivity?->creator?->name ?: 'Atanmamış');
    $finalGraphicUrl = $finalGraphic ? route('admin.work-forms.attachments.preview', $finalGraphic) : null;
    $finalGraphicIsImage = $finalGraphic?->isImage() ?? false;
    $canTransferToSubcontract = OrderItemPrintProduction::normalizeProductionType($production->production_type ?: $production->orderItemPrint?->production_type) === OrderItemPrintProduction::TYPE_INTERNAL
        && !in_array($production->production_status, [OrderItemPrintProduction::STATUS_COMPLETED, OrderItemPrintProduction::STATUS_CANCELLED], true)
        && $remaining > 0.0;
@endphp

<div class="pd-internal-operator pd-ui-v1-internal-operator" data-production-id="{{ $production->id }}" data-print-row-id="{{ $production->order_item_print_id }}">
    <section class="pd-internal-operator__hero">
        <div>
            <span class="pd-internal-operator__eyebrow">Üretim / Fason · İç Baskı</span>
            <h2>{{ $order?->document_number ?? '-' }} · {{ $printSequence }}</h2>
            <p>{{ $customerName }} için exact baskı işi. Sadece iç üretim akışı bu ekrandan yürütülür.</p>
        </div>
        <div class="pd-internal-operator__hero-actions">
            @if($canTransferToSubcontract)
                <a href="#route-transfer-panel" class="pd-internal-operator__route-transfer-trigger">Fasona Devret</a>
            @endif
            <a href="{{ route('admin.productions.index', ['route' => 'internal']) }}" class="pd-internal-operator__secondary-link">Üretim Listesine Dön</a>
        </div>
    </section>

    <div class="pd-internal-operator__layout">
        <main class="pd-internal-operator__main">
            <section class="pd-internal-operator__focus" aria-label="Aktif üretim odağı">
                <div>
                    <span class="pd-internal-operator__kicker">Sıradaki İş</span>
                    <h3>{{ $operatorAction['title'] }}</h3>
                    <p>{{ $operatorAction['hint'] }}</p>
                </div>

                @if($operatorAction['type'] === 'assign_operator')
                    <details class="pd-internal-operator__assignment-panel" id="operator-assignment-panel" open>
                        <summary class="pd-internal-operator__primary-action">{{ $operatorAction['label'] }}</summary>
                        <form method="POST" action="{{ route('admin.productions.update-assignment', $production) }}" class="pd-internal-operator__assignment-form">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="production_type" value="{{ OrderItemPrintProduction::TYPE_INTERNAL }}">
                            <input type="hidden" name="return_to" value="operator">
                            <input type="hidden" name="cliche_required" value="{{ $production->cliche_required ? 1 : 0 }}">
                            <input type="hidden" name="cliche_status" value="{{ $production->cliche_status ?: OrderItemPrintProduction::CLICHE_NOT_REQUIRED }}">
                            <label>
                                <span>Operatör</span>
                                <select name="assigned_to" required>
                                    <option value="">Operatör seçin</option>
                                    @foreach($users as $user)
                                        <option value="{{ $user->id }}" @selected((int) old('assigned_to', $production->assigned_to) === (int) $user->id)>{{ $user->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span>Üretim Birimi / Hat</span>
                                <input type="text" name="production_unit_name" value="{{ old('production_unit_name', $production->production_unit_name ?: 'İç üretim') }}" maxlength="120" placeholder="UV İç Hat">
                            </label>
                            <div class="pd-internal-operator__assignment-actions">
                                <button type="submit" class="pd-internal-operator__button">Operatöre Ata</button>
                                <a href="{{ route('admin.productions.operator', $production) }}" class="pd-internal-operator__secondary-link">Vazgeç</a>
                            </div>
                        </form>
                    </details>
                @elseif($operatorAction['type'] === 'start')
                    <form method="POST" action="{{ route('admin.productions.update-status', $production) }}" class="pd-internal-operator__primary-form">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="assign_internal">
                        <input type="hidden" name="production_unit_name" value="{{ $operatorAction['unit_name'] }}">
                        <input type="hidden" name="return_to" value="operator">
                        <button type="submit" class="pd-internal-operator__primary-action">{{ $operatorAction['label'] }}</button>
                    </form>
                @elseif($operatorAction['type'] === 'result')
                    <details class="pd-internal-operator__result-panel">
                        <summary class="pd-internal-operator__primary-action">{{ $operatorAction['label'] }}</summary>
                        <div class="pd-internal-operator__result-grid">
                            <form method="POST" action="{{ route('admin.productions.update-status', $production) }}" class="pd-internal-operator__inline-form">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="action" value="partial">
                                <input type="hidden" name="return_to" value="operator">
                                <label>Bu işlemde basılan adet</label>
                                <input type="number" name="partial_quantity" min="0.0001" step="0.0001" max="{{ $remaining }}" value="{{ $remaining > 0 ? OrderItemPrintProduction::formatDisplayQuantity($remaining) : '' }}">
                                <textarea name="note" rows="2" placeholder="Kısa üretim notu"></textarea>
                                <button type="submit" class="pd-internal-operator__button">Kısmi Kaydet</button>
                            </form>

                            <form method="POST" action="{{ route('admin.productions.update-status', $production) }}" class="pd-internal-operator__inline-form pd-internal-operator__inline-form--soft">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="action" value="completed">
                                <input type="hidden" name="completed_quantity" value="{{ $planned }}">
                                <input type="hidden" name="return_to" value="operator">
                                <label>Kalanı tamamla</label>
                                <p>{{ OrderItemPrintProduction::formatDisplayQuantity($remaining) }} {{ $unit }} kalan iş tamamlandı olarak işaretlenir.</p>
                                <button type="submit" class="pd-internal-operator__button">Tamamlandı</button>
                            </form>

                            <form method="POST" action="{{ route('admin.productions.update-status', $production) }}" class="pd-internal-operator__inline-form pd-internal-operator__inline-form--danger">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="action" value="issue">
                                <input type="hidden" name="return_to" value="operator">
                                <label>Sorun bildir</label>
                                <textarea name="note" rows="2" required placeholder="Sorunu kısa açıklayın"></textarea>
                                <button type="submit" class="pd-internal-operator__button">Sorun Bildir</button>
                            </form>
                        </div>
                    </details>
                @else
                    <a href="{{ $operatorAction['url'] }}" class="pd-internal-operator__primary-action">{{ $operatorAction['label'] }}</a>
                @endif
            </section>

            @if($canTransferToSubcontract)
                <section class="pd-internal-operator__route-transfer-panel pd-internal-operator__card" id="route-transfer-panel" aria-label="Fasona devretme paneli">
                    <header>
                        <div>
                            <span class="pd-internal-operator__kicker">Rota Değişikliği</span>
                            <h3>Fasona Devret</h3>
                        </div>
                    </header>
                    <form method="POST" action="{{ route('admin.productions.update-assignment', $production) }}" class="pd-internal-operator__assignment-form">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="production_type" value="{{ OrderItemPrintProduction::TYPE_OUTSOURCED }}">
                        <input type="hidden" name="return_to" value="subcontract_assignment">
                        <input type="hidden" name="cliche_required" value="{{ $production->cliche_required ? 1 : 0 }}">
                        <input type="hidden" name="cliche_status" value="{{ $production->cliche_status ?: OrderItemPrintProduction::CLICHE_NOT_REQUIRED }}">

                        <div class="pd-internal-operator__transfer-metrics">
                            <div><span>Planlanan</span><strong>{{ OrderItemPrintProduction::formatDisplayQuantity($planned) }} {{ $unit }}</strong></div>
                            <div><span>Tamamlanan</span><strong>{{ OrderItemPrintProduction::formatDisplayQuantity($completed) }} {{ $unit }}</strong></div>
                            <div><span>Fasona Gidecek</span><strong>{{ OrderItemPrintProduction::formatDisplayQuantity($remaining) }} {{ $unit }}</strong></div>
                        </div>

                        <label>
                            <span>Fason Firma</span>
                            <select name="production_company_id" required>
                                <option value="">Fason firma seçin</option>
                                @foreach($companies as $company)
                                    <option value="{{ $company->id }}" @selected((int) old('production_company_id') === (int) $company->id)>{{ $company->legal_name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span>Değişiklik Gerekçesi</span>
                            <textarea name="route_change_reason" rows="3" maxlength="1000" required placeholder="İç üretimden fasona devretme gerekçesi">{{ old('route_change_reason') }}</textarea>
                        </label>
                        <div class="pd-internal-operator__assignment-actions">
                            <button type="submit" class="pd-internal-operator__button">Fasona Devret</button>
                            <a href="{{ route('admin.productions.operator', $production) }}" class="pd-internal-operator__secondary-link">Vazgeç</a>
                        </div>
                    </form>
                </section>
            @endif

            <section class="pd-internal-operator__card">
                <header>
                    <div>
                        <span class="pd-internal-operator__kicker">Adet Durumu</span>
                        <h3>{{ OrderItemPrintProduction::formatDisplayQuantity($remaining) }} {{ $unit }} kaldı</h3>
                    </div>
                    <strong>{{ $progress }}%</strong>
                </header>
                <div class="pd-internal-operator__progress" aria-hidden="true"><span style="width: {{ $progress }}%;"></span></div>
                <div class="pd-internal-operator__metrics">
                    <div><span>Planlanan</span><strong>{{ OrderItemPrintProduction::formatDisplayQuantity($planned) }} {{ $unit }}</strong></div>
                    <div><span>Tamamlanan</span><strong>{{ OrderItemPrintProduction::formatDisplayQuantity($completed) }} {{ $unit }}</strong></div>
                    <div><span>Kalan</span><strong>{{ OrderItemPrintProduction::formatDisplayQuantity($remaining) }} {{ $unit }}</strong></div>
                </div>
            </section>

            <section class="pd-internal-operator__card pd-internal-operator__work-card">
                <div>
                    <span class="pd-internal-operator__kicker">Exact Baskı</span>
                    <h3>{{ $productName }}</h3>
                    <p>{{ $productCode }} · {{ $snapshot['print_type'] ?? ($print?->print_type ?: '-') }} · {{ $snapshot['print_option'] ?? ($print?->print_option ?: '-') }}</p>
                </div>
                <div class="pd-internal-operator__info-grid">
                    <div><span>Baskı satırı</span><strong>{{ $printSequence }}</strong></div>
                    <div><span>Konum</span><strong>{{ $snapshot['print_location'] ?? ($print?->print_location ?: '-') }}</strong></div>
                    <div><span>Renk</span><strong>{{ $snapshot['print_color'] ?? ($print?->print_color ?: '-') }}</strong></div>
                    <div><span>Ölçü</span><strong>{{ $snapshot['print_size'] ?? ($print?->print_size ?: '-') }}</strong></div>
                    <div><span>Operatör</span><strong>{{ $operatorName }}</strong></div>
                    <div><span>Birim</span><strong>{{ $production->production_unit_name ?: 'İç üretim' }}</strong></div>
                </div>
            </section>

            <section class="pd-internal-operator__card pd-internal-operator__graphic-card">
                <header>
                    <div>
                        <span class="pd-internal-operator__kicker">Onaylı Grafik</span>
                        <h3>{{ $readiness['graphic_status_label'] ?? $readiness['readiness_label'] ?? 'Grafik durumu' }}</h3>
                    </div>
                    @if($graphic)
                        <a href="{{ route('admin.graphics.show', $graphic) }}" class="pd-internal-operator__secondary-link">Grafik Detayı</a>
                    @endif
                </header>
                @if($finalGraphic && $finalGraphicIsImage && $finalGraphicUrl)
                    <div class="pd-internal-operator__graphic-preview is-loading" data-operator-graphic-preview>
                        <a href="{{ $finalGraphicUrl }}" target="_blank" rel="noopener" class="pd-internal-operator__graphic-link">
                            <img src="{{ $finalGraphicUrl }}" alt="Onaylı üretim grafiği" data-operator-graphic-image>
                        </a>
                        <div class="pd-internal-operator__graphic-loading" data-operator-graphic-loading>Görsel yükleniyor...</div>
                        <div class="pd-internal-operator__graphic-error" data-operator-graphic-error>
                            Grafik önizlemesi yüklenemedi. Grafik detayını açın.
                        </div>
                    </div>
                @elseif($finalGraphic)
                    <div class="pd-internal-operator__empty">Final grafik dosyası görsel olarak önizlenemiyor. Grafik detayını açın.</div>
                @else
                    <div class="pd-internal-operator__empty">Bu exact baskı için üretime hazır final grafik görseli yok.</div>
                @endif
            </section>

                        <section class="pd-internal-operator__card">
                <header>
                    <div>
                        <span class="pd-internal-operator__kicker">Üretim Fotoğrafı</span>
                        <h3>Mobil kamera ile ekle</h3>
                    </div>
                </header>
                @if($workForm)
                    <form method="POST" action="{{ route('admin.work-forms.attachments.store', $workForm) }}" enctype="multipart/form-data" class="pd-internal-operator__photo-form">
                        @csrf
                        <input type="hidden" name="attachment_type" value="production_photo">
                        <input type="hidden" name="visibility" value="internal">
                        <input type="hidden" name="redirect_to" value="admin.productions.operator">
                        <input type="hidden" name="redirect_production_id" value="{{ $production->id }}">
                        <input type="file" name="file" accept="image/*" capture="environment" required>
                        <input type="text" name="note" placeholder="Fotoğraf notu">
                        <button type="submit" class="pd-internal-operator__button">Fotoğraf Ekle</button>
                    </form>
                @else
                    <div class="pd-internal-operator__empty">Fotoğraf eklemek için iş formu kaydı gerekli.</div>
                @endif

                @if($productionPhotos->isNotEmpty())
                    <div class="pd-internal-operator__photo-list">
                        @foreach($productionPhotos as $photo)
                            <a href="{{ route('admin.work-forms.attachments.preview', $photo) }}" target="_blank" rel="noopener">
                                <span>{{ $photo->file_name ?: 'Üretim fotoğrafı' }}</span>
                                <small>{{ optional($photo->created_at)->format('d.m.Y H:i') }} · {{ $photo->uploader?->name ?: 'Sistem' }}</small>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>
        </main>

        <aside class="pd-internal-operator__aside">
            <section class="pd-internal-operator__summary">
                <h3>Kısa Bilgi</h3>
                <div><span>Sipariş</span><strong>{{ $order?->document_number ?? '-' }}</strong></div>
                <div><span>İş formu</span><strong>{{ $workForm?->work_form_number ?? '-' }}</strong></div>
                <div><span>Müşteri</span><strong>{{ $customerName }}</strong></div>
                <div><span>Durum</span><strong>{{ $production->safeStatusLabel() }}</strong></div>
                <div><span>Hazırlık</span><strong>{{ $readiness['readiness_label'] ?? '-' }}</strong></div>
            </section>

            <section class="pd-internal-operator__summary pd-internal-operator__summary--history">
                <h3>Son Geçmiş</h3>
                @forelse($history as $log)
                    <article>
                        <strong>{{ $activityLabelResolver->title((string) $log->action_type) }}</strong>
                        <span>{{ $log->note }}</span>
                        <small>{{ optional($log->created_at)->format('d.m.Y H:i') }} · {{ $log->creator?->name ?: 'Sistem' }}</small>
                    </article>
                @empty
                    <p>Henüz üretim geçmişi yok.</p>
                @endforelse
            </section>
        </aside>
    </div>
</div>
@endsection


@push('styles')
<style>
    .pd-ui-v1-internal-operator .pd-internal-operator__hero-actions {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-end;
    }
    .pd-ui-v1-internal-operator .pd-internal-operator__route-transfer-trigger {
        align-items: center;
        border: 1px solid rgba(37, 99, 235, 0.26);
        border-radius: 8px;
        color: #1d4ed8;
        display: inline-flex;
        font-weight: 700;
        min-height: 44px;
        padding: 0 14px;
        text-decoration: none;
    }
    .pd-ui-v1-internal-operator .pd-internal-operator__route-transfer-panel {
        scroll-margin-top: 24px;
    }
    .pd-ui-v1-internal-operator .pd-internal-operator__transfer-metrics {
        display: grid;
        gap: 10px;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .pd-ui-v1-internal-operator .pd-internal-operator__transfer-metrics div {
        background: #f8fafc;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 8px;
        padding: 10px;
    }
    .pd-ui-v1-internal-operator .pd-internal-operator__transfer-metrics span {
        color: #64748b;
        display: block;
        font-size: 12px;
    }
    .pd-ui-v1-internal-operator .pd-internal-operator__transfer-metrics strong {
        color: #0f172a;
        display: block;
        margin-top: 3px;
    }
    @media (max-width: 720px) {
        .pd-ui-v1-internal-operator .pd-internal-operator__hero-actions,
        .pd-ui-v1-internal-operator .pd-internal-operator__route-transfer-trigger {
            width: 100%;
        }
        .pd-ui-v1-internal-operator .pd-internal-operator__route-transfer-trigger {
            justify-content: center;
        }
        .pd-ui-v1-internal-operator .pd-internal-operator__transfer-metrics {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush
