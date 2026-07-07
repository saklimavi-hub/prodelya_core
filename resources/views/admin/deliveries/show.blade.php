@extends('layouts.prodelya-admin')

@section('title', 'Teslimat Detayı')
@section('page_title', 'Teslimat Detayı')
@section('page_subtitle', 'Ürün bazlı teslimat, koli/paket, belge ve kısmi/tam teslim süreçlerini yönetin.')

@section('content')
@php
    $snapshot = is_array($delivery->delivery_snapshot) ? $delivery->delivery_snapshot : [];
    $productSnapshot = is_array($delivery->workForm?->product_snapshot) ? $delivery->workForm->product_snapshot : [];
    $deliveryPhotos = $delivery->workForm?->attachments?->where('attachment_type', 'delivery_photo')->values() ?? collect();
    $deliveryDocuments = $delivery->workForm?->attachments?->where('attachment_type', 'delivery_document')->values() ?? collect();
    $groupDeliveries = $groupDeliveries ?? collect();
    $warnings = array_values(array_filter((array) data_get($snapshot, 'readiness_warnings', [])));
    $statusTone = match ($delivery->delivery_status) {
        'teslimata_hazir', 'teslim_edildi' => 'green',
        'kismi_teslim_edildi' => 'amber',
        'teslimat_sorunu', 'iptal' => 'red',
        default => 'gray',
    };
    $isDelivered = $delivery->delivery_status === \App\Models\OrderItemWorkFormDelivery::STATUS_DELIVERED || (float) $delivery->remaining_quantity <= 0.0001;
    $canViewFinancialData = auth('web')->user()?->canViewFinancialData($delivery->tenant_account_id) ?? false;
@endphp

