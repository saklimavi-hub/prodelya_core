@extends('layouts.prodelya-admin')

@section('title', 'Fason Atama')
@section('page_topbar_hidden', true)
@section('hide_side_summary', true)

@section('content')
@php
    use App\Models\OrderItemPrintProduction;

    $printSequence = $snapshot['print_sequence'] ?? '-';
    $orderNumber = $snapshot['order_number'] ?? ($production->order?->document_number ?: '-');
    $workFormNumber = $snapshot['work_form_number'] ?? ($production->workForm?->work_form_number ?: '-');
    $customerName = $production->order?->customer?->legal_name ?: '-';
    $productName = $snapshot['product_name'] ?? ($production->orderItem?->product_name ?: '-');
    $productCode = $snapshot['product_code'] ?? ($production->orderItem?->product_code ?: '-');
    $printType = $production->orderItemPrint?->displayPrintType() ?: ($snapshot['print_type'] ?? 'Baskı tekniği');
    $printOption = $snapshot['print_option'] ?? ($production->orderItemPrint?->print_option ?: '-');
    $unit = $snapshot['unit'] ?? ($production->orderItem?->unit ?: 'Adet');
    $planned = OrderItemPrintProduction::formatDisplayQuantity($production->planned_quantity);
    $completed = OrderItemPrintProduction::formatDisplayQuantity($production->completed_quantity);
    $remaining = OrderItemPrintProduction::formatDisplayQuantity($production->remainingQuantity());
    $hasCompany = (bool) $production->production_company_id;
    $canSend = $hasCompany && (bool) ($readiness['can_start'] ?? false);
    $isTrackingOpen = in_array($production->production_status, [
        OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
        OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR,
        OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
        OrderItemPrintProduction::STATUS_QUALITY_CONTROL,
        OrderItemPrintProduction::STATUS_PROBLEMATIC,
    ], true);
    $isCompleted = $production->production_status === OrderItemPrintProduction::STATUS_COMPLETED;
    $blockingReason = $readiness['blocking_reason_label'] ?? 'Grafik ve tedarik hazırlığı tamamlanınca fasona gönderilebilir.';
    $selectedCompany = $production->productionCompany;
    $selectedCompanyId = (int) old('production_company_id', $production->production_company_id);
    $selectedCompanyName = $companies->firstWhere('id', $selectedCompanyId)?->legal_name;    $historyPreview = $history->take(3);
    $extraHistoryCount = max(0, $history->count() - $historyPreview->count());
@endphp

