@extends('layouts.prodelya-admin')

@section('title', 'Fason Takibi')
@section('page_topbar_hidden', true)
@section('hide_side_summary', true)
@section('page_title', 'Fason Takibi')
@section('page_subtitle', 'Exact fason baskı işi için gelen miktar ve sorun takibi.')

@section('content')
@php
    use App\Models\OrderItemPrintProduction;

    $order = $production->order;
    $workForm = $production->workForm;
    $orderItem = $production->orderItem;
    $print = $production->orderItemPrint;
    $orderNumber = $snapshot['order_number'] ?? ($order?->document_number ?: '-');
    $workFormNumber = $snapshot['work_form_number'] ?? ($workForm?->work_form_number ?: '-');
    $customerName = $order?->customer?->legal_name ?: '-';
    $productName = $snapshot['product_name'] ?? ($orderItem?->product_name ?: '-');
    $productCode = $snapshot['product_code'] ?? ($orderItem?->product_code ?: '-');
    $printSequence = $snapshot['print_sequence'] ?? ($print?->sequence_code ?: '-');
    $printType = $print?->displayPrintType() ?: ($snapshot['print_type'] ?? 'Baskı tekniği');
    $printOption = $snapshot['print_option'] ?? ($print?->print_option ?: '-');
    $unit = $snapshot['unit'] ?? ($orderItem?->unit ?: 'Adet');
    $planned = OrderItemPrintProduction::formatDisplayQuantity($receiptSummary['planned_quantity'] ?? $production->planned_quantity);
    $completed = OrderItemPrintProduction::formatDisplayQuantity($receiptSummary['completed_quantity'] ?? $production->completed_quantity);
    $remaining = OrderItemPrintProduction::formatDisplayQuantity($receiptSummary['remaining_quantity'] ?? $production->remaining_quantity);
    $priorInternal = $receiptSummary['prior_internal_completed_quantity'] !== null
        ? OrderItemPrintProduction::formatDisplayQuantity($receiptSummary['prior_internal_completed_quantity'])
        : null;
    $sentQuantity = $receiptSummary['sent_quantity'] !== null
        ? OrderItemPrintProduction::formatDisplayQuantity($receiptSummary['sent_quantity'])
        : null;
    $receivedFromSubcontractor = $receiptSummary['received_from_subcontractor_quantity'] !== null
        ? OrderItemPrintProduction::formatDisplayQuantity($receiptSummary['received_from_subcontractor_quantity'])
        : null;
    $remainingFromSubcontractor = $receiptSummary['remaining_from_subcontractor_quantity'] !== null
        ? OrderItemPrintProduction::formatDisplayQuantity($receiptSummary['remaining_from_subcontractor_quantity'])
        : null;
    $maxPartial = $receiptSummary['remaining_from_subcontractor_quantity'] ?? $production->remainingQuantity();
    $statusLabel = $production->safeStatusLabel();
    $isCompleted = $trackingAction['type'] === 'readonly'
        && $production->production_status === OrderItemPrintProduction::STATUS_COMPLETED;
    $visiblePhotos = $productionPhotos->take(3);
    $extraPhotoCount = max(0, $productionPhotos->count() - $visiblePhotos->count());
    $visibleHistory = $history->take(3);
    $extraHistoryCount = max(0, $history->count() - $visibleHistory->count());
@endphp