<style>
    .dvs-page { display: grid; gap: 16px; }
    .dvs-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05); overflow: hidden; }
    .dvs-body { padding: 16px; }
    .dvs-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
    .dvs-title { font-size: 26px; font-weight: 700; color: #111827; }
    .dvs-subtitle { margin-top: 6px; color: #6b7280; font-size: 14px; }
    .dvs-links, .dvs-actions, .dvs-form-actions, .dvs-file-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .dvs-summary { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-top: 14px; }
    .dvs-box, .dvs-form-card, .dvs-file-card, .dvs-phone, .dvs-mini-card { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 12px; }
    .dvs-label { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .dvs-value { margin-top: 6px; color: #111827; font-size: 18px; font-weight: 700; }
    .dvs-layout { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(340px, .9fr); gap: 16px; align-items: start; }
    .dvs-stack { display: grid; gap: 16px; }
    .dvs-product-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
    .dvs-product-name { font-size: 18px; font-weight: 700; color: #111827; }
    .dvs-product-code { margin-top: 4px; color: #6b7280; font-size: 13px; }
    .dvs-detail-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
    .dvs-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .dvs-form-grid .full { grid-column: 1 / -1; }
    .dvs-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 12px; font-weight: 700; }
    .dvs-note { border: 1px solid #dbeafe; border-radius: 6px; background: #f8fbff; padding: 10px 12px; color: #6b7280; font-size: 12px; line-height: 1.45; }
    .dvs-thumb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
    .dvs-file-thumb { width: 100%; height: 120px; object-fit: cover; display: block; border-radius: 4px; border: 1px solid #e5e7eb; background: #fff; }
    .dvs-history { display: grid; gap: 8px; }
    .dvs-history-row { display: grid; grid-template-columns: 135px 1fr auto; gap: 10px; border: 1px solid #e5e7eb; border-radius: 6px; background: #fff; padding: 10px; }
    .dvs-mobile-title { font-size: 16px; font-weight: 700; color: #111827; }
    .dvs-big-upload { min-height: 52px; border: 1px dashed #b9c4d0; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #f8fafc; color: #2563eb; font-weight: 700; }
    @media (max-width: 1250px) { .dvs-layout, .dvs-summary, .dvs-detail-grid { grid-template-columns: 1fr; } }
    @media (max-width: 900px) { .dvs-form-grid, .dvs-history-row { grid-template-columns: 1fr; } }
</style>

@if(session('success'))
    <div class="pd-alert">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="pd-alert-warning">{{ $errors->first() }}</div>
@endif

<div class="dvs-page">
    <section class="dvs-card">
        <div class="dvs-body">
            <div class="dvs-head">
                <div>
                    <div class="dvs-title">{{ $delivery->order?->document_number ?: '-' }} / {{ data_get($snapshot, 'product_name', $delivery->orderItem?->product_name ?: '-') }}</div>
                    <div class="dvs-subtitle">{{ data_get($snapshot, 'product_code', $delivery->orderItem?->product_code ?: '-') }} · {{ rtrim(rtrim(number_format((float) $delivery->planned_quantity, 4, ',', '.'), '0'), ',') }} {{ data_get($snapshot, 'unit', $delivery->orderItem?->unit) }} · {{ $delivery->order?->delivery_type ?: 'Teslimat tipi girilmedi' }}</div>
                </div>
                <div class="dvs-links">
                    <a href="{{ route('admin.deliveries.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                    @if($delivery->order)
                        <a href="{{ route('admin.orders.show', $delivery->order) }}" class="pd-btn pd-btn-light">Siparişi Aç</a>
                        @if($canViewFinancialData)
                            <a href="{{ route('admin.finance.show', $delivery->order) }}" class="pd-btn pd-btn-light">Finans · Yetkili</a>
                        @endif
                    @endif
                    @if($delivery->workForm)
                        <a href="{{ route('admin.work-forms.show', $delivery->workForm) }}" class="pd-btn pd-btn-primary">İş Formu</a>
                    @endif
                    @if($delivery->workForm?->printProductions?->isNotEmpty())
                        <a href="{{ route('admin.productions.show', $delivery->workForm->printProductions->first()) }}" class="pd-btn pd-btn-light">Üretim</a>
                    @endif
                </div>
            </div>

            <div class="dvs-summary">
                <div class="dvs-box">
                    <div class="dvs-label">Müşteri</div>
                    <div class="dvs-value" style="font-size:16px;">{{ $delivery->order?->customer?->legal_name ?: '-' }}</div>
                </div>
                <div class="dvs-box">
                    <div class="dvs-label">Genel Durum</div>
                    <div class="dvs-value" style="font-size:16px;"><span class="pd-badge pd-badge-{{ $statusTone }}">{{ $statusLabels[$delivery->delivery_status] ?? $delivery->delivery_status }}</span></div>
                </div>
                <div class="dvs-box">
                    <div class="dvs-label">Teslim Edilen</div>
                    <div class="dvs-value">{{ rtrim(rtrim(number_format((float) $delivery->delivered_quantity, 4, ',', '.'), '0'), ',') }}</div>
                </div>
                <div class="dvs-box">
                    <div class="dvs-label">Kalan</div>
                    <div class="dvs-value">{{ rtrim(rtrim(number_format((float) $delivery->remaining_quantity, 4, ',', '.'), '0'), ',') }}</div>
                </div>
                <div class="dvs-box">
                    <div class="dvs-label">Finans Uyarısı</div>
                    <div class="dvs-value" style="font-size:16px;">{{ $delivery->safeFinancialWarningLabel() }}</div>
                </div>
            </div>

            <div class="dvs-actions" style="margin-top:14px;">
                <a href="#delivery-partial" class="pd-btn pd-btn-primary">Kısmi Teslim</a>
                <a href="#delivery-complete" class="pd-btn pd-btn-light">Tamamı Teslim</a>
                <a href="#delivery-files" class="pd-btn pd-btn-light">Belge / Fotoğraf Ekle</a>
            </div>
        </div>
    </section>

    <div class="dvs-layout">
        <div class="dvs-stack">
            @if($warnings !== [])
                <section class="dvs-card">
                    <div class="dvs-body">
                        <h3 class="dvs-product-name">Teslimat Uyarıları</h3>
                        <div class="dvs-stack" style="margin-top:12px;">
                            @foreach($warnings as $warning)
                                <div class="dvs-note">{{ $warning }}</div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif

            @if($groupDeliveries->count() > 1)
                <section class="dvs-card">
                    <div class="dvs-body">
                        <h3 class="dvs-product-name">Aynı Siparişteki Diğer Ürünler</h3>
                        <div class="dvs-actions" style="margin-top:12px;">
                            @foreach($groupDeliveries as $groupDelivery)
                                <a href="{{ route('admin.deliveries.show', $groupDelivery) }}" class="pd-btn {{ $groupDelivery->is($delivery) ? 'pd-btn-primary' : 'pd-btn-light' }}">
                                    {{ $groupDelivery->orderItem?->product_name ?: ('Ürün #' . $groupDelivery->id) }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif

            <section class="dvs-card">
                <div class="dvs-body">
                    <div class="dvs-product-head">
                        <div>
                            <div class="dvs-product-name">Ürün Kartı</div>
                            <div class="dvs-product-code">{{ data_get($snapshot, 'product_name', $delivery->orderItem?->product_name ?: '-') }} · {{ data_get($snapshot, 'product_code', $delivery->orderItem?->product_code ?: '-') }}</div>
                        </div>
                        <div class="dvs-actions">
                            @if(filled($delivery->recipient_name))
                                <span class="pd-badge pd-badge-gray">Teslim alan: {{ $delivery->recipient_name }}</span>
                            @endif
                            @if(filled($delivery->tracking_number))
                                <span class="pd-badge pd-badge-gray">Takip: {{ $delivery->tracking_number }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="dvs-detail-grid" style="margin-top:14px;">
                        <div class="dvs-box">
                            <div class="dvs-label">Sipariş Adedi</div>
                            <div class="dvs-value">{{ rtrim(rtrim(number_format((float) data_get($snapshot, 'ordered_quantity', $delivery->planned_quantity), 4, ',', '.'), '0'), ',') }}</div>
                        </div>
                        <div class="dvs-box">
                            <div class="dvs-label">Üretim / Hazır Adet</div>
                            <div class="dvs-value">{{ rtrim(rtrim(number_format((float) data_get($delivery->workForm?->production_snapshot, 'completed_quantity', data_get($snapshot, 'ordered_quantity', $delivery->planned_quantity)), 4, ',', '.'), '0'), ',') }}</div>
                        </div>
                        <div class="dvs-box">
                            <div class="dvs-label">Teslim Edilen</div>
                            <div class="dvs-value">{{ rtrim(rtrim(number_format((float) $delivery->delivered_quantity, 4, ',', '.'), '0'), ',') }}</div>
                        </div>
                        <div class="dvs-box">
                            <div class="dvs-label">Kalan Adet</div>
                            <div class="dvs-value">{{ rtrim(rtrim(number_format((float) $delivery->remaining_quantity, 4, ',', '.'), '0'), ',') }}</div>
                        </div>
                        <div class="dvs-box">
                            <div class="dvs-label">Ticari Teslimat Tipi</div>
                            <div class="dvs-value" style="font-size:15px;">{{ $delivery->order?->delivery_type ?: '-' }}</div>
                        </div>
                        <div class="dvs-box">
                            <div class="dvs-label">Paket Tipi</div>
                            <div class="dvs-value">{{ $packageTypeLabels[$delivery->package_type] ?? '-' }}</div>
                        </div>
                        <div class="dvs-box">
                            <div class="dvs-label">Koli Adedi</div>
                            <div class="dvs-value">{{ $delivery->package_count ?: '-' }}</div>
                        </div>
                        <div class="dvs-box">
                            <div class="dvs-label">Koli İçi Adet</div>
                            <div class="dvs-value">{{ $delivery->units_per_package ?: '-' }}</div>
                        </div>
                        <div class="dvs-box">
                            <div class="dvs-label">Toplam Paketlenen</div>
                            <div class="dvs-value">{{ $delivery->packaged_quantity ?: '-' }}</div>
                        </div>
                        <div class="dvs-box">
                            <div class="dvs-label">Belge / Takip No</div>
                            <div class="dvs-value" style="font-size:15px;">{{ $delivery->delivery_document_no ?: '-' }}{{ $delivery->tracking_number ? ' · ' . $delivery->tracking_number : '' }}</div>
                        </div>
                        <div class="dvs-box">
                            <div class="dvs-label">Kargo / Kurye Firma</div>
                            <div class="dvs-value" style="font-size:15px;">{{ $delivery->carrier_name ?: '-' }}</div>
                        </div>
                    </div>

                    @if(filled($delivery->package_note) || filled($delivery->delivery_note))
                        <div class="dvs-note" style="margin-top:14px;">
                            @if(filled($delivery->package_note))
                                <strong>Paket notu:</strong> {{ $delivery->package_note }}<br>
                            @endif
                            @if(filled($delivery->delivery_note))
                                <strong>Teslimat notu:</strong> {{ $delivery->delivery_note }}
                            @endif
                        </div>
                    @endif
                </div>
            </section>

            <section class="dvs-card" id="delivery-partial">
                <div class="dvs-body">
                    <h3 class="dvs-product-name">Kısmi / Tam Teslim İşlemleri</h3>
                    <div class="dvs-note" style="margin-top:10px;">Önce teslim adedini girin, sonra koli ve belge bilgisini ekleyin. Hazır olmayan veya kalan adedi aşan kayıtlar kaydedilmez.</div>

                    <div class="dvs-stack" style="margin-top:14px;">
                        <form method="POST" action="{{ route('admin.deliveries.update-status', $delivery) }}" class="dvs-form-card">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="partially_delivered">
                            <div class="dvs-product-name" style="font-size:16px;">Kısmi Teslim</div>
                            <div class="dvs-form-grid" style="margin-top:12px;">
                                <div class="dvs-field">
                                    <label>Bu teslimatta teslim edilen adet</label>
                                    <input type="number" step="0.0001" min="0.0001" name="this_delivery_quantity" value="{{ old('this_delivery_quantity') }}" placeholder="Örn. 20">
                                </div>
                                <div class="dvs-field">
                                    <label>Koli adedi</label>
                                    <input type="number" min="1" name="package_count" value="{{ old('package_count', $delivery->package_count) }}">
                                </div>
                                <div class="dvs-field">
                                    <label>Koli içi adet</label>
                                    <input type="number" min="1" name="units_per_package" value="{{ old('units_per_package', $delivery->units_per_package) }}">
                                </div>
                                <div class="dvs-field">
                                    <label>Paket tipi</label>
                                    <select name="package_type">
                                        <option value="">Seçiniz</option>
                                        @foreach($packageTypeLabels as $key => $label)
                                            <option value="{{ $key }}" @selected(old('package_type', $delivery->package_type) === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="dvs-field">
                                    <label>Teslim alan kişi</label>
                                    <input type="text" name="recipient_name" value="{{ old('recipient_name', $delivery->recipient_name) }}">
                                </div>
                                <div class="dvs-field">
                                    <label>Teslimat tipi</label>
                                    <select name="delivery_method">
                                        <option value="">Seçiniz</option>
                                        @foreach($methodLabels as $key => $label)
                                            <option value="{{ $key }}" @selected(old('delivery_method', $delivery->delivery_method) === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="dvs-field">
                                    <label>Belge No</label>
                                    <input type="text" name="delivery_document_no" value="{{ old('delivery_document_no', $delivery->delivery_document_no) }}">
                                </div>
                                <div class="dvs-field">
                                    <label>Takip No</label>
                                    <input type="text" name="tracking_number" value="{{ old('tracking_number', $delivery->tracking_number) }}">
                                </div>
                                <div class="dvs-field full">
                                    <label>Kargo / Kurye Firma</label>
                                    <input type="text" name="carrier_name" value="{{ old('carrier_name', $delivery->carrier_name) }}">
                                </div>
                                <div class="dvs-field full">
                                    <label>Paket notu</label>
                                    <textarea name="package_note">{{ old('package_note', $delivery->package_note) }}</textarea>
                                </div>
                                <div class="dvs-field full">
                                    <label>Teslimat notu</label>
                                    <textarea name="note">{{ old('note', $delivery->delivery_note) }}</textarea>
                                </div>
                            </div>
                            <div class="dvs-form-actions" style="margin-top:12px;">
                                <button type="submit" class="pd-btn pd-btn-primary">Kısmi Teslim Kaydet</button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('admin.deliveries.update-status', $delivery) }}" class="dvs-form-card" id="delivery-complete">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="delivered">
                            <div class="dvs-product-name" style="font-size:16px;">Tam Teslim</div>
                            <div class="dvs-form-grid" style="margin-top:12px;">
                                <div class="dvs-field">
                                    <label>Kalan adet</label>
                                    <input type="text" value="{{ rtrim(rtrim(number_format((float) $delivery->remaining_quantity, 4, ',', '.'), '0'), ',') }}" disabled>
                                </div>
                                <div class="dvs-field">
                                    <label>Teslim alan kişi</label>
                                    <input type="text" name="recipient_name" value="{{ old('recipient_name', $delivery->recipient_name) }}">
                                </div>
                                <div class="dvs-field">
                                    <label>Paket tipi</label>
                                    <select name="package_type">
                                        <option value="">Seçiniz</option>
                                        @foreach($packageTypeLabels as $key => $label)
                                            <option value="{{ $key }}" @selected(old('package_type', $delivery->package_type) === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="dvs-field">
                                    <label>Koli adedi</label>
                                    <input type="number" min="1" name="package_count" value="{{ old('package_count', $delivery->package_count) }}">
                                </div>
                                <div class="dvs-field">
                                    <label>Koli içi adet</label>
                                    <input type="number" min="1" name="units_per_package" value="{{ old('units_per_package', $delivery->units_per_package) }}">
                                </div>
                                <div class="dvs-field">
                                    <label>Belge / Takip No</label>
                                    <input type="text" name="delivery_document_no" value="{{ old('delivery_document_no', $delivery->delivery_document_no) }}">
                                </div>
                                <div class="dvs-field full">
                                    <label>Kargo / Kurye Firma</label>
                                    <input type="text" name="carrier_name" value="{{ old('carrier_name', $delivery->carrier_name) }}">
                                </div>
                                <div class="dvs-field full">
                                    <label>Belge / fotoğraf notu</label>
                                    <textarea name="note">{{ old('note', $delivery->delivery_note) }}</textarea>
                                </div>
                            </div>
                            <div class="dvs-form-actions" style="margin-top:12px;">
                                <button type="submit" class="pd-btn pd-btn-success" @disabled($isDelivered)>Tam Teslim Kaydet</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <section class="dvs-card" id="delivery-files">
                <div class="dvs-body">
                    <h3 class="dvs-product-name">Fotoğraf / Belge / İmza</h3>
                    <div class="dvs-note" style="margin-top:10px;">Müşterinin göreceği dosyalar için <strong>Müşteri Görür</strong>, yalnız iç ekipte kalacak dosyalar için <strong>Sadece İç Ekip</strong> seçin.</div>

                    <div class="dvs-thumb-grid" style="margin-top:14px;">
                        @foreach($deliveryPhotos as $photo)
                            <div class="dvs-file-card">
                                <div class="dvs-label">Teslimat Fotoğrafı</div>
                                @if($photo->isImage())
                                    <img class="dvs-file-thumb" src="{{ route('admin.work-forms.attachments.preview', $photo) }}" alt="{{ $photo->file_name }}">
                                @endif
                                <strong>{{ $photo->file_name }}</strong>
                                <div class="dvs-subtitle" style="font-size:12px;">{{ $photo->visibility === 'customer_visible' ? 'Müşteri Görür' : 'Sadece İç Ekip' }}</div>
                            </div>
                        @endforeach
                        @foreach($deliveryDocuments as $document)
                            <div class="dvs-file-card">
                                <div class="dvs-label">{{ str_contains(mb_strtolower($document->file_name), 'irsaliye') ? 'İrsaliye' : 'Teslimat Belgesi' }}</div>
                                @if($document->isImage())
                                    <img class="dvs-file-thumb" src="{{ route('admin.work-forms.attachments.preview', $document) }}" alt="{{ $document->file_name }}">
                                @endif
                                <strong>{{ $document->file_name }}</strong>
                                <div class="dvs-subtitle" style="font-size:12px;">{{ $document->visibility === 'customer_visible' ? 'Müşteri Görür' : 'Sadece İç Ekip' }}</div>
                            </div>
                        @endforeach
                    </div>

                    @if($deliveryPhotos->isEmpty() && $deliveryDocuments->isEmpty())
                        <div class="dvs-note" style="margin-top:12px;">Henüz teslimat fotoğrafı veya belgesi eklenmedi.</div>
                    @endif

                    @if($delivery->workForm)
                        <div class="dvs-stack" style="margin-top:14px;">
                            <form method="POST" action="{{ route('admin.work-forms.attachments.store', $delivery->workForm) }}" enctype="multipart/form-data" class="dvs-form-card">
                                @csrf
                                <input type="hidden" name="attachment_type" value="delivery_photo">
                                <input type="hidden" name="section" value="delivery">
                                <input type="hidden" name="redirect_to" value="admin.deliveries.show">
                                <input type="hidden" name="redirect_delivery_id" value="{{ $delivery->id }}">
                                <div class="dvs-form-grid">
                                    <div class="dvs-field full">
                                        <label>Fotoğraf Ekle</label>
                                        <input type="file" name="file" accept="image/*" capture="environment">
                                    </div>
                                    <div class="dvs-field">
                                        <label>Görünürlük</label>
                                        <select name="visibility">
                                            <option value="internal">Sadece İç Ekip</option>
                                            <option value="customer_visible">Müşteri Görür</option>
                                        </select>
                                    </div>
                                    <div class="dvs-field">
                                        <label>Not</label>
                                        <input type="text" name="note" placeholder="Teslimat / imza / koli fotoğrafı notu">
                                    </div>
                                </div>
                                <div class="dvs-form-actions" style="margin-top:12px;">
                                    <button type="submit" class="pd-btn pd-btn-primary">Teslimat Fotoğrafı</button>
                                </div>
                            </form>

                            <form method="POST" action="{{ route('admin.work-forms.attachments.store', $delivery->workForm) }}" enctype="multipart/form-data" class="dvs-form-card">
                                @csrf
                                <input type="hidden" name="attachment_type" value="delivery_document">
                                <input type="hidden" name="section" value="delivery">
                                <input type="hidden" name="redirect_to" value="admin.deliveries.show">
                                <input type="hidden" name="redirect_delivery_id" value="{{ $delivery->id }}">
                                <div class="dvs-form-grid">
                                    <div class="dvs-field full">
                                        <label>Belge Ekle</label>
                                        <input type="file" name="file" accept="image/*,application/pdf">
                                    </div>
                                    <div class="dvs-field">
                                        <label>Görünürlük</label>
                                        <select name="visibility">
                                            <option value="internal">Sadece İç Ekip</option>
                                            <option value="customer_visible">Müşteri Görür</option>
                                        </select>
                                    </div>
                                    <div class="dvs-field">
                                        <label>Not</label>
                                        <input type="text" name="note" placeholder="Belge / irsaliye / imza notu">
                                    </div>
                                </div>
                                <div class="dvs-form-actions" style="margin-top:12px;">
                                    <button type="submit" class="pd-btn pd-btn-light">Teslimat Belgesi</button>
                                </div>
                            </form>
                        </div>
                    @endif
                </div>
            </section>

            <section class="dvs-card">
                <div class="dvs-body">
                    <h3 class="dvs-product-name">Workflow Geçmişi</h3>
                    <div class="dvs-history" style="margin-top:12px;">
                        @forelse($history as $log)
                            <div class="dvs-history-row">
                                <div>{{ optional($log->created_at)->format('d.m.Y H:i') ?: '-' }}</div>
                                <div>{{ $log->note ?: ($log->action_type ?? '-') }}</div>
                                <div><span class="pd-badge pd-badge-{{ $log->visibility === 'customer_visible' ? 'green' : 'gray' }}">{{ $log->visibility === 'customer_visible' ? 'Müşteri Görür' : 'Sadece İç Ekip' }}</span></div>
                            </div>
                        @empty
                            <div class="dvs-note">Henüz teslimat workflow kaydı yok.</div>
                        @endforelse
                    </div>
                </div>
            </section>
        </div>

        <aside class="dvs-stack">
            <section class="dvs-card">
                <div class="dvs-body">
                    <h3 class="dvs-product-name">Teslimat Bilgileri</h3>
                    <form method="POST" action="{{ route('admin.deliveries.update-details', $delivery) }}" class="dvs-stack" style="margin-top:12px;">
                        @csrf
                        @method('PATCH')
                        <div class="dvs-form-grid">
                            <div class="dvs-field">
                                <label>Ticari teslimat tipi</label>
                                <select name="delivery_type_id">
                                    <option value="">Seçiniz</option>
                                    @foreach(($deliveryTypeOptions ?? collect()) as $deliveryType)
                                        <option value="{{ $deliveryType->id }}" @selected((string) old('delivery_type_id', $selectedDeliveryTypeId ?? '') === (string) $deliveryType->id)>{{ $deliveryType->name }}</option>
                                    @endforeach
                                    @if(filled($legacyDeliveryTypeLabel ?? null))
                                        <option value="" selected>Mevcut değer: {{ $legacyDeliveryTypeLabel }}</option>
                                    @endif
                                </select>
                            </div>
                            <div class="dvs-field">
                                <label>Operasyonel teslim yöntemi</label>
                                <select name="delivery_method">
                                    <option value="">Seçiniz</option>
                                    @foreach($methodLabels as $key => $label)
                                        <option value="{{ $key }}" @selected(old('delivery_method', $delivery->delivery_method) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="dvs-field">
                                <label>Teslim alan kişi</label>
                                <input type="text" name="recipient_name" value="{{ old('recipient_name', $delivery->recipient_name) }}">
                            </div>
                            <div class="dvs-field">
                                <label>Kargo / Kurye firma</label>
                                <input type="text" name="carrier_name" value="{{ old('carrier_name', $delivery->carrier_name) }}">
                            </div>
                            <div class="dvs-field">
                                <label>Takip no</label>
                                <input type="text" name="tracking_number" value="{{ old('tracking_number', $delivery->tracking_number) }}">
                            </div>
                            <div class="dvs-field">
                                <label>Belge no</label>
                                <input type="text" name="delivery_document_no" value="{{ old('delivery_document_no', $delivery->delivery_document_no) }}">
                            </div>
                            <div class="dvs-field">
                                <label>Alıcı telefon</label>
                                <input type="text" name="recipient_phone" value="{{ old('recipient_phone', $delivery->recipient_phone) }}">
                            </div>
                            <div class="dvs-field">
                                <label>Koli adedi</label>
                                <input type="number" min="1" name="package_count" value="{{ old('package_count', $delivery->package_count) }}">
                            </div>
                            <div class="dvs-field">
                                <label>Koli içi adet</label>
                                <input type="number" min="1" name="units_per_package" value="{{ old('units_per_package', $delivery->units_per_package) }}">
                            </div>
                            <div class="dvs-field">
                                <label>Toplam paketlenen adet</label>
                                <input type="number" min="1" name="packaged_quantity" value="{{ old('packaged_quantity', $delivery->packaged_quantity) }}">
                            </div>
                            <div class="dvs-field">
                                <label>Paket tipi</label>
                                <select name="package_type">
                                    <option value="">Seçiniz</option>
                                    @foreach($packageTypeLabels as $key => $label)
                                        <option value="{{ $key }}" @selected(old('package_type', $delivery->package_type) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="dvs-field full">
                                <label>Paket notu</label>
                                <textarea name="package_note">{{ old('package_note', $delivery->package_note) }}</textarea>
                            </div>
                            <div class="dvs-field full">
                                <label>Teslimat notu</label>
                                <textarea name="delivery_note">{{ old('delivery_note', $delivery->delivery_note) }}</textarea>
                            </div>
                        </div>
                        <div class="dvs-form-actions">
                            <button type="submit" class="pd-btn pd-btn-primary">Teslimat Bilgilerini Güncelle</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="dvs-card">
                <div class="dvs-body">
                    <h3 class="dvs-product-name">Mobil Teslimat</h3>
                    <div class="dvs-phone" style="margin-top:12px;">
                        <div class="dvs-mobile-title">{{ $delivery->order?->document_number ?: '-' }}</div>
                        <div class="dvs-subtitle" style="margin-top:4px;">{{ data_get($snapshot, 'product_name', $delivery->orderItem?->product_name ?: '-') }}</div>
                        <div class="dvs-mini-card" style="margin-top:12px;">
                            <div class="dvs-label">Koli / Paket</div>
                            <div class="dvs-value" style="font-size:16px;">{{ $delivery->package_count ?: '-' }} {{ $packageTypeLabels[$delivery->package_type] ?? 'paket' }}</div>
                            <div class="dvs-big-upload" style="margin-top:12px;">Fotoğraf Ekle</div>
                            <div class="dvs-file-actions" style="margin-top:12px;">
                                <span class="pd-btn pd-btn-light">Belge Ekle</span>
                                <span class="pd-btn pd-btn-primary">Kısmi Teslim</span>
                                <span class="pd-btn pd-btn-success">Tam Teslim</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Teslimat Planı</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Teslimat Tipi</span><strong>{{ $delivery->order?->delivery_type ?: '-' }}</strong></div>
            <div class="pd-status-row"><span>Sipariş No</span><strong>{{ $delivery->order?->document_number ?: '-' }}</strong></div>
            <div class="pd-status-row"><span>İş Formu</span><strong>{{ $delivery->workForm?->work_form_number ?: '-' }}</strong></div>
            <div class="pd-status-row"><span>Ürün</span><strong>{{ data_get($snapshot, 'product_name', $delivery->orderItem?->product_name ?: '-') }}</strong></div>
            <div class="pd-status-row"><span>Durum</span><strong>{{ $statusLabels[$delivery->delivery_status] ?? $delivery->delivery_status }}</strong></div>
            <div class="pd-status-row"><span>Koli / Paket</span><strong>{{ $delivery->package_count ?: '-' }} / {{ $packageTypeLabels[$delivery->package_type] ?? '-' }}</strong></div>
            <div class="pd-status-row"><span>Teslim Edilen</span><strong>{{ rtrim(rtrim(number_format((float) $delivery->delivered_quantity, 4, ',', '.'), '0'), ',') }}</strong></div>
            <div class="pd-status-row"><span>Kalan</span><strong>{{ rtrim(rtrim(number_format((float) $delivery->remaining_quantity, 4, ',', '.'), '0'), ',') }}</strong></div>
            <div class="pd-status-row"><span>Finans Uyarısı</span><strong>{{ $delivery->safeFinancialWarningLabel() }}</strong></div>
            <div class="pd-status-row"><span>Sıradaki İş</span><strong>{{ $nextActionLabel }}</strong></div>
        </div>
        <div class="pd-side-note">
            Public tracking yalnız müşteri görünür belge ve fotoğrafları yansıtır. Finans warning, fiyat ve teknik dosya yolu bilgileri müşteriye açılmaz.
        </div>
    </div>
</div>
@endsection