<div class="pd-ui-v1-subcontract-assignment pd-subcontract-assignment">
    <section class="pd-subcontract-assignment__compact-header">
        <div class="pd-subcontract-assignment__title-block">
            <span>Üretim / Fason · Atama</span>
            <h1>{{ $orderNumber }} · {{ $printSequence }} · {{ $printType }}</h1>
            <strong>{{ $productCode }} {{ $productName }}</strong>
            <p class="pd-subcontract-assignment__meta-line">
                <span>Müşteri: {{ $customerName }}</span>
                <span>İş Formu: {{ $workFormNumber }}</span>
                <span>Baskı: {{ $printOption }}</span>
                <span>Üretim Yolu: Dış Baskı / Fason</span>
            </p>
        </div>
        <a href="{{ route('admin.productions.index', ['route' => 'outsourced']) }}" class="pd-subcontract-assignment__btn">Fason Listesine Dön</a>
    </section>

    @if(session('success'))
        <div class="pd-subcontract-assignment__notice">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="pd-subcontract-assignment__alert">
            <strong>İşlem tamamlanamadı.</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <main class="pd-subcontract-assignment__surface">
        <section class="pd-subcontract-assignment__action-panel" aria-label="Fason atama aktif karar yüzeyi">
            <div class="pd-subcontract-assignment__action-copy">
                @if($isCompleted)
                    <span>Tamamlanan fason işi</span>
                    <h2>Fason işi tamamlandı</h2>
                    <p>{{ $selectedCompany?->legal_name ?: 'Atanan firma' }} kaydı read-only incelenebilir.</p>
                @elseif($isTrackingOpen)
                    <span>Fason takibi açık</span>
                    <h2>{{ $selectedCompany?->legal_name ?: 'Fason firma' }}</h2>
                    <p>Bu iş fasona gönderildi. Gelen miktar ve sorun takibi ayrı takip ekranında yapılır.</p>
                @elseif($hasCompany)
                    <span>Atanan firma</span>
                    <h2>{{ $selectedCompany?->legal_name ?: 'Fason firma seçildi' }}</h2>
                    <p>{{ $canSend ? 'Hazırlıklar tamam. Bu exact iş fason firmaya gönderilebilir.' : $blockingReason }}</p>
                @else
                    <span>Fason firma seçimi</span>
                    <h2>Uygun fason firmayı seçin</h2>
                    <p>Firma şimdi atanabilir. İş, grafik ve tedarik hazır olduğunda gönderilir.</p>
                @endif
            </div>

            <div class="pd-subcontract-assignment__metrics-line" aria-label="Fason hazırlık ve miktar özeti">
                @if((float) $production->completed_quantity > 0)
                    <div class="pd-subcontract-assignment__metric"><span>Önceden Tamamlanan</span><strong>{{ $completed }}</strong></div>
                @endif
                <div class="pd-subcontract-assignment__metric {{ ($readiness['graphic_ready'] ?? false) ? 'is-ok' : 'is-waiting' }}">
                    <span>Grafik</span><strong>{{ ($readiness['graphic_ready'] ?? false) ? 'Hazır' : 'Bekliyor' }}</strong>
                </div>
                <div class="pd-subcontract-assignment__metric {{ ($readiness['procurement_ready'] ?? false) ? 'is-ok' : 'is-waiting' }}">
                    <span>Tedarik</span><strong>{{ ($readiness['procurement_ready'] ?? false) ? 'Hazır' : 'Bekliyor' }}</strong>
                </div>
                <div class="pd-subcontract-assignment__metric"><span>Fasona Gidecek</span><strong>{{ $remaining }} {{ $unit }}</strong></div>
                <div class="pd-subcontract-assignment__metric"><span>Durum</span><strong>{{ $production->safeStatusLabel() }}</strong></div>
            </div>

            <div class="pd-subcontract-assignment__primary-action">
                @if($isCompleted)
                    <a href="{{ route('admin.productions.show', $production) }}" class="pd-subcontract-assignment__btn pd-subcontract-assignment__btn--primary">Kaydı Aç</a>
                @elseif($isTrackingOpen)
                    <a href="{{ route('admin.productions.subcontract-tracking', $production) }}" class="pd-subcontract-assignment__btn pd-subcontract-assignment__btn--primary">Fason Takibi Aç</a>
                @elseif($hasCompany)
                    <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="sent_to_subcontractor">
                        <input type="hidden" name="return_to" value="subcontract_assignment">
                        <button type="submit" class="pd-subcontract-assignment__btn pd-subcontract-assignment__btn--primary" @disabled(!$canSend)>Fasona Gönder</button>
                    </form>
                    @unless($canSend)
                        <small>{{ $blockingReason }}</small>
                    @endunless
                @else
                    <span class="pd-subcontract-assignment__selected-company">Firma listesinden seçim yapın.</span>
                @endif
            </div>
        </section>

        @if(!$hasCompany && !$isTrackingOpen && !$isCompleted)
            <section class="pd-subcontract-assignment__card" id="fason-firma-secimi">
                <div class="pd-subcontract-assignment__section-line">
                    <strong>Fason firma seçimi</strong>
                    <small>{{ $companies->count() }} firma</small>
                </div>
                <form method="POST" action="{{ route('admin.productions.update-assignment', $production) }}" id="subcontract-assignment-form" class="pd-subcontract-assignment__form">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="production_type" value="{{ OrderItemPrintProduction::TYPE_OUTSOURCED }}">
                    <input type="hidden" name="return_to" value="subcontract_assignment">
                    <input type="hidden" name="cliche_required" value="{{ $production->cliche_required ? 1 : 0 }}">
                    <input type="hidden" name="cliche_status" value="{{ $production->cliche_status }}">

                    <div class="pd-subcontract-assignment__company-list">
                        @forelse($companies as $company)
                            @php
                                $roles = $company->companyRoles->pluck('role_key')->map(fn ($role) => match ($role) {
                                    'print_fason' => 'Fason Baskı',
                                    'production_partner' => 'Fason Üretim',
                                    default => 'Fason',
                                })->unique()->implode(' · ');
                            @endphp
                            <label class="pd-subcontract-assignment__company-row @if((int) old('production_company_id', $production->production_company_id) === (int) $company->id) pd-subcontract-assignment__company-row--selected @endif" data-company-name="{{ $company->legal_name }}">
                                <input type="radio" name="production_company_id" value="{{ $company->id }}" @checked((int) old('production_company_id', $production->production_company_id) === (int) $company->id)>
                                <span>
                                    <strong>{{ $company->legal_name }}</strong>
                                    <small>{{ $roles ?: 'Fason firma' }}</small>
                                </span>
                            </label>
                        @empty
                            <div class="pd-subcontract-assignment__empty">Bu tenant için aktif fason firma bulunamadı.</div>
                        @endforelse
                    </div>

                    <div class="pd-subcontract-assignment__sticky-action" aria-label="Fason firma atama aksiyonu">
                        <div class="pd-subcontract-assignment__selected-company">
                            <span>Seçilen Firma</span>
                            <strong data-subcontract-selected-company>{{ $selectedCompanyName ?: 'Firma seçilmedi' }}</strong>
                        </div>
                        <button type="submit" class="pd-subcontract-assignment__btn pd-subcontract-assignment__btn--primary pd-subcontract-assignment__submit" @disabled($companies->isEmpty() || !$selectedCompanyId)>Seçilen Firmaya Ata</button>
                    </div>
                </form>
            </section>
        @endif

        @if($hasCompany && !$isTrackingOpen && !$isCompleted)
            <details class="pd-subcontract-assignment__card pd-subcontract-assignment__details-toggle">
                <summary>Firmayı Değiştir</summary>
                <form method="POST" action="{{ route('admin.productions.update-assignment', $production) }}" class="pd-subcontract-assignment__form">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="production_type" value="{{ OrderItemPrintProduction::TYPE_OUTSOURCED }}">
                    <input type="hidden" name="return_to" value="subcontract_assignment">
                    <input type="hidden" name="cliche_required" value="{{ $production->cliche_required ? 1 : 0 }}">
                    <input type="hidden" name="cliche_status" value="{{ $production->cliche_status }}">

                    <div class="pd-subcontract-assignment__company-list">
                        @foreach($companies as $company)
                            @php
                                $roles = $company->companyRoles->pluck('role_key')->map(fn ($role) => match ($role) {
                                    'print_fason' => 'Fason Baskı',
                                    'production_partner' => 'Fason Üretim',
                                    default => 'Fason',
                                })->unique()->implode(' · ');
                            @endphp
                            <label class="pd-subcontract-assignment__company-row @if((int) old('production_company_id', $production->production_company_id) === (int) $company->id) pd-subcontract-assignment__company-row--selected @endif" data-company-name="{{ $company->legal_name }}">
                                <input type="radio" name="production_company_id" value="{{ $company->id }}" @checked((int) old('production_company_id', $production->production_company_id) === (int) $company->id)>
                                <span>
                                    <strong>{{ $company->legal_name }}</strong>
                                    <small>{{ $roles ?: 'Fason firma' }}</small>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <label class="pd-subcontract-assignment__reason">
                        <span>Değişiklik gerekçesi</span>
                        <textarea name="production_note" rows="3" required placeholder="Fason firma değişiyorsa kısa gerekçe yazın.">{{ old('production_note') }}</textarea>
                    </label>
                    <button type="submit" class="pd-subcontract-assignment__btn pd-subcontract-assignment__btn--soft">Firmayı Güncelle</button>
                </form>
            </details>
        @endif

        <details class="pd-subcontract-assignment__card pd-subcontract-assignment__details-toggle">
            <summary>İş Detaylarını Göster</summary>
            <div class="pd-subcontract-assignment__definition-row">
                <span>Sipariş <strong>{{ $orderNumber }}</strong></span>
                <span>İş formu <strong>{{ $workFormNumber }}</strong></span>
                <span>Müşteri <strong>{{ $customerName }}</strong></span>
                <span>Ürün <strong>{{ $productName }}</strong></span>
                <span>SKU <strong>{{ $productCode }}</strong></span>
                <span>Baskı <strong>{{ $printOption }}</strong></span>
                <span>Planlanan <strong>{{ $planned }} {{ $unit }}</strong></span>
                <span>Kalan <strong>{{ $remaining }} {{ $unit }}</strong></span>
            </div>
        </details>

        <section class="pd-subcontract-assignment__card pd-subcontract-assignment__history-compact">
            <div class="pd-subcontract-assignment__section-line">
                <strong>Son Geçmiş</strong>
                @if($extraHistoryCount > 0)
                    <small>Son 3 hareket gösteriliyor.</small>
                @endif
            </div>
            @forelse($historyPreview as $log)
                <article>
                    <strong>{{ $activityLabelResolver->title((string) $log->action_type) }}</strong>
                    <span>{{ $log->created_at?->format('d.m.Y H:i') }}</span>
                </article>
            @empty
                <p>Henüz üretim geçmişi yok.</p>
            @endforelse
            @if($extraHistoryCount > 0)
                <details class="pd-subcontract-assignment__details-toggle">
                    <summary>Tüm Geçmişi Göster (+{{ $extraHistoryCount }})</summary>
                    @foreach($history->skip(3) as $log)
                        <article>
                            <strong>{{ $activityLabelResolver->title((string) $log->action_type) }}</strong>
                            <span>{{ $log->created_at?->format('d.m.Y H:i') }}</span>
                        </article>
                    @endforeach
                </details>
            @endif
        </section>
    </main>