<div class="pd-ui-v1-subcontract-tracking pd-subcontract-tracking" data-production-id="{{ $production->id }}" data-print-row-id="{{ $production->order_item_print_id }}">
    <section class="pd-subcontract-tracking__compact-header">
        <div class="pd-subcontract-tracking__title-block">
            <span>Üretim / Fason · Gelen Miktar</span>
            <h1>{{ $orderNumber }} · {{ $printSequence }} · {{ $printType }}</h1>
            <strong>{{ $productCode }} {{ $productName }}</strong>
            <p class="pd-subcontract-tracking__meta-line">
                <span>Müşteri: {{ $customerName }}</span>
                <span>Fason: {{ $production->productionCompany?->legal_name ?: '-' }}</span>
                <span>Gönderim: {{ optional($production->sent_to_subcontractor_at)->format('d.m.Y H:i') ?: '-' }}</span>
                <span>{{ $sentQuantity ?? $planned }} {{ $unit }}</span>
            </p>
        </div>
        <a href="{{ route('admin.productions.index', ['route' => 'outsourced']) }}" class="pd-subcontract-tracking__btn">Fason Listesine Dön</a>
    </section>

    @if(session('success'))
        <div class="pd-subcontract-tracking__notice">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="pd-subcontract-tracking__alert">
            <strong>İşlem tamamlanamadı.</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <main class="pd-subcontract-tracking__surface">
        <section class="pd-subcontract-tracking__action-panel" aria-label="Fason takip aktif aksiyonu">
            <div class="pd-subcontract-tracking__action-copy">
                <span>{{ $trackingAction['title'] }}</span>
                <h2>{{ $isCompleted ? 'Fason işi tamamlandı' : 'Fason dönüşünü kaydet' }}</h2>
                <p>{{ $trackingAction['hint'] }}</p>
            </div>

            <div class="pd-subcontract-tracking__metrics-line" aria-label="Fason miktar özeti">
                @if($receiptSummary['has_baseline'])
                    @if($priorInternal !== null && (float) $receiptSummary['prior_internal_completed_quantity'] > 0)
                        <div class="pd-subcontract-tracking__metric"><span>Önceden tamamlanan</span><strong>{{ $priorInternal }}</strong></div>
                    @endif
                    <div class="pd-subcontract-tracking__metric"><span>Gönderilen</span><strong>{{ $sentQuantity }}</strong></div>
                    <div class="pd-subcontract-tracking__metric"><span>Gelen</span><strong>{{ $receivedFromSubcontractor }}</strong></div>
                    <div class="pd-subcontract-tracking__metric"><span>Kalan</span><strong>{{ $remainingFromSubcontractor }}</strong></div>
                @else
                    <div class="pd-subcontract-tracking__metric"><span>Toplam tamamlanan</span><strong>{{ $completed }}</strong></div>
                    <div class="pd-subcontract-tracking__metric"><span>Toplam kalan</span><strong>{{ $remaining }}</strong></div>
                @endif
                <div class="pd-subcontract-tracking__metric"><span>Durum</span><strong>{{ $statusLabel }}</strong></div>
            </div>

            @if($receiptSummary['warning'])
                <p class="pd-subcontract-tracking__warning pd-subcontract-tracking__warning--compact">
                    Bu eski kayıtta fason başlangıç miktarı ayrı izlenemiyor. Toplam tamamlanan ve kalan miktarlar gösteriliyor.
                </p>
            @endif

            @if($isCompleted)
                <div class="pd-subcontract-tracking__completed">
                    <strong>{{ $completed }} / {{ $planned }} {{ $unit }} teslim alındı</strong>
                    <span>Gönderilen {{ $sentQuantity ?? $planned }} · Gelen {{ $receivedFromSubcontractor ?? $completed }} · Kalan {{ $remainingFromSubcontractor ?? $remaining }}</span>
                    <details class="pd-subcontract-tracking__details-toggle">
                        <summary class="pd-subcontract-tracking__btn pd-subcontract-tracking__btn--primary">{{ $trackingAction['label'] }}</summary>
                        <div class="pd-subcontract-tracking__definition-row">
                            <span>İş formu <strong>{{ $workFormNumber }}</strong></span>
                            <span>Baskı <strong>{{ $printOption }}</strong></span>
                            <span>Exact satır <strong>{{ $printSequence }}</strong></span>
                        </div>
                    </details>
                </div>
            @elseif($trackingAction['type'] === 'receipt')
                <details class="pd-subcontract-tracking__receipt-panel">
                    <summary class="pd-subcontract-tracking__btn pd-subcontract-tracking__btn--primary">{{ $trackingAction['label'] }}</summary>
                    <div class="pd-subcontract-tracking__receipt-grid">
                        <form method="POST" action="{{ route('admin.productions.update-status', $production) }}" class="pd-subcontract-tracking__inline-form pd-subcontract-tracking__inline-form--ok">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="subcontract_completed">
                            <input type="hidden" name="return_to" value="subcontract_tracking">
                            <label>Tamamı Geldi</label>
                            <p>Kalan {{ $remainingFromSubcontractor ?? $remaining }} {{ $unit }} tamamlandı olarak kaydedilir.</p>
                            <textarea name="note" rows="2" placeholder="İsteğe bağlı teslim notu"></textarea>
                            <button type="submit" class="pd-subcontract-tracking__btn pd-subcontract-tracking__btn--soft">Tamamı Geldi</button>
                        </form>

                        <form method="POST" action="{{ route('admin.productions.update-status', $production) }}" class="pd-subcontract-tracking__inline-form">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="partial">
                            <input type="hidden" name="return_to" value="subcontract_tracking">
                            <label>Kısmi Geldi</label>
                            <input type="number" name="partial_quantity" min="0.0001" step="0.0001" max="{{ $maxPartial }}" placeholder="Bu teslimatta gelen adet">
                            <textarea name="note" rows="2" placeholder="Kısa not"></textarea>
                            <button type="submit" class="pd-subcontract-tracking__btn pd-subcontract-tracking__btn--soft">Kısmi Kaydet</button>
                        </form>

                        <form method="POST" action="{{ route('admin.productions.update-status', $production) }}" class="pd-subcontract-tracking__inline-form pd-subcontract-tracking__inline-form--danger">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="issue">
                            <input type="hidden" name="return_to" value="subcontract_tracking">
                            <label>Eksik / Sorun Bildir</label>
                            <textarea name="note" rows="3" required placeholder="Sorun açıklaması"></textarea>
                            <button type="submit" class="pd-subcontract-tracking__btn pd-subcontract-tracking__btn--soft">Sorun Bildir</button>
                        </form>
                    </div>
                </details>
            @else
                <span class="pd-subcontract-tracking__btn">{{ $trackingAction['label'] }}</span>
            @endif
        </section>

        <details class="pd-subcontract-tracking__card pd-subcontract-tracking__details-toggle">
            <summary>İş Detaylarını Göster</summary>
            <div class="pd-subcontract-tracking__definition-row">
                <span>İş formu <strong>{{ $workFormNumber }}</strong></span>
                <span>Baskı <strong>{{ $printOption }}</strong></span>
                <span>Exact satır <strong>{{ $printSequence }}</strong></span>
                <span>Ürün kodu <strong>{{ $productCode }}</strong></span>
            </div>
        </details>

        <section class="pd-subcontract-tracking__card pd-subcontract-tracking__photo-strip">
            <div class="pd-subcontract-tracking__section-line">
                <strong>Fotoğraflar</strong>
                @if($workForm && !$isCompleted)
                    <details class="pd-subcontract-tracking__photo-upload">
                        <summary class="pd-subcontract-tracking__btn pd-subcontract-tracking__btn--soft">Teslim Fotoğrafı Ekle</summary>
                        <form method="POST" action="{{ route('admin.work-forms.attachments.store', $workForm) }}" enctype="multipart/form-data" class="pd-subcontract-tracking__photo-form">
                            @csrf
                            <input type="hidden" name="attachment_type" value="production_photo">
                            <input type="hidden" name="visibility" value="internal">
                            <input type="hidden" name="redirect_to" value="admin.productions.subcontract-tracking">
                            <input type="hidden" name="redirect_production_id" value="{{ $production->id }}">
                            <input type="file" name="file" accept="image/*" capture="environment" required>
                            <input type="text" name="note" placeholder="Fotoğraf notu">
                            <button type="submit" class="pd-subcontract-tracking__btn pd-subcontract-tracking__btn--soft">Fotoğraf Ekle</button>
                        </form>
                    </details>
                @endif
            </div>
            @if($visiblePhotos->isNotEmpty())
                <div class="pd-subcontract-tracking__photo-list">
                    @foreach($visiblePhotos as $photo)
                        <a href="{{ route('admin.work-forms.attachments.preview', $photo) }}" target="_blank" rel="noopener" class="pd-subcontract-tracking__photo-link">
                            <strong>{{ $photo->file_name ?: 'Üretim fotoğrafı' }}</strong>
                            <span>{{ optional($photo->created_at)->format('d.m.Y H:i') }}</span>
                        </a>
                    @endforeach
                </div>
                @if($extraPhotoCount > 0)
                    <details class="pd-subcontract-tracking__details-toggle">
                        <summary>Tüm Fotoğrafları Göster (+{{ $extraPhotoCount }})</summary>
                        <div class="pd-subcontract-tracking__photo-list">
                            @foreach($productionPhotos->skip(3) as $photo)
                                <a href="{{ route('admin.work-forms.attachments.preview', $photo) }}" target="_blank" rel="noopener" class="pd-subcontract-tracking__photo-link">
                                    <strong>{{ $photo->file_name ?: 'Üretim fotoğrafı' }}</strong>
                                    <span>{{ optional($photo->created_at)->format('d.m.Y H:i') }}</span>
                                </a>
                            @endforeach
                        </div>
                    </details>
                @endif
            @elseif(!$workForm)
                <div class="pd-subcontract-tracking__empty">Fotoğraf eklemek için iş formu kaydı gerekli.</div>
            @endif
        </section>

        <section class="pd-subcontract-tracking__card pd-subcontract-tracking__history-compact">
            <div class="pd-subcontract-tracking__section-line">
                <strong>Son Geçmiş</strong>
                @if($extraHistoryCount > 0)
                    <small>Son 3 hareket gösteriliyor.</small>
                @endif
            </div>
            @forelse($visibleHistory as $log)
                <article>
                    <strong>{{ $activityLabelResolver->title((string) $log->action_type) }}</strong>
                    <span>{{ $log->note }}</span>
                    <small>{{ optional($log->created_at)->format('d.m.Y H:i') }} · {{ $log->creator?->name ?: 'Sistem' }}</small>
                </article>
            @empty
                <p>Henüz üretim geçmişi yok.</p>
            @endforelse
            @if($extraHistoryCount > 0)
                <details class="pd-subcontract-tracking__details-toggle">
                    <summary>Tüm Geçmişi Göster (+{{ $extraHistoryCount }})</summary>
                    @foreach($history->skip(3) as $log)
                        <article>
                            <strong>{{ $activityLabelResolver->title((string) $log->action_type) }}</strong>
                            <span>{{ $log->note }}</span>
                            <small>{{ optional($log->created_at)->format('d.m.Y H:i') }} · {{ $log->creator?->name ?: 'Sistem' }}</small>
                        </article>
                    @endforeach
                </details>
            @endif
        </section>
    </main>
</div>
@endsection