</div>
@endsection


@push('styles')
<style>
    .pd-ui-v1-subcontract-assignment .pd-subcontract-assignment__sticky-action {
        align-items: center;
        border-top: 1px solid rgba(15, 23, 42, 0.08);
        display: flex;
        gap: 16px;
        justify-content: space-between;
        margin-top: 14px;
        padding-top: 14px;
    }
    .pd-ui-v1-subcontract-assignment .pd-subcontract-assignment__selected-company {
        color: #64748b;
        display: grid;
        gap: 3px;
        font-size: 12px;
    }
    .pd-ui-v1-subcontract-assignment .pd-subcontract-assignment__selected-company strong {
        color: #0f172a;
        font-size: 14px;
    }
    .pd-ui-v1-subcontract-assignment .pd-subcontract-assignment__submit {
        min-height: 44px;
    }
    @media (max-width: 720px) {
        .pd-ui-v1-subcontract-assignment .pd-subcontract-assignment__sticky-action {
            align-items: stretch;
            flex-direction: column;
        }
        .pd-ui-v1-subcontract-assignment .pd-subcontract-assignment__submit {
            width: 100%;
        }
    }
</style>
@endpush

@if(!$hasCompany && !$isTrackingOpen && !$isCompleted)
@push('scripts')
<script>
    document.addEventListener('change', function (event) {
        if (!event.target.matches('#subcontract-assignment-form input[name="production_company_id"]')) {
            return;
        }

        const form = event.target.closest('#subcontract-assignment-form');
        const selected = form.querySelector('[data-subcontract-selected-company]');
        const submit = form.querySelector('.pd-subcontract-assignment__submit');
        const row = event.target.closest('[data-company-name]');

        form.querySelectorAll('.pd-subcontract-assignment__company-row--selected').forEach(function (item) {
            item.classList.remove('pd-subcontract-assignment__company-row--selected');
        });

        if (row) {
            row.classList.add('pd-subcontract-assignment__company-row--selected');
        }
        if (selected) {
            selected.textContent = row ? row.getAttribute('data-company-name') : 'Firma seçilmedi';
        }
        if (submit) {
            submit.disabled = !event.target.value;
        }
    });
</script>
@endpush
@endif
